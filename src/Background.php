<?php
/**
 * Background follow-up jobs: retries, timed checks and sweeps that no customer waits for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * The one mechanism for background hooks, next to the customer actions of Jobs (see ARCHITECTURE.md section 4,
 * "Customer actions" and "Job scheduling"). A feature declares each of its hooks once and gets three things it
 * must not build itself:
 *
 * - the systemd runners claim the hook (filter beltoft_services_job_hooks), so it does not wait for cron;
 * - a job that crashes (an exception, a killed process, Action Scheduler's timeout) is queued again along
 *   Reconciler::BACKOFF, staff hear of it once per error, and the action beltoft_services_job_failed lets the
 *   feature clean up. After the last backoff step the job is left alone and the watchdog tries again after a day;
 * - the watchdog (every sweep) queues a hook again that its `due` callback says should have a job and has none.
 *
 * A job that ends normally and decides to look again later still schedules itself with Jobs::schedule_once():
 * that is the feature's own rule (a wait that ends in days, a vendor that answers slowly), not plumbing. A job
 * that is stuck in progress is ended by Action Scheduler's cleaner (action_scheduler_failed_action), which
 * reaches failed() like any crash.
 */
final class Background {

	/** Filter: hook name => options. Declare with declare(). */
	public const FILTER = 'beltoft_services_background_hooks';

	/** Action fired after a registered job crashed: ( $hook, $args, $why ). */
	public const FAILED = 'beltoft_services_job_failed';

	/** Option: crash counts, key => [ n, at, over ]. */
	public const OPTION = 'beltoft_services_background_failures';

	/** A hook that used its whole backoff is not queued by the watchdog again for this long. */
	public const COOL_OFF = DAY_IN_SECONDS;

	/** Records older than this are forgotten. */
	private const KEEP = 7 * DAY_IN_SECONDS;

	/** Most jobs one watchdog run queues. */
	public const LIMIT = 500;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_job_hooks', array( __CLASS__, 'runner_hooks' ) );
		add_action( Jobs::SWEEP, array( __CLASS__, 'watchdog' ), 30 );
		add_action( 'action_scheduler_completed_action', array( __CLASS__, 'completed' ) );
	}

	/**
	 * Declares a background hook. Call it from the feature's register(), next to add_action( $hook, ... ).
	 *
	 * Options:
	 * - due (callable): returns the argument lists ( array of arrays ) that should have a job now, for the watchdog.
	 *   Cheap and read only; the job itself checks again. Without it there is no watchdog for the hook.
	 * - resource (bool): the first argument is a resource id, so the staff alert is about that resource.
	 * - label (string): what the job does, for the alert, in English.
	 * - requeue (bool): queue a crashed job again (default true).
	 *
	 * @param string               $hook    Action Scheduler hook.
	 * @param array<string, mixed> $options Options.
	 */
	public static function declare( string $hook, array $options = array() ): void {
		add_filter(
			self::FILTER,
			static function ( $hooks ) use ( $hook, $options ) {
				$hooks          = is_array( $hooks ) ? $hooks : array();
				$hooks[ $hook ] = $options;

				return $hooks;
			}
		);
	}

	/**
	 * The declared hooks, with defaults.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function hooks(): array {
		/**
		 * Filters the background hooks: hook name => options (see Background::declare()).
		 *
		 * @param array<string, array<string, mixed>> $hooks Hooks.
		 */
		$all = apply_filters( self::FILTER, array() );
		$out = array();

		foreach ( is_array( $all ) ? $all : array() as $hook => $options ) {
			if ( is_string( $hook ) && '' !== $hook ) {
				$out[ $hook ] = array_merge(
					array(
						'due'      => null,
						'resource' => true,
						'label'    => '',
						'requeue'  => true,
					),
					is_array( $options ) ? $options : array()
				);
			}
		}

		return $out;
	}

	/**
	 * Is this hook declared?
	 *
	 * @param string $hook Hook.
	 */
	public static function registered( string $hook ): bool {
		return array_key_exists( $hook, self::hooks() );
	}

	/**
	 * Filter beltoft_services_job_hooks: the declared hooks are claimed by the site runners.
	 *
	 * @param mixed $hooks Hook names.
	 * @return array<int, string>
	 */
	public static function runner_hooks( $hooks ): array {
		$hooks = is_array( $hooks ) ? $hooks : array();

		return array_values( array_unique( array_merge( $hooks, array_keys( self::hooks() ) ) ) );
	}

	/**
	 * The words for a crash, from what Action Scheduler passes (an exception, the error array of a shutdown or the
	 * cleaner's timeout in seconds).
	 *
	 * @param mixed $error Error.
	 */
	public static function why( $error ): string {
		if ( $error instanceof \Throwable ) {
			return $error->getMessage();
		}

		if ( is_array( $error ) ) {
			return (string) ( $error['message'] ?? '' );
		}

		return is_int( $error ) ? sprintf( 'stopped without finishing (marked failed after %d seconds)', $error ) : '';
	}

	/**
	 * A declared job crashed. Called from Jobs::handle_failed() for every failed job of this plugin.
	 *
	 * @param string            $hook  Hook.
	 * @param array<int, mixed> $args  Arguments.
	 * @param mixed             $error The exception, the error array or the timeout.
	 * @return bool Whether the hook is declared (and so handled here).
	 */
	public static function failed( string $hook, array $args, $error ): bool {
		$options = self::hooks()[ $hook ] ?? null;

		if ( null === $options ) {
			return false;
		}

		$why     = self::why( $error );
		$key     = self::key( $hook, $args );
		$records = self::records();
		$n       = (int) ( $records[ $key ]['n'] ?? 0 ) + 1;
		$over    = $n > count( Reconciler::BACKOFF );

		$records[ $key ] = array(
			'n'    => $n,
			'at'   => time(),
			'over' => $over,
		);
		self::save( $records );

		if ( ! $over && $options['requeue'] ) {
			Jobs::schedule_once( time() + Reconciler::BACKOFF[ $n - 1 ], $hook, $args );
		}

		$what     = '' !== (string) $options['label'] ? (string) $options['label'] : $hook;
		$resource = $options['resource'] && isset( $args[0] ) ? Resource::find( (int) $args[0] ) : null;
		$text     = sprintf(
			'Background job "%1$s" stopped with an error and %2$s: %3$s',
			$what,
			$over ? 'is left alone for a day' : 'is tried again',
			'' !== $why ? $why : 'no message'
		);

		if ( $resource ) {
			Alerts::admin( $resource, $text );
		} else {
			Alerts::notify( 'Background job stopped: ' . $what, array( $text, 'Arguments: ' . wp_json_encode( $args ) ), null, 'beltoft_services_bg_' . md5( $key . '|' . $why ), array( 'fallback' => true ) );
		}

		/**
		 * A background job crashed. Fired after it was queued again (or left alone) and staff were told. A feature
		 * cleans up what the crashed run left half done.
		 *
		 * @param string            $hook Hook.
		 * @param array<int, mixed> $args Arguments.
		 * @param string            $why  The error message.
		 */
		do_action( self::FAILED, $hook, $args, $why );

		return true;
	}

	/**
	 * Action Scheduler finished a job: a declared one starts its crash count again.
	 *
	 * @param mixed $action_id Action id.
	 */
	public static function completed( $action_id ): void {
		if ( ! class_exists( '\ActionScheduler' ) || array() === self::records() ) {
			return;
		}

		try {
			$action = \ActionScheduler::store()->fetch_action( (string) (int) $action_id );
		} catch ( \Throwable $e ) {
			return;
		}

		$hook = method_exists( $action, 'get_hook' ) ? (string) $action->get_hook() : '';
		$args = method_exists( $action, 'get_args' ) ? (array) $action->get_args() : array();
		$key  = self::key( $hook, $args );
		$all  = self::records();

		if ( isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
			self::save( $all );
		}
	}

	/**
	 * The watchdog, on every sweep: a declared hook whose `due` callback lists an argument set that has no job, waiting
	 * or running, gets one now. A set that used its whole backoff waits COOL_OFF first.
	 *
	 * @return int Jobs queued.
	 */
	public static function watchdog(): int {
		$queued  = 0;
		$records = self::records();

		foreach ( self::hooks() as $hook => $options ) {
			if ( ! is_callable( $options['due'] ) ) {
				continue;
			}

			try {
				$sets = (array) call_user_func( $options['due'] );
			} catch ( \Throwable $e ) {
				unset( $e );
				continue;
			}

			foreach ( $sets as $args ) {
				$args = is_array( $args ) ? array_values( $args ) : array( $args );
				$last = $records[ self::key( $hook, $args ) ] ?? null;

				if ( $last && ! empty( $last['over'] ) && time() - (int) $last['at'] < self::COOL_OFF ) {
					continue;
				}

				if ( self::active( $hook, $args ) ) {
					continue;
				}

				if ( Jobs::schedule_once( time(), $hook, $args ) ) {
					++$queued;
				}

				if ( $queued >= self::LIMIT ) {
					return $queued;
				}
			}
		}

		return $queued;
	}

	/**
	 * Runs a waiting job of a declared hook now, for a page that polls while it waits (the runners are normally a few
	 * seconds away, cron up to a minute). Safe next to the runner: Action Scheduler runs only a pending action.
	 *
	 * @param string            $hook   Hook.
	 * @param array<int, mixed> $args   Arguments.
	 * @param int               $waited Seconds the job must have been due.
	 */
	public static function run_waiting( string $hook, array $args, int $waited = 2 ): void {
		if ( ! class_exists( '\ActionScheduler' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => Jobs::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		foreach ( $ids as $id ) {
			$date = \ActionScheduler::store()->get_date( $id );

			if ( $date && $date->getTimestamp() <= time() - max( 0, $waited ) ) {
				\ActionScheduler::runner()->process_action( (int) $id, 'Beltoft Services' );
			}
		}
	}

	/**
	 * Does a job with these arguments wait or run?
	 *
	 * @param string            $hook Hook.
	 * @param array<int, mixed> $args Arguments.
	 */
	private static function active( string $hook, array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return true;
		}

		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			if ( as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => Jobs::GROUP,
					'status'   => $status,
					'per_page' => 1,
				),
				'ids'
			) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Key of a crash record.
	 *
	 * @param string            $hook Hook.
	 * @param array<int, mixed> $args Arguments.
	 */
	private static function key( string $hook, array $args ): string {
		return md5( $hook . '|' . wp_json_encode( array_values( $args ) ) );
	}

	/**
	 * The crash records.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function records(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * Stores the records, forgetting old ones.
	 *
	 * @param array<string, array<string, mixed>> $records Records.
	 */
	private static function save( array $records ): void {
		foreach ( $records as $key => $row ) {
			if ( time() - (int) ( $row['at'] ?? 0 ) > self::KEEP ) {
				unset( $records[ $key ] );
			}
		}

		update_option( self::OPTION, $records, false );
	}
}

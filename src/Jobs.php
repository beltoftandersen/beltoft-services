<?php
/**
 * Background jobs: one reconcile job per resource, and the recurring sweep.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Events;
use BeltoftLicenses\Install;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * All driver work runs here, in Action Scheduler jobs of group beltoft-services. A job takes
 * the resource's database lock, so two runners never work on the same resource at once, and
 * does nothing while the kill switch is on: it waits and keeps its place.
 */
final class Jobs {

	public const HOOK  = 'beltoft_services_reconcile';
	public const SWEEP = 'beltoft_services_sweep';
	public const ACT   = 'beltoft_services_act';
	public const USAGE = 'beltoft_services_usage_refresh';
	public const GROUP = 'beltoft-services';
	public const CRON  = '*/15 * * * *';

	/**
	 * Seconds between two usage refreshes of one resource.
	 */
	public const USAGE_INTERVAL = 300;

	/**
	 * Seconds a job waits while the kill switch is on.
	 */
	public const PAUSED_DELAY = 900;

	/**
	 * Seconds a job waits when another runner holds the resource.
	 */
	public const LOCKED_DELAY = 60;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::SWEEP, array( __CLASS__, 'sweep' ) );
		add_action( self::ACT, array( __CLASS__, 'act' ), 10, 3 );
		add_action( self::USAGE, array( __CLASS__, 'refresh_usage' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_updated' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( __CLASS__, 'settings_added' ), 10, 2 );
	}

	/**
	 * Schedules the sweep, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::SWEEP, array(), self::GROUP ) ) {
			as_schedule_cron_action( time(), self::CRON, self::SWEEP, array(), self::GROUP );
		}
	}

	/**
	 * Removes the sweep (plugin deactivation). Pending reconcile jobs keep their place.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SWEEP, array(), self::GROUP );
		}
	}

	/**
	 * Queues a reconcile job for a resource. A job already waiting for it at the same time or
	 * earlier is enough; a later one is replaced, so a change never waits out a long backoff.
	 *
	 * @param int $activation_id Activation id of the resource.
	 * @param int $delay         Seconds from now.
	 */
	public static function enqueue( int $activation_id, int $delay = 0 ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$delay   = max( 0, $delay );
		$pending = self::pending( $activation_id );

		if ( $pending && min( $pending ) <= time() + $delay ) {
			return;
		}

		foreach ( array_keys( $pending ) as $action_id ) {
			\ActionScheduler::store()->cancel_action( $action_id );
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $activation_id ), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK, array( $activation_id ), self::GROUP );
		}
	}

	/**
	 * The jobs waiting for a resource, as action id => scheduled time. Only pending jobs count:
	 * the job that is running now must be able to queue its own follow-up
	 * (as_has_scheduled_action also counts running ones).
	 *
	 * @param int $activation_id Activation id.
	 * @return array<int, int>
	 */
	public static function pending( int $activation_id ): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => array( $activation_id ),
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'none',
			)
		);

		$times = array();
		foreach ( $actions as $action_id => $action ) {
			$date = $action->get_schedule()->get_date();

			$times[ (int) $action_id ] = $date ? $date->getTimestamp() : time();
		}

		return $times;
	}

	/**
	 * The reconcile job: one step for one resource, under its lock.
	 *
	 * @param int $activation_id Activation id.
	 */
	public static function run( int $activation_id ): void {
		if ( Settings::get( 'pause_all' ) ) {
			self::enqueue( $activation_id, self::PAUSED_DELAY );
			return;
		}

		$lock = Locks::name( 'resource', $activation_id );

		if ( ! self::acquire( $lock ) ) {
			self::enqueue( $activation_id, self::LOCKED_DELAY );
			return;
		}

		try {
			// Read under the lock: whatever was decided before it was taken may be stale.
			$resource = Resource::find( $activation_id );

			if ( $resource && $resource->is_live() ) {
				Reconciler::step( $resource );
			}
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * Queues a customer action (job beltoft_services_act, args [id, action, args]). The same
	 * action with the same arguments already waiting is enough.
	 *
	 * @param int                  $activation_id Activation id of the resource.
	 * @param string               $action        Action name.
	 * @param array<string, mixed> $args          Arguments.
	 * @param int                  $delay         Seconds from now.
	 */
	public static function queue_act( int $activation_id, string $action, array $args = array(), int $delay = 0 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$job = array( $activation_id, $action, $args );

		$waiting = as_get_scheduled_actions(
			array(
				'hook'     => self::ACT,
				'args'     => $job,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		if ( $waiting ) {
			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::ACT, $job, self::GROUP );
		} else {
			as_enqueue_async_action( self::ACT, $job, self::GROUP );
		}
	}

	/**
	 * The customer action job: runs Driver::act() under the resource lock, and not while the
	 * kill switch is on (the job waits and keeps its place). The resource must still accept
	 * actions and the driver must still offer the action; otherwise the job is dropped. The
	 * result's meta is stored (never the reconciler's own keys) and a history event written.
	 * A retry is queued along the reconciler's backoff; when that runs out, or the driver
	 * fails, the admin is alerted. A failed action never changes the service's state.
	 *
	 * @param int                  $activation_id Activation id.
	 * @param string               $action        Action name.
	 * @param array<string, mixed> $args          Arguments.
	 */
	public static function act( int $activation_id, string $action, $args = array() ): void {
		$args = is_array( $args ) ? $args : array();

		if ( Settings::get( 'pause_all' ) ) {
			self::queue_act( $activation_id, $action, $args, self::PAUSED_DELAY );
			return;
		}

		$lock = Locks::name( 'resource', $activation_id );

		if ( ! self::acquire( $lock ) ) {
			self::queue_act( $activation_id, $action, $args, self::LOCKED_DELAY );
			return;
		}

		try {
			$resource = Resource::find( $activation_id );
			$driver   = $resource ? Registry::get( $resource->get( 'driver' ) ) : null;

			if ( ! $resource || ! $driver || ! $resource->accepts_actions() || ! array_key_exists( $action, $driver->customer_actions( $resource ) ) ) {
				return;
			}

			try {
				$result = $driver->act( $resource, $action, $args );
			} catch ( \Throwable $e ) {
				$result = StepResult::retry( 0, $e->getMessage() );
			}

			if ( ! $result instanceof StepResult ) {
				$result = StepResult::retry( 0, 'The driver returned no result' );
			}

			self::acted( Resource::find( $activation_id ) ?? $resource, $action, $args, $result );
		} finally {
			self::release( $lock );
		}
	}

	/**
	 * Records the outcome of a customer action.
	 *
	 * @param Resource             $r      Fresh resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments.
	 * @param StepResult           $result Outcome.
	 */
	private static function acted( Resource $r, string $action, array $args, StepResult $result ): void {
		$meta     = array_diff_key( $result->meta(), array_flip( Reconciler::RESERVED ) );
		$attempts = (int) $r->get( 'act_attempts' ) + 1;
		$backoff  = Reconciler::BACKOFF;

		if ( $result->is_retry() && $attempts <= count( $backoff ) ) {
			// Without the attempt stored the backoff would never run out: alert, never loop.
			if ( ! $r->set( array_merge( $meta, array( 'act_attempts' => $attempts ) ) ) ) {
				Alerts::write_failed( $r, 'Resource could not be updated' );
				return;
			}

			$delay = max( min( $result->delay(), $backoff[ count( $backoff ) - 1 ] ), $backoff[ $attempts - 1 ] );
			self::queue_act( $r->id(), $action, $args, $delay );
			return;
		}

		$license_id = (int) ( $r->license()['id'] ?? 0 );

		if ( ! $r->set( array_merge( $meta, array( 'act_attempts' => 0 ) ) ) ) {
			Alerts::write_failed( $r, 'Resource could not be updated' );
		}

		if ( $result->is_done() ) {
			Events::add( $license_id, 'service', '' !== $result->event() ? $result->event() : sprintf( 'Customer action "%s" done', $action ) );
			return;
		}

		$error = sprintf( 'Customer action "%s" failed: %s', $action, '' !== $result->error() ? $result->error() : 'Unknown error' );

		Events::add( $license_id, 'service', $error );
		Alerts::admin( $r, $error );
	}

	/**
	 * Queues a refresh of the driver's status (usage) for a resource, at most once per
	 * interval (filter beltoft_services_usage_refresh_interval). The refresh runs as a job, so
	 * no page or REST request waits for the service.
	 *
	 * @param int $activation_id Activation id.
	 */
	public static function queue_usage_refresh( int $activation_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$transient = 'beltoft_services_usage_queued_' . $activation_id;

		if ( false !== get_transient( $transient ) ) {
			return;
		}

		/**
		 * Seconds between two usage refreshes of one resource.
		 *
		 * @param int $seconds Seconds, default 300.
		 * @param int $activation_id Activation id.
		 */
		$interval = max( 1, (int) apply_filters( 'beltoft_services_usage_refresh_interval', self::USAGE_INTERVAL, $activation_id ) );

		set_transient( $transient, 1, $interval );

		$waiting = as_get_scheduled_actions(
			array(
				'hook'     => self::USAGE,
				'args'     => array( $activation_id ),
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		if ( ! $waiting ) {
			as_enqueue_async_action( self::USAGE, array( $activation_id ), self::GROUP );
		}
	}

	/**
	 * The usage refresh job: asks the driver for its status (which keeps the usage for the
	 * account panel). Only for a ready resource that accepts customer actions, and not while
	 * the kill switch is on (then it is skipped: the next status request queues another).
	 *
	 * @param int $activation_id Activation id.
	 */
	public static function refresh_usage( int $activation_id ): void {
		if ( Settings::get( 'pause_all' ) ) {
			return;
		}

		$resource = Resource::find( $activation_id );
		$driver   = $resource ? Registry::get( $resource->get( 'driver' ) ) : null;

		if ( ! $resource || ! $driver || ! $resource->accepts_actions() ) {
			return;
		}

		try {
			$driver->status( $resource );
		} catch ( \Throwable $e ) {
			// Usage is a convenience: the last known value stays until the next refresh.
			unset( $e );
		}
	}

	/**
	 * The recurring sweep: queues every live resource that has something to do, at its retry
	 * time if it has one. Failed resources whose operation waits for an admin are left alone
	 * (but alerted if that never happened); resources with missing meta are alerted and left
	 * alone.
	 */
	public static function sweep(): void {
		foreach ( self::live_ids() as $id ) {
			$resource = Resource::find( $id );

			if ( ! $resource ) {
				continue;
			}

			if ( ! $resource->is_valid() ) {
				Alerts::admin( $resource, $resource->get( 'last_error' ) );
				continue;
			}

			// A desired state that could not take the lock is applied by the next step.
			if ( null !== $resource->pending_desire() ) {
				self::enqueue( $id );
				continue;
			}

			// A failed operation waiting for an admin has no operation (see Reconciler::operation()).
			if ( null === Reconciler::operation( $resource ) ) {
				// A failure whose alert was never sent (no valid address then) is sent now.
				if ( 'failed' === $resource->get( 'state' ) && '' === (string) $resource->get( 'alerted' ) ) {
					Alerts::admin( $resource, $resource->get( 'last_error' ) );
				}
				continue;
			}

			self::enqueue( $id, Reconciler::wait( $resource ) );
		}
	}

	/**
	 * Ids of the live resources of every registered driver's kind.
	 *
	 * @return int[]
	 */
	private static function live_ids(): array {
		global $wpdb;

		$ids = array();

		foreach ( Registry::kinds() as $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
			$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE kind = %s AND deactivated_at IS NULL ORDER BY id ASC', Install::tables()['activations'], $kind ) );
			$ids  = array_merge( $ids, array_map( 'intval', (array) $rows ) );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * When the kill switch is turned off, the jobs it held back run now. Jobs waiting for a
	 * retry time keep it.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public static function settings_updated( $old_value, $value ): void {
		$was = is_array( $old_value ) && ! empty( $old_value['pause_all'] );
		$is  = is_array( $value ) && ! empty( $value['pause_all'] );

		if ( $was && ! $is ) {
			self::wake();
		}
	}

	/**
	 * First save of the settings: nothing was held back yet.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New settings.
	 */
	public static function settings_added( $option, $value ): void {
		self::settings_updated( array(), $value );
	}

	/**
	 * Brings every waiting job forward to now, unless its resource waits for a retry time.
	 */
	public static function wake(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'         => self::HOOK,
				'group'        => self::GROUP,
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => time(),
				'date_compare' => '>',
				'per_page'     => -1,
			)
		);

		foreach ( $actions as $action_id => $action ) {
			$args     = $action->get_args();
			$id       = (int) ( $args[0] ?? 0 );
			$resource = $id ? Resource::find( $id ) : null;

			if ( $resource && Reconciler::wait( $resource ) > 0 ) {
				continue;
			}

			\ActionScheduler::store()->cancel_action( $action_id );

			if ( $id ) {
				self::enqueue( $id );
			}
		}
	}

	/**
	 * Takes the resource lock without waiting.
	 *
	 * @param string $name Lock name.
	 */
	private static function acquire( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) );
	}

	/**
	 * Releases the resource lock.
	 *
	 * @param string $name Lock name.
	 */
	private static function release( string $name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock, nothing to cache.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
	}
}

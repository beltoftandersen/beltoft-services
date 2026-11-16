<?php
/**
 * Customer actions in background jobs: starting one, running it, polling, parking, recording its outcome.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Events;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\InternalActions;
use BeltoftServices\Drivers\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * The life of one customer action (job beltoft_services_act): start_action() sets the Progress marker and queues the job,
 * act() runs Driver::act() under the resource lock, and the outcome is stored. Scheduling, the runner and the constants
 * stay on Jobs (which delegates here); a lost or failed job is handled by JobFailures.
 */
final class JobActions {

	/**
	 * The refusal of a second action while one of the service is pending: "Wait until <what it is doing> is done." The one busy check:
	 * start_action() asks it, and a caller that must ask again under its own lock before local work calls it too.
	 *
	 * @param Resource $r Resource.
	 * @return \WP_Error|null Error code busy, or null when nothing is pending.
	 */
	public static function busy( Resource $r ): ?\WP_Error {
		$marker = Progress::pending( $r );

		if ( null === $marker ) {
			return null;
		}

		$label = (string) $marker['label'];
		$label = '' !== $label ? mb_strtolower( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 ) : __( 'the other change', 'beltoft-services' );

		/* translators: %s: what the service is doing now, for example "adding a website". */
		return new \WP_Error( 'busy', sprintf( __( 'Wait until %s is done.', 'beltoft-services' ), $label ), array( 'status' => 409 ) );
	}

	/**
	 * The one way a route starts a customer action that a driver carries out in a job: refused while another action of the
	 * service is pending (one at a time), else the Progress marker is set and the job queued. A route checks its input first.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name (offered by the driver's customer_actions() or internal_actions()).
	 * @param string               $label  The action's label for the customer.
	 * @param array<string, mixed> $args   Arguments of the job (never a secret).
	 * @param callable|null        $prepare Stores what the job reads (a previewed list, an encrypted secret) once the service is known
	 *                                     to be free and before the job is queued: returns true, an array (the job's arguments, in place of
	 *                                     $args, for a revision known only after the store) or an error that refuses the action.
	 * @return true|\WP_Error True when queued; error code busy when another action is pending, or the error of $prepare.
	 */
	public static function start_action( Resource $r, string $action, string $label, array $args = array(), ?callable $prepare = null ) {
		// The busy check, the preparation, the marker and the queue are one step under the resource lock (a named lock is
		// re-entered by the same connection, so a prepare that takes the lock itself, or a start from inside a job, works).
		$lock = Locks::name( 'resource', $r->id() );

		if ( ! Locks::acquire( $lock, Jobs::START_LOCK_WAIT ) ) {
			return new \WP_Error( 'busy', __( 'The service is busy with another change. Please try again in a minute.', 'beltoft-services' ), array( 'status' => 409 ) );
		}

		try {
			$fresh = Resource::find( $r->id() ) ?? $r;
			$busy  = self::busy( $fresh );

			if ( null !== $busy ) {
				return $busy;
			}

			if ( null !== $prepare ) {
				$prepared = $prepare();

				if ( is_wp_error( $prepared ) ) {
					return $prepared;
				}

				// A preparation may answer the job's arguments (a stored revision), which then replace $args.
				if ( is_array( $prepared ) ) {
					$args = $prepared;
				}

				$fresh = Resource::find( $r->id() ) ?? $fresh;
			}

			Progress::mark( $fresh, $action, $label, $args );
			Jobs::queue_act( $r->id(), $action, $args );
		} finally {
			Locks::release( $lock );
		}

		return true;
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
	public static function act( int $activation_id, string $action, $args = array(), $resumed = 0 ): void {
		$args    = is_array( $args ) ? $args : array();
		$resumed = (int) $resumed;

		if ( Jobs::on_hold() ) {
			Jobs::queue_act( $activation_id, $action, $args, Jobs::PAUSED_DELAY, $resumed );
			return;
		}

		$lock = Locks::name( 'resource', $activation_id );

		if ( ! Locks::acquire( $lock, 0 ) ) {
			Jobs::queue_act( $activation_id, $action, $args, Jobs::LOCKED_DELAY, $resumed );
			return;
		}

		$outer             = Jobs::enter_run();
		$outer_resumed     = self::$resumed;
		self::$resumed     = $resumed;

		try {
			$resource = Resource::find( $activation_id );

			// A job queued again after a lost one (handle_failed) runs only while the marker it repeats is still there: when the first
			// run went on and finished (the cleaner had marked it failed too early), or another action took over, the repeat is dropped.
			if ( $resumed > 0 && $resource ) {
				$marker = $resource->get( Progress::MARKER );

				if ( ! is_array( $marker ) || (string) ( $marker['action'] ?? '' ) !== $action || (int) ( $marker['at'] ?? 0 ) !== $resumed ) {
					return;
				}
			}

			$driver   = $resource ? Registry::get( $resource->get( 'driver' ) ) : null;
			$internal = $driver instanceof InternalActions && in_array( $action, $driver->internal_actions(), true );
			$allowed = $resource && $driver && ( $internal ? $resource->is_live() && 'deleted' !== $resource->get( 'desired' ) : $resource->accepts_actions() && array_key_exists( $action, $driver->customer_actions( $resource ) ) );

			if ( ! $allowed && $resource && self::park( $resource, $action, $args ) ) {
				return;
			}

			if ( ! $allowed ) {
				// Dropped, but not silently: the customer asked for something that did not happen.
				if ( $resource ) {
					Progress::clear( $resource );
					$resource->set( array( Jobs::RESULT => self::result( $action, false ) ) );
					Events::add( (int) ( $resource->license()['id'] ?? 0 ), 'service', sprintf( 'Customer action "%s" was not run: it is not available any more', $action ) );
				}

				return;
			}

			if ( null !== $resource->get( 'act_parked_since' ) ) {
				$resource->set( array( 'act_parked_since' => null, 'act_parked_alerted' => null ) );
			}

			// Something works on the action now: the marker is believed (and resumed after a crash) from here for MARKER_TTL again.
			Progress::touch( $resource, $action );

			$result = Reconciler::call( static fn() => $driver->act( $resource, $action, $args ) );

			self::acted( Resource::find( $activation_id ) ?? $resource, $action, $args, $result );
		} finally {
			Jobs::leave_run( $outer );
			self::$resumed     = $outer_resumed;
			Locks::release( $lock );
		}
	}

	/**
	 * Click time of the marker the running act job repeats (0: not a repeat); the job's own re-queues (retry, poll, park) keep it.
	 *
	 * @var int
	 */
	private static $resumed = 0;

	/** Seconds between looks at a parked action. */
	private const PARK_DELAY = 300;

	/** Seconds after which a parked action is dropped, and after which staff are told it is still parked. */
	private const PARK_MAX   = DAY_IN_SECONDS;
	private const PARK_ALERT = HOUR_IN_SECONDS;

	/**
	 * An action that cannot run now but whose work is half done (a private network attach or detach in flight: the server
	 * may be stopped or half attached) is not dropped with "not changed": it waits until the service accepts actions again.
	 * Staff are told once when it waits over an hour; after a day it is dropped as an unfinished action.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments.
	 * @return bool True when the action was parked, false when it is to be dropped.
	 */
	private static function park( Resource $r, string $action, array $args ): bool {
		/**
		 * Filters whether a customer action that cannot run now waits (true) instead of being dropped: its
		 * work is half done, e.g. a private network attach or detach in flight.
		 *
		 * @param bool     $parkable Whether the action's work is half done.
		 * @param string   $action   Action name.
		 * @param Resource $r        Resource.
		 */
		if ( ! apply_filters( 'beltoft_services_park_action', false, $action, $r ) || ! $r->is_live() || 'deleted' === $r->get( 'desired' ) ) {
			return false;
		}

		$since = (int) $r->get( 'act_parked_since' );

		if ( $since <= 0 ) {
			$since = Jobs::now();

			if ( ! $r->set( array( 'act_parked_since' => $since ) ) ) {
				return false;
			}
		}

		$age = Jobs::now() - $since;

		if ( $age > self::PARK_MAX ) {
			$r->set( array( 'act_parked_since' => null, 'act_parked_alerted' => null, Progress::MARKER => null, Jobs::RESULT => self::result( $action, false, true ) ) );
			Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', sprintf( 'Customer action "%s" gave up after waiting a day with its work half done', $action ) );
			Alerts::admin( $r, sprintf( 'Customer action "%s" was dropped with its work half done', $action ) );
			JobFailures::failed( Resource::find( $r->id() ) ?? $r, $action, $args, 'stopped' );
			return true;
		}

		if ( $age > self::PARK_ALERT && ! $r->get( 'act_parked_alerted' ) ) {
			$r->set( array( 'act_parked_alerted' => true ) );
			Alerts::admin( $r, sprintf( 'Customer action "%s" is waiting with its work half done; the service does not accept actions', $action ) );
		}

		Jobs::queue_act( $r->id(), $action, $args, self::PARK_DELAY, self::$resumed );

		return true;
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
		// A done action closes the staff alert slot: the same failure after a success is news again (Alerts::admin dedupes a failure in a row).
		$meta     = $result->is_done() ? array_merge( $meta, array( 'alerted' => '' ) ) : $meta;
		$backoff  = Reconciler::BACKOFF;

		if ( $result->is_poll() && self::polled( $r, $action, $args, $result, $meta ) ) {
			return;
		}

		if ( $result->is_retry() && $attempts <= count( $backoff ) ) {
			// Without the attempt stored the backoff would never run out: alert, never loop.
			if ( ! $r->set( array_merge( $meta, array( 'act_attempts' => $attempts, 'waiting' => '' ) ) ) ) {
				Alerts::write_failed( $r, 'Resource could not be updated' );
				return;
			}

			$delay = Reconciler::clamp_delay( $result->delay(), $attempts );
			Jobs::queue_act( $r->id(), $action, $args, $delay, self::$resumed );
			return;
		}

		$license_id = (int) ( $r->license()['id'] ?? 0 );

		// The action is over, done or failed: the card shows the real state from here, not "requested".
		$episodes = (array) $r->get( 'act_wait_since' );
		unset( $episodes[ $action ] );

		// What the customer is told about the outcome: success only for a done action that did not refuse. The sentence is the driver's
		// own (Jobs::TEXT, also on an unexpected failure): result() cleans and caps it, a fixed text stands in when there is none.
		$refused = $result->is_refusal();
		$ok      = $result->is_done() && ! $refused;
		$said    = isset( $result->meta()[ Jobs::TEXT ] ) ? (string) $result->meta()[ Jobs::TEXT ] : '';

		// An expected refusal (the driver says so) is not a failure for staff; a sentence alone does not make it one.
		$expected = ! $result->is_done() && ! empty( $result->meta()[ Jobs::EXPECTED ] );

		if ( $expected ) {
			$refused = true;
		}

		if ( ! $r->set( array_merge( $meta, array( 'act_attempts' => 0, 'waiting' => '', 'act_wait_since' => $episodes ? $episodes : null, Progress::MARKER => null, Jobs::RESULT => self::result( $action, $ok, ( $result->is_failed() || $result->is_retry() ) && ! $refused, $said ) ) ) ) ) {
			Alerts::write_failed( $r, 'Resource could not be updated' );
		}

		if ( $result->is_done() ) {
			// An error left by an earlier try of the reconciler has no owner any more.
			Reconciler::clear_stale( Resource::find( $r->id() ) ?? $r );

			Events::add( $license_id, 'service', '' !== $result->event() ? $result->event() : sprintf( 'Customer action "%s" done', $action ) );

			/**
			 * A customer action ended well. Fired after its result is stored and its marker is gone, so a feature may queue a
			 * follow-up action of the service through Jobs::start_action() (the hosting address change looks at WordPress's
			 * address afterwards). Not fired for a refusal a driver reported as done (a `refused` answer) or a failure (see beltoft_services_action_failed).
			 *
			 * @param Resource             $r      Resource.
			 * @param string               $action Action name.
			 * @param array<string, mixed> $args   Its arguments.
			 */
			if ( $ok ) {
				do_action( 'beltoft_services_action_done', Resource::find( $r->id() ) ?? $r, $action, $args );
			}

			return;
		}

		$error = sprintf( 'Customer action "%s" failed: %s', $action, '' !== $result->error() ? $result->error() : 'Unknown error' );

		Events::add( $license_id, 'service', $error );

		if ( ! $expected ) {
			Alerts::admin( $r, $error );
		}

		JobFailures::failed( Resource::find( $r->id() ) ?? $r, $action, $args, 'failed' );
	}

	/**
	 * The outcome of a customer action for the card: {action, ok, at, text}. The text is fixed, for the customer.
	 *
	 * @param string $action Action name.
	 * @param bool   $ok     Whether it was done.
	 * @param bool   $failed Whether it failed part way (not just refused).
	 * @param string $said   The driver's own sentence (Jobs::TEXT), for a done or a refused action.
	 * @return array{action: string, ok: bool, at: int, text: string}
	 */
	public static function result( string $action, bool $ok, bool $failed = false, string $said = '' ): array {
		// A driver's sentence is plain text of at most TEXT_MAX characters, whatever it was built from.
		$text = '' === $said ? '' : mb_substr( sanitize_text_field( $said ), 0, Jobs::TEXT_MAX );
		$said = $text;

		if ( '' === $said && ! $ok ) {
			$text = $failed
				? __( 'This did not finish. We have been told and will look into it.', 'beltoft-services' )
				: __( 'This was not done. Your server was not changed.', 'beltoft-services' );
		}

		return array( 'action' => $action, 'ok' => $ok, 'at' => time(), 'text' => $text );
	}

	/**
	 * A customer action that waits for something still running: queued again in a few seconds, no attempt counted,
	 * until its wait has lasted its budget (then the counted retry takes over). Progress starts the wait again.
	 *
	 * @param Resource             $r      Fresh resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments.
	 * @param StepResult           $result Poll result.
	 * @param array<string, mixed> $meta   Driver meta.
	 * @return bool True when the poll was stored and queued.
	 */
	private static function polled( Resource $r, string $action, array $args, StepResult $result, array $meta ): bool {
		$budget   = Reconciler::poll_budget( $result->poll_budget() );
		$episodes = (array) $r->get( 'act_wait_since' );
		$episode  = is_array( $episodes[ $action ] ?? null ) ? $episodes[ $action ] : array( 'since' => 0, 'resets' => 0 );
		$state    = Reconciler::poll_episode( (int) $episode['since'], (int) $episode['resets'], $result->has_progress(), $budget, Jobs::now() );

		// One episode per action name, kept when it has run out: every later run of it counts.
		$episodes[ $action ] = array( 'since' => $state['since'], 'resets' => $state['resets'] );
		$expired             = $state['expired'];

		if ( $expired ) {
			$r->set( array( 'act_wait_since' => $episodes ) );

			return false;
		}

		if ( ! $r->set( array_merge( $meta, array( 'waiting' => $result->error(), 'act_wait_since' => $episodes ) ) ) ) {
			Alerts::write_failed( $r, 'Resource could not be updated' );
			return true;
		}

		Jobs::queue_act( $r->id(), $action, $args, $result->poll_seconds(), self::$resumed );

		return true;
	}
}

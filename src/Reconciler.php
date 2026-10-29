<?php
/**
 * Moves a resource one step towards its desired state.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Drivers\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Picks one driver operation from the resource's state and desired state, runs it and
 * records the outcome. Only Jobs::run() calls step(), under the resource lock.
 *
 * | desired | state                                              | operation              |
 * |---------|----------------------------------------------------|------------------------|
 * | deleted | any but deleted                                    | delete                 |
 * | paused  | ready, provisioning, failed, requested with steps  | pause                  |
 * | paused  | requested with no steps (nothing exists yet)       | paused, no driver call |
 * | ready   | requested, provisioning, failed after provision    | provision              |
 * | ready   | paused, failed after resume                        | resume                 |
 * | ready   | ready with plan_dirty, failed after update_plan    | update_plan            |
 *
 * Provision and resume create or start something, so the license is read again right before
 * (guard()): a license that ended after the job was queued (a chargeback right after payment,
 * an expiry that passed by a second) pauses the service instead. A license with a future
 * expiry is active and provisions normally.
 *
 * A delete first runs the driver's pause unless the resource is paused already or the driver's
 * delete stops the service itself (Drivers\StopsOnDelete, the Proxmox driver): a remote service may
 * only delete what is paused. A pause that fails stops the delete and retries as a delete.
 *
 * A failed pause or delete whose desired state is ready again resumes. A completed delete
 * makes a new resource only when the deletion asked for it (desire( 'deleted', ..., true ))
 * and the license is active.
 *
 * `attempts` counts per operation (meta `op`): it starts again when the operation or the
 * desired state changes and is never reset by failures of the same operation. A failed
 * resource does not retry the operation that failed (meta `failed_op`) by itself while the
 * desired state is still the one it failed under (meta `failed_desired`) and it has counted
 * attempts: an admin retry resets `attempts` and queues it. Another operation or a new
 * desired state (the license was revoked, say) starts with a fresh count.
 */
final class Reconciler {

	/**
	 * Seconds to wait before retry N (1-based: retry 1 waits 60 s). After the last one the
	 * resource fails.
	 */
	public const BACKOFF = array( 60, 300, 900, 3600, 14400 );

	/**
	 * Error recorded when the driver's step meta cannot be stored.
	 */
	public const META_ERROR = 'Driver meta could not be stored';

	/**
	 * Meta only the reconciler, the jobs and the resource itself write. A driver's step or
	 * action meta cannot change these.
	 */
	public const RESERVED = array( 'uuid', 'driver', 'state', 'desired', 'plan', 'plan_dirty', 'op', 'attempts', 'next_attempt_at', 'last_error', 'failed_op', 'failed_desired', 'alerted', 'protected', 'paused_at', 'warned_at', 'desired_at', 'replace_after_delete', 'reported', 'act_attempts', 'notified', 'limit_notified', 'breach_at', 'breach_reason', 'breach_alerted', 'delay_notified', 'progress_resets', 'delete_paused', 'action_pending', 'waiting', 'wait_since', 'act_wait_since' );

	/**
	 * How often one operation may restart its retry count because a run made progress. After
	 * this many, retries count normally, so a step that "progresses" without ever finishing
	 * (a VM that something keeps restarting) still ends in a failure and an alert.
	 */
	public const MAX_PROGRESS_RESETS = 10;

	/**
	 * The state each operation reaches when it is done.
	 */
	private const REACHES = array(
		'provision'   => 'ready',
		'resume'      => 'ready',
		'pause'       => 'paused',
		'delete'      => 'deleted',
		'update_plan' => 'ready',
	);

	/**
	 * The operation a resource needs now, or null when there is nothing to do (or it waits
	 * for an admin retry).
	 *
	 * @param Resource $r Resource.
	 */
	public static function operation( Resource $r ): ?string {
		if ( ! $r->is_valid() ) {
			return null;
		}

		$operation = self::wanted( $r );

		if (
			null !== $operation
			&& 'failed' === $r->get( 'state' )
			&& $r->get( 'failed_op' ) === $operation
			&& $r->get( 'failed_desired' ) === $r->get( 'desired' )
			&& $r->get( 'attempts' ) > 0
		) {
			return null;
		}

		return $operation;
	}

	/**
	 * The operation the table gives for the state and desired state.
	 *
	 * @param Resource $r Resource.
	 */
	private static function wanted( Resource $r ): ?string {
		$state     = $r->get( 'state' );
		$desired   = $r->get( 'desired' );
		$failed_op = 'failed' === $state ? $r->get( 'failed_op' ) : '';

		if ( 'deleted' === $desired ) {
			return 'deleted' === $state ? null : 'delete';
		}

		if ( 'paused' === $desired ) {
			return in_array( $state, array( 'ready', 'provisioning', 'failed', 'requested' ), true ) ? 'pause' : null;
		}

		if ( in_array( $state, array( 'requested', 'provisioning' ), true ) || 'provision' === $failed_op ) {
			return 'provision';
		}

		if ( 'paused' === $state || in_array( $failed_op, array( 'resume', 'pause', 'delete' ), true ) ) {
			return 'resume';
		}

		if ( ( 'ready' === $state && $r->get( 'plan_dirty' ) ) || 'update_plan' === $failed_op ) {
			return 'update_plan';
		}

		return null;
	}

	/**
	 * Seconds until the resource's operation may run: its retry time, while the operation is
	 * still the one that set it; 0 otherwise.
	 *
	 * @param Resource $r Resource.
	 */
	public static function wait( Resource $r ): int {
		$operation = self::operation( $r );

		if ( null === $operation || $r->get( 'op' ) !== $operation ) {
			return 0;
		}

		return max( 0, (int) $r->get( 'next_attempt_at' ) - time() );
	}

	/**
	 * Runs one operation for a resource that was read under its lock.
	 *
	 * @param Resource $r Resource.
	 */
	public static function step( Resource $r ): void {
		if ( ! $r->is_valid() ) {
			Alerts::admin( $r, $r->get( 'last_error' ) );
			return;
		}

		// A desired state that could not take the lock earlier is applied first. desire()
		// removes it once stored; a failed write keeps it for the next step.
		$r->apply_pending_desire();

		$operation = self::operation( $r );

		if ( null === $operation ) {
			self::clear_stale( $r );
			return;
		}

		// Early for this operation's retry (a sweep or another queueing): wait for it.
		$wait = self::wait( $r );

		if ( $wait > 0 ) {
			Jobs::enqueue( $r->id(), $wait );
			return;
		}

		// Nothing exists yet, so there is nothing to pause.
		if ( 'pause' === $operation && 'requested' === $r->get( 'state' ) && ! $r->get( 'steps' ) ) {
			if ( ! $r->set( self::cleared() ) ) {
				self::not_stored( $r );
				return;
			}
			self::move( $r, 'paused', 'Service paused before it was provisioned' );
			self::follow_up( $r );
			return;
		}

		// Nothing is created or started for a license that is no longer active.
		if ( in_array( $operation, array( 'provision', 'resume' ), true ) && self::guard( $r ) ) {
			self::follow_up( $r );
			return;
		}

		$driver_id = $r->get( 'driver' );
		$driver    = Registry::get( $driver_id );

		if ( ! $driver ) {
			// The driver's plugin may come back: wait without counting an attempt.
			Alerts::admin( $r, sprintf( 'Driver "%s" is not available', $driver_id ) );
			Jobs::enqueue( $r->id(), Jobs::PAUSED_DELAY );
			return;
		}

		if ( $r->get( 'op' ) !== $operation ) {
			$started = $r->set(
				array(
					'op'              => $operation,
					'attempts'        => 0,
					'progress_resets' => 0,
					'next_attempt_at' => null,
					'alerted'         => '',
					'waiting'         => '',
					'wait_since'      => null,
				)
			);

			if ( ! $started ) {
				self::not_stored( $r );
				return;
			}
		}

		// Decided before the state moves to deleting.
		$pause_first = 'delete' === $operation && self::needs_pause_before_delete( $r, $driver );

		if ( 'provision' === $operation && 'provisioning' !== $r->get( 'state' ) ) {
			self::move( $r, 'provisioning', 'Provisioning started' );
		} elseif ( 'delete' === $operation && 'deleting' !== $r->get( 'state' ) ) {
			self::move( $r, 'deleting', 'Deletion started' );
		}

		$plan = $r->get( 'plan' );

		// A service is always paused before it is deleted.
		if ( $pause_first ) {
			$paused = self::pause_before_delete( $r, $driver );

			if ( ! $paused ) {
				return;
			}

			$r = Resource::find( $r->id() );

			if ( ! $r ) {
				return;
			}
		}

		try {
			$result = $driver->$operation( $r );
		} catch ( \Throwable $e ) {
			$result = StepResult::retry( 0, $e->getMessage() );
		}

		if ( ! $result instanceof StepResult ) {
			$result = StepResult::retry( 0, 'The driver returned no result' );
		}

		// The driver may have run for a while: work from what is stored now.
		$fresh = Resource::find( $r->id() );

		if ( ! $fresh ) {
			return;
		}

		$meta = array_diff_key( $result->meta(), array_flip( self::RESERVED ) );

		if ( $result->is_done() ) {
			self::done( $fresh, $operation, $result, $meta, $plan );
		} elseif ( $result->is_retry() ) {
			self::retry( $fresh, $operation, $result->delay(), $result->error(), $meta, $result->has_progress(), $result->wait_until(), $result->poll_seconds(), $result->poll_budget() );
		} else {
			self::fail( $fresh, $operation, $result->error(), $meta );
		}
	}

	/**
	 * Whether the driver's pause must run before its delete: not when the resource is already
	 * paused (or was paused for this deletion), when nothing exists yet, or when the driver's
	 * delete stops the service itself (Drivers\StopsOnDelete).
	 *
	 * @param Resource $r      Resource.
	 * @param Driver   $driver Driver.
	 */
	private static function needs_pause_before_delete( Resource $r, Driver $driver ): bool {
		if ( $driver instanceof StopsOnDelete || 'paused' === $r->get( 'state' ) || $r->get( 'delete_paused' ) ) {
			return false;
		}

		return ! ( 'requested' === $r->get( 'state' ) && ! $r->get( 'steps' ) );
	}

	/**
	 * Runs the driver's pause for a resource about to be deleted. The resource keeps its state
	 * (no paused notices); meta `delete_paused` records it, so a retry of the delete does not
	 * pause again. A pause that is not done leaves the delete unattempted: it counts as an
	 * attempt of the delete and retries or fails by the usual rules.
	 *
	 * @param Resource $r      Resource.
	 * @param Driver   $driver Driver.
	 * @return bool True when the delete may go on.
	 */
	private static function pause_before_delete( Resource $r, Driver $driver ): bool {
		try {
			$result = $driver->pause( $r );
		} catch ( \Throwable $e ) {
			$result = StepResult::retry( 0, $e->getMessage() );
		}

		if ( ! $result instanceof StepResult ) {
			$result = StepResult::retry( 0, 'The driver returned no result' );
		}

		$fresh = Resource::find( $r->id() );

		if ( ! $fresh ) {
			return false;
		}

		$meta = array_diff_key( $result->meta(), array_flip( self::RESERVED ) );

		if ( $result->is_done() ) {
			if ( $fresh->set( array_merge( $meta, array( 'delete_paused' => true ) ) ) ) {
				return true;
			}

			self::retry( $fresh, 'delete', 0, self::META_ERROR, array() );
			return false;
		}

		$error = 'Pause before delete: ' . ( '' !== $result->error() ? $result->error() : 'Unknown error' );

		if ( $result->is_retry() ) {
			self::retry( $fresh, 'delete', $result->delay(), $error, $meta, $result->has_progress(), 0, $result->poll_seconds(), $result->poll_budget() );
		} else {
			self::fail( $fresh, 'delete', $error, $meta );
		}

		return false;
	}

	/**
	 * Re-reads the license before something is created or started. When it is not active (by
	 * its status or its expiry), the desired state becomes paused, with a history event, and
	 * the caller must not create or start anything. A resource with no readable license is
	 * left alone.
	 *
	 * Drivers call this too, between the steps of one run, before a step that creates or
	 * starts something (the Proxmox clone and start).
	 *
	 * @param Resource $r Resource, read under its lock.
	 * @return bool True when the license is not active: the caller stops.
	 */
	public static function guard( Resource $r ): bool {
		$license = $r->license();

		if ( ! $license || Lifecycle::is_active( $license ) ) {
			return false;
		}

		if ( 'paused' !== $r->get( 'desired' ) ) {
			$r->desire( 'paused', sprintf( 'License %s: nothing was created or started', Licenses::effective_status( $license ) ) );
		}

		return true;
	}

	/**
	 * Bookkeeping cleared when an operation completes.
	 *
	 * @return array<string, mixed>
	 */
	private static function cleared(): array {
		return array(
			'op'              => '',
			'attempts'        => 0,
			'progress_resets' => 0,
			'next_attempt_at' => null,
			'last_error'      => '',
			'waiting'         => '',
			'wait_since'      => null,
			'failed_op'       => '',
			'failed_desired'  => '',
			'alerted'         => '',
			'delete_paused'   => false,
		);
	}

	/**
	 * Clears an error or a wait that no operation owns any more (the plan was applied by another path, the desired
	 * state moved on): a state that is not failed has nothing to report. Bookkeeping keys only.
	 *
	 * @param Resource $r Resource.
	 */
	public static function clear_stale( Resource $r ): void {
		if ( 'failed' === $r->get( 'state' ) ) {
			return;
		}

		if ( '' === (string) $r->get( 'last_error' ) && '' === (string) $r->get( 'waiting' ) && ! $r->get( 'wait_since' ) ) {
			return;
		}

		$r->set( array( 'last_error' => '', 'waiting' => '', 'wait_since' => null ) );
	}

	/**
	 * Records a completed operation and queues the next step if one is needed. Without the
	 * driver's meta stored it is not complete: it counts as a retry.
	 *
	 * @param Resource             $r         Fresh resource.
	 * @param string               $operation Operation.
	 * @param StepResult           $result    Result.
	 * @param array<string, mixed> $meta      Driver meta.
	 * @param array<string, mixed> $plan      The plan the operation was given.
	 */
	private static function done( Resource $r, string $operation, StepResult $result, array $meta, array $plan ): void {
		$bookkeeping = self::cleared();

		// A plan changed while the update ran stays dirty, so it is applied next.
		if ( 'update_plan' === $operation && $r->get( 'plan' ) === $plan ) {
			$bookkeeping['plan_dirty'] = false;
		}

		if ( ! $r->set( array_merge( $meta, $bookkeeping ) ) ) {
			self::retry( $r, $operation, 0, self::META_ERROR, array() );
			return;
		}

		$event = '' !== $result->event() ? $result->event() : self::event( $operation );

		if ( 'delete' === $operation ) {
			// Closed before listeners hear of it: they see the resource as it now is.
			self::move( $r, 'deleted', $event, true );
			self::replace_deleted( $r );
			return;
		}

		self::move( $r, self::REACHES[ $operation ], $event );

		self::follow_up( $r );
	}

	/**
	 * After a deletion that was asked to be replaced (meta `replace_after_delete`), on a
	 * license that is active (renewed while the deletion ran), a new resource is made with
	 * the product's current plan and queued. Any other deletion stays a deletion.
	 *
	 * @param Resource $r The deleted, closed resource.
	 */
	private static function replace_deleted( Resource $r ): void {
		if ( ! $r->get( 'replace_after_delete' ) ) {
			return;
		}

		$license = $r->license();

		if ( ! $license || Licenses::STATUS_ACTIVE !== Licenses::effective_status( $license ) ) {
			return;
		}

		if ( Breach::refuse( $license ) ) {
			return;
		}

		$product_id   = (int) ( $license['product_id'] ?? 0 );
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$driver       = Product::driver_for( $product_id );
		$driver       = $driver ? $driver : Registry::get( $r->get( 'driver' ) );

		if ( ! $driver ) {
			Alerts::admin( $r, 'A new service could not be created after the deletion: no driver is available for the product' );
			return;
		}

		$plan     = $product_id > 0 ? Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ) : $r->get( 'plan' );
		$resource = Resource::create( $license, $driver, $plan, Lifecycle::initial_meta( $license, $driver ) );

		if ( ! $resource instanceof Resource ) {
			Alerts::admin( $r, 'A new service could not be created after the deletion: ' . $resource->get_error_message() );
			return;
		}

		Jobs::enqueue( $resource->id() );
	}

	/**
	 * Queues the next step when the stored resource still needs one (the desired state may
	 * have changed while the step ran), at its retry time if it has one.
	 *
	 * @param Resource $r Resource.
	 */
	private static function follow_up( Resource $r ): void {
		$fresh = Resource::find( $r->id() );

		if ( $fresh && null !== self::operation( $fresh ) ) {
			Jobs::enqueue( $fresh->id(), self::wait( $fresh ) );
		}
	}

	/**
	 * Counts an attempt and waits, or fails after the last retry. Driver meta that cannot be
	 * stored is dropped and the attempt is recorded with META_ERROR.
	 *
	 * @param Resource             $r         Fresh resource.
	 * @param string               $operation Operation.
	 * @param int                  $delay     Delay the driver asked for.
	 * @param string               $error     Error.
	 * @param array<string, mixed> $meta      Driver meta.
	 * @param bool                 $progress  The driver got further in this run: the count starts again at this retry (at most MAX_PROGRESS_RESETS times per operation).
	 * @param int                  $wait_until Deadline of a bounded external wait.
	 * @param int                  $poll_seconds Seconds to the next look of a poll (StepResult::poll()).
	 * @param int                  $poll_budget  Seconds the poll episode may last.
	 */
	private static function retry( Resource $r, string $operation, int $delay, string $error, array $meta, bool $progress = false, int $wait_until = 0, int $poll_seconds = 0, int $poll_budget = 0 ): void {
		$resets = $r->get( 'op' ) === $operation ? $r->get( 'progress_resets' ) : 0;

		if ( $poll_budget > 0 && self::polled( $r, $operation, $error, $meta, $progress, $resets, $poll_seconds, $poll_budget ) ) {
			return;
		}

		// A poll past its budget is a counted retry like any other, and the episode stays over (meta wait_since
		// is kept), so every further run of it counts too and the usual five end in a failure.
		$expired = $poll_budget > 0;

		if ( $wait_until > time() ) {
			$attempts = 1;
		} elseif ( $progress && $resets < self::MAX_PROGRESS_RESETS ) {
			$attempts = 1;
			++$resets;
		} else {
			$attempts = $r->get( 'attempts' ) + 1;
		}

		if ( $attempts > count( self::BACKOFF ) ) {
			self::fail( $r, $operation, $error, $meta );
			return;
		}

		// A driver cannot push its retry beyond the longest backoff.
		$delay       = max( min( $delay, self::BACKOFF[ count( self::BACKOFF ) - 1 ] ), self::BACKOFF[ $attempts - 1 ] );
		$bookkeeping = array(
			'op'              => $operation,
			'attempts'        => $attempts,
			'progress_resets' => $resets,
			'next_attempt_at' => time() + $delay,
			'last_error'      => $error,
			'waiting'         => '',
		);

		if ( ! $expired ) {
			$bookkeeping['wait_since'] = null;
		}

		if ( ! self::record( $r, $meta, $bookkeeping ) ) {
			return;
		}

		// Another operation (the desired state changed meanwhile) runs now; this one waits.
		$fresh = Resource::find( $r->id() );
		$next  = $fresh ? self::operation( $fresh ) : null;

		if ( null !== $next ) {
			Jobs::enqueue( $r->id(), $next === $operation ? $delay : 0 );
		}
	}

	/**
	 * A poll: waiting for something that still runs. Within its budget it counts no attempt, applies no backoff and
	 * writes no error: the reason is meta `waiting`, the next look is a few seconds away. A run that progressed starts
	 * the episode again (at most MAX_PROGRESS_RESETS times per operation, so a wait that "progresses" for ever still
	 * ends). Past the budget this returns false and the caller counts it as the normal retry it always was.
	 *
	 * @param Resource             $r            Fresh resource.
	 * @param string               $operation    Operation.
	 * @param string               $why          What it waits for.
	 * @param array<string, mixed> $meta         Driver meta.
	 * @param bool                 $progress     The run progressed.
	 * @param int                  $resets       Progress resets so far (before this run).
	 * @param int                  $seconds      Seconds to the next look.
	 * @param int                  $budget       Budget in seconds.
	 * @return bool True when the poll was stored and queued.
	 */
	private static function polled( Resource $r, string $operation, string $why, array $meta, bool $progress, int $resets, int $seconds, int $budget ): bool {
		/**
		 * The longest a wait may last whatever a driver asks for.
		 *
		 * @param int $seconds Default 1800.
		 */
		$budget = min( $budget, max( 1, (int) apply_filters( 'beltoft_services_poll_budget_max', 1800 ) ) );
		$since  = $r->get( 'op' ) === $operation ? (int) $r->get( 'wait_since' ) : 0;

		if ( $progress && $resets < self::MAX_PROGRESS_RESETS ) {
			++$resets;
			$since = 0;
		}

		if ( $since <= 0 ) {
			$since = time();
		}

		if ( time() - $since > $budget ) {
			return false;
		}

		$bookkeeping = array(
			'op'              => $operation,
			'progress_resets' => $resets,
			'next_attempt_at' => time() + $seconds,
			'last_error'      => '',
			'waiting'         => $why,
			'wait_since'      => $since,
		);

		if ( ! self::record( $r, $meta, $bookkeeping ) ) {
			return true;
		}

		$fresh = Resource::find( $r->id() );
		$next  = $fresh ? self::operation( $fresh ) : null;

		if ( null !== $next ) {
			Jobs::enqueue( $r->id(), $next === $operation ? $seconds : 0 );
		}

		return true;
	}

	/**
	 * Fails for good: counts the attempt, keeps the error and the operation, and tells the
	 * admin. A desired state that changed meanwhile is followed up.
	 *
	 * @param Resource             $r         Fresh resource.
	 * @param string               $operation Operation.
	 * @param string               $error     Error.
	 * @param array<string, mixed> $meta      Driver meta.
	 */
	private static function fail( Resource $r, string $operation, string $error, array $meta ): void {
		$error       = '' !== $error ? $error : 'Unknown error';
		$bookkeeping = array(
			'op'              => $operation,
			'attempts'        => $r->get( 'attempts' ) + 1,
			'next_attempt_at' => null,
			'last_error'      => $error,
			'waiting'         => '',
			'wait_since'      => null,
			'failed_op'       => $operation,
			'failed_desired'  => $r->get( 'desired' ),
		);

		if ( ! self::record( $r, $meta, $bookkeeping ) ) {
			return;
		}

		$error = $r->get( 'last_error' );

		self::move( $r, 'failed', 'Service failed: ' . $error );
		Alerts::admin( $r, $error );
		self::follow_up( $r );
	}

	/**
	 * Stores the outcome of an attempt with the driver's meta, or, if that cannot be stored,
	 * without it and with META_ERROR. When even that fails, nothing is queued: the error is
	 * logged and the sweep tries again later, so a broken write never loops.
	 *
	 * @param Resource             $r           Resource.
	 * @param array<string, mixed> $meta        Driver meta.
	 * @param array<string, mixed> $bookkeeping Reconciler meta.
	 * @return bool Whether the bookkeeping was stored.
	 */
	private static function record( Resource $r, array $meta, array $bookkeeping ): bool {
		if ( $r->set( array_merge( $meta, $bookkeeping ) ) ) {
			return true;
		}

		if ( $meta ) {
			$bookkeeping['last_error'] = self::META_ERROR;

			if ( $r->set( $bookkeeping ) ) {
				return true;
			}
		}

		self::not_stored( $r );

		return false;
	}

	/**
	 * Logs and alerts the admin that the resource could not be updated: at most once an hour
	 * per resource.
	 *
	 * @param Resource $r Resource.
	 */
	private static function not_stored( Resource $r ): void {
		if ( ! Alerts::write_failed( $r, 'Resource could not be updated' ) ) {
			return;
		}

		error_log( sprintf( 'Beltoft Services: resource %d could not be updated; the sweep will try again.', $r->id() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed database write cannot be recorded anywhere else.
	}

	/**
	 * Moves to a state, then tells listeners when it changed. With $close the resource is
	 * closed once the state is stored, before listeners are told.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $state New state.
	 * @param string   $event History text.
	 * @param bool     $close Close the resource (a completed deletion).
	 */
	private static function move( Resource $r, string $state, string $event, bool $close = false ): void {
		$old    = $r->get( 'state' );
		$stored = $r->transition( $state, $event );

		if ( $close ) {
			$r->close();
		}

		if ( ! $stored || $old === $state ) {
			return;
		}

		self::notify( $r, $old, $state );
	}

	/**
	 * Fires beltoft_services_state_changed. A listener that throws is logged and never breaks
	 * the job that moved the resource (the rest of that hook's listeners are skipped).
	 *
	 * @param Resource $r   Resource.
	 * @param string   $old Previous state.
	 * @param string   $new New state.
	 */
	public static function notify( Resource $r, string $old, string $new ): void {
		try {
			/**
			 * Fires after a resource moved to another state. A resource that reached `deleted`
			 * is already closed. Listeners must not throw; one that does is logged and skipped.
			 *
			 * @param Resource $r   Resource.
			 * @param string   $old Previous state.
			 * @param string   $new New state.
			 */
			do_action( 'beltoft_services_state_changed', $r, $old, $new );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Beltoft Services: a beltoft_services_state_changed listener failed for resource %d: %s', $r->id(), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a listener's failure must not stop the job; this is its only record.
		}
	}

	/**
	 * Default history text of a completed operation.
	 *
	 * @param string $operation Operation.
	 */
	private static function event( string $operation ): string {
		$events = array(
			'provision'   => 'Service provisioned',
			'resume'      => 'Service resumed',
			'pause'       => 'Service paused',
			'delete'      => 'Service deleted',
			'update_plan' => 'Service plan updated',
		);

		return $events[ $operation ];
	}
}

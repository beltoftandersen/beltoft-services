<?php
/**
 * Moves a resource one step towards its desired state.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Picks one driver operation from the resource's state and desired state, runs it and
 * records the outcome. Only Jobs::run() calls step(), under the resource lock.
 *
 * | state                                       | desired | operation   |
 * |---------------------------------------------|---------|-------------|
 * | requested, provisioning, failed (never ready) | ready | provision   |
 * | paused                                      | ready   | resume      |
 * | ready                                       | paused  | pause       |
 * | any but deleted                             | deleted | delete      |
 * | ready, plan_dirty                           | ready   | update_plan |
 */
final class Reconciler {

	/**
	 * Seconds to wait before retry N (1-based: retry 1 waits 60 s). After the last one the
	 * resource fails.
	 */
	public const BACKOFF = array( 60, 300, 900, 3600, 14400 );

	/**
	 * Meta only the reconciler and the resource itself write. A driver's step meta cannot
	 * change these.
	 */
	private const RESERVED = array( 'uuid', 'driver', 'state', 'desired', 'plan', 'plan_dirty', 'attempts', 'next_attempt_at', 'last_error', 'failed_from', 'alerted', 'protected', 'paused_at', 'warned_at' );

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
	 * The operation a resource needs now, or null when there is nothing to do.
	 *
	 * @param Resource $r Resource.
	 */
	public static function operation( Resource $r ): ?string {
		if ( ! $r->is_valid() ) {
			return null;
		}

		$state   = $r->get( 'state' );
		$desired = $r->get( 'desired' );

		if ( 'deleted' === $desired ) {
			return 'deleted' === $state ? null : 'delete';
		}

		if ( 'paused' === $desired ) {
			return 'ready' === $state ? 'pause' : null;
		}

		// Desired ready.
		$never_ready = 'failed' === $state && in_array( $r->get( 'failed_from' ), array( 'requested', 'provisioning' ), true );

		if ( in_array( $state, array( 'requested', 'provisioning' ), true ) || $never_ready ) {
			return 'provision';
		}

		if ( 'paused' === $state ) {
			return 'resume';
		}

		if ( 'ready' === $state && $r->get( 'plan_dirty' ) ) {
			return 'update_plan';
		}

		return null;
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

		$operation = self::operation( $r );

		if ( null === $operation ) {
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

		if ( 'provision' === $operation && 'provisioning' !== $r->get( 'state' ) ) {
			self::move( $r, 'provisioning', 'Provisioning started' );
		} elseif ( 'delete' === $operation && 'deleting' !== $r->get( 'state' ) ) {
			self::move( $r, 'deleting', 'Deletion started' );
		}

		$plan = $r->get( 'plan' );

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
			self::retry( $fresh, $result, $meta );
		} else {
			self::fail( $fresh, $result->error(), $meta );
		}
	}

	/**
	 * Records a completed operation and queues the next step if one is needed.
	 *
	 * @param Resource             $r         Fresh resource.
	 * @param string               $operation Operation.
	 * @param StepResult           $result    Result.
	 * @param array<string, mixed> $meta      Driver meta.
	 * @param array<string, mixed> $plan      The plan the operation was given.
	 */
	private static function done( Resource $r, string $operation, StepResult $result, array $meta, array $plan ): void {
		$meta['attempts']        = 0;
		$meta['next_attempt_at'] = null;
		$meta['last_error']      = '';
		$meta['failed_from']     = '';
		$meta['alerted']         = '';

		// A plan changed while the update ran stays dirty, so it is applied next.
		if ( 'update_plan' === $operation && $r->get( 'plan' ) === $plan ) {
			$meta['plan_dirty'] = false;
		}

		$r->set( $meta );

		$event = '' !== $result->event() ? $result->event() : self::event( $operation );
		self::move( $r, self::REACHES[ $operation ], $event );

		if ( 'delete' === $operation ) {
			$r->close();
			return;
		}

		$fresh = Resource::find( $r->id() );

		if ( $fresh && null !== self::operation( $fresh ) ) {
			Jobs::enqueue( $fresh->id() );
		}
	}

	/**
	 * Counts an attempt and waits, or fails after the last retry.
	 *
	 * @param Resource             $r      Fresh resource.
	 * @param StepResult           $result Result.
	 * @param array<string, mixed> $meta   Driver meta.
	 */
	private static function retry( Resource $r, StepResult $result, array $meta ): void {
		$attempts = $r->get( 'attempts' ) + 1;

		if ( $attempts > count( self::BACKOFF ) ) {
			self::fail( $r, $result->error(), $meta );
			return;
		}

		$delay = max( $result->delay(), self::BACKOFF[ $attempts - 1 ] );

		$meta['attempts']        = $attempts;
		$meta['next_attempt_at'] = time() + $delay;
		$meta['last_error']      = $result->error();

		$r->set( $meta );
		Jobs::enqueue( $r->id(), $delay );
	}

	/**
	 * Fails for good: counts the attempt, keeps the error and tells the admin.
	 *
	 * @param Resource             $r     Fresh resource.
	 * @param string               $error Error.
	 * @param array<string, mixed> $meta  Driver meta.
	 */
	private static function fail( Resource $r, string $error, array $meta ): void {
		$error = '' !== $error ? $error : 'Unknown error';
		$from  = $r->get( 'state' );

		$meta['attempts']        = $r->get( 'attempts' ) + 1;
		$meta['next_attempt_at'] = null;
		$meta['last_error']      = $error;
		$meta['failed_from']     = 'failed' === $from ? $r->get( 'failed_from' ) : $from;

		$r->set( $meta );
		self::move( $r, 'failed', 'Service failed: ' . $error );
		Alerts::admin( $r, $error );
	}

	/**
	 * Moves to a state, then tells listeners when it changed.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $state New state.
	 * @param string   $event History text.
	 */
	private static function move( Resource $r, string $state, string $event ): void {
		$old = $r->get( 'state' );

		if ( ! $r->transition( $state, $event ) || $old === $state ) {
			return;
		}

		/**
		 * Fires after a resource moved to another state.
		 *
		 * @param Resource $r   Resource.
		 * @param string   $old Previous state.
		 * @param string   $new New state.
		 */
		do_action( 'beltoft_services_state_changed', $r, $old, $state );
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

<?php
/**
 * What happens when Action Scheduler gives up on one of our jobs, and when a customer action is given up.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Crash handling for reconcile and customer action jobs: job_failed() (the hook callback, reached through Jobs::job_failed()),
 * resumable() and lapsed(), and the beltoft_services_action_failed action.
 */
final class JobFailures {

	/**
	 * Action Scheduler gave up on one of our jobs: it threw, or its PHP process died (a fatal error, a deploy that
	 * changed files under it). A build or reconcile job is queued again in a minute (the reconciler starts from the
	 * stored state, so this is safe); a customer action is not repeated, because it may have done part of its work,
	 * but it gets a failed result so the page that waits for it stops and says so, and staff are told. An action its
	 * driver says is resumable (see resumable(): its steps are kept, so a repeat is safe) is queued again once while its
	 * Progress marker is still young, and goes on where it stopped.
	 *
	 * @param mixed $action_id Action Scheduler action id.
	 * @param mixed $error     The exception, or the error array of an unexpected shutdown.
	 */
	public static function job_failed( $action_id, $error = null ): void {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		// Never let the handling of a failure end the runner that reports it.
		try {
			self::handle_failed( (int) $action_id, $error );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The body of job_failed(). One failure can be reported twice (a fatal error, then the cleaner's timeout): the admin
	 * mail is sent once per error (Alerts::admin), and a reconcile job is queued once (enqueue keeps one pending).
	 * An action that did its work and then threw in an after-execute listener is reported as failed too: rare, and visible.
	 *
	 * @param int   $action_id Action Scheduler action id.
	 * @param mixed $error     The exception, the error array of an unexpected shutdown, or the cleaner's timeout.
	 */
	private static function handle_failed( int $action_id, $error ): void {

		try {
			$action = \ActionScheduler::store()->fetch_action( (string) (int) $action_id );
		} catch ( \Throwable $e ) {
			return;
		}

		$hook = method_exists( $action, 'get_hook' ) ? (string) $action->get_hook() : '';
		$args = method_exists( $action, 'get_args' ) ? (array) $action->get_args() : array();
		$id   = (int) ( $args[0] ?? 0 );

		// A background hook a feature declared (Background::declare()): the one generic crash handling.
		if ( Background::failed( $hook, $args, $error ) ) {
			return;
		}

		if ( $id < 1 || ! in_array( $hook, array( Jobs::HOOK, Jobs::ACT ), true ) ) {
			return;
		}

		$why = $error instanceof \Throwable ? $error->getMessage() : ( is_array( $error ) ? (string) ( $error['message'] ?? '' ) : ( is_int( $error ) ? sprintf( 'stopped without finishing (marked failed after %d seconds)', $error ) : '' ) );
		$resource = Resource::find( $id );

		if ( Jobs::HOOK === $hook ) {
			// The reconciler starts again from the stored state, as after a driver exception. Staff hear of it once per error.
			$delay = $resource ? Reconciler::crashed( $resource, $why ) : 60;
			if ( $delay >= 0 ) {
				Jobs::enqueue( $id, $delay );
			}
			if ( $resource && $delay >= 0 ) {
				Alerts::admin( $resource, 'A service job stopped with an error and is tried again: ' . ( '' !== $why ? $why : 'no message' ) );
			}
			return;
		}

		if ( ! $resource ) {
			return;
		}

		$name   = (string) ( $args[1] ?? '' );
		$marker = $resource->get( Progress::MARKER );
		$at     = is_array( $marker ) ? (int) ( $marker['at'] ?? 0 ) : 0;

		// Once per marker, and only while something worked on it lately (its last run, not the click: a restore of 40 minutes
		// whose process dies late is still resumed). The repeat that is lost again is a failure. The queued job carries the marker's
		// click time, so it is dropped when the first run finished the action meanwhile (act()).
		if ( self::resumable( $resource, $name ) && Progress::alive( $marker ) > time() - Progress::MARKER_TTL && (string) ( $marker['action'] ?? '' ) === $name && (int) $resource->get( 'act_resumed_at' ) !== $at ) {
			$resource->set( array( 'act_resumed_at' => $at ) );
			Jobs::queue_act( $id, $name, (array) ( $args[2] ?? array() ), 5, $at );
			Alerts::admin( $resource, sprintf( 'Customer action "%s" stopped with an error and is queued again: %s', $name, '' !== $why ? $why : 'no message' ) );
			return;
		}

		Progress::clear( $resource );
		$resource->set( array( Jobs::RESULT => JobActions::result( $name, false, true ) ) );
		Alerts::admin( $resource, sprintf( 'Customer action "%s" stopped with an error and was not finished: %s', $name, '' !== $why ? $why : 'no message' ) );
		self::failed( $resource, $name, (array) ( $args[2] ?? array() ), 'stopped' );
	}

	/**
	 * May a customer action whose job was lost be queued again? Only when its driver says so: a repeat must be safe.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action name.
	 */
	public static function resumable( Resource $r, string $action ): bool {
		/**
		 * Whether a customer action whose job was lost (a crash, a deploy, an out of memory kill) is queued again, once,
		 * while its Progress marker is young. True only for an action that keeps its own steps, so a repeat starts
		 * nothing twice (the hosting changes). Servers and databases stay false: their actions may have done part of their work.
		 *
		 * @param bool   $resumable Default false.
		 * @param string $driver    Driver id.
		 * @param string $action    Action name.
		 */
		return (bool) apply_filters( 'beltoft_services_action_resumable', false, (string) $r->get( 'driver' ), $action );
	}

	/**
	 * A customer action ended without finishing (it failed for good, its job was lost, or nothing worked on it). Features
	 * clean up what it left half done.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Its arguments, when known (a lapsed one has those its marker kept).
	 * @param string               $why    failed, stopped or lapsed.
	 */
	public static function failed( Resource $r, string $action, array $args, string $why ): void {
		/**
		 * A customer action ended without finishing. Fired after its failed result is stored and staff were told.
		 *
		 * @param Resource             $r      Resource.
		 * @param string               $action Action name.
		 * @param array<string, mixed> $args   Its arguments, when known.
		 * @param string               $why    failed, stopped or lapsed.
		 */
		do_action( 'beltoft_services_action_failed', $r, $action, $args, $why );
	}

	/**
	 * A Progress marker that nothing works on any more (no job waits or runs, and it is older than MARKER_TTL): the action
	 * ends with a failed result, staff are told once (the marker is gone afterwards) and features may clean up.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action name from the marker.
	 * @param int|null $at     The marker's click time as the caller read it; the marker is read again and left alone when it is gone, another
	 *                         one, or was worked on since (the caller's Resource may be stale), so this is safe to call twice.
	 */
	public static function lapsed( Resource $r, string $action, ?int $at = null ): void {
		$fresh  = Resource::find( $r->id() ) ?? $r;
		$marker = $fresh->get( Progress::MARKER );

		if ( ! is_array( $marker ) || (string) ( $marker['action'] ?? '' ) !== $action || ( null !== $at && (int) ( $marker['at'] ?? 0 ) !== $at ) || Progress::alive( $marker ) >= time() - Progress::MARKER_TTL ) {
			return;
		}

		$r    = $fresh;
		$args = is_array( $marker['args'] ?? null ) ? $marker['args'] : array();
		$r->set( array( Progress::MARKER => null, Jobs::RESULT => JobActions::result( $action, false, true ) ) );
		Alerts::admin( $r, sprintf( 'Customer action "%s" was given up: no job worked on it for %d minutes', $action, (int) ( Progress::MARKER_TTL / 60 ) ) );
		self::failed( $r, $action, $args, 'lapsed' );
	}
}

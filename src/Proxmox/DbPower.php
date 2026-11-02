<?php
/**
 * Shut down and start of a managed database: the server's own power actions, with the database's refusals.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A database is a Proxmox VM, so `start` and `shutdown` are the actions of Actions (verified VM, re-read power, rate floors,
 * `stopped_by_customer` so a stop is not reported as down). What this class adds is only the rules of a database:
 * no power action while another operation runs (a new password, a restore, an access change, a memory policy, a public
 * IP change, a build or a plan change), none while the service is paused, held for a breach, failed or protected, and the
 * jobs that need the running database (password, memory policy, access list) answer "Start the database first." while it is
 * stopped. A restore needs no running database and works as the server's restore does. The power shown is the one the
 * last status kept (no Proxmox call).
 */
final class DbPower {

	public const ACTIONS = array( 'start', 'shutdown' );

	/**
	 * Is the action one of the two power actions?
	 *
	 * @param mixed $action Action.
	 */
	public static function is_power( $action ): bool {
		return is_string( $action ) && in_array( $action, self::ACTIONS, true );
	}

	/**
	 * The power the last status kept: running, stopped or '' (not known).
	 *
	 * @param Resource $r Database.
	 */
	public static function power( Resource $r ): string {
		$status = Operations::cached_status( $r->id() );

		return null === $status ? '' : (string) $status['power'];
	}

	/**
	 * Is the database known to be stopped?
	 *
	 * @param Resource $r Database.
	 */
	public static function stopped( Resource $r ): bool {
		return 'stopped' === self::power( $r );
	}

	/**
	 * What the offered list gets: shut down for a running database, start for a stopped one, both when the power is not known
	 * (each re-reads the VM and answers what does not apply). The refusals are in refusal(), so a refused click gets its reason.
	 *
	 * @param Resource $r Database.
	 * @return array<string, string>
	 */
	public static function offered( Resource $r ): array {
		if ( 'ready' !== $r->get( 'db_state' ) || true === $r->get( 'protected' ) ) {
			return array();
		}

		$power = self::power( $r );
		$out   = array();

		if ( 'running' !== $power ) {
			$out['start'] = __( 'Start', 'beltoft-services' );
		}

		if ( 'stopped' !== $power ) {
			$out['shutdown'] = __( 'Shut down', 'beltoft-services' );
		}

		return $out;
	}

	/**
	 * Why a power action may not be taken now, in words for the customer; null when it may. The state is the one
	 * the job sees too ($queued false: the power job itself is the act that is queued).
	 *
	 * @param Resource $r      Database.
	 * @param bool     $queued Whether another queued or running customer action counts as busy (the route), not the job.
	 */
	public static function block( Resource $r, bool $queued = true ): ?string {
		if ( true === $r->get( 'protected' ) ) {
			return __( 'This is not available for this database. Please contact support.', 'beltoft-services' );
		}

		if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || $r->on_hold() ) {
			return __( 'The database is not available now.', 'beltoft-services' );
		}

		if (
			! DbMonitor::watched( $r )
			|| '' !== (string) $r->get( 'db_reset_request' )
			|| DbPolicy::busy( $r )
			|| ! DbIp::idle( $r )
			|| ( $queued && ( Jobs::acting( $r->id() ) || 'applying' === (string) $r->get( DbAccess::STATE ) ) )
		) {
			return __( 'The database is busy. Please try again when it is ready.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * The early refusal of the route for start and shutdown: the reasons of block(), then the server's fixed floors.
	 *
	 * @param mixed    $refusal Refusal so far.
	 * @param Resource $r       Database.
	 * @param string   $action  start or shutdown.
	 * @return mixed
	 */
	public static function refusal( $refusal, Resource $r, string $action ) {
		if ( null !== $refusal ) {
			return $refusal;
		}

		$why = self::block( $r );

		if ( null !== $why ) {
			return new \WP_Error( 'beltoft_services_busy', $why, array( 'status' => 409 ) );
		}

		if ( Actions::floor_exceeded( $r, $action ) ) {
			return new \WP_Error( 'beltoft_services_rate_limited', __( 'Too many requests. Please wait a while and try again.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		return null;
	}

	/**
	 * The refusal of an action that needs the running database (a new password, the memory policy, the access list); null when
	 * the database is not known to be stopped.
	 *
	 * @param Resource $r Database.
	 */
	public static function needs_running( Resource $r ): ?\WP_Error {
		return self::stopped( $r ) ? new \WP_Error( 'beltoft_services_stopped', self::start_first(), array( 'status' => 409 ) ) : null;
	}

	/**
	 * The words for a stopped database.
	 */
	public static function start_first(): string {
		return __( 'Start the database first.', 'beltoft-services' );
	}

	/**
	 * Called by the driver after a power action ran: a started database is a new start for the monitor (its old health
	 * reading and the nightly dump are judged from now on, not from before the stop).
	 *
	 * @param Resource                    $r      Database.
	 * @param string                      $action Action.
	 * @param \BeltoftServices\Drivers\StepResult $result Result.
	 */
	public static function after( Resource $r, string $action, $result ): void {
		if ( 'start' !== $action || ! $result->is_done() ) {
			return;
		}

		$state = (array) $r->get( DbMonitor::META );

		unset( $state['down_since'], $state['down_admin'], $state['down_customer'] );
		$r->set( array( 'db_power_started_at' => time(), DbMonitor::META => $state ) );
	}
}

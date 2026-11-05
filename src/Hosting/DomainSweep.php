<?php
/**
 * Looks at the external domains of hosting accounts.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A domain the customer points at us themselves is never on the edge on the customer's word: every quarter of an hour
 * this sweep looks at each external name (SiteDns::check_external: our edge address AND the ownership TXT record), and when a
 * name became active, or stopped being, the account's routes are sent to the edge again.
 */
final class DomainSweep {

	public const HOOK = 'beltoft_services_hosting_domains';
	public const CRON = '*/15 * * * *';

	/**
	 * Hooks the job and its schedule.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the sweep once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::CRON, self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * The sweep.
	 *
	 * @return array<int, string> Hosts whose state changed, with the state.
	 */
	public static function run(): array {
		$changed = array();

		if ( Settings::get( 'pause_all' ) ) {
			return $changed;
		}

		$touched = array();

		foreach ( SiteDomains::external() as $row ) {
			$record = Records::for_resource( $row['resource_id'] );
			$node   = $record ? Nodes::get( (string) $record['node'] ) : null;

			if ( null === $node ) {
				continue;
			}

			$state = SiteDns::check_external( $row['host'], $node );

			if ( null !== $state && $state !== $row['state'] ) {
				$changed[ $row['host'] ]        = $state;
				$touched[ $row['resource_id'] ] = true;
			}
		}

		foreach ( array_keys( $touched ) as $resource_id ) {
			$r = Resource::find( (int) $resource_id );

			if ( $r && 'ready' === $r->get( 'state' ) && 'ready' === $r->get( 'desired' ) ) {
				( new HostingActions( $r ) )->sync_edge();
			}
		}

		return $changed;
	}
}

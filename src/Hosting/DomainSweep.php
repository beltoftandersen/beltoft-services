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
 * Also writes the temporary address of a website whose add stopped before the DNS record. A domain the customer
 * points at us themselves is never on the edge on the customer's word: every quarter of an hour
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
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the sweep once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON );
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

		// A website that was made but whose temporary address was not written (the DNS was busy): written now.
		foreach ( SiteDomains::pending_previews() as $row ) {
			$record = Records::for_resource( $row['resource_id'] );
			$node   = $record ? Nodes::get( (string) $record['node'] ) : null;
			$slug   = '';

			foreach ( Sites::for_resource( $row['resource_id'] ) as $site ) {
				if ( $site['id'] === $row['site_id'] ) {
					$slug = (string) $site['slug'];
				}
			}

			if ( null === $node || '' === $slug || true !== SiteDns::publish_preview( $slug, $node ) ) {
				continue;
			}

			SiteDomains::set_state( $row['host'], 'active' );
			$changed[ $row['host'] ]        = 'active';
			$touched[ $row['resource_id'] ] = true;
		}

		foreach ( array_keys( $touched ) as $resource_id ) {
			$r = Resource::find( (int) $resource_id );

			if ( $r && 'ready' === $r->get( 'state' ) && 'ready' === $r->get( 'desired' ) ) {
				( new HostingActions( $r ) )->sync_edge();
				SiteWordPress::consider_all( $r );
			}
		}

		return $changed;
	}
}

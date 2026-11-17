<?php
/**
 * Looks at the external domains of hosting accounts.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Also writes the temporary address of a website whose add stopped before the DNS record. A domain the customer
 * points at us themselves is never on the edge on the customer's word: every quarter of an hour
 * this sweep looks at each external name (SiteDns::check_external: our edge address AND the ownership TXT record). It then
 * looks at every ready hosting account the way a reconciler does: when the node is behind what the shop says (a name became
 * active, or stopped being, a change that failed half way), the change `apply_names` is queued through the shared controller
 * (Actions\Steps::start_follow(); it waits when another change runs, the next sweep looks again); otherwise WordPress's own address
 * is looked at (SiteWordPress::consider_all(), which also queues through that path). No flag of "wanted" and no wait in the sweep.
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

		foreach ( SiteDomains::external() as $row ) {
			$record = Records::for_resource( $row['resource_id'] );
			$node   = $record ? Nodes::get( (string) $record['node'] ) : null;

			if ( null === $node ) {
				continue;
			}

			$state = SiteDns::check_external( $row['host'], $node );

			if ( null !== $state && $state !== $row['state'] ) {
				$changed[ $row['host'] ] = $state;
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
			$changed[ $row['host'] ] = 'active';
		}

		$reads = SiteFacts::PER_SWEEP;

		foreach ( Resource::live_ids( HostingDriver::KIND ) as $resource_id ) {
			$r = Resource::find( (int) $resource_id );

			if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) ) {
				continue;
			}

			// Whether each website has WordPress, read again from the node about once an hour (a few accounts a round).
			if ( $reads > 0 && SiteFacts::due( $r ) ) {
				SiteFacts::refresh( $r );
				--$reads;
			}

			// Paid domains a change under way held up (to add, or to take off) are made now.
			AddonEffects::retry( $r );

			if ( ( new HostingActions( $r ) )->names_stale() ) {
				Steps::start_follow( $r, 'apply_names', array() );
			} else {
				SiteWordPress::consider_all( $r );
			}

			SiteNewDomain::sweep( $r );
		}

		return $changed;
	}
}

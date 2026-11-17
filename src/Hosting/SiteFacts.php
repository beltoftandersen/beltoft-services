<?php
/**
 * What the node says about a website's folder: does it have WordPress?
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Background;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The site flag `wp` (Sites::has_wordpress()) is the last answer of the node's read-only operation `site.facts`. It is set when the
 * website is made, and read again from the node:
 *
 * - after a restore or a staging push (they put other files back): Steps::done() asks, the job runs in the background;
 * - once an hour per account, by the domain sweep (a few accounts per round, so a node is never asked about all of them at once);
 * - when the customer opens the Websites tab and the last reading of the account is older than a day (background job, the page does not wait).
 *
 * So WordPress that the customer installs by hand gives the WordPress parts of the page within the hour, and WordPress that is deleted
 * takes them away. A node whose agent does not know the operation, or does not answer, changes nothing: the flag stays as it was.
 * A website that is still being made (pending) is never read, and a website with no flag keeps the page it always had until the first answer.
 */
final class SiteFacts {

	public const HOOK = 'beltoft_services_hosting_facts';

	/** Resource meta: when the account's websites were last read (unix time). */
	public const META = 'facts_at';

	/** Seconds between two readings of an account by the sweep. */
	public const SWEEP_AFTER = HOUR_IN_SECONDS;

	/** Seconds after which opening the Websites tab asks for a new reading. */
	public const TAB_AFTER = DAY_IN_SECONDS;

	/** Accounts one sweep round reads. */
	public const PER_SWEEP = 10;

	/**
	 * Hooks the background reading.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Background::declare(
			self::HOOK,
			array(
				'resource' => true,
				'label'    => 'Hosting website facts',
			)
		);
	}

	/**
	 * Has the account not been read for this long?
	 *
	 * @param Resource $r     Hosting resource.
	 * @param int      $older Seconds.
	 */
	public static function due( Resource $r, int $older = self::SWEEP_AFTER ): bool {
		return (int) ( Resource::find( $r->id() ) ?? $r )->get( self::META ) + $older <= Jobs::now();
	}

	/**
	 * Asks for a reading in the background (one job at a time per account).
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function ask( Resource $r ): void {
		Jobs::schedule_once( time(), self::HOOK, array( $r->id() ) );
	}

	/**
	 * The background job.
	 *
	 * @param mixed $resource_id Resource id.
	 */
	public static function run( $resource_id ): void {
		$r = Resource::find( (int) $resource_id );

		if ( $r && HostingDriver::ID === $r->get( 'driver' ) ) {
			self::refresh( $r );
		}
	}

	/**
	 * Reads every finished website of the account and stores what the node says.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, bool> Website => whether it has WordPress now, for the websites the node answered for.
	 */
	public static function refresh( Resource $r ): array {
		$out = array();

		if ( Settings::get( 'pause_all' ) || 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) ) {
			return $out;
		}

		$actions = new HostingActions( $r );

		foreach ( Sites::for_resource( $r->id() ) as $site ) {
			if ( ! empty( $site['flags']['pending'] ) ) {
				continue;
			}

			$facts = $actions->site_facts( (string) $site['slug'] );

			if ( is_wp_error( $facts ) ) {
				continue; // The node did not answer, or does not know the operation: what was stored stays.
			}

			$out[ (string) $site['slug'] ] = $facts['wordpress'];
			Sites::update( $r->id(), (string) $site['slug'], array( 'flags' => array( 'wp' => $facts['wordpress'], 'wp_checked' => Jobs::now() ) ) );
		}

		( Resource::find( $r->id() ) ?? $r )->set( array( self::META => Jobs::now() ) );

		return $out;
	}
}

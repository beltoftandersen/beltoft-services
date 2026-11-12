<?php
/**
 * Sends a website's stored country block to the node again after the node may have lost it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Alerts;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The shop keeps the country block of a website (CountryBlock, the site's flags); the node keeps its own copy in the
 * container's nginx. A restore of the account (also its undo) or of one website's files puts an older container or
 * older files back, so the node's copy can be missing or old. HostingRestore::step() calls queue() when such a round
 * has finished; the job sends the stored setting with the same operation the customer's change uses
 * (`site.country_block`, CountryActions::resend). It never holds the restore: a failure is tried again after 5 minutes,
 * 30 minutes, 2 hours and 6 hours, and then the admin is told once.
 *
 * Websites made new (the first website of an order, a website added later) have no stored block, so nothing is sent
 * for them. A website that never had a block is left alone.
 */
final class CountryResend {

	public const HOOK    = 'beltoft_services_hosting_country_resend';
	public const FIRST   = 60;
	public const BACKOFF = array( 300, 1800, 7200, 21600 );

	/**
	 * Hooks the job.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 3 );
	}

	/**
	 * Queues the send for one website, or for every website of the account with a stored block. Never throws.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Website, or '' for all.
	 */
	public static function queue( Resource $r, string $slug = '' ): void {
		try {
			Jobs::schedule_once( time() + self::FIRST, self::HOOK, array( $r->id(), $slug, 0 ) );
		} catch ( \Throwable $e ) {
			error_log( 'Beltoft Services: the country block could not be queued again: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a restore never fails over this.
		}
	}

	/**
	 * The job.
	 *
	 * @param int    $id      Hosting resource id.
	 * @param string $slug    Website, or '' for all with a stored block.
	 * @param int    $attempt Attempts made so far.
	 */
	public static function run( int $id, string $slug = '', int $attempt = 0 ): void {
		if ( Settings::get( 'pause_all' ) ) {
			Jobs::schedule_once( time() + 300, self::HOOK, array( $id, $slug, $attempt ) );

			return;
		}

		$r = Resource::find( $id );

		if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) || ! $r->is_live() ) {
			return;
		}

		// Another restore is running: the node is changing again, send when it is done.
		if ( HostingRestore::active( $r ) ) {
			Jobs::schedule_once( time() + 120, self::HOOK, array( $id, $slug, $attempt ) );

			return;
		}

		$actions = new Actions\CountryActions( $r );
		$failed  = array();

		foreach ( Sites::for_resource( $id ) as $site ) {
			if ( '' !== $slug && $site['slug'] !== $slug ) {
				continue;
			}

			if ( ! isset( $site['flags']['country_block'] ) ) {
				continue;
			}

			if ( true !== $actions->resend( (string) $site['slug'] ) ) {
				$failed[] = (string) $site['slug'];
			}
		}

		foreach ( $failed as $failed_slug ) {
			self::again( $r, $failed_slug, $attempt );
		}
	}

	/**
	 * The next attempt for a website, or the admin mail once the attempts are used.
	 *
	 * @param Resource $r       Hosting resource.
	 * @param string   $slug    Website.
	 * @param int      $attempt Attempts made so far.
	 */
	private static function again( Resource $r, string $slug, int $attempt ): void {
		if ( isset( self::BACKOFF[ $attempt ] ) ) {
			Jobs::schedule_once( time() + self::BACKOFF[ $attempt ], self::HOOK, array( $r->id(), $slug, $attempt + 1 ) );

			return;
		}

		Alerts::quietly(
			static function () use ( $r, $slug ): void {
				Alerts::admin( $r, sprintf( 'The stored country block of website "%s" could not be sent to the node after a restore. The node may not block the chosen countries. Open the website on the hosting page and save its country block again.', $slug ) );
			}
		);
	}
}

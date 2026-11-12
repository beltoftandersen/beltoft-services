<?php
/**
 * The cache set-up of every website of every hosting account (the command `wp beltoft-services hosting cache setup`).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the agent of each account's node to set up the caches of each website (operation site.cache_setup). A node whose
 * agent does not have the operation answers with a refusal, which is reported for that website and does not stop the run.
 */
final class CacheSetup {

	/**
	 * Runs the set-up.
	 *
	 * @param bool $dry  Only list what would be done.
	 * @param int  $only One account only (0: every account).
	 * @return array<int, array{resource:int, slug:string, result:string}> One row per website.
	 */
	public static function run( bool $dry, int $only = 0 ): array {
		$rows = array();

		if ( ! $dry ) {
			// The set-up installs plugins from wordpress.org: wait longer for the agent than a customer click does.
			add_filter( 'beltoft_services_hosting_action_wait', array( __CLASS__, 'wait' ) );
		}

		try {
			$ids = $only > 0 ? array( $only ) : Resource::live_ids( HostingDriver::KIND );

			foreach ( $ids as $id ) {
				$r = Resource::find( (int) $id );

				if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) || ! $r->accepts_actions() ) {
					continue;
				}

				foreach ( Sites::for_resource( $r->id() ) as $site ) {
					$rows[] = array(
						'resource' => (int) $r->id(),
						'slug'     => (string) $site['slug'],
						'result'   => $dry ? 'would set up' : self::one( $r, (string) $site['slug'] ),
					);
				}
			}
		} finally {
			remove_filter( 'beltoft_services_hosting_action_wait', array( __CLASS__, 'wait' ) );
		}

		return $rows;
	}

	/**
	 * Seconds to wait for the agent's job.
	 */
	public static function wait(): int {
		return 180;
	}

	/**
	 * One website, as a line of text: what the agent did, or why it did not.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Site.
	 */
	private static function one( Resource $r, string $slug ): string {
		if ( Settings::get( 'pause_all' ) ) {
			return 'paused';
		}

		$out = ( new HostingActions( $r ) )->cache_setup( $slug );

		if ( is_wp_error( $out ) ) {
			return 'not done: ' . $out->get_error_code();
		}

		if ( isset( $out['skipped'] ) ) {
			return 'skipped: ' . sanitize_text_field( (string) $out['skipped'] );
		}

		$parts = array();

		foreach ( array( 'page_plugin', 'object_cache', 'nginx' ) as $key ) {
			if ( isset( $out[ $key ] ) && is_string( $out[ $key ] ) ) {
				$parts[] = $key . '=' . sanitize_key( $out[ $key ] );
			}
		}

		return implode( ' ', $parts );
	}
}

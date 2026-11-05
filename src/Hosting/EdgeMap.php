<?php
/**
 * The edge routes of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of routes the edge needs for one account: every host name of its sites and the container's
 * private address behind it. In this version a site has its preview name only (`<slug>.<preview zone>`); the
 * domain records of the plan (own and external domains) extend this later. A suspended account keeps its routes
 * with the flag set, so the edge shows its suspended page instead of the site.
 */
final class EdgeMap {

	/**
	 * The preview host name of a site.
	 *
	 * @param string $slug Site slug.
	 * @return string Empty when the preview zone is not set.
	 */
	public static function preview_host( string $slug ): string {
		$zone = Config::preview_zone();

		return '' === $zone || ! Sites::valid_slug( $slug ) ? '' : $slug . '.' . $zone;
	}

	/**
	 * The routes of an account.
	 *
	 * @param int  $resource_id Activation id.
	 * @param bool $suspended   Whether the edge shows the suspended page.
	 * @return array<int, array{host: string, ip: string, suspended: bool}>
	 */
	public static function routes( int $resource_id, bool $suspended ): array {
		$record = Records::for_resource( $resource_id );

		if ( ! $record || null === $record['ip'] ) {
			return array();
		}

		$routes = array();

		foreach ( Sites::for_resource( $resource_id ) as $site ) {
			$host = self::preview_host( (string) $site['slug'] );

			if ( '' !== $host ) {
				$routes[] = array(
					'host'      => $host,
					'ip'        => $record['ip'],
					'suspended' => $suspended,
				);
			}
		}

		return $routes;
	}
}

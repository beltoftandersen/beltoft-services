<?php
/**
 * The guest adapters monitoring knows.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Monitoring;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The one registry: a driver's adapter is added on the filter `beltoft_services_monitoring_guests`, so this folder never
 * names a driver.
 */
final class Guests {

	/**
	 * Every adapter.
	 *
	 * @return Guest[]
	 */
	public static function all(): array {
		/**
		 * The monitoring adapters of the drivers.
		 *
		 * @param Guest[] $guests Adapters so far.
		 */
		$guests = apply_filters( 'beltoft_services_monitoring_guests', array() );

		return array_values( array_filter( is_array( $guests ) ? $guests : array(), static fn( $guest ) => $guest instanceof Guest ) );
	}

	/**
	 * The adapter that serves a resource, or null (not a monitored kind of service).
	 *
	 * @param Resource $r Resource.
	 */
	public static function for( Resource $r ): ?Guest {
		foreach ( self::all() as $guest ) {
			if ( $guest->owns( $r ) ) {
				return $guest;
			}
		}

		return null;
	}
}

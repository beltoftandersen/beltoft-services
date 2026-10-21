<?php
/**
 * The registered drivers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

/**
 * Looks drivers up by id. Read through the filter every time, so plugins can register late.
 */
final class Registry {

	/**
	 * All drivers, keyed by id.
	 *
	 * @return array<string, Driver>
	 */
	public static function all(): array {
		/**
		 * Filters the registered drivers.
		 *
		 * @param array<string, Driver> $drivers Drivers keyed by id.
		 */
		$drivers = apply_filters( 'beltoft_services_drivers', array() );

		$valid = array();
		foreach ( (array) $drivers as $id => $driver ) {
			if ( $driver instanceof Driver ) {
				$valid[ (string) $id ] = $driver;
			}
		}

		return $valid;
	}

	/**
	 * One driver by id.
	 *
	 * @param string $id Driver id.
	 * @return Driver|null
	 */
	public static function get( string $id ): ?Driver {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Activation kinds of all drivers.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		$kinds = array();
		foreach ( self::all() as $driver ) {
			$kinds[] = $driver->kind();
		}

		return array_values( array_unique( $kinds ) );
	}
}

<?php
/**
 * Site-wide hosting values.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * The few values that belong to the whole hosting product, not to a node or a plan: the preview zone (the
 * names of the temporary addresses), the PHP version a new site gets and the container naming prefix. Nothing
 * here names real infrastructure: every value is empty or neutral by default, saved in one option and open to a
 * filter. A settings screen comes with a later slice.
 */
final class Config {

	public const OPTION = 'beltoft_services_hosting_settings';

	/**
	 * A setting.
	 *
	 * @param string $key preview_zone, php_default or prefix.
	 * @return string
	 */
	public static function get( string $key ): string {
		$defaults = array(
			'preview_zone' => '',
			'php_default'  => '8.3',
			'prefix'       => 'c',
		);

		$saved = get_option( self::OPTION, array() );
		$value = is_array( $saved ) && isset( $saved[ $key ] ) && is_string( $saved[ $key ] ) ? $saved[ $key ] : ( $defaults[ $key ] ?? '' );

		/**
		 * Filters a hosting setting.
		 *
		 * @param string $value Value.
		 * @param string $key   Setting key.
		 */
		return (string) apply_filters( 'beltoft_services_hosting_setting', $value, $key );
	}

	/**
	 * The preview zone when it is a usable domain name, otherwise an empty string.
	 */
	public static function preview_zone(): string {
		$zone = strtolower( self::get( 'preview_zone' ) );

		return 1 === preg_match( '/^(?=.{1,200}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D', $zone ) ? $zone : '';
	}

	/**
	 * The account name of a resource: the prefix and the resource id, for example c123. It names the
	 * container host name, the database user and the edge map entry.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function account( int $resource_id ): string {
		$prefix = preg_replace( '/[^a-z0-9]/', '', strtolower( self::get( 'prefix' ) ) );

		return ( '' === $prefix ? 'c' : substr( $prefix, 0, 8 ) ) . $resource_id;
	}
}

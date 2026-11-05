<?php
/**
 * Site-wide hosting values.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The values that belong to the whole hosting product, not to a node or a plan. They are the plugin settings of
 * the Hosting tab (HostingSettings), read here with their defaults and open to a filter. Nothing names real
 * infrastructure: every default is empty or neutral.
 */
final class Config {

	/**
	 * The defaults, by short key. The setting is stored as `hosting_<key>`.
	 */
	public const DEFAULTS = array(
		'preview_zone'      => '',
		'php_default'       => '8.3',
		'php_versions'      => '8.3,8.4,8.5',
		'prefix'            => 'c',
		'tag'               => 'hosting',
		'admin_user'        => 'owner',
		'mem_overcommit'    => '1.5',
		'disk_overcommit'   => '2',
		'reserve_gb'        => '9',
		'alert_percent'     => '80',
		'db_account_cpu'    => '50',
		'db_total_cpu'      => '80',
		'db_minutes'        => '15',
	);

	/**
	 * A setting as text.
	 *
	 * @param string $key Short key, see DEFAULTS.
	 * @return string
	 */
	public static function get( string $key ): string {
		$value = Settings::get( 'hosting_' . $key );
		$value = is_scalar( $value ) && '' !== (string) $value ? (string) $value : ( self::DEFAULTS[ $key ] ?? '' );

		/**
		 * Filters a hosting setting.
		 *
		 * @param string $value Value.
		 * @param string $key   Setting key.
		 */
		return (string) apply_filters( 'beltoft_services_hosting_setting', $value, $key );
	}

	/**
	 * A setting as a number, never below a floor.
	 *
	 * @param string $key   Short key.
	 * @param float  $floor Lowest value.
	 */
	public static function number( string $key, float $floor = 0.0 ): float {
		return max( $floor, (float) self::get( $key ) );
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

	/**
	 * The PHP versions a customer may choose, in the order given.
	 *
	 * @return string[]
	 */
	public static function php_versions(): array {
		$list = array();

		foreach ( explode( ',', self::get( 'php_versions' ) ) as $v ) {
			$v = trim( $v );

			if ( 1 === preg_match( '/^\d\.\d{1,2}$/D', $v ) ) {
				$list[] = $v;
			}
		}

		return array() === $list ? array( self::DEFAULTS['php_default'] ) : array_values( array_unique( $list ) );
	}

	/**
	 * The Proxmox tag put on every container (lowercase letters, numbers, dashes).
	 */
	public static function tag(): string {
		$tag = preg_replace( '/[^a-z0-9-]/', '', strtolower( self::get( 'tag' ) ) );

		return '' === $tag ? 'hosting' : substr( $tag, 0, 30 );
	}

	/**
	 * The WordPress admin user name a new site gets when the order named none.
	 */
	public static function admin_user(): string {
		$user = strtolower( self::get( 'admin_user' ) );

		return 1 === preg_match( '/^[a-z0-9_-]{1,32}$/D', $user ) ? $user : 'owner';
	}
}

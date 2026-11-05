<?php
/**
 * Settings, Services, Hosting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The Hosting tab (the whole-product values, read through Config) and the tab of the node list
 * (NodesScreen). Plugs into the Settings filters, as ProxmoxSettings does.
 */
final class HostingSettings {

	public const TAB = 'hosting';

	/**
	 * Numeric bounds of the decimal settings: key => [lowest, highest].
	 */
	private const RANGES = array(
		'hosting_mem_overcommit'  => array( 1.0, 4.0 ),
		'hosting_disk_overcommit' => array( 1.0, 5.0 ),
		'hosting_reserve_gb'      => array( 0.0, 1024.0 ),
		'hosting_alert_percent'   => array( 10.0, 100.0 ),
		'hosting_db_account_cpu'  => array( 1.0, 100.0 ),
		'hosting_db_total_cpu'    => array( 1.0, 100.0 ),
		'hosting_db_minutes'      => array( 1.0, 1440.0 ),
	);

	/**
	 * Hooks the tabs, the fields and the bounds check.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'add_tab' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'add_section' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'add_fields' ) );
		// After Settings::sanitize() (priority 10): the shapes and bounds of the Hosting values.
		add_filter( 'sanitize_option_' . Settings::OPTION, array( __CLASS__, 'sanitize' ), 20 );
	}

	/**
	 * Adds the tabs.
	 *
	 * @param mixed $tabs Tabs so far.
	 * @return mixed
	 */
	public static function add_tab( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ]         = __( 'Hosting', 'beltoft-services' );
			$tabs[ NodesScreen::TAB ] = __( 'Hosting nodes', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * Adds the section title.
	 *
	 * @param mixed $sections Titles so far.
	 * @return mixed
	 */
	public static function add_section( $sections ) {
		if ( is_array( $sections ) ) {
			$sections[ self::TAB ] = __( 'WordPress hosting', 'beltoft-services' );
		}

		return $sections;
	}

	/**
	 * Adds the fields.
	 *
	 * @param mixed $fields Fields so far.
	 * @return mixed
	 */
	public static function add_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array(
			'tab'     => self::TAB,
			'section' => self::TAB,
			'type'    => 'text',
		);

		$list = array(
			'hosting_preview_zone'    => array( 'preview_zone', __( 'Preview zone', 'beltoft-services' ), __( 'A domain whose DNS zone this site manages. Every site gets a temporary name <name>.<zone>. Hosting provisioning waits until this is set.', 'beltoft-services' ) ),
			'hosting_php_default'     => array( 'php_default', __( 'Default PHP version', 'beltoft-services' ), __( 'A new site starts on this version, for example 8.3.', 'beltoft-services' ) ),
			'hosting_php_versions'    => array( 'php_versions', __( 'PHP versions offered', 'beltoft-services' ), __( 'Versions customers can choose, separated by commas, for example 8.3,8.4,8.5. The end-of-support dates are kept with the plan notes.', 'beltoft-services' ) ),
			'hosting_prefix'          => array( 'prefix', __( 'Account name prefix', 'beltoft-services' ), __( 'Letters and numbers. The account name is this prefix and the license resource id; it names the container, the database user and the edge entry.', 'beltoft-services' ) ),
			'hosting_tag'             => array( 'tag', __( 'Container tag', 'beltoft-services' ), __( 'A Proxmox tag put on every hosting container. Lowercase letters, numbers and dashes.', 'beltoft-services' ) ),
			'hosting_admin_user'      => array( 'admin_user', __( 'Default WordPress admin user', 'beltoft-services' ), __( 'The administrator name of a new WordPress install when the order named none.', 'beltoft-services' ) ),
			'hosting_mem_overcommit'  => array( 'mem_overcommit', __( 'Memory overcommit', 'beltoft-services' ), __( 'Memory promised to containers may be this many times the memory of a node (1 to 4). Set it from load test results.', 'beltoft-services' ) ),
			'hosting_disk_overcommit' => array( 'disk_overcommit', __( 'Disk overcommit', 'beltoft-services' ), __( 'Disk promised to containers may be this many times the real disk of a node (1 to 5), on thin storage.', 'beltoft-services' ) ),
			'hosting_reserve_gb'      => array( 'reserve_gb', __( 'Memory reserve per node, in GB', 'beltoft-services' ), __( 'Kept free for the node system, its cache, the edge and the shared database server.', 'beltoft-services' ) ),
			'hosting_alert_percent'   => array( 'alert_percent', __( 'Capacity alert at, percent', 'beltoft-services' ), __( 'The admin is told when committed memory or disk on a node reaches this share.', 'beltoft-services' ) ),
			'hosting_db_account_cpu'  => array( 'db_account_cpu', __( 'Database monitor: one account above, percent of CPU', 'beltoft-services' ), __( 'Alert when one account uses more than this share of the shared database server for the minutes below.', 'beltoft-services' ) ),
			'hosting_db_total_cpu'    => array( 'db_total_cpu', __( 'Database monitor: server above, percent of CPU', 'beltoft-services' ), __( 'Alert when the shared database server stays above this CPU share for the minutes below.', 'beltoft-services' ) ),
			'hosting_db_minutes'      => array( 'db_minutes', __( 'Database monitor: minutes', 'beltoft-services' ), '' ),
		);

		foreach ( $list as $key => $row ) {
			$fields[ $key ] = $base + array(
				'default' => Config::DEFAULTS[ $row[0] ],
				'label'   => $row[1],
				'desc'    => $row[2],
			);
		}

		return $fields;
	}

	/**
	 * Cleans the Hosting values: a zone and names in their format, decimals within their bounds.
	 *
	 * @param mixed $value The option value after Settings::sanitize().
	 * @return mixed
	 */
	public static function sanitize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( self::RANGES as $key => $range ) {
			if ( array_key_exists( $key, $value ) ) {
				$value[ $key ] = (string) min( $range[1], max( $range[0], (float) $value[ $key ] ) );
			}
		}

		if ( array_key_exists( 'hosting_preview_zone', $value ) ) {
			$zone = strtolower( trim( (string) $value['hosting_preview_zone'] ) );

			$value['hosting_preview_zone'] = 1 === preg_match( '/^(?=.{1,200}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D', $zone ) ? $zone : '';
		}

		if ( array_key_exists( 'hosting_php_default', $value ) && 1 !== preg_match( '/^\d\.\d{1,2}$/D', (string) $value['hosting_php_default'] ) ) {
			$value['hosting_php_default'] = Config::DEFAULTS['php_default'];
		}

		if ( array_key_exists( 'hosting_prefix', $value ) ) {
			$prefix                   = substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $value['hosting_prefix'] ) ), 0, 8 );
			$value['hosting_prefix'] = '' === $prefix ? Config::DEFAULTS['prefix'] : $prefix;
		}

		if ( array_key_exists( 'hosting_tag', $value ) ) {
			$tag                    = substr( (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $value['hosting_tag'] ) ), 0, 30 );
			$value['hosting_tag'] = '' === $tag ? Config::DEFAULTS['tag'] : $tag;
		}

		if ( array_key_exists( 'hosting_admin_user', $value ) && 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/D', (string) $value['hosting_admin_user'] ) ) {
			$value['hosting_admin_user'] = Config::DEFAULTS['admin_user'];
		}

		return $value;
	}
}

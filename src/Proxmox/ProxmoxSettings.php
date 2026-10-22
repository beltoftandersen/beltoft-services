<?php
/**
 * Settings → Services → Proxmox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The flat Proxmox settings, added to the generic settings page through its filters. The list of
 * clusters has its own tab (ClustersScreen), because Settings takes flat fields only.
 */
final class ProxmoxSettings {

	public const TAB = 'proxmox';

	/**
	 * Allowed range (min, max) of each number.
	 *
	 * @var array<string, array{0:int,1:int}>
	 */
	private const RANGES = array(
		'shutdown_timeout' => array( 10, 3600 ),
		'task_wait'        => array( 1, 120 ),
		'abuse_cpu'        => array( 1, 100 ),
		'abuse_hours'      => array( 1, 168 ),
		'host_reserve_gb'  => array( 0, 1024 ),
	);

	/**
	 * Hooks the tab, the fields and the extra bounds check.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'add_tab' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'add_section' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'add_fields' ) );
		// After Settings::sanitize() (priority 10, added by register_setting): the bounds and the site tag format.
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
			$tabs[ self::TAB ]             = __( 'Proxmox', 'beltoft-services' );
			$tabs[ ClustersScreen::TAB ] = __( 'Proxmox clusters', 'beltoft-services' );
			$tabs[ IpPoolScreen::TAB ]   = __( 'IP pool', 'beltoft-services' );
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
			$sections[ self::TAB ] = __( 'Proxmox virtual machines', 'beltoft-services' );
		}

		return $sections;
	}

	/**
	 * The default site tag: the host of this site.
	 */
	public static function default_site_tag(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * Whether a value can sit in the ownership line `beltoft-services:<site_tag>:<uuid>`.
	 *
	 * @param string $tag Candidate.
	 */
	private static function valid_tag( string $tag ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $tag );
	}

	/**
	 * The site tag used in VM descriptions. Never empty and never holds a colon or whitespace,
	 * whatever is stored.
	 */
	public static function site_tag(): string {
		$tag = (string) Settings::get( 'site_tag' );

		return self::valid_tag( $tag ) ? $tag : self::default_site_tag();
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
		);

		$fields['site_tag']         = $base + array(
			'type'    => 'text',
			'default' => self::default_site_tag(),
			'label'   => __( 'Site tag', 'beltoft-services' ),
			'desc'    => __( 'Written into the description of every virtual machine this site creates. Only machines that carry this tag are ever changed or deleted. Letters, numbers, dots, dashes and underscores. Do not change it once machines exist.', 'beltoft-services' ),
		);
		$fields['shutdown_timeout'] = $base + array(
			'type'    => 'number',
			'default' => 120,
			'min'     => self::RANGES['shutdown_timeout'][0],
			'label'   => __( 'Shutdown timeout in seconds', 'beltoft-services' ),
			'desc'    => __( 'How long a pause waits for a clean shutdown before the machine is stopped.', 'beltoft-services' ),
		);
		$fields['task_wait']        = $base + array(
			'type'    => 'number',
			'default' => 25,
			'min'     => self::RANGES['task_wait'][0],
			'label'   => __( 'Seconds to wait for one Proxmox task', 'beltoft-services' ),
			'desc'    => __( 'A step waits this long, then keeps the task and checks it again later. Keep it below the PHP time limit of this server.', 'beltoft-services' ),
		);
		$fields['host_reserve_gb']  = $base + array(
			'type'    => 'number',
			'default' => 4,
			'min'     => self::RANGES['host_reserve_gb'][0],
			'label'   => __( 'Memory kept free on each node, in GB', 'beltoft-services' ),
			'desc'    => __( 'A node counts as full when the memory promised to the virtual machines of this site, plus this reserve, reaches the memory of the node. It leaves room for Proxmox itself and for machines this site did not create.', 'beltoft-services' ),
		);
		$fields['abuse_mode']       = $base + array(
			'type'    => 'select',
			'default' => 'report',
			'options' => array(
				'off'    => __( 'Off', 'beltoft-services' ),
				'report' => __( 'Report only: alert, change nothing', 'beltoft-services' ),
				'pause'  => __( 'Pause the service', 'beltoft-services' ),
			),
			'label'   => __( 'Sustained high CPU', 'beltoft-services' ),
		);
		$fields['abuse_cpu']        = $base + array(
			'type'    => 'number',
			'default' => 90,
			'min'     => self::RANGES['abuse_cpu'][0],
			'label'   => __( 'High CPU means above this percent', 'beltoft-services' ),
		);
		$fields['abuse_hours']      = $base + array(
			'type'    => 'number',
			'default' => 2,
			'min'     => self::RANGES['abuse_hours'][0],
			'label'   => __( 'for at least this many hours', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * Clamps the numbers, checks the mode and the site tag, for the keys that are present.
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
				$value[ $key ] = min( $range[1], max( $range[0], (int) $value[ $key ] ) );
			}
		}

		if ( array_key_exists( 'abuse_mode', $value ) && ! in_array( $value['abuse_mode'], array( 'off', 'report', 'pause' ), true ) ) {
			$value['abuse_mode'] = 'report';
		}

		if ( array_key_exists( 'site_tag', $value ) && ! self::valid_tag( (string) $value['site_tag'] ) ) {
			$value['site_tag'] = self::default_site_tag();
		}

		return $value;
	}
}

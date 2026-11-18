<?php
/**
 * Registration and settings of the dedicated server feature.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Crypto;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The driver, and the tab Settings, Dedicated servers. The web service login of the supplier is two `secret` settings: stored
 * encrypted with Crypto, never shown again, never in code. Live ordering also needs BELTOFT_SERVICES_DEDICATED_LIVE in wp-config.php.
 */
final class ManualServerSettings {
	public const TAB = 'dedicated';

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 9 );
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'tabs' ) );
		add_filter( 'beltoft_services_settings_sections', array( __CLASS__, 'sections' ) );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ), 9 );
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		ManualServerPanel::register();
		ManualServerRoutes::register();
		ControlRoutes::register();
		DistField::register();
		OrderLine::register();
	}

	/**
	 * Adds the driver to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( is_array( $drivers ) && ! isset( $drivers[ ManualServerDriver::ID ] ) ) {
			$drivers[ ManualServerDriver::ID ] = new ManualServerDriver();
		}

		return $drivers;
	}

	/**
	 * @param mixed $tabs Tabs.
	 * @return mixed
	 */
	public static function tabs( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ] = __( 'Dedicated servers', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * @param mixed $sections Sections.
	 * @return mixed
	 */
	public static function sections( $sections ) {
		if ( is_array( $sections ) ) {
			$sections[ self::TAB ] = __( 'Dedicated servers', 'beltoft-services' );
		}

		return $sections;
	}

	/** The tab page. */
	public static function render(): void {
		echo '<form action="options.php" method="post">';
		settings_fields( Settings::PAGE );
		do_settings_sections( Settings::PAGE . '-' . self::TAB );
		submit_button();
		echo '</form>';
	}

	/**
	 * The API address, with the default.
	 */
	public static function api_url(): string {
		$url = trim( (string) Settings::get( 'dedicated_api_url' ) );

		return '' !== $url ? $url : DedicatedClients::real_url();
	}

	/**
	 * A decrypted secret setting, '' when unset or unreadable. Keep it out of logs, events and mail.
	 *
	 * @param string $key Setting key.
	 */
	public static function secret( string $key ): string {
		$stored = (string) Settings::get( $key );

		return '' === $stored ? '' : (string) Crypto::decrypt( $stored );
	}

	/**
	 * The settings.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array( 'tab' => self::TAB, 'section' => self::TAB );

		$fields['dedicated_enabled']          = $base + array( 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Order dedicated servers automatically', 'beltoft-services' ), 'desc' => __( 'Off: a paid order waits and staff are mailed to buy the server by hand and press Mark ready. On: the server is ordered through the supplier web service after payment.', 'beltoft-services' ) );
		$fields['dedicated_client']           = $base + array(
			'type'    => 'select',
			'default' => 'fake',
			'options' => array(
				'fake'        => __( 'Fake: nothing real is ordered', 'beltoft-services' ),
				'test_server' => __( 'Test server: the real client against a test address (staging). The real host is refused', 'beltoft-services' ),
				'live'        => __( 'Live: servers are ordered at the supplier and cost real money', 'beltoft-services' ),
			),
			'label'   => __( 'Supplier connection', 'beltoft-services' ),
			'desc'    => __( 'Live needs this setting, the login below AND the constant BELTOFT_SERVICES_DEDICATED_LIVE set to true in wp-config.php. Every server ordered live costs real money.', 'beltoft-services' ),
		);
		$fields['dedicated_api_url']          = $base + array( 'type' => 'url', 'default' => DedicatedClients::real_url(), 'label' => __( 'Web service address', 'beltoft-services' ), 'desc' => __( 'https only. Live accepts only the supplier host.', 'beltoft-services' ) );
		$fields['dedicated_api_user']         = $base + array( 'type' => 'secret', 'default' => '', 'label' => __( 'Web service user', 'beltoft-services' ), 'desc' => __( 'Stored encrypted and never shown again.', 'beltoft-services' ) );
		$fields['dedicated_api_password']     = $base + array( 'type' => 'secret', 'default' => '', 'label' => __( 'Web service password', 'beltoft-services' ), 'desc' => __( 'Stored encrypted and never shown again.', 'beltoft-services' ) );
		$fields['dedicated_dist']             = $base + array( 'type' => 'text', 'default' => '', 'label' => __( 'Distribution to preinstall', 'beltoft-services' ), 'desc' => __( 'The name exactly as the supplier product lists it (optional). A plan can set its own. Empty: none is preinstalled.', 'beltoft-services' ) );
		$fields['dedicated_images']           = $base + array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Systems for a reinstall', 'beltoft-services' ), 'desc' => __( 'One image file name per line, exactly as the installer lists them (the result of ls /root/images in a rescue system). Used until a reinstall has read the real list from a rescue system. Empty and no list yet: the reinstall is not offered.', 'beltoft-services' ) );
		$fields['dedicated_max_servers']      = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 100000, 'label' => __( 'Most servers ordered live', 'beltoft-services' ), 'desc' => __( 'Live orders only. 0: none may be ordered live.', 'beltoft-services' ) );
		$fields['dedicated_max_monthly_cost'] = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 10000000, 'label' => __( 'Highest monthly cost of all live servers, in whole EUR', 'beltoft-services' ), 'desc' => __( 'The sum of the cost field of the plans. An order that would pass it is refused and staff are told. 0: none may be ordered live.', 'beltoft-services' ) );
		$fields['dedicated_max_new_per_day']  = $base + array( 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 20, 'label' => __( 'Most new servers per day', 'beltoft-services' ), 'desc' => __( 'Live orders only. The supplier itself allows 20 order requests a day. 0: none may be ordered live.', 'beltoft-services' ) );
		$fields['manual_server_promise_minutes'] = $base + array( 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 10080, 'label' => __( 'Minutes to deliver', 'beltoft-services' ), 'desc' => __( 'The promise told to the customer. Staff are mailed once when a server is later than this.', 'beltoft-services' ) );

		return $fields;
	}
}

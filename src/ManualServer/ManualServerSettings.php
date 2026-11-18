<?php
/**
 * Registration of the manual server feature.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the driver and the one setting (the delivery promise in hours).
 */
final class ManualServerSettings {

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 9 );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ), 9 );
		ManualServerPanel::register();
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
	 * The setting.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( is_array( $fields ) ) {
			$fields['manual_server_promise_hours'] = array(
				'tab'     => 'general',
				'section' => 'usage',
				'type'    => 'number',
				'default' => 2,
				'min'     => 1,
				'max'     => 720,
				'label'   => __( 'Dedicated servers: hours to deliver', 'beltoft-services' ),
				'desc'    => __( 'The promise told to the customer. Staff are mailed once when a server is later than this.', 'beltoft-services' ),
			);
		}

		return $fields;
	}
}

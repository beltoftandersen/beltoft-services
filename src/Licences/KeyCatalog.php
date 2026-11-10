<?php
/**
 * What the standalone licence products sell: tier keys, their vendor items and costs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Licences;

use BeltoftServices\Domains\DomainSettings;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A licence key is a tier key: an edition ("pro" on a virtual server, "pro-physical" on a physical one) or an add-on
 * ("cloudlinux-solo"). The Openprovider item id (the id of GET /licenses/items, never a price list SKU) and the cost
 * per month come from settings (`licence_items`, `licence_costs`: one `key=value` line each) or the filters
 * `beltoft_services_licence_items` and `beltoft_services_licence_costs`. Only items known from the live catalogue or the
 * sandbox have a default; an add-on without an item is refused with an admin alert, never guessed.
 */
final class KeyCatalog {

	public const FAMILY_PLESK      = 'plesk';
	public const FAMILY_CLOUDLINUX = 'cloudlinux';
	public const FAMILY_ADDONS     = 'addons';

	/** Software slug of each product family, as the CLI creates it. */
	public const SLUGS = array(
		self::FAMILY_PLESK      => 'plesk-licence',
		self::FAMILY_CLOUDLINUX => 'cloudlinux',
		self::FAMILY_ADDONS     => 'plesk-addons',
	);

	/**
	 * The editions: tier key => name. A virtual server and a physical server use different vendor items.
	 */
	public const EDITIONS = array(
		'admin' => 'Web Admin',
		'pro'   => 'Web Pro',
		'host'  => 'Web Host',
	);

	/**
	 * The add-ons: key => array( family, name, price list SKU as a hint for the owner ). The SKU is not an item id.
	 */
	public const ADDONS = array(
		'cloudlinux-solo'       => array( self::FAMILY_CLOUDLINUX, 'CloudLinux Solo', 'CLNX-SOL' ),
		'cloudlinux-admin'      => array( self::FAMILY_CLOUDLINUX, 'CloudLinux Admin', 'CLNX-ADM' ),
		'cloudlinux-shared'     => array( self::FAMILY_CLOUDLINUX, 'CloudLinux Shared', 'CLNX-SH' ),
		'cloudlinux-shared-pro' => array( self::FAMILY_CLOUDLINUX, 'CloudLinux Shared PRO', 'CLNX-SH-PRO' ),
		'imunify360-1'          => array( self::FAMILY_ADDONS, 'Imunify360, 1 user', 'PLSK-EXT-IFY-1-M' ),
		'imunify360-30'         => array( self::FAMILY_ADDONS, 'Imunify360, up to 30 users', 'PLSK-EXT-IFY-30-M' ),
		'imunify360-250'        => array( self::FAMILY_ADDONS, 'Imunify360, up to 250 users', 'PLSK-EXT-IFY-250-M' ),
		'imunify360-unlimited'  => array( self::FAMILY_ADDONS, 'Imunify360, unlimited users', 'PLSK-EXT-IFY-UNL-M' ),
		'imunifyav-plus'        => array( self::FAMILY_ADDONS, 'ImunifyAV+', 'PLSK-EXT-RAV-PRE-M' ),
		'kernelcare'            => array( self::FAMILY_ADDONS, 'KernelCare', 'PLSK-EXT-KC-M' ),
		'spamexperts-in-1'      => array( self::FAMILY_ADDONS, 'SpamExperts incoming, 1 domain', 'PLSK-EXT-SE-I-1-M' ),
		'spamexperts-inout-1'   => array( self::FAMILY_ADDONS, 'SpamExperts incoming and outgoing, 1 domain', 'PLSK-EXT-SE-IO-1-M' ),
		'spamexperts-inout-10'  => array( self::FAMILY_ADDONS, 'SpamExperts incoming and outgoing, 10 domains', 'PLSK-EXT-SE-IO-10-M' ),
	);

	/**
	 * Items read from the live catalogue (GET /licenses/items, 2026-11-06). The settings or the filter override them.
	 */
	private const DEFAULT_ITEMS = array(
		'admin'                 => 'PLESK-12-VPS-WEB-ADMIN-1M',
		'pro'                   => 'PLESK-12-VPS-WEB-PRO-1M',
		'host'                  => 'PLESK-12-VPS-WEB-HOST-1M',
		'admin-physical'        => 'PLESK-12-WEB-ADMIN-1M',
		'pro-physical'          => 'PLESK-12-WEB-PRO-1M',
		'host-physical'         => 'PLESK-12-WEB-HOST-1M',
		'cloudlinux-solo'       => 'CLNX-SOL-1M',
		'cloudlinux-admin'      => 'CLNX-ADM-1M',
		'cloudlinux-shared'     => 'CLNX-SH-1M',
		'cloudlinux-shared-pro' => 'CLNX-SH-PRO-1M',
		'imunify360-1'          => 'ADD-IMUNIFY360-1-USER-1M',
		'imunify360-30'         => 'ADD-IMUNIFY360-30-USERS-1M',
		'imunify360-250'        => 'ADD-IMUNIFY360-250-USERS-1M',
		'imunify360-unlimited'  => 'ADD-IMUNIFY360-UNL-USERS-1M',
		'imunifyav-plus'        => 'PLSK-EXT-RAV-PRE-1M',
		'kernelcare'            => 'ADD-KERNELCARE-1M',
		'spamexperts-in-1'      => 'ADD-SPAMEXPERTS-IN-1-DOM-1M',
		'spamexperts-inout-1'   => 'ADD-SPAMEXPERTS-INOUT-1-DOM-1M',
		'spamexperts-inout-10'  => 'ADD-SPAMEXPERTS-INOUT-10-DOM-1M',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'add_drivers' ), 6 );
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'fields' ) );
	}

	/**
	 * Adds the licence key driver to the registry.
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add_drivers( $drivers ) {
		if ( ! is_array( $drivers ) ) {
			return $drivers;
		}

		if ( ! isset( $drivers[ 'licence-key' ] ) ) {
			$drivers[ 'licence-key' ] = new KeyDriver();
		}

		return $drivers;
	}

	/**
	 * Settings, Services, Domains: the item and the cost of each licence key.
	 *
	 * @param mixed $fields Fields.
	 * @return mixed
	 */
	public static function fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$base = array( 'tab' => DomainSettings::TAB, 'section' => DomainSettings::TAB );

		$fields['licence_items'] = $base + array(
			'type'    => 'textarea',
			'default' => '',
			'label'   => __( 'Licence key items', 'beltoft-services' ),
			'desc'    => __( 'One licence key and Openprovider item per line, for example kernelcare=ITEM-ID-1M. The key is an edition (admin, pro, host, or admin-physical, pro-physical, host-physical) or an add-on (cloudlinux-solo, imunify360-1 and so on). The item is the id from the licence item list, not the price list SKU.', 'beltoft-services' ),
		);
		$fields['licence_costs'] = $base + array(
			'type'    => 'textarea',
			'default' => '',
			'label'   => __( 'Licence key costs', 'beltoft-services' ),
			'desc'    => __( 'One licence key and what Openprovider charges per month in EUR excluding VAT, for example pro-physical=25.49. A key is only ordered when the paid line covers this. Empty means unknown, and the live client refuses to order.', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * "key=value" lines as a map.
	 *
	 * @param mixed $raw Setting text.
	 * @return array<string, string>
	 */
	public static function lines( $raw ): array {
		$out = array();

		foreach ( preg_split( '/\R/', is_string( $raw ) ? $raw : '' ) ?: array() as $line ) {
			$parts = explode( '=', $line, 2 );
			$key   = strtolower( trim( $parts[0] ) );

			if ( 2 === count( $parts ) && 1 === preg_match( '/^[a-z0-9_-]{1,40}$/D', $key ) && '' !== trim( $parts[1] ) ) {
				$out[ $key ] = trim( $parts[1] );
			}
		}

		return $out;
	}

	/**
	 * The keys of a product family, each with its name.
	 *
	 * @param string $family plesk, cloudlinux or addons.
	 * @return array<string, string>
	 */
	public static function keys_of( string $family ): array {
		$out = array();

		if ( self::FAMILY_PLESK === $family ) {
			foreach ( self::EDITIONS as $tier => $name ) {
				$out[ $tier ]               = $name . ' (virtual server)';
				$out[ $tier . '-physical' ] = $name . ' (physical server)';
			}

			return $out;
		}

		foreach ( self::ADDONS as $key => $row ) {
			if ( $row[0] === $family ) {
				$out[ $key ] = $row[1];
			}
		}

		return $out;
	}

	/**
	 * Is the key an edition (a Plesk licence) and not an add-on?
	 *
	 * @param string $key Licence key.
	 */
	public static function is_edition( string $key ): bool {
		return isset( self::EDITIONS[ preg_replace( '/-physical$/D', '', $key ) ?? $key ] );
	}

	/**
	 * Does the edition run on a physical server?
	 *
	 * @param string $key Licence key.
	 */
	public static function is_physical( string $key ): bool {
		return str_ends_with( $key, '-physical' ) && self::is_edition( $key );
	}

	/**
	 * Key => Openprovider item id.
	 *
	 * @return array<string, string>
	 */
	public static function items(): array {
		$items = array_filter( self::lines( Settings::get( 'licence_items' ) ) + self::DEFAULT_ITEMS, static fn( $item ) => 1 === preg_match( '/^[A-Za-z0-9._-]{3,80}$/D', (string) $item ) );

		/**
		 * The Openprovider item of each licence key.
		 *
		 * @param array<string, string> $items Key => item id.
		 */
		$filtered = apply_filters( 'beltoft_services_licence_items', $items );

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $items;
	}

	/**
	 * Key => what Openprovider charges per month in EUR excluding VAT. A key with no entry has an unknown cost.
	 *
	 * @return array<string, float>
	 */
	public static function costs(): array {
		$costs = array();

		foreach ( self::lines( Settings::get( 'licence_costs' ) ) as $key => $value ) {
			if ( is_numeric( $value ) && (float) $value >= 0 ) {
				$costs[ $key ] = (float) $value;
			}
		}

		/**
		 * What each licence key costs at Openprovider, EUR per month excluding VAT.
		 *
		 * @param array<string, float> $costs Key => cost.
		 */
		$filtered = apply_filters( 'beltoft_services_licence_costs', $costs );

		return is_array( $filtered ) ? array_map( 'floatval', $filtered ) : $costs;
	}
}

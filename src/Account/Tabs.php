<?php
/**
 * My Account tabs for domains and servers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Beltoft Licenses lists every license on one tab. This gives domains and servers their own
 * tabs, so the Licenses tab keeps the plugin keys. Which tabs exist, and what they are called,
 * can be changed with the beltoft_services_account_tabs filter.
 */
final class Tabs {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_licenses_account_groups', array( __CLASS__, 'groups' ) );
	}

	/**
	 * Adds the tabs to the ones other plugins declare.
	 *
	 * @param mixed $groups Groups so far.
	 * @return array<int, array<string, mixed>>
	 */
	public static function groups( $groups ): array {
		$groups = is_array( $groups ) ? $groups : array();
		$tabs   = array(
			array(
				'slug'  => 'domains',
				'label' => __( 'Domains', 'beltoft-services' ),
				'empty' => __( 'You have no domains yet.', 'beltoft-services' ),
				'match' => static fn( $license ): bool => self::has_kind( $license, array( 'domain', 'dns' ) ),
			),
			array(
				'slug'  => Servers::slug(),
				'label' => __( 'VPS', 'beltoft-services' ),
				'empty' => __( 'You have no servers yet.', 'beltoft-services' ),
				'match' => static fn( $license ): bool => self::has_kind( $license, array( 'vm', 'disk', 'snapshot' ) ),
			),
		);

		/**
		 * Filters the My Account tabs of the services: slug, label, empty text and a match rule.
		 * Return an empty array to keep every service on the Licenses tab.
		 *
		 * @param array<int, array<string, mixed>> $tabs Tabs.
		 */
		$tabs = apply_filters( 'beltoft_services_account_tabs', $tabs );

		return array_merge( $groups, is_array( $tabs ) ? $tabs : array() );
	}

	/**
	 * Does the license's product use a driver of one of these kinds?
	 *
	 * @param mixed                $license License row.
	 * @param array<int, string>   $kinds   Driver kinds.
	 */
	private static function has_kind( $license, array $kinds ): bool {
		if ( ! is_array( $license ) ) {
			return false;
		}

		$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );

		return $driver && in_array( $driver->kind(), $kinds, true );
	}
}

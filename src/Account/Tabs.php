<?php
/**
 * My Account tabs for domains, servers, databases and apps.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Beltoft Licenses lists every license on one tab. This gives domains, servers, databases and apps their own
 * tabs, so the Licenses tab keeps the plugin keys. Which tabs exist, and what they are called,
 * can be changed with the beltoft_services_account_tabs filter.
 */
final class Tabs {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_licenses_account_groups', array( __CLASS__, 'groups' ) );
		// After Beltoft Licenses (10) and the DNS claim item (20), before the Billing grouping and the Email position (99, 100).
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'always' ), 50 );
	}

	/**
	 * Every logged-in customer sees Servers, Databases, Domains and Email in the menu, with or without a service of
	 * the kind (Beltoft Licenses leaves a tab out when the customer has nothing on it). Each page then shows its
	 * empty state and a create button. Apps stay as they were: only for customers who have one. The items are
	 * put before Licenses and Log out; the site's menu order (beltoft_services_account_menu_order) places them.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function always( $items ) {
		if ( ! is_array( $items ) || ! is_user_logged_in() ) {
			return $items;
		}

		$labels = array();

		foreach ( self::groups( array() ) as $group ) {
			$labels[ (string) $group['slug'] ] = (string) $group['label'];
		}

		$missing = array();

		foreach ( array( ServerLinks::slug(), ServiceArea::get( Databases::class )->slug(), Domains::slug(), ServiceArea::get( Email::class )->slug(), ServiceArea::get( Ssl::class )->slug(), ServiceArea::get( Licences::class )->slug() ) as $slug ) {
			if ( ! isset( $items[ $slug ] ) && isset( $labels[ $slug ] ) ) {
				$missing[ $slug ] = $labels[ $slug ];
			}
		}

		if ( ! $missing ) {
			return $items;
		}

		$before = array( \BeltoftLicenses\Account\Endpoint::slug(), 'customer-logout' );
		$out    = array();
		$placed = false;

		foreach ( $items as $key => $label ) {
			if ( ! $placed && in_array( $key, $before, true ) ) {
				$out    += $missing;
				$placed  = true;
			}

			$out[ $key ] = $label;
		}

		return $placed ? $out : $out + $missing;
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
				'slug'  => Domains::slug(),
				'label' => __( 'Domains', 'beltoft-services' ),
				'empty' => __( 'You have no domains yet.', 'beltoft-services' ),
				'match' => static fn( $license ): bool => self::has_kind( $license, array( 'domain', 'dns' ) ),
			),
			array(
				'slug'  => ServerLinks::slug(),
				'label' => __( 'VPS', 'beltoft-services' ),
				'empty' => __( 'You have no servers yet.', 'beltoft-services' ),
				'match' => static fn( $license ): bool => self::has_kind( $license, array( 'vm', 'disk', 'snapshot', 'applic' ) ),
			),
		);

		foreach ( ServiceArea::all() as $area ) {
			$tabs[] = $area->group();
		}

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

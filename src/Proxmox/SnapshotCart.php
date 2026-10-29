<?php
/** Add one pack of snapshot slots per WooCommerce subscription. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class SnapshotCart {
	private const NONCE = 'beltoft-services-snapshots';

	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item' ), 10, 4 );
		add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( __CLASS__, 'recurring_key' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'one_each' ), 5 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_validation' ), 10, 2 );
	}

	/** Slots a variation of the pack product gives. */
	public static function slots_of( \WC_Product $variation ): int {
		return max( 0, (int) ( Product::plan( $variation->get_parent_id() ?: $variation->get_id(), $variation->get_id() )['snapshot_slots'] ?? 0 ) );
	}

	/** Published, purchasable subscription variations of the snapshot product, by slots. Empty when the site has none. */
	public static function packs(): array {
		if ( ! class_exists( '\\WC_Subscriptions_Product' ) ) {
			return array();
		}
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 30, 'meta_key' => Product::META_DRIVER, 'meta_value' => SnapshotDriver::ID ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Service catalogue is small and bounded.
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product_Variable || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
				continue;
			}
			foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
				if ( \WC_Subscriptions_Product::is_subscription( $variation ) && $variation->is_purchasable() && self::slots_of( $variation ) > 0 ) {
					$out[ $variation->get_id() ] = $variation;
				}
			}
		}
		uasort( $out, static fn( $a, $b ) => self::slots_of( $a ) <=> self::slots_of( $b ) );

		return $out;
	}

	/** Slots a server has room for on top of what it has (free, paid and the packs in the cart). */
	public static function room( Resource $server, int $in_cart = 0 ): int {
		$free = max( 0, (int) Settings::get( 'snapshot_free_slots' ) );

		// Every pack of the server holds its place, also one that is not counted right now (starting, paused, on hold).
		return max( 0, max( 1, (int) Settings::get( 'snapshot_max_slots' ) ) - $free - SnapshotMeta::reserved_slots( $server ) - $in_cart );
	}

	/**
	 * The pack a WooCommerce Subscriptions switch replaces: its server and its slots, or null when the cart data is no switch of a pack.
	 *
	 * @param array<string, mixed> $data Cart item data.
	 * @return array{server: Resource, slots: int}|null
	 */
	private static function switching( array $data ): ?array {
		$id = (int) ( $data['subscription_switch']['subscription_id'] ?? 0 );
		if ( $id < 1 && isset( $_GET['switch-subscription'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WCS validated the switch.
			$id = absint( wp_unslash( $_GET['switch-subscription'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		foreach ( $id > 0 ? Licenses::where( 'subscription_id', $id ) : array() as $license ) {
			$pack   = Resource::for_license( (int) $license['id'] );
			$server = $pack && SnapshotDriver::ID === $pack->get( 'driver' ) ? SnapshotMeta::server_of( $pack ) : null;
			if ( $server ) {
				return array( 'server' => $server, 'slots' => SnapshotMeta::slots_of( $pack ) );
			}
		}

		return null;
	}

	/** Does the switch fit: the slots it adds (new pack minus the one replaced) within the room. */
	private static function switch_fits( array $data, \WC_Product $target ): bool {
		$switch = self::switching( $data );

		return null !== $switch && self::slots_of( $target ) - $switch['slots'] <= self::room( $switch['server'] );
	}

	/** A renewal is the same pack again; a resubscribe has no server on its line (packs are bought from the server card). */
	private static function resubscribing( array $data ): bool {
		return ! empty( $data['subscription_resubscribe'] );
	}

	/** May this server take a pack: a ready, owned-by-caller-checked server that gets snapshots (qcow2). */
	private static function server_ok( ?Resource $server ): bool {
		return $server && SshKeyField::DRIVER === $server->get( 'driver' ) && 'ready' === $server->get( 'state' ) && 'ready' === $server->get( 'desired' )
			&& Lifecycle::is_active( $server->license() ) && Snapshots::eligible( $server );
	}

	public static function add_url( Resource $server, \WC_Product_Variation $pack ): string {
		if ( ! function_exists( 'wcs_get_subscription' ) || ! self::server_ok( $server ) || self::slots_of( $pack ) < 1 || self::slots_of( $pack ) > self::room( $server ) ) {
			return '';
		}
		$parent = $pack->get_parent_id();
		$driver = Product::driver_for( $parent );
		if ( ! $driver || SnapshotDriver::ID !== $driver->id() || ! $pack->is_purchasable() ) {
			return '';
		}
		$license = $server->license();
		$args    = array_merge( array(
			'add-to-cart'  => $parent,
			'variation_id' => $pack->get_id(),
			'beltoft_services_snapshot_for'   => (int) $license['id'],
			'beltoft_services_snapshot_nonce' => wp_create_nonce( self::NONCE . ':' . (int) $license['id'] ),
		), array_map( 'rawurlencode', $pack->get_variation_attributes() ) ); // add_query_arg() leaves values as they are: a "+" in a pack name must not turn into a space.

		return add_query_arg( $args, wc_get_checkout_url() );
	}

	private static function pack_product( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );

		return $driver && SnapshotDriver::ID === $driver->id();
	}

	/** The server license id the line is for, when it is the caller's own, ready and fit for snapshots; else null. */
	private static function link( array $data ) {
		$requested = isset( $_REQUEST['beltoft_services_snapshot_for'] ) && is_scalar( $_REQUEST['beltoft_services_snapshot_for'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['beltoft_services_snapshot_for'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$link      = $data['beltoft_services_snapshot_for'] ?? $requested;
		if ( ! is_scalar( $link ) || ! ctype_digit( (string) $link ) ) {
			return null;
		}
		$id              = (int) $link;
		$requested_nonce = isset( $_REQUEST['beltoft_services_snapshot_nonce'] ) && is_scalar( $_REQUEST['beltoft_services_snapshot_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['beltoft_services_snapshot_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		$nonce           = $data['beltoft_services_snapshot_nonce'] ?? $requested_nonce;
		if ( ! is_scalar( $nonce ) || ! wp_verify_nonce( (string) $nonce, self::NONCE . ':' . $id ) ) {
			return null;
		}

		return self::owned( $id ) ? $id : null;
	}

	/** The caller's own server that can take a pack. */
	private static function owned( int $license_id ): bool {
		$license = Licenses::find( $license_id );
		$server  = $license ? Resource::for_license( $license_id ) : null;
		$user    = get_current_user_id();

		return $user > 0 && $license && (int) ( $license['user_id'] ?? 0 ) === $user && self::server_ok( $server );
	}

	/** Slots of the pack lines in the cart for one server, the line being added not included. */
	private static function in_cart( int $link ): int {
		$sum = 0;
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ( $item['beltoft_services_snapshot_for'] ?? null ) === $link ) {
				$sum += self::slots_of( wc_get_product( (int) ( $item['variation_id'] ?: $item['product_id'] ) ) ?: new \WC_Product() );
			}
		}

		return $sum;
	}

	public static function validate( $valid, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ): bool {
		if ( ! $valid || ! self::pack_product( (int) $product_id ) ) {
			return (bool) $valid;
		}
		$data    = is_array( $data ) ? $data : array();
		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( ! $product || ! class_exists( '\\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
			wc_add_notice( __( 'These snapshot slots must be sold as a subscription.', 'beltoft-services' ), 'error' );
			return false;
		}
		if ( self::resubscribing( $data ) ) {
			wc_add_notice( __( 'Add snapshot slots again from your server in My account.', 'beltoft-services' ), 'error' );
			return false;
		}
		if ( DiskCart::valid_switch( (int) $product_id ) || ! empty( $data['subscription_switch'] ) ) {
			if ( ! self::switch_fits( $data, $product ) ) {
				wc_add_notice( __( 'This server cannot hold that many more snapshots.', 'beltoft-services' ), 'error' );
				return false;
			}
			return true;
		}
		if ( DiskCart::flow( $data ) ) {
			return true;
		}
		$link = self::link( $data );
		if ( null === $link || 1 !== (int) $quantity ) {
			wc_add_notice( __( 'Choose snapshot slots for a server in your account.', 'beltoft-services' ), 'error' );
			return false;
		}
		if ( self::slots_of( $product ) > self::room( Resource::for_license( $link ), self::in_cart( $link ) ) ) {
			wc_add_notice( __( 'This server cannot hold that many more snapshots.', 'beltoft-services' ), 'error' );
			return false;
		}

		return true;
	}

	/** Add a unique key before WooCommerce computes the cart ID. */
	public static function item_data( $data, $product_id ): array {
		$data = is_array( $data ) ? $data : array();
		if ( ! self::pack_product( (int) $product_id ) || DiskCart::flow( $data ) ) {
			return $data;
		}
		$link = self::link( $data );
		if ( null === $link ) {
			throw new \Exception( esc_html__( 'Choose snapshot slots for a server in your account first.', 'beltoft-services' ) );
		}
		$data['beltoft_services_snapshot_for'] = $link;
		$data['beltoft_services_snapshot_uid'] = wp_generate_uuid4();

		return $data;
	}

	public static function order_item( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( isset( $values['beltoft_services_snapshot_uid'], $values['beltoft_services_snapshot_for'] ) ) {
			$item->add_meta_data( '_beltoft_services_snapshot_uid', (string) $values['beltoft_services_snapshot_uid'], true );
			$item->add_meta_data( '_beltoft_services_snapshot_server', $values['beltoft_services_snapshot_for'], true );
		}
	}

	public static function recurring_key( string $key, array $cart_item ): string {
		$uid = (string) ( $cart_item['beltoft_services_snapshot_uid'] ?? '' );

		return '' === $uid ? $key : $key . '_snapshots_' . md5( $uid );
	}

	/** One line is one purchase: a quantity raised by a cart update or the Store API goes back to 1. */
	public static function one_each( \WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( isset( $item['beltoft_services_snapshot_uid'] ) && 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
				$cart->cart_contents[ $key ]['quantity'] = 1;
			}
		}
	}

	public static function quantity( string $html, string $cart_item_key, array $cart_item ): string {
		return isset( $cart_item['beltoft_services_snapshot_uid'] ) ? '1' : $html;
	}

	/** At checkout: the server is still the caller's and ready, and the lines together stay within the maximum. */
	public static function checkout_validation( array $data, \WP_Error $errors ): void {
		$wanted = array();
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ! self::pack_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				continue;
			}
			if ( self::resubscribing( $item ) ) {
				$errors->add( 'snapshot_resubscribe', __( 'Add snapshot slots again from your server in My account.', 'beltoft-services' ) );
				continue;
			}
			if ( ! empty( $item['subscription_switch'] ) ) {
				$target = wc_get_product( (int) ( $item['variation_id'] ?: $item['product_id'] ) );
				if ( ! $target || ! self::switch_fits( $item, $target ) ) {
					$errors->add( 'snapshot_limit', __( 'This server cannot hold that many more snapshots.', 'beltoft-services' ) );
				}
				continue;
			}
			if ( ! isset( $item['beltoft_services_snapshot_uid'] ) ) {
				continue;
			}
			$link = $item['beltoft_services_snapshot_for'] ?? null;
			if ( ! is_int( $link ) || ! self::owned( $link ) ) {
				$errors->add( 'snapshot_server_unavailable', __( 'The server for these snapshot slots is no longer available.', 'beltoft-services' ) );
				continue;
			}
			$wanted[ $link ] = (int) ( $wanted[ $link ] ?? 0 ) + self::slots_of( wc_get_product( (int) ( $item['variation_id'] ?: $item['product_id'] ) ) ?: new \WC_Product() );
		}
		foreach ( $wanted as $link => $slots ) {
			$server = Resource::for_license( (int) $link );
			if ( $server && $slots > self::room( $server ) ) {
				$errors->add( 'snapshot_limit', __( 'This server cannot hold that many more snapshots.', 'beltoft-services' ) );
			}
		}
	}
}

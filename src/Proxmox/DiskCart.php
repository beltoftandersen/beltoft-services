<?php
/** Add one extra disk per WooCommerce subscription. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DiskCart {
	private const NONCE = 'beltoft-services-disk';

	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item' ), 10, 4 );
		add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( __CLASS__, 'recurring_key' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity' ), 10, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_validation' ), 10, 2 );
	}

	public static function add_url( Resource $server, \WC_Product_Variation $size ): string {
		if ( ! function_exists( 'wcs_get_subscription' ) || 'ready' !== $server->get( 'state' ) || $server->get( 'linked' ) || SshKeyField::DRIVER !== $server->get( 'driver' ) || count( DiskMeta::for_server( $server ) ) >= max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
			return '';
		}
		$parent = $size->get_parent_id();
		$driver = Product::driver_for( $parent );
		if ( ! $driver || DiskDriver::ID !== $driver->id() || ! $size->is_purchasable() ) {
			return '';
		}
		$license = $server->license();
		$args = array_merge( array(
			'add-to-cart' => $parent,
			'variation_id' => $size->get_id(),
			'beltoft_services_disk_for' => (int) $license['id'],
			'beltoft_services_disk_nonce' => wp_create_nonce( self::NONCE . ':' . (int) $license['id'] ),
		), $size->get_variation_attributes() );
		return add_query_arg( $args, wc_get_checkout_url() );
	}

	private static function disk_product( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );
		return $driver && DiskDriver::ID === $driver->id();
	}

	private static function flow( array $data ): bool {
		foreach ( array( 'subscription_renewal', 'subscription_renewal_early', 'subscription_resubscribe', 'subscription_switch' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Confirm WCS is switching this customer's existing disk line. */
	private static function valid_switch( int $product_id ): bool {
		$subscription_id = isset( $_GET['switch-subscription'] ) ? absint( wp_unslash( $_GET['switch-subscription'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WCS validates the switch before this hook.
		$item_id = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ownership and WCS ability checked below.
		if ( ! $subscription_id || ! $item_id || ! function_exists( 'wcs_get_subscription' ) || ! class_exists( '\\WC_Subscriptions_Switcher' ) ) {
			return false;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		$item = $subscription instanceof \WC_Subscription ? $subscription->get_item( $item_id ) : null;
		return $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription, get_current_user_id() );
	}

	private static function link( array $data ) {
		$requested_link = isset( $_REQUEST['beltoft_services_disk_for'] ) && is_scalar( $_REQUEST['beltoft_services_disk_for'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['beltoft_services_disk_for'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$link = $data['beltoft_services_disk_for'] ?? $requested_link;
		if ( 'cart_server' === $link ) {
			return DiskField::cart_server() ? 'cart_server' : null;
		}
		if ( ! is_scalar( $link ) || ! ctype_digit( (string) $link ) ) {
			return null;
		}
		$id = (int) $link;
		$requested_nonce = isset( $_REQUEST['beltoft_services_disk_nonce'] ) && is_scalar( $_REQUEST['beltoft_services_disk_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['beltoft_services_disk_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		$nonce = $data['beltoft_services_disk_nonce'] ?? $requested_nonce;
		if ( ! is_scalar( $nonce ) || ! wp_verify_nonce( (string) $nonce, self::NONCE . ':' . $id ) ) {
			return null;
		}
		$license = Licenses::find( $id );
		$server = $license ? Resource::for_license( $id ) : null;
		if ( ! $server || ! $license || (int) ( $license['user_id'] ?? 0 ) !== get_current_user_id() || SshKeyField::DRIVER !== $server->get( 'driver' ) || 'ready' !== $server->get( 'state' ) || $server->get( 'linked' ) ) {
			return null;
		}
		return $id;
	}

	public static function validate( $valid, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ): bool {
		if ( ! $valid || ! self::disk_product( (int) $product_id ) ) {
			return (bool) $valid;
		}
		$data = is_array( $data ) ? $data : array();
		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( ! $product || ! class_exists( '\\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
			wc_add_notice( __( 'This extra disk must be sold as a subscription.', 'beltoft-services' ), 'error' );
			return false;
		}
		if ( self::flow( $data ) || self::valid_switch( (int) $product_id ) ) {
			return true;
		}
		$link = self::link( $data );
		if ( null === $link || 1 !== (int) $quantity ) {
			wc_add_notice( __( 'Choose one disk for a server in your account.', 'beltoft-services' ), 'error' );
			return false;
		}
		if ( is_int( $link ) ) {
			$server = Resource::for_license( $link );
			$in_cart = 0;
			foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
				$in_cart += (int) ( ( $item['beltoft_services_disk_for'] ?? null ) === $link );
			}
			if ( count( DiskMeta::for_server( $server ) ) + $in_cart >= max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
				wc_add_notice( __( 'This server has reached its extra disk limit.', 'beltoft-services' ), 'error' );
				return false;
			}
		}
		return true;
	}

	/** Add a unique key before WooCommerce computes the cart ID. */
	public static function item_data( $data, $product_id ): array {
		$data = is_array( $data ) ? $data : array();
		if ( ! self::disk_product( (int) $product_id ) || self::flow( $data ) ) {
			return $data;
		}
		$link = self::link( $data );
		if ( null === $link ) {
			throw new \Exception( esc_html__( 'Choose a disk for a server in your account first.', 'beltoft-services' ) );
		}
		$data['beltoft_services_disk_for'] = $link;
		$data['beltoft_services_disk_uid'] = wp_generate_uuid4();
		return $data;
	}

	public static function order_item( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( isset( $values['beltoft_services_disk_uid'], $values['beltoft_services_disk_for'] ) ) {
			$item->add_meta_data( '_beltoft_services_disk_uid', (string) $values['beltoft_services_disk_uid'], true );
			$item->add_meta_data( '_beltoft_services_disk_server', $values['beltoft_services_disk_for'], true );
		}
	}

	public static function recurring_key( string $key, array $cart_item ): string {
		$uid = (string) ( $cart_item['beltoft_services_disk_uid'] ?? '' );
		return '' === $uid ? $key : $key . '_disk_' . md5( $uid );
	}

	public static function quantity( string $html, string $cart_item_key, array $cart_item ): string {
		return isset( $cart_item['beltoft_services_disk_uid'] ) ? '1' : $html;
	}

	public static function checkout_validation( array $data, \WP_Error $errors ): void {
		$requested = array();
		$new_server_disk_gb = 0;
		$new_server_disk_count = 0;
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ! isset( $item['beltoft_services_disk_uid'] ) || ! self::disk_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				continue;
			}
			$link = $item['beltoft_services_disk_for'] ?? null;
			if ( is_int( $link ) ) {
				$license = Licenses::find( $link );
				$server = $license ? Resource::for_license( $link ) : null;
				if ( ! $server || ! $license || (int) $license['user_id'] !== get_current_user_id() || 'ready' !== $server->get( 'state' ) || $server->get( 'linked' ) ) {
					$errors->add( 'disk_server_unavailable', __( 'The server for this disk is no longer available.', 'beltoft-services' ) );
					continue;
				}
				$in_cart = 0;
				foreach ( WC()->cart->get_cart() as $other ) {
					$in_cart += (int) ( ( $other['beltoft_services_disk_for'] ?? null ) === $link );
				}
				if ( count( DiskMeta::for_server( $server ) ) + $in_cart > max( 1, (int) Settings::get( 'disk_max_extra' ) ) ) {
					$errors->add( 'disk_limit', __( 'This server has reached its extra disk limit.', 'beltoft-services' ) );
				}
				$record = VmRecords::for_resource( $server->id() );
				$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
				$size = (int) ( Product::plan( (int) $item['product_id'], (int) $item['variation_id'] )['disk_gb'] ?? 0 );
				$key = $record && $cluster ? $record['cluster'] . '|' . $record['node'] . '|' . $cluster['storage'] : '';
				$requested[ $key ] = (int) ( $requested[ $key ] ?? 0 ) + $size;
				if ( ! $record || ! $cluster || ! DiskCapacity::fits( $record['cluster'], $record['node'], (string) $cluster['storage'], $requested[ $key ] ) ) {
					$errors->add( 'disk_capacity', __( 'This disk size is no longer available for the server.', 'beltoft-services' ) );
				}
			} elseif ( 'cart_server' === $link ) {
				++$new_server_disk_count;
				$new_server_disk_gb += (int) ( Product::plan( (int) $item['product_id'], (int) $item['variation_id'] )['disk_gb'] ?? 0 );
			}
		}
		if ( $new_server_disk_count > 1 ) {
			$errors->add( 'disk_limit', __( 'Choose only one extra disk with a new server.', 'beltoft-services' ) );
		}
		if ( $new_server_disk_gb > 0 ) {
			$entry = DiskField::cart_server();
			$server_item = $entry['item'] ?? null;
			$plan = $server_item ? Product::plan( (int) $server_item['product_id'], (int) ( $server_item['variation_id'] ?? 0 ) ) : array();
			$cluster_id = (string) ( $plan['cluster'] ?? '' );
			$cluster = Clusters::get( $cluster_id );
			$memory = max( 0, (int) ( $plan['memory_mb'] ?? 0 ) ) * 1048576;
			$space = max( 0, (int) ( $plan['disk_gb'] ?? 0 ) ) + $new_server_disk_gb;
			$fits = false;
			foreach ( $cluster ? (array) Capacity::free_by_node( $cluster_id ) : array() as $node => $free ) {
				if ( $free >= $memory && DiskCapacity::fits( $cluster_id, (string) $node, (string) $cluster['storage'], $space ) ) {
					$fits = true;
					break;
				}
			}
			if ( ! $fits ) {
				$errors->add( 'disk_capacity', __( 'The server and extra disk cannot be delivered with the available capacity.', 'beltoft-services' ) );
			}
		}
	}

	/** WooCommerce Subscriptions' own switch link, limited to the next larger size. */
	public static function grow_url( Resource $disk ): string {
		if ( ! class_exists( '\\WC_Subscriptions_Switcher' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}
		$license = $disk->license();
		$subscription = wcs_get_subscription( (int) ( $license['subscription_id'] ?? 0 ) );
		$current = (int) $disk->get( 'size_gb' );
		$next = null;
		foreach ( DiskField::sizes() as $variation ) {
			if ( $variation->get_parent_id() !== (int) ( $license['product_id'] ?? 0 ) ) {
				continue;
			}
			$size = (int) ( Product::plan( $variation->get_parent_id(), $variation->get_id() )['disk_gb'] ?? 0 );
			if ( $size > $current && ( null === $next || $size < $next['size'] ) ) {
				$next = array( 'size' => $size, 'variation' => $variation );
			}
		}
		if ( ! $subscription instanceof \WC_Subscription || null === $next ) {
			return '';
		}
		foreach ( $subscription->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() !== (int) $license['product_id'] || ! \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription ) ) {
				continue;
			}
			$query = array();
			wp_parse_str( (string) wp_parse_url( \WC_Subscriptions_Switcher::get_switch_url( $item_id, $item, $subscription ), PHP_URL_QUERY ), $query );
			$args = array_intersect_key( $query, array_flip( array( 'switch-subscription', 'item', '_wcsnonce' ) ) );
			if ( 3 !== count( $args ) ) {
				return '';
			}
			$args['add-to-cart'] = $next['variation']->get_parent_id();
			$args['variation_id'] = $next['variation']->get_id();
			foreach ( $next['variation']->get_attributes() as $name => $value ) {
				$args[ 'attribute_' . $name ] = $value;
			}
			return add_query_arg( $args, wc_get_checkout_url() );
		}
		return '';
	}
}

<?php
/** Add the managed server service to one server per WooCommerce subscription. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Managed;

use BeltoftLicenses\Licenses;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonCart;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class ManagedCart {
	private const NONCE = 'beltoft-services-managed';

	private static function addon(): Addon {
		return Addon::for_driver( ManagedDriver::ID );
	}

	public static function register(): void {
		AddonCart::register_cart_hooks( __CLASS__, self::addon() );
	}

	/** The published, purchasable subscription (a variation or a simple product) of the managed service, or null. */
	public static function product(): ?\WC_Product {
		return AddonCart::single_product( ManagedDriver::ID );
	}

	/** May this server or database take the service: a ready, active one. */
	private static function server_ok( ?Resource $server ): bool {
		return $server && in_array( (string) $server->get( 'driver' ), self::addon()->parent_drivers(), true ) && 'ready' === $server->get( 'state' ) && 'ready' === $server->get( 'desired' )
			&& Lifecycle::is_active( $server->license() );
	}

	/** Can the server take one: no managed service holds its place and none is in the cart. */
	public static function room( Resource $server, int $in_cart = 0 ): bool {
		return array() === ManagedMeta::reserved_for( $server ) && 0 === $in_cart;
	}

	/** The link that puts the service for this server in the checkout, or ''. */
	public static function add_url( Resource $server ): string {
		$product = self::product();

		if ( null === $product || ! function_exists( 'wcs_get_subscription' ) || ! self::server_ok( $server ) || ! self::room( $server ) ) {
			return '';
		}

		$parent  = $product instanceof \WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
		$license = $server->license();
		$args    = array(
			'add-to-cart'                    => $parent,
			'beltoft_services_managed_for'   => (int) $license['id'],
			'beltoft_services_managed_nonce' => wp_create_nonce( self::NONCE . ':' . (int) $license['id'] ),
		);

		if ( $product instanceof \WC_Product_Variation ) {
			$args['variation_id'] = $product->get_id();
			$args                += array_map( 'rawurlencode', $product->get_variation_attributes() );
		}

		return add_query_arg( $args, wc_get_checkout_url() );
	}

	private static function managed_product( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );

		return $driver && ManagedDriver::ID === $driver->id();
	}

	/** The server licence id the line is for, when it is the caller's own and fit; else null. */
	private static function link( array $data ) {
		return AddonCart::link( self::addon(), $data, self::owned( ... ) );
	}

	private static function owned( int $license_id ): bool {
		$license = Licenses::find( $license_id );
		$server  = $license ? Resource::for_license( $license_id ) : null;
		$user    = get_current_user_id();

		return $user > 0 && $license && (int) ( $license['user_id'] ?? 0 ) === $user && self::server_ok( $server );
	}

	/** Lines of the service in the cart for one server. */
	private static function in_cart( int $link ): int {
		$n = 0;

		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ( $item['beltoft_services_managed_for'] ?? null ) === $link ) {
				++$n;
			}
		}

		return $n;
	}

	/** Lines of the service in the cart for the new server of the cart. */
	private static function in_cart_server(): int {
		$n = 0;

		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$n += 'cart_server' === ( $item['beltoft_services_managed_for'] ?? null ) ? 1 : 0;
		}

		return $n;
	}

	public static function validate( $valid, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ): bool {
		if ( ! $valid || ! self::managed_product( (int) $product_id ) ) {
			return (bool) $valid;
		}

		$data = is_array( $data ) ? $data : array();

		if ( CartFlow::has_value( $data, array( CartFlow::RESUBSCRIBE ) ) ) {
			wc_add_notice( __( 'Add Managed again from your server or database in My account.', 'beltoft-services' ), 'error' );
			return false;
		}

		if ( AddonCart::valid_switch( (int) $product_id ) || CartFlow::has_value( $data, CartFlow::ALL ) ) {
			return true;
		}

		$link = self::link( $data );

		if ( null === $link || 1 !== (int) $quantity ) {
			wc_add_notice( __( 'Choose Managed for a server or database in your account.', 'beltoft-services' ), 'error' );
			return false;
		}

		if ( 'cart_server' === $link ) {
			// Bought with the new server of the cart (the checkout box): one line for it.
			if ( self::in_cart_server() > 0 ) {
				wc_add_notice( __( 'This server or database is managed already.', 'beltoft-services' ), 'error' );
				return false;
			}

			return true;
		}

		if ( ! self::room( Resource::for_license( $link ), self::in_cart( $link ) ) ) {
			wc_add_notice( __( 'This server or database is managed already.', 'beltoft-services' ), 'error' );
			return false;
		}

		return true;
	}

	/** Add a unique key before WooCommerce computes the cart ID. */
	public static function item_data( $data, $product_id ): array {
		$data = is_array( $data ) ? $data : array();

		if ( ! self::managed_product( (int) $product_id ) || CartFlow::has_value( $data, CartFlow::ALL ) ) {
			return $data;
		}

		$link = self::link( $data );

		if ( null === $link ) {
			throw new \Exception( esc_html__( 'Choose Managed for a server or database in your account first.', 'beltoft-services' ) );
		}

		$data['beltoft_services_managed_for'] = $link;
		$data['beltoft_services_managed_uid'] = wp_generate_uuid4();

		return $data;
	}

	/** At checkout: the server is still the caller's and ready, and no server has two lines. */
	public static function checkout_validation( array $data, \WP_Error $errors ): void {
		$count = array();

		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ! self::managed_product( (int) ( $item['product_id'] ?? 0 ) ) || ! isset( $item['beltoft_services_managed_uid'] ) ) {
				continue;
			}

			$link = $item['beltoft_services_managed_for'] ?? null;

			if ( 'cart_server' === $link ) {
				if ( ! AddonCart::cart_has_parent( self::addon() ) ) {
					$errors->add( 'managed_server_unavailable', __( 'The server or database for Managed is no longer in the order.', 'beltoft-services' ) );
				} elseif ( self::in_cart_server() > 1 ) {
					$errors->add( 'managed_limit', __( 'This server or database is managed already.', 'beltoft-services' ) );
				}
				continue;
			}

			if ( ! is_int( $link ) || ! self::owned( $link ) ) {
				$errors->add( 'managed_server_unavailable', __( 'The server or database for Managed is no longer available.', 'beltoft-services' ) );
				continue;
			}

			$count[ $link ] = (int) ( $count[ $link ] ?? 0 ) + 1;

			if ( $count[ $link ] > 1 || ! self::room( Resource::for_license( $link ) ) ) {
				$errors->add( 'managed_limit', __( 'This server or database is managed already.', 'beltoft-services' ) );
			}
		}
	}
}

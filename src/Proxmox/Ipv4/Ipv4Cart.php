<?php
/**
 * Add an IPv4 address to a server or a database: one subscription per address, one cart line each.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftLicenses\Licenses;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonCart;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The same cart rules as the other add-ons, from the shared model: the line remembers its server or database (`cart_server`
 * for the one new in the cart), is always one piece and has its own renewal cart. This class adds: the parent is the
 * caller's own, ready and active; the parent is under its limit counting what is in the cart; and an address is free in the
 * pool (the ones kept for new servers do not count).
 */
final class Ipv4Cart {
	private const NONCE = 'beltoft-services-ipv4';

	private static function addon(): Addon {
		return Addon::for_driver( Ipv4Driver::ID );
	}

	/**
	 * Hooks.
	 */
	public static function register(): void {
		AddonCart::register_cart_hooks( __CLASS__, self::addon() );
	}

	/**
	 * The published, purchasable subscription of the IPv4 address, or null.
	 */
	public static function product(): ?\WC_Product {
		return AddonCart::single_product( Ipv4Driver::ID );
	}

	/**
	 * May this parent take an address: a ready, active server or database of the customer's own, not adopted.
	 *
	 * @param Resource|null $parent Server or database.
	 */
	private static function parent_ok( ?Resource $parent ): bool {
		return Ipv4Meta::is_parent( $parent ) && 'ready' === $parent->get( 'state' ) && 'ready' === $parent->get( 'desired' ) && Lifecycle::is_active( $parent->license() );
	}

	/**
	 * Can the parent take one more: under its limit, counting the lines in the cart.
	 *
	 * @param Resource $parent  Server or database.
	 * @param int      $in_cart Lines of the cart for this parent.
	 */
	public static function room( Resource $parent, int $in_cart = 0 ): bool {
		return count( Ipv4Meta::reserved_for( $parent ) ) + $in_cart < Ipv4Meta::limit( $parent );
	}

	/**
	 * Is an address free to sell now?
	 */
	public static function in_stock(): bool {
		return IpPool::available_for_addon() > 0;
	}

	/**
	 * The link that puts one address for this parent in the checkout, or '' (no product, a parent that cannot take one, no room,
	 * or sold out).
	 *
	 * @param Resource $parent Server or database.
	 */
	public static function add_url( Resource $parent ): string {
		$product = self::product();

		if ( null === $product || ! function_exists( 'wcs_get_subscription' ) || ! self::parent_ok( $parent ) || ! self::room( $parent ) || ! self::in_stock() ) {
			return '';
		}

		$parent_id = $product instanceof \WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
		$license   = $parent->license();
		$args      = array(
			'add-to-cart'                 => $parent_id,
			'beltoft_services_ipv4_for'   => (int) $license['id'],
			'beltoft_services_ipv4_nonce' => wp_create_nonce( self::NONCE . ':' . (int) $license['id'] ),
		);

		if ( $product instanceof \WC_Product_Variation ) {
			$args['variation_id'] = $product->get_id();
			$args                += array_map( 'rawurlencode', $product->get_variation_attributes() );
		}

		return add_query_arg( $args, wc_get_checkout_url() );
	}

	private static function ipv4_product( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );

		return $driver && Ipv4Driver::ID === $driver->id();
	}

	/**
	 * The license id of the parent the line is for, 'cart_server' for the new one in the cart, or null.
	 *
	 * @param array<string, mixed> $data Cart item data.
	 * @return int|string|null
	 */
	private static function link( array $data ) {
		return AddonCart::link( self::addon(), $data, self::owned( ... ) );
	}

	private static function owned( int $license_id ): bool {
		$license = Licenses::find( $license_id );
		$parent  = $license ? Resource::for_license( $license_id ) : null;
		$user    = get_current_user_id();

		return $user > 0 && $license && (int) ( $license['user_id'] ?? 0 ) === $user && self::parent_ok( $parent );
	}

	/**
	 * Lines of the cart for one parent (a license id, or 'cart_server').
	 *
	 * @param int|string $link Link.
	 */
	public static function in_cart( $link ): int {
		$n = 0;

		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ( $item[ self::addon()->cart_for() ] ?? null ) === $link ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * Filter woocommerce_add_to_cart_validation.
	 *
	 * @param mixed $valid        Valid so far.
	 * @param mixed $product_id   Product.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation.
	 * @param mixed $variation    Attributes.
	 * @param mixed $data         Cart item data.
	 */
	public static function validate( $valid, $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( ! $valid || ! self::ipv4_product( (int) $product_id ) ) {
			return (bool) $valid;
		}

		$data = is_array( $data ) ? $data : array();

		if ( CartFlow::has_value( $data, array( CartFlow::RESUBSCRIBE ) ) ) {
			wc_add_notice( __( 'Add the IPv4 address again from your server or database in My account.', 'beltoft-services' ), 'error' );
			return false;
		}

		if ( AddonCart::valid_switch( (int) $product_id ) || CartFlow::has_value( $data, CartFlow::ALL ) ) {
			return true;
		}

		$link = self::link( $data );

		if ( null === $link || 1 !== (int) $quantity ) {
			wc_add_notice( __( 'Choose the IPv4 address for a server or a database in your account.', 'beltoft-services' ), 'error' );
			return false;
		}

		if ( ! self::in_stock() ) {
			Ipv4Alerts::sold_out();
			wc_add_notice( __( 'No IPv4 address is free right now.', 'beltoft-services' ), 'error' );
			return false;
		}

		if ( 'cart_server' === $link ) {
			$target = Ipv4Field::target();

			if ( null === $target ) {
				wc_add_notice( Ipv4Field::both() ? self::both_text() : __( 'Choose the IPv4 address for a server or a database in your account.', 'beltoft-services' ), 'error' );
				return false;
			}

			if ( self::in_cart( 'cart_server' ) >= (int) $target['max'] ) {
				wc_add_notice( __( 'This server or database cannot take another IPv4 address.', 'beltoft-services' ), 'error' );
				return false;
			}
		}

		if ( is_int( $link ) && ! self::room( Resource::for_license( $link ), self::in_cart( $link ) ) ) {
			wc_add_notice( __( 'This server or database cannot take another IPv4 address.', 'beltoft-services' ), 'error' );
			return false;
		}

		return true;
	}

	private static function both_text(): string {
		return __( 'An IPv4 address cannot be bought together with a new server and a new database in one order. Buy the addresses from your dashboard after the order.', 'beltoft-services' );
	}

	/**
	 * Filter woocommerce_add_cart_item_data: a unique key before WooCommerce computes the cart id, so every address is its own line.
	 *
	 * @param mixed $data       Cart item data.
	 * @param mixed $product_id Product.
	 * @return array<string, mixed>
	 */
	public static function item_data( $data, $product_id ): array {
		$data = is_array( $data ) ? $data : array();

		if ( ! self::ipv4_product( (int) $product_id ) || CartFlow::has_value( $data, CartFlow::ALL ) ) {
			return $data;
		}

		$link = self::link( $data );

		if ( null === $link ) {
			throw new \Exception( esc_html__( 'Choose the IPv4 address for a server or a database in your account first.', 'beltoft-services' ) );
		}

		$data[ self::addon()->cart_for() ] = $link;
		$data[ self::addon()->cart_uid() ] = wp_generate_uuid4();

		return $data;
	}

	/**
	 * At checkout: the parent is still the caller's and ready, each is under its limit, and an address is free for every line.
	 *
	 * @param array<string, mixed> $data   Posted data.
	 * @param \WP_Error            $errors Errors.
	 */
	public static function checkout_validation( array $data, \WP_Error $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$count = array();
		$lines = 0;
		$new   = 0;

		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			if ( ! self::ipv4_product( (int) ( $item['product_id'] ?? 0 ) ) || ! isset( $item[ self::addon()->cart_uid() ] ) ) {
				continue;
			}

			++$lines;
			$link = $item[ self::addon()->cart_for() ] ?? null;

			if ( 'cart_server' === $link ) {
				$target = Ipv4Field::target();

				if ( null === $target ) {
					$errors->add( 'ipv4_parent_unavailable', Ipv4Field::both() ? self::both_text() : __( 'The server or database for the IPv4 address is no longer available.', 'beltoft-services' ) );
				} elseif ( ++$new > (int) $target['max'] ) {
					$errors->add( 'ipv4_limit', __( 'This server or database cannot take another IPv4 address.', 'beltoft-services' ) );
				}

				continue;
			}

			if ( ! is_int( $link ) || ! self::owned( $link ) ) {
				$errors->add( 'ipv4_parent_unavailable', __( 'The server or database for the IPv4 address is no longer available.', 'beltoft-services' ) );
				continue;
			}

			$count[ $link ] = (int) ( $count[ $link ] ?? 0 ) + 1;

			if ( ! self::room( Resource::for_license( $link ), $count[ $link ] - 1 ) ) {
				$errors->add( 'ipv4_limit', __( 'This server or database cannot take another IPv4 address.', 'beltoft-services' ) );
			}
		}

		// A new dedicated server in the same cart takes its own address first and brings its own share of the reserve.
		$server       = $lines > 0 ? \BeltoftServices\Proxmox\Disk\DiskField::cart_server() : null;
		$takes_server = null !== $server && \BeltoftServices\Proxmox\Vm\VpsProfile::plan_has_ipv4( Product::plan( (int) ( $server['item']['product_id'] ?? 0 ), (int) ( $server['item']['variation_id'] ?? 0 ) ) );

		if ( $lines > 0 && IpPool::free_count() < $lines + ( $takes_server ? 1 : IpPool::reserve_for_vps() ) ) {
			Ipv4Alerts::sold_out();
			$errors->add( 'ipv4_sold_out', __( 'No IPv4 address is free right now.', 'beltoft-services' ) );
		}
	}
}

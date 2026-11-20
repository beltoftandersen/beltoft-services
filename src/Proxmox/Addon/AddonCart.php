<?php
/** The cart hooks every server add-on kind shares. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Addon;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\SwitchLink;

defined( 'ABSPATH' ) || exit;

/**
 * What the cart of an extra disk, a snapshot pack and an app licence do the same way: the order line remembers its
 * server, each line gets its own renewal cart, and a line is always one piece. The kinds keep their own validation.
 */
final class AddonCart {
	/**
	 * Hook the cart of one add-on kind: the class's validate(), item_data() and checkout_validation() and the per-line behaviour.
	 *
	 * @param class-string $class Class that has the three methods.
	 * @param Addon        $a     Add-on kind.
	 */
	public static function register_cart_hooks( string $class, Addon $a ): void {
		self::register_pending_lines();
		// The product found for an add-on kind is kept for the request: a saved or deleted product starts the lookup again.
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'reset_products' ) );
		add_action( 'woocommerce_after_product_variation_object_save', array( __CLASS__, 'reset_products' ) );
		add_action( 'deleted_post', array( __CLASS__, 'reset_products' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $class, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $class, 'item_data' ), 20, 2 );
		self::register_line_hooks( $a );
		add_action( 'woocommerce_after_checkout_validation', array( $class, 'checkout_validation' ), 10, 2 );
	}

	private static bool $pending_registered = false;

	/**
	 * The add-on lines bought with the new server of the cart are kept like the domain lines of the order form (Domains\PendingLines):
	 * a checkout refresh that read the cart before the choice and writes the session after it must not take the line out.
	 */
	private static function register_pending_lines(): void {
		if ( self::$pending_registered ) {
			return;
		}

		self::$pending_registered = true;
		add_filter( 'beltoft_services_pending_line_name', array( __CLASS__, 'pending_name' ), 10, 2 );
		add_filter( 'beltoft_services_pending_line_can_put_back', array( __CLASS__, 'pending_can_put_back' ), 10, 2 );
	}

	/** The kind of an add-on line that goes with the new server of the cart, or null. */
	private static function pending_kind( $item ): ?Addon {
		foreach ( is_array( $item ) ? Addon::all() : array() as $a ) {
			if ( $a->accepts_cart_server && 'cart_server' === ( $item[ $a->cart_for() ] ?? null ) && '' !== (string) ( $item[ $a->cart_uid() ] ?? '' ) ) {
				return $a;
			}
		}

		return null;
	}

	/** Filter beltoft_services_pending_line_name. */
	public static function pending_name( $name, $item = array() ): string {
		$a = self::pending_kind( $item );

		return $a ? 'addon:' . $item[ $a->cart_uid() ] : (string) $name;
	}

	/** Filter beltoft_services_pending_line_can_put_back: an add-on line only while its new server (or database) is in the cart. */
	public static function pending_can_put_back( $allowed, $item = array() ) {
		$a = self::pending_kind( $item );

		if ( ! $a ) {
			return $allowed;
		}

		/**
		 * Does a kept add-on line still fit its new server or database: the billing twin of the parent and the limit of addresses.
		 * A kind that has such rules answers false; the default is true.
		 *
		 * @param bool                 $fits True.
		 * @param array<string, mixed> $item The kept cart line.
		 * @param Addon                $a    Add-on kind.
		 */
		return self::cart_has_parent( $a ) && (bool) apply_filters( 'beltoft_services_addon_line_fits', true, $item, $a );
	}

	/**
	 * Hook the per-line cart behaviour of one add-on kind.
	 *
	 * @param Addon $a Add-on kind.
	 */
	public static function register_line_hooks( Addon $a ): void {
		add_action(
			'woocommerce_checkout_create_order_line_item',
			static function ( $item, $cart_item_key, $values, $order ) use ( $a ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
				if ( $item instanceof \WC_Order_Item_Product && is_array( $values ) && isset( $values[ $a->cart_uid() ], $values[ $a->cart_for() ] ) ) {
					$item->add_meta_data( $a->item_uid(), (string) $values[ $a->cart_uid() ], true );
					$item->add_meta_data( $a->item_server(), $values[ $a->cart_for() ], true );
				}
			},
			10,
			4
		);

		add_filter(
			'woocommerce_subscriptions_recurring_cart_key',
			static function ( $key, $cart_item = array() ) use ( $a ): string {
				$uid = is_array( $cart_item ) ? (string) ( $cart_item[ $a->cart_uid() ] ?? '' ) : '';

				return '' === $uid ? (string) $key : $key . $a->recurring_suffix . md5( $uid );
			},
			10,
			2
		);

		add_filter(
			'woocommerce_cart_item_quantity',
			static function ( $html, $cart_item_key = '', $cart_item = array() ) use ( $a ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
				return is_array( $cart_item ) && isset( $cart_item[ $a->cart_uid() ] ) ? '1' : (string) $html;
			},
			10,
			3
		);

		add_action(
			'woocommerce_before_calculate_totals',
			static function ( $cart ) use ( $a ): void {
				foreach ( $cart instanceof \WC_Cart ? $cart->get_cart() : array() as $key => $item ) {
					if ( isset( $item[ $a->cart_uid() ] ) && 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
						$cart->cart_contents[ $key ]['quantity'] = 1;
					}
				}
			},
			5
		);
	}

	/**
	 * Confirm WCS is switching this customer's existing line of the product.
	 *
	 * @param int $product_id Product id.
	 */
	public static function valid_switch( int $product_id ): bool {
		$requested       = SwitchLink::requested();
		$subscription_id = $requested['subscription'];
		$item_id         = $requested['item'];
		if ( ! $subscription_id || ! $item_id || ! function_exists( 'wcs_get_subscription' ) || ! class_exists( '\\WC_Subscriptions_Switcher' ) ) {
			return false;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		$item         = $subscription instanceof \WC_Subscription ? $subscription->get_item( $item_id ) : null;

		return $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription, get_current_user_id() );
	}

	/**
	 * The server a new line is for: the license id when the nonce is right and the caller owns it, `cart_server` for the
	 * server still in the cart (only for kinds that accept it), else null.
	 *
	 * @param Addon    $a     Add-on kind.
	 * @param array    $data  Cart item data.
	 * @param callable $owned Given the license id: is it the caller's own, fit server?
	 * @return int|string|null
	 */
	public static function link( Addon $a, array $data, callable $owned ) {
		$requested_link = isset( $_REQUEST[ $a->cart_for() ] ) && is_scalar( $_REQUEST[ $a->cart_for() ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $a->cart_for() ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$link           = $data[ $a->cart_for() ] ?? $requested_link;
		if ( $a->accepts_cart_server && 'cart_server' === $link ) {
			return self::cart_has_parent( $a ) ? 'cart_server' : null;
		}
		if ( ! is_scalar( $link ) || ! ctype_digit( (string) $link ) ) {
			return null;
		}
		$id              = (int) $link;
		$nonce_key       = 'beltoft_services_' . $a->prefix . '_nonce';
		$requested_nonce = isset( $_REQUEST[ $nonce_key ] ) && is_scalar( $_REQUEST[ $nonce_key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		$nonce           = $data[ $nonce_key ] ?? $requested_nonce;
		if ( ! is_scalar( $nonce ) || ! wp_verify_nonce( (string) $nonce, $a->nonce_action . ':' . $id ) ) {
			return null;
		}

		return $owned( $id ) ? $id : null;
	}

	/**
	 * Is there a new server in the cart for a line of this kind to be bought with: the one server of the cart, or for a kind that
	 * also belongs to a database (the IPv4 address) the one new database?
	 *
	 * @param Addon $a Add-on kind.
	 */
	public static function cart_has_parent( Addon $a ): bool {
		return null !== DiskField::cart_server() || ( in_array( \BeltoftServices\Proxmox\Db\DbDriver::ID, $a->also_parents, true ) && null !== \BeltoftServices\Proxmox\Db\DbIpField::new_database() );
	}

	/**
	 * The published, purchasable subscription (a variation or a simple product) of an add-on kind that is one product, or null.
	 *
	 * @param string    $driver_id Driver id of the add-on.
	 * @param bool|null $usage     True: the pay as you go variation, false: the monthly one, null: whichever the product has (a kind with one billing).
	 */
	public static function single_product( string $driver_id, ?bool $usage = null ): ?\WC_Product {
		if ( ! class_exists( '\\WC_Subscriptions_Product' ) ) {
			return null;
		}

		// Looked up once per request: the cart hooks ask for it on every line and every removal. A saved product starts it again (register_cart_hooks).
		$key = $driver_id . '|' . ( null === $usage ? 'any' : ( $usage ? 'usage' : 'monthly' ) );

		if ( ! array_key_exists( $key, self::$products ) ) {
			self::$products[ $key ] = self::find_single_product( $driver_id, $usage );
		}

		return self::$products[ $key ];
	}

	/** @var array<string, \WC_Product|null> */
	private static array $products = array();

	/**
	 * Forgets the products found (a product was saved or deleted, or a test changed the catalogue).
	 */
	public static function reset_products(): void {
		self::$products = array();
	}

	/**
	 * The lookup behind single_product().
	 *
	 * @param string    $driver_id Driver id of the add-on.
	 * @param bool|null $usage     See single_product().
	 */
	private static function find_single_product( string $driver_id, ?bool $usage ): ?\WC_Product {

		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 5, 'meta_key' => Product::META_DRIVER, 'meta_value' => $driver_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One product.

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( $product instanceof \WC_Product_Variable ) {
				// A pay as you go variation has no price at checkout, so WooCommerce hides it from the available ones: read the children.
				$variations = true === $usage ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : $product->get_available_variations( 'objects' );

				foreach ( $variations as $variation ) {
					if ( ! $variation instanceof \WC_Product_Variation || ( null !== $usage && \BeltoftServices\Usage\Rates::is_usage( $variation ) !== $usage ) ) {
						continue;
					}

					if ( ( true === $usage || \WC_Subscriptions_Product::is_subscription( $variation ) ) && 'publish' === $variation->get_status() && $variation->is_purchasable() ) {
						return $variation;
					}
				}
			} elseif ( $product && \WC_Subscriptions_Product::is_subscription( $product ) && $product->is_purchasable() ) {
				return $product;
			}
		}

		return null;
	}
}

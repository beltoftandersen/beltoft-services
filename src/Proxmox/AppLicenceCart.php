<?php
/** The tier of a paid app as a second cart line next to a new server. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * The extra disk's pattern (DiskCart, DiskField), for one line only: the tier variation is added with
 * `beltoft_services_applic_for => cart_server`, so it is bought together with the new server of the cart and
 * nowhere else. A renewal, a resubscribe and a WooCommerce Subscriptions switch of a tier line pass (the line
 * was bought before). The app field's script keeps the line in step with the chosen tier (AJAX).
 *
 * Billing follows the server. A pay-as-you-go server gets the tier's usage sibling (price 0 at checkout, billed by
 * the hour from the usage ledger, see Rates::items) and never the monthly variation: a tier with no usage sibling is
 * not offered there, so a pay-as-you-go customer never gets a fixed monthly subscription they did not see. When the
 * billing choice of the cart's server changes, rematch() puts the matching variation in the cart.
 *
 * A reinstall of an existing server to a paid app uses the same line, linked to that server's license id instead of
 * `cart_server` (AppReinstallPaid: URL, nonce and the pending reinstall request it needs).
 */
final class AppLicenceCart {

	public const AJAX  = 'beltoft_services_app_tier';
	public const NONCE = 'beltoft-services-app-tier';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item' ), 10, 4 );
		add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( __CLASS__, 'recurring_key' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'one_each' ), 5 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'clean_orphan' ) );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
	}

	/**
	 * Is this product the licence product of a paid app?
	 *
	 * @param int $product_id Product id.
	 */
	private static function licence_product( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );

		return $driver && AppLicenceDriver::ID === $driver->id();
	}

	/**
	 * The cart line of the tier, or null: its key and item.
	 *
	 * @return array{key: string, item: array<string, mixed>}|null
	 */
	public static function line(): ?array {
		foreach ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array() as $key => $item ) {
			if ( isset( $item[ AppLicence::CART_UID ] ) && 'cart_server' === ( $item[ AppLicence::CART_FOR ] ?? null ) ) {
				return array( 'key' => (string) $key, 'item' => (array) $item );
			}
		}

		return null;
	}

	/**
	 * Every tier line of the cart for its new server (one is allowed; the checkout check counts them).
	 */
	public static function lines(): int {
		$count = 0;

		foreach ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$count += isset( $item[ AppLicence::CART_UID ] ) && 'cart_server' === ( $item[ AppLicence::CART_FOR ] ?? null ) ? 1 : 0;
		}

		return $count;
	}

	/**
	 * Puts the tier of an app in the cart for its new server, replacing the earlier tier line; no app removes the line.
	 *
	 * @param string $app_id App id, '' for none.
	 * @param string $tier   Tier key.
	 * @return bool Whether the cart holds what was asked.
	 */
	public static function choose( string $app_id, string $tier ): bool {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || null === DiskField::cart_server() ) {
			return false;
		}

		$line = self::line();

		if ( '' === $app_id ) {
			if ( $line ) {
				$cart->remove_cart_item( $line['key'] );
			}

			return true;
		}

		$wanted    = AppLicence::tier( $app_id, $tier );
		$variation = null === $wanted ? null : AppLicence::line_variation( $wanted, self::server_usage() );

		if ( null === $variation ) {
			return false;
		}

		if ( $line && (int) ( $line['item']['variation_id'] ?? 0 ) === $variation->get_id() ) {
			return true;
		}

		$attributes = array();

		foreach ( $variation->get_attributes() as $name => $value ) {
			$attributes[ 'attribute_' . $name ] = $value;
		}

		$added = $cart->add_to_cart( $wanted['product_id'], 1, $variation->get_id(), $attributes, array( AppLicence::CART_FOR => 'cart_server' ) );

		if ( $added && $line && $line['key'] !== (string) $added ) {
			$cart->remove_cart_item( $line['key'] );
		}

		return (bool) $added;
	}

	/**
	 * Is the cart's new server billed pay as you go?
	 */
	public static function server_usage(): bool {
		$server = DiskField::cart_server();

		return null !== $server && \BeltoftServices\Usage\Rates::is_usage( (int) ( $server['item']['variation_id'] ?? 0 ) );
	}

	/**
	 * After the billing choice of the cart's server changed: the tier line gets the variation that matches (monthly, or
	 * its usage sibling), or leaves the cart when the tier has none for that billing (the app field then asks again).
	 *
	 * @return bool Whether the cart is in step (no line, or a matching one).
	 */
	public static function rematch(): bool {
		$line = self::line();

		if ( ! $line ) {
			return true;
		}

		$item = $line['item'];
		$app  = AppLicence::app_of( (int) ( $item['variation_id'] ?? 0 ) );
		$plan = Product::plan( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );

		if ( null === $app ) {
			return true;
		}

		$wanted    = AppLicence::tier( $app, (string) ( $plan['tier'] ?? '' ) );
		$variation = null === $wanted ? null : AppLicence::line_variation( $wanted, self::server_usage() );

		if ( null !== $variation && (int) ( $item['variation_id'] ?? 0 ) === $variation->get_id() ) {
			return true;
		}

		if ( null === $variation ) {
			WC()->cart->remove_cart_item( $line['key'] );

			return false;
		}

		return self::choose( $app, (string) $wanted['key'] );
	}

	/**
	 * A renewal, resubscribe or switch cart: the line was bought before.
	 *
	 * @param mixed $data Cart item data.
	 */
	private static function flow( $data ): bool {
		return DiskCart::flow( is_array( $data ) ? $data : array() );
	}

	/**
	 * Only a listed tier of a paid app, one of it, for the new server of the cart (or an existing line's own flow).
	 *
	 * @param mixed $valid        Validity so far.
	 * @param mixed $product_id   Product.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation.
	 * @param mixed $variation    Attributes (unused).
	 * @param mixed $data         Cart item data.
	 */
	public static function validate( $valid, $product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( ! $valid || ! self::licence_product( (int) $product_id ) ) {
			return (bool) $valid;
		}

		if ( self::flow( $data ) || DiskCart::valid_switch( (int) $product_id ) ) {
			return true;
		}

		$data = is_array( $data ) ? $data : array();
		$app  = AppLicence::app_of( (int) $variation_id );
		$link = AppReinstallPaid::link( $data );
		$ok   = 1 === (int) $quantity && null !== $app && ( ( null !== DiskField::cart_server() && 'cart_server' === $link ) || is_int( $link ) );

		if ( $ok ) {
			$plan  = Product::plan( (int) $product_id, (int) $variation_id );
			$tier  = AppLicence::tier( (string) $app, (string) ( $plan['tier'] ?? '' ) );
			$usage = is_int( $link ) ? AppReinstallPaid::server_usage( $link ) : self::server_usage();
			$want  = null === $tier ? null : AppLicence::line_variation( $tier, $usage );
			$ok    = null !== $want && $want->get_id() === (int) $variation_id && ( ! is_int( $link ) || AppReinstallPaid::wants( $link, (string) $app, (string) $tier['key'] ) );
		}

		if ( ! $ok ) {
			wc_add_notice( __( 'An app licence is bought together with a new server. Choose the app on the server step.', 'beltoft-services' ), 'error' );
		}

		return $ok;
	}

	/**
	 * A line of its own: a unique key before WooCommerce computes the cart id.
	 *
	 * @param mixed $data       Cart item data.
	 * @param mixed $product_id Product.
	 */
	public static function item_data( $data, $product_id = 0 ): array {
		$data = is_array( $data ) ? $data : array();

		if ( ! self::licence_product( (int) $product_id ) || self::flow( $data ) ) {
			return $data;
		}

		$link = AppReinstallPaid::link( $data );

		if ( null === $link || ( 'cart_server' === $link && null === DiskField::cart_server() ) ) {
			throw new \Exception( esc_html__( 'Choose the app on the server step first.', 'beltoft-services' ) );
		}

		$data[ AppLicence::CART_FOR ] = $link;
		$data[ AppLicence::CART_UID ] = wp_generate_uuid4();

		return $data;
	}

	/**
	 * The order line remembers which server it is for.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key (unused).
	 * @param array<string, mixed>   $values Cart item.
	 * @param \WC_Order              $order  Order (unused).
	 */
	public static function order_item( $item, $key, $values, $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( $item instanceof \WC_Order_Item_Product && is_array( $values ) && isset( $values[ AppLicence::CART_UID ], $values[ AppLicence::CART_FOR ] ) ) {
			$item->add_meta_data( AppLicence::ITEM_UID, (string) $values[ AppLicence::CART_UID ], true );
			$item->add_meta_data( AppLicence::ITEM_SERVER, (string) $values[ AppLicence::CART_FOR ], true );
		}
	}

	/**
	 * Its own renewal cart.
	 *
	 * @param mixed $key       Recurring cart key.
	 * @param mixed $cart_item Cart item.
	 */
	public static function recurring_key( $key, $cart_item = array() ): string {
		$uid = is_array( $cart_item ) ? (string) ( $cart_item[ AppLicence::CART_UID ] ?? '' ) : '';

		return '' === $uid ? (string) $key : $key . '_applic_' . md5( $uid );
	}

	/**
	 * One licence is one line.
	 *
	 * @param mixed $html Quantity html.
	 * @param mixed $key  Cart item key (unused).
	 * @param mixed $item Cart item.
	 */
	public static function quantity( $html, $key = '', $item = array() ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		return is_array( $item ) && isset( $item[ AppLicence::CART_UID ] ) ? '1' : (string) $html;
	}

	/**
	 * A quantity raised by a cart update goes back to 1.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function one_each( $cart ): void {
		foreach ( $cart instanceof \WC_Cart ? $cart->get_cart() : array() as $key => $item ) {
			if ( isset( $item[ AppLicence::CART_UID ] ) && 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
				$cart->cart_contents[ $key ]['quantity'] = 1;
			}
		}
	}

	/**
	 * A removed server must not leave a licence for nothing in the cart.
	 */
	public static function clean_orphan(): void {
		if ( DiskField::server_swap_in_progress() || null !== DiskField::cart_server() || AppReinstallPaid::in_cart() ) {
			return;
		}

		$line = self::line();

		if ( $line && function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->remove_cart_item( $line['key'] );
		}
	}

	/**
	 * The app field's script: the tier chosen (or none) puts the line in the cart or takes it out.
	 */
	public static function ajax(): void {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$app  = isset( $_POST['app'] ) && is_scalar( $_POST['app'] ) ? sanitize_key( wp_unslash( (string) $_POST['app'] ) ) : '';
		$tier = isset( $_POST['tier'] ) && is_scalar( $_POST['tier'] ) ? sanitize_key( wp_unslash( (string) $_POST['tier'] ) ) : '';
		// phpcs:enable

		wp_send_json( array( 'ok' => self::sync( $app, $tier ) ), 200 );
	}

	/**
	 * Puts the cart in step with an app choice: only an app the cart's server is offered, with a tier, adds a line.
	 *
	 * @param string $app_id App id, '' for none.
	 * @param string $tier   Tier key.
	 */
	public static function sync( string $app_id, string $tier ): bool {
		if ( '' === $app_id || ! AppLicence::paid( $app_id ) ) {
			return self::choose( '', '' );
		}

		$offer = AppField::offer();

		if ( null === $offer || ! isset( $offer['apps'][ $app_id ] ) ) {
			self::choose( '', '' );

			return false;
		}

		if ( '' === $tier ) {
			return self::choose( '', '' );
		}

		$current = $offer['apps'][ $app_id ]['choices'] ? reset( $offer['apps'][ $app_id ]['choices'] ) : null;

		if ( null === $current || null !== Apps::plan_refusal( $current, (array) $offer['plan'] ) ) {
			return false;
		}

		return self::choose( $app_id, $tier );
	}
}

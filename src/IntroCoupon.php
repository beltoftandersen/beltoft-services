<?php
/**
 * The introductory price of a service product: a coupon applied to a customer's first purchase.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * A product can name an "Intro coupon" (the code of an ordinary WooCommerce coupon, for example a recurring
 * percentage coupon limited to one payment, which WooCommerce Subscriptions already supports; what it discounts and
 * for which variations is the coupon's own setting). This class adds only the rules around it:
 *
 * - it is applied automatically when such a product is added to the cart as a new purchase, never to a renewal, a
 *   switch or a resubscribe (WooCommerce Subscriptions marks those cart items, as Checkout::trials_in_cart reads);
 * - it is not applied, and a checkout that has it is refused, when the customer (the signed-in user, or the billing
 *   email, so a guest counts) already holds or held a license of that product: the same lookup as the trial rule
 *   (Checkout::check). The coupon is taken off the cart when it is refused, so the customer can try again at the
 *   full price.
 *
 * The code is stored in the product meta `_beltoft_services_intro_coupon` (a theme reads the same key).
 */
final class IntroCoupon {

	public const META = '_beltoft_services_intro_coupon';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add' ), 20, 6 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_removed' ), 10, 2 );
		// Before WooCommerce's own coupon check (priority 1) turns a coupon with no line into an error that hides the checkout.
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'drop_unbacked' ), 0 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
	}

	/**
	 * The coupon code of a product (or variation, through its parent), '' when none.
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function code( int $product_id ): string {
		$parent = Product::parent_id( $product_id );
		$code   = get_post_meta( $parent, self::META, true );

		return is_string( $code ) ? wc_format_coupon_code( $code ) : '';
	}

	/**
	 * The product's coupon code as it is saved from the editor: letters, numbers, dashes, underscores.
	 *
	 * @param string $raw Raw value.
	 */
	public static function clean_code( string $raw ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( trim( $raw ) ) ), 0, 60 );
	}

	/**
	 * An email as the same person writes it in different ways: lower case, no +tag, and for Gmail no dots in the name
	 * (googlemail.com is gmail.com). A second sign-up with a variation of the same address is the same customer.
	 *
	 * @param string $email Email.
	 */
	public static function normalise_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return $email;
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$plus   = strpos( $local, '+' );
		$local  = false === $plus ? $local : substr( $local, 0, $plus );

		if ( 'googlemail.com' === $domain ) {
			$domain = 'gmail.com';
		}

		if ( 'gmail.com' === $domain ) {
			$local = str_replace( '.', '', $local );
		}

		return $local . '@' . $domain;
	}

	/**
	 * Has the customer held a license of this product (any of its plans)? By user id and by billing email, the email
	 * compared in its normalised form.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param int    $user_id    Signed-in user, 0 for a guest.
	 * @param string $email      Billing email, '' when not known yet.
	 */
	public static function held( int $product_id, int $user_id, string $email = '' ): bool {
		global $wpdb;

		$slug = sanitize_title( (string) get_post_meta( Product::parent_id( $product_id ), \BeltoftLicenses\Software::META_SLUG, true ) );

		if ( '' === $slug ) {
			return false;
		}

		$want = self::normalise_email( sanitize_email( $email ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the licenses table of beltoft-licenses, read fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, email FROM %i WHERE product_slug = %s', \BeltoftLicenses\Install::tables()['licenses'], $slug ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $user_id > 0 && (int) $row['user_id'] === $user_id ) {
				return true;
			}

			if ( '' !== $want && self::normalise_email( (string) $row['email'] ) === $want ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is a cart line a new purchase (not a renewal, switch or resubscribe)?
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	private static function is_new( array $item ): bool {
		foreach ( array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe', 'subscription_renewal_early' ) as $flag ) {
			if ( isset( $item[ $flag ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The intro coupon codes of the new purchases in the cart: code => the product ids of its lines (a code can be on
	 * several lines).
	 *
	 * @param \WC_Cart|null $cart Cart; the current one when null.
	 * @return array<string, int[]>
	 */
	public static function lines( ?\WC_Cart $cart = null ): array {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$out  = array();

		if ( ! $cart ) {
			return $out;
		}

		foreach ( $cart->get_cart() as $item ) {
			$id   = (int) ( $item['product_id'] ?? 0 );
			$code = $id > 0 && self::is_new( $item ) && Product::is_service( $id ) ? self::code( $id ) : '';

			// A quantity of two is two accounts at the introductory price.
			for ( $i = 0, $n = max( 1, (int) ( $item['quantity'] ?? 1 ) ); '' !== $code && $i < $n; $i++ ) {
				$out[ $code ][] = $id;
			}
		}

		return $out;
	}

	/**
	 * Action woocommerce_add_to_cart: a new purchase of a product with an intro coupon gets the coupon, when the
	 * customer is not known to have had the product before.
	 *
	 * @param mixed $cart_item_key Cart item key.
	 * @param mixed $product_id    Product id.
	 */
	public static function on_add( $cart_item_key, $product_id = 0 ): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || ! is_string( $cart_item_key ) || ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$item = $cart->cart_contents[ $cart_item_key ];
		$code = self::code( (int) $product_id );

		if ( '' === $code || ! self::is_new( $item ) || $cart->has_discount( $code ) || 0 === wc_get_coupon_id_by_code( $code ) ) {
			return;
		}

		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';

		if ( self::held( (int) $product_id, get_current_user_id(), $email ) ) {
			self::tell_held( (int) $product_id );

			return;
		}

		// A coupon that does not fit this line (a monthly variation, when the coupon is for yearly ones) is not an error for the customer.
		$before = wc_notice_count( 'error' );

		if ( ! $cart->apply_coupon( $code ) && wc_notice_count( 'error' ) > $before ) {
			$notices          = wc_get_notices();
			$notices['error'] = array_slice( (array) ( $notices['error'] ?? array() ), 0, $before );
			wc_set_notices( $notices );
		}
	}

	/**
	 * Action woocommerce_cart_item_removed: the intro coupon goes with the last line it was for. Without this the coupon
	 * stays when another product replaces the service (the shop keeps one purchase per cart), and WooCommerce then answers
	 * the next page with the error "coupon is invalid", which hides the whole checkout form as if the cart were empty.
	 *
	 * @param mixed $cart_item_key Cart item key.
	 * @param mixed $cart          Cart.
	 */
	public static function on_removed( $cart_item_key, $cart = null ): void {
		if ( ! $cart instanceof \WC_Cart || ! is_string( $cart_item_key ) || ! isset( $cart->removed_cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$code = self::code( (int) ( $cart->removed_cart_contents[ $cart_item_key ]['product_id'] ?? 0 ) );

		if ( '' !== $code && $cart->has_discount( $code ) && ! isset( self::lines( $cart )[ $code ] ) ) {
			$cart->remove_coupon( $code );
		}
	}

	/**
	 * Action woocommerce_check_cart_items: an intro coupon in a cart that no longer has a line for it is taken off
	 * quietly (a cart saved before on_removed() existed, or changed by another plugin).
	 */
	public static function drop_unbacked(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof \WC_Cart || array() === $cart->get_applied_coupons() ) {
			return;
		}

		$lines = self::lines( $cart );

		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( ! isset( $lines[ $code ] ) && self::is_intro_code( (string) $code ) ) {
				$cart->remove_coupon( $code );
			}
		}
	}

	/**
	 * Is this coupon code the intro coupon of some product?
	 *
	 * @param string $code Coupon code.
	 */
	private static function is_intro_code( string $code ): bool {
		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one cheap lookup, only for a cart that holds a coupon.
				'meta_value'     => wc_format_coupon_code( $code ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- same.
			)
		);

		return array() !== $found;
	}

	/**
	 * Tells a returning customer why the cart shows the regular price (once per product name).
	 *
	 * @param int $product_id Product or variation id.
	 */
	private static function tell_held( int $product_id ): void {
		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		/* translators: %s: product name. */
		$message = sprintf( __( 'The introductory price for %s is for your first order, so the regular price applies.', 'beltoft-services' ), (string) get_the_title( Product::parent_id( $product_id ) ) );

		if ( ! wc_has_notice( $message, 'notice' ) ) {
			wc_add_notice( $message, 'notice' );
		}
	}

	/**
	 * Classic checkout: refuses an intro price for a customer who has had the product, and takes the coupon off.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors Validation errors.
	 */
	public static function validate( $data, $errors ): void {
		self::check( is_array( $data ) ? (string) ( $data['billing_email'] ?? '' ) : '', $errors, null );
	}

	/**
	 * Block checkout: the same rule.
	 *
	 * @param mixed $errors Cart errors.
	 * @param mixed $cart   Cart.
	 */
	public static function store_api_errors( $errors, $cart = null ): void {
		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';

		self::check( $email, $errors, $cart instanceof \WC_Cart ? $cart : null );
	}

	/**
	 * The rule.
	 *
	 * @param string        $email  Billing email.
	 * @param mixed         $errors Errors.
	 * @param \WC_Cart|null $cart   Cart.
	 */
	private static function check( string $email, $errors, ?\WC_Cart $cart ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );

		foreach ( self::lines( $cart ) as $code => $product_ids ) {
			if ( ! $cart || ! $cart->has_discount( $code ) ) {
				continue;
			}

			// One introductory price per customer: a cart with the same offer on two lines is refused as well as a customer who had the product.
			$held = count( $product_ids ) > 1;

			foreach ( $product_ids as $product_id ) {
				$held = $held || self::held( $product_id, get_current_user_id(), $email );
			}

			if ( ! $held ) {
				continue;
			}

			$product_id = (int) $product_ids[0];
			$cart->remove_coupon( $code );
			$errors->add(
				'beltoft_services_intro_used',
				/* translators: %s: product name. */
				sprintf( __( 'The introductory price for %s is for new customers, one account each. We took it off your cart, please check the new total and place the order again.', 'beltoft-services' ), (string) get_the_title( $product_id ) )
			);
		}
	}
}

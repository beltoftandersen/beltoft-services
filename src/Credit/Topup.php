<?php
/**
 * Top-ups: a hidden product, the cart form, crediting, refunds and chargebacks.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * A top-up is a prepayment: the hidden virtual product "Account credit" has tax status none, so no VAT is charged on
 * it (also not for a reverse-charge customer); VAT is charged when usage is invoiced (Credit\Invoice). The customer
 * pays exactly the chosen amount and all of it is credited. The credit amount is filterable
 * (beltoft_services_credit_amount_from_order); its default is the line total.
 *
 * One line per top-up, quantity 1, price set from the validated amount in the cart item (never from the request at
 * checkout). Credited when the order is processing or completed, once per order item (ledger reference
 * order_item "<order>-<item>"). A refund debits the refunded amount back; a top-up order that leaves
 * processing/completed for on-hold, failed, cancelled or refunded is a chargeback and debits the credit that is
 * left; going back to processing/completed gives it back once. The balance may go below 0, which is recorded and
 * noted on the order.
 */
final class Topup {

	public const OPTION_PRODUCT = 'beltoft_services_credit_product';

	/**
	 * Marker on the product, the cart item (cents) and the order item (decimal amount).
	 */
	public const META_PRODUCT = '_beltoft_services_credit_product';
	public const CART_KEY     = 'beltoft_services_credit_cents';
	public const META_AMOUNT  = '_beltoft_services_credit_amount';

	/** Cart item key (and order line meta): the currency the amount was validated in. */
	public const CURRENCY_KEY  = 'beltoft_services_price_currency';
	public const META_CURRENCY = '_beltoft_services_credit_currency';

	/** Order meta: a paid top-up that could not be credited in its currency was put on hold (once). */
	public const META_BLOCKED = '_beltoft_services_credit_blocked';

	/** @var bool One notice per request when a line is removed for its currency. */
	private static bool $noticed = false;

	/**
	 * The receipt wording of a top-up.
	 */
	public static function receipt_text(): string {
		return __( 'Prepayment for Pay as you go usage. No VAT is charged on a top-up; VAT is charged on your usage invoices.', 'beltoft-services' );
	}

	public const NONCE  = 'beltoft_services_credit';
	public const ACTION = 'beltoft_services_credit_topup';

	/**
	 * True while the form handler adds the line, the only way the product may enter a cart.
	 *
	 * @var bool
	 */
	private static $adding = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'settings_fields' ), 9 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_product_page' ), 1 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price_lines' ), 5 );
		// Also when the cart is read (the Store API shows it before it is recalculated): a line in another currency is gone then too.
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'price_lines' ), 5 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_name' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_note' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'no_coupons' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 10, 2 );

		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'credit_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'credit_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'credit_order' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 10, 3 );
	}

	/**
	 * The account credit settings on the General tab.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions by key.
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_fields( array $fields ): array {
		return array_merge(
			$fields,
			array(
				'credit_enabled' => array(
					'tab'     => 'general',
					'section' => 'credit',
					'type'    => 'checkbox',
					'default' => 0,
					'label'   => __( 'Account credit for pay as you go', 'beltoft-services' ),
					'desc'    => __( 'Customers prepay a balance, without VAT. It is applied first to their Pay as you go usage invoices, which carry the VAT. Off, nothing about credit shows.', 'beltoft-services' ),
				),
				'credit_min' => array(
					'tab'     => 'general',
					'section' => 'credit',
					'type'    => 'number',
					'default' => 10,
					'min'     => 1,
					'label'   => __( 'Smallest top-up', 'beltoft-services' ),
					'desc'    => __( 'In the store currency. No VAT is charged on a top-up: the customer pays this amount and all of it is credited.', 'beltoft-services' ),
				),
				'credit_max' => array(
					'tab'     => 'general',
					'section' => 'credit',
					'type'    => 'number',
					'default' => 1000,
					'min'     => 1,
					'label'   => __( 'Largest top-up', 'beltoft-services' ),
					'desc'    => __( 'In the store currency.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * A limit of a currency (whole amounts), or null when the currency has none (see Currency::amount()).
	 *
	 * @param string $key      Setting key.
	 * @param string $currency Currency code, '' for the current one.
	 */
	private static function limit( string $key, string $currency ): ?int {
		$value = Currency::amount( $key, $currency );

		return null === $value ? null : max( 1, (int) $value );
	}

	/**
	 * Whether top-ups are sold in a currency: it has both limits. A currency with no amount for them has no top-ups.
	 *
	 * @param string $currency Currency code, '' for the current one.
	 */
	public static function available( string $currency = '' ): bool {
		return null !== self::limit( 'credit_min', $currency ) && null !== self::limit( 'credit_max', $currency );
	}

	/**
	 * The smallest top-up in a currency (the store currency by default). 0 when top-ups are not sold in it.
	 *
	 * @param string $currency Currency code, '' for the current one.
	 */
	public static function min( string $currency = '' ): int {
		return self::limit( 'credit_min', $currency ) ?? 0;
	}

	/**
	 * The largest top-up in a currency (the store currency by default). 0 when top-ups are not sold in it.
	 *
	 * @param string $currency Currency code, '' for the current one.
	 */
	public static function max( string $currency = '' ): int {
		$max = self::limit( 'credit_max', $currency );

		return null === $max ? 0 : max( self::min( $currency ), $max );
	}

	/**
	 * The fixed amounts offered, within min and max of the currency. None when top-ups are not sold in it.
	 *
	 * @param string $currency Currency code, '' for the current one.
	 * @return int[]
	 */
	public static function amounts( string $currency = '' ): array {
		$currency = Currency::or_current( $currency );

		if ( ! self::available( $currency ) ) {
			return array();
		}

		/**
		 * Filters the fixed top-up amounts (whole amounts of the currency). The default list is for the store currency only:
		 * another currency starts with none, because euro amounts are not scaled.
		 *
		 * @param int[]  $amounts  Default 10, 25, 50, 100 and 250 in the store currency, none in another.
		 * @param string $currency Currency code.
		 */
		$amounts = (array) apply_filters( 'beltoft_services_credit_amounts', Currency::base() === $currency ? array( 10, 25, 50, 100, 250 ) : array(), $currency );
		$amounts = array_values( array_unique( array_map( 'intval', $amounts ) ) );
		$amounts = array_values(
			array_filter(
				$amounts,
				static function ( int $a ) use ( $currency ): bool {
					return $a >= self::min( $currency ) && $a <= self::max( $currency );
				}
			)
		);
		sort( $amounts );

		return $amounts;
	}

	/**
	 * The hidden product, made when it does not exist.
	 */
	public static function product_id(): int {
		$id = (int) get_option( self::OPTION_PRODUCT );

		$existing = $id > 0 ? wc_get_product( $id ) : false;

		if ( $existing instanceof \WC_Product ) {
			// An older top-up product was taxable: a top-up carries no VAT.
			if ( 'none' !== $existing->get_tax_status() ) {
				$existing->set_tax_status( 'none' );
				$existing->save();
			}

			return $id;
		}

		$product = new \WC_Product_Simple();
		$product->set_name( __( 'Account credit', 'beltoft-services' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_tax_status( 'none' );
		$product->set_regular_price( '0' );
		$product->update_meta_data( self::META_PRODUCT, 'yes' );
		$product->save();

		update_option( self::OPTION_PRODUCT, $product->get_id(), false );

		return $product->get_id();
	}

	/**
	 * Is this the top-up product?
	 *
	 * @param int $product_id Product id.
	 */
	public static function is_product( int $product_id ): bool {
		$id = (int) get_option( self::OPTION_PRODUCT );

		return $id > 0 && $id === $product_id;
	}

	/**
	 * Cents of a typed amount, or null when it is not a valid top-up: digits with at most two decimals, within min and max.
	 *
	 * @param mixed  $raw      Typed value.
	 * @param string $currency Currency code, '' for the current one. Null when top-ups are not sold in it.
	 */
	public static function parse( $raw, string $currency = '' ): ?int {
		if ( ! self::available( $currency ) ) {
			return null;
		}

		$raw = trim( str_replace( ',', '.', (string) $raw ) );

		if ( ! preg_match( '/^\d{1,7}(\.\d{1,2})?$/D', $raw ) ) {
			return null;
		}

		$cents = Balance::cents( $raw );

		return $cents >= self::min( $currency ) * 100 && $cents <= self::max( $currency ) * 100 ? $cents : null;
	}

	/**
	 * An amount in cents as "25.00 EUR".
	 *
	 * @param int    $cents    Cents.
	 * @param string $currency Currency code, '' for the shop's.
	 */
	public static function label_amount( int $cents, string $currency = '' ): string {
		return number_format( $cents / 100, 2, '.', '' ) . ' ' . ( '' !== $currency ? $currency : get_woocommerce_currency() );
	}

	/**
	 * The name of a top-up line.
	 */
	public static function line_name(): string {
		return __( 'Account credit', 'beltoft-services' );
	}

	/**
	 * Puts a top-up in the cart, replacing an earlier one.
	 *
	 * @param int $cents Validated cents.
	 * @return bool
	 */
	public static function add_to_cart( int $cents ): bool {
		if ( ! WC()->cart || ! Config::enabled() || ! is_user_logged_in() || null === self::parse( number_format( $cents / 100, 2, '.', '' ) ) ) {
			return false;
		}

		// Credit is in one currency: a customer whose credit is in another one cannot top up in this one.
		if ( ! Balance::allows( get_current_user_id(), Currency::current() ) ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( self::is_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}

		self::$adding = true;
		// The line carries the currency the amount was validated in, so a cart that is re-priced to another currency cannot charge it there.
		$key          = WC()->cart->add_to_cart( self::product_id(), 1, 0, array(), array( self::CART_KEY => $cents, self::CURRENCY_KEY => Currency::current() ) );
		self::$adding = false;

		return false !== $key;
	}

	/**
	 * The top-up form: validates on the server, fills the cart and goes to checkout.
	 */
	public static function handle(): void {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || empty( $_POST[ self::ACTION ] ) || ! Config::enabled() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			return;
		}

		$back = Page::url();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			wc_add_notice( __( 'The page expired. Please try again.', 'beltoft-services' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$cents = self::amount_from_post( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.

		if ( null === $cents && ! self::available() ) {
			wc_add_notice( __( 'Top-ups are not available in your currency.', 'beltoft-services' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		if ( null === $cents ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: smallest amount, 2: largest amount (with currency). */
					__( 'Enter an amount from %1$s to %2$s.', 'beltoft-services' ),
					self::label_amount( self::min() * 100 ),
					self::label_amount( self::max() * 100 )
				),
				'error'
			);
			wp_safe_redirect( $back );
			exit;
		}

		if ( ! self::add_to_cart( $cents ) ) {
			wc_add_notice( __( 'The top-up could not be added to the cart.', 'beltoft-services' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * The validated cents of a submitted form: a fixed amount from the list, or a typed amount within min and max.
	 *
	 * @param array<string, mixed> $post Submitted fields.
	 */
	public static function amount_from_post( array $post ): ?int {
		$choice = isset( $post['beltoft_credit_amount'] ) ? sanitize_text_field( wp_unslash( (string) $post['beltoft_credit_amount'] ) ) : '';
		$typed  = 'custom' === $choice ? ( isset( $post['beltoft_credit_custom'] ) ? sanitize_text_field( wp_unslash( (string) $post['beltoft_credit_custom'] ) ) : '' ) : $choice;
		$cents  = self::parse( $typed );

		if ( null !== $cents && 'custom' !== $choice && ( 0 !== $cents % 100 || ! in_array( (int) ( $cents / 100 ), self::amounts(), true ) ) ) {
			return null;
		}

		return $cents;
	}

	/**
	 * The product has no page: its address goes to the top-up page.
	 */
	public static function no_product_page(): void {
		$id = (int) get_option( self::OPTION_PRODUCT );

		if ( $id > 0 && is_singular( 'product' ) && get_queried_object_id() === $id ) {
			wp_safe_redirect( Page::url() );
			exit;
		}
	}

	/**
	 * Only the form may add the product, and only while account credit is on and a customer is logged in.
	 *
	 * @param mixed $valid      Valid so far.
	 * @param mixed $product_id Product id.
	 * @return mixed
	 */
	public static function validate_add( $valid, $product_id ) {
		if ( ! self::is_product( (int) $product_id ) ) {
			return $valid;
		}

		return self::$adding && Config::enabled() && is_user_logged_in() ? $valid : false;
	}

	/**
	 * Keeps the product out of every other way of buying.
	 *
	 * @param mixed $purchasable Purchasable.
	 * @param mixed $product     Product.
	 * @return mixed
	 */
	public static function purchasable( $purchasable, $product ) {
		return $purchasable && $product instanceof \WC_Product && self::is_product( $product->get_id() ) && ! Config::enabled() ? false : $purchasable;
	}

	/**
	 * Sets the price of each top-up line from its validated amount. A line without a valid amount (added some
	 * other way, or the limits changed) is removed.
	 *
	 * @param mixed $cart Cart.
	 */
	public static function price_lines( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				continue;
			}

			$cents = (int) ( $item[ self::CART_KEY ] ?? 0 );
			// A line with no currency (made before lines carried one) was validated in the shop's currency.
			$tag   = '' !== (string) ( $item[ self::CURRENCY_KEY ] ?? '' ) ? (string) $item[ self::CURRENCY_KEY ] : Currency::base();

			// The cents were validated in the currency on the line (and the customer's credit is in one currency, or the one fixed for them): in another one the line goes, with one notice.
			if ( Currency::current() !== $tag || ! Balance::allows( get_current_user_id(), Currency::current() ) ) {
				$cart->remove_cart_item( $key );

				if ( ! self::$noticed && function_exists( 'wc_add_notice' ) ) {
					self::$noticed = true;
					wc_add_notice( __( 'Your top-up was removed from the cart because the currency changed. Add it again in the new currency.', 'beltoft-services' ), 'notice' );
				}

				continue;
			}

			$cart->cart_contents[ $key ][ self::CURRENCY_KEY ] = Currency::current();

			if ( ! Config::enabled() || ! self::available() || $cents < self::min() * 100 || $cents > self::max() * 100 ) {
				$cart->remove_cart_item( $key );
				continue;
			}

			$item['data']->set_tax_status( 'none' );
			$item['data']->set_price( number_format( $cents / 100, 2, '.', '' ) );
			$cart->cart_contents[ $key ]['quantity'] = 1;
		}
	}

	/**
	 * Cart name.
	 *
	 * @param mixed $name Name markup.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public static function cart_name( $name, $item ) {
		if ( is_array( $item ) && self::is_product( (int) ( $item['product_id'] ?? 0 ) ) && (int) ( $item[ self::CART_KEY ] ?? 0 ) > 0 ) {
			return esc_html( self::line_name() );
		}

		return $name;
	}

	/**
	 * The receipt note on the cart and checkout line.
	 *
	 * @param mixed $data Item data.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public static function cart_note( $data, $item ) {
		if ( is_array( $data ) && is_array( $item ) && self::is_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
			$data[] = array(
				'key'   => __( 'Note', 'beltoft-services' ),
				'value' => self::receipt_text(),
			);
		}

		return $data;
	}

	/**
	 * Names the order line and stores the amount on it.
	 *
	 * @param mixed $item          Order item.
	 * @param mixed $cart_item_key Key.
	 * @param mixed $values        Cart item.
	 */
	public static function order_line( $item, $cart_item_key, $values ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( ! $item instanceof \WC_Order_Item_Product || ! is_array( $values ) || ! self::is_product( (int) ( $values['product_id'] ?? 0 ) ) ) {
			return;
		}

		$cents = (int) ( $values[ self::CART_KEY ] ?? 0 );
		$item->set_name( self::line_name() );
		$item->add_meta_data( self::META_AMOUNT, number_format( $cents / 100, 2, '.', '' ), true );
		$item->add_meta_data( self::META_CURRENCY, (string) ( $values[ self::CURRENCY_KEY ] ?? Currency::current() ), true );
		$item->add_meta_data( __( 'Note', 'beltoft-services' ), self::receipt_text(), true );
	}

	/**
	 * No coupon applies to a top-up.
	 *
	 * @param mixed $valid   Valid.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function no_coupons( $valid, $product ) {
		return $product instanceof \WC_Product && self::is_product( $product->get_id() ) ? false : $valid;
	}

	/**
	 * The top-up lines of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, \WC_Order_Item_Product> Items by id.
	 */
	public static function items( \WC_Order $order ): array {
		$out = array();

		foreach ( $order->get_items() as $id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && '' !== (string) $item->get_meta( self::META_AMOUNT ) ) {
				$out[ (int) $id ] = $item;
			}
		}

		return $out;
	}

	/**
	 * Reference id of an order item.
	 *
	 * @param int $order_id Order.
	 * @param int $item_id  Item.
	 */
	private static function ref( int $order_id, int $item_id ): string {
		return $order_id . '-' . $item_id;
	}

	/**
	 * The credit an order line adds: the line total, what the customer paid for it (no VAT), through the filter.
	 *
	 * @param \WC_Order_Item_Product $item  Item.
	 * @param \WC_Order              $order Order.
	 */
	public static function credit_cents( \WC_Order_Item_Product $item, \WC_Order $order ): int {
		$cents = Balance::cents( (float) $item->get_total() );

		/**
		 * Filters the credit a top-up order line adds to the balance, in cents. The default is the line total.
		 *
		 * @param int                    $cents Cents.
		 * @param \WC_Order_Item_Product $item  Order line.
		 * @param \WC_Order              $order Order.
		 */
		return max( 0, (int) apply_filters( 'beltoft_services_credit_amount_from_order', $cents, $item, $order ) );
	}

	/**
	 * Credits a paid top-up order, once per line.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function credit_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			return;
		}

		$items = self::items( $order );

		if ( ! $items ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();

		if ( $user_id < 1 ) {
			$order->add_order_note( __( 'Account credit not added: the order has no customer account.', 'beltoft-services' ) );

			return;
		}

		foreach ( $items as $item_id => $item ) {
			$ref   = self::ref( (int) $order->get_id(), $item_id );
			$cents = self::credit_cents( $item, $order );

			// The credit is in the order's currency and equals what was paid.
			if ( $cents > 0 && Balance::credit( $user_id, $cents, Balance::TOPUP, 'order_item', $ref, __( 'Top-up', 'beltoft-services' ), (string) $order->get_currency() ) ) {
				$order->add_order_note( sprintf( /* translators: %s: amount. */ __( 'Account credit of %s added to the balance.', 'beltoft-services' ), self::label_amount( $cents, $order->get_currency() ) ) );
			} elseif ( $cents > 0 && ! Balance::exists( Balance::TOPUP, 'order_item', $ref ) ) {
				$own = Balance::currency( $user_id );

				if ( ! Balance::allows( $user_id, (string) $order->get_currency() ) ) {
					self::block( $order, $user_id, Balance::account_currency( $user_id ) );
				} else {
					$order->add_order_note( __( 'Account credit could not be added now (balance busy). It is added when the order status is saved again.', 'beltoft-services' ) );
				}
			}

			self::reinstate( $user_id, $order, $ref );
		}
	}

	/**
	 * A paid top-up that cannot be credited because the customer's credit is in another currency: once per order, a note, the order on hold
	 * so the admin refunds or resolves it, and one alert. Saving the status again does none of it twice.
	 *
	 * @param \WC_Order $order    Order.
	 * @param int       $user_id  Customer.
	 * @param string    $currency The customer's credit currency.
	 */
	private static function block( \WC_Order $order, int $user_id, string $currency ): void {
		if ( '' !== (string) $order->get_meta( self::META_BLOCKED ) ) {
			return;
		}

		$order->update_meta_data( self::META_BLOCKED, 'yes' );
		$order->save();

		$note = sprintf( /* translators: %s: currency code. */ __( 'Account credit not added: the customer\'s credit is in %s, not in the currency of this order. The order is on hold: refund it or resolve it by hand.', 'beltoft-services' ), $currency );

		Alerts::notify(
			/* translators: %s: order number */
			sprintf( __( 'Top-up #%s could not be credited', 'beltoft-services' ), $order->get_order_number() ),
			array( sprintf( 'Top-up order %1$s of customer %2$d is in %3$s but their credit is in %4$s. Nothing was credited and the order was put on hold.', $order->get_order_number(), $user_id, $order->get_currency(), $currency ) ),
			null,
			'beltoft_services_credit_blocked_' . $order->get_id()
		);

		$order->update_status( 'on-hold', $note );
	}

	/**
	 * How many times a line's chargeback has been given back.
	 *
	 * @param string $ref Line reference.
	 */
	private static function round_of( string $ref ): int {
		$k = 0;

		while ( Balance::exists( Balance::ADJUST, 'reinstate', $ref . '-' . $k ) ) {
			++$k;
		}

		return $k;
	}

	/**
	 * A line that was charged back and is paid again gets its credit back, once per chargeback.
	 *
	 * @param int       $user_id Customer.
	 * @param \WC_Order $order   Order.
	 * @param string    $ref     Line reference.
	 */
	private static function reinstate( int $user_id, \WC_Order $order, string $ref ): void {
		$k     = self::round_of( $ref );
		$taken = Balance::find( Balance::CHARGEBACK, 'order_item', $ref . '-' . $k );

		if ( $taken && Balance::credit( $user_id, abs( (int) $taken['amount_cents'] ), Balance::ADJUST, 'reinstate', $ref . '-' . $k, sprintf( /* translators: %s: order number. */ __( 'Chargeback undone, order #%s', 'beltoft-services' ), $order->get_order_number() ), (string) $order->get_currency() ) ) {
			$order->add_order_note( __( 'The order is paid again: the credit taken back for the chargeback was returned to the balance.', 'beltoft-services' ) );
		}
	}

	/**
	 * What of a line's credit is still on the balance owner's account: the credit, less refunds and chargebacks, plus returns.
	 *
	 * @param string $ref Line reference.
	 */
	private static function remaining( string $ref ): int {
		$credited = Balance::find( Balance::TOPUP, 'order_item', $ref );

		if ( ! $credited ) {
			return 0;
		}

		return (int) $credited['amount_cents']
			+ Balance::sum_prefix( Balance::REFUND, 'order_item', $ref . '-' )
			+ Balance::sum_prefix( Balance::CHARGEBACK, 'order_item', $ref . '-' )
			+ Balance::sum_prefix( Balance::ADJUST, 'reinstate', $ref . '-' );
	}

	/**
	 * A refund of a top-up order takes the refunded amount back.
	 *
	 * @param mixed $order_id  Order id.
	 * @param mixed $refund_id Refund id.
	 */
	public static function refunded( $order_id, $refund_id ): void {
		$order  = wc_get_order( (int) $order_id );
		$refund = wc_get_order( (int) $refund_id );

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$items   = self::items( $order );
		$user_id = (int) $order->get_customer_id();

		if ( ! $items || $user_id < 1 ) {
			return;
		}

		$refund_lines = $refund->get_items();
		$order_total  = (float) $order->get_total();

		foreach ( $items as $item_id => $item ) {
			$ref = self::ref( (int) $order->get_id(), $item_id );
			$was = Balance::find( Balance::TOPUP, 'order_item', $ref );

			if ( ! $was ) {
				continue;
			}

			$want = 0;

			if ( $refund_lines ) {
				foreach ( $refund_lines as $line ) {
					if ( (int) $line->get_meta( '_refunded_item_id' ) === $item_id ) {
						$want += Balance::cents( abs( (float) $line->get_total() ) );
					}
				}
			} elseif ( $order_total > 0 ) {
				// An amount without lines: the same share of the credit as of the order total.
				$want = (int) round( (int) $was['amount_cents'] * min( 1.0, abs( (float) $refund->get_amount() ) / $order_total ) );
			}

			$cents = min( $want, max( 0, self::remaining( $ref ) ) );
			$ok    = $cents > 0 && Balance::debit( $user_id, $cents, Balance::REFUND, 'order_item', $ref . '-' . (int) $refund_id, sprintf( /* translators: %s: order number. */ __( 'Refund, order #%s', 'beltoft-services' ), $order->get_order_number() ), (string) $order->get_currency() );

			if ( $ok ) {
				$order->add_order_note( sprintf( /* translators: %s: amount. */ __( 'Refund: %s taken back from the account credit.', 'beltoft-services' ), self::label_amount( $cents, $order->get_currency() ) ) );
				self::note_negative( $user_id, $order );
			}
		}
	}

	/**
	 * A top-up order that stops being paid is a chargeback; one that is paid again gets its credit back.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $from     Old status.
	 * @param mixed $to       New status.
	 */
	public static function status_changed( $order_id, $from, $to ): void {
		$paid = array( 'processing', 'completed' );

		if ( ! in_array( $from, $paid, true ) || in_array( $to, $paid, true ) || ! in_array( $to, array( 'on-hold', 'failed', 'refunded', 'cancelled' ), true ) ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// A refund object is already saved when a refund moves the order to refunded: its own handler debits.
		if ( 'refunded' === $to && (float) $order->get_total_refunded() > 0 ) {
			return;
		}

		$items   = self::items( $order );
		$user_id = (int) $order->get_customer_id();

		if ( ! $items || $user_id < 1 ) {
			return;
		}

		foreach ( $items as $item_id => $item ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $item unused.
			$ref   = self::ref( (int) $order->get_id(), $item_id );
			$cents = max( 0, self::remaining( $ref ) );

			if ( $cents > 0 && Balance::debit( $user_id, $cents, Balance::CHARGEBACK, 'order_item', $ref . '-' . self::round_of( $ref ), sprintf( /* translators: 1: order number, 2: new status. */ __( 'Chargeback, order #%1$s is now %2$s', 'beltoft-services' ), $order->get_order_number(), (string) $to ), (string) $order->get_currency() ) ) {
				$order->add_order_note( sprintf( /* translators: %s: amount. */ __( 'The order is no longer paid: %s taken back from the account credit.', 'beltoft-services' ), self::label_amount( $cents, $order->get_currency() ) ) );
				self::note_negative( $user_id, $order );
			}
		}
	}

	/**
	 * Notes a negative balance on the order, for the admin.
	 *
	 * @param int       $user_id Customer.
	 * @param \WC_Order $order   Order.
	 */
	private static function note_negative( int $user_id, \WC_Order $order ): void {
		$balance = Balance::get( $user_id );

		if ( $balance < 0 ) {
			$order->add_order_note( sprintf( /* translators: 1: customer id, 2: balance. */ __( 'The account credit of customer #%1$d is now negative: %2$s. The customer has used credit that was paid back.', 'beltoft-services' ), $user_id, self::label_amount( $balance, $order->get_currency() ) ) );
		}
	}
}

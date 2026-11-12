<?php
/**
 * Account credit paid onto the first order at checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Alerts;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Card\Card;
use BeltoftServices\Currency;
use BeltoftServices\Usage\Cart as UsageCart;
use BeltoftServices\Usage\Minimum;

defined( 'ABSPATH' ) || exit;

/**
 * With the setting `credit_checkout` on, a signed-in customer's credit pays the order the checkout creates, before the card is asked.
 * It is the mechanism of renewals and usage invoices ({@see Invoice::apply()}, with {@see RenewalCredit::promo_cap()} and the usage
 * minimum): a non-taxable negative fee "Paid from account credit" on the ORDER, the ledger debit once per order, lines and taxes as
 * they are. The cart is never given a negative fee (WooCommerce would treat it as a discount and take VAT off).
 *
 * Order of operations (WC_Checkout::process_checkout): validation, create_order (filter woocommerce_create_order first), the action
 * woocommerce_checkout_order_processed (WooCommerce Subscriptions creates the subscriptions at 100; the credit is applied at 200), then
 * the decision "needs payment" from the order total, then the gateway is asked to charge the ORDER total.
 *
 * A card always needs to be there to use credit: the checkout still asks for a payment method. A cart with a subscription or pay as you
 * go services saves the card at checkout (the gateway's zero-total path), also when credit covers all of it. Any other cart uses credit
 * only when the customer already has a usable saved card; with none, no credit is used and the card pays in full. When credit covers a
 * plain cart in full, WooCommerce completes the order of 0 without asking the gateway (process_order_without_payment): nothing is charged.
 *
 * What the customer sees is a forecast by the same rule: {@see CheckoutCredit::preview()} reads the cart's own total (incl. VAT) and the
 * lines the promotional box allows, then asks {@see Invoice::split()}, which is also what Invoice::apply() asks with the order's total
 * and lines. Both are shown through WooCommerce's own hooks (the row before the order total, the order total, whether the cart needs a
 * payment method) and in the cart data the Stripe gateway gives the browser (the amount of the card form and of the wallet sheets).
 *
 * A retry of the same cart: WooCommerce resumes the unpaid order (session order_awaiting_payment, same cart hash) and rebuilds its
 * lines, which drops the fee while the ledger keeps the debit. That order keeps its credit: it is not released, and when it comes back
 * through woocommerce_checkout_order_processed its fee and lowered total are put back from the ledger (Invoice::restore(), no ledger
 * movement). This is also what a second submit during 3D Secure meets: the gateway finishes a next action in the browser and on its
 * return URL, not by submitting the form again, so an order is not released in the middle of its own payment. If the rebuilt total is
 * lower than the credit, the credit is given back and the order goes on without credit (it is then paid in full; it is not marked
 * released, because it is the order being paid now).
 *
 * Any other unpaid credited order of the customer (a changed cart, a lost session) is released before a new order is made: its credit is
 * given back, its fee removed, it is not resumed (cart hash cleared), and it is marked released. An order that fails is released the same
 * way. A released order can no longer be paid: the Pay link and order-pay refuse it, and a payment that was started before the release
 * (the browser holds the client secret of an intent for the lowered amount) and completes afterwards must not both keep the credit that
 * went back and fulfil the order: such an order is kept on hold, with a note and one admin alert, until an administrator moves it on. The
 * ledger's unique key (kind, order reference) would refuse a second debit for the same order anyway; a retry with a changed cart is a new
 * order that takes the credit again, and the forecast counts what the orders to be released hold.
 */
final class CheckoutCredit {

	/** Order meta: credit was applied to this order by the checkout ('yes'). */
	public const META = '_beltoft_services_credit_checkout';

	/** Order meta: when the credit of this unpaid order was given back by a new attempt or a failure. */
	public const META_RELEASED = '_beltoft_services_credit_released';

	/** Order meta: the cents that were given back (what a late payment of the released order is short by). */
	public const META_RELEASED_CENTS = '_beltoft_services_credit_released_cents';

	/** Order meta: the guard has held a late payment of this released order ('yes'). */
	public const META_GUARDED = '_beltoft_services_credit_guarded';

	/** Order meta: an administrator moved a held released order on ('yes'); the guard no longer applies. */
	public const META_CLEARED = '_beltoft_services_credit_cleared';

	/**
	 * Per request: forecasts, by customer, currency, cart total, promotional cap, balance and awaiting order.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $memo = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// After WooCommerce Subscriptions has made its subscriptions (100), before the decision whether a payment is needed.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'apply' ), 200, 3 );
		// Before WooCommerce decides whether to resume the order that awaits payment.
		add_filter( 'woocommerce_create_order', array( __CLASS__, 'release_awaiting' ), 5 );
		// After Invoice::failed (10), which gives the credit back and removes the fee.
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'after_failed' ), 20 );
		// A released order cannot be paid by the customer, and a late payment of one is held (see the class comment). Last word: WooCommerce adds its draft status to the list too.
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( __CLASS__, 'no_payment_for_released' ), 999, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'no_complete_for_released' ), 999, 2 );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'hold_before_save' ), 10, 1 );
		foreach ( array( 'pending', 'failed', 'on-hold', 'cancelled' ) as $status ) {
			add_action( 'woocommerce_payment_complete_order_status_' . $status, array( __CLASS__, 'hold_late_payment' ), 10, 1 );
		}
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'review_row' ) );
		add_filter( 'woocommerce_cart_totals_order_total_html', array( __CLASS__, 'total_html' ), 10 );
		// What the Stripe gateway tells the browser about the cart: the amount of the card form and of the wallet sheets.
		add_filter( 'wc_stripe_cart_data', array( __CLASS__, 'stripe_cart_data' ), 20, 2 );
	}

	/**
	 * An unsaved order that stands for the one the checkout will make, for the filter and for the promotional box of a line.
	 *
	 * @param int $user_id Customer.
	 */
	private static function draft( int $user_id ): \WC_Order {
		$order = new \WC_Order();
		$order->set_currency( Currency::current() );
		$order->set_customer_id( $user_id );
		$order->set_created_via( 'checkout' );

		return $order;
	}

	/**
	 * May credit pay a cart of this kind? Not a top-up, a switch, a renewal or an early renewal.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	private static function cart_eligible( \WC_Cart $cart ): bool {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( CartFlow::has( $item, array( CartFlow::RENEWAL, CartFlow::SWITCH, CartFlow::RENEWAL_EARLY ) ) || Topup::is_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * May credit pay this order? The same kinds as cart_eligible(): made by the checkout, not a top-up, a switch or a renewal.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function order_eligible( \WC_Order $order ): bool {
		if ( 'checkout' !== $order->get_created_via() || array() !== Topup::items( $order ) ) {
			return false;
		}

		foreach ( array( 'wcs_order_contains_renewal', 'wcs_order_contains_switch' ) as $fn ) {
			if ( function_exists( $fn ) && $fn( $order ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Does the cart save a card by itself (a subscription, or pay as you go services)? Then the gateway saves the card at checkout, also
	 * when credit covers the whole cart and the total is 0.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	private static function cart_saves_card( \WC_Cart $cart ): bool {
		return ( class_exists( \WC_Subscriptions_Cart::class ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) || UsageCart::usage_units( $cart ) > 0;
	}

	/**
	 * A card always needs to be there to use credit: a cart that saves a card (see cart_saves_card()) is fine, any other cart only when the
	 * customer already has a usable saved card (the default saved method, one WooCommerce Subscriptions can charge: Card::default_token(),
	 * not expired: Card::usable()). One function for the forecast and for the order (the checkout cart is still there when the order is made).
	 *
	 * @param int           $user_id Customer.
	 * @param \WC_Cart|null $cart    Cart.
	 */
	private static function card_rule_met( int $user_id, ?\WC_Cart $cart ): bool {
		if ( $cart instanceof \WC_Cart && ! $cart->is_empty() && self::cart_saves_card( $cart ) ) {
			return true;
		}

		$token = Card::default_token( $user_id );

		return null !== $token && Card::usable( Card::summarize_token( $token ) );
	}

	/**
	 * The most promotional credit may pay of a cart, in cents: the lines whose product allows it, with their VAT. The same figure as
	 * RenewalCredit::promo_cap() gives for the order made from the cart (an order line holds the cart line's total and tax).
	 *
	 * @param \WC_Cart  $cart  Cart.
	 * @param \WC_Order $draft Draft order.
	 */
	private static function cart_cap( \WC_Cart $cart, \WC_Order $draft ): int {
		$sum = 0;

		foreach ( $cart->get_cart() as $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			$item = new \WC_Order_Item_Product();
			$item->set_props(
				array(
					'product_id'   => (int) ( $values['product_id'] ?? 0 ),
					'variation_id' => (int) ( $values['variation_id'] ?? 0 ),
					'total'        => $values['line_total'] ?? 0,
					'total_tax'    => $values['line_tax'] ?? 0,
				)
			);

			if ( RenewalCredit::line_allows_promo( $item, $draft ) ) {
				$sum += Balance::cents( (float) $item->get_total() + (float) $item->get_total_tax() );
			}
		}

		return $sum;
	}

	/**
	 * The unpaid orders of the checkout that hold credit of this customer (pending or failed, credit not given back). WooCommerce only
	 * remembers the one that awaits payment in the session, and forgets it when the cart is emptied, so they are found by customer.
	 *
	 * @param int $user_id Customer.
	 * @param int $except  An order id that is left out (the one WooCommerce will resume).
	 * @return \WC_Order[]
	 */
	private static function unpaid_orders( int $user_id, int $except = 0 ): array {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'pending', 'failed' ),
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'       => 20,
				'type'        => 'shop_order',
			)
		);

		return array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn( $o ) => $o instanceof \WC_Order && $o->get_id() !== $except && self::releasable( $o ) ) );
	}

	/**
	 * The order WooCommerce will resume for this cart, if it holds credit: the one that awaits payment in the session, unpaid, of this
	 * customer, with this cart's hash (the test of WC_Checkout::create_order()).
	 *
	 * @param int      $user_id Customer.
	 * @param \WC_Cart $cart    Cart.
	 */
	private static function resumable( int $user_id, \WC_Cart $cart ): ?\WC_Order {
		$id = function_exists( 'WC' ) && WC()->session ? absint( WC()->session->get( 'order_awaiting_payment' ) ) : 0;

		if ( $id < 1 ) {
			return null;
		}

		$order = wc_get_order( $id );

		if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== $user_id || ! self::releasable( $order ) || ! $order->has_cart_hash( $cart->get_cart_hash() ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Credit the customer's unpaid orders hold in a currency, which a new attempt gives back first: its parts in cents.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $currency Currency code.
	 * @param int    $except   An order id that is left out (the one WooCommerce will resume).
	 * @return array{promo: int, paid: int}
	 */
	private static function held( int $user_id, string $currency, int $except ): array {
		$sum = array( 'promo' => 0, 'paid' => 0 );

		foreach ( self::unpaid_orders( $user_id, $except ) as $old ) {
			if ( (string) $old->get_currency() !== $currency ) {
				continue;
			}

			$debit         = Invoice::debited( $old );
			$sum['promo'] += $debit['promo'];
			$sum['paid']  += $debit['paid'];
		}

		return $sum;
	}

	/**
	 * What credit pays of a cart: the forecast the checkout shows, by the rule the order will be given.
	 *
	 * Null when nothing is used: the setting is off, a guest, an empty cart, a cart of a kind credit does not pay, nothing to pay, no
	 * credit in the cart's currency, or the rest for the card would be under the usage minimum. Otherwise: credit (cents used), promo and
	 * paid (its parts), due (cents left for the card), total (the cart total in cents) and currency.
	 *
	 * @param \WC_Cart|null $cart Cart (the current one when null).
	 * @return array{credit: int, promo: int, paid: int, due: int, total: int, currency: string}|null
	 */
	public static function preview( ?\WC_Cart $cart = null ): ?array {
		if ( ! Config::checkout() ) {
			return null;
		}

		$user_id = get_current_user_id();
		$cart    = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );

		if ( $user_id < 1 || ! $cart instanceof \WC_Cart || $cart->is_empty() || ! self::cart_eligible( $cart ) ) {
			return null;
		}

		$total = Balance::cents( $cart->get_total( 'edit' ) );

		if ( $total < 1 ) {
			return null;
		}

		$draft    = self::draft( $user_id );
		$currency = Currency::current();
		$cap      = self::cart_cap( $cart, $draft );
		$awaiting = function_exists( 'WC' ) && WC()->session ? absint( WC()->session->get( 'order_awaiting_payment' ) ) : 0;
		$key      = implode( '|', array( $user_id, $currency, $total, $cap, Balance::get( $user_id ), $awaiting ) );

		if ( ! array_key_exists( $key, self::$memo ) ) {
			self::$memo[ $key ] = self::forecast( $user_id, $currency, $total, $cap, $draft, $cart );
		}

		return self::$memo[ $key ];
	}

	/**
	 * Forgets the forecasts of this request (a credit change, a test).
	 */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * The forecast itself.
	 *
	 * @param int       $user_id  Customer.
	 * @param string    $currency Currency code.
	 * @param int       $total    Cents due.
	 * @param int       $cap      Promotional cap in cents.
	 * @param \WC_Order $draft    Draft order.
	 * @param \WC_Cart  $cart     Cart.
	 * @return array{credit: int, promo: int, paid: int, due: int, total: int, currency: string}|null
	 */
	private static function forecast( int $user_id, string $currency, int $total, int $cap, \WC_Order $draft, \WC_Cart $cart ): ?array {
		// The filter is documented at apply(). On the checkout page the order does not exist yet: it gets an unsaved order that holds the customer and the currency, no lines.
		if ( ! apply_filters( 'beltoft_services_credit_applies_to_order', true, $draft, $user_id ) ) {
			return null;
		}

		// The order WooCommerce will resume keeps exactly what it holds: the forecast is its credit, not a fresh split.
		$resume = self::resumable( $user_id, $cart );

		if ( $resume ) {
			$debit  = Invoice::debited( $resume );
			$credit = $debit['promo'] + $debit['paid'];

			// A credit above the total cannot be put back (apply() gives it back and the order goes on without credit).
			if ( $credit < 1 || $credit > $total || (string) $resume->get_currency() !== $currency ) {
				return null;
			}

			return array(
				'credit'   => $credit,
				'promo'    => $debit['promo'],
				'paid'     => $debit['paid'],
				'due'      => $total - $credit,
				'total'    => $total,
				'currency' => $currency,
			);
		}

		if ( ! self::card_rule_met( $user_id, $cart ) ) {
			return null;
		}

		$held       = self::held( $user_id, $currency, 0 );
		$balance    = Balance::get_in( $user_id, $currency ) + $held['promo'] + $held['paid'];
		$promo_left = Balance::promo_left( $user_id ) + $held['promo'];
		$min        = (int) round( Minimum::amount( $currency ) * 100 );
		$parts      = Invoice::split( $total, $balance, min( $promo_left, $balance ), $cap, $min );

		if ( $parts['cents'] < 1 ) {
			return null;
		}

		return array(
			'credit'   => $parts['cents'],
			'promo'    => $parts['promo'],
			'paid'     => $parts['paid'],
			'due'      => $total - $parts['cents'],
			'total'    => $total,
			'currency' => $currency,
		);
	}

	/**
	 * Pays the new order from credit, before the payment is taken.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $posted   Posted checkout data.
	 * @param mixed $order    Order.
	 */
	public static function apply( $order_id, $posted = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// An order WooCommerce resumed: its items were rebuilt, its credit is still debited. This runs also with the setting off.
		if ( 'yes' === $order->get_meta( self::META ) && Invoice::used( $order ) > 0 ) {
			self::resume( $order );

			return;
		}

		if ( ! Config::checkout() ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();

		if ( $user_id < 1 || '' !== (string) $order->get_meta( Invoice::META_USED ) || ! self::order_eligible( $order ) ) {
			return;
		}

		/**
		 * Whether credit may pay this order at all. The default is true. The same filter decides for a renewal order, and on the checkout
		 * page (where the order does not exist yet) it gets an unsaved order that holds the customer and the currency but no lines.
		 *
		 * @param bool      $applies Default true.
		 * @param \WC_Order $order   The order.
		 * @param int       $user_id The customer.
		 */
		if ( ! apply_filters( 'beltoft_services_credit_applies_to_order', true, $order, $user_id ) ) {
			return;
		}

		if ( ! self::card_rule_met( $user_id, function_exists( 'WC' ) ? WC()->cart : null ) ) {
			return;
		}

		$min = (int) round( Minimum::amount( (string) $order->get_currency() ) * 100 );

		if ( Invoice::apply( $order, $user_id, $min, RenewalCredit::promo_cap( $order ) ) > 0 ) {
			$order->update_meta_data( self::META, 'yes' );
			$order->save();
		}
	}

	/**
	 * A resumed order carries its credit again: the fee and the lowered total are put back from the ledger. When that cannot be done
	 * (the rebuilt total is below the credit, or the ledger does not match) the credit is given back and the order goes on without it,
	 * to be paid in full; the order is not marked released, because it is the order being paid now.
	 *
	 * @param \WC_Order $order Resumed order.
	 */
	private static function resume( \WC_Order $order ): void {
		self::reset();

		if ( Invoice::restore( $order ) ) {
			$order->save();

			return;
		}

		Invoice::give_back( $order->get_id() );
		$order->update_meta_data( Invoice::META_USED, '0' );
		$order->delete_meta_data( self::META );
		$order->add_order_note( __( 'The account credit of this order could not be kept when the order was resumed. It was returned to the customer, and the order is paid in full.', 'beltoft-services' ) );
		$order->save();
		self::reset();
	}

	/**
	 * Is this an unpaid order of the checkout that holds credit?
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function releasable( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::META ) && Invoice::used( $order ) > 0 && $order->has_status( array( 'pending', 'failed' ) );
	}

	/**
	 * Before an order is made or resumed: the customer's unpaid credited orders, except the one WooCommerce will resume, give their credit
	 * back and are not resumed, so the new order takes the credit again (see the class comment).
	 *
	 * @param mixed $order_id An order id that another plugin made, normally null.
	 * @return mixed
	 */
	public static function release_awaiting( $order_id ) {
		$user_id = get_current_user_id();

		if ( $order_id || $user_id < 1 || ! Config::enabled() ) {
			return $order_id;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$keep = $cart instanceof \WC_Cart ? self::resumable( $user_id, $cart ) : null;

		foreach ( self::unpaid_orders( $user_id, $keep ? $keep->get_id() : 0 ) as $order ) {
			self::release( $order );
		}

		return $order_id;
	}

	/**
	 * Gives the credit of an unpaid order back, takes the fee off it, and keeps WooCommerce from resuming it.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function release( \WC_Order $order ): void {
		$used = Invoice::used( $order );
		$back = Invoice::give_back( $order->get_id() );

		self::reset();

		if ( $back > 0 ) {
			foreach ( $order->get_items( 'fee' ) as $id => $fee ) {
				if ( 'yes' === $fee->get_meta( Invoice::META_FEE ) ) {
					$order->remove_item( $id );
					$order->set_total( number_format( ( Balance::cents( $order->get_total() ) + $used ) / 100, 2, '.', '' ) );
				}
			}
		}

		self::seal( $order, $used );
	}

	/**
	 * An unpaid order whose credit has gone back: nothing is held, it is marked released (the customer can no longer pay it, and a late
	 * payment is held), and the next attempt is a new order.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $cents The credit that went back.
	 */
	private static function seal( \WC_Order $order, int $cents ): void {
		$order->update_meta_data( Invoice::META_USED, '0' );
		$order->update_meta_data( self::META_RELEASED, (string) time() );
		$order->update_meta_data( self::META_RELEASED_CENTS, (string) $cents );
		$order->set_cart_hash( '' );
		$order->save();
	}

	/**
	 * A failed order has had its credit given back and its fee removed (Invoice::failed); it is also not resumed.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function after_failed( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof \WC_Order && 'yes' === $order->get_meta( self::META ) && Invoice::used( $order ) > 0 ) {
			self::seal( $order, Invoice::used( $order ) );
			self::reset();
		}
	}

	/**
	 * Has the credit of this order been given back while the order is still unpaid, and not been cleared by an administrator?
	 *
	 * @param mixed $order Order.
	 */
	private static function released( $order ): bool {
		return $order instanceof \WC_Order && '' !== (string) $order->get_meta( self::META_RELEASED ) && 'yes' !== $order->get_meta( self::META_CLEARED );
	}

	/**
	 * Is a payment of this order to be held? Released, and the one moving it is not an administrator (a customer, a payment webhook).
	 *
	 * @param mixed $order Order.
	 */
	private static function held_back( $order ): bool {
		return self::released( $order ) && ! current_user_can( 'manage_woocommerce' );
	}

	/**
	 * A released order is not payable: the Pay link and the order-pay page refuse it.
	 *
	 * @param mixed $statuses Statuses that may be paid.
	 * @param mixed $order    Order.
	 * @return mixed
	 */
	public static function no_payment_for_released( $statuses, $order = null ) {
		return self::released( $order ) ? array() : $statuses;
	}

	/**
	 * payment_complete() does nothing to a released order (it neither sets a paid status nor fires woocommerce_payment_complete, which
	 * issues licences): hold_late_payment() holds it instead.
	 *
	 * @param mixed $statuses Statuses payment_complete() accepts.
	 * @param mixed $order    Order.
	 * @return mixed
	 */
	public static function no_complete_for_released( $statuses, $order = null ) {
		return self::held_back( $order ) ? array() : $statuses;
	}

	/**
	 * A gateway that sets processing or completed itself: the status is changed to on-hold before it is saved, so no paid-status action
	 * (licences, subscriptions) fires. An administrator moving the order on clears the guard.
	 *
	 * @param mixed $order Order.
	 */
	public static function hold_before_save( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::released( $order ) || ! in_array( $order->get_status( 'edit' ), array( 'processing', 'completed' ), true ) ) {
			return;
		}

		$changes = $order->get_changes();

		if ( ! isset( $changes['status'] ) ) {
			return;
		}

		if ( ! self::held_back( $order ) ) {
			$order->update_meta_data( self::META_CLEARED, 'yes' );

			return;
		}

		$order->set_status( 'on-hold' );
		$order->set_date_paid( null );
		self::note_guard( $order );
	}

	/**
	 * payment_complete() was asked of a released order and did nothing (no_complete_for_released): the order goes on hold.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function hold_late_payment( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || ! self::held_back( $order ) ) {
			return;
		}

		self::note_guard( $order );

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold' );
		} else {
			$order->save();
		}
	}

	/**
	 * Tells once, by an order note and one admin mail, that a released order was paid.
	 *
	 * @param \WC_Order $order Order. Saved by the caller.
	 */
	private static function note_guard( \WC_Order $order ): void {
		if ( 'yes' === $order->get_meta( self::META_GUARDED ) ) {
			return;
		}

		$order->update_meta_data( self::META_GUARDED, 'yes' );
		$short = Topup::label_amount( (int) $order->get_meta( self::META_RELEASED_CENTS ), (string) $order->get_currency() );
		$note  = sprintf(
			/* translators: %s: the amount of account credit that had been given back. */
			__( 'A payment arrived for this order after its account credit (%s) had been returned to the customer, so the payment can be that much short. The order is on hold and nothing is set up. Check what was paid, then move the order on by hand.', 'beltoft-services' ),
			$short
		);
		$order->add_order_note( $note );

		Alerts::notify(
			/* translators: %s: order number. */
			sprintf( __( 'Order #%s was paid after its account credit was returned', 'beltoft-services' ), $order->get_order_number() ),
			array( $note, 'The customer: ' . (int) $order->get_customer_id() . '. The order total is ' . $order->get_total() . ' ' . $order->get_currency() . '.' ),
			null,
			'beltoft_services_credit_late_' . (int) $order->get_id()
		);
	}

	/**
	 * Is this the checkout (its page or its refresh), not the cart page or an order that is paid later?
	 */
	private static function on_checkout(): bool {
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return false;
		}

		return ( function_exists( 'is_checkout' ) && is_checkout() ) || defined( 'WOOCOMMERCE_CHECKOUT' );
	}

	/**
	 * The row "Paid from account credit" before the order total of the checkout.
	 */
	public static function review_row(): void {
		$p = self::on_checkout() ? self::preview() : null;

		if ( null === $p ) {
			return;
		}

		echo '<tr class="fee beltoft-services-credit-row"><th>' . esc_html__( 'Paid from account credit', 'beltoft-services' ) . '</th><td data-title="' . esc_attr__( 'Paid from account credit', 'beltoft-services' ) . '">-' . wp_kses_post( wc_price( $p['credit'] / 100, array( 'currency' => $p['currency'] ) ) ) . '</td></tr>';
	}

	/**
	 * The order total of the checkout is what is left to pay after credit. The tax note after it stays: it is the VAT of the order.
	 *
	 * @param mixed $html Markup (the amount in strong, then the tax note).
	 * @return mixed
	 */
	public static function total_html( $html ) {
		$p = is_string( $html ) && self::on_checkout() ? self::preview() : null;

		if ( null === $p ) {
			return $html;
		}

		$price = wc_price( $p['due'] / 100, array( 'currency' => $p['currency'] ) );

		return (string) preg_replace_callback( '#<strong>.*?</strong>#s', static fn() => '<strong>' . $price . '</strong>', $html, 1 );
	}

	/**
	 * The cart as the Stripe gateway gives it to the browser carries the credit, so the card form and the wallet sheets show the amount
	 * the order will charge: the total after credit, with one more line.
	 *
	 * @param mixed $data Cart data.
	 * @param mixed $cart Cart.
	 * @return mixed
	 */
	public static function stripe_cart_data( $data, $cart = null ) {
		$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$p    = is_array( $data ) && $cart instanceof \WC_Cart ? self::preview( $cart ) : null;

		if ( null === $p ) {
			return $data;
		}

		$data['total']      = round( $p['due'] / 100, 2 );
		$data['totalCents'] = $p['due'];

		if ( isset( $data['lineItems'] ) && is_array( $data['lineItems'] ) ) {
			$data['lineItems'][] = array(
				'label'       => __( 'Paid from account credit', 'beltoft-services' ),
				'amount'      => -round( $p['credit'] / 100, 2 ),
				'amountCents' => -$p['credit'],
				'type'        => 'fee',
			);
		}

		return $data;
	}
}

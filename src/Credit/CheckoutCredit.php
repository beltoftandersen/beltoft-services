<?php
/**
 * Account credit paid onto the first order at checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Billing\CartFlow;
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
 * What the customer sees is a forecast by the same rule: {@see CheckoutCredit::preview()} reads the cart's own total (incl. VAT) and the
 * lines the promotional box allows, then asks {@see Invoice::split()}, which is also what Invoice::apply() asks with the order's total
 * and lines. Both are shown through WooCommerce's own hooks (the row before the order total, the order total, whether the cart needs a
 * payment method) and in the cart data the Stripe gateway gives the browser (the amount of the card form and of the wallet sheets).
 *
 * A retry: WooCommerce resumes an unpaid order of the same cart and then rebuilds its lines, which would drop the fee and keep the
 * debit. So before a new order is made the credit of the customer's unpaid checkout orders is given back (they are found by customer,
 * because WooCommerce forgets the order that awaits payment when the cart is emptied), their fee is removed, and they are not resumed
 * (their cart hash is cleared). The same is done when an order fails. The ledger's unique key (kind, order reference) would refuse a
 * second debit for the same order anyway; the retry is a new order that takes the credit again, and the forecast counts what the unpaid
 * orders hold, so the figure shown before the retry is the one applied.
 *
 * What stays open: a paid order keeps its credit; an unpaid order whose payment is still being finished in another browser tab
 * (3D Secure) is released by a new attempt, so its credit is given back although the card may still be charged for it.
 */
final class CheckoutCredit {

	/** Order meta: credit was applied to this order by the checkout ('yes'). */
	public const META = '_beltoft_services_credit_checkout';

	/** Order meta: when the credit of this unpaid order was given back by a new attempt or a failure. */
	public const META_RELEASED = '_beltoft_services_credit_released';

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
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'review_row' ) );
		add_filter( 'woocommerce_cart_totals_order_total_html', array( __CLASS__, 'total_html' ), 10 );
		// After WooCommerce Subscriptions (10), the trial rule (20) and the usage rule (30).
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 40, 2 );
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
	 * @return \WC_Order[]
	 */
	private static function unpaid_orders( int $user_id ): array {
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

		return array_values( array_filter( is_array( $orders ) ? $orders : array(), static fn( $o ) => $o instanceof \WC_Order && self::releasable( $o ) ) );
	}

	/**
	 * Credit the customer's unpaid orders hold in a currency, which a new attempt gives back first: its parts in cents.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $currency Currency code.
	 * @return array{promo: int, paid: int}
	 */
	private static function held( int $user_id, string $currency ): array {
		$sum = array( 'promo' => 0, 'paid' => 0 );

		foreach ( self::unpaid_orders( $user_id ) as $old ) {
			if ( (string) $old->get_currency() !== $currency ) {
				continue;
			}

			$ref   = (string) $old->get_id();
			$promo = Balance::find( Balance::PROMO_USE, Invoice::REF_TYPE, $ref );
			$paid  = Balance::find( Balance::INVOICE, Invoice::REF_TYPE, $ref );

			$sum['promo'] += $promo && ! Balance::exists( Balance::PROMO_BACK, Invoice::REF_RETURN, $ref ) ? abs( (int) $promo['amount_cents'] ) : 0;
			$sum['paid']  += $paid && ! Balance::exists( Balance::ADJUST, Invoice::REF_RETURN, $ref ) ? abs( (int) $paid['amount_cents'] ) : 0;
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

		$draft = self::draft( $user_id );

		return self::forecast( $user_id, Currency::current(), $total, self::cart_cap( $cart, $draft ), $draft );
	}

	/**
	 * The forecast itself.
	 *
	 * @param int       $user_id  Customer.
	 * @param string    $currency Currency code.
	 * @param int       $total    Cents due.
	 * @param int       $cap      Promotional cap in cents.
	 * @param \WC_Order $draft    Draft order.
	 * @return array{credit: int, promo: int, paid: int, due: int, total: int, currency: string}|null
	 */
	private static function forecast( int $user_id, string $currency, int $total, int $cap, \WC_Order $draft ): ?array {
		// The filter is documented at apply(). On the checkout page the order does not exist yet: it gets an unsaved order that holds the customer and the currency, no lines.
		if ( ! apply_filters( 'beltoft_services_credit_applies_to_order', true, $draft, $user_id ) ) {
			return null;
		}

		$held       = self::held( $user_id, $currency );
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
		if ( ! Config::checkout() ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
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

		$min = (int) round( Minimum::amount( (string) $order->get_currency() ) * 100 );

		if ( Invoice::apply( $order, $user_id, $min, RenewalCredit::promo_cap( $order ) ) > 0 ) {
			$order->update_meta_data( self::META, 'yes' );
			$order->save();
		}
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
	 * Before a new attempt: the customer's unpaid orders give their credit back and are not resumed, so the new order takes the credit
	 * again (see the class comment).
	 *
	 * @param mixed $order_id An order id that another plugin made, normally null.
	 * @return mixed
	 */
	public static function release_awaiting( $order_id ) {
		$user_id = get_current_user_id();

		if ( $order_id || $user_id < 1 || ! Config::enabled() ) {
			return $order_id;
		}

		foreach ( self::unpaid_orders( $user_id ) as $order ) {
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

		if ( $back > 0 ) {
			foreach ( $order->get_items( 'fee' ) as $id => $fee ) {
				if ( 'yes' === $fee->get_meta( Invoice::META_FEE ) ) {
					$order->remove_item( $id );
					$order->set_total( number_format( ( Balance::cents( $order->get_total() ) + $used ) / 100, 2, '.', '' ) );
				}
			}
		}

		self::seal( $order );
	}

	/**
	 * An unpaid order whose credit has gone back: nothing is held, and the next attempt is a new order.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function seal( \WC_Order $order ): void {
		$order->update_meta_data( Invoice::META_USED, '0' );
		$order->update_meta_data( self::META_RELEASED, (string) time() );
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
			self::seal( $order );
		}
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
	 * With credit covering the whole cart and nothing that needs a saved card (a subscription, a pay as you go account), no payment
	 * method is asked for. A subscription still gets one: WooCommerce Subscriptions needs it for the renewals.
	 *
	 * @param mixed $needs Whether the cart needs payment.
	 * @param mixed $cart  Cart.
	 * @return mixed
	 */
	public static function cart_needs_payment( $needs, $cart = null ) {
		if ( ! $needs ) {
			return $needs;
		}

		$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$p    = $cart instanceof \WC_Cart ? self::preview( $cart ) : null;

		if ( null === $p || $p['due'] > 0 ) {
			return $needs;
		}

		if ( class_exists( \WC_Subscriptions_Cart::class ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return $needs;
		}

		if ( UsageCart::usage_units( $cart ) > 0 ) {
			return $needs;
		}

		return false;
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

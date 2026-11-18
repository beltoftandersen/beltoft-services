<?php
/**
 * What the checkout shows of the cards, and the card that is still asked for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\GiftCredit;

use Bgca\Pay;
use Bgca\Wallet;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Card\Card;
use BeltoftServices\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * The add-on puts the cards on the order when the checkout makes it. Before that the customer is shown a forecast by the same rule: a row
 * "Paid from account credit" before the order total, the total after it, and the amount the Stripe gateway gives the browser (the card form and
 * the wallet sheets). The forecast is Bgca\Pay::plan() on the cart's own total, the lines the product box allows, and the minimum rule.
 *
 * A cart that saves a card (a subscription, pay as you go) must still ask the gateway when the cards cover all of it: the card is saved for
 * the renewals. WooCommerce decides "needs payment" from the order total, so after the cards were put on the order of this request the
 * answer is yes ({@see Checkout::gateway_saves_card()}).
 *
 * Exception: a customer who already has a usable default saved card (a token of a gateway that charges subscriptions, active, not expired)
 * is not asked when the cards cover the WHOLE order. The subscriptions of that order are put on the saved card instead
 * ({@see Checkout::saved_card_on_subscriptions()}): WooCommerce Subscriptions makes them at priority 100 of
 * woocommerce_checkout_order_processed, before the add-on pays from the cards (200), so they carry the posted gateway (or manual renewal),
 * and this class writes the card on them at 250, when it is known that the cards paid everything and no gateway is called. A partial
 * payment, or a card that stopped being usable, asks the gateway as before.
 */
final class Checkout {

	/**
	 * Set when the order of this request carries cards and its cart saves a card.
	 *
	 * @var bool
	 */
	private static bool $ask_gateway = false;

	/**
	 * Orders of this request that the cards paid in full and whose subscriptions go on the saved card: order id => token id.
	 *
	 * @var array<int, int>
	 */
	private static array $saved_card_orders = array();

	/**
	 * Orders whose subscriptions now carry the saved card: nothing is left to collect from a gateway (order id => true).
	 *
	 * @var array<int, bool>
	 */
	private static array $settled_orders = array();

	/**
	 * Set when the order of this request exists and the order hooks run: from then on "needs payment" is read from the real order, not
	 * from the forecast.
	 *
	 * @var bool
	 */
	private static bool $processing = false;

	/**
	 * Per request: forecasts by customer, currency, total and cart cap.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $memo = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'bgca_cards_applied', array( __CLASS__, 'applied' ), 10, 3 );
		// After WooCommerce Subscriptions (10), the trial rule (20) and the usage rule (30).
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'gateway_saves_card' ), 40, 2 );
		// First of all: the order exists, the forecast is no longer the answer.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'order_exists' ), 0 );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'saved_method_line' ) );
		// After WooCommerce Subscriptions made the subscriptions (100) and the add-on put the cards on the order (200).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'saved_card_on_subscriptions' ), 250, 3 );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'review_row' ) );
		add_filter( 'woocommerce_cart_totals_order_total_html', array( __CLASS__, 'total_html' ), 10 );
		add_filter( 'wc_stripe_cart_data', array( __CLASS__, 'stripe_cart_data' ), 20, 2 );
	}

	/**
	 * Forgets the forecasts of this request (a test, a change of cards).
	 */
	public static function reset(): void {
		self::$memo              = array();
		self::$ask_gateway       = false;
		self::$saved_card_orders = array();
		self::$settled_orders    = array();
		self::$processing        = false;
	}

	/**
	 * The checkout made the order: "needs payment" is now decided by the order ({@see Checkout::gateway_saves_card()}).
	 */
	public static function order_exists(): void {
		self::$processing = true;
	}

	/**
	 * The add-on put cards on an order.
	 *
	 * @param mixed $order   Order.
	 * @param mixed $cents   Cents.
	 * @param mixed $context 'checkout' or 'renewal'.
	 */
	public static function applied( $order, $cents = 0, $context = '' ): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( 'checkout' === $context && (int) $cents > 0 && $order instanceof \WC_Order ) {
			self::$ask_gateway = Rules::cart_saves_card( $cart );

			// The cards paid it all and the customer has a card that can be charged: nothing to ask, the subscriptions get that card.
			if ( self::$ask_gateway && (int) $order->get_id() > 0 && Wallet::cents( $order->get_total() ) < 1 ) {
				$saved = self::saved_card( (int) $order->get_customer_id() );

				if ( null !== $saved ) {
					self::$ask_gateway                                = false;
					self::$saved_card_orders[ (int) $order->get_id() ] = (int) $saved['token']->get_id();
				}
			}
		}
	}

	/**
	 * The customer's default saved card when it can be charged now: a token of a gateway that supports subscriptions, the gateway is
	 * active, the card is valid and not expired.
	 *
	 * @param int $user_id Customer.
	 * @return array{token: \WC_Payment_Token, gateway: \WC_Payment_Gateway}|null
	 */
	public static function saved_card( int $user_id ): ?array {
		$token = Card::default_token( $user_id );

		if ( null === $token || ! Card::gateway_active( $token->get_gateway_id() ) || ! Card::usable( Card::summarize_token( $token ) ) ) {
			return null;
		}

		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ $token->get_gateway_id() ] ?? null;

		return $gateway instanceof \WC_Payment_Gateway ? array( 'token' => $token, 'gateway' => $gateway ) : null;
	}

	/**
	 * Puts the saved card on the subscriptions of an order the cards paid in full ({@see Checkout::applied()}), so the renewals are charged
	 * by themselves. Only these orders: an order that goes to a gateway keeps the payment method the gateway gave. If the card stopped
	 * being usable since the cards were applied, nothing is written and the gateway is asked (no silent manual renewal).
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $posted   Posted data.
	 * @param mixed $order    Order.
	 */
	public static function saved_card_on_subscriptions( $order_id, $posted = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$order_id = (int) $order_id;

		if ( $order_id < 1 || ! isset( self::$saved_card_orders[ $order_id ] ) || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		$saved = $order instanceof \WC_Order ? self::saved_card( (int) $order->get_customer_id() ) : null;

		if ( null === $saved || (int) $saved['token']->get_id() !== self::$saved_card_orders[ $order_id ] || Wallet::cents( $order->get_total() ) > 0 ) {
			unset( self::$saved_card_orders[ $order_id ], self::$settled_orders[ $order_id ] );
			self::$ask_gateway = true;

			return;
		}

		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
			Card::apply_token( $subscription, $saved['token'], $saved['gateway'] );
		}

		self::$settled_orders[ $order_id ] = true;
	}

	/**
	 * See the class comment. Two phases:
	 *
	 * - Before the order exists (the page, the refresh, the validation of the submit): the forecast. When the cards will pay the whole cart
	 *   and the customer has a usable saved method, no payment is needed, so no payment methods (and no card form) are shown and none is
	 *   required.
	 * - After the order exists (the order hooks of the submit, where WooCommerce decides between payment and no payment): the order
	 *   decides. A cart that saves a card asks the gateway, unless the cards paid the whole order and its subscriptions carry the saved
	 *   card ({@see Checkout::saved_card_on_subscriptions()}). An order with a total above 0 is never answered "no payment".
	 *
	 * @param mixed $needs Whether a payment is needed.
	 * @param mixed $cart  The cart.
	 * @return mixed
	 */
	public static function gateway_saves_card( $needs, $cart = null ) {
		if ( ! self::$processing ) {
			return $needs && null !== self::forecast_saved_card( $cart ) ? false : $needs;
		}

		if ( self::$ask_gateway ) {
			return true;
		}

		return array() !== self::$settled_orders ? false : $needs;
	}

	/**
	 * The saved method that replaces the card form: the checkout is the current cart's, the cards pay all of it and the customer has a
	 * usable saved method.
	 *
	 * @param mixed $cart Cart.
	 * @return array{token: \WC_Payment_Token, gateway: \WC_Payment_Gateway}|null
	 */
	private static function forecast_saved_card( $cart ): ?array {
		if ( ! $cart instanceof \WC_Cart || ! function_exists( 'WC' ) || WC()->cart !== $cart || ! self::on_checkout() || ! Rules::cart_saves_card( $cart ) ) {
			return null;
		}

		$p = self::preview( $cart );

		return null !== $p && $p['due'] < 1 ? self::saved_card( get_current_user_id() ) : null;
	}

	/**
	 * Where the payment methods would be: the line that tells the customer no card is needed.
	 */
	public static function saved_method_line(): void {
		$cart  = function_exists( 'WC' ) ? WC()->cart : null;
		$saved = self::$processing ? null : self::forecast_saved_card( $cart );

		if ( null === $saved ) {
			return;
		}

		$name = trim( wp_strip_all_tags( (string) $saved['token']->get_display_name() ) );

		/* translators: %s: the saved payment method, for example "Visa ending in 4242". */
		echo '<p class="beltoft-services-credit-saved">' . esc_html( sprintf( __( 'Paid from your account credit. Renewals are paid from your account credit first, then from your saved payment method (%s).', 'beltoft-services' ), $name ) ) . '</p>';
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
	 * What the cards pay of the cart: null when nothing is used.
	 *
	 * @param \WC_Cart|null $cart Cart (the current one when null).
	 * @return array{credit: int, free: int, paid: int, due: int, total: int, currency: string}|null
	 */
	public static function preview( ?\WC_Cart $cart = null ): ?array {
		$user_id = get_current_user_id();
		$cart    = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );

		if ( ! GiftCredit::active() || $user_id < 1 || ! $cart instanceof \WC_Cart || $cart->is_empty() || ! self::eligible( $cart ) ) {
			return null;
		}

		$total    = Wallet::cents( $cart->get_total( 'edit' ) );
		$currency = Currency::current();

		if ( $total < 1 ) {
			return null;
		}

		$draft = new \WC_Order();
		$draft->set_currency( $currency );
		$draft->set_customer_id( $user_id );
		$draft->set_created_via( 'checkout' );

		if ( ! apply_filters( 'bgca_auto_apply', true, $draft, $user_id, 'checkout' ) ) {
			return null;
		}

		$cap = Pay::free_cap( self::draft_lines( $cart, $draft ) );
		$key = implode( '|', array( $user_id, $currency, $total, $cap, Wallet::totals( $user_id, $currency )['total'] ) );

		if ( ! array_key_exists( $key, self::$memo ) ) {
			$plan = Pay::plan( Wallet::cards( $user_id, $currency ), $total, $cap, static fn( int $rest ): bool => Rules::rest_allowed( $rest, $currency ) );

			self::$memo[ $key ] = $plan['cents'] < 1 ? null : array(
				'credit'   => $plan['cents'],
				'free'     => $plan['free'],
				'paid'     => $plan['paid'],
				'due'      => $total - $plan['cents'],
				'total'    => $total,
				'currency' => $currency,
			);
		}

		return self::$memo[ $key ];
	}

	/**
	 * May cards pay a cart of this kind? Not a plan switch, a renewal or a top-up.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	private static function eligible( \WC_Cart $cart ): bool {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( CartFlow::has( $item, array( CartFlow::RENEWAL, CartFlow::SWITCH, CartFlow::RENEWAL_EARLY ) ) || \Bgca\TopUp::is_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The cart's lines put on the draft order (not saved), so the add-on's own rule (Pay::free_cap) decides what free cards may pay:
	 * one rule for the checkout forecast and for the order.
	 *
	 * @param \WC_Cart  $cart  Cart.
	 * @param \WC_Order $draft Draft order.
	 */
	private static function draft_lines( \WC_Cart $cart, \WC_Order $draft ): \WC_Order {
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
			$draft->add_item( $item );
		}

		foreach ( $cart->get_fees() as $fee ) {
			$item = new \WC_Order_Item_Fee();
			$item->set_props(
				array(
					'name'      => (string) $fee->name,
					'total'     => (string) $fee->total,
					'total_tax' => (string) $fee->tax,
				)
			);
			$draft->add_item( $item );
		}

		return $draft;
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
	 * The order total of the checkout is what is left to pay after the cards. The tax note after it stays.
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
	 * The cart as the Stripe gateway gives it to the browser carries the cards: the amount of the card form and the wallet sheets is the
	 * total after them.
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

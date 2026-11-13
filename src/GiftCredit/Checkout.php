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
 */
final class Checkout {

	/**
	 * Set when the order of this request carries cards and its cart saves a card.
	 *
	 * @var bool
	 */
	private static bool $ask_gateway = false;

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
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'gateway_saves_card' ), 40 );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'review_row' ) );
		add_filter( 'woocommerce_cart_totals_order_total_html', array( __CLASS__, 'total_html' ), 10 );
		add_filter( 'wc_stripe_cart_data', array( __CLASS__, 'stripe_cart_data' ), 20, 2 );
	}

	/**
	 * Forgets the forecasts of this request (a test, a change of cards).
	 */
	public static function reset(): void {
		self::$memo        = array();
		self::$ask_gateway = false;
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
		}
	}

	/**
	 * See the class comment.
	 *
	 * @param mixed $needs Whether a payment is needed.
	 * @return mixed
	 */
	public static function gateway_saves_card( $needs ) {
		return self::$ask_gateway ? true : $needs;
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

<?php
/**
 * When the cards pay, and in which currency the account is.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\GiftCredit;

use Bgca\Wallet;
use BeltoftServices\Card\Card;
use BeltoftServices\Currency;
use BeltoftServices\Usage\Cart as UsageCart;
use BeltoftServices\Usage\Minimum;

defined( 'ABSPATH' ) || exit;

/**
 * The answers this plugin gives to the add-on's filters.
 *
 * - Checkout (the order a customer places): not a plan switch, not a renewal; and a card must be there: a cart that saves a card by itself
 *   (a subscription, or pay as you go services) is fine, any other cart only when the customer already has a usable saved card.
 * - Renewals (the renewal orders of subscriptions, which includes the usage invoices): always, except the early renewal of the modal,
 *   which charges the card at once and deletes its order when that fails.
 * - The minimum: a rest for the card above 0 and under the usage minimum (including VAT) means the cards are not used on that order.
 * - The currency of the account: the customer's currency by the chain of the filter beltoft_services_user_currency (their usage account,
 *   a currency bridge), and on a page of a currency when nobody has fixed one; the currency of their cards is the answer when nothing
 *   else is.
 */
final class Rules {

	/**
	 * Does the add-on use the cards on this order?
	 *
	 * @param mixed $apply   Answer so far.
	 * @param mixed $order   The order.
	 * @param mixed $user_id Customer.
	 * @param mixed $context 'checkout' or 'renewal'.
	 * @return mixed
	 */
	public static function auto_apply( $apply, $order = null, $user_id = 0, $context = '' ) {
		if ( ! $apply || ! GiftCredit::active() || ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( 'renewal' === $context ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads whether WooCommerce Subscriptions' own request is running.
			return ! isset( $_GET['process_early_renewal'] );
		}

		return self::checkout_order( $order, (int) $user_id );
	}

	/**
	 * May the cards pay the order a checkout makes (or would make: the draft of the forecast)?
	 *
	 * @param \WC_Order     $order   Order.
	 * @param int           $user_id Customer.
	 * @param \WC_Cart|null $cart    The cart (the current one when null).
	 */
	public static function checkout_order( \WC_Order $order, int $user_id, ?\WC_Cart $cart = null ): bool {
		if ( 'checkout' !== $order->get_created_via() ) {
			return false;
		}

		foreach ( array( 'wcs_order_contains_renewal', 'wcs_order_contains_switch' ) as $fn ) {
			if ( function_exists( $fn ) && $fn( $order ) ) {
				return false;
			}
		}

		return self::card_rule_met( $user_id, $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null ) );
	}

	/**
	 * Does the cart save a card by itself (a subscription, or pay as you go services)? Then the gateway saves the card at checkout, also
	 * when the cards cover the whole cart and the total is 0.
	 *
	 * @param \WC_Cart|null $cart Cart.
	 */
	public static function cart_saves_card( ?\WC_Cart $cart ): bool {
		return $cart instanceof \WC_Cart && ! $cart->is_empty() && ( ( class_exists( \WC_Subscriptions_Cart::class ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) || UsageCart::usage_units( $cart ) > 0 );
	}

	/**
	 * A card always needs to be there to use credit: a cart that saves a card, or a customer with a usable saved card (the default saved
	 * method, one WooCommerce Subscriptions can charge, not expired).
	 *
	 * @param int           $user_id Customer.
	 * @param \WC_Cart|null $cart    Cart.
	 */
	public static function card_rule_met( int $user_id, ?\WC_Cart $cart ): bool {
		if ( self::cart_saves_card( $cart ) ) {
			return true;
		}

		$token = Card::default_token( $user_id );

		return null !== $token && Card::usable( Card::summarize_token( $token ) );
	}

	/**
	 * The minimum rule for the add-on's question "may the cards pay part of this order".
	 *
	 * @param mixed $allowed Answer so far.
	 * @param mixed $rest    Cents left to pay after the cards.
	 * @param mixed $due     Cents due before the cards.
	 * @param mixed $order   The order.
	 * @return mixed
	 */
	public static function allow_partial_use( $allowed, $rest = 0, $due = 0, $order = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		$currency = $order instanceof \WC_Order ? (string) $order->get_currency() : '';

		return $allowed && self::rest_allowed( (int) $rest, $currency );
	}

	/**
	 * Is a rest for the card allowed: 0, or at least the usage minimum (incl. VAT) of the currency?
	 *
	 * @param int    $rest     Cents.
	 * @param string $currency Currency code, '' for the current one.
	 */
	public static function rest_allowed( int $rest, string $currency = '' ): bool {
		$min = (int) round( Minimum::amount( $currency ) * 100 );

		return $rest < 1 || $min < 1 || $rest >= $min;
	}

	/**
	 * The currency of the account for the add-on.
	 *
	 * @param mixed $currency Default (the shop's).
	 * @param mixed $user_id  Customer.
	 * @return mixed
	 */
	public static function account_currency( $currency, $user_id = 0 ) {
		return GiftCredit::active() && (int) $user_id > 0 ? Currency::of_user_or_current( (int) $user_id ) : $currency;
	}

	/**
	 * The customer's currency when nobody earlier answered: the currency of their cards.
	 *
	 * @param mixed $code    Answer so far.
	 * @param mixed $user_id Customer.
	 * @return mixed
	 */
	public static function user_currency( $code, $user_id = 0 ) {
		if ( ( is_string( $code ) && '' !== $code ) || ! GiftCredit::active() || (int) $user_id < 1 ) {
			return $code;
		}

		$own = Wallet::currency( (int) $user_id );

		return '' !== $own ? $own : $code;
	}

	/**
	 * What the cards add to the credit a usage invoice would use (the minimum rule of the usage invoice asks).
	 *
	 * @param mixed $cents    Cents so far.
	 * @param mixed $user_id  Customer.
	 * @param mixed $currency Currency code.
	 * @return mixed
	 */
	public static function usage_credit_cents( $cents, $user_id = 0, $currency = '' ) {
		return (int) $cents + GiftCredit::balance( (int) $user_id, '' !== (string) $currency ? (string) $currency : Currency::current() );
	}
}

<?php
/**
 * A saved card cannot be deleted while it is still needed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Card;

use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Minimum;

defined( 'ABSPATH' ) || exit;

/**
 * The one rule: needed( $user_id, $token ) gives the reason a card must stay, or null when it may go.
 *
 * A card is needed when the customer has no other usable saved card AND one of these holds: an unpaid order
 * with a total above 0; an active subscription with a price above 0 that pays with this token (WooCommerce
 * Subscriptions asks for another card of the same payment method then); the pay as you go account has a running
 * service, or unbilled usage that reaches the usage minimum. Deleting one of two cards stays allowed.
 *
 * The rule is used in three places: the Delete button (replaced by "In use" and the reason), WooCommerce Subscriptions'
 * own question (`wc_subscriptions_allow_subscription_token_deletion`), and the request itself, which is refused
 * before WooCommerce's delete handler (the `wp` action at priority 20) runs. The plugin has no REST or MCP route that
 * deletes a card (MCP only sets the default), and no hook runs before a token is deleted by code.
 */
final class DeleteGuard {

	/**
	 * Anchor of the reason under the table of saved methods.
	 */
	public const ANCHOR = 'beltoft-services-card-needed';

	/**
	 * The reasons shown for the cards of this request.
	 *
	 * @var string[]
	 */
	private static $shown = array();

	/**
	 * Hooks. The list item filter runs after WooCommerce Subscriptions' own (priority 10).
	 */
	public static function register(): void {
		add_filter( 'woocommerce_payment_methods_list_item', array( __CLASS__, 'list_item' ), 30, 2 );
		add_filter( 'wc_subscriptions_allow_subscription_token_deletion', array( __CLASS__, 'allow_deletion' ), 20, 2 );
		add_action( 'woocommerce_after_account_payment_methods', array( __CLASS__, 'print_reason' ) );
		add_action( 'wp', array( __CLASS__, 'intercept' ), 10 );
	}

	/**
	 * The reason this card must stay, in words, or null when the customer may delete it.
	 *
	 * @param int               $user_id Customer.
	 * @param \WC_Payment_Token $token   The saved card.
	 */
	public static function needed( int $user_id, \WC_Payment_Token $token ): ?string {
		if ( $user_id < 1 || (int) $token->get_user_id() !== $user_id ) {
			return null;
		}

		$any    = self::has_other( $user_id, $token, false );
		$same   = self::has_other( $user_id, $token, true );
		$reason = null;

		if ( ! $any && self::unpaid_order( $user_id ) ) {
			$reason = __( 'This card is needed to pay an unpaid order. Pay or cancel the order, or add another card first, then you can delete this one.', 'beltoft-services' );
		} elseif ( ! $same && self::pays_subscription( $token ) ) {
			$reason = __( 'This card pays for your active services. Add another card first, then you can delete this one.', 'beltoft-services' );
		} elseif ( ! $any && self::usage_running( $user_id ) ) {
			$reason = __( 'This card pays for your pay as you go services. Add another card first, then you can delete this one.', 'beltoft-services' );
		}

		/**
		 * The reason a saved card cannot be deleted. Return null to allow the deletion.
		 *
		 * @param string|null       $reason  Reason, null when the card may be deleted.
		 * @param int               $user_id Customer.
		 * @param \WC_Payment_Token $token   The card.
		 */
		$reason = apply_filters( 'beltoft_services_card_delete_needed', $reason, $user_id, $token );

		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * Has the customer another saved card that can be charged and has not expired?
	 *
	 * @param int               $user_id      Customer.
	 * @param \WC_Payment_Token $token        The card that would be deleted.
	 * @param bool              $same_gateway Only cards of the same payment method (what a subscription can move to).
	 */
	private static function has_other( int $user_id, \WC_Payment_Token $token, bool $same_gateway ): bool {
		if ( ! class_exists( \WC_Payment_Tokens::class ) ) {
			return false;
		}

		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id ) as $other ) {
			if ( $other->get_id() === $token->get_id() || ( $same_gateway && $other->get_gateway_id() !== $token->get_gateway_id() ) ) {
				continue;
			}

			if ( Card::eligible( $other ) && Card::usable( Card::summarize_token( $other ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Has the customer an order that waits for payment, with a total above 0?
	 *
	 * @param int $user_id Customer.
	 */
	private static function unpaid_order( int $user_id ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		foreach ( wc_get_orders( array( 'customer_id' => $user_id, 'status' => array( 'pending', 'failed' ), 'type' => 'shop_order', 'limit' => 50 ) ) as $order ) {
			if ( $order instanceof \WC_Order && (float) $order->get_total() > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does an active or on-hold subscription with a price pay with this card? The pay as you go account is not counted here:
	 * its price is 0 and the usage rule covers it.
	 *
	 * @param \WC_Payment_Token $token The card.
	 */
	private static function pays_subscription( \WC_Payment_Token $token ): bool {
		if ( ! class_exists( \WCS_Payment_Tokens::class ) ) {
			return false;
		}

		foreach ( \WCS_Payment_Tokens::get_subscriptions_from_token( $token ) as $subscription ) {
			if ( $subscription instanceof \WC_Subscription && $subscription->has_status( array( 'active', 'on-hold' ) ) && ! Account::is_account( $subscription ) && (float) $subscription->get_total() > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does the pay as you go account have a running service, or unbilled usage that reaches the usage minimum (incl. VAT)?
	 *
	 * @param int $user_id Customer.
	 */
	private static function usage_running( int $user_id ): bool {
		if ( array() !== Account::services( $user_id ) ) {
			return true;
		}

		$estimate = Estimate::for_user( $user_id );

		if ( ! $estimate ) {
			return false;
		}

		$cents = Estimate::invoice_cents( $estimate );

		return $cents > 0 && $cents >= Minimum::cents( Minimum::amount( (string) $estimate['currency'] ) );
	}

	/**
	 * Replaces the Delete button with "In use" and remembers the reason for the line under the table.
	 *
	 * @param mixed $item  The list item.
	 * @param mixed $token The token.
	 * @return mixed
	 */
	public static function list_item( $item, $token = null ) {
		if ( ! is_array( $item ) || ! $token instanceof \WC_Payment_Token ) {
			return $item;
		}

		$reason = self::needed( (int) $token->get_user_id(), $token );

		if ( null === $reason ) {
			return $item;
		}

		unset( $item['actions']['delete'], $item['actions']['wcs_deletion_error'] );
		$item['actions']['beltoft_services_card_needed'] = array(
			'url'  => '#' . self::ANCHOR,
			'name' => __( 'In use', 'beltoft-services' ),
		);
		self::$shown[ $reason ]                          = $reason;

		return $item;
	}

	/**
	 * WooCommerce Subscriptions asks whether a card with no other card of its method may be deleted: not when it is needed.
	 *
	 * @param mixed $allow Answer so far.
	 * @param mixed $token The token.
	 * @return mixed
	 */
	public static function allow_deletion( $allow, $token = null ) {
		return $allow && $token instanceof \WC_Payment_Token && null !== self::needed( (int) $token->get_user_id(), $token ) ? false : $allow;
	}

	/**
	 * The reason, in words, under the table.
	 */
	public static function print_reason(): void {
		$first = true;

		foreach ( self::$shown as $reason ) {
			echo '<p' . ( $first ? ' id="' . esc_attr( self::ANCHOR ) . '"' : '' ) . ' class="beltoft-services-card__needed">' . esc_html( $reason ) . '</p>';
			$first = false;
		}

		self::$shown = array();
	}

	/**
	 * A request to delete a card that is needed: refused with the reason, before WooCommerce's handler (priority 20) deletes
	 * it (and the payment provider's own listener removes it there).
	 */
	public static function intercept(): void {
		global $wp;

		if ( ! isset( $wp->query_vars['delete-payment-method'] ) || ! class_exists( \WC_Payment_Tokens::class ) ) {
			return;
		}

		$token = \WC_Payment_Tokens::get( absint( $wp->query_vars['delete-payment-method'] ) );
		$user  = get_current_user_id();

		if ( ! $token instanceof \WC_Payment_Token || $user < 1 || (int) $token->get_user_id() !== $user ) {
			return; // WooCommerce answers "Invalid payment method".
		}

		$reason = self::needed( $user, $token );

		if ( null === $reason ) {
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $reason, 'error' );
		}

		wp_safe_redirect( Card::url() );
		exit;
	}
}

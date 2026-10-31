<?php
/**
 * Saved payment cards, as the account pages and the MCP tools describe them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Gateways bring their own token classes (the Stripe plugin's does not extend WooCommerce's card token), so a card is
 * recognised by its methods, not its class. Only the brand, the last four digits and the expiry are ever read.
 */
final class Cards {

	/**
	 * Whether a token is a card with its last four digits.
	 *
	 * @param mixed $token A payment token or false.
	 */
	public static function is_card( $token ): bool {
		return $token instanceof \WC_Payment_Token && method_exists( $token, 'get_last4' ) && method_exists( $token, 'get_card_type' ) && '' !== (string) $token->get_last4();
	}

	/**
	 * "Visa ending 4242" for a card token.
	 *
	 * @param \WC_Payment_Token $token A card token (see is_card()).
	 */
	public static function label( $token ): string {
		$type = (string) $token->get_card_type();

		/* translators: 1: card brand, 2: last four digits */
		return sprintf( __( '%1$s ending %2$s', 'beltoft-services' ), function_exists( 'wc_get_credit_card_type_label' ) ? wc_get_credit_card_type_label( $type ) : ucfirst( $type ), $token->get_last4() );
	}

	/**
	 * Does the customer have a default saved payment method that can be charged: it exists, is theirs and, if it is a
	 * card, has not expired?
	 *
	 * @param int $user_id Customer.
	 */
	public static function has_valid_default( int $user_id ): bool {
		$token = $user_id > 0 && class_exists( '\WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get_customer_default_token( $user_id ) : null;

		if ( ! $token instanceof \WC_Payment_Token || (int) $token->get_user_id() !== $user_id ) {
			return false;
		}

		if ( self::is_card( $token ) && method_exists( $token, 'get_expiry_month' ) && method_exists( $token, 'get_expiry_year' ) ) {
			return (int) $token->get_expiry_year() * 100 + (int) $token->get_expiry_month() >= (int) gmdate( 'Ym' );
		}

		return true;
	}
}

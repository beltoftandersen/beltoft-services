<?php
/**
 * Choosing the default saved payment method.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

use BeltoftServices\Account\Cards;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's own page sets any saved method of the customer as the default. The rules here are stricter and are
 * the only ones a connected app uses: it must be the customer's, not the default already, and not an expired card.
 */
final class PaymentMethods {

	/**
	 * The saved method a customer may make the default.
	 *
	 * @param int $user_id  Customer.
	 * @param int $token_id Payment method id.
	 * @return \WC_Payment_Token|\WP_Error
	 */
	public static function usable( int $user_id, int $token_id ) {
		$token = class_exists( '\WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( $token_id ) : null;

		if ( ! $token instanceof \WC_Payment_Token || $user_id <= 0 || (int) $token->get_user_id() !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This payment method is not available.', 'beltoft-services' ) );
		}

		if ( $token->is_default() ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This is the default payment method already.', 'beltoft-services' ) );
		}

		if ( Cards::is_card( $token ) && method_exists( $token, 'get_expiry_year' ) && (int) $token->get_expiry_year() * 100 + (int) $token->get_expiry_month() < (int) gmdate( 'Ym' ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This card has expired. Choose another.', 'beltoft-services' ) );
		}

		return $token;
	}

	/**
	 * The customer's name for a method: "Visa ending 4242", or its type.
	 *
	 * @param \WC_Payment_Token $token Method.
	 */
	public static function label( \WC_Payment_Token $token ): string {
		return Cards::is_card( $token ) ? Cards::label( $token ) : (string) $token->get_type();
	}

	/**
	 * Makes a method the default after usable() said yes.
	 *
	 * @param int $user_id  Customer.
	 * @param int $token_id Payment method id.
	 */
	public static function make_default( int $user_id, int $token_id ): void {
		\WC_Payment_Tokens::set_users_default( $user_id, $token_id );
	}
}

<?php
/**
 * What the server picker and the database picker share when they put a new service in the cart: the post guard (nonce
 * and rate limit), the rule that a payment for a subscription that exists is never mixed with a new service, and the
 * text of a refusal by the shop.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Billing\CartFlow;
use BeltoftServices\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * The shared parts of a picker's process() and add_to_cart().
 */
final class PickerCart {

	/**
	 * The nonce and the rate limit of a picker post.
	 *
	 * @param array<string, mixed> $post  Raw post.
	 * @param int                  $user  Customer.
	 * @param string               $field Name of the nonce field.
	 * @param string               $nonce Nonce action.
	 * @return string|null The refusal text for the form, or null when the post may go on.
	 */
	public static function guard( array $post, int $user, string $field, string $nonce ): ?string {
		if ( $user <= 0 || false === wp_verify_nonce( isset( $post[ $field ] ) && is_string( $post[ $field ] ) ? $post[ $field ] : '', $nonce ) ) {
			return __( 'Your session has expired. Reload the page and try again.', 'beltoft-services' );
		}

		/**
		 * Most posts of the picker a customer can make in an hour (0 turns the limit off).
		 *
		 * @param int $limit Default 20.
		 */
		if ( RateLimit::exceeded( $user, 'beltoft-services:picker', (int) apply_filters( 'beltoft_services_picker_rate_limit', 20 ), HOUR_IN_SECONDS ) ) {
			return __( 'Too many tries. Please wait a while and try again.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * The keys of the cart lines an earlier try left, or null when the cart holds a payment for a subscription that
	 * exists (renewal, switch, resubscribe): that is never touched or mixed with a new service.
	 *
	 * @param \WC_Cart $cart    The cart.
	 * @param callable $is_old  Gets a cart line (array), says whether it is an earlier try's line.
	 * @return array<int, string>|null
	 */
	public static function old_lines( $cart, callable $is_old ): ?array {
		$old = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( CartFlow::has_value( (array) $item, CartFlow::ALL ) ) {
				return null;
			}

			if ( $is_old( (array) $item ) ) {
				$old[] = (string) $key;
			}
		}

		return $old;
	}

	/**
	 * The refusal text when a cart holds a subscription payment.
	 */
	public static function payment_in_cart(): string {
		return __( 'Finish or remove the subscription payment in your cart first.', 'beltoft-services' );
	}

	/**
	 * The shop's refusals of an add-to-cart as one sentence ('' when it said nothing). The notices are cleared.
	 */
	public static function shop_refusal(): string {
		$notes = array();

		foreach ( (array) wc_get_notices( 'error' ) as $notice ) {
			$text = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;

			if ( '' !== $text ) {
				$notes[] = wp_strip_all_tags( $text );
			}
		}

		wc_clear_notices();

		return implode( ' ', $notes );
	}
}

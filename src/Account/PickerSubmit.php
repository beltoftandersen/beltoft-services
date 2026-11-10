<?php
/**
 * The early post handling that the customer's pickers share.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * For a picker class with on_page(), url(), process() and a FIELD constant: sends a visitor who is not logged in to the
 * login, and a checked post on to checkout.
 */
trait PickerSubmit {

	/**
	 * Early on the page: a visitor who is not logged in goes to the login and comes back here; a post is checked and,
	 * when it holds, the cart is filled and the customer goes to checkout. A refused post prints the page again.
	 */
	public static function submit(): void {
		if ( ! self::on_page() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( self::url() ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in process().
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in process() and the choices are checked against what is offered.
		$result = self::process( wp_unslash( $_POST ), get_current_user_id() );

		if ( '' !== $result['redirect'] ) {
			wp_safe_redirect( $result['redirect'] );
			exit;
		}
	}
}

<?php
/**
 * What a post of My Account did, told once in the status bar.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Status;

defined( 'ABSPATH' ) || exit;

/**
 * A form of the account area that posts and redirects leaves its answer as a WooCommerce notice. A theme or a Bricks
 * account page may print it at the top, or not at all. Whatever is still waiting at the end of an account page is handed
 * to the status bar (assets/status.js reads the hidden [data-bs-flash] lines), so an error or a confirmation is shown in
 * one place, once, and a notice that was already printed is not shown again.
 */
final class Flash {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'wp_footer', array( __CLASS__, 'print' ), 5 );
	}

	/**
	 * Prints the waiting notices as flash lines and clears them.
	 */
	public static function print(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() || ! function_exists( 'wc_get_notices' ) || ! wp_script_is( Status::HANDLE, 'enqueued' ) ) {
			return;
		}

		$notices = wc_get_notices();

		if ( ! $notices ) {
			return;
		}

		foreach ( $notices as $kind => $list ) {
			foreach ( (array) $list as $notice ) {
				$text = trim( wp_strip_all_tags( is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice ) );

				if ( '' !== $text ) {
					printf( '<p hidden data-bs-flash="%1$s">%2$s</p>', 'error' === $kind ? 'fail' : 'done', esc_html( $text ) );
				}
			}
		}

		wc_clear_notices();
	}
}

<?php
/**
 * The shared feedback script of My Account and the checkout fields: status bar, action lock, styled question.
 *
 * Scripts that report to the customer depend on the handle, so it is registered at once; it is printed on the
 * account pages, the cart and the checkout (where the add-on fields ask questions).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Registers assets/status.js (window.beltoftStatus).
 */
final class Status {

	public const HANDLE = 'beltoft-services-status';

	/** The shared REST and copy helper (assets/api.js, window.beltoftApi). */
	public const API_HANDLE = 'beltoft-services-api';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 1 );
	}

	/**
	 * Registers the script, and prints it where customers see feedback.
	 */
	public static function assets(): void {
		wp_register_script( self::HANDLE, plugins_url( 'assets/status.js', BELTOFT_SERVICES_FILE ), array(), Assets::version( 'assets/status.js' ), true );
		wp_register_script( self::API_HANDLE, plugins_url( 'assets/api.js', BELTOFT_SERVICES_FILE ), array(), Assets::version( 'assets/api.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftStatusConfig', array( 'strings' => self::strings() ) );

		$account  = function_exists( 'is_account_page' ) && is_account_page();
		$checkout = ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() );

		if ( $account || $checkout ) {
			if ( apply_filters( 'beltoft_services_account_css', true ) ) {
				wp_enqueue_style( 'beltoft-services-account', plugins_url( 'assets/account.css', BELTOFT_SERVICES_FILE ), array(), Assets::version( 'assets/account.css' ) );
			}
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * The words the script itself uses.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'close'        => __( 'Close', 'beltoft-services' ),
			'failedPrefix' => __( 'Failed: ', 'beltoft-services' ),
			/* translators: %s: what is going on, such as "rebooting your server" */
			'wait'         => __( 'Wait for %s to finish', 'beltoft-services' ),
			'ok'           => __( 'OK', 'beltoft-services' ),
			'cancel'       => __( 'Cancel', 'beltoft-services' ),
			'working'      => __( 'Working…', 'beltoft-services' ),
			'busyState'    => __( 'Busy', 'beltoft-services' ),
		);
	}
}

<?php
/**
 * A tick box on the classic checkout that adds or removes one add-on line through a wc-ajax call.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * The markup and the one script shared by the add-on boxes of the checkout (managed server, priority support). Each box
 * names its own wc-ajax endpoint and nonce in data attributes; the script posts `on` (1 or 0) and then refreshes the order
 * review. The feature that owns the box decides what the cart holds.
 */
final class CheckBox {

	public const HANDLE = 'beltoft-services-check-box';

	/**
	 * The box.
	 *
	 * @param string $id          Element id.
	 * @param string $ajax        wc-ajax action of the owner.
	 * @param string $nonce       Nonce action of the owner.
	 * @param bool   $checked     Is the line in the cart.
	 * @param string $label       Label, with the price.
	 * @param string $description What it includes.
	 */
	public static function html( string $id, string $ajax, string $nonce, bool $checked, string $label, string $description ): string {
		return sprintf(
			'<div class="beltoft-services-check-box"><p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox beltoft-services-check-box__input" id="%1$s" data-url="%2$s" data-nonce="%3$s" %4$s> <span>%5$s</span></label></p><p class="description">%6$s</p></div>',
			esc_attr( $id ),
			esc_url( \WC_AJAX::get_endpoint( $ajax ) ),
			esc_attr( wp_create_nonce( $nonce ) ),
			checked( $checked, true, false ),
			esc_html( $label ),
			esc_html( $description )
		);
	}

	/**
	 * The script, once, on the checkout page.
	 */
	public static function enqueue(): void {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/check-box.js', BELTOFT_SERVICES_FILE ), array( 'jquery', 'beltoft-services-status' ), \BeltoftServices\Assets::version( 'assets/check-box.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesCheckBox', array( 'failed' => __( 'The order could not be changed. Reload the page and try again.', 'beltoft-services' ) ) );
	}

	/**
	 * Is this a checkout page where a box may show?
	 */
	public static function on_checkout(): bool {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();
	}

	/**
	 * The wc-ajax answer: nonce checked, then the owner's change with the posted `on`.
	 *
	 * @param string   $nonce  Nonce action.
	 * @param callable $choose Given bool on: array{ok: bool, on: bool}.
	 * @return array{0: array<string, mixed>, 1: int}
	 */
	public static function answer( string $nonce, callable $choose ): array {
		if ( false === check_ajax_referer( $nonce, 'nonce', false ) ) {
			return array( array( 'ok' => false ), 403 );
		}

		$on     = ! empty( $_POST['on'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$result = $choose( $on );

		return array( $result, $result['ok'] ? 200 : 409 );
	}
}

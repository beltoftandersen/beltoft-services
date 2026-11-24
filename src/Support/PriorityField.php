<?php
/**
 * The "Priority support" box of the classic checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Support;

use BeltoftServices\Checkout\CheckBox;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * One box, shown when the cart holds a new service and the customer does not have priority support (a visitor may tick it;
 * the account is made at checkout). Ticked, the cart holds one line of the priority support product.
 */
final class PriorityField {

	public const AJAX  = 'beltoft_services_priority_field';
	public const NONCE = 'beltoft-services-priority-field';

	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ) );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Is there a box: the product is sold, the customer has none, and the cart holds another service. */
	public static function offered(): bool {
		return null !== Priority::product() && ! Priority::has( get_current_user_id() ) && self::other_service();
	}

	/** Does the cart hold a service other than priority support? */
	private static function other_service(): bool {
		foreach ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

			if ( $driver && PriorityDriver::ID !== $driver->id() ) {
				return true;
			}
		}

		return false;
	}

	/** Cart keys of the priority support lines. */
	private static function keys(): array {
		$keys = array();

		foreach ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array() as $key => $item ) {
			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

			if ( $driver && PriorityDriver::ID === $driver->id() ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	public static function render(): void {
		if ( ! self::offered() ) {
			return;
		}

		$product = Priority::product();
		$price   = wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) );

		echo CheckBox::html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in CheckBox::html().
			'beltoft_services_priority',
			self::AJAX,
			self::NONCE,
			array() !== self::keys(),
			/* translators: %s: the monthly price, such as 49 kr. */
			sprintf( __( 'Priority support, %s a month', 'beltoft-services' ), $price ),
			__( 'An answer within 4 hours, from 8 to 22 every day, weekends included. Covers every service on your account.', 'beltoft-services' )
		);
	}

	public static function assets(): void {
		if ( CheckBox::on_checkout() && self::offered() ) {
			CheckBox::enqueue();
		}
	}

	public static function ajax(): void {
		$answer = CheckBox::answer( self::NONCE, array( __CLASS__, 'choose' ) );

		wp_send_json( $answer[0], $answer[1] );
	}

	/**
	 * Makes the cart hold one line (on) or none (off).
	 *
	 * @param bool $on Wanted.
	 * @return array{ok: bool, on: bool}
	 */
	public static function choose( bool $on ): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || ( $on && ! self::offered() ) ) {
			return array( 'ok' => false, 'on' => array() !== self::keys() );
		}

		$keys = self::keys();

		foreach ( $on ? array_slice( $keys, 1 ) : $keys as $key ) {
			$cart->remove_cart_item( $key );
		}

		if ( $on && array() === self::keys() && ! $cart->add_to_cart( Priority::product()->get_id() ) ) {
			return array( 'ok' => false, 'on' => false );
		}

		return array( 'ok' => true, 'on' => array() !== self::keys() );
	}
}

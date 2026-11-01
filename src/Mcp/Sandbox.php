<?php
/**
 * Runs WooCommerce's own cart and checkout for a customer without touching the customer's real cart, session or
 * saved cart, and without a redirect or an exit.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * An order made through Claude must obey every rule the dashboard's checkout obeys (stock, billing details, the usage
 * rules, the shop's own validators, the line item meta every field saver writes), and those rules live in hooks that
 * need a cart and a posted form. So the order is made by WooCommerce's own checkout, not by hand. What this class adds
 * is the isolation:
 *
 * - The session is replaced by one that lives in memory, the customer object by one that is not tied to a session, and
 *   the cart is emptied and put back, so what the customer has in a browser cart is never read, changed or saved.
 *   The saved cart in the user's meta is left alone too (the filter woocommerce_persistent_cart_enabled).
 * - The posted form and the request are the ones we prepared; the originals come back afterwards.
 * - WooCommerce ends a checkout with wp_send_json() or a redirect and exit. The request is made an AJAX one, and the
 *   handler of wp_die() for AJAX throws, so the end of the checkout is a catchable exception and the JSON it printed is
 *   caught in an output buffer. Nothing is printed and nothing exits.
 *
 * Nothing here decides anything about money: it only runs the shop's own code in a box.
 */
final class Sandbox {

	/**
	 * Runs a callback in the box.
	 *
	 * @param int                  $user_id Customer (the current user must be this customer).
	 * @param array<string, mixed> $post    What the checkout form posts.
	 * @param callable             $fn      Receives the WC_Cart; returns whatever the caller wants back.
	 * @return mixed What the callback returned.
	 * @throws \RuntimeException When the cart cannot be loaded.
	 */
	public static function run( int $user_id, array $post, callable $fn ) {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) || get_current_user_id() !== $user_id || $user_id < 1 ) {
			throw new \RuntimeException( 'The cart is not available.' );
		}

		if ( ! WC()->cart && ! WC()->session ) {
			wc_load_cart();
		} elseif ( ! WC()->cart ) {
			WC()->initialize_cart();
		}

		$wc = WC();

		if ( ! $wc->cart instanceof \WC_Cart ) {
			throw new \RuntimeException( 'The cart is not available.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- the request is only kept and put back, never read for a value.
		$saved = array(
			'session'  => $wc->session,
			'customer' => $wc->customer,
			'contents' => $wc->cart->get_cart_contents(),
			'coupons'  => $wc->cart->get_applied_coupons(),
			'removed'  => $wc->cart->get_removed_cart_contents(),
			'post'     => $_POST,
			'request'  => $_REQUEST,
			'get'      => $_GET,
			'method'   => $_SERVER['REQUEST_METHOD'] ?? null,
		);
		// phpcs:enable

		$no_persist = '__return_false';
		$ajax       = '__return_true';
		$die        = static function () {
			return static function ( $message = '', $title = '', $args = array() ) {
				unset( $message, $title, $args );

				throw new SandboxEnd();
			};
		};

		add_filter( 'woocommerce_persistent_cart_enabled', $no_persist, 9999 );
		add_filter( 'wp_doing_ajax', $ajax, 9999 );
		add_filter( 'wp_die_ajax_handler', $die, 9999 );

		try {
			$wc->session  = new MemorySession();
			$wc->customer = new \WC_Customer( $user_id, false );
			$wc->cart->empty_cart( false );
			wc_clear_notices();

			$_POST                  = $post;
			$_REQUEST               = $post;
			$_GET                   = array();
			$_SERVER['REQUEST_METHOD'] = 'POST';

			return $fn( $wc->cart );
		} finally {
			remove_filter( 'woocommerce_persistent_cart_enabled', $no_persist, 9999 );
			remove_filter( 'wp_doing_ajax', $ajax, 9999 );
			remove_filter( 'wp_die_ajax_handler', $die, 9999 );

			$_POST    = $saved['post'];
			$_REQUEST = $saved['request'];
			$_GET     = $saved['get'];

			if ( null === $saved['method'] ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $saved['method'];
			}

			$wc->cart->empty_cart( false );
			$wc->cart->set_cart_contents( $saved['contents'] );
			$wc->cart->set_applied_coupons( $saved['coupons'] );
			$wc->cart->set_removed_cart_contents( $saved['removed'] );
			$wc->session  = $saved['session'];
			$wc->customer = $saved['customer'];
		}
	}

	/**
	 * Runs WooCommerce's checkout with the form that was posted and says how it ended.
	 *
	 * @return array{result: string, order_id: int, messages: string[]} result is success, failure or none (the checkout ended without saying).
	 */
	public static function checkout(): array {
		$order_id = 0;
		$capture  = static function ( $id ) use ( &$order_id ) {
			$order_id = (int) $id;
		};
		$json     = '';

		add_action( 'woocommerce_checkout_order_processed', $capture, 9999 );
		ob_start();

		try {
			WC()->checkout()->process_checkout();
		} catch ( SandboxEnd $end ) {
			unset( $end );
		} finally {
			$json = (string) ob_get_clean();
			remove_action( 'woocommerce_checkout_order_processed', $capture, 9999 );
		}

		$data = json_decode( $json, true );
		$data = is_array( $data ) ? $data : array();

		$messages = array();

		if ( ! empty( $data['messages'] ) && is_string( $data['messages'] ) ) {
			$text = preg_replace( '#</li>#i', "\n", $data['messages'] );
			$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );

			foreach ( preg_split( '/\R/', (string) $text ) ?: array() as $line ) {
				$line = trim( $line );

				if ( '' !== $line ) {
					$messages[] = $line;
				}
			}
		}

		wc_clear_notices();

		return array(
			'result'   => isset( $data['result'] ) && is_string( $data['result'] ) ? $data['result'] : 'none',
			'order_id' => $order_id,
			'messages' => $messages,
		);
	}
}

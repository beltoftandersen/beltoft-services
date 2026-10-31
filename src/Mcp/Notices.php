<?php
/**
 * Runs code that talks to the customer through WooCommerce notices, and keeps what it said.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce and WooCommerce Subscriptions report the result of an account action as a notice in the customer's
 * session (wc_add_notice) and need a session for it. WooCommerce leaves the session and cart unloaded on custom REST
 * routes, so this loads them as Domains\Cart does and, if there is still no session, runs nothing and says so. Otherwise it runs the code, hands back the notices as plain text and puts the
 * customer's own pending notices back, so a tool call neither shows the customer a stranger's notice later nor eats one.
 */
final class Notices {

	/**
	 * Runs a callback and returns what it said.
	 *
	 * @param callable $callback Callback.
	 * @return array{ok: bool, messages: string[], errors: string[], result: mixed} ok is false when an error notice was added.
	 */
	public static function capture( callable $callback ): array {
		if ( ! WC()->session && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$session = WC()->session;

		// Without a session there would be no notices to read: the action is not run at all.
		if ( ! $session ) {
			return array(
				'ok'       => false,
				'messages' => array(),
				'errors'   => array( __( 'This cannot be done right now. The customer can do it in the dashboard.', 'beltoft-services' ) ),
				'result'   => false,
			);
		}

		$before = $session->get( 'wc_notices', array() );
		$session->set( 'wc_notices', array() );

		try {
			$result = $callback();
			$made   = (array) $session->get( 'wc_notices', array() );
		} finally {
			$session->set( 'wc_notices', $before );
		}

		$text = static function ( array $list ): array {
			$out = array();

			foreach ( $list as $notice ) {
				$out[] = trim( html_entity_decode( wp_strip_all_tags( (string) ( is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice ) ), ENT_QUOTES, 'UTF-8' ) );
			}

			return array_values( array_filter( $out ) );
		};

		$errors = $text( (array) ( $made['error'] ?? array() ) );

		return array(
			'ok'       => ! $errors,
			'messages' => array_merge( $text( (array) ( $made['success'] ?? array() ) ), $text( (array) ( $made['notice'] ?? array() ) ) ),
			'errors'   => $errors,
			'result'   => $result,
		);
	}
}

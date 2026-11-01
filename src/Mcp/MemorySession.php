<?php
/**
 * A WooCommerce session that is kept in memory only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Stands in for the real session while the Sandbox runs a checkout, so nothing the checkout puts in the session (the
 * cart, notices, the order awaiting payment) reaches the customer's real session. Any method of the real handler that
 * is called on it (cookies, saving, destroying) does nothing.
 */
final class MemorySession extends \WC_Session {

	/**
	 * The customer is the one logged in.
	 */
	public function __construct() {
		$this->_customer_id = (string) get_current_user_id();
	}

	/**
	 * Does nothing: there is no store.
	 */
	public function save_data() {}

	/**
	 * A method of the real session handler (for example the cookie ones) that has no meaning here.
	 *
	 * @param string       $name Method.
	 * @param array<mixed> $args Arguments.
	 * @return null
	 */
	public function __call( $name, $args ) {
		unset( $name, $args );

		return null;
	}
}

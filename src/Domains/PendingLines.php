<?php
/**
 * The domain lines a customer has just put in the cart, kept outside the WooCommerce session.
 *
 * The session row of a customer is written whole: a request that read it before the domain was added (the checkout refresh that runs
 * while the add waits for the registrar) writes its older copy back at shutdown, and the line is gone. The lines are therefore kept
 * in a transient too, keyed by the session customer id, and a missing line is put back when the cart loads, before totals and before
 * the session is written. Nothing here asks the registrar: a line comes back only if the local checks of the add still pass.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class PendingLines {

	public const TRANSIENT = 'beltoft_services_pending_domains_';

	/** A pending line lives this long; the cart's own session is shorter lived than the line is useful. */
	public const EXPIRY = 2 * DAY_IN_SECONDS;

	public static function register(): void {
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'restore' ), 5 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'restore' ), 5 );
		add_action( 'shutdown', array( __CLASS__, 'guard_session' ), 19 );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'line_removed' ), 10, 2 );
		add_action( 'woocommerce_remove_cart_item_from_session', array( __CLASS__, 'line_dropped' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( __CLASS__, 'line_restored' ), 10, 2 );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'forget_all' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'forget_all' ) );
		add_action( 'wp_login', array( __CLASS__, 'login_merge' ), 5, 2 );
	}

	/**
	 * A guest logs in: the cart is merged into the account's own cart by WooCommerce, and the guest's pending store would only be
	 * orphaned (the customer id changes), so it goes now.
	 *
	 * @param string   $login Login name.
	 * @param \WP_User $user  The user.
	 */
	public static function login_merge( $login = '', $user = null ): void {
		$customer = self::customer();
		if ( '' !== $customer && $user instanceof \WP_User && (string) $user->ID !== $customer ) {
			self::forget_all();
		}
	}

	/** The end of the WooCommerce session of this request (the cart expires with it), as a time stamp; 0 when it is not known. */
	private static function session_end(): int {
		$session = function_exists( 'WC' ) && WC() ? WC()->session : null;
		if ( ! $session instanceof \WC_Session_Handler ) { return 0; }
		$read = \Closure::bind( fn() => (int) $this->_session_expiration, $session, \WC_Session_Handler::class );
		return $read();
	}

	/** The WooCommerce session customer id, or '' when there is no session. */
	private static function customer(): string {
		$session = function_exists( 'WC' ) && WC() ? WC()->session : null;
		return is_object( $session ) && method_exists( $session, 'get_customer_id' ) ? (string) $session->get_customer_id() : '';
	}

	private static function option_key( string $customer ): string {
		return self::TRANSIENT . md5( $customer );
	}

	/** @return array<string, array{product_id: int, item: array}> Lines by domain name. */
	private static function all( string $customer ): array {
		if ( '' === $customer ) { return array(); }
		$rows = get_transient( self::option_key( $customer ) );
		if ( ! is_array( $rows ) ) { return array(); }
		// A logged-in customer keeps the same id for ever, so the store itself would outlive an expired cart: each row carries the end
		// of the session it was kept in, and is gone with it.
		$now = time();
		return array_filter( $rows, fn( $row ) => ! is_array( $row ) || ! isset( $row['exp'] ) || (int) $row['exp'] >= $now );
	}

	private static function save( string $customer, array $rows ): void {
		if ( '' === $customer ) { return; }
		if ( ! $rows ) {
			delete_transient( self::option_key( $customer ) );
			return;
		}
		set_transient( self::option_key( $customer ), $rows, self::EXPIRY );
	}

	/** The domain of a registration or transfer line, or ''. Renewals and the like are not kept: they are not chosen on the order form. */
	private static function name( array $item ): string {
		$line = $item['beltoft_services_domain'] ?? null;
		if ( ! is_array( $line ) || ! in_array( $line['mode'] ?? '', array( 'register', 'transfer' ), true ) ) { return ''; }
		return strtolower( (string) ( $line['domain'] ?? '' ) );
	}

	/** The cart line as the session stores it: without the product object. */
	private static function session_form( array $item ): array {
		unset( $item['data'] );
		return $item;
	}

	/** Remembers a line that was just added. */
	public static function keep( array $item ): void {
		$name = self::name( $item );
		if ( '' === $name || Cart::is_continuation_line( $item ) ) { return; }
		$customer = self::customer();
		$rows = self::all( $customer );
		$end = self::session_end();
		$exp = time() + self::EXPIRY;
		$rows[ $name ] = array( 'product_id' => (int) ( $item['product_id'] ?? 0 ), 'item' => self::session_form( $item ), 'exp' => $end > 0 ? min( $exp, $end ) : $exp );
		self::save( $customer, $rows );
	}

	public static function forget( string $domain ): void {
		$customer = self::customer();
		$rows = self::all( $customer );
		if ( isset( $rows[ strtolower( $domain ) ] ) ) {
			unset( $rows[ strtolower( $domain ) ] );
			self::save( $customer, $rows );
		}
	}

	/** The cart was emptied or an order was placed: nothing is pending. */
	public static function forget_all(): void {
		$customer = self::customer();
		if ( '' !== $customer ) { delete_transient( self::option_key( $customer ) ); }
	}

	/** @param string $key Cart item key. @param \WC_Cart $cart Cart. */
	public static function line_removed( $key, $cart = null ): void {
		$item = $cart instanceof \WC_Cart ? ( $cart->cart_contents[ $key ] ?? null ) : null;
		if ( is_array( $item ) && '' !== self::name( $item ) ) { self::forget( self::name( $item ) ); }
	}

	/** @param string $key Cart item key. @param array $values The item the session held. */
	public static function line_dropped( $key, $values = array() ): void {
		if ( is_array( $values ) && '' !== self::name( $values ) ) { self::forget( self::name( $values ) ); }
	}

	/** @param string $key Cart item key. @param \WC_Cart $cart Cart. */
	public static function line_restored( $key, $cart = null ): void {
		$item = $cart instanceof \WC_Cart ? ( $cart->cart_contents[ $key ] ?? null ) : null;
		if ( is_array( $item ) ) { self::keep( $item ); }
	}

	/** Names of the pending lines that are still allowed, in the order kept; the others are dropped from the store. @return array<string, array> */
	private static function allowed( string $customer ): array {
		$rows = self::all( $customer );
		$keep = array();

		foreach ( $rows as $name => $row ) {
			if ( is_array( $row['item'] ?? null ) && Cart::can_restore( $row['item'] ) ) {
				$keep[ $name ] = $row;
			}
		}

		if ( count( $keep ) !== count( $rows ) ) { self::save( $customer, $keep ); }
		return $keep;
	}

	/** Puts a pending line that the cart lacks back in the cart. */
	public static function restore( $cart = null ): void {
		$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) && WC() ? WC()->cart : null );
		if ( ! $cart instanceof \WC_Cart ) { return; }
		// Only the customer's own cart. WooCommerce Subscriptions builds a recurring cart per billing group as a clone of it, with the
		// other groups' lines removed, and calculates its totals through the same hook: a line put back there would join another
		// subscription (a domain inside the hosting subscription).
		if ( ! empty( $cart->recurring_cart_key ) || ( function_exists( 'WC' ) && WC() && WC()->cart instanceof \WC_Cart && $cart !== WC()->cart ) ) { return; }
		$customer = self::customer();
		if ( '' === $customer ) { return; }
		$rows = self::all( $customer );
		if ( ! $rows ) { return; }

		$present = array();
		foreach ( $cart->cart_contents as $item ) {
			if ( is_array( $item ) && '' !== self::name( $item ) ) { $present[ self::name( $item ) ] = true; }
		}

		foreach ( self::allowed( $customer ) as $name => $row ) {
			if ( isset( $present[ $name ] ) ) { continue; }
			$product = wc_get_product( (int) ( $row['item']['variation_id'] ?? 0 ) ?: (int) $row['product_id'] );
			if ( ! $product ) { continue; }
			$key = (string) ( $row['item']['key'] ?? '' );
			if ( '' === $key || isset( $cart->cart_contents[ $key ] ) ) { continue; }
			// The line goes through the filter every line of a loaded session goes through.
			$cart->cart_contents[ $key ] = apply_filters( 'woocommerce_get_cart_item_from_session', array_merge( $row['item'], array( 'data' => $product ) ), $row['item'], $key );
		}
	}

	/**
	 * Just before the session is written: a request that holds an older copy of the cart, and writes it, must not take a pending line
	 * with it. Only a request that writes at all is touched, so a read-only request never overwrites newer session data.
	 */
	public static function guard_session(): void {
		$session = function_exists( 'WC' ) && WC() ? WC()->session : null;
		if ( ! $session instanceof \WC_Session_Handler || '' === self::customer() ) { return; }
		$dirty = \Closure::bind( fn() => $this->_dirty, $session, \WC_Session_Handler::class );
		if ( ! $dirty() ) { return; }

		$stored = $session->get( 'cart', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$have = array();
		foreach ( $stored as $item ) {
			if ( is_array( $item ) && '' !== self::name( $item ) ) { $have[ self::name( $item ) ] = true; }
		}

		$changed = false;
		foreach ( self::allowed( self::customer() ) as $name => $row ) {
			$key = (string) ( $row['item']['key'] ?? '' );
			if ( isset( $have[ $name ] ) || '' === $key || isset( $stored[ $key ] ) ) { continue; }
			$stored[ $key ] = $row['item'];
			$changed = true;
		}

		if ( $changed ) { $session->set( 'cart', $stored ); }
	}
}

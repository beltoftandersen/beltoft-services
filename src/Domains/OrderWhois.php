<?php
/** Checkout: the WHOIS privacy choice of each domain line. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * One checkbox per register or transfer line in the order review, on by default, for an extension that allows WHOIS privacy
 * (WhoisPrivacy::order_allowed). It is free, so no price is shown. The box sits in the review table, which WooCommerce prints again
 * on every checkout refresh from the cart, so it always shows what the cart holds; a change is one wc-ajax call that writes the cart
 * line (`beltoft_services_domain.whois_privacy`) and its pending copy. The choice reaches the registrar as `is_private_whois_enabled`
 * when the order is placed (DomainDriver).
 */
final class OrderWhois {
	/** The wc-ajax action. */
	public const AJAX = 'beltoft_services_order_whois';

	/** Nonce action; it starts with `woocommerce-` so a guest's nonce is bound to the guest's own session (see OrderDomain::NONCE). */
	public const NONCE = 'woocommerce-beltoft-services-order-whois';

	/** Script handle. */
	public const HANDLE = 'beltoft-services-order-whois';

	public static function register(): void {
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'woocommerce_review_order_after_cart_contents', array( __CLASS__, 'print_rows' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The domain lines of the cart that carry the option, with the current choice.
	 *
	 * @return array<int, array{domain: string, on: bool}>
	 */
	public static function lines(): array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$out  = array();
		if ( ! $cart ) { return $out; }
		foreach ( $cart->get_cart() as $item ) {
			$line = $item['beltoft_services_domain'] ?? null;
			if ( ! is_array( $line ) || ! in_array( $line['mode'] ?? '', array( OrderDomain::REGISTER, OrderDomain::TRANSFER ), true ) ) { continue; }
			$name = HostName::normalise( (string) ( $line['domain'] ?? '' ) );
			if ( null === $name || ! WhoisPrivacy::order_allowed( $name ) ) { continue; }
			$out[ $name ] = array( 'domain' => $name, 'on' => false !== ( $line[ WhoisPrivacy::LINE_KEY ] ?? true ) );
		}
		return array_values( $out );
	}

	/** The words, one place for the rows and the script. */
	public static function texts(): array {
		return array(
			'label'  => __( 'WHOIS privacy (free): hides your name, address, email and phone in the public WHOIS.', 'beltoft-services' ),
			'failed' => __( 'The choice could not be saved. Reload the page and try again.', 'beltoft-services' ),
		);
	}

	/** The rows in the order review table. */
	public static function print_rows(): void {
		$text = self::texts();
		foreach ( self::lines() as $line ) {
			echo '<tr class="beltoft-services-whois-row"><td colspan="2"><label class="beltoft-services-whois"><input type="checkbox" data-bs-whois="' . esc_attr( $line['domain'] ) . '"' . checked( $line['on'], true, false ) . '> <span class="beltoft-services-whois__text"><strong>' . esc_html( HostName::display( $line['domain'] ) ) . '</strong> ' . esc_html( $text['label'] ) . '</span></label><span class="beltoft-services-whois__note" role="status" aria-live="polite"></span></td></tr>';
		}
	}

	/** The script, on the checkout page when a line can carry the option. */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || array() === self::lines() ) { return; }
		wp_enqueue_style( OrderDomain::HANDLE, plugins_url( 'assets/order-domain.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/order-domain.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/order-whois.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/order-whois.js' ), true );
		wp_localize_script( self::HANDLE, 'beltoftServicesOrderWhois', array( 'url' => class_exists( '\WC_AJAX' ) ? \WC_AJAX::get_endpoint( self::AJAX ) : '', 'nonce' => wp_create_nonce( self::NONCE ), 'text' => self::texts() ) );
	}

	public static function ajax(): void {
		$answer = self::respond();
		wp_send_json( $answer[0], $answer[1] );
	}

	/**
	 * Nonce checked, then the line's choice is written.
	 *
	 * @return array{0: array<string, mixed>, 1: int}
	 */
	public static function respond(): array {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) { return array( array( 'ok' => false ), 403 ); }
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$on     = isset( $_POST['on'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['on'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$count  = self::set( $domain, $on );
		return array( array( 'ok' => $count > 0, 'changed' => $count ), $count > 0 ? 200 : 409 );
	}

	/**
	 * Writes the choice on the register or transfer line of a domain, only where the extension allows it.
	 *
	 * @param string $domain Domain as typed.
	 * @param bool   $on     Privacy on.
	 * @return int Lines changed.
	 */
	public static function set( string $domain, bool $on ): int {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		$name = HostName::normalise( $domain );
		if ( ! $cart || null === $name || ! WhoisPrivacy::order_allowed( $name ) ) { return 0; }
		$count = 0;
		foreach ( $cart->get_cart() as $key => $item ) {
			$line = $item['beltoft_services_domain'] ?? null;
			if ( ! is_array( $line ) || ! in_array( $line['mode'] ?? '', array( OrderDomain::REGISTER, OrderDomain::TRANSFER ), true ) || HostName::normalise( (string) ( $line['domain'] ?? '' ) ) !== $name ) { continue; }
			$cart->cart_contents[ $key ]['beltoft_services_domain'][ WhoisPrivacy::LINE_KEY ] = $on;
			PendingLines::keep( $cart->cart_contents[ $key ] );
			++$count;
		}
		if ( $count > 0 ) { $cart->set_session(); }
		return $count;
	}
}

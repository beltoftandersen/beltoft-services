<?php
/** Draft .dk checkout agreement. Disabled until its wording is approved. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class DkAgreement {
	public const TERMS_VERSION = '15';
	public const TERMS_URL = 'https://punktum.dk/en/articles/terms-and-conditions-dk-domain-name';
	public const PRIVACY_URL = 'https://punktum.dk/en/articles/privacy-policy';

	public static function ready(): bool {
		return defined( 'BELTOFT_SERVICES_DK_AGREEMENT_APPROVED' ) && true === constant( 'BELTOFT_SERVICES_DK_AGREEMENT_APPROVED' );
	}

	public static function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'record' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_store_api' ), 10, 2 );
	}

	/** @return string[] Names for new/transfer .dk cart lines; renewal lines need no new agreement. */
	public static function domains( array $items ): array {
		$names = array();
		foreach ( $items as $item ) {
			$data = $item['beltoft_services_domain'] ?? null;
			if ( ! is_array( $data ) || ! in_array( $data['mode'] ?? '', array( 'register', 'transfer' ), true ) ) { continue; }
			$name = (string) ( $data['domain'] ?? '' );
			if ( str_ends_with( $name, '.dk' ) ) { $names[] = $name; }
		}
		return array_values( array_unique( $names ) );
	}

	private static function cart_domains(): array {
		return WC()->cart ? self::domains( WC()->cart->get_cart() ) : array();
	}

	/** Draft wording; only shown when the approval constant is explicitly enabled. */
	public static function render(): void {
		$names = self::cart_domains();
		if ( ! self::ready() || ! $names ) { return; }
		wp_enqueue_script( 'beltoft-services-dk-agreement', plugins_url( 'assets/dk-agreement.js', BELTOFT_SERVICES_FILE ), array(), BELTOFT_SERVICES_VERSION, true );
		wp_enqueue_style( 'beltoft-services-dk-agreement', plugins_url( 'assets/dk-agreement.css', BELTOFT_SERVICES_FILE ), array(), BELTOFT_SERVICES_VERSION );
		echo '<section class="beltoft-dk-agreement"><h3>' . esc_html__( '.dk registration agreement', 'beltoft-services' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Requested domain names', 'beltoft-services' ) . ':</strong> ' . esc_html( implode( ', ', $names ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Applicant', 'beltoft-services' ) . ':</strong> <span data-dk-applicant></span><br><span data-dk-address></span></p>';
		echo '<p><strong>Punktum dk A/S</strong><br>Ørestads Boulevard 108, 11th floor<br>DK-2300 Copenhagen S, Denmark</p>';
		echo '<p>' . esc_html__( 'For each name above, I apply for a right to use that .dk domain under Punktum dk’s terms. I will keep the registrant details accurate and complete any requested identity check. I understand that the name may be suspended, transferred, blocked or deleted under those terms, and that the agreement has no ordinary withdrawal right. I will pay beltoft.net for the first registration period and for later periods under my chosen management arrangement. Punktum dk handles my personal data under its privacy policy; if I register for a legal entity, its published email address must not contain personal data unless permitted by Punktum dk.', 'beltoft-services' ) . '</p>';
		echo '<p><a href="' . esc_url( self::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Punktum dk terms', 'beltoft-services' ) . '</a> · <a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Punktum dk privacy policy', 'beltoft-services' ) . '</a></p>';
		foreach ( $names as $index => $name ) {
			echo '<label class="beltoft-dk-accept"><input type="checkbox" name="beltoft_dk_agreement[' . esc_attr( (string) $index ) . ']" value="' . esc_attr( $name ) . '"> ';
			/* translators: %s: .dk domain name. */
			echo esc_html( sprintf( __( 'I accept the agreement with Punktum dk A/S for %s.', 'beltoft-services' ), $name ) ) . '</label>';
		}
		echo '</section>';
	}

	public static function validate(): void {
		$names = self::cart_domains();
		if ( ! $names ) { return; }
		if ( ! self::ready() ) { wc_add_notice( __( '.dk orders are not available yet.', 'beltoft-services' ), 'error' ); return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout nonce before processing the form.
		$posted = isset( $_POST['beltoft_dk_agreement'] ) && is_array( $_POST['beltoft_dk_agreement'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['beltoft_dk_agreement'] ) ) : array();
		if ( array_values( $posted ) !== $names ) { wc_add_notice( __( 'Accept the Punktum dk agreement for each .dk domain.', 'beltoft-services' ), 'error' ); }
	}

	/** Save proof on the parent order before WooCommerce stores it. */
	public static function record( \WC_Order $order, array $data ): void {
		$names = self::cart_domains();
		if ( ! self::ready() || ! $names ) { return; }
		$time = gmdate( 'Y-m-d H:i:s' );
		$order->update_meta_data( '_beltoft_services_dk_agreement', array(
			'version' => self::TERMS_VERSION,
			'accepted_at_utc' => $time,
			'domains' => $names,
			'applicant' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'address' => array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_postcode(), $order->get_billing_city(), $order->get_billing_country() ),
		) );
	}

	/** The approved display is classic checkout only; prevent an API request from bypassing it. */
	public static function block_store_api( \WC_Order $order, \WP_REST_Request $request ): void {
		if ( self::cart_domains() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'dk_checkout_agreement_required', esc_html__( 'Complete the .dk agreement in the website checkout.', 'beltoft-services' ), 400 );
		}
	}
}

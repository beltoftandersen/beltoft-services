<?php
/** .dk checkout agreement with Punktum dk's prescribed wording. Disabled until approved. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class DkAgreement {
	public const TERMS_VERSION = '15';
	public const TERMS_URL = 'https://www.punktum.dk/en/articles/terms-and-conditions-dk-domain-name';
	public const PRIVACY_URL = 'https://www.punktum.dk/en/articles/privacy-policy';
	public const ADDRESS_URL = 'https://punktum.dk/index.php/en/articles/appendix';

	/** Wording required by Punktum dk's implementation guide and its appendix (English), reproduced as given. */
	public const TERMS_LABEL    = 'Terms and conditions for the right of use to a .dk domain name';
	public const PRIVACY_LABEL  = 'Privacy Policy';
	public const CHECKBOX_LABEL = 'Yes, I accept the user agreement with Punktum dk A/S';
	public const INTRO          = array(
		'To register a .dk domain name, you have to enter into an agreement with Punktum dk A/S.',
		'Punktum dk is the administrator for all .dk domain names.',
	);

	/** The seller named in the payment paragraph: setting domain_seller_name, else the site name. */
	public static function seller(): string {
		$name = trim( (string) \BeltoftServices\Settings::get( 'domain_seller_name' ) );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** @return string[] The seven paragraphs of the agreement text (appendix, Ad. 4), with the seller filled in. */
	public static function agreement_paragraphs( string $seller ): array {
		return array(
			'I hereby agree to enter into an agreement on the right to use the specified .dk domain name according to the terms applicable hereto.',
			'Among other things, this means that I will ensure that my contact details as a registrant are accurate at all times. I will perform data- and identity control when requested to do so.',
			"My right to use the specified .dk domain name may be transferred, suspended, deleted, or blocked according to the conditions set out in Punktum dk A/S' terms of use.",
			'Pursuant to Section 18 (2) (13) of the Danish Consumer Contract Act, I agree to renounce the right to withdraw from the agreement on the right to use the specified .dk domain name.',
			'I understand that Punktum dk A/S, as domain administrator, use my personal data in accordance with its personal data policy.',
			'If I am a legal person (and not a registered sole proprietorship) I have been informed that my email address will be published and that my email address must not contain any personal data. The email address can be changed via Punktum dk‘s self-service portal or the registrar.',
			sprintf( "I agree that I will pay to %s the fee for the first registration period for the specified .dk domain name, and that payment for subsequent registration periods depends on my choice of management arrangement, cf. section 2.1 of Punktum dk A/S' terms and conditions.", $seller ),
		);
	}

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

	/** Punktum dk's wording; only shown when the approval constant is explicitly enabled. */
	public static function render(): void {
		$names = self::cart_domains();
		if ( ! self::ready() || ! $names ) { return; }
		wp_enqueue_script( 'beltoft-services-dk-agreement', plugins_url( 'assets/dk-agreement.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/dk-agreement.js' ), true );
		wp_enqueue_style( 'beltoft-services-dk-agreement', plugins_url( 'assets/dk-agreement.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/dk-agreement.css' ) );
		$seller = self::seller();
		echo '<section class="beltoft-dk-agreement"><h3>' . esc_html__( '.dk registration agreement', 'beltoft-services' ) . '</h3>';
		echo '<p>' . esc_html( self::INTRO[0] ) . ' ' . esc_html( self::INTRO[1] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Domain name', 'beltoft-services' ) . ':</strong> ' . esc_html( implode( ', ', $names ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Applicant', 'beltoft-services' ) . '*:</strong> <span data-dk-applicant></span><br><span data-dk-address></span><br><span data-dk-phone></span><br><span data-dk-email></span></p>';
		echo '<p class="beltoft-dk-note">* ' . esc_html__( "Applicant's name and address are required.", 'beltoft-services' ) . '</p>';
		echo '<p><strong>Punktum dk A/S</strong><br>Ørestads Boulevard 108, 11th floor<br>DK-2300 Copenhagen S<br><a href="' . esc_url( self::ADDRESS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Address and agreement text in Danish and English', 'beltoft-services' ) . '</a></p>';
		foreach ( self::agreement_paragraphs( $seller ) as $paragraph ) {
			echo '<p>' . esc_html( $paragraph ) . '</p>';
		}
		echo '<p><a href="' . esc_url( self::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( self::TERMS_LABEL ) . '</a><br><a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( self::PRIVACY_LABEL ) . '</a></p>';
		foreach ( $names as $index => $name ) {
			echo '<label class="beltoft-dk-accept"><input type="checkbox" name="beltoft_dk_agreement[' . esc_attr( (string) $index ) . ']" value="' . esc_attr( $name ) . '"> ' . esc_html( self::CHECKBOX_LABEL ) . ' (' . esc_html( $name ) . ')</label>';
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
		// The registrar requires a tax number (a person) or a VAT number (a company) for .dk. Only the VAT number is collected so far, so private persons cannot order a .dk domain yet.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout nonce before processing the form.
		$vat = isset( $_POST['billing_eu_vat_number'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['billing_eu_vat_number'] ) ) ) : '';
		if ( '' === $vat ) { wc_add_notice( __( 'A .dk domain needs a VAT number. Private persons cannot register a .dk domain here yet.', 'beltoft-services' ), 'error' ); }
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

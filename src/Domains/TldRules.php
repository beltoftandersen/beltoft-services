<?php
/** Registrant rules that come with an extension. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * .eu may only be registered by someone in the EU (the registrar says so). The billing country is the registrant's
 * country in the order, so checkout refuses a .eu domain with a billing country outside the EU.
 */
final class TldRules {
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'check_store_api' ), 10, 2 );
	}

	/** Does the cart hold a .eu domain to register or transfer? */
	private static function cart_has_eu(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return false; }
		foreach ( WC()->cart->get_cart() as $item ) {
			$domain = (string) ( $item['beltoft_services_domain']['domain'] ?? '' );
			if ( str_ends_with( $domain, '.eu' ) && in_array( $item['beltoft_services_domain']['mode'] ?? '', array( 'register', 'transfer' ), true ) ) { return true; }
		}
		return false;
	}

	public static function eu_country( string $country ): bool {
		return in_array( strtoupper( $country ), (array) WC()->countries->get_european_union_countries(), true );
	}

	/** Classic checkout. */
	public static function validate( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error || ! self::cart_has_eu() ) { return; }
		if ( ! self::eu_country( (string) ( $data['billing_country'] ?? '' ) ) ) {
			$errors->add( 'beltoft_services_eu_registrant', __( 'A .eu domain needs a registrant in the EU. Choose an EU country as the billing country.', 'beltoft-services' ) );
		}
	}

	/** Store API checkout. */
	public static function check_store_api( $order, $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( $order instanceof \WC_Order && self::cart_has_eu() && ! self::eu_country( (string) $order->get_billing_country() ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'beltoft_services_eu_registrant', esc_html__( 'A .eu domain needs a registrant in the EU. Choose an EU country as the billing country.', 'beltoft-services' ), 400 );
		}
	}
}

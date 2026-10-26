<?php
/** Domain purchase cart lines. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

final class Cart {
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_prices' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item' ), 10, 4 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity' ), 10, 3 );
		add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( __CLASS__, 'recurring_key' ), 10, 2 );
	}

	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/domains/cart', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'rest_add' ),
			'permission_callback' => '__return_true',
			'args' => array( 'domain' => array( 'type' => 'string', 'required' => true ), 'mode' => array( 'type' => 'string', 'required' => true ), 'auth_code' => array( 'type' => 'string' ) ),
		) );
	}

	public static function rest_add( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WC-Nonce' ), 'wc_store_api' ) ) {
			return new \WP_Error( 'bad_nonce', __( 'Refresh the page and try again.', 'beltoft-services' ), array( 'status' => 403 ) );
		}
		$data = self::line_data( (string) $request['domain'], (string) $request['mode'], (string) ( $request['auth_code'] ?? '' ) );
		if ( is_wp_error( $data ) ) { return $data; }
		$cart = WC()->cart;
		if ( ! $cart ) { return new \WP_Error( 'no_cart', __( 'The cart is unavailable.', 'beltoft-services' ), array( 'status' => 503 ) ); }
		foreach ( $cart->get_cart() as $item ) {
			if ( ( $item['beltoft_services_domain']['domain'] ?? '' ) === $data['beltoft_services_domain']['domain'] ) {
				return new \WP_Error( 'duplicate_domain', __( 'This domain is already in your cart.', 'beltoft-services' ), array( 'status' => 409 ) );
			}
		}
		$mode = $data['beltoft_services_domain']['mode'];
		$product = self::product_for( 'dns' === $mode ? 'dns' : 'domain' );
		if ( ! $product ) { return new \WP_Error( 'product_missing', __( 'This service is not available yet.', 'beltoft-services' ), array( 'status' => 503 ) ); }
		if ( 'register' === $mode || 'transfer' === $mode ) {
			$search = Search::find( $data['beltoft_services_domain']['domain'] );
			if ( is_wp_error( $search ) ) { return $search; }
			$status = $search['results'][0]['status'] ?? 'not_sold';
			if ( ( 'register' === $mode && 'free' !== $status ) || ( 'transfer' === $mode && 'taken' !== $status ) ) {
				return new \WP_Error( 'domain_unavailable', __( 'This domain cannot be ordered in that way.', 'beltoft-services' ), array( 'status' => 409 ) );
			}
		}
		$key = $cart->add_to_cart( $product->get_id(), 1, 0, array(), $data );
		if ( ! $key ) { return new \WP_Error( 'cart_failed', __( 'The domain could not be added to your cart.', 'beltoft-services' ), array( 'status' => 400 ) ); }
		return new \WP_REST_Response( array( 'added' => true, 'cart_url' => wc_get_cart_url() ), 201 );
	}

	public static function line_data( string $domain, string $mode, string $auth_code = '' ): array|\WP_Error {
		$domain = Search::normalise( $domain );
		if ( is_wp_error( $domain ) ) { return $domain; }
		if ( ! in_array( $mode, array( 'register', 'transfer', 'dns' ), true ) ) { return new \WP_Error( 'bad_mode', 'Choose registration, transfer or DNS hosting.' ); }
		$parts = explode( '.', $domain );
		if ( 'dns' === $mode && count( $parts ) > 2 ) {
			$table = get_option( Prices::OPTION, array() );
			$suffix = implode( '.', array_slice( $parts, 1 ) );
			if ( ! is_array( $table ) || ! isset( $table[ $suffix ] ) ) { return new \WP_Error( 'subdomain', 'Enter the main domain, without a subdomain.' ); }
		}
		if ( 'transfer' === $mode && '' === trim( $auth_code ) ) { return new \WP_Error( 'auth_required', 'Enter the transfer code.' ); }
		$data = array( 'beltoft_services_domain' => array( 'domain' => $domain, 'mode' => $mode ) );
		if ( 'transfer' === $mode ) {
			try { $data['beltoft_services_domain_auth'] = Crypto::encrypt( trim( $auth_code ) ); }
			catch ( \RuntimeException $e ) { return new \WP_Error( 'encryption_unavailable', 'Transfer cannot start until secure storage is configured.' ); }
		}
		return $data;
	}

	public static function price_for( array $item ): ?int {
		foreach ( array( 'subscription_renewal', 'subscription_resubscribe', 'subscription_switch' ) as $key ) {
			if ( ! empty( $item[ $key ] ) ) { return null; }
		}
		$domain = $item['beltoft_services_domain'] ?? null;
		if ( ! is_array( $domain ) || 'dns' === ( $domain['mode'] ?? '' ) ) { return null; }
		$name = (string) ( $domain['domain'] ?? '' );
		$table = get_option( Prices::OPTION, array() );
		$tlds = is_array( $table ) ? array_keys( $table ) : array();
		usort( $tlds, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		foreach ( $tlds as $tld ) {
			if ( str_ends_with( $name, '.' . $tld ) ) { return Prices::price( $tld ); }
		}
		return null;
	}

	public static function apply_prices( \WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $item ) {
			$price = self::price_for( $item );
			if ( null !== $price && isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) { $item['data']->set_price( $price ); }
		}
	}

	public static function order_item( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( isset( $values['beltoft_services_domain'] ) ) { $item->add_meta_data( '_beltoft_services_domain', $values['beltoft_services_domain'], true ); }
		if ( isset( $values['beltoft_services_domain_auth'] ) ) { $item->add_meta_data( '_beltoft_services_domain_auth', $values['beltoft_services_domain_auth'], true ); }
	}

	public static function quantity( string $html, string $cart_item_key, array $cart_item ): string {
		return isset( $cart_item['beltoft_services_domain'] ) ? '1' : $html;
	}

	/** Keep each domain on its own subscription, so each gets its own license and renewal date. */
	public static function recurring_key( string $key, array $cart_item ): string {
		$domain = (string) ( $cart_item['beltoft_services_domain']['domain'] ?? '' );
		return '' === $domain ? $key : $key . '_domain_' . md5( $domain );
	}

	private static function product_for( string $driver ): ?\WC_Product {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 20, 'meta_key' => Product::META_DRIVER, 'meta_value' => $driver ) );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->is_purchasable() ) { return $product; }
		}
		return null;
	}
}

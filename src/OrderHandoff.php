<?php
/**
 * The hand-off screen: a short page after payment, then My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * The customer still lands on the order received URL, and that request runs as before (the gateway's return, the cart
 * emptied, the purchase event of the tag manager). Only the template of the page changes, through wc_get_template: a
 * short screen with a progress line that waits until the purchase event has been sent (assets/handoff.js) and then moves
 * the browser to My Account. Never a server redirect.
 *
 * Which orders get it (applies()): the setting is on, the visitor is logged in and is the order's customer, the order is
 * paid (processing or completed), and there is a place to go (target()): a new order with services goes to the overview
 * with ?order=<id>; an add-on, a switch or a renewal payment goes back to the page of the service it was for (a feature
 * says which through the filter beltoft_services_order_handoff_target). Everything else keeps the normal page.
 */
final class OrderHandoff {

	public const SETTING = 'order_handoff';

	/**
	 * Query argument of the overview that names the new order.
	 */
	public const ARG = 'order';

	/**
	 * Shortcode a page builder's order received template places.
	 */
	public const SHORTCODE = 'beltoft_services_order_handoff';

	/**
	 * Body class of an order received page that gets the hand-off, so the template can hide its normal content.
	 */
	public const BODY_CLASS = 'bs-handoff';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'settings_fields' ), 8 );
		add_filter( 'wc_get_template', array( __CLASS__, 'swap' ), 10, 5 );
		// A page builder draws the order received page itself and never loads checkout/thankyou.php: its template places the shortcode.
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	/**
	 * The setting, on the General tab.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions by key.
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_fields( array $fields ): array {
		$fields[ self::SETTING ] = array(
			'tab'     => 'general',
			'section' => 'account',
			'type'    => 'checkbox',
			'default' => 0,
			'label'   => __( 'Hand-off screen after payment', 'beltoft-services' ),
			'desc'    => __( 'After a paid order the order received page shows a short "Setting up your order" screen and then takes the customer to My Account, where the services of the order show their progress. Orders that are not paid yet, orders without services and visitors who are not logged in keep the normal page. Turn it off to get the normal page back at once.', 'beltoft-services' ),
		);

		return $fields;
	}

	/**
	 * Is the hand-off turned on?
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( self::SETTING );
	}

	/**
	 * Does this order get the hand-off screen for this visitor?
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function applies( \WC_Order $order ): bool {
		if ( ! self::enabled() || ! is_user_logged_in() ) {
			return false;
		}

		$customer = (int) $order->get_customer_id();

		if ( $customer < 1 || get_current_user_id() !== $customer || ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			return false;
		}

		// The return of an express checkout from a product page ends on its own page.
		if ( isset( $_GET['wc_stripe_product_checkout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only check of the gateway's return.
			return false;
		}

		if ( '' === self::target( $order ) ) {
			return false;
		}

		/**
		 * Whether this order gets the hand-off screen. A site can keep the normal page for a kind of order.
		 *
		 * @param bool      $apply True when every rule above holds.
		 * @param \WC_Order $order The order.
		 */
		return (bool) apply_filters( 'beltoft_services_order_handoff', true, $order );
	}

	/**
	 * Where the browser goes after the hand-off, or '' when there is no place (the normal page stays).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function target( \WC_Order $order ): string {
		/**
		 * The page a paid order leads to: the page of the service an add-on, a switch or a renewal payment was for. Empty
		 * until a feature knows; the core then sends a new order with services to the overview of My Account.
		 *
		 * @param string    $url   Address, '' by default.
		 * @param \WC_Order $order The order.
		 */
		$url = (string) apply_filters( 'beltoft_services_order_handoff_target', '', $order );

		if ( '' !== $url ) {
			return $url;
		}

		// A renewal, a switch or a resubscribe whose service is unknown keeps the normal page.
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, array( 'renewal', 'switch', 'resubscribe' ) ) ) {
			return '';
		}

		// The services are made just after payment, in the background, so they are often not there yet when this page loads: an
		// order with a service product qualifies at once and the overview shows "Setting up your order" until they appear.
		if ( ( ! ThankYou::resources( $order ) && ! self::has_service_lines( $order ) ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}

		return add_query_arg( self::ARG, $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * Does the order hold a line of a service product (a product with a driver)?
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function has_service_lines( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Product::is_service( (int) ( $item->get_variation_id() ?: $item->get_product_id() ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The order of the order received request, when its key matches; null otherwise.
	 */
	public static function page_order(): ?\WC_Order {
		// WooCommerce's order-received endpoint is the only place this query variable is set.
		$order_id = function_exists( 'wc_get_order' ) ? absint( get_query_var( 'order-received' ) ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the proof, as in WooCommerce.
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;

		return $order instanceof \WC_Order && '' !== $key && hash_equals( $order->get_order_key(), $key ) ? $order : null;
	}

	/**
	 * The shortcode: the hand-off content on the order received request of an order that gets it, nothing otherwise.
	 */
	public static function shortcode(): string {
		$order = self::page_order();

		if ( null === $order || ! self::applies( $order ) ) {
			return '';
		}

		self::enqueue();

		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/order/handoff.php';

		return (string) ob_get_clean();
	}

	/**
	 * Filter body_class: a marker on a page that gets the hand-off.
	 *
	 * @param mixed $classes Classes.
	 * @return mixed
	 */
	public static function body_class( $classes ) {
		$order = self::page_order();

		if ( is_array( $classes ) && null !== $order && self::applies( $order ) ) {
			$classes[] = self::BODY_CLASS;
		}

		return $classes;
	}

	/**
	 * Filter wc_get_template: the hand-off template replaces the thank-you template of an order that gets it.
	 *
	 * @param mixed $template      Located template.
	 * @param mixed $template_name Name.
	 * @param mixed $args          Variables of the template.
	 * @return mixed
	 */
	public static function swap( $template, $template_name = '', $args = array() ) {
		if ( 'checkout/thankyou.php' !== $template_name || ! is_array( $args ) ) {
			return $template;
		}

		$order = $args['order'] ?? null;

		return $order instanceof \WC_Order && self::applies( $order ) ? BELTOFT_SERVICES_PATH . 'templates/order/handoff.php' : $template;
	}

	/**
	 * The order of the order received page, or null.
	 */
	private static function current_order(): ?\WC_Order {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return null;
		}

		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * The hand-off page is never kept by a cache (headers go out before the template, so this is on template_redirect).
	 */
	public static function no_cache(): void {
		$order = self::current_order();

		if ( $order && self::applies( $order ) ) {
			nocache_headers();
		}
	}

	/**
	 * The script, only on a page that prints the hand-off.
	 */
	public static function assets(): void {
		$order = self::current_order();

		if ( ! $order || ! self::applies( $order ) ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * Enqueues the script (footer, so it can also be called while the content is drawn).
	 */
	private static function enqueue(): void {
		wp_enqueue_script( 'beltoft-services-handoff', plugins_url( 'assets/handoff.js', BELTOFT_SERVICES_FILE ), array(), Assets::version( 'assets/handoff.js' ), true );
	}

	/**
	 * Whether the tag manager plugin is on this site (the page then waits for its purchase event).
	 */
	public static function tracking(): bool {
		return defined( 'GTM4WP_VERSION' );
	}
}

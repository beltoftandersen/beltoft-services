<?php
/**
 * The checkout rule of managed databases: a customer needs a server with us first.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A managed database (driver `proxmox-db`) is reachable only over the customer's private network, from their
 * own servers on the same cluster. A cart with a database is refused unless the customer is logged in and has a
 * Proxmox server of ours (driver `proxmox`, not linked) in state ready or paused on the database plan's cluster.
 *
 * The shop keeps one item per cart, so "a server in the same cart" does not count: the server must already be
 * in the account. The filter `beltoft_services_db_needs_server` (bool) turns the rule off, and `beltoft_services_db_needs_server_url` changes the page the message links to.
 */
final class DbNeedsServer {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_cart' ), 20, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
	}

	/**
	 * Is the rule on?
	 */
	public static function enabled(): bool {
		/**
		 * Turns the "a database needs a server with us" rule on (true, the default) or off.
		 *
		 * @param bool $on Whether the rule applies.
		 */
		return (bool) apply_filters( 'beltoft_services_db_needs_server', true );
	}

	/**
	 * The page the message links to.
	 */
	public static function url(): string {
		/**
		 * The page where a customer orders a server.
		 *
		 * @param string $url Address.
		 */
		return (string) apply_filters( 'beltoft_services_db_needs_server_url', home_url( '/vps-hosting/' ) );
	}

	/**
	 * The message, with the link on its second sentence when $html.
	 *
	 * @param bool $html With the link (a notice) or plain text (the Store API strips markup).
	 */
	public static function message( bool $html ): string {
		$first = __( 'A database is reachable from your servers with us over a private network.', 'beltoft-services' );
		$link  = __( 'Order a server first', 'beltoft-services' );
		$last  = __( 'then add the database to your account.', 'beltoft-services' );

		if ( ! $html ) {
			return $first . ' ' . $link . ', ' . $last;
		}

		return esc_html( $first ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html( $link ) . '</a>, ' . esc_html( $last );
	}

	/**
	 * The cluster a database product (or variation) is planned on, or null when it is not a database product.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 */
	private static function database_cluster( int $product_id, int $variation_id = 0 ): ?string {
		$driver = Product::driver_for( $product_id );

		if ( ! $driver || DbDriver::ID !== $driver->id() ) {
			return null;
		}

		$plan = Product::plan( $product_id, $variation_id > 0 ? $variation_id : null );

		return (string) ( $plan['cluster'] ?? '' );
	}

	/**
	 * The clusters of the databases in a cart (renewals and switches are not a new database).
	 *
	 * @param \WC_Cart|null $cart Cart; the current one when null.
	 * @return string[]
	 */
	private static function clusters_in_cart( ?\WC_Cart $cart = null ): array {
		$cart     = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		$clusters = array();

		foreach ( $cart ? $cart->get_cart() : array() as $item ) {
			if ( isset( $item['subscription_renewal'] ) || isset( $item['subscription_switch'] ) ) {
				continue;
			}

			$cluster = self::database_cluster( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );

			if ( null !== $cluster ) {
				$clusters[] = $cluster;
			}
		}

		return $clusters;
	}

	/**
	 * Does the customer have one of our own servers on this cluster, ready or paused?
	 *
	 * @param int    $user_id Customer (0 when logged out).
	 * @param string $cluster Cluster id.
	 */
	public static function has_server( int $user_id, string $cluster ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		foreach ( \BeltoftLicenses\Licenses::where( 'user_id', $user_id ) as $license ) {
			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || 'proxmox' !== $resource->get( 'driver' ) || true === $resource->get( 'linked' ) || ! in_array( $resource->get( 'state' ), array( 'ready', 'paused' ), true ) ) {
				continue;
			}

			$vm = VmRecords::for_resource( $resource->id() );
			$on = $vm ? $vm['cluster'] : (string) ( $resource->get( 'plan' )['cluster'] ?? '' );

			if ( '' !== $cluster && $on === $cluster ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is a database on these clusters refused for this customer?
	 *
	 * @param int      $user_id  Customer.
	 * @param string[] $clusters Clusters of the databases being bought.
	 */
	private static function refused( int $user_id, array $clusters ): bool {
		if ( ! $clusters || ! self::enabled() ) {
			return false;
		}

		foreach ( $clusters as $cluster ) {
			if ( ! self::has_server( $user_id, $cluster ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add to cart: the same notice, and the database is not added.
	 *
	 * @param mixed $valid        Whether it is valid so far.
	 * @param mixed $product_id   Product id.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation id.
	 */
	public static function add_to_cart( $valid, $product_id = 0, $quantity = 1, $variation_id = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( ! $valid ) {
			return false;
		}

		$cluster = self::database_cluster( (int) $product_id, (int) $variation_id );

		if ( null !== $cluster && self::refused( get_current_user_id(), array( $cluster ) ) ) {
			wc_add_notice( self::message( true ), 'error' );

			return false;
		}

		return true;
	}

	/**
	 * Classic checkout. WooCommerce has verified the checkout nonce.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors Validation errors.
	 */
	public static function validate( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( $errors instanceof \WP_Error && self::refused( get_current_user_id(), self::clusters_in_cart() ) ) {
			$errors->add( 'beltoft_services_db_needs_server', self::message( true ) );
		}
	}

	/**
	 * Block checkout (Store API): the same rule.
	 *
	 * @param mixed $errors Cart errors.
	 * @param mixed $cart   Cart.
	 */
	public static function store_api_errors( $errors, $cart = null ): void {
		if ( $errors instanceof \WP_Error && self::refused( get_current_user_id(), self::clusters_in_cart( $cart instanceof \WC_Cart ? $cart : null ) ) ) {
			$errors->add( 'beltoft_services_db_needs_server', self::message( false ) );
		}
	}
}

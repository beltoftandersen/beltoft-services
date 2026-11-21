<?php
/** Priority support: one entitlement per customer account, and the flag the support desk reads. @package BeltoftServices */

namespace BeltoftServices\Support;

use BeltoftLicenses\Licenses;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A customer has priority support while a ready resource of the driver exists for their account and its licence is active.
 * How staff sees it, using only pieces that exist today:
 *
 * - user meta `beltoft_services_priority_support` ("1" or removed), kept in step on every state change; it is the one flag
 *   a support integration reads (it also shows on the customer's WordPress profile, which the support desk's customer
 *   link opens);
 * - the filter `beltoft_services_has_priority_support` (customer id) for code that asks at ticket time (the support
 *   plugin can set the ticket priority from it).
 *
 * The promise text is a setting-free constant sentence: an answer within a set time on every day of the week, never "24/7".
 */
final class Priority {

	/** User meta: priority support is active. */
	public const META = 'beltoft_services_priority_support';

	public static function register(): void {
		add_filter( 'beltoft_services_drivers', array( __CLASS__, 'drivers' ) );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'changed' ), 20, 3 );
		add_filter( 'beltoft_services_has_priority_support', array( __CLASS__, 'filter' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 20, 3 );
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
	}

	/**
	 * Registers the driver.
	 *
	 * @param mixed $drivers Drivers so far.
	 * @return mixed
	 */
	public static function drivers( $drivers ) {
		if ( is_array( $drivers ) && ! isset( $drivers[ PriorityDriver::ID ] ) ) {
			$drivers[ PriorityDriver::ID ] = new PriorityDriver();
		}

		return $drivers;
	}

	/** What priority support promises, in plain words. */
	public static function promise(): string {
		return __( 'Priority support: we answer within 4 hours, from 8 to 22 every day, weekends included.', 'beltoft-services' );
	}

	/**
	 * Does the customer have priority support now?
	 *
	 * @param int $user_id User id.
	 */
	public static function has( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		foreach ( Licenses::where( 'user_id', $user_id ) as $license ) {
			$driver = Product::driver_for( (int) ( $license['product_id'] ?? 0 ) );

			if ( ! $driver || PriorityDriver::ID !== $driver->id() ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( $resource && 'ready' === $resource->get( 'state' ) && 'ready' === $resource->get( 'desired' ) && Lifecycle::is_active( $license ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What the account overview offers: the promise, and either that it is active or the price and the link that buys it.
	 * Null when the customer does not have it and no published product sells it.
	 *
	 * @param int $user_id Customer.
	 * @return array{active: bool, promise: string, price: string, url: string}|null
	 */
	public static function offer( int $user_id ): ?array {
		if ( $user_id < 1 ) {
			return null;
		}

		if ( self::has( $user_id ) ) {
			return array( 'active' => true, 'promise' => self::promise(), 'price' => '', 'url' => '' );
		}

		$product = self::product();

		if ( null === $product ) {
			return null;
		}

		return array(
			'active'  => false,
			'promise' => self::promise(),
			'price'   => (string) $product->get_price_html(),
			'url'     => add_query_arg( 'add-to-cart', $product->get_id(), wc_get_checkout_url() ),
		);
	}

	/** The published product that sells priority support, or null. */
	public static function product(): ?\WC_Product {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => Product::META_DRIVER, 'meta_value' => PriorityDriver::ID ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One product.
		$product = $ids ? wc_get_product( (int) $ids[0] ) : null;

		return $product instanceof \WC_Product && $product->is_purchasable() ? $product : null;
	}

	/**
	 * Filter beltoft_services_has_priority_support.
	 *
	 * @param mixed $has     Answer so far.
	 * @param mixed $user_id User id.
	 */
	public static function filter( $has, $user_id = 0 ) {
		return (bool) $has || self::has( (int) $user_id );
	}

	/**
	 * Keeps the flag in step: written when the customer has the service, removed when not.
	 *
	 * @param mixed $r Resource.
	 */
	public static function changed( $r ): void {
		if ( $r instanceof Resource && PriorityDriver::ID === (string) $r->get( 'driver' ) ) {
			self::sync( (int) ( $r->license()['user_id'] ?? 0 ) );
		}
	}

	/**
	 * Writes or removes the flag of one customer.
	 *
	 * @param int $user_id User id.
	 */
	public static function sync( int $user_id ): void {
		if ( $user_id < 1 ) {
			return;
		}

		if ( self::has( $user_id ) ) {
			update_user_meta( $user_id, self::META, '1' );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * One service per customer: a second one is refused at add to cart.
	 *
	 * @param mixed $valid      Valid so far.
	 * @param mixed $product_id Product.
	 * @param mixed $quantity   Quantity.
	 */
	public static function validate( $valid, $product_id = 0, $quantity = 1 ): bool {
		$driver = $valid ? Product::driver_for( (int) $product_id ) : null;

		if ( ! $driver || PriorityDriver::ID !== $driver->id() ) {
			return (bool) $valid;
		}

		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'Log in to add priority support to your account.', 'beltoft-services' ), 'error' );
			return false;
		}

		if ( 1 !== (int) $quantity || self::has( get_current_user_id() ) ) {
			wc_add_notice( __( 'Your account has priority support already.', 'beltoft-services' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * A line on the WordPress profile (the page the support desk's customer link opens): staff sees the flag.
	 *
	 * @param mixed $user User.
	 */
	public static function profile( $user ): void {
		if ( ! $user instanceof \WP_User || ! current_user_can( 'edit_users' ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Support', 'beltoft-services' ) . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Priority support', 'beltoft-services' ) . '</th><td>';
		echo esc_html( self::has( (int) $user->ID ) ? self::promise() : __( 'No', 'beltoft-services' ) );
		echo '</td></tr></table>';
	}
}

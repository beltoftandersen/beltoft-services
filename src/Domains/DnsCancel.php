<?php
/**
 * A cancelled DNS-only subscription ends at once.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * DNS hosting for a domain registered elsewhere costs nothing, so there is no paid period to run
 * out. WooCommerce Subscriptions would hold the subscription in pending-cancel to the end of its
 * term; this moves it on to cancelled first (priority 5, before beltoft-licenses at 10), and the
 * records are switched off by the normal pause.
 */
final class DnsCancel {

	/**
	 * Subscriptions this class cancelled during the current event.
	 *
	 * @var array<int, bool>
	 */
	private static array $cancelled = array();

	/**
	 * Subscription meta: DNS hosting that was folded into a domain registration (the domain's resource id).
	 */
	public const FOLDED_META = '_beltoft_services_folded_into';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_subscription_status_pending-cancel', array( __CLASS__, 'on_pending_cancel' ), 5 );
		add_action( 'woocommerce_subscription_status_pending-cancel', array( __CLASS__, 'expire_after_licenses' ), 20 );
		add_filter( 'woocommerce_email_enabled_cancelled_subscription', array( __CLASS__, 'folded_email_enabled' ), 10, 2 );
	}

	/**
	 * The "Cancelled Subscription" admin email is not sent for DNS hosting that was folded into a domain
	 * registration: the cancel is the plugin's own and the subscription's order note says so.
	 *
	 * @param mixed $enabled      Whether the email is enabled.
	 * @param mixed $subscription Subscription.
	 */
	public static function folded_email_enabled( $enabled, $subscription = null ) {
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_meta' ) && (int) $subscription->get_meta( self::FOLDED_META, true ) > 0 ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Cancels the subscription now when it holds DNS hosting only and costs nothing.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function on_pending_cancel( $subscription ): void {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_items' ) || ! self::dns_only( $subscription ) ) {
			return;
		}

		$subscription->update_status( 'cancelled', __( 'DNS hosting ends at once when cancelled.', 'beltoft-services' ) );

		self::$cancelled[ (int) $subscription->get_id() ] = true;
	}

	/**
	 * Runs after beltoft-licenses has handled the same pending-cancel event (priority 10), which sets the
	 * licenses' expiry to the end of the term, over the "now" that the cancel gave them.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function expire_after_licenses( $subscription ): void {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
			return;
		}

		$id = (int) $subscription->get_id();

		if ( empty( self::$cancelled[ $id ] ) ) {
			return;
		}

		unset( self::$cancelled[ $id ] );
		self::expire_licenses( $id );
	}

	/**
	 * beltoft-licenses ends a cancelled subscription's licenses at "now", which still counts as active for
	 * that second. One second in the past makes them expired right away, through the licenses API, so its
	 * expiry action fires and the service is paused at once instead of at the next sweep.
	 *
	 * @param int $subscription_id Subscription.
	 */
	private static function expire_licenses( int $subscription_id ): void {
		if ( $subscription_id <= 0 || ! class_exists( Licenses::class ) ) {
			return;
		}

		foreach ( Licenses::where( 'subscription_id', $subscription_id ) as $license ) {
			if ( Licenses::STATUS_ACTIVE === $license['status'] ) {
				// Always strictly earlier than the stored expiry, so the update is a change and its action fires.
				$stored = empty( $license['expires_at'] ) ? time() : (int) strtotime( $license['expires_at'] . ' UTC' );
				Licenses::update( (int) $license['id'], array( 'expires_at' => min( $stored, time() ) - 1 ) );
			}
		}
	}

	/**
	 * Every line is a DNS hosting product and the total is 0.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private static function dns_only( $subscription ): bool {
		$items = $subscription->get_items();

		if ( ! $items || (float) $subscription->get_total() > 0 ) {
			return false;
		}

		foreach ( $items as $item ) {
			$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$driver     = Product::driver_for( $product_id );

			if ( ! $driver || 'dns' !== $driver->id() ) {
				return false;
			}
		}

		return true;
	}
}

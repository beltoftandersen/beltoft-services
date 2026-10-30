<?php
/**
 * The customer's one "Usage account" subscription.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftLicenses\Events;
use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Pay-as-you-go servers are billed on one WooCommerce Subscriptions subscription per customer: a hidden
 * subscription product of 0 per month, put in the cart with the first pay-as-you-go server (which makes
 * WooCommerce Subscriptions and the Stripe gateway save the card). Later pay-as-you-go orders attach to
 * it. The licenses of the usage servers carry the account's subscription id, so beltoft-licenses moves
 * them with it: on hold gives the grace period then expiry (Lifecycle pauses them), active again resumes
 * them, cancelled expires them (retention).
 */
final class Account {

	/**
	 * Subscription meta marking the account.
	 */
	public const META = '_beltoft_services_usage_account';

	/**
	 * Subscription meta, 'yes' once a usage charge has been paid.
	 */
	public const META_CHARGED = '_beltoft_services_usage_charged';

	/**
	 * Option holding the id of the hidden account product.
	 */
	public const OPTION_PRODUCT = 'beltoft_services_usage_product';

	/**
	 * Subscription statuses of an account that still exists.
	 */
	public const LIVE = array( 'active', 'pending', 'on-hold', 'pending-cancel' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_subscription_created', array( __CLASS__, 'flag' ), 10, 2 );
		// Before Lifecycle (10), so the license is on the account when its service is made.
		add_action( 'beltoft_licenses_license_created', array( __CLASS__, 'attach' ), 5 );
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'paid' ), 10, 2 );
	}

	/**
	 * The hidden account product, made when it does not exist.
	 */
	public static function product_id(): int {
		$id = (int) get_option( self::OPTION_PRODUCT );

		if ( $id > 0 && wc_get_product( $id ) instanceof \WC_Product ) {
			return $id;
		}

		if ( ! class_exists( \WC_Product_Subscription::class ) ) {
			return 0;
		}

		$product = new \WC_Product_Subscription();
		$product->set_name( __( 'Usage account', 'beltoft-services' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_tax_status( 'none' );
		$product->set_regular_price( '0' );
		$product->save();

		$meta = array(
			'_subscription_price'          => '0',
			'_subscription_period'         => 'month',
			'_subscription_period_interval' => '1',
			'_subscription_length'         => '0',
			'_subscription_sign_up_fee'    => '0',
			'_subscription_trial_length'   => '0',
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $product->get_id(), $key, $value );
		}

		update_option( self::OPTION_PRODUCT, $product->get_id(), false );

		return $product->get_id();
	}

	/**
	 * Is this subscription a usage account?
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function is_account( $subscription ): bool {
		return $subscription instanceof \WC_Subscription && 'yes' === $subscription->get_meta( self::META );
	}

	/**
	 * The customer's account that still exists, newest first.
	 *
	 * @param int      $user_id  Customer.
	 * @param string[] $statuses Subscription statuses that count.
	 */
	public static function for_user( int $user_id, array $statuses = self::LIVE ): ?\WC_Subscription {
		if ( $user_id < 1 || ! function_exists( 'wcs_get_subscriptions' ) ) {
			return null;
		}

		$found = wcs_get_subscriptions(
			array(
				'customer_id'            => $user_id,
				'subscription_status'    => $statuses,
				'subscriptions_per_page' => -1,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
			)
		);

		foreach ( $found as $subscription ) {
			if ( self::is_account( $subscription ) ) {
				return $subscription;
			}
		}

		return null;
	}

	/**
	 * Has a usage charge been paid on this account?
	 *
	 * @param \WC_Subscription|null $subscription Account.
	 */
	public static function charged( ?\WC_Subscription $subscription ): bool {
		return $subscription && 'yes' === $subscription->get_meta( self::META_CHARGED );
	}

	/**
	 * Does the account have a saved payment method WooCommerce Subscriptions charges by itself?
	 *
	 * @param \WC_Subscription $subscription Account.
	 */
	public static function has_card( \WC_Subscription $subscription ): bool {
		return '' !== (string) $subscription->get_payment_method() && ! $subscription->is_manual();
	}

	/**
	 * Marks the subscription made at checkout from the hidden product as the customer's account.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param \WC_Order        $order        Order.
	 */
	public static function flag( $subscription, $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}

		$product_id = (int) get_option( self::OPTION_PRODUCT );

		foreach ( $subscription->get_items() as $item ) {
			if ( $product_id > 0 && (int) $item->get_product_id() === $product_id ) {
				$subscription->update_meta_data( self::META, 'yes' );
				$subscription->save();
				return;
			}
		}
	}

	/**
	 * A license of a pay-as-you-go variation: source usage, on the customer's account (the one made by this
	 * license's order when it is the first). Licenses::update() cannot change the source, so it is written
	 * to the license row here, before any listener acts on it.
	 *
	 * @param mixed $license License row.
	 */
	public static function attach( $license ): void {
		if ( ! is_array( $license ) || (int) ( $license['variation_id'] ?? 0 ) < 1 || ! Rates::is_usage( (int) $license['variation_id'] ) ) {
			return;
		}

		global $wpdb;

		if ( 'usage' !== $license['source'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the license table has no API for the source.
			$wpdb->update( Install::tables()['licenses'], array( 'source' => 'usage' ), array( 'id' => (int) $license['id'] ) );
		}

		$account = self::for_user( (int) ( $license['user_id'] ?? 0 ) );

		if ( ! $account && (int) ( $license['order_id'] ?? 0 ) > 0 && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			foreach ( wcs_get_subscriptions_for_order( (int) $license['order_id'], array( 'order_type' => 'parent' ) ) as $candidate ) {
				if ( self::is_account( $candidate ) ) {
					$account = $candidate;
					break;
				}
			}
		}

		if ( ! $account ) {
			Events::add( (int) $license['id'], 'service', 'Pay as you go license without a usage account' );
			return;
		}

		Licenses::update( (int) $license['id'], array( 'subscription_id' => $account->get_id() ) );
	}

	/**
	 * A renewal was paid. When it billed usage, the account has had its first successful usage charge.
	 *
	 * @param \WC_Subscription $subscription Account.
	 * @param \WC_Order|null   $order        Renewal order.
	 */
	public static function paid( $subscription, $order = null ): void {
		if ( ! self::is_account( $subscription ) || ! $order instanceof \WC_Order || (float) $order->get_meta( Renewal::META_BILLED ) <= 0 ) {
			return;
		}

		$subscription->update_meta_data( self::META_CHARGED, 'yes' );
		$subscription->save();
	}

	/**
	 * Statuses of an account whose first order was paid (a pending one never was).
	 */
	public const ESTABLISHED = array( 'active', 'on-hold', 'pending-cancel' );

	/**
	 * Usage resources of a customer that exist (not deleted, not failed), by activation id.
	 *
	 * @param int $user_id Customer.
	 * @return int[]
	 */
	public static function resources( int $user_id ): array {
		$ids = array();

		foreach ( Licenses::where( 'user_id', $user_id ) as $license ) {
			if ( 'usage' !== $license['source'] ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( $resource && ! in_array( $resource->get( 'state' ), array( 'deleted', 'failed' ), true ) ) {
				$ids[] = $resource->id();
			}
		}

		return $ids;
	}
}

<?php
/**
 * Where an order paid from the dashboard leads after the hand-off screen: the page of the service it was for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;
use BeltoftServices\ThankYou;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the filter beltoft_services_order_handoff_target (OrderHandoff) for three kinds of order that come from the
 * account area: a hosting add-on order (its meta names the account), a renewal, switch or resubscribe payment (its
 * subscription's service) and an add-on licence bought for a server (a disk, a snapshot pack, an app licence: the server).
 * A new order of its own services is not answered here: it goes to the overview.
 */
final class OrderTarget {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_order_handoff_target', array( __CLASS__, 'target' ), 10, 2 );
	}

	/**
	 * The page of the service the order was for, or what was found before.
	 *
	 * @param mixed $url   Address so far.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function target( $url, $order = null ) {
		if ( '' !== (string) $url || ! $order instanceof \WC_Order ) {
			return $url;
		}

		$license = self::license_for( $order );

		return $license ? OverviewData::follow_url( $license ) : $url;
	}

	/**
	 * The licence row of the service the order was for, or null.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>|null
	 */
	public static function license_for( \WC_Order $order ): ?array {
		$named = self::ask( $order, 'meta' );

		if ( null !== $named ) {
			return $named ?: null;
		}

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) && function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, array( 'renewal', 'switch', 'resubscribe' ) ) ) {
			foreach ( array_keys( wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'renewal', 'switch', 'resubscribe' ) ) ) ) as $subscription_id ) {
				foreach ( Licenses::where( 'subscription_id', (int) $subscription_id ) as $license ) {
					if ( Resource::for_license( (int) $license['id'] ) ) {
						return $license;
					}
				}
			}

			return null;
		}

		// An add-on line names its server in its own line meta (set at add to cart), so the page is known before the add-on's service exists.
		$by_line = self::ask( $order, 'lines' );

		if ( null !== $by_line ) {
			return $by_line ?: null;
		}

		$resources = ThankYou::resources( $order );

		if ( ! $resources ) {
			return null;
		}

		$parent = 0;

		foreach ( $resources as $resource ) {
			$link = (int) $resource->get( 'server_license' );

			if ( $link < 1 || ( $parent > 0 && $parent !== $link ) ) {
				return null;
			}

			$parent = $link;
		}

		$server = Licenses::find( $parent );

		return $server && (int) $server['user_id'] === (int) $order->get_customer_id() ? $server : null;
	}

	/**
	 * What a feature knows of the order: asked at two places of license_for(), 'meta' (before the renewal check: an order whose own meta
	 * names the service) and 'lines' (after it: add-on lines that name their server).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $phase  'meta' or 'lines'.
	 * @return array<string, mixed>|false|null The licence row, false when the feature found that the order leads to no single service, null when it has no answer.
	 */
	private static function ask( \WC_Order $order, string $phase ) {
		/**
		 * Filters the licence row of the service an order from the account area was for. Null is no answer, false is "no single service".
		 *
		 * @param array<string, mixed>|false|null $found Answer so far.
		 * @param \WC_Order                       $order Order.
		 * @param string                          $phase 'meta' or 'lines'.
		 */
		return apply_filters( 'beltoft_services_order_target_license', null, $order, $phase );
	}
}

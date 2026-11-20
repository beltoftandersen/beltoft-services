<?php
/**
 * Where an order paid from the dashboard leads after the hand-off screen: the page of the service it was for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftLicenses\Licenses;
use BeltoftServices\Hosting\Addons;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
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
		$meta = $order->get_meta( Addons::ORDER_META );

		if ( is_array( $meta ) && (int) ( $meta['resource'] ?? 0 ) > 0 ) {
			$r = Resource::find( (int) $meta['resource'] );

			return $r ? $r->license() : null;
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
		$by_line = self::server_from_lines( $order );

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
	 * The server the add-on lines of the order are for, read from the lines' own meta: the licence row of that server, false when the
	 * lines point to no single server of the customer (a mix, or another customer's), null when no line is an add-on line.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>|false|null
	 */
	private static function server_from_lines( \WC_Order $order ) {
		$parent = 0;
		$linked = 0;
		$other  = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! Product::is_service( (int) ( $item->get_variation_id() ?: $item->get_product_id() ) ) ) {
				continue;
			}

			$link = 0;

			foreach ( Addon::all() as $addon ) {
				$value = $item->get_meta( $addon->item_server(), true );

				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$link = (int) $value;
					break;
				}
			}

			if ( $link < 1 ) {
				++$other;
				continue;
			}

			if ( $parent > 0 && $parent !== $link ) {
				return false;
			}

			$parent = $link;
			++$linked;
		}

		if ( 0 === $linked ) {
			return null;
		}

		$server = 0 === $other ? Licenses::find( $parent ) : null;

		return $server && (int) $server['user_id'] === (int) $order->get_customer_id() ? $server : false;
	}
}

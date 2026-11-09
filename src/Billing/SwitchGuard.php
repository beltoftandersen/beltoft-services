<?php
/**
 * Which resources a subscription switch is about.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The switch checks (servers, disks, databases, email, hosting) all look at the resources of one subscription that
 * belong to the line item being switched. This is that lookup, so each check keeps only its own comparisons.
 */
final class SwitchGuard {

	/**
	 * The product of the line item being switched; 0 when the item is not an object that can tell.
	 *
	 * @param mixed $item The line item switched.
	 */
	public static function item_product( $item ): int {
		return is_object( $item ) && method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
	}

	/**
	 * The resources of the driver on the subscription's licences, in licence order. Licences of another product than
	 * the switched item are skipped (all are kept when the item names none), and with a target product given only
	 * licences of that product stay. A licence without a resource, or with one of another driver, is skipped.
	 *
	 * @param int    $subscription_id Subscription.
	 * @param mixed  $item            The line item switched.
	 * @param string $driver_id       Driver id the resources must have.
	 * @param int    $product_id      Only licences of this product when above 0.
	 * @return array<int, Resource>
	 */
	public static function resources( int $subscription_id, $item, string $driver_id, int $product_id = 0 ): array {
		$item_product = self::item_product( $item );
		$found        = array();

		foreach ( Licenses::where( 'subscription_id', $subscription_id ) as $license ) {
			$licensed = (int) ( $license['product_id'] ?? 0 );

			if ( ( $item_product > 0 && $licensed !== $item_product ) || ( $product_id > 0 && $licensed !== $product_id ) ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || $driver_id !== $resource->get( 'driver' ) ) {
				continue;
			}

			$found[] = $resource;
		}

		return $found;
	}
}

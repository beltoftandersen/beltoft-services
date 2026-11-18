<?php
/**
 * The order line that paid for a backup box, recorded when the service is made.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Checkout\LineField;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The spend guard ({@see StorageSpendGuard::problem()}) reads the paid line through `order_item_id` and refuses to buy a box
 * without it. A new storage resource takes the first line of its order and product (and variation) that no sibling storage
 * resource of the same order holds, the same way licence keys and SSL certificates do ({@see LineField::siblings()}).
 * Without this the first live order failed at "Checking the order" on 2026-11-18 (nothing was bought).
 */
final class OrderLine {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 30, 3 );
	}

	/**
	 * Gives a new storage resource its order line.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || StorageDriver::ID !== $driver->id() || ! empty( $meta['order_item_id'] ) ) {
			return $meta;
		}

		$sibling = LineField::siblings( $license, StorageDriver::ID, static fn( Resource $r ) => (int) $r->get( 'order_item_id' ) );

		if ( null === $sibling ) {
			return $meta;
		}

		foreach ( $sibling['items'] as $item ) {
			if ( ! in_array( (int) $item->get_id(), $sibling['used'], true ) ) {
				$meta['order_item_id'] = (int) $item->get_id();
				break;
			}
		}

		return $meta;
	}
}

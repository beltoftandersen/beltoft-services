<?php
/**
 * Gives a new dedicated server resource its paid order line (read by the spend guard).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Checkout\LineField;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class OrderLine {

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 30, 3 );
	}

	/**
	 * @param mixed $meta    Meta so far.
	 * @param mixed $license License row.
	 * @param mixed $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || ManualServerDriver::ID !== $driver->id() || ! empty( $meta['order_item_id'] ) ) {
			return $meta;
		}

		$sibling = LineField::siblings( $license, ManualServerDriver::ID, static fn( Resource $r ) => (int) $r->get( 'order_item_id' ) );

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

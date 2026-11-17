<?php
/**
 * Plan changes of a backup storage subscription: upgrades only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Billing\SwitchGuard;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * A smaller box, or one whose cost is not in the cost table, is refused before payment with WooCommerce Subscriptions' own
 * filter. The driver's update_plan never lowers a box either (BoxLifecycle::update_plan), and passes the spend guard.
 */
final class StorageSwitch {

	public static function register(): void {
		add_filter( 'woocommerce_subscriptions_switch_error_message', array( __CLASS__, 'check' ), 10, 6 );
	}

	/**
	 * A non-empty message makes the switch invalid.
	 *
	 * @param mixed $message      Message so far.
	 * @param mixed $product_id   Target product.
	 * @param mixed $quantity     Quantity (unused).
	 * @param mixed $variation_id Target variation.
	 * @param mixed $subscription WC_Subscription.
	 * @param mixed $item         The line item switched.
	 * @return mixed
	 */
	public static function check( $message, $product_id = 0, $quantity = 1, $variation_id = 0, $subscription = null, $item = null ) {
		unset( $quantity );

		if ( ! empty( $message ) || ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
			return $message;
		}

		$driver = Product::driver_for( (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id );

		if ( ! $driver || StorageDriver::ID !== $driver->id() ) {
			return $message;
		}

		$plan = Product::plan( (int) $product_id, (int) $variation_id > 0 ? (int) $variation_id : null );
		$type = strtolower( (string) ( $plan['storage_type'] ?? '' ) );

		if ( null === StorageSettings::cost( $type ) ) {
			return __( 'This storage size cannot be ordered right now.', 'beltoft-services' );
		}

		foreach ( SwitchGuard::resources( (int) $subscription->get_id(), $item, StorageDriver::ID ) as $resource ) {
			$have = (int) $resource->get( 'storage_size_bytes' );
			$want = (int) ( $plan['storage_size_gb'] ?? 0 ) * 1000000000;

			if ( $have > 0 && $want > 0 && $want < $have ) {
				return __( "Backup storage can't be made smaller. Choose a size at least as large as you have now.", 'beltoft-services' );
			}
		}

		return $message;
	}
}

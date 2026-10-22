<?php
/**
 * Refuses a subscription switch to a plan with a smaller disk than the customer's server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * WCS applies a switch by changing the license's variation. A disk is never shrunk, so a
 * downgrade of the disk would be paid for and then not applied: it is refused before payment, with
 * WCS's own filter `woocommerce_subscriptions_switch_error_message` (WooCommerce Subscriptions
 * includes/switching/class-wc-subscriptions-switcher.php, applied in validate_switch_request()).
 * Plan A's Checkout skips switch carts, so this is its own class.
 */
final class Switches {

	/**
	 * Hooks the filter.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_subscriptions_switch_error_message', array( self::class, 'check' ), 10, 6 );
	}

	/**
	 * The filter: a non-empty message makes the switch invalid.
	 *
	 * @param mixed $message      Message so far.
	 * @param mixed $product_id   Target product.
	 * @param mixed $quantity     Quantity.
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

		try {
			$target = Product::plan( (int) $product_id, (int) $variation_id > 0 ? (int) $variation_id : null );

			if ( ! isset( $target['disk_gb'] ) || 'proxmox' !== ( Product::driver_for( (int) $product_id ) ? Product::driver_for( (int) $product_id )->id() : '' ) ) {
				return $message;
			}

			$item_product = ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
			$biggest      = 0;

			foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
				if ( $item_product > 0 && (int) ( $license['product_id'] ?? 0 ) !== $item_product ) {
					continue;
				}

				$resource = Resource::for_license( (int) $license['id'] );

				if ( ! $resource || SshKeyField::DRIVER !== $resource->get( 'driver' ) ) {
					continue;
				}

				$plan    = (array) $resource->get( 'plan' );
				$biggest = max( $biggest, (int) ( $plan['disk_gb'] ?? 0 ), (int) $resource->get( 'disk_gb' ) );
			}

			if ( $biggest > (int) $target['disk_gb'] ) {
				/* translators: %d: disk size in GB */
				return sprintf( __( 'This plan has a smaller disk than your server. Choose a plan with at least %d GB.', 'beltoft-services' ), $biggest );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Cannot tell: let the switch through; update_plan keeps the disk anyway.
		}

		return $message;
	}
}

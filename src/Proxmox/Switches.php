<?php
/**
 * Refuses a subscription switch to a plan smaller than the customer's server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * WCS applies a switch by changing the license's variation. Servers are never downgraded (and a
 * disk is never shrunk, so it would be paid for and then not applied): a plan with less disk, fewer
 * cores or less memory than the server has now is refused before payment, with
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
			$target_driver = Product::driver_for( (int) $product_id );
			if ( $target_driver && DiskDriver::ID === $target_driver->id() ) {
				$item_product = ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
				$found = false;
				foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
					if ( $item_product > 0 && (int) ( $license['product_id'] ?? 0 ) !== $item_product ) {
						continue;
					}
					$disk = Resource::for_license( (int) $license['id'] );
					if ( ! $disk || DiskDriver::ID !== $disk->get( 'driver' ) ) {
						continue;
					}
					$found = true;
					$plan = (array) $disk->get( 'plan' );
					$have = max( (int) ( $plan['disk_gb'] ?? 0 ), (int) $disk->get( 'size_gb' ) );
					if ( $have > (int) ( $target['disk_gb'] ?? 0 ) ) {
						return __( "Disks can't be made smaller. Choose a size at least as large as your current disk.", 'beltoft-services' );
					}
				}
				return $found ? $message : __( 'The disk for this subscription could not be found.', 'beltoft-services' );
			}

			if ( $target_driver && DbDriver::ID === $target_driver->id() ) {
				return DbIp::switch_error( (int) $subscription->get_id(), (int) $product_id, $target, $item );
			}

			if ( 'proxmox' !== ( Product::driver_for( (int) $product_id ) ? Product::driver_for( (int) $product_id )->id() : '' ) ) {
				return $message;
			}

			$item_product = ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
			$have         = array(
				'disk_gb'   => 0,
				'cores'     => 0,
				'memory_mb' => 0,
			);

			foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
				if ( $item_product > 0 && (int) ( $license['product_id'] ?? 0 ) !== $item_product ) {
					continue;
				}

				$resource = Resource::for_license( (int) $license['id'] );

				if ( ! $resource || SshKeyField::DRIVER !== $resource->get( 'driver' ) ) {
					continue;
				}

				$plan              = (array) $resource->get( 'plan' );
				$have['disk_gb']   = max( $have['disk_gb'], (int) ( $plan['disk_gb'] ?? 0 ), (int) $resource->get( 'disk_gb' ) );
				$have['cores']     = max( $have['cores'], (int) ( $plan['cores'] ?? 0 ) );
				$have['memory_mb'] = max( $have['memory_mb'], (int) ( $plan['memory_mb'] ?? 0 ) );
			}

			// A dimension the target plan does not state (or the server's record lacks) cannot be compared: let it through.
			foreach ( $have as $key => $now ) {
				if ( isset( $target[ $key ] ) && $now > 0 && (int) $target[ $key ] > 0 && $now > (int) $target[ $key ] ) {
					return __( "Servers can't be downgraded. Choose a plan at least as large as your current one.", 'beltoft-services' );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Cannot tell: let the switch through; update_plan keeps the disk anyway.
		}

		return $message;
	}
}

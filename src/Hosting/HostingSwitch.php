<?php
/**
 * Plan changes of a hosting subscription: upgrades only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions applies a switch by changing the license's variation, and Lifecycle then takes the new
 * plan (HostingDriver::update_plan applies the limits and grows the disk, it never shrinks it: a container disk
 * cannot shrink). So, like servers and email plans, a hosting plan is never made smaller: a plan with less disk,
 * memory, CPU or PHP workers than the account has now, or with fewer websites than it has, or on another node
 * group, is refused before payment through WCS's filter `woocommerce_subscriptions_switch_error_message`. A
 * downgrade is a manual move by support.
 */
final class HostingSwitch {

	/** Plan values that may not go down, with the words for the customer. */
	private const KEYS = array( 'disk_gb', 'memory_mb', 'cores', 'php_workers' );

	public static function register(): void {
		add_filter( 'woocommerce_subscriptions_switch_error_message', array( __CLASS__, 'check' ), 10, 6 );
	}

	/**
	 * The filter: a non-empty message makes the switch invalid.
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

		if ( ! empty( $message ) || ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) || ! SiteField::is_hosting_product( (int) $product_id ) ) {
			return $message;
		}

		try {
			$target       = Plan::typed( Product::plan( (int) $product_id, (int) $variation_id > 0 ? (int) $variation_id : null ) );
			$item_product = ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;

			foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
				if ( $item_product > 0 && (int) ( $license['product_id'] ?? 0 ) !== $item_product ) {
					continue;
				}

				$resource = Resource::for_license( (int) $license['id'] );

				if ( ! $resource || HostingDriver::ID !== $resource->get( 'driver' ) ) {
					continue;
				}

				$refusal = self::refusal( $resource, $target );

				if ( null !== $refusal ) {
					return $refusal;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Cannot tell: let it through; update_plan never shrinks a disk.
		}

		return $message;
	}

	/**
	 * Why the target plan is too small for the account, or null.
	 *
	 * @param Resource                       $resource The hosting account.
	 * @param array<string, int|string|bool> $target   Typed target plan.
	 */
	public static function refusal( Resource $resource, array $target ): ?string {
		$have = Plan::typed( (array) $resource->get( 'plan' ) );

		if ( (string) $have['node_group'] !== (string) $target['node_group'] ) {
			return __( 'This plan is on a different server group. Contact support to move your hosting.', 'beltoft-services' );
		}

		foreach ( self::KEYS as $key ) {
			if ( (int) $have[ $key ] > (int) $target[ $key ] ) {
				return __( "Hosting plans can't be made smaller. Choose a plan with at least as much storage, memory, CPU and PHP workers as you have now, or contact support to move to a smaller plan.", 'beltoft-services' );
			}
		}

		if ( count( Sites::for_resource( $resource->id() ) ) > (int) $target['sites'] ) {
			return __( 'This plan has fewer websites than you have now. Remove a website first, or choose a larger plan.', 'beltoft-services' );
		}

		return null;
	}
}

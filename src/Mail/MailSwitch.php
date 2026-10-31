<?php
/**
 * Plan changes of a Business email subscription: upgrades only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions applies a switch by changing the license's variation, and Lifecycle then takes the new plan
 * (MailDriver::update_plan raises limits and never lowers them). Like servers and storage, an email plan is never
 * made smaller: a plan with fewer mailboxes, aliases, domains or a smaller mailbox size than the domain has now is
 * refused before payment, with WCS's own filter `woocommerce_subscriptions_switch_error_message`. The limits applied
 * are never below what is in use, so this also covers "fewer than in use".
 */
final class MailSwitch {

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

		if ( ! empty( $message ) || ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) || ! MailField::is_mail_product( (int) $product_id ) ) {
			return $message;
		}

		try {
			$target = MailDriver::limits_from( Product::plan( (int) $product_id, (int) $variation_id > 0 ? (int) $variation_id : null ) );
			$item_product = ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;

			foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
				if ( $item_product > 0 && (int) ( $license['product_id'] ?? 0 ) !== $item_product ) {
					continue;
				}

				$resource = Resource::for_license( (int) $license['id'] );

				if ( ! $resource || MailDriver::ID !== $resource->get( 'driver' ) ) {
					continue;
				}

				$have = MailGuard::limits( $resource );

				foreach ( array( 'mailboxes', 'quota_mb', 'aliases', 'domains' ) as $key ) {
					if ( (int) ( $have[ $key ] ?? 0 ) > $target[ $key ] ) {
						return __( "Email plans can't be made smaller. Choose a plan with at least as many mailboxes, as much storage and as many aliases as you have now.", 'beltoft-services' );
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Cannot tell: let it through; update_plan never lowers a limit.
		}

		return $message;
	}
}

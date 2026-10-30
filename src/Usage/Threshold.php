<?php
/**
 * Billing a usage account early, when its unbilled usage passes a threshold.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions' early renewal does not fit: wcs_can_user_renew_early() refuses a subscription
 * with a total of 0 (the usage account is one), and the early renewal is a cart the customer pays. So the
 * renewal order is made by the same function a scheduled renewal uses (wcs_create_renewal_order(), which runs
 * our line builder and claims the rows) and charged through the gateway hook a scheduled payment uses
 * (WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook()). The subscription's dates and
 * status are not touched; a payment that fails puts it on hold like any failed renewal. Runs after the hourly meter.
 * The rows are claimed by the order, so the next run finds nothing until more usage has accrued.
 */
final class Threshold {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( Meter::HOOK, array( __CLASS__, 'run' ), 20 );
	}

	/**
	 * The threshold for an account.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function for_account( \WC_Subscription $account ): float {
		return (float) Settings::get( Account::charged( $account ) ? 'usage_threshold' : 'usage_threshold_new' );
	}

	/**
	 * Checks every customer with unbilled usage.
	 *
	 * @return int Early renewals made.
	 */
	public static function run(): int {
		$made = 0;

		foreach ( Ledger::open_users() as $user_id ) {
			$account = Account::for_user( $user_id, array( 'active' ) );

			if ( $account && self::check( $account ) ) {
				++$made;
			}
		}

		return $made;
	}

	/**
	 * Bills one account early when its usage has reached its threshold.
	 *
	 * @param \WC_Subscription $account Account.
	 * @return bool Whether an order was made.
	 */
	public static function check( \WC_Subscription $account ): bool {
		if ( ! Account::has_card( $account ) ) {
			return false;
		}

		// An invoice that still waits for its payment is not piled on.
		$last = $account->get_last_order( 'all', 'renewal' );

		if ( $last instanceof \WC_Order && $last->needs_payment() ) {
			return false;
		}

		$accrued = Renewal::statement( Ledger::unbilled( (int) $account->get_customer_id(), Ledger::hour( time() ) ) )['total'];

		if ( $accrued <= 0 || $accrued < self::for_account( $account ) ) {
			return false;
		}

		$order = wcs_create_renewal_order( $account );

		if ( is_wp_error( $order ) || ! $order instanceof \WC_Order ) {
			return false;
		}

		// A scheduled renewal sets the payment method the same way (WC_Subscriptions_Manager::process_renewal()).
		$order->set_payment_method( $account->get_payment_method() );
		$order->update_meta_data( Renewal::META_EARLY, 'yes' );
		$order->save();
		$order->add_order_note( __( 'Usage billed early: the unbilled usage reached the threshold.', 'beltoft-services' ) );

		if ( $order->get_total() > 0 && $order->get_payment_method() ) {
			\WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $order );
		} elseif ( 0.0 === (float) $order->get_total() ) {
			$order->payment_complete();
		}

		return true;
	}
}

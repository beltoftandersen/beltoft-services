<?php
/**
 * Billing a usage account early, when its unbilled usage passes a threshold.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Billing\Subscriptions;
use BeltoftServices\Alerts;
use BeltoftServices\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions' early renewal does not fit: wcs_can_user_renew_early() refuses a subscription
 * with a total of 0 (the usage account is one), and the early renewal is a cart the customer pays. So the
 * renewal order is made by the same function a scheduled renewal uses (wcs_create_renewal_order(), which runs
 * our line builder and claims the rows) and charged through the gateway hook a scheduled payment uses
 * (Billing\Subscriptions::charge_now()). The subscription's dates and
 * status are not touched; a payment that fails puts it on hold like any failed renewal. Runs after the hourly meter.
 * The rows are claimed by the order, so the next run finds nothing until more usage has accrued.
 */
final class Threshold {

	public const META_HOLD = '_beltoft_services_usage_early_hold';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( Meter::HOOK, array( __CLASS__, 'run' ), 20 );
	}

	/**
	 * The threshold for an account, in the account's currency. Null when that currency has no amount for it: no early bill.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function for_account( \WC_Subscription $account ): ?float {
		$value = Currency::amount( Account::charged( $account ) ? 'usage_threshold' : 'usage_threshold_new', (string) $account->get_currency() );

		return null === $value ? null : (float) $value;
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

		if ( $accrued <= 0 ) {
			return false;
		}

		$threshold = self::for_account( $account );

		// A currency with no threshold amount gets no early bill; the monthly renewal still bills the usage.
		if ( null === $threshold ) {
			$currency = (string) $account->get_currency();

			Alerts::notify(
				/* translators: %s: currency code */
				sprintf( __( 'No early bill threshold for %s', 'beltoft-services' ), $currency ),
				array( sprintf( 'The settings usage_threshold and usage_threshold_new have no amount in %s, so usage in that currency is billed at the monthly renewal only.', $currency ) ),
				null,
				'beltoft_services_usage_threshold_' . strtolower( $currency )
			);

			return false;
		}

		if ( $accrued < $threshold ) {
			return false;
		}

		// An early bill was just dropped for being under the minimum: not tried again for a day.
		if ( (int) $account->get_meta( self::META_HOLD ) > time() - DAY_IN_SECONDS ) {
			return false;
		}

		Renewal::$early = true;

		try {
			$order = wcs_create_renewal_order( $account );
		} finally {
			// A throw must not leave the flag set for the rest of the request.
			Renewal::$early = false;
		}

		if ( is_wp_error( $order ) || ! $order instanceof \WC_Order ) {
			return false;
		}

		// Under the minimum for the card: nothing is billed early; the rows stay for the monthly renewal.
		if ( '' !== (string) $order->get_meta( Minimum::META_ORDER ) ) {
			$order->delete( true );
			$account->update_meta_data( self::META_HOLD, (string) time() );
			$account->save();

			return false;
		}

		// A scheduled renewal sets the payment method the same way (WC_Subscriptions_Manager::process_renewal()).
		$order->set_payment_method( $account->get_payment_method() );
		$order->update_meta_data( Renewal::META_EARLY, 'yes' );
		$order->save();
		$order->add_order_note( __( 'Usage billed early: the unbilled usage reached the threshold.', 'beltoft-services' ) );

		if ( $order->get_total() > 0 && $order->get_payment_method() ) {
			Subscriptions::charge_now( $order );
		} elseif ( 0.0 === (float) $order->get_total() ) {
			$order->payment_complete();
		}

		return true;
	}
}

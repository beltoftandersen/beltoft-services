<?php
/**
 * Account credit paid onto Pay as you go usage invoices.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Alerts;
use BeltoftServices\Usage\Renewal;

defined( 'ABSPATH' ) || exit;

/**
 * A usage invoice is the usage account's renewal order (Usage\Renewal) or an early bill (Usage\Threshold, which builds
 * it the same way). Once its usage lines and taxes are final, a customer with credit gets a non-taxable negative fee,
 * "Paid from account credit", of min(balance, order total incl. VAT). Credit is a payment, not a discount: the VAT
 * on the usage lines is unchanged. The balance is debited (kind invoice, reference the order id) under the balance
 * lock in the same request, once per order. An order that is later cancelled, refunded or fails gives the credit back
 * once; a failed order also loses the fee line, so it is payable in full.
 *
 * A total of 0 after credit is a paid invoice: WooCommerce Subscriptions asks no gateway to charge it, and
 * {@see Invoice::settle()} completes it on the scheduled payment hook.
 */
final class Invoice {

	public const META_USED = '_beltoft_services_credit_used';
	public const META_FEE  = '_beltoft_services_credit_fee';

	public const REF_TYPE   = 'order';
	public const REF_RETURN = 'order_return';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_scheduled_subscription_payment', array( __CLASS__, 'settle_latest' ), 30 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'give_back' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'give_back' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'failed' ) );
		add_action( 'woocommerce_order_before_calculate_totals', array( __CLASS__, 'before_recalculate' ), 10, 2 );
		add_action( 'woocommerce_order_after_calculate_totals', array( __CLASS__, 'after_recalculate' ), 10, 2 );
	}

	/**
	 * The credit fee of an order, if it has one.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function fee_of( \WC_Order $order ): ?\WC_Order_Item_Fee {
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( $fee instanceof \WC_Order_Item_Fee && 'yes' === $fee->get_meta( self::META_FEE ) ) {
				return $fee;
			}
		}

		return null;
	}

	/**
	 * An admin recalculation (Recalculate in the order screen) would treat the credit fee as a discount: take VAT off
	 * and cap it at the net total. So the fee is set to 0 while WooCommerce calculates, and put back after.
	 *
	 * @param mixed $and_taxes Whether taxes are calculated.
	 * @param mixed $order     Order.
	 */
	public static function before_recalculate( $and_taxes, $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$fee = $order instanceof \WC_Order ? self::fee_of( $order ) : null;

		if ( $fee && self::used( $order ) > 0 ) {
			$fee->set_total( '0' );
		}
	}

	/**
	 * Puts the credit fee back and takes the credit off the recalculated total.
	 *
	 * @param mixed $and_taxes Whether taxes are calculated.
	 * @param mixed $order     Order.
	 */
	public static function after_recalculate( $and_taxes, $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$fee  = $order instanceof \WC_Order ? self::fee_of( $order ) : null;
		$used = $order instanceof \WC_Order ? self::used( $order ) : 0;

		if ( ! $fee || $used < 1 ) {
			return;
		}

		$fee->set_total( number_format( -$used / 100, 2, '.', '' ) );
		$order->set_total( number_format( max( 0, Balance::cents( $order->get_total() ) - $used ) / 100, 2, '.', '' ) );
	}

	/**
	 * Applies credit to an invoice whose lines, taxes and totals are final. Does not save the order.
	 *
	 * @param \WC_Order $order   The invoice.
	 * @param int       $user_id The customer.
	 * @return int Cents applied (0: none).
	 */
	public static function apply( \WC_Order $order, int $user_id ): int {
		if ( ! Config::enabled() || $user_id < 1 || (int) $order->get_id() < 1 || '' !== (string) $order->get_meta( self::META_USED ) ) {
			return 0;
		}

		$currency = (string) $order->get_currency();

		$wrong = '';

		list( $locked, $used ) = Balance::locked(
			$user_id,
			static function () use ( $order, $user_id, $currency, &$wrong ): int {
				$due     = Balance::cents( $order->get_total() );
				// Only credit in the order's own currency is applied: a balance in another currency is never used on this invoice.
				$balance = Balance::get_in( $user_id, $currency );
				$cents   = min( $balance, $due );

				if ( $balance < 1 && Balance::currency( $user_id ) !== '' && Balance::currency( $user_id ) !== $currency && Balance::get( $user_id ) > 0 ) {
					$wrong = sprintf( 'Customer %1$d has credit in %2$s and invoice #%3$s is in %4$s. The credit was not applied.', $user_id, Balance::currency( $user_id ), $order->get_order_number(), $currency );
				}

				if ( $cents < 1 || Balance::exists( Balance::INVOICE, self::REF_TYPE, (string) $order->get_id() ) ) {
					return 0;
				}

				$debited = Balance::debit(
					$user_id,
					$cents,
					Balance::INVOICE,
					self::REF_TYPE,
					(string) $order->get_id(),
					/* translators: %s: order number. */
					sprintf( __( 'Used on invoice #%s', 'beltoft-services' ), $order->get_order_number() ),
					$currency
				);

				return $debited ? $cents : 0;
			}
		);

		// Told after the balance lock is released.
		if ( '' !== $wrong ) {
			Alerts::notify(
				/* translators: %d: customer id */
				sprintf( __( 'Credit in another currency than the invoice, customer %d', 'beltoft-services' ), $user_id ),
				array( $wrong ),
				null,
				'beltoft_services_credit_wrongcur_' . $user_id
			);
		}

		if ( ! $locked || ! is_int( $used ) || $used < 1 ) {
			return 0;
		}

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( __( 'Paid from account credit', 'beltoft-services' ) );
		$fee->set_tax_status( 'none' );
		$fee->set_tax_class( '' );
		$fee->set_total( number_format( -$used / 100, 2, '.', '' ) );
		$fee->add_meta_data( self::META_FEE, 'yes', true );
		$order->add_item( $fee );
		$order->update_meta_data( self::META_USED, (string) $used );
		// calculate_totals() is not used: it treats a negative fee as a discount, takes VAT off with it and refuses a
		// fee larger than the net total, so credit could never pay the VAT part. The lines and tax lines stay as they are.
		$order->set_total( number_format( ( Balance::cents( $order->get_total() ) - $used ) / 100, 2, '.', '' ) );
		$order->add_order_note( sprintf( /* translators: %s: amount. */ __( 'Paid from account credit: %s.', 'beltoft-services' ), Topup::label_amount( $used, $order->get_currency() ) ) );

		return $used;
	}

	/**
	 * Cents of credit used on an order (0: none or given back already).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function used( \WC_Order $order ): int {
		return (int) $order->get_meta( self::META_USED );
	}

	/**
	 * After the scheduled payment hook has run (WooCommerce Subscriptions' own handlers run first), a usage invoice
	 * that credit paid in full is completed. WooCommerce Subscriptions does not charge a total of 0 and leaves it pending.
	 *
	 * @param mixed $subscription_id Subscription id.
	 */
	public static function settle_latest( $subscription_id ): void {
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : false;

		if ( $subscription ) {
			$order = $subscription->get_last_order( 'all', 'renewal' );

			if ( $order instanceof \WC_Order ) {
				self::settle( $order );
			}
		}
	}

	/**
	 * Completes an invoice credit paid in full.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether it was completed now.
	 */
	public static function settle( \WC_Order $order ): bool {
		if ( self::used( $order ) < 1 || (float) $order->get_total() > 0 || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return false;
		}

		$order->add_order_note( __( 'The invoice was paid in full from account credit.', 'beltoft-services' ) );

		return (bool) $order->payment_complete();
	}

	/**
	 * Gives the credit used on an order back, once.
	 *
	 * @param mixed $order_id Order id.
	 * @return int Cents returned now.
	 */
	public static function give_back( $order_id ): int {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || ! $order->get_customer_id() ) {
			return 0;
		}

		$was = Balance::find( Balance::INVOICE, self::REF_TYPE, (string) $order->get_id() );

		if ( ! $was || ! Balance::credit( (int) $order->get_customer_id(), abs( (int) $was['amount_cents'] ), Balance::ADJUST, self::REF_RETURN, (string) $order->get_id(), /* translators: %s: order number. */ sprintf( __( 'Returned from invoice #%s', 'beltoft-services' ), $order->get_order_number() ), (string) $order->get_currency() ) ) {
			return 0;
		}

		$order->add_order_note( sprintf( /* translators: %s: amount. */ __( 'Account credit returned: %s.', 'beltoft-services' ), Topup::label_amount( abs( (int) $was['amount_cents'] ), $order->get_currency() ) ) );

		return abs( (int) $was['amount_cents'] );
	}

	/**
	 * A failed invoice gives its credit back and loses the fee line, so it can be paid in full.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function failed( $order_id ): void {
		if ( self::give_back( $order_id ) < 1 ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $order->get_items( 'fee' ) as $id => $fee ) {
			if ( 'yes' === $fee->get_meta( self::META_FEE ) ) {
				$order->remove_item( $id );
			}
		}

		$order->calculate_totals( true );
		$order->save();
	}
}

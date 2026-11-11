<?php
/**
 * The minimum amount of a pay-as-you-go invoice that is charged to a card.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Config;
use BeltoftServices\Alerts;
use BeltoftServices\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Card fees cost more than a few cents, so a month whose card amount is under the minimum (setting
 * `usage_minimum`, including VAT, 0 = off) is not billed: the renewal order is built as usual, then its usage lines
 * are taken off again, its rows are released and the order stays a 0 order with a note. The next renewal finds the rows
 * again and bills them together with the new usage. Each month keeps its own cap (Renewal::statement groups by month),
 * so carrying never lets a month cost more than its cap.
 *
 * What the card would pay is the order total incl. VAT less the account credit that covers it. Credit that covers the
 * whole total is always used (no card fee). Credit that covers only part, with a rest under the minimum, is not used:
 * the whole month is carried and the credit stays in the balance, because splitting would charge the card the small rest.
 *
 * The carried amount (incl. VAT) is kept on the account (META_AMOUNT) for the display. Carried rows wait for the next
 * renewal with no time limit; nothing is written off.
 */
final class Minimum {

	public const META_AMOUNT = '_beltoft_services_usage_carried';
	public const META_ORDER  = '_beltoft_services_usage_carried_order';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_enabled_customer_processing_renewal_order', array( __CLASS__, 'email_enabled' ), 20, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_renewal_order', array( __CLASS__, 'email_enabled' ), 20, 2 );
		add_filter( 'woocommerce_email_enabled_customer_renewal_invoice', array( __CLASS__, 'email_enabled' ), 20, 2 );
		add_filter( 'woocommerce_email_enabled_new_renewal_order', array( __CLASS__, 'email_enabled' ), 20, 2 );
	}

	/**
	 * The minimum, incl. VAT, in a currency (the store currency by default; 0: off). A currency with no amount for it has no
	 * minimum (0), and the admin is told once.
	 *
	 * @param string $currency Currency code, '' for the current one.
	 */
	public static function amount( string $currency = '' ): float {
		$currency = Currency::or_current( $currency );
		$setting  = Currency::amount( 'usage_minimum', $currency );

		/**
		 * The least amount charged to a card for a usage invoice, incl. VAT. 0 turns it off.
		 *
		 * @param float  $amount   Setting usage_minimum in that currency (0 when the currency has none).
		 * @param string $currency Currency code.
		 */
		$amount = max( 0.0, (float) apply_filters( 'beltoft_services_usage_minimum', (float) $setting, $currency ) );

		if ( null === $setting && $amount <= 0 ) {
			Alerts::notify(
				/* translators: %s: currency code */
				sprintf( __( 'No usage minimum for %s', 'beltoft-services' ), $currency ),
				array( sprintf( 'The setting usage_minimum has no amount in %s, so no minimum applies to usage invoices in that currency.', $currency ) ),
				null,
				'beltoft_services_usage_minimum_' . strtolower( $currency )
			);
		}

		return $amount;
	}

	/**
	 * No customer email for a 0 order that only carried its usage over.
	 *
	 * @param mixed $enabled Whether the email is sent.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public static function email_enabled( $enabled, $order = null ) {
		return $order instanceof \WC_Order && '' !== (string) $order->get_meta( self::META_ORDER ) ? false : $enabled;
	}

	/**
	 * Whether an invoice of this total (cents, incl. VAT) is carried: what is left for the card after credit that covers
	 * it is above 0 and under the minimum.
	 *
	 * @param int    $total_cents Order total incl. VAT.
	 * @param int    $user_id     Customer.
	 * @param string $currency    Currency of the invoice, '' for the current one.
	 */
	public static function carries( int $total_cents, int $user_id, string $currency = '' ): bool {
		$minimum = (int) round( self::amount( $currency ) * 100 );

		if ( $minimum < 1 || $total_cents < 1 ) {
			return false;
		}

		$credit = Config::enabled() && $user_id > 0 ? max( 0, min( Balance::get_in( $user_id, Currency::or_current( $currency ) ), $total_cents ) ) : 0;
		$rest   = $total_cents - $credit;

		return $rest > 0 && $rest < $minimum;
	}

	/**
	 * Takes the usage off a renewal order that is carried: the fee lines go, the rows are free again.
	 *
	 * @param \WC_Order        $order   Renewal order (usage lines added, no credit yet).
	 * @param \WC_Subscription $account Account.
	 * @param bool             $early   An early bill: the account's counters stay as they are.
	 * @return string Amount carried, formatted.
	 */
	public static function carry( \WC_Order $order, \WC_Subscription $account, bool $early ): string {
		$cents    = Balance::cents( $order->get_total() );
		$amount   = Rates::money( $cents / 100, 2, $order->get_currency() );
		$minimum  = Rates::money( self::amount( $order->get_currency() ), 2, $order->get_currency() );

		foreach ( $order->get_items( 'fee' ) as $id => $fee ) {
			if ( '' !== (string) $fee->get_meta( '_beltoft_services_usage_item' ) ) {
				$order->remove_item( $id );
			}
		}

		Ledger::release( (int) $order->get_id() );
		$order->calculate_totals( true );
		$order->update_meta_data( self::META_ORDER, (string) $cents );
		/* translators: 1: amount with currency, 2: minimum with currency. */
		$order->add_order_note( sprintf( __( 'Usage of %1$s carried to next month (under the %2$s minimum).', 'beltoft-services' ), $amount, $minimum ) );

		if ( ! $early ) {
			$account->update_meta_data( self::META_AMOUNT, (string) $cents );
			$account->save();
		}

		return $amount;
	}

	/**
	 * The usage is billed (or written off): nothing is carried any more.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function clear( \WC_Subscription $account ): void {
		if ( '' === (string) $account->get_meta( self::META_AMOUNT ) ) {
			return;
		}

		foreach ( array( self::META_AMOUNT ) as $key ) {
			$account->delete_meta_data( $key );
		}

		$account->save();
	}

	/**
	 * The amount carried for a customer, incl. VAT (0: none).
	 *
	 * @param int $user_id Customer.
	 */
	public static function carried( int $user_id ): float {
		$account = Account::for_user( $user_id );

		return $account ? round( (int) $account->get_meta( self::META_AMOUNT ) / 100, 2 ) : 0.0;
	}
}

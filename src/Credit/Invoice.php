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
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'deleted' ) );
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
	 * Has credit been applied to this order (a debit row of either part)?
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function applied( \WC_Order $order ): bool {
		$id = (string) $order->get_id();

		return Balance::exists( Balance::INVOICE, self::REF_TYPE, $id ) || Balance::exists( Balance::PROMO_USE, self::REF_TYPE, $id );
	}

	/**
	 * What credit pays of an amount due: the one rule, used by apply() on the order and by CheckoutCredit for the figure the checkout
	 * shows beforehand, so the two cannot disagree. Promotional credit first (within its cap), then paid credit for the rest; nothing
	 * when the rest for the card would be above 0 and under $min_rest.
	 *
	 * @param int      $due        Cents due (the order total).
	 * @param int      $balance    Cents of credit in the order's currency.
	 * @param int      $promo_left Cents of the balance that are promotional.
	 * @param int|null $promo_cap  Most cents of promotional credit that may be used (null: no limit).
	 * @param int      $min_rest   Cents. With a rest for the card above 0 and under this, nothing is used (0: no such rule).
	 * @return array{promo: int, paid: int, cents: int} All 0 when no credit is used.
	 */
	public static function split( int $due, int $balance, int $promo_left, ?int $promo_cap, int $min_rest = 0 ): array {
		$none = array( 'promo' => 0, 'paid' => 0, 'cents' => 0 );

		if ( min( $balance, $due ) < 1 ) {
			return $none;
		}

		$promo = min( $promo_left, null === $promo_cap ? $due : max( 0, $promo_cap ), $due );
		$paid  = min( max( 0, $balance - $promo_left ), $due - $promo );
		$cents = $promo + $paid;

		if ( $cents < 1 || ( $min_rest > 0 && $due - $cents > 0 && $due - $cents < $min_rest ) ) {
			return $none;
		}

		return array( 'promo' => $promo, 'paid' => $paid, 'cents' => $cents );
	}

	/**
	 * Applies credit to an invoice whose lines, taxes and totals are final. Does not save the order.
	 *
	 * Promotional credit is spent first, then paid credit. A debit is up to two ledger rows with the order as reference: the promotional
	 * part as kind promo_use and the paid part as kind invoice (the only row, as before, when no promotional credit is used). A usage
	 * invoice takes both without limit. A renewal order (RenewalCredit) limits the promotional part with $promo_cap and asks for the
	 * minimum rule: nothing is used when what is left for the card would be above 0 and under $min_rest cents. The limits and the balance
	 * are read, and the rows written, under one balance lock.
	 *
	 * @param \WC_Order $order     The invoice.
	 * @param int       $user_id   The customer.
	 * @param int       $min_rest  Cents. With a rest for the card above 0 and under this, nothing is used (0: no such rule).
	 * @param int|null  $promo_cap Most cents of promotional credit that may be used (null: no limit).
	 * @return int Cents applied (0: none).
	 */
	public static function apply( \WC_Order $order, int $user_id, int $min_rest = 0, ?int $promo_cap = null ): int {
		if ( ! Config::enabled() || $user_id < 1 || (int) $order->get_id() < 1 || '' !== (string) $order->get_meta( self::META_USED ) ) {
			return 0;
		}

		$currency = (string) $order->get_currency();

		$wrong = '';

		// Fixed before the first split row is written (see Balance::split_from()).
		Balance::split_from();

		list( $locked, $used ) = Balance::locked(
			$user_id,
			static function () use ( $order, $user_id, $currency, $min_rest, $promo_cap, &$wrong ): int {
				$due     = Balance::cents( $order->get_total() );
				// Only credit in the order's own currency is applied: a balance in another currency is never used on this invoice.
				$balance = Balance::get_in( $user_id, $currency );

				if ( $balance < 1 && Balance::currency( $user_id ) !== '' && Balance::currency( $user_id ) !== $currency && Balance::get( $user_id ) > 0 ) {
					$wrong = sprintf( 'Customer %1$d has credit in %2$s and invoice #%3$s is in %4$s. The credit was not applied.', $user_id, Balance::currency( $user_id ), $order->get_order_number(), $currency );
				}

				if ( min( $balance, $due ) < 1 || self::applied( $order ) ) {
					return 0;
				}

				// Promotional credit first (within its cap), then paid credit for the rest.
				$parts = self::split( $due, $balance, Balance::promo_left( $user_id ), $promo_cap, $min_rest );
				$promo = $parts['promo'];
				$paid  = $parts['paid'];
				$cents = $parts['cents'];

				if ( $cents < 1 ) {
					return 0;
				}

				$id = (string) $order->get_id();
				/* translators: %s: order number. */
				$note = sprintf( __( 'Used on invoice #%s', 'beltoft-services' ), $order->get_order_number() );

				if ( $promo > 0 && ! Balance::debit( $user_id, $promo, Balance::PROMO_USE, self::REF_TYPE, $id, $note, $currency ) ) {
					return 0;
				}

				if ( $paid > 0 && ! Balance::debit( $user_id, $paid, Balance::INVOICE, self::REF_TYPE, $id, $note, $currency ) ) {
					// Never leave half a debit: the promotional part goes back, and nothing is applied.
					if ( $promo > 0 ) {
						Balance::credit( $user_id, $promo, Balance::PROMO_BACK, self::REF_RETURN, $id, $note, $currency );
					}

					return 0;
				}

				return $cents;
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

		$id       = (string) $order->get_id();
		$user_id  = (int) $order->get_customer_id();
		$currency = (string) $order->get_currency();
		/* translators: %s: order number. */
		$note     = sprintf( __( 'Returned from invoice #%s', 'beltoft-services' ), $order->get_order_number() );

		// Each part goes back to where it came from, once (the unique key of each returning row).
		list( , $back ) = Balance::locked(
			$user_id,
			static function () use ( $user_id, $id, $currency, $note ): int {
				$sum   = 0;
				$paid  = Balance::find( Balance::INVOICE, self::REF_TYPE, $id );
				$promo = Balance::find( Balance::PROMO_USE, self::REF_TYPE, $id );

				if ( $paid && Balance::credit( $user_id, abs( (int) $paid['amount_cents'] ), Balance::ADJUST, self::REF_RETURN, $id, $note, $currency ) ) {
					$sum += abs( (int) $paid['amount_cents'] );
				}

				if ( $promo && Balance::credit( $user_id, abs( (int) $promo['amount_cents'] ), Balance::PROMO_BACK, self::REF_RETURN, $id, $note, $currency ) ) {
					$sum += abs( (int) $promo['amount_cents'] );
				}

				return $sum;
			}
		);

		$back = is_int( $back ) ? $back : 0;

		if ( $back > 0 ) {
			$order->add_order_note( sprintf( /* translators: %s: amount. */ __( 'Account credit returned: %s.', 'beltoft-services' ), Topup::label_amount( $back, $order->get_currency() ) ) );
		}

		return $back;
	}

	/**
	 * An order that is deleted while it is unpaid gives its credit back (an early renewal that failed is deleted by WooCommerce
	 * Subscriptions). A paid order keeps what it used.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function deleted( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof \WC_Order && self::used( $order ) > 0 && $order->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
			self::give_back( $order_id );
		}
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

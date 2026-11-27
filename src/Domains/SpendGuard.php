<?php
/** Refuses to spend registrar money on an order that was paid below the registrar cost. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class SpendGuard {
	public const MESSAGE = 'The order was paid below the registrar cost.';

	/**
	 * Is the paid line (after discounts, without tax) at least the registrar's cost for this action?
	 * A missing cost or missing line fails closed. Equal passes (rounding of a cent allowed).
	 *
	 * @param string $name Full domain name.
	 * @param string $key  Cost row: create, transfer or renew.
	 * @param mixed  $item The paid order line, or null.
	 * @return string|null Why not, or null when the spend is covered.
	 */
	public static function problem( string $name, string $key, $item ): ?string {
		$cost = Prices::cost( $name, $key );
		if ( null === $cost ) { return 'The registrar cost for this domain is unknown.'; }
		if ( ! $item instanceof \WC_Order_Item_Product ) { return 'The paid order line for this domain is unavailable.'; }
		$order = $item->get_order();
		// What was paid, in the cost currency: an order in another currency is converted at its guard rate (a little above the real one), and
		// with no guard rate it is refused, never taken 1 to 1. This is only a comparison: nothing is converted for the customer.
		$paid = $order ? Currency::to_cost( self::paid( $item ), (string) $order->get_currency() ) : null;
		if ( null === $paid ) { return 'The order is not in EUR; the registrar cost cannot be compared.'; }
		if ( $paid + 0.01 < $cost ) { return self::MESSAGE; }
		return null;
	}

	/**
	 * What the customer paid for a line, without tax, counting account credit as paid. Account credit (gift cards the customer topped
	 * up) is applied as an order coupon, which lowers the line total; the guards compare what was paid, so the net share of the
	 * credit is added back, never more than the line was discounted. Other discounts stay discounts.
	 *
	 * @param \WC_Order_Item_Product $item The order line.
	 */
	public static function paid( \WC_Order_Item_Product $item ): float {
		$total = (float) $item->get_total();
		$order = $item->get_order();
		/**
		 * Account credit, in cents of the order currency including tax, that paid part of this order.
		 *
		 * @param int       $cents Credit applied.
		 * @param \WC_Order $order The order.
		 */
		$cents = $order ? (int) apply_filters( 'beltoft_services_order_credit_cents', 0, $order ) : 0;
		if ( ! $order || $cents < 1 ) { return $total; }
		$sub = 0.0;
		$tax = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$sub += (float) $line->get_subtotal();
			$tax += (float) $line->get_subtotal_tax();
		}
		if ( $sub <= 0 ) { return $total; }
		$credit = ( $cents / 100 ) * ( $sub / ( $sub + $tax ) ) * ( (float) $item->get_subtotal() / $sub );
		return $total + max( 0.0, min( $credit, (float) $item->get_subtotal() - $total ) );
	}

	/** The order line recorded on the resource when it was created. */
	public static function item_of_resource( Resource $r ) {
		$order = wc_get_order( (int) ( $r->license()['order_id'] ?? 0 ) );
		$item_id = (int) $r->get( 'order_item_id' );
		return $order && $item_id > 0 ? $order->get_item( $item_id ) : null;
	}

	/** The domain's line on a renewal order: tagged with the domain, else the only product line. */
	public static function item_of_order( \WC_Order $order, string $name ) {
		$name = strtolower( $name );
		$only = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) { continue; }
			$only[] = $item;
			$line = $item->get_meta( '_beltoft_services_domain', true );
			if ( is_array( $line ) && strtolower( (string) ( $line['domain'] ?? '' ) ) === $name ) { return $item; }
		}
		return 1 === count( $only ) ? $only[0] : null;
	}

	/** A failing step result and one admin alert, or null when the spend is covered. */
	public static function guard( Resource $r, string $name, string $key, $item ): ?StepResult {
		$why = self::problem( $name, $key, $item );
		if ( null === $why ) { return null; }
		Alerts::admin( $r, $why . ' No registrar order was placed for ' . $name . '.' );
		return StepResult::fail( $why );
	}

	/** What a customer reads when a paid renewal waits for staff (the money is taken, the registrar order is not placed yet). */
	public const RENEWAL_WAITING = 'Your renewal payment is received. The renewal is waiting for our staff to confirm it with the registrar. We have been told.';

	/** The loss cap for one renewal, in the cost currency: the setting, 0 when off, 5 when unset. */
	public static function loss_cap(): float {
		$cap = Settings::get( 'domain_renew_loss_cap' );
		return is_numeric( $cap ) ? max( 0.0, (float) $cap ) : 5.0;
	}

	/** How much below the registrar's cost the line was paid, in the cost currency (0 when not below), or null when it cannot be told. */
	public static function loss( string $name, string $key, $item ): ?float {
		$cost = Prices::cost( $name, $key );
		if ( null === $cost || ! $item instanceof \WC_Order_Item_Product ) { return null; }
		$order = $item->get_order();
		$paid = $order ? Currency::to_cost( self::paid( $item ), (string) $order->get_currency() ) : null;
		return null === $paid ? null : max( 0.0, round( $cost - $paid, 2 ) );
	}

	/**
	 * The guard for a paid renewal. A renewal paid below the registrar cost is still bought when the loss is at most the loss cap
	 * (setting domain_renew_loss_cap, 0 turns it off), and staff get one mail with the figures. Above the cap, or when the cost or the
	 * paid amount cannot be compared, it waits for staff as before: no registrar order, one alert, and a plain sentence for the customer.
	 * Registrations and transfers never use this.
	 *
	 * @param Resource $r        The domain.
	 * @param string   $name     Domain name.
	 * @param mixed    $item     The paid renewal line.
	 * @param string   $order_id The renewal order id.
	 */
	public static function guard_renewal( Resource $r, string $name, $item, string $order_id ): ?StepResult {
		$why = self::problem( $name, 'renew', $item );
		if ( null === $why ) { return null; }
		$cap = self::loss_cap();
		$loss = self::MESSAGE === $why && $cap > 0 ? self::loss( $name, 'renew', $item ) : null;
		if ( null !== $loss && $loss <= $cap + 0.001 ) {
			$cost = (float) Prices::cost( $name, 'renew' );
			Alerts::notify(
				'Domain renewed below the registrar cost: ' . $name,
				array(
					sprintf( 'The renewal of %s (order %s) was paid below the registrar cost and was bought anyway, within the loss cap of %s.', $name, $order_id, number_format( $cap, 2, '.', '' ) ),
					sprintf( 'Price paid: %s %s', number_format( $cost - $loss, 2, '.', '' ), Currency::COST ),
					sprintf( 'Registrar cost: %s %s', number_format( $cost, 2, '.', '' ), Currency::COST ),
					sprintf( 'Loss: %s %s', number_format( $loss, 2, '.', '' ), Currency::COST ),
				),
				$r,
				'beltoft_services_renew_loss_' . $r->id() . '_' . $order_id,
				array( 'ttl' => 30 * DAY_IN_SECONDS )
			);
			return null;
		}
		Alerts::admin( $r, $why . ' No registrar order was placed for ' . $name . '.' );
		return StepResult::fail( $why, array( Jobs::TEXT => self::RENEWAL_WAITING, Jobs::EXPECTED => true ) );
	}

	/** What a customer reads when a paid restore waits for staff. */
	public const RESTORE_WAITING = Restore::WAITING;

	/**
	 * Does an amount in a currency cover the registrar's cost of restoring a domain: the renewal cost plus the full restore cost? A missing
	 * cost, or an amount that cannot be compared, fails closed. Nothing here is shown to a customer.
	 *
	 * @param string $name     Full domain name.
	 * @param float  $paid     Renewal line plus reactivation fee, excluding tax, in $currency.
	 * @param string $currency Currency code of the amount.
	 * @return string|null Why not, or null when it is covered.
	 */
	public static function problem_restore_amounts( string $name, float $paid, string $currency ): ?string {
		$renew   = Prices::cost( $name, 'renew' );
		$restore = ReactivationFee::cost( $name );

		if ( null === $renew || null === $restore ) { return 'The registrar cost to restore this domain is unknown.'; }

		$in_cost = Currency::to_cost( $paid, $currency );

		if ( null === $in_cost ) { return 'The order is not in EUR; the registrar cost cannot be compared.'; }
		if ( $in_cost + 0.01 < $renew + $restore ) { return self::MESSAGE; }

		return null;
	}

	/**
	 * Does the paid restore order cover the registrar's renewal plus restore cost? Counted from the order's own two fee lines, without tax.
	 * Account credit is not added back: a fee line is not discounted by a coupon, so the credit is a payment, not a lower line.
	 *
	 * @param string    $name  Full domain name.
	 * @param \WC_Order $order The restore order.
	 */
	public static function problem_restore( string $name, \WC_Order $order ): ?string {
		$paid = 0.0;

		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( '' !== (string) $item->get_meta( Restore::LINE_META, true ) ) { $paid += (float) $item->get_total(); }
		}

		return self::problem_restore_amounts( $name, $paid, (string) $order->get_currency() );
	}

	/** A failing step result and one admin alert, or null when the paid restore order covers the cost. */
	public static function guard_restore( Resource $r, string $name, \WC_Order $order ): ?StepResult {
		$why = self::problem_restore( $name, $order );
		if ( null === $why ) { return null; }
		Alerts::admin( $r, $why . ' No registrar restore was placed for ' . $name . '.' );
		return StepResult::fail( $why, array( Jobs::TEXT => self::RESTORE_WAITING, Jobs::EXPECTED => true ) );
	}
}

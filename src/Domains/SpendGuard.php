<?php
/** Refuses to spend registrar money on an order that was paid below the registrar cost. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

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
		if ( (float) $item->get_total() + 0.01 < $cost ) { return self::MESSAGE; }
		return null;
	}

	/** The order line recorded on the resource when it was created. */
	public static function item_of_resource( Resource $r ) {
		$order = wc_get_order( (int) ( $r->license()['order_id'] ?? 0 ) );
		$item_id = (int) $r->get( 'order_item_id' );
		return $order && $item_id > 0 ? $order->get_item( $item_id ) : null;
	}

	/** The domain's line on a renewal order: tagged with the domain, else the only product line. */
	public static function item_of_order( \WC_Order $order, string $name ) {
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
}

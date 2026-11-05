<?php
/** Refuses to spend registrar money on a certificate paid below its cost. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Alerts;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The order is paid from the registrar balance when it is posted, and a renewal when it is requested, so the check runs
 * before both: the paid line (after discounts, without tax) must cover the cost of the product. Fails closed: an unknown
 * cost, a missing line or an order in another currency places nothing.
 */
final class SslGuard {
	public const MESSAGE = 'The order was paid below the certificate cost.';

	/**
	 * Why the spend is not covered, or null.
	 *
	 * @param int   $product_id Registrar product id.
	 * @param mixed $item       The paid order line, or null.
	 */
	public static function problem( int $product_id, $item ): ?string {
		$cost = SslCatalog::cost( $product_id );

		if ( null === $cost ) {
			return 'The registrar cost for this certificate product is unknown.';
		}

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return 'The paid order line for this certificate is unavailable.';
		}

		$order = $item->get_order();

		if ( ! $order || 'EUR' !== strtoupper( (string) $order->get_currency() ) ) {
			return 'The order is not in EUR; the certificate cost cannot be compared.';
		}

		if ( (float) $item->get_total() + 0.01 < $cost ) {
			return self::MESSAGE;
		}

		return null;
	}

	/**
	 * A failing result and one admin alert, or null when the spend is covered.
	 *
	 * @param Resource $r          Resource.
	 * @param int      $product_id Registrar product id.
	 * @param mixed    $item       The paid order line, or null.
	 */
	public static function guard( Resource $r, int $product_id, $item ): ?StepResult {
		$why = self::problem( $product_id, $item );

		if ( null === $why ) {
			return null;
		}

		Alerts::admin( $r, $why . ' Nothing was ordered for ' . (string) $r->get( 'ssl_domain' ) . '.' );

		return StepResult::fail( $why );
	}

	/**
	 * The order line recorded on the resource when it was created.
	 *
	 * @param Resource $r Resource.
	 */
	public static function item_of_resource( Resource $r ) {
		return SpendGuard::item_of_resource( $r );
	}

	/**
	 * The certificate's line on a renewal order: the only product line.
	 *
	 * @param \WC_Order $order Renewal order.
	 */
	public static function item_of_order( \WC_Order $order ) {
		return SpendGuard::item_of_order( $order, '' );
	}
}

<?php
/**
 * Refuses to buy storage that the customer did not pay for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A box created at the provider costs real money from the first hour, so every purchase (a new box, a larger type) passes
 * this first, and every check fails closed:
 *
 * 1. the cost of the type must be in the cost table (an unknown cost refuses);
 * 2. the paid order line (without tax, credit counted as paid, converted at the guard rate) must be at least that cost;
 * 3. on a live client only: the caps on live boxes, on the monthly cost of all boxes and on new boxes per day (all 0 by
 *    default, so nothing is bought live until the owner sets them).
 *
 * The create step also looks the box up by our own name first, so a retried job never buys a second box.
 */
final class StorageSpendGuard {
	public const MESSAGE = 'The order was paid below the storage cost.';

	/** Option prefix of the per-day counter of new boxes. */
	private const DAY_OPTION = 'beltoft_services_storage_new_';

	/**
	 * Is the paid line at least the cost of the type? Equal passes (a cent of rounding allowed).
	 *
	 * @param string $type Box type.
	 * @param mixed  $item The paid order line, or null.
	 * @return string|null Why not, or null.
	 */
	public static function problem( string $type, $item ): ?string {
		$cost = StorageSettings::cost( $type );

		if ( null === $cost ) {
			return 'The storage cost for this box type is unknown (see the cost table in Settings, Backup storage).';
		}

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return 'The paid order line for this storage is unavailable.';
		}

		$order = $item->get_order();
		$paid  = $order ? Currency::to_cost( SpendGuard::paid( $item ), (string) $order->get_currency() ) : null;

		if ( null === $paid ) {
			return 'The order is not in EUR; the storage cost cannot be compared.';
		}

		return $paid + 0.01 < $cost ? self::MESSAGE : null;
	}

	/**
	 * For a larger type: does the price of the target product cover the cost of the type? (A switch has no order line of its
	 * own yet when the plan is applied, so the price of the product the licence now has is compared.)
	 *
	 * @param int    $product_id Product or variation of the licence.
	 * @param string $type       Box type.
	 * @return string|null Why not, or null.
	 */
	public static function price_problem( int $product_id, string $type ): ?string {
		$cost = StorageSettings::cost( $type );

		if ( null === $cost ) {
			return 'The storage cost for this box type is unknown (see the cost table in Settings, Backup storage).';
		}

		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return 'The product of this storage is unavailable.';
		}

		$price = (float) wc_get_price_excluding_tax( $product );
		$paid  = Currency::to_cost( $price, Currency::base() );

		if ( null === $paid ) {
			return 'The shop currency is not EUR; the storage cost cannot be compared.';
		}

		return $paid + 0.01 < $cost ? self::MESSAGE : null;
	}

	/**
	 * The caps. Only on a live client; the fake has no money to spend.
	 *
	 * @param string        $type   Box type being bought.
	 * @param Resource|null $except A box that is changed, not added: its own cost is left out of the sum.
	 * @param bool|null     $live   Whether the client is live; null asks StorageClients (tests give it).
	 * @return string|null Why not, or null.
	 */
	public static function budget_problem( string $type, ?Resource $except = null, ?bool $live = null ): ?string {
		if ( ! ( $live ?? StorageClients::is_live() ) ) {
			return null;
		}

		$new  = null === $except;
		$ids  = Resource::live_ids( StorageDriver::KIND );
		$sum  = 0.0;
		$boxes = 0;

		foreach ( Resource::find_many( $ids ) as $other ) {
			if ( '' === (string) $other->get( 'storage_box_id' ) || ( $except && $other->id() === $except->id() ) ) {
				continue;
			}

			++$boxes;
			$sum += (float) StorageSettings::cost( (string) $other->get( 'storage_type' ) );
		}

		$max_boxes = (int) Settings::get( 'storage_max_boxes' );

		if ( $new && $boxes >= $max_boxes ) {
			return 'The cap on live storage boxes is reached (Settings, Backup storage).';
		}

		$max_cost = (float) Settings::get( 'storage_max_monthly_cost' );

		if ( $sum + (float) StorageSettings::cost( $type ) > $max_cost ) {
			return 'The cap on the monthly cost of live storage boxes would be passed (Settings, Backup storage).';
		}

		if ( $new && self::created_today() >= (int) Settings::get( 'storage_max_new_per_day' ) ) {
			return 'The cap on new storage boxes per day is reached (Settings, Backup storage).';
		}

		return null;
	}

	/**
	 * How many boxes were bought today.
	 */
	public static function created_today(): int {
		return (int) get_option( self::DAY_OPTION . gmdate( 'Ymd' ), 0 );
	}

	/**
	 * Counts one bought box for today.
	 */
	public static function count_created(): void {
		update_option( self::DAY_OPTION . gmdate( 'Ymd' ), self::created_today() + 1, false );
	}

	/**
	 * A failing result and one admin alert for a NEW box, or null when the spend is covered.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $type Box type.
	 * @param mixed    $item The paid order line, or null.
	 */
	public static function guard( Resource $r, string $type, $item ): ?StepResult {
		$why = self::problem( $type, $item ) ?? self::budget_problem( $type );

		if ( null === $why ) {
			return null;
		}

		Alerts::admin( $r, $why . ' No storage box was bought.' );

		return StepResult::fail( $why );
	}
}

<?php
/**
 * The spend guard of automatic dedicated server orders.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Applies to LIVE orders only (the fake and the test server spend nothing). An order is placed only when: the plan names the
 * supplier product and a monthly and a setup cost; the setup fee the customer paid covers the setup cost; the caps in Settings,
 * Dedicated servers (most servers, highest monthly cost of all of them, most new orders a day) are not passed. Every cap is 0
 * (nothing may be bought live) until the owner sets it. The Robot documentation itself limits ordering to 20 requests a day.
 */
final class DedicatedSpendGuard {
	private const DAY_OPTION = 'beltoft_services_dedicated_new_';

	/**
	 * The cost in EUR of a plan field, or null when it is not a positive figure.
	 *
	 * @param Resource $r   Resource.
	 * @param string   $key cost_monthly or cost_setup.
	 */
	public static function cost( Resource $r, string $key ): ?float {
		$value = ( (array) $r->get( 'plan' ) )[ $key ] ?? '';

		return is_numeric( $value ) && (float) $value > 0 ? (float) $value : null;
	}

	/**
	 * Why a live order must not be placed, or null.
	 *
	 * @param Resource $r Resource.
	 */
	public static function problem( Resource $r ): ?string {
		$plan = (array) $r->get( 'plan' );

		if ( '' === (string) ( $plan['server_ref'] ?? '' ) ) {
			return 'The plan names no supplier product.';
		}

		$setup = self::cost( $r, 'cost_setup' );

		if ( null === self::cost( $r, 'cost_monthly' ) || null === $setup ) {
			return 'The monthly or setup cost of this server is unknown.';
		}

		$item = SpendGuard::item_of_resource( $r );

		if ( ! $item instanceof \WC_Order_Item_Product || ! $item->get_order() ) {
			return 'The paid order line of this server is unavailable.';
		}

		$paid = Currency::to_cost( SpendGuard::paid( $item ), (string) $item->get_order()->get_currency() );

		if ( null === $paid ) {
			return 'The order is not in EUR; the setup fee cannot be compared with the cost.';
		}

		return $paid + 0.01 < $setup ? 'The setup fee paid is below the supplier setup cost.' : null;
	}

	/**
	 * Why the caps forbid one more live order, or null.
	 *
	 * @param Resource $r Resource that would be ordered.
	 */
	public static function budget_problem( Resource $r ): ?string {
		$servers = 0;
		$sum     = 0.0;

		foreach ( Resource::find_many( Resource::live_ids( ManualServerDriver::KIND ) ) as $other ) {
			if ( $other->id() === $r->id() || '' === (string) $other->get( RobotOrder::TRANSACTION ) ) {
				continue;
			}

			++$servers;
			$sum += (float) self::cost( $other, 'cost_monthly' );
		}

		if ( $servers >= (int) Settings::get( 'dedicated_max_servers' ) ) {
			return 'The cap on dedicated servers is reached (Settings, Dedicated servers).';
		}

		if ( $sum + (float) self::cost( $r, 'cost_monthly' ) > (float) Settings::get( 'dedicated_max_monthly_cost' ) ) {
			return 'The cap on the monthly cost of dedicated servers would be passed (Settings, Dedicated servers).';
		}

		return self::created_today() >= (int) Settings::get( 'dedicated_max_new_per_day' ) ? 'The cap on new dedicated servers per day is reached (Settings, Dedicated servers).' : null;
	}

	/** Orders counted today. */
	public static function created_today(): int {
		return (int) get_option( self::DAY_OPTION . gmdate( 'Ymd' ), 0 );
	}

	/** Counts one live order. */
	public static function count_created(): void {
		update_option( self::DAY_OPTION . gmdate( 'Ymd' ), self::created_today() + 1, false );
	}

	/**
	 * A failing result and one admin alert, or null when the order may go.
	 *
	 * @param Resource $r Resource.
	 */
	public static function guard( Resource $r ): ?StepResult {
		$why = self::problem( $r ) ?? self::budget_problem( $r );

		if ( null === $why ) {
			return null;
		}

		Alerts::admin( $r, $why . ' No server was ordered.' );

		return StepResult::fail( $why );
	}
}

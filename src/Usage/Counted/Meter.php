<?php
/**
 * Writes the ledger rows of counted events.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Counted;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter as HourlyMeter;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Called from the hourly meter (no new schedule). Billable events are billed by the hour they were RECEIVED in, and only for hours
 * that ended more than the hold window ago (setting `counted_hold_hours`, 24), so a flood can be seen before it reaches an invoice.
 *
 * PRICE STEPS. A price in steps per calendar month (Rates::unit_steps) needs a running count. It is assigned at receipt order, hour
 * by hour, and never revisited: the events of an hour start from the number of events already billed in earlier hours of the same
 * UTC month, and fill the steps from there. One ledger row per step and hour (item `order`, `order_2`, `order_3`; quantity = the
 * events of the hour in that step; rate = that step's price). Hours are written in order, an hour that is written is never changed, a
 * late event is billed in the hour it is received (so it goes behind everything already billed and can never shift an earlier order
 * between steps or lower an amount already invoiced), and a re-run of the same hour writes the same rows (the unique key keeps them,
 * and a row that exists with another quantity is reported, not overwritten). Free events (intro, free rate) are not billed and do not
 * count towards the steps. A month's steps start again at 0 on its first hour.
 *
 * Off unless the setting `counted_billing` is on; events are still pulled and judged while it is off.
 */
final class Meter {

	public const ITEM = 'order';

	/**
	 * The ledger item of a step: order, order_2, order_3.
	 *
	 * @param int $step Step number from 1.
	 */
	public static function item( int $step ): string {
		return $step < 2 ? self::ITEM : self::ITEM . '_' . $step;
	}

	/**
	 * Splits the events of an hour over the steps, from the running count of the month.
	 *
	 * @param int                                        $prior Events billed earlier in the month.
	 * @param int                                        $n     Events of this hour.
	 * @param array<int, array{upto: ?int, rate: float}> $steps Steps.
	 * @return array<int, array{step: int, quantity: int, rate: float}>
	 */
	public static function allocate( int $prior, int $n, array $steps ): array {
		$out  = array();
		$from = $prior;

		foreach ( $steps as $i => $step ) {
			if ( $n < 1 ) {
				break;
			}

			$room = null === $step['upto'] ? $n : max( 0, (int) $step['upto'] - $from );
			$take = min( $n, $room );

			if ( $take > 0 ) {
				$out[] = array(
					'step'     => $i + 1,
					'quantity' => $take,
					'rate'     => (float) $step['rate'],
				);
				$from += $take;
				$n    -= $take;
			}
		}

		return $out;
	}

	/**
	 * Writes the missing rows of every counted service.
	 *
	 * @param int $now Unix time.
	 * @return int Ledger rows written.
	 */
	public static function run( int $now ): int {
		if ( ! Settings::get( 'counted_billing' ) ) {
			return 0;
		}

		$written = 0;

		foreach ( \BeltoftServices\Http\OrderPull::counted( $now, true ) as $r ) {
			$written += self::bill( $r, $now );
		}

		return $written;
	}

	/**
	 * Bills one service's events that are old enough.
	 *
	 * @param Resource $r   Resource.
	 * @param int      $now Unix time.
	 * @return int Ledger rows written.
	 */
	public static function bill( Resource $r, int $now ): int {
		$license = $r->license();
		$user_id = (int) ( $license['user_id'] ?? 0 );
		$before  = Store::hour( $now - max( 0, (int) Settings::get( 'counted_hold_hours' ) ) * HOUR_IN_SECONDS );
		$hours   = Store::billable_hours( $r->id(), $before );

		if ( $user_id < 1 || ! $hours ) {
			return 0;
		}

		$variation = wc_get_product( (int) ( $license['variation_id'] ?? 0 ) );
		$currency  = HourlyMeter::currency_of( $license, $user_id );

		list( $ok, $steps ) = $variation ? Currency::with( $currency, static fn() => Rates::unit_steps( $variation, $currency ) ) : array( false, array() );

		// Fail closed: no price in the account's currency bills nothing, and the events stay billable for when there is one.
		if ( ! $ok || ! $steps ) {
			Alerts::notify(
				/* translators: %s: currency code */
				sprintf( __( 'Counted orders not billed in %s', 'beltoft-services' ), $currency ),
				array( sprintf( 'Service %1$d has orders to bill but its product has no price in %2$s. They stay unbilled until it has one.', $r->id(), $currency ) ),
				$r,
				'beltoft_services_counted_price_' . $r->id() . '_' . strtolower( $currency )
			);

			return 0;
		}

		$written = 0;

		foreach ( $hours as $row ) {
			$hour  = (int) strtotime( $row['hour_bucket'] . ' UTC' );
			$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $hour ) . ' UTC' );
			$parts = self::allocate( Store::billed_in_month( $r->id(), $month, $hour ), (int) $row['n'], $steps );
			$clean = true;

			foreach ( $parts as $part ) {
				$item = self::item( $part['step'] );

				if ( Ledger::record( $r->id(), $user_id, $item, (float) $part['quantity'], 'order', $part['rate'], $hour, $currency ) ) {
					++$written;
					continue;
				}

				// The row is there already (a run that stopped half way): it must say the same.
				$have = Ledger::row( $r->id(), $item, $hour );

				if ( ! $have || abs( (float) $have['quantity'] - $part['quantity'] ) > 0.00005 ) {
					$clean = false;
					Alerts::notify(
						/* translators: %d: resource id */
						sprintf( __( 'Counted orders differ from the ledger for service %d', 'beltoft-services' ), $r->id() ),
						array( sprintf( 'Service %1$d, hour %2$s, item %3$s: %4$d orders to bill, the ledger holds %5$s. Nothing was changed.', $r->id(), gmdate( 'Y-m-d H:i', $hour ), $item, $part['quantity'], $have ? (string) $have['quantity'] : 'no row' ) ),
						$r,
						'beltoft_services_counted_mismatch_' . $r->id() . '_' . $hour
					);
				}
			}

			// An hour that could not be settled stops the run: a later hour would start its steps from a count that lacks this one.
			if ( ! $clean ) {
				break;
			}

			Store::mark_billed( $r->id(), $hour );
		}

		return $written;
	}
}

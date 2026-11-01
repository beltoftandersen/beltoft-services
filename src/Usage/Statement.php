<?php
/**
 * The statement of ledger rows, with the monthly cap: shared by the usage invoice and the prepaid daily debit.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Groups rows per resource, item and UTC month and prices them: hours x rate, never more than the cap. The cap is
 * the most one resource's item costs in a month (the largest hourly amount x 730), less what earlier orders or earlier
 * prepaid debits took in that month (Ledger::billed()). One function, so the invoice, the estimate and the prepaid
 * debit can never disagree about a cap.
 */
final class Statement {

	/**
	 * The statement.
	 *
	 * @param array<int, array<string, mixed>> $rows     Ledger rows.
	 * @param int                              $except   An order whose rows do not count as billed before.
	 * @param int                              $decimals Decimals of each line's charge (2 for an invoice line, 4 to sum before rounding to cents).
	 * @return array{lines: array<int, array<string, mixed>>, total: float}
	 */
	public static function build( array $rows, int $except = 0, int $decimals = 2 ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			$start = (int) strtotime( gmdate( 'Y-m-01 00:00:00', (int) strtotime( $row['hour_bucket'] . ' UTC' ) ) . ' UTC' );
			$key   = $row['resource_id'] . '|' . $row['item'] . '|' . $start;

			$groups[ $key ]['resource_id'] = (int) $row['resource_id'];
			$groups[ $key ]['item']        = (string) $row['item'];
			$groups[ $key ]['unit']        = (string) $row['unit'];
			$groups[ $key ]['quantity']    = (float) $row['quantity'];
			$groups[ $key ]['rate']        = (float) $row['rate'];
			$groups[ $key ]['month']       = $start;
			$groups[ $key ]['hours']       = ( $groups[ $key ]['hours'] ?? 0 ) + 1;
			$groups[ $key ]['raw']         = ( $groups[ $key ]['raw'] ?? 0.0 ) + (float) $row['quantity'] * (float) $row['rate'];
			$groups[ $key ]['peak']        = max( $groups[ $key ]['peak'] ?? 0.0, (float) $row['quantity'] * (float) $row['rate'] );
		}

		$lines = array();
		$total = 0.0;

		foreach ( $groups as $group ) {
			$end    = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $group['month'] + 32 * DAY_IN_SECONDS ) . ' UTC' );
			$cap    = round( $group['peak'] * Rates::HOURS, 2 );
			$prior  = min( $cap, Ledger::billed( $group['resource_id'], $group['item'], $group['month'], $end, $except ) );
			$room   = max( 0.0, $cap - $prior );
			$raw    = round( $group['raw'], 4 );
			$charge = round( min( $raw, $room ), $decimals );

			$group['cap']    = $cap;
			$group['raw']    = $raw;
			$group['charge'] = $charge;
			$group['capped'] = $raw > $room;
			$lines[]         = $group;
			$total          += $charge;
		}

		return array(
			'lines' => $lines,
			'total' => round( $total, $decimals ),
		);
	}
}

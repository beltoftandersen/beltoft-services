<?php
/**
 * What this month's usage will take from the account credit, as an estimate for the credit page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Minimum;

defined( 'ABSPATH' ) || exit;

/**
 * Display only: nothing is written to the ledger or the balance. It mirrors Invoice::apply(): the usage invoice total
 * including VAT (the unbilled rows, earlier months carried over included, since carried rows wait in the ledger) is paid
 * from credit in the invoice's own currency, min( balance, total ), unless Minimum carries the month (a rest under the
 * minimum), when no credit is used and the usage waits for the next invoice.
 */
final class Reserve {

	/**
	 * The reservation of a customer, or null with no credit in the usage account's currency or no usage.
	 *
	 * Keys: currency, balance, usage (cents incl. VAT), used (cents the invoice would take), left (cents), rest (cents for the card),
	 * carried (true: the month is under the minimum and carried, no credit is used).
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>|null
	 */
	public static function for_user( int $user_id ): ?array {
		if ( ! Config::enabled() || $user_id < 1 ) {
			return null;
		}

		$estimate = Estimate::for_user( $user_id );

		if ( ! $estimate || ! $estimate['resources'] ) {
			return null;
		}

		$currency = (string) $estimate['currency'];
		$balance  = Balance::get_in( $user_id, $currency );
		$usage    = Estimate::invoice_cents( $estimate );

		if ( $balance < 1 || $usage < 1 ) {
			return null;
		}

		$carried = Minimum::carries( $usage, $user_id, $currency );
		$used    = $carried ? 0 : min( $balance, $usage );

		return array(
			'currency' => $currency,
			'balance'  => $balance,
			'usage'    => $usage,
			'used'     => $used,
			'left'     => $balance - $used,
			'rest'     => $carried ? 0 : $usage - $used,
			'carried'  => $carried,
		);
	}
}

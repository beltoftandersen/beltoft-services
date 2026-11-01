<?php
/**
 * Account credit: the switch.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Whether account credit is on. A top-up carries no VAT (it is a prepayment): the balance holds exactly what the
 * customer paid, and VAT is charged on the usage invoices the credit pays.
 */
final class Config {

	/**
	 * Is account credit switched on?
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'credit_enabled' );
	}
}

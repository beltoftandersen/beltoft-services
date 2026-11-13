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

	/**
	 * Is credit held as gift cards (the setting `gift_credit`, with the add-on active)? Then src/GiftCredit/ draws the credit page and
	 * pays the orders; this folder is on its way out and draws nothing.
	 */
	public static function gift(): bool {
		return (bool) Settings::get( 'gift_credit' ) && class_exists( '\\Bgca\\Plugin' );
	}

	/**
	 * Is credit also applied to the renewal orders of other subscriptions? Needs account credit to be on.
	 */
	public static function renewals(): bool {
		return self::enabled() && (bool) Settings::get( 'credit_renewals' );
	}

	/**
	 * Is credit also applied to the first order of a signed-in customer at checkout? Needs account credit to be on.
	 */
	public static function checkout(): bool {
		return self::enabled() && (bool) Settings::get( 'credit_checkout' );
	}
}

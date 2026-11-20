<?php
/**
 * The pay as you go feature's hooks into the core's extension points.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * The core (the product form, the line names) never names pay as you go; this class answers its filters and actions.
 */
final class CoreHooks {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_line_is_usage', array( __CLASS__, 'line_is_usage' ), 10, 2 );
		add_filter( 'beltoft_services_line_usage_note', array( __CLASS__, 'line_usage_note' ), 10, 2 );
		add_filter( 'beltoft_services_line_account_product_id', array( __CLASS__, 'line_account_product_id' ) );
	}

	/**
	 * Is a line billed by use?
	 *
	 * @param mixed $usage   What an earlier listener said.
	 * @param mixed $product Product, variation or id.
	 * @return mixed
	 */
	public static function line_is_usage( $usage, $product = null ) {
		return Rates::is_usage( $product );
	}

	/**
	 * The note under a pay-as-you-go line.
	 *
	 * @param mixed $note    The note so far.
	 * @param mixed $context 'order' or 'cart'.
	 */
	public static function line_usage_note( $note = '', $context = 'order' ): string {
		// The cart and an order line say what the figures next to them are: the shop's tax display for the cart (an order page, the
		// thank-you page and order emails show the order's figures the same way).
		if ( 'incl' === \BeltoftServices\Billing\TaxNote::in_cart( static fn() => \BeltoftServices\Billing\TaxNote::display() ) ) {
			/* translators: %s: how pay as you go is billed */
			return sprintf( __( '%s, incl. VAT', 'beltoft-services' ), Rates::billed_note() );
		}

		/* translators: %s: how pay as you go is billed */
		return sprintf( __( '%s, excl. VAT', 'beltoft-services' ), Rates::billed_note() );
	}

	/**
	 * The hidden pay-as-you-go account product.
	 *
	 * @param mixed $id The id so far.
	 */
	public static function line_account_product_id( $id = 0 ): int {
		return (int) get_option( Account::OPTION_PRODUCT );
	}
}

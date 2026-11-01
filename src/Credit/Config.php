<?php
/**
 * Prepaid credit: the switch and the VAT the balance works with.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The balance holds gross amounts (VAT included): a top-up credits what the customer paid. This class answers whether
 * prepaid credit is on and which VAT percent applies to a customer.
 */
final class Config {

	/**
	 * User meta: 'yes' when the customer's latest paid top-up was reverse charged (no VAT).
	 */
	public const META_EXEMPT = 'beltoft_services_credit_vat_exempt';

	/**
	 * Is prepaid credit switched on?
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'prepaid_enabled' );
	}

	/**
	 * The VAT percent that usage of this customer carries now, the way WooCommerce would tax a sale to them: nothing when
	 * taxes are off or the customer was reverse charged on their latest top-up (beltoft-eu-vat exemption), else the rate of
	 * the customer's tax address (billing country by default). Usage rates are excl. VAT; the balance holds gross amounts,
	 * so a debit is net x (1 + rate).
	 *
	 * @param int $user_id Customer.
	 */
	public static function vat_rate( int $user_id ): float {
		/**
		 * Filters the VAT percent usage is debited with. Return a number to decide it yourself, null to let the plugin work it out.
		 *
		 * @param float|null $rate    Percent or null.
		 * @param int        $user_id Customer.
		 */
		$pre = apply_filters( 'beltoft_services_credit_vat_rate', null, $user_id );

		if ( null !== $pre ) {
			return max( 0.0, (float) $pre );
		}

		if ( ! wc_tax_enabled() || 'yes' === get_user_meta( $user_id, self::META_EXEMPT, true ) ) {
			return 0.0;
		}

		$rates = \WC_Tax::get_rates( '', new \WC_Customer( $user_id, true ) );

		return (float) array_sum( \WC_Tax::calc_tax( 100.0, $rates, false ) );
	}

	/**
	 * Words for a VAT rate on amounts: "incl. VAT", or "excl. VAT" when there is none.
	 *
	 * @param float $rate Percent.
	 */
	public static function vat_label( float $rate ): string {
		return $rate > 0 ? __( 'incl. VAT', 'beltoft-services' ) : __( 'excl. VAT', 'beltoft-services' );
	}
}

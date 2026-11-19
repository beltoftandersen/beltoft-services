<?php
/** The reactivation fee of an expired domain: the registrar's restore cost plus the markup. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Currency;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owner decision 2026-11-19 (plan section 9): the fee is our restore cost times 1.30, on top of the normal renewal, with no minimum and the
 * same rule for .dk. No fee before the expiry, and none while the registrar restores free of charge (the extension's soft restore price is 0).
 * The cost is the restore price the price refresh stored (Prices::sync), in EUR excluding tax; nothing here is a fixed rate.
 */
final class ReactivationFee {

	/** Setting: the markup on the restore cost, in percent (default 30). */
	public const MARKUP_SETTING = 'domain_reactivation_markup_percent';

	/** The markup in percent. */
	public static function markup_percent(): float {
		$value = Settings::get( self::MARKUP_SETTING );

		return is_numeric( $value ) ? max( 0.0, min( 500.0, (float) $value ) ) : 30.0;
	}

	/**
	 * The registrar's cost to restore a domain, EUR excluding tax: the soft restore price while the domain is inside the extension's soft quarantine
	 * (days after the renewal date given), else the restore price. Null when the price table does not state it.
	 *
	 * @param string   $domain     Full domain name.
	 * @param int|null $days_after Whole days since the renewal date, null for "after the free period".
	 */
	public static function cost( string $domain, ?int $days_after = null ): ?float {
		list( , $row ) = RestoreFacts::row( $domain );
		$soft_days     = (int) ( $row['soft_quarantine_days'] ?? 0 );

		if ( null !== $days_after && $soft_days > 0 && $days_after <= $soft_days ) {
			return isset( $row['soft_restore'] ) ? (float) $row['soft_restore'] : null;
		}

		return isset( $row['restore'] ) ? (float) $row['restore'] : null;
	}

	/**
	 * The fee for a cost: cost times (1 + markup), rounded to whole cents. 0 costs 0.
	 *
	 * @param float $cost Registrar restore cost, EUR excluding tax.
	 */
	public static function of_cost( float $cost ): float {
		return round( $cost * ( 1.0 + self::markup_percent() / 100.0 ), 2 );
	}

	/**
	 * The fee of a domain excluding tax, in the shop's currency (the cost currency). Null when the cost is not known.
	 *
	 * @param string   $domain     Full domain name.
	 * @param int|null $days_after See cost().
	 */
	public static function fee( string $domain, ?int $days_after = null ): ?float {
		$cost = self::cost( $domain, $days_after );

		return null === $cost ? null : self::of_cost( $cost );
	}

	/**
	 * The fee as the customer reads it: plain text in the shop's tax display. In another currency than the shop's only a figure a site's own
	 * listener gives is shown (filter beltoft_services_domain_reactivation_fee); without one the fee is shown in the shop's currency, never
	 * a euro figure relabelled. Empty when the fee is not known.
	 *
	 * @param float  $excl     Fee excluding tax, in the shop's currency.
	 * @param string $currency The customer's currency code, '' for the current one.
	 * @param string $domain   Full domain name.
	 */
	public static function text( float $excl, string $currency = '', string $domain = '' ): string {
		$product  = Cart::domain_product();
		$currency = Currency::or_current( $currency );

		if ( Currency::base() !== $currency ) {
			/**
			 * The reactivation fee in a currency other than the shop's, excluding tax, or null when the site has no figure for it.
			 *
			 * @param float|null $amount   Amount in $currency, null by default.
			 * @param float      $base     The fee in the shop's currency, excluding tax.
			 * @param string     $currency Currency code.
			 * @param string     $domain   Full domain name.
			 */
			$amount = apply_filters( 'beltoft_services_domain_reactivation_fee', null, $excl, $currency, $domain );

			if ( is_numeric( $amount ) ) {
				return \BeltoftServices\Billing\TaxNote::text( (float) $amount, 2, $product, $currency, false );
			}
		}

		return \BeltoftServices\Billing\TaxNote::text( $excl, 2, $product, Currency::base(), false );
	}

	/**
	 * What the page and the notices say about an extension: the days it can be restored, the fee inside a free grace period and after it.
	 * Computed from the stored price table, so a refreshed price changes it.
	 *
	 * @param string $domain Full domain name.
	 * @return array{window: int|null, free_days: int, free_fee: float|null, paid_fee: float|null}
	 */
	public static function terms( string $domain ): array {
		list( , $row ) = RestoreFacts::row( $domain );
		$soft          = (int) ( $row['soft_quarantine_days'] ?? 0 );
		$hard          = (int) ( $row['quarantine_days'] ?? 0 );
		$free_fee      = $soft > 0 ? self::fee( $domain, 0 ) : null;
		// The paid restore price applies from day 1 when there is no soft quarantine, and after it only when a quarantine period follows.
		$paid_fee = 0 === $soft || $hard > 0 ? self::fee( $domain, null ) : null;

		return array(
			'window'    => RestoreFacts::window_days( $domain ),
			'free_days' => $soft,
			'free_fee'  => $free_fee,
			'paid_fee'  => $paid_fee,
		);
	}
}

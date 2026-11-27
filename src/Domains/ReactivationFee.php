<?php
/** The reactivation fee of an expired domain: the registrar's restore cost plus the markup. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Currency;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owner decisions (plan section 9): the fee is our restore cost times 1.30 (the markup is a setting), on top of the normal renewal, with no
 * minimum and the same rule for .dk. No fee before the expiry. Since 2026-11-27 the cost is always the full restore price, never the soft
 * restore price: Openprovider's semantics are not known, so the most expensive case is assumed and the fee is charged even while the
 * registrar might restore free. The cost is the restore price the price refresh stored (Prices::sync), in EUR excluding tax; nothing here is
 * a fixed rate.
 */
final class ReactivationFee {

	/** Setting: the markup on the restore cost, in percent (default 30). */
	public const MARKUP_SETTING = 'domain_reactivation_markup_percent';

	/** Setting: the owner's fixed rate, kroner per euro (empty: no kroner figure). */
	public const DKK_RATE_SETTING = 'domain_fee_dkk_rate';

	/** The fixed EUR to DKK rate, or null when the owner has set none. */
	public static function dkk_rate(): ?float {
		$value = Settings::get( self::DKK_RATE_SETTING );

		return is_numeric( $value ) && (float) $value > 0 ? (float) $value : null;
	}

	/**
	 * The fee in kroner, excluding tax: the euro fee times the fixed rate, rounded up to whole kroner. Null without a rate.
	 *
	 * @param float $eur Fee in euro, excluding tax.
	 */
	public static function dkk( float $eur ): ?float {
		$rate = self::dkk_rate();

		return null === $rate ? null : (float) ceil( round( $eur * $rate, 6 ) );
	}

	/** The markup in percent. */
	public static function markup_percent(): float {
		$value = Settings::get( self::MARKUP_SETTING );

		return is_numeric( $value ) ? max( 0.0, min( 500.0, (float) $value ) ) : 30.0;
	}

	/**
	 * The registrar's cost to restore a domain, EUR excluding tax: always the full restore price (never the soft restore price, owner decision
	 * 2026-11-27). Null when the price table does not state it.
	 *
	 * @param string $domain Full domain name.
	 */
	public static function cost( string $domain ): ?float {
		list( , $row ) = RestoreFacts::row( $domain );

		return isset( $row['restore'] ) && is_numeric( $row['restore'] ) ? (float) $row['restore'] : null;
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
	 * @param string $domain Full domain name.
	 */
	public static function fee( string $domain ): ?float {
		$cost = self::cost( $domain );

		return null === $cost ? null : self::of_cost( $cost );
	}

	/**
	 * The fee in a currency, excluding tax: the shop's currency as it is, another one only from the fixed kroner rate or a site's own
	 * listener (filter beltoft_services_domain_reactivation_fee). Null when there is no figure in that currency.
	 *
	 * @param float  $excl     Fee excluding tax, in the shop's currency.
	 * @param string $currency Currency code, '' for the current one.
	 * @param string $domain   Full domain name.
	 */
	public static function amount( float $excl, string $currency = '', string $domain = '' ): ?float {
		$currency = Currency::or_current( $currency );

		if ( Currency::base() === $currency ) {
			return $excl;
		}

		/**
		 * The reactivation fee in a currency other than the shop's, excluding tax, or null when the site has no figure for it.
		 *
		 * @param float|null $amount   Amount in $currency; for DKK the fixed rate setting's figure, else null.
		 * @param float      $base     The fee in the shop's currency, excluding tax.
		 * @param string     $currency Currency code.
		 * @param string     $domain   Full domain name.
		 */
		$amount = apply_filters( 'beltoft_services_domain_reactivation_fee', 'DKK' === $currency ? self::dkk( $excl ) : null, $excl, $currency, $domain );

		return is_numeric( $amount ) ? (float) $amount : null;
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
		$amount   = Currency::base() !== $currency ? self::amount( $excl, $currency, $domain ) : null;

		if ( null !== $amount ) {
			return \BeltoftServices\Billing\TaxNote::text( $amount, 2, $product, $currency, false );
		}

		return \BeltoftServices\Billing\TaxNote::text( $excl, 2, $product, Currency::base(), false );
	}

	/**
	 * What the page and the notices say about an extension: the days it can be restored and the fee. Computed from the stored price table, so
	 * a refreshed price changes it.
	 *
	 * @param string $domain Full domain name.
	 * @return array{window: int|null, fee: float|null}
	 */
	public static function terms( string $domain ): array {
		return array(
			'window' => RestoreFacts::window_days( $domain ),
			'fee'    => self::fee( $domain ),
		);
	}
}

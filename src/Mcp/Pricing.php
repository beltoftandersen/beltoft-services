<?php
/**
 * What the MCP tools say about prices: pay as you go amounts, add-ons and the account's
 * card and credit.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\GiftCredit\GiftCredit;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. The amounts come from Rates and Breakdown, the same code the checkout uses, so Claude quotes what the
 * website shows. Prices exclude VAT. Only pay as you go plans are listed: a plan of a monthly product is not sold here.
 */
final class Pricing {

	/**
	 * The model in a few plain sentences, for the server instructions and the price tools.
	 */
	public static function note(): string {
		return __( 'Servers and databases are pay as you go: billed by the hour, and a month never costs more than the max per month. An hour that has started counts as a whole hour, and a stopped server is still billed until it is deleted (delete_server, delete_database); billing stops in the hour the service is deleted. The hours are invoiced monthly to the customer\'s saved card, which is required to order (account credit alone is not enough); VAT is added on the invoice, and every price here excludes VAT. Add-ons are billed by the hour like the service and capped the same way: a database can get extra storage (it can be added later in the dashboard and only made larger) and a public IPv4 address, a server can get daily backups and one extra disk. Use price_quote for the total of a plan with add-ons. Account credit, if the customer has some, is applied first on usage invoices. Business email is a monthly subscription, plugins are yearly and domains are yearly per ending.', 'beltoft-services' );
	}

	/**
	 * "€0.0226 per hour, max €16.50 per month, billed monthly for the hours used (excluding VAT)".
	 *
	 * @param float $hourly Per hour.
	 * @param float $cap    Monthly cap.
	 */
	public static function text( float $hourly, float $cap ): string {
		/* translators: 1: "€0.0226 per hour, max €16.50 per month", 2: "Billed monthly for the hours used" in lower case. */
		return sprintf( __( '%1$s, %2$s (excluding VAT)', 'beltoft-services' ), Rates::tile_terms( $hourly, $cap ), lcfirst( Rates::billed_note() ) );
	}

	/**
	 * The pay as you go price of something.
	 *
	 * @param float $hourly Per hour.
	 * @param float $cap    Max per month.
	 * @return array{hourly: float, max_per_month: float, currency: string, text: string, excl_vat: bool}
	 */
	public static function payg( float $hourly, float $cap ): array {
		return array(
			'hourly'        => round( $hourly, 4 ),
			'max_per_month' => round( $cap, 2 ),
			'currency'      => get_woocommerce_currency(),
			'text'          => self::text( $hourly, $cap ),
			'excl_vat'      => true,
		);
	}

	/**
	 * The price of an add-on from its max per month: the hour is the month divided by the hours of a month.
	 *
	 * @param float $cap Max per month.
	 */
	public static function adds( float $cap ): array {
		$hourly = round( $cap / Rates::HOURS, 8 );

		return array(
			'hourly'        => round( $hourly, 4 ),
			'max_per_month' => round( $cap, 2 ),
			'currency'      => get_woocommerce_currency(),
			/* translators: 1: "+€5 per month", 2: "€0.0068 per hour" */
			'text'          => sprintf( __( '%1$s, %2$s, billed with the service and capped (excluding VAT)', 'beltoft-services' ), Rates::plus_per_month( $cap ), Rates::per_hour( $hourly ) ),
			'excl_vat'      => true,
		);
	}

	/**
	 * What switching from one variation to another adds at most in a month.
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function difference( \WC_Product_Variation $from, \WC_Product_Variation $to ): float {
		if ( Rates::is_usage( $to ) ) {
			return Rates::monthly_difference( $from, $to );
		}

		return Rates::cap( (float) wc_get_price_excluding_tax( $to ) - (float) wc_get_price_excluding_tax( $from ), Rates::premium( $to->get_parent_id() ) );
	}

	// -- Plans ------------------------------------------------------------------------------------

	// -- The quote --------------------------------------------------------------------------------

	// -- Add-ons of one service --------------------------------------------------------------------

	/**
	 * An add-on that is a variation of its own (an extra disk, a snapshot pack): what it costs at most a month, and an hour.
	 *
	 * @param \WC_Product_Variation $v Variation.
	 * @return array<string, mixed>
	 */
	public static function variation_price( \WC_Product_Variation $v ): array {
		$monthly = Rates::is_usage( $v ) ? Rates::monthly( $v ) : (float) wc_get_price_excluding_tax( $v );

		return self::adds( Rates::cap( (float) $monthly, Rates::premium( $v->get_parent_id() ) ) );
	}

	/**
	 * What one backups switch adds, for the add-ons of a service.
	 *
	 * @param \WC_Product_Variation $from Current variation.
	 * @param \WC_Product_Variation $to   Variation with the add-on.
	 * @return array<string, mixed>
	 */
	public static function addon_between( \WC_Product_Variation $from, \WC_Product_Variation $to ): array {
		return self::adds( max( 0.0, self::difference( $from, $to ) ) );
	}

	// -- The account -------------------------------------------------------------------------------

	/**
	 * Whether a card is saved, the credit and the usage so far. The card check reads the usage account only (no call to
	 * the payment provider), so it is fast; it says what the account can be charged for, not that the card is valid today.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	public static function account( int $user_id ): array {
		$currency = get_woocommerce_currency();
		$account  = Account::for_user( $user_id, Account::LIVE );
		$out      = array(
			'card_saved'  => null !== $account && Account::chargeable( $account ),
			'card_note'   => 'A saved card is required to order servers and databases. The check reads the usage account, not the payment provider.',
			'credit'      => array( 'enabled' => GiftCredit::active() ),
			'vat_note'    => 'VAT is added on the usage invoice. Prices elsewhere exclude VAT.',
		);

		if ( GiftCredit::active() ) {
			// Credit is gift cards in one currency, the customer's own, and is named with its code: it is never added to another currency.
			$credit_currency          = \Bgca\Wallet::account_currency( $user_id );
			$cents                    = GiftCredit::balance( $user_id, $credit_currency );
			$out['credit']['balance'] = round( $cents / 100, 2 );
			$out['credit']['currency'] = $credit_currency;
			$out['credit']['text']    = Rates::money( $cents / 100, 2, $credit_currency );
			$out['credit']['note']    = 'Credit is applied first on usage invoices as "Paid from account credit"; the rest goes to the card. Top-ups carry no VAT. Credit alone is not enough to order.';
		}

		$estimate = Estimate::for_user( $user_id );

		$out['usage_this_month'] = $estimate ? array(
			'so_far' => Estimate::money( (float) $estimate['total'], (string) $estimate['currency'] ),
			'note'   => 'Excluding VAT, an estimate. Each service is capped at its max per month (usage_summary shows which lines reached it).',
		) : null;

		$minimum = \BeltoftServices\Usage\Minimum::amount( (string) $currency );

		$out['usage_minimum'] = array(
			'minimum' => $minimum > 0 ? Rates::money( $minimum, 2, $currency ) : null,
			'carried' => $estimate && $estimate['carried'] > 0 ? Estimate::money( (float) $estimate['carried'], (string) $estimate['currency'] ) : null,
			'note'    => 'Including VAT. When the amount to charge the card for a month is under the minimum, it is added to the next month\'s invoice instead.',
		);

		return $out;
	}
}

<?php
/**
 * Pay-as-you-go prices: the hourly rate and the monthly cap.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Currency;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A pay-as-you-go product carries the meta `_beltoft_services_billing` = usage (or counted) on the PARENT, and its variations
 * a price of 0 at checkout. The monthly price a variation's rate is taken from is typed on the variation
 * (`_beltoft_services_usage_monthly`, `_beltoft_services_usage_monthly_<CODE>` for another currency). The hourly rate is that
 * price (excluding tax) x (1 + premium) / 730, the cap per resource, item and month is the monthly price x (1 + premium). The
 * premium is a product setting (`_beltoft_services_usage_premium`, percent, 10 when empty).
 *
 * A variation of a flagged product can be a monthly subscription (a live one from before the product became pay as you go): its
 * own meta `_beltoft_services_billing` = `monthly` says so, and it is never pay as you go. A variation's own `usage`/`counted`
 * (the way it was flagged before) is still honoured.
 */
final class Rates {

	public const META_BILLING = '_beltoft_services_billing';
	public const META_PREMIUM = '_beltoft_services_usage_premium';
	public const META_MONTHLY = '_beltoft_services_usage_monthly';

	/**
	 * Value of META_BILLING on a variation: a monthly subscription under a pay as you go product. It is never metered or billed by the hour.
	 */
	public const KIND_MONTHLY = 'monthly';

	/**
	 * Billing kinds, the value of META_BILLING: hourly (`usage`, the monthly price spread over hours) and counted
	 * (`counted`, a price for each unit a service reports, see Counted\Store). Both are pay as you go: they live on the
	 * usage account, need its card and are invoiced by its renewal.
	 */
	public const KIND_HOURLY  = 'usage';
	public const KIND_COUNTED = 'counted';

	/**
	 * Variation meta: the price of one counted unit, a number in the shop's currency, excluding VAT. Another currency has its own
	 * meta, this name plus an underscore and the code (`_beltoft_services_unit_price_DKK`). The variation's own price stays 0: nothing is charged at checkout.
	 */
	public const META_UNIT_PRICE = '_beltoft_services_unit_price';

	/**
	 * Variation meta: price steps per calendar month, like brackets, "500:0.10,2000:0.05,0:0.02" = the first 500 units at 0.10, the
	 * next up to 2000 at 0.05, every unit above at 0.02 (0 = no upper end). Per currency: this name plus an underscore and the code.
	 * Without it the single unit price is the only step.
	 */
	public const META_UNIT_STEPS = '_beltoft_services_unit_steps';

	public const DEFAULT_PREMIUM = 10.0;

	/**
	 * Hours in a month for the rate (365 x 24 / 12).
	 */
	public const HOURS = 730;

	/**
	 * The billing kind of a product or variation: `usage`, `counted`, or '' (monthly or not a pay as you go product). A variation says
	 * `monthly` itself to stay monthly under a pay as you go product, else it follows its own flag, else its parent's.
	 *
	 * @param \WC_Product|int|false $product Product or id.
	 */
	public static function kind( $product ): string {
		$id = $product instanceof \WC_Product ? $product->get_id() : (int) $product;

		if ( $id < 1 ) {
			return '';
		}

		$kinds = array( self::KIND_HOURLY, self::KIND_COUNTED );
		$own   = (string) get_post_meta( $id, self::META_BILLING, true );

		if ( self::KIND_MONTHLY === $own ) {
			return '';
		}

		if ( in_array( $own, $kinds, true ) ) {
			return $own;
		}

		$parent = (string) get_post_meta( Product::parent_id( $id ), self::META_BILLING, true );

		return in_array( $parent, $kinds, true ) ? $parent : '';
	}

	/**
	 * Is this variation (or product) sold pay as you go?
	 *
	 * @param \WC_Product|int|false $product Product or id.
	 */
	public static function is_usage( $product ): bool {
		return '' !== self::kind( $product );
	}

	/**
	 * Is this variation (or product) billed per counted unit (an order a service processed) and not per hour?
	 *
	 * @param \WC_Product|int|false $product Product or id.
	 */
	public static function is_counted( $product ): bool {
		return self::KIND_COUNTED === self::kind( $product );
	}

	/**
	 * The price steps of a counted variation in a currency: a list of `upto` (the month's unit number the step ends at, null for
	 * the last) and `rate` (per unit, excluding VAT). Empty when there is no price in that currency (it is not metered).
	 *
	 * @param \WC_Product $variation Counted variation.
	 * @param string      $currency  Currency code, '' for the shop's.
	 * @return array<int, array{upto: ?int, rate: float}>
	 */
	public static function unit_steps( \WC_Product $variation, string $currency = '' ): array {
		$code = Currency::clean( $currency );
		$code = '' !== $code ? $code : Currency::base();
		$text = (string) $variation->get_meta( Currency::base() === $code ? self::META_UNIT_STEPS : self::META_UNIT_STEPS . '_' . $code );
		$out  = array();
		$last = 0;

		foreach ( '' === trim( $text ) ? array() : explode( ',', $text ) as $part ) {
			$bits = explode( ':', trim( $part ) );
			$upto = 2 === count( $bits ) ? (int) $bits[0] : 0;
			$rate = (float) end( $bits );

			if ( $rate <= 0 || ! is_numeric( end( $bits ) ) || ( $upto > 0 && $upto <= $last ) || ( $out && null === $out[ count( $out ) - 1 ]['upto'] ) ) {
				$out = array();
				break;
			}

			$out[] = array( 'upto' => $upto > 0 ? $upto : null, 'rate' => $rate );
			$last  = $upto;
		}

		if ( $out && null !== $out[ count( $out ) - 1 ]['upto'] ) {
			$out[ count( $out ) - 1 ]['upto'] = null;
		}

		if ( ! $out ) {
			$price = self::unit_price( $variation, $code );
			$out   = null !== $price ? array( array( 'upto' => null, 'rate' => $price ) ) : array();
		}

		/**
		 * Filters the price steps of a counted variation.
		 *
		 * @param array<int, array{upto: ?int, rate: float}> $steps     Steps.
		 * @param \WC_Product                              $variation Variation.
		 * @param string                                    $currency  Currency code.
		 */
		$steps = apply_filters( 'beltoft_services_unit_steps', $out, $variation, $code );

		return is_array( $steps ) ? array_values( $steps ) : array();
	}

	/**
	 * The price of one counted unit in a currency, excluding VAT, or null when there is none (such a currency is not metered).
	 *
	 * @param \WC_Product $variation Counted variation.
	 * @param string      $currency  Currency code, '' for the shop's.
	 */
	public static function unit_price( \WC_Product $variation, string $currency = '' ): ?float {
		$code = Currency::clean( $currency );
		$code = '' !== $code ? $code : Currency::base();
		$meta = Currency::base() === $code ? (string) $variation->get_meta( self::META_UNIT_PRICE ) : (string) $variation->get_meta( self::META_UNIT_PRICE . '_' . $code );
		$price = is_numeric( $meta ) && (float) $meta > 0 ? (float) $meta : null;

		return is_numeric( $price ) && (float) $price > 0 ? (float) $price : null;
	}

	/**
	 * The premium in percent for a product (or variation).
	 *
	 * @param int $product_id Product id.
	 */
	public static function premium( int $product_id ): float {
		$parent = 'product_variation' === get_post_type( $product_id ) ? (int) wp_get_post_parent_id( $product_id ) : $product_id;
		$value  = (string) get_post_meta( $parent, self::META_PREMIUM, true );

		return is_numeric( $value ) ? max( 0.0, (float) $value ) : self::DEFAULT_PREMIUM;
	}

	/**
	 * Price per hour for a monthly price.
	 *
	 * @param float $monthly Monthly price.
	 * @param float $premium Percent.
	 */
	public static function hourly( float $monthly, float $premium ): float {
		return round( $monthly * ( 1 + $premium / 100 ) / self::HOURS, 8 );
	}

	/**
	 * The most one resource's item costs in a month.
	 *
	 * @param float $monthly Monthly price.
	 * @param float $premium Percent.
	 */
	public static function cap( float $monthly, float $premium ): float {
		return round( $monthly * ( 1 + $premium / 100 ), 2 );
	}

	/**
	 * An amount for people as plain text, the way the shop shows prices ("€16.50", "€0.0226"): wc_price without its
	 * markup. Spaces in it are non-breaking, so an amount never wraps inside.
	 *
	 * @param float            $amount   Amount.
	 * @param int              $decimals Decimals.
	 * @param string           $currency Currency code, '' for the shop's.
	 * @param \WC_Product|null $tax      A pay as you go catalogue figure: the product it belongs to, so the figure is the shop's tax display of it at the standard tax class, the one the usage invoice is taxed at (see TaxNote::usage_figure()). Null (invoices, API answers): the amount as given.
	 */
	public static function money( float $amount, int $decimals = 2, string $currency = '', ?\WC_Product $tax = null ): string {
		return TaxNote::plain( TaxNote::usage_figure( $amount, $tax ), $decimals, $currency );
	}

	/**
	 * A monthly cap for people: a whole amount has no decimals ("€55"), any other has two ("€16.50").
	 *
	 * @param float            $cap Monthly cap.
	 * @param \WC_Product|null $tax Product for the tax display, null for the amount as given.
	 */
	public static function cap_money( float $cap, ?\WC_Product $tax = null ): string {
		return self::cap_shown( TaxNote::usage_figure( $cap, $tax ) );
	}

	/**
	 * An amount that is already the figure to show, formatted like cap_money(): whole amounts have no decimals.
	 *
	 * @param float $shown Amount as shown.
	 */
	public static function cap_shown( float $shown ): string {
		return TaxNote::plain( $shown, abs( $shown - round( $shown ) ) < 0.005 ? 0 : 2 );
	}

	/**
	 * A pay as you go figure of a cart line in the CART's tax display, for a theme that builds its own order summary: the hourly rate (four
	 * decimals) or a monthly cap (whole amounts without decimals), at the standard tax class the usage invoice is taxed at. Plain text, or
	 * with $html the same through beltoft_services_price_html (context 'cart-rate'), escaped. Pinned in tests/fixtures/external-api.php.
	 *
	 * @param float            $amount  Amount excluding tax (an hourly rate or a cap).
	 * @param string           $kind    'hourly' or 'cap'.
	 * @param \WC_Product|null $product A product or variation of the cart line, for the tax display; null: the amount as given.
	 * @param bool             $html    Pass the filter and return markup.
	 */
	public static function in_cart( float $amount, string $kind, ?\WC_Product $product, bool $html = false ): string {
		$render = static fn(): string => 'hourly' === $kind ? self::money( $amount, 4, '', $product ) : self::cap_money( $amount, $product );

		if ( ! $html ) {
			return (string) TaxNote::in_cart( $render );
		}

		return wp_kses_post(
			TaxNote::html(
				static fn( string $display = '' ): string => esc_html( (string) ( '' === $display ? TaxNote::in_cart( $render ) : TaxNote::in( $display, $render ) ) ),
				'cart-rate'
			)
		);
	}

	/**
	 * "€45 / month": a monthly amount in whole euros (see cap_money()) in the order summary.
	 *
	 * @param float            $amount Monthly amount.
	 * @param \WC_Product|null $tax    Product for the tax display, null for the amount as given.
	 * @param float|null       $shown  The figure to show, already worked out (it replaces the amount).
	 */
	public static function per_month_line( float $amount, ?\WC_Product $tax = null, ?float $shown = null ): string {
		/* translators: %s: monthly amount such as €45 */
		return sprintf( __( '%s / month', 'beltoft-services' ), null !== $shown ? self::cap_shown( $shown ) : self::cap_money( $amount, $tax ) );
	}

	/**
	 * "+€5 / month": an add-on's monthly amount in the order summary.
	 *
	 * @param float            $amount Monthly amount.
	 * @param \WC_Product|null $tax    Product for the tax display, null for the amount as given.
	 * @param float|null       $shown  The figure to show, already worked out (it replaces the amount).
	 */
	public static function plus_per_month_line( float $amount, ?\WC_Product $tax = null, ?float $shown = null ): string {
		/* translators: %s: monthly amount such as €5 */
		return sprintf( __( '+%s / month', 'beltoft-services' ), null !== $shown ? self::cap_shown( $shown ) : self::cap_money( $amount, $tax ) );
	}

	/**
	 * "+€5 per month": an add-on's monthly amount in a label.
	 *
	 * @param float            $amount Monthly amount.
	 * @param \WC_Product|null $tax    Product for the tax display, null for the amount as given.
	 */
	public static function plus_per_month( float $amount, ?\WC_Product $tax = null ): string {
		/* translators: %s: monthly amount such as €5 */
		return sprintf( __( '+%s per month', 'beltoft-services' ), self::cap_money( $amount, $tax ) );
	}

	/**
	 * The most an add-on costs in a month: the difference between two pay-as-you-go variations, from their monthly
	 * siblings and the product's premium (the same figures as the hourly rate and the cap).
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function monthly_difference( \WC_Product_Variation $from, \WC_Product_Variation $to ): float {
		$was = self::monthly( $from );
		$now = self::monthly( $to );

		return null === $was || null === $now ? 0.0 : self::cap( $now - $was, self::premium( $to->get_parent_id() ) );
	}

	/**
	 * "+€5 per month" for an add-on that is a pay-as-you-go variation, or null when the target is not pay as you go
	 * (the monthly label keeps its wording).
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function addon_per_month( \WC_Product_Variation $from, \WC_Product_Variation $to ): ?string {
		return self::is_usage( $to ) ? self::plus_per_month( self::monthly_difference( $from, $to ), $to ) : null;
	}

	/**
	 * "Or €0.0226/hour, max €16.50/month, pay as you go" for a pay as you go variation, or '' when it has no price.
	 * The slash is followed by a word joiner, so a line never breaks inside "€0.0226/hour".
	 *
	 * @param \WC_Product_Variation $usage Pay as you go variation.
	 */
	public static function line( \WC_Product_Variation $usage ): string {
		$rates = self::for_usage( $usage );

		if ( null === $rates ) {
			return '';
		}

		/* translators: 1: hourly price such as €0.0226, 2: monthly cap such as €16.50 */
		return str_replace( '/', "/\u{2060}", sprintf( __( 'Or %1$s/hour, max %2$s/month, pay as you go', 'beltoft-services' ), self::money( $rates['hourly'], 4, '', $usage ), self::cap_money( $rates['cap'], $usage ) ) );
	}

	/**
	 * The terms of pay as you go, next to the choice: "€0.0226 per hour, max €16.50 per month excluding VAT".
	 *
	 * @param float            $hourly Price per hour.
	 * @param float            $cap    Monthly cap.
	 * @param \WC_Product|null $tax    Product for the tax display, null for the amounts as given (excluding VAT).
	 */
	public static function terms( float $hourly, float $cap, ?\WC_Product $tax = null ): string {
		// A pay as you go figure is taxed on the invoice whatever the product's own tax status, so the words follow the display alone.
		if ( null !== $tax && 'incl' === TaxNote::display() ) {
			/* translators: %s: the terms, such as "€0.0283 per hour, max €20.60 per month" */
			return sprintf( __( '%s including VAT', 'beltoft-services' ), self::tile_terms( $hourly, $cap, $tax ) );
		}

		/* translators: %s: the terms, such as "€0.0226 per hour, max €16.50 per month" */
		return sprintf( __( '%s excluding VAT', 'beltoft-services' ), self::tile_terms( $hourly, $cap, $tax ) );
	}

	/**
	 * The rate and the cap of a pay-as-you-go variation (excluding tax): its monthly sibling's price and the
	 * product's premium. Null when the variation is not pay as you go or has no monthly price.
	 *
	 * @param mixed $usage Product of a line.
	 * @return array{hourly: float, cap: float}|null
	 */
	public static function for_usage( $usage ): ?array {
		if ( ! $usage instanceof \WC_Product_Variation || ! self::is_usage( $usage ) ) {
			return null;
		}

		$monthly = self::monthly( $usage );

		if ( null === $monthly || $monthly <= 0 ) {
			return null;
		}

		$premium = self::premium( $usage->get_parent_id() );

		return array( 'hourly' => self::hourly( $monthly, $premium ), 'cap' => self::cap( $monthly, $premium ) );
	}

	/**
	 * What a pay as you go add-on variation (an extra disk size, an IPv4 address) costs at most in a month, for a tile or a line:
	 * "+€4.40 per month at most, billed by the hour". Empty when the variation has no pay as you go price.
	 *
	 * @param \WC_Product_Variation $variation Pay as you go variation.
	 */
	public static function addon_terms( \WC_Product_Variation $variation ): string {
		$rates = self::for_usage( $variation );

		/* translators: %s: monthly amount such as +€4.40 per month */
		return null === $rates ? '' : sprintf( __( '%s at most, billed by the hour', 'beltoft-services' ), self::plus_per_month( $rates['cap'], $variation ) );
	}

	/**
	 * The one sentence that says how pay as you go is billed.
	 */
	public static function billed_note(): string {
		return __( 'Billed monthly for the hours used', 'beltoft-services' );
	}

	/**
	 * "€0.0829 per hour".
	 *
	 * @param float            $hourly Price per hour.
	 * @param \WC_Product|null $tax    Product for the tax display, null for the amount as given.
	 */
	public static function per_hour( float $hourly, ?\WC_Product $tax = null ): string {
		/* translators: %s: price per hour such as €0.0829 */
		return sprintf( __( '%s per hour', 'beltoft-services' ), self::money( $hourly, 4, '', $tax ) );
	}

	/**
	 * "max €55.00 per month".
	 *
	 * @param float            $cap Monthly cap.
	 * @param \WC_Product|null $tax Product for the tax display, null for the amount as given.
	 */
	public static function max_per_month( float $cap, ?\WC_Product $tax = null ): string {
		/* translators: %s: monthly cap such as €55.00 */
		return sprintf( __( 'max %s per month', 'beltoft-services' ), self::cap_money( $cap, $tax ) );
	}

	/**
	 * The first line of a pay as you go price: "€0.0829 per hour, max €55.00 per month".
	 *
	 * @param float            $hourly Price per hour.
	 * @param float            $cap    Monthly cap.
	 * @param \WC_Product|null $tax    Product for the tax display, null for the amounts as given.
	 */
	public static function tile_terms( float $hourly, float $cap, ?\WC_Product $tax = null ): string {
		/* translators: 1: "€0.0829 per hour", 2: "max €55.00 per month" */
		return sprintf( __( '%1$s, %2$s', 'beltoft-services' ), self::per_hour( $hourly, $tax ), self::max_per_month( $cap, $tax ) );
	}

	/**
	 * The monthly price (excluding tax) a pay-as-you-go variation is priced from: its own meta `_beltoft_services_usage_monthly` in the
	 * shop's currency, this name plus an underscore and the code in another. A missing figure is no price (null), never a relabelled
	 * figure of another currency. A variation that is not pay as you go has none.
	 *
	 * @param \WC_Product_Variation $v Pay-as-you-go variation.
	 */
	public static function monthly( \WC_Product_Variation $v ): ?float {
		if ( ! self::is_usage( $v ) ) {
			return null;
		}

		$code = Currency::current();
		$meta = (string) $v->get_meta( Currency::base() === $code ? self::META_MONTHLY : self::META_MONTHLY . '_' . $code );

		return is_numeric( $meta ) && (float) $meta > 0 ? (float) $meta : null;
	}

	/**
	 * What a pay-as-you-go resource is billed for each hour: a list of items with their quantity, unit and
	 * rate per unit and hour. The items are the driver's own (Drivers\UsageItems::usage_items()); the ledger item names are theirs.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{item: string, quantity: float, unit: string, rate: float}>
	 */
	public static function items( Resource $r ): array {
		$license = $r->license();
		$vid     = (int) ( $license['variation_id'] ?? 0 );
		$v       = $vid > 0 ? wc_get_product( $vid ) : false;

		// A counted variation has no hourly items: its events are written by Counted\Meter.
		if ( ! $v instanceof \WC_Product_Variation || self::is_counted( $v ) ) {
			return array();
		}

		$monthly = self::monthly( $v );

		if ( null === $monthly ) {
			return array();
		}

		$driver = Items::driver_of( $r );

		// Each driver declares its own hourly items (Drivers\UsageItems).
		return null === $driver ? array() : $driver->usage_items( $r, $v, $monthly, self::premium( $v->get_parent_id() ) );
	}
}

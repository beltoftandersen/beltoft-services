<?php
/**
 * The currency helpers of the core.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Which currency a price or an amount is in. The plugin reads prices and currencies through WooCommerce only;
 * where a site decides something per currency it answers one of the filters here:
 * beltoft_services_currency_runner, beltoft_services_user_currency and beltoft_services_amount_setting.
 * With a single currency and no listener every method answers what the plugin always did.
 */
final class Currency {

	/** The currency suppliers (the registrar, the licence vendor) are paid in: the currency a cost is stored in. */
	public const COST = 'EUR';

	/** Cart item key: the currency a runtime price (a domain line, a top-up line) was worked out in. A site's bridge reads it as a string. */
	public const CART_KEY = 'beltoft_services_price_currency';

	/**
	 * The shop's own currency: the option, never the filtered getter, so a forced currency does not change it.
	 */
	public static function base(): string {
		return (string) get_option( 'woocommerce_currency' );
	}

	/**
	 * The currency WooCommerce prices in right now (a visitor's, a forced one, or the shop's).
	 */
	public static function current(): string {
		return (string) get_woocommerce_currency();
	}

	/**
	 * The currency to price a customer in. Only the filter decides: listeners run in priority order and the first
	 * non-empty answer stays. Empty or not a three letter code gives the shop's currency.
	 *
	 * @param int $user_id User id.
	 */
	public static function of_user( int $user_id ): string {
		$code = self::clean( apply_filters( 'beltoft_services_user_currency', '', $user_id ) );

		return '' !== $code ? $code : self::base();
	}

	/**
	 * The currency a customer is in when someone has fixed it, else the current one (a new account made on a page in that page's currency).
	 *
	 * @param int $user_id User id.
	 */
	public static function of_user_or_current( int $user_id ): string {
		$code = self::clean( apply_filters( 'beltoft_services_user_currency', '', $user_id ) );

		return '' !== $code ? $code : self::current();
	}

	/**
	 * Runs a callback with a currency in force. Fails closed: a price is never read under a currency we did not get.
	 *
	 * The current currency (or an empty code) runs the callback as it is. For another one the filter
	 * beltoft_services_currency_runner must give a callable function ( string $code, callable $fn ); with none, or when the
	 * runner forced nothing, the callback does not run. The runner MUST restore the currency it forced in a `finally`, also when the
	 * callback throws: this class does not touch another plugin's state, but it checks after the runner returns and alerts the admin
	 * once when the current currency is not what it was before the call.
	 *
	 * @param string   $code Currency code; a literal '' means the current one, any other value that is not a three letter code fails closed.
	 * @param callable $fn   Callback.
	 * @return array{0: bool, 1: mixed} Whether it ran, and its result (null when it did not).
	 */
	public static function with( string $code, callable $fn ): array {
		if ( '' === $code ) {
			return array( true, $fn() );
		}

		// Only a literal empty code means the current currency; a code that is not a currency never runs the callback.
		$code = self::clean( $code );

		if ( '' === $code ) {
			return array( false, null );
		}

		if ( self::current() === $code ) {
			return array( true, $fn() );
		}

		$runner = apply_filters( 'beltoft_services_currency_runner', null );

		if ( ! is_callable( $runner ) ) {
			return array( false, null );
		}

		$ran    = false;
		$result = null;
		$before = self::current();

		$runner(
			$code,
			function () use ( $code, $fn, &$ran, &$result ) {
				if ( self::current() !== $code ) {
					return null;
				}

				$ran    = true;
				$result = $fn();

				return $result;
			}
		);

		if ( self::current() !== $before ) {
			Alerts::notify(
				__( 'A currency runner did not restore the currency', 'beltoft-services' ),
				array( sprintf( 'After forcing %1$s the runner left the current currency at %2$s instead of %3$s. A runner must restore the currency in a finally block. Prices read after this call may be in the wrong currency.', $code, self::current(), $before ) ),
				null,
				'beltoft_services_currency_leak'
			);
		}

		return $ran ? array( true, $result ) : array( false, null );
	}

	/**
	 * An amount setting in a currency. For the shop's currency it is the setting, passed through the filter
	 * beltoft_services_amount_setting. For another currency the filter gets null and answers the amount, or leaves
	 * null, which means there is no amount for that currency: the setting's number is never reused for another currency.
	 *
	 * @param string $key      Setting key.
	 * @param string $currency Currency code, '' for the current one.
	 * @return mixed The amount, or null for none.
	 */
	public static function amount( string $key, string $currency = '' ) {
		$currency = self::or_current( $currency );
		$value    = self::base() === $currency ? Settings::get( $key ) : null;

		return apply_filters( 'beltoft_services_amount_setting', $value, $key, $currency );
	}

	/**
	 * The guard rate of a currency: how many units of it are worth one unit of the cost currency, deliberately a little above the real
	 * rate (O2, about 2 percent), so a price converted back is understated and a mispriced line is refused rather than paid for. From the
	 * setting currency_guard_rates (lines such as DKK=7.60) and the filter beltoft_services_guard_rate. Null when there is none: never 1 to 1.
	 *
	 * @param string $code Currency code.
	 */
	public static function guard_rate( string $code ): ?float {
		$code = self::clean( $code );
		$rate = null;

		if ( '' === $code ) {
			return null;
		}

		$text = Settings::get( 'currency_guard_rates' );

		foreach ( is_string( $text ) ? preg_split( '/\r?\n/', $text ) : array() as $line ) {
			if ( 1 === preg_match( '/^\s*([A-Za-z]{3})\s*=\s*([0-9]+(?:[.,][0-9]+)?)\s*$/D', (string) $line, $m ) && strtoupper( $m[1] ) === $code ) {
				$rate = (float) str_replace( ',', '.', $m[2] );
			}
		}

		/**
		 * The guard rate of a currency against the cost currency: units of the currency per unit of EUR, a little above the real rate.
		 *
		 * @param float|null $rate Rate from the setting, or null.
		 * @param string     $code Currency code.
		 */
		$rate = apply_filters( 'beltoft_services_guard_rate', $rate, $code );

		return is_numeric( $rate ) && (float) $rate > 0 ? (float) $rate : null;
	}

	/**
	 * What an amount in a currency is worth in the cost currency, for comparing what a customer paid with what a supplier is paid. Nothing
	 * here is ever shown to or charged to a customer. The cost currency itself is 1 to 1; any other needs a guard rate; without one
	 * the answer is null (the caller refuses) and the admin is told once per currency.
	 *
	 * @param float  $amount Amount in $code.
	 * @param string $code   Currency code.
	 */
	public static function to_cost( float $amount, string $code ): ?float {
		$code = self::clean( $code );

		if ( self::COST === $code ) {
			return $amount;
		}

		$rate = self::guard_rate( $code );

		if ( null === $rate ) {
			Alerts::notify(
				/* translators: %s: currency code */
				sprintf( __( 'No guard rate for %s', 'beltoft-services' ), '' !== $code ? $code : '?' ),
				array( sprintf( 'An order in %1$s could not be compared with a supplier cost in %2$s because the setting currency_guard_rates has no line for %1$s. The supplier order was not placed. Add a line such as %1$s=7.60 (units of %1$s per %2$s, a little above the real rate).', '' !== $code ? $code : '?', self::COST ) ),
				null,
				'beltoft_services_guard_rate_' . strtolower( $code )
			);

			return null;
		}

		return $amount / $rate;
	}

	/**
	 * The same for a catalogue price (a variation's price, a pay as you go cap): the shop's own currency is the one its costs are set
	 * against, so a price in it is compared as it is, as it always was; a price in any other currency is converted at its guard rate, and
	 * refused without one.
	 *
	 * @param float  $amount Price in $code.
	 * @param string $code   Currency code the price is in.
	 */
	public static function price_to_cost( float $amount, string $code ): ?float {
		return self::clean( $code ) === self::base() ? $amount : self::to_cost( $amount, $code );
	}

	/**
	 * A currency code as a caller gave it, or the current currency when it is empty or not a code.
	 *
	 * @param string $code Currency code.
	 */
	public static function or_current( string $code ): string {
		$code = self::clean( $code );

		return '' !== $code ? $code : self::current();
	}

	/**
	 * The currency of a ledger row (usage or credit): its currency column, and an empty or missing one reads as the shop's currency.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function of_row( array $row ): string {
		$code = self::clean( $row['currency'] ?? '' );

		return '' !== $code ? $code : self::base();
	}

	/**
	 * A currency code in capitals, or '' when the value is not a three letter code.
	 *
	 * @param mixed $code Value.
	 */
	public static function clean( $code ): string {
		$code = strtoupper( trim( (string) ( is_scalar( $code ) ? $code : '' ) ) );

		return 1 === preg_match( '/^[A-Z]{3}$/D', $code ) ? $code : '';
	}
}

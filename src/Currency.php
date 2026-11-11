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
	 * Runs a callback with a currency in force. Fails closed: a price is never read under a currency we did not get.
	 *
	 * The current currency (or an empty code) runs the callback as it is. For another one the filter
	 * beltoft_services_currency_runner must give a callable function ( string $code, callable $fn ); with none, or when the
	 * runner forced nothing, the callback does not run.
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

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

	/** @var array<string, string> Currency of a resource's subscription by resource and subscription id, for the request. */
	private static array $resource_codes = array();

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
	 * The currencies this shop sells in: its own, those with a line in the guard rates, and any a site adds through the filter
	 * beltoft_services_currencies. One entry on a shop with a single currency.
	 *
	 * @return string[]
	 */
	public static function known_codes(): array {
		$codes = array( self::base() );

		foreach ( array_keys( self::parse_guard_rates( (string) Settings::get( 'currency_guard_rates' ) )['rates'] ) as $code ) {
			$codes[] = $code;
		}

		/**
		 * The currencies of this shop, for the admin screens that offer a choice of currency.
		 *
		 * @param string[] $codes Shop currency and the currencies with a guard rate.
		 */
		$filtered = apply_filters( 'beltoft_services_currencies', $codes );

		return array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean' ), (array) $filtered ) ) ) );
	}

	/**
	 * The currency of an order or subscription: its own code, else the shop's.
	 *
	 * @param \WC_Abstract_Order $order Order or subscription.
	 */
	public static function of_order( $order ): string {
		$code = $order instanceof \WC_Abstract_Order ? self::clean( $order->get_currency() ) : '';

		return '' !== $code ? $code : self::base();
	}

	/**
	 * The currency of the subscription behind a service, or '' when it has none (the current currency then applies).
	 *
	 * @param Resource $r Resource.
	 */
	public static function of_resource( Resource $r ): string {
		$id  = (int) ( $r->license()['subscription_id'] ?? 0 );
		$key = $r->id() . ':' . $id;

		// One answer per resource for the request: a panel asks for it once per option otherwise (one subscription query each).
		if ( ! isset( self::$resource_codes[ $key ] ) ) {
			$sub                          = $id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : false;
			self::$resource_codes[ $key ] = $sub instanceof \WC_Subscription ? self::of_order( $sub ) : '';
		}

		return self::$resource_codes[ $key ];
	}

	/**
	 * Forgets the per-request answers (tests, and a request that changes a subscription's currency).
	 */
	public static function reset(): void {
		self::$resource_codes = array();
	}

	/**
	 * Runs a callback in the currency of a service's subscription (prices shown for an existing service, and compared with its own, are
	 * in that currency, never the request's). With no such currency in force the default comes back, so nothing is shown at a figure of another.
	 *
	 * @param Resource $r       Resource.
	 * @param callable $fn      Callback.
	 * @param mixed    $default Answer when the currency cannot be put in force.
	 * @return mixed
	 */
	public static function with_resource( Resource $r, callable $fn, $default = null ) {
		list( $ok, $result ) = self::with( self::of_resource( $r ), $fn );

		return $ok ? $result : $default;
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

		$parsed = self::parse_guard_rates( (string) Settings::get( 'currency_guard_rates' ) );
		$rate   = $parsed['rates'][ $code ] ?? null;

		/**
		 * The guard rate of a currency against the cost currency: units of the currency per unit of EUR, a little above the real rate.
		 *
		 * @param float|null $rate Rate from the setting, or null.
		 * @param string     $code Currency code.
		 */
		$rate = apply_filters( 'beltoft_services_guard_rate', $rate, $code );

		return is_numeric( $rate ) && self::plausible_rate( (float) $rate, $code ) ? (float) $rate : null;
	}

	/**
	 * Reads the guard rates text strictly: one line per currency, a code, an equals sign and a plain decimal with a dot (DKK=7.60). A comma
	 * is not a decimal point here (DKK=1,000 is not 1.0), so anything else is a bad line, never a guessed rate.
	 *
	 * @param string $text The setting.
	 * @return array{rates: array<string, float>, bad: string[]} Rates by code (the last line of a code wins, a rate outside its bounds is bad) and the lines that are not usable.
	 */
	public static function parse_guard_rates( string $text ): array {
		$rates = array();
		$bad   = array();

		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( 1 !== preg_match( '/^([A-Za-z]{3})\s*=\s*([0-9]+(?:\.[0-9]+)?)$/D', $line, $m ) || ! self::plausible_rate( (float) $m[2], strtoupper( $m[1] ) ) ) {
				$bad[] = $line;
				continue;
			}

			$rates[ strtoupper( $m[1] ) ] = (float) $m[2];
		}

		return array( 'rates' => $rates, 'bad' => $bad );
	}

	/**
	 * Whether a guard rate is believable: by default from 1 to 1000 units per euro. A currency outside that (the pound is below 1, some
	 * currencies are in the thousands) needs the filter beltoft_services_guard_rate_bounds to say so.
	 *
	 * @param float  $rate Rate.
	 * @param string $code Currency code.
	 */
	public static function plausible_rate( float $rate, string $code ): bool {
		/**
		 * The lowest and highest guard rate that is accepted for a currency.
		 *
		 * @param float[] $bounds Lowest and highest, 1 and 1000 by default.
		 * @param string  $code   Currency code.
		 */
		$bounds = (array) apply_filters( 'beltoft_services_guard_rate_bounds', array( 1.0, 1000.0 ), strtoupper( $code ) );
		$low    = isset( $bounds[0] ) ? (float) $bounds[0] : 1.0;
		$high   = isset( $bounds[1] ) ? (float) $bounds[1] : 1000.0;

		return $rate > 0 && $rate >= $low && $rate <= $high;
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

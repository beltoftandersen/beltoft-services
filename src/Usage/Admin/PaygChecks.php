<?php
/**
 * The checks of a pay as you go product: one list for the product editor and WP-CLI.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Admin;

use BeltoftServices\Currency;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. An error is a setup that would sell a service nobody can bill; a warning is a setup that sells less than the admin meant.
 * A product billed monthly has nothing to check.
 */
final class PaygChecks {

	public const OK    = 'ok';
	public const WARN  = 'warn';
	public const ERROR = 'error';

	/**
	 * The checks and what each one says when it passes.
	 *
	 * @return array<string, string>
	 */
	private static function titles(): array {
		return array(
			'type'     => __( 'The product is a Variable product', 'beltoft-services' ),
			'rate'     => __( 'Every hourly plan has a monthly price to take its rate from', 'beltoft-services' ),
			'units'    => __( 'Every per unit plan has a unit price or valid price steps', 'beltoft-services' ),
			'premium'  => __( 'The premium is a number of 0 or more', 'beltoft-services' ),
			'currency' => __( 'Every currency the shop sells in has a rate', 'beltoft-services' ),
			'account'  => __( 'The usage account product exists', 'beltoft-services' ),
		);
	}

	/**
	 * Runs every check. An empty list for a product billed monthly.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int, array{code: string, level: string, text: string, plans: array<int, string>}>
	 */
	public static function run( \WC_Product $product ): array {
		$out = array();

		if ( '' === PaygProduct::kind( $product ) ) {
			return $out;
		}

		foreach ( self::titles() as $code => $title ) {
			$problem = self::{'check_' . $code}( $product );
			$out[]   = null === $problem
				? array( 'code' => $code, 'level' => self::OK, 'text' => $title, 'plans' => array() )
				: array( 'code' => $code, 'level' => $problem[0], 'text' => $problem[1], 'plans' => $problem[2] ?? array() );
		}

		return $out;
	}

	/**
	 * Is any check an error?
	 *
	 * @param array<int, array{level: string}> $checks Result of run().
	 */
	public static function has_error( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( self::ERROR === $check['level'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The published variations of the product.
	 *
	 * @param \WC_Product $p Product.
	 * @return \WC_Product_Variation[]
	 */
	private static function on_sale( \WC_Product $p ): array {
		return array_values( array_filter( PaygProduct::variations( $p ), static fn( $v ) => 'publish' === $v->get_status() ) );
	}

	/**
	 * A subscription type (or a simple product) with a billing kind.
	 *
	 * @param \WC_Product $p Product.
	 * @return array{0: string, 1: string, 2?: array<int, string>}|null
	 */
	private static function check_type( \WC_Product $p ): ?array {
		return 'variable' === $p->get_type() ? null : array( self::ERROR, __( 'Pay as you go needs a Variable product, not a subscription product. Create it as a Variable product.', 'beltoft-services' ) );
	}

	/**
	 * An hourly variation without a monthly price above 0 in the shop currency.
	 *
	 * @param \WC_Product $p Product.
	 * @return array{0: string, 1: string, 2?: array<int, string>}|null
	 */
	private static function check_rate( \WC_Product $p ): ?array {
		$bad = array();

		if ( Rates::KIND_HOURLY === PaygProduct::kind( $p ) ) {
			foreach ( self::on_sale( $p ) as $v ) {
				if ( null === PaygProduct::monthly( $v, Currency::base() ) ) {
					$bad[ $v->get_id() ] = PaygProduct::label( $v );
				}
			}
		}

		return $bad ? array( self::ERROR, __( 'No monthly price above 0 to take the rate from. Plans:', 'beltoft-services' ), $bad ) : null;
	}

	/**
	 * A per unit variation without a unit price in the shop currency, or price steps that do not parse.
	 *
	 * @param \WC_Product $p Product.
	 * @return array{0: string, 1: string, 2?: array<int, string>}|null
	 */
	private static function check_units( \WC_Product $p ): ?array {
		$bad = array();

		if ( Rates::KIND_COUNTED === PaygProduct::kind( $p ) ) {
			foreach ( self::on_sale( $p ) as $v ) {
				$broken = false;

				foreach ( Currency::known_codes() as $code ) {
					$broken = $broken || null === PaygSteps::parse( (string) $v->get_meta( PaygProduct::key( Rates::META_UNIT_STEPS, $code ) ) );
				}

				if ( $broken || ! Rates::unit_steps( $v, Currency::base() ) ) {
					$bad[ $v->get_id() ] = PaygProduct::label( $v );
				}
			}
		}

		return $bad ? array( self::ERROR, __( 'No unit price in the shop currency, or price steps that cannot be read. Plans:', 'beltoft-services' ), $bad ) : null;
	}

	/**
	 * A premium below 0 or not a number.
	 *
	 * @param \WC_Product $p Product.
	 * @return array{0: string, 1: string, 2?: array<int, string>}|null
	 */
	private static function check_premium( \WC_Product $p ): ?array {
		if ( Rates::KIND_HOURLY !== PaygProduct::kind( $p ) ) {
			return null;
		}

		$value = trim( (string) $p->get_meta( Rates::META_PREMIUM ) );

		if ( '' !== $value && ( ! is_numeric( $value ) || (float) $value < 0 ) ) {
			return array( self::ERROR, __( 'The premium is not a number of 0 or more.', 'beltoft-services' ) );
		}

		return '' !== $value && (float) $value > 100 ? array( self::WARN, __( 'The premium is above 100 percent: the monthly cap is more than double the monthly price.', 'beltoft-services' ) ) : null;
	}

	/**
	 * A currency the shop sells in (other than the shop's own, which the rate and unit checks cover) with no rate.
	 *
	 * @param \WC_Product $p Product.
	 * @return array{0: string, 1: string, 2?: array<int, string>}|null
	 */
	private static function check_currency( \WC_Product $p ): ?array {
		$kind    = PaygProduct::kind( $p );
		$missing = array();
		$plans   = array();

		foreach ( self::on_sale( $p ) as $v ) {
			foreach ( Currency::known_codes() as $code ) {
				if ( Currency::base() === $code ) {
					continue;
				}

				$has = Rates::KIND_COUNTED === $kind ? array() !== Rates::unit_steps( $v, $code ) : null !== PaygProduct::monthly( $v, $code );

				if ( ! $has ) {
					$missing[ $code ]      = $code;
					$plans[ $v->get_id() ] = PaygProduct::label( $v );
				}
			}
		}

		/* translators: %s: currency codes such as DKK */
		return $missing ? array( self::WARN, sprintf( __( 'No rate in %s: customers in that currency cannot buy. Plans:', 'beltoft-services' ), implode( ', ', $missing ) ), $plans ) : null;
	}

	/**
	 * The usage account product is missing.
	 *
	 * @param \WC_Product $p Product.
	 * @return array{0: string, 1: string, 2?: array<int, string>}|null
	 */
	private static function check_account( \WC_Product $p ): ?array {
		$id = (int) get_option( Account::OPTION_PRODUCT );

		return $id > 0 && wc_get_product( $id ) instanceof \WC_Product ? null : array( self::WARN, __( 'The usage account product is missing: nobody can buy pay as you go until it exists.', 'beltoft-services' ) );
	}
}

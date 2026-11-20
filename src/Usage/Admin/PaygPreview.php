<?php
/**
 * The rates of a pay as you go product, per plan and currency, as the meter will read them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Admin;

use BeltoftServices\Currency;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. One row per published variation of the product. An hourly plan shows its monthly price, the hourly rate and the cap
 * (Rates::hourly() and Rates::cap()); a per unit plan shows what 1,000 units cost. A currency without a price shows nothing, never a guess.
 */
final class PaygPreview {

	public const EXAMPLE_UNITS = 1000;

	/**
	 * The rows.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int, array{id: int, name: string, prices: array<string, array{monthly: ?float, hourly: ?float, cap: ?float, example: ?float}>}>
	 */
	public static function rows( \WC_Product $product ): array {
		$kind    = PaygProduct::kind( $product );
		$premium = Rates::premium( $product->get_id() );
		$rows    = array();

		foreach ( PaygProduct::variations( $product ) as $v ) {
			if ( 'publish' !== $v->get_status() ) {
				continue;
			}

			$prices = array();

			foreach ( Currency::known_codes() as $code ) {
				$monthly = Rates::KIND_HOURLY === $kind ? PaygProduct::monthly( $v, $code ) : null;
				$steps   = Rates::KIND_COUNTED === $kind ? Rates::unit_steps( $v, $code ) : array();

				$prices[ $code ] = array(
					'monthly' => $monthly,
					'hourly'  => null === $monthly ? null : Rates::hourly( $monthly, $premium ),
					'cap'     => null === $monthly ? null : Rates::cap( $monthly, $premium ),
					'example' => $steps ? PaygSteps::cost( $steps, self::EXAMPLE_UNITS ) : null,
				);
			}

			$rows[] = array(
				'id'     => $v->get_id(),
				'name'   => PaygProduct::label( $v ),
				'prices' => $prices,
			);
		}

		return $rows;
	}
}

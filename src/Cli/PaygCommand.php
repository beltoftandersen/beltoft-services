<?php
/**
 * WP-CLI: the pay as you go setup of a product, the same checks as the product editor.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Usage\Admin\PaygChecks;
use BeltoftServices\Usage\Admin\PaygPreview;
use BeltoftServices\Usage\Admin\PaygProduct;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Prints what the Billing block of the Service panel prints. Nothing here bills, meters or writes.
 */
final class PaygCommand {

	/**
	 * Prints the rates of a pay as you go product and the checks.
	 *
	 * ## OPTIONS
	 *
	 * <product>
	 * : Product id or SKU.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services payg check 123
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function check( array $args, array $assoc_args ): void {
		$product = $this->product( (string) ( $args[0] ?? '' ) );

		if ( ! PaygProduct::offers( $product ) ) {
			\WP_CLI::success( 'This service cannot be billed by use.' );

			return;
		}

		$kind = PaygProduct::kind( $product );

		if ( '' === $kind ) {
			\WP_CLI::success( 'Billed as a monthly subscription. Nothing to check.' );

			return;
		}

		\WP_CLI::log( 'Billing: ' . ( Rates::KIND_COUNTED === $kind ? 'pay per unit' : 'pay as you go by the hour' ) );

		foreach ( PaygPreview::rows( $product ) as $row ) {
			$line = array();

			foreach ( $row['prices'] as $code => $p ) {
				if ( Rates::KIND_COUNTED === $kind ) {
					$line[] = null === $p['example'] ? "$code no price" : sprintf( '%s %d units %.2f', $code, PaygPreview::EXAMPLE_UNITS, $p['example'] );
				} else {
					$line[] = null === $p['monthly'] ? "$code no price" : sprintf( '%s %.2f monthly, %.4f per hour, max %.2f', $code, $p['monthly'], $p['hourly'], $p['cap'] );
				}
			}

			\WP_CLI::log( sprintf( '#%d %s: %s', $row['id'], $row['name'], implode( '; ', $line ) ) );
		}

		$checks = PaygChecks::run( $product );

		foreach ( $checks as $check ) {
			\WP_CLI::log( sprintf( '[%s] %s', strtoupper( $check['level'] ), $check['text'] ) );
		}

		if ( PaygChecks::has_error( $checks ) ) {
			\WP_CLI::error( 'The pay as you go setup has errors.' );
		}

		\WP_CLI::success( 'No errors.' );
	}

	/**
	 * Finds a product by id or SKU.
	 *
	 * @param string $ref Id or SKU.
	 */
	private function product( string $ref ): \WC_Product {
		$id      = ctype_digit( $ref ) ? (int) $ref : (int) wc_get_product_id_by_sku( $ref );
		$product = $id > 0 ? wc_get_product( $id ) : false;

		if ( ! $product instanceof \WC_Product ) {
			\WP_CLI::error( 'No such product.' );
		}

		return $product;
	}
}

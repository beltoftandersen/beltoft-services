<?php
/**
 * The words of a ledger item for a counted unit, in one place.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * A counted item is billed per unit, not per hour, and has no monthly cap. Its ledger item is a base name and, for the second step
 * of a price in steps and later, an underscore and the step number (`order`, `order_2`, `order_3`). The base names and their words
 * are here and can be added to through the filter `beltoft_services_usage_item_defs`; the hourly items (server, database, ...)
 * keep their own lists in Renewal.
 */
final class Items {

	/**
	 * What a ledger item is, or null for an hourly item.
	 *
	 * @param string $item Ledger item.
	 * @return array{base: string, step: int, label: string, singular: string, plural: string}|null
	 */
	public static function counted( string $item ): ?array {
		$base = (string) preg_replace( '/_\d+$/', '', $item );
		$step = 1 === preg_match( '/_(\d+)$/', $item, $m ) ? (int) $m[1] : 1;
		$defs = array(
			'order' => array(
				'label'    => __( 'Orders processed', 'beltoft-services' ),
				'singular' => __( 'order', 'beltoft-services' ),
				'plural'   => __( 'orders', 'beltoft-services' ),
			),
		);

		/**
		 * Filters the counted items: base name => label (the line title), singular and plural (the unit).
		 *
		 * @param array<string, array<string, string>> $defs Definitions.
		 */
		$defs = apply_filters( 'beltoft_services_usage_item_defs', $defs );

		if ( ! is_array( $defs[ $base ] ?? null ) ) {
			return null;
		}

		return array(
			'base'     => $base,
			'step'     => $step,
			'label'    => (string) ( $defs[ $base ]['label'] ?? $base ),
			'singular' => (string) ( $defs[ $base ]['singular'] ?? $base ),
			'plural'   => (string) ( $defs[ $base ]['plural'] ?? $base ),
		);
	}

	/**
	 * The line title of a counted item: "Orders processed" and, from the second step, "Orders processed, step 2".
	 *
	 * @param array{step: int, label: string} $def Definition.
	 */
	public static function title( array $def ): string {
		/* translators: 1: what is counted ("Orders processed"), 2: price step number. */
		return $def['step'] > 1 ? sprintf( __( '%1$s, step %2$d', 'beltoft-services' ), $def['label'], $def['step'] ) : $def['label'];
	}

	/**
	 * "1 order", "380 orders".
	 *
	 * @param array{singular: string, plural: string} $def   Definition.
	 * @param float                                   $units Units.
	 */
	public static function units_text( array $def, float $units ): string {
		$n = (int) round( $units );

		/* translators: 1: number, 2: the unit word, such as "order" or "orders". */
		return sprintf( __( '%1$s %2$s', 'beltoft-services' ), number_format_i18n( $n ), 1 === $n ? $def['singular'] : $def['plural'] );
	}
}

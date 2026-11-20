<?php
/**
 * The price steps text of a per unit variation: parsed strictly, shown as rows and written back in the stored format.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The stored format is the one Usage\Rates::unit_steps() reads ("500:0.025,2000:0.02,0:0.015"). Rates falls back to the single
 * unit price when the text does not parse; the editor refuses such a text instead.
 */
final class PaygSteps {

	/**
	 * Parses a steps text strictly.
	 *
	 * @param string $text Stored text.
	 * @return array<int, array{0: string, 1: string}>|null Rows of [up to as typed ('' for a bare price), price as typed], an empty list for an empty text, null when the text is not valid.
	 */
	public static function parse( string $text ): ?array {
		$text  = trim( $text );
		$rows  = array();
		$last  = 0;
		$ended = false;

		if ( '' === $text ) {
			return array();
		}

		foreach ( explode( ',', $text ) as $part ) {
			$bits = array_map( 'trim', explode( ':', $part ) );

			if ( count( $bits ) > 2 || ! is_numeric( end( $bits ) ) || (float) end( $bits ) <= 0 || $ended ) {
				return null;
			}

			$upto = 2 === count( $bits ) ? $bits[0] : '';

			if ( '' !== $upto && ! ctype_digit( $upto ) ) {
				return null;
			}

			if ( (int) $upto > 0 && (int) $upto <= $last ) {
				return null;
			}

			if ( (int) $upto > 0 ) {
				$last = (int) $upto;
			} else {
				$ended = true;
			}

			$rows[] = array( $upto, end( $bits ) );
		}

		return $rows;
	}

	/**
	 * The rows as the stored text.
	 *
	 * @param array<int, array{0: string, 1: string}> $rows Rows from parse() or the form.
	 */
	public static function compose( array $rows ): string {
		$parts = array();

		foreach ( $rows as $row ) {
			$parts[] = ( '' === $row[0] ? '0' : $row[0] ) . ':' . $row[1];
		}

		return implode( ',', $parts );
	}

	/**
	 * Do two texts mean the same price steps?
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 */
	public static function same( string $a, string $b ): bool {
		$norm = static function ( string $text ): ?array {
			$rows = self::parse( $text );

			return null === $rows ? null : array_map( static fn( $r ) => array( (int) $r[0], (float) $r[1] ), $rows );
		};

		return $norm( $a ) === $norm( $b );
	}

	/**
	 * What a number of units cost in a month at these steps (each step's units at its price), excluding VAT.
	 *
	 * @param array<int, array{upto: ?int, rate: float}> $steps Steps as Rates::unit_steps() gives them.
	 * @param int                                        $units Units in the month.
	 */
	public static function cost( array $steps, int $units ): float {
		$total = 0.0;
		$from  = 0;

		foreach ( $steps as $step ) {
			$to    = null === $step['upto'] ? $units : min( $units, (int) $step['upto'] );
			$total += max( 0, $to - $from ) * (float) $step['rate'];
			$from   = max( $from, $to );
		}

		return $total;
	}
}

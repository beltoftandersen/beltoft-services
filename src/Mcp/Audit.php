<?php
/**
 * The customer's own log of what a connected app did to things that have no service history.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * A change of the address belongs to no license, order or subscription. It is kept in the customer's user meta, the
 * last 100 lines, as "Claude: ...", and shown to the customer in the Security section. Never a result, a password or a
 * card.
 *
 * Each line is a meta row of its own (add_user_meta), so two calls at once cannot overwrite each other's line. Trimming
 * to the last 100 is best effort: two calls at once may both trim, which at worst leaves a few lines more or fewer.
 */
final class Audit {

	public const META = 'beltoft_services_mcp_audit';
	public const KEEP = 100;

	/**
	 * Adds a line.
	 *
	 * @param int    $user_id Customer.
	 * @param string $text    What happened, after "Claude: ".
	 */
	public static function add( int $user_id, string $text ): void {
		add_user_meta( $user_id, self::META, array( 'at' => time(), 'text' => 'Claude: ' . $text ) );

		$lines = self::lines( $user_id );

		foreach ( array_slice( $lines, 0, max( 0, count( $lines ) - self::KEEP ) ) as $old ) {
			delete_user_meta( $user_id, self::META, $old );
		}
	}

	/**
	 * The lines, oldest first.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array{at: int, text: string}>
	 */
	public static function lines( int $user_id ): array {
		$lines = array_values( array_filter( (array) get_user_meta( $user_id, self::META, false ), 'is_array' ) );

		usort( $lines, static fn( $a, $b ) => (int) $a['at'] <=> (int) $b['at'] );

		return $lines;
	}
}

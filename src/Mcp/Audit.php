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
	 * The lines of "asked, waiting for confirmation" are kept apart, with a quota of their own, so a flood of summaries can
	 * never push out the lines of what was done.
	 */
	public const ASKED      = 'beltoft_services_mcp_audit_asked';
	public const KEEP_ASKED = 20;

	/**
	 * Adds a line.
	 *
	 * @param int    $user_id Customer.
	 * @param string $text    What happened, after "Claude: ".
	 * @param bool   $asked   A summary that waits for the customer's yes (kept apart, with a smaller quota).
	 */
	public static function add( int $user_id, string $text, bool $asked = false ): void {
		$key = $asked ? self::ASKED : self::META;

		add_user_meta( $user_id, $key, array( 'at' => time(), 'text' => 'Claude: ' . $text ) );

		$lines = self::stored( $user_id, $key );

		foreach ( array_slice( $lines, 0, max( 0, count( $lines ) - ( $asked ? self::KEEP_ASKED : self::KEEP ) ) ) as $old ) {
			delete_user_meta( $user_id, $key, $old );
		}
	}

	/**
	 * The lines, oldest first.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array{at: int, text: string}>
	 */
	public static function lines( int $user_id ): array {
		$lines = array_merge( self::stored( $user_id, self::META ), self::stored( $user_id, self::ASKED ) );

		usort( $lines, static fn( $a, $b ) => (int) $a['at'] <=> (int) $b['at'] );

		return $lines;
	}

	/**
	 * The lines of one key, oldest first.
	 *
	 * @param int    $user_id Customer.
	 * @param string $key     Meta key.
	 * @return array<int, array{at: int, text: string}>
	 */
	private static function stored( int $user_id, string $key ): array {
		$lines = array_values( array_filter( (array) get_user_meta( $user_id, $key, false ), 'is_array' ) );

		usort( $lines, static fn( $a, $b ) => (int) $a['at'] <=> (int) $b['at'] );

		return $lines;
	}
}

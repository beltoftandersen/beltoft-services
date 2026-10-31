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
 * last 100 lines, as "Claude: ...". Never a result, a password or a card.
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
		$lines   = self::lines( $user_id );
		$lines[] = array( 'at' => time(), 'text' => 'Claude: ' . $text );

		update_user_meta( $user_id, self::META, array_slice( $lines, -self::KEEP ) );
	}

	/**
	 * The lines, oldest first.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array{at: int, text: string}>
	 */
	public static function lines( int $user_id ): array {
		$lines = get_user_meta( $user_id, self::META, true );

		return is_array( $lines ) ? $lines : array();
	}
}

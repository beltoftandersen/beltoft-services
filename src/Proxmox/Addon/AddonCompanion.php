<?php
/** Server add-ons that sit in the cart beside the server they are bought with. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Addon;

defined( 'ABSPATH' ) || exit;

/**
 * Answers `beltoft_services_cart_companion` from the add-on model: a new line that carries the cart key of a kind that
 * `accepts_cart_server` with the value `cart_server` is bought with the new server of the cart, so adding it removes
 * nothing. It reads Addon::all(), so a kind added there is covered without a list anywhere else.
 */
final class AddonCompanion {

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_cart_companion', array( __CLASS__, 'answer' ), 10, 3 );
	}

	/**
	 * Is this cart item data the line of a server add-on for the server still in the cart?
	 *
	 * @param array<string, mixed> $item Cart line or cart item data.
	 */
	public static function is_for_cart_server( array $item ): bool {
		foreach ( Addon::all() as $addon ) {
			if ( $addon->accepts_cart_server && 'cart_server' === ( $item[ $addon->cart_for() ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Filter beltoft_services_cart_companion.
	 *
	 * @param mixed $keep Earlier answer.
	 * @param mixed $line Existing cart line.
	 * @param mixed $new  Line being added.
	 */
	public static function answer( $keep, $line = array(), $new = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		return true === $keep || ( is_array( $new ) && self::is_for_cart_server( $new ) );
	}
}

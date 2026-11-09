<?php
/**
 * The WooCommerce Subscriptions cart-item keys, in one place.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions marks a cart line that belongs to one of its flows with a key in the line's item data:
 * the `$cart_item_key` properties of WCS_Cart_Renewal (core/class-wcs-cart-renewal.php:18), WCS_Cart_Switch
 * (switching/class-wcs-cart-switch.php:11) and WCS_Cart_Resubscribe (core/class-wcs-cart-resubscribe.php:19). An early
 * renewal is a renewal cart too, and also carries its own flag. The same data sits on a session item and on the
 * `$data` WooCommerce passes to the add-to-cart filters, so every helper takes a plain array.
 *
 * Three key sets exist ON PURPOSE. Each answers a different question, and a caller keeps the set that answers its own:
 *
 * - EXISTING_SERVICE (renewal, switch): "does this line pay for a service that already exists?" A resubscribe is not
 *   in it: it builds a new resource, so it must count as a new service for billing details, the new-account unit
 *   limit and the system choice.
 * - BOUGHT_BEFORE (renewal, switch, resubscribe): "was this line bought before, so it carries no new proof, trial or
 *   choice?" The customer already holds the service or domain.
 * - ALL (those three plus the early renewal flag): "is this line anything but a new purchase?" The intro discount,
 *   the sold out check and the public IP guard use it.
 *
 * Two readers exist because two forms were written before this class: `has()` is true when a key is set (isset), and
 * `has_value()` when a key holds something (not empty). WCS always stores a non-empty array, so they agree on real
 * carts; each caller keeps the form it had.
 */
final class CartFlow {

	public const RENEWAL = 'subscription_renewal';
	public const SWITCH = 'subscription_switch';
	public const RESUBSCRIBE = 'subscription_resubscribe';
	public const RENEWAL_EARLY = 'subscription_renewal_early';

	/**
	 * A renewal or a switch.
	 */
	public const EXISTING_SERVICE = array( self::RENEWAL, self::SWITCH );

	/**
	 * A renewal, a switch or a resubscribe.
	 */
	public const BOUGHT_BEFORE = array( self::RENEWAL, self::SWITCH, self::RESUBSCRIBE );

	/**
	 * Every flow key, early renewal included.
	 */
	public const ALL = array( self::RENEWAL, self::SWITCH, self::RESUBSCRIBE, self::RENEWAL_EARLY );

	/**
	 * Does the item data have any of these keys set?
	 *
	 * @param array<string, mixed> $item Cart item, session item or add-to-cart data.
	 * @param string[]             $keys One of the sets above, or a single key in an array.
	 */
	public static function has( array $item, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( isset( $item[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does the item data hold something under any of these keys?
	 *
	 * @param array<string, mixed> $item Cart item, session item or add-to-cart data.
	 * @param string[]             $keys One of the sets above, or a single key in an array.
	 */
	public static function has_value( array $item, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! empty( $item[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is the line a renewal (an early renewal is one too)?
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	public static function is_renewal( array $item ): bool {
		return isset( $item[ self::RENEWAL ] );
	}

	/**
	 * Is the line a subscription switch?
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	public static function is_switch( array $item ): bool {
		return isset( $item[ self::SWITCH ] );
	}

	/**
	 * Is the line a resubscribe?
	 *
	 * @param array<string, mixed> $item Cart item.
	 */
	public static function is_resubscribe( array $item ): bool {
		return isset( $item[ self::RESUBSCRIBE ] );
	}
}

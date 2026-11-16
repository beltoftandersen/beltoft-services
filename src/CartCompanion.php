<?php
/** Which cart lines belong beside each other on purpose. @package BeltoftServices */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * The one public answer to "does this cart line stay when that line is added?". The plugin puts two lines in one cart on
 * purpose in a few places (a server with its extra disk or app licence, a domain with a mail or hosting plan, the hidden
 * usage account line beside a pay as you go service). A site rule that keeps a cart to one purchase asks here instead of
 * naming those kinds itself, so a kind added later is covered without a change outside the plugin.
 *
 * The core names no feature: every feature answers through the filter `beltoft_services_cart_companion`.
 * Subscription flows (renewal, switch, resubscribe) are not decided here: the caller treats those carts first.
 */
final class CartCompanion {

	/**
	 * Does an existing cart line stay when a new line is added?
	 *
	 * Both arguments have the shape of a WooCommerce cart line: the cart item data keys at the top level plus `product_id`.
	 * For the new line, merge the item data passed to add_to_cart() with its product id.
	 *
	 * @param array<string, mixed> $line Existing cart line.
	 * @param array<string, mixed> $new  Line being added.
	 */
	public static function keeps( array $line, array $new ): bool {
		/**
		 * Filter: does the existing line stay beside the new line?
		 *
		 * @param bool                 $keep Whether the line stays (false unless an earlier callback said yes).
		 * @param array<string, mixed> $line Existing cart line.
		 * @param array<string, mixed> $new  Line being added.
		 */
		return true === apply_filters( 'beltoft_services_cart_companion', false, $line, $new );
	}
}

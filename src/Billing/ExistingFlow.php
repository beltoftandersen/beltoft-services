<?php
/**
 * Is a request a renewal, switch or resubscribe of the customer's own subscription for this product?
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

use BeltoftServices\SwitchLink;

defined( 'ABSPATH' ) || exit;

/**
 * A hand-closed product (stored `outofstock`) is still open to a customer who already holds it: a renewal, a switch and
 * a resubscribe are not a new sale. WooCommerce Subscriptions checks a renewal, switch or resubscribe itself, with a
 * nonce and an ownership check, but this plugin cannot lean on that for a stock decision: a bare request argument
 * proves nothing. So a flow counts only when ALL of this holds:
 *
 * - a user is logged in;
 * - the subscription the request names (`switch-subscription` in the request, `resubscribe` in the query) or the cart
 *   item keys carry (`subscription_renewal`, `subscription_switch`, `subscription_resubscribe`, each holding the
 *   `subscription_id`) exists and belongs to that user;
 * - one of its line items is this product, or another variation of the same parent product (a switch moves between
 *   variations of one product; a renewal and a resubscribe are the same product);
 * - for a switch, WooCommerce Subscriptions allows this user to switch that line item
 *   (`WC_Subscriptions_Switcher::can_item_be_switched_by_user()`: active subscription, switchable type and payment
 *   method); for a resubscribe named in the request, `wcs_can_user_resubscribe_to()` says yes, and for one carried by
 *   the cart the subscription is pending-cancel, cancelled or expired.
 *
 * kinds() answers which flows hold, so a caller can treat them differently: a resubscribe builds a new service, a
 * renewal and a switch act on one that exists.
 *
 * The cart item data of the add in progress is kept here because WooCommerce checks is_purchasable() and the stock
 * INSIDE add_to_cart(), before the item is in the cart, and WooCommerce Subscriptions adds each flow through
 * add_to_cart() with its key in the item data.
 */
final class ExistingFlow {

	public const RENEWAL     = 'renewal';
	public const SWITCH      = 'switch';
	public const RESUBSCRIBE = 'resubscribe';

	/**
	 * Product, variation and item data of the add being made now. Set by the item-data filter, replaced by the next
	 * add, cleared once the item is added or the request ends.
	 *
	 * @var array{ids: int[], data: array<string, mixed>}|null
	 */
	private static $adding = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// After WooCommerce Subscriptions' own item-data filters, so the keys are in place.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'note' ), 999, 3 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'clear' ) );
		// An add can abort after the item-data filter and no action follows: the next add's validation or the end of the
		// request closes the flag instead.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validation' ), 0 );
		add_action( 'shutdown', array( __CLASS__, 'clear' ) );
	}

	/**
	 * Filter woocommerce_add_cart_item_data: remembers the data of a flow add.
	 *
	 * @param mixed $data         Cart item data.
	 * @param mixed $product_id   Product id.
	 * @param mixed $variation_id Variation id.
	 * @return mixed
	 */
	public static function note( $data, $product_id = 0, $variation_id = 0 ) {
		self::$adding = is_array( $data ) && CartFlow::has( $data, CartFlow::ALL ) ? array(
			'ids'  => array( (int) $product_id, (int) $variation_id ),
			'data' => $data,
		) : null;

		return $data;
	}

	/**
	 * Filter woocommerce_add_to_cart_validation, first in line: a new add starts.
	 *
	 * @param mixed $valid Whether the add is valid so far.
	 * @return mixed
	 */
	public static function validation( $valid ) {
		self::$adding = null;

		return $valid;
	}

	/**
	 * Closes the flag.
	 */
	public static function clear(): void {
		self::$adding = null;
	}

	/**
	 * The flows that hold for this product, from the request, the add in progress and the cart.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return string[] Some of RENEWAL, SWITCH, RESUBSCRIBE; empty when none holds.
	 */
	public static function kinds( \WC_Product $product ): array {
		$user = get_current_user_id();

		if ( $user < 1 || ! function_exists( 'wcs_get_subscription' ) ) {
			return array();
		}

		$found = array();

		foreach ( self::candidates( $product ) as list( $kind, $subscription_id, $from_request ) ) {
			if ( ! in_array( $kind, $found, true ) && self::holds( $kind, $subscription_id, $product, $user, $from_request ) ) {
				$found[] = $kind;
			}
		}

		return $found;
	}

	/**
	 * Every claim of a flow that this request makes for the product: kind, subscription id, named by the request.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array<int, array{0: string, 1: int, 2: bool}>
	 */
	private static function candidates( \WC_Product $product ): array {
		$out = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only an id is read; holds() checks the subscription belongs to the logged-in user and holds the product, and WooCommerce Subscriptions verifies its own nonces.
		$switch      = SwitchLink::requested( true )['subscription'];
		$resubscribe = isset( $_GET['resubscribe'] ) ? absint( wp_unslash( $_GET['resubscribe'] ) ) : 0;
		// phpcs:enable

		if ( $switch > 0 ) {
			$out[] = array( self::SWITCH, $switch, true );
		}

		if ( $resubscribe > 0 ) {
			$out[] = array( self::RESUBSCRIBE, $resubscribe, true );
		}

		$ids = array( $product->get_id() );

		if ( null !== self::$adding && array_intersect( $ids, self::$adding['ids'] ) ) {
			$out = array_merge( $out, self::from_data( self::$adding['data'] ) );
		}

		$cart = function_exists( 'WC' ) && isset( WC()->cart ) ? WC()->cart : null;

		if ( $cart ) {
			// cart_contents, not get_cart(): this runs while the cart is loading from the session.
			foreach ( (array) $cart->cart_contents as $item ) {
				if ( is_array( $item ) && array_intersect( $ids, array( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ) ) ) {
					$out = array_merge( $out, self::from_data( $item ) );
				}
			}
		}

		return $out;
	}

	/**
	 * The flows a cart item or item data claims, with the subscription each names.
	 *
	 * @param array<string, mixed> $data Cart item or add-to-cart data.
	 * @return array<int, array{0: string, 1: int, 2: bool}>
	 */
	private static function from_data( array $data ): array {
		$map = array(
			CartFlow::RENEWAL     => self::RENEWAL,
			CartFlow::SWITCH      => self::SWITCH,
			CartFlow::RESUBSCRIBE => self::RESUBSCRIBE,
		);
		$out = array();

		foreach ( $map as $key => $kind ) {
			$id = isset( $data[ $key ]['subscription_id'] ) ? absint( $data[ $key ]['subscription_id'] ) : 0;

			if ( $id > 0 ) {
				$out[] = array( $kind, $id, false );
			}
		}

		return $out;
	}

	/**
	 * Does this subscription belong to the user, hold the product, and allow the flow?
	 *
	 * @param string      $kind            RENEWAL, SWITCH or RESUBSCRIBE.
	 * @param int         $subscription_id Subscription named.
	 * @param \WC_Product $product         Product or variation.
	 * @param int         $user            Logged-in user.
	 * @param bool        $from_request    True when a request argument named it, false when cart data did.
	 */
	private static function holds( string $kind, int $subscription_id, \WC_Product $product, int $user, bool $from_request ): bool {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof \WC_Subscription || (int) $subscription->get_user_id() !== $user ) {
			return false;
		}

		if ( self::RESUBSCRIBE === $kind ) {
			$allowed = $from_request && function_exists( 'wcs_can_user_resubscribe_to' )
				? wcs_can_user_resubscribe_to( $subscription, $user )
				: $subscription->has_status( array( 'pending-cancel', 'cancelled', 'expired' ) );

			if ( ! $allowed ) {
				return false;
			}
		}

		foreach ( $subscription->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! self::same_product( $item, $product ) ) {
				continue;
			}

			if ( self::SWITCH === $kind && ! self::may_switch( $item, $subscription, $user ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Is the line item this product, or a variation of the same parent?
	 *
	 * @param \WC_Order_Item_Product $item    Line item of the subscription.
	 * @param \WC_Product            $product Product or variation.
	 */
	private static function same_product( \WC_Order_Item_Product $item, \WC_Product $product ): bool {
		$parent = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

		return $parent === (int) $item->get_product_id() || $product->get_id() === (int) $item->get_variation_id();
	}

	/**
	 * May this user switch this line item, by WooCommerce Subscriptions' own rules?
	 *
	 * @param \WC_Order_Item_Product $item         Line item.
	 * @param \WC_Subscription       $subscription Subscription.
	 * @param int                    $user         User.
	 */
	private static function may_switch( \WC_Order_Item_Product $item, \WC_Subscription $subscription, int $user ): bool {
		if ( class_exists( '\WC_Subscriptions_Switcher' ) ) {
			return (bool) \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription, $user );
		}

		return $subscription->has_status( 'active' );
	}
}

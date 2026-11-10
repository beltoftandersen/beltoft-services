<?php
/**
 * The WooCommerce Subscriptions switch link of a service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the server upgrade links, the backups switch and the email plan link.
 */
final class SwitchLink {

	/**
	 * The switch WooCommerce Subscriptions named in the request: the subscription and the item ids, 0 when absent. Only
	 * read, never trusted: WooCommerce Subscriptions checks the nonce, and callers check ownership.
	 *
	 * @param bool $any_source True reads the subscription from the whole request (query or form), false from the query only. The item is always read from the query.
	 * @return array{subscription: int, item: int}
	 */
	public static function requested( bool $any_source = false ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Only ids are read; WooCommerce Subscriptions validates the switch and callers check ownership.
		$source       = $any_source ? $_REQUEST : $_GET;
		$subscription = isset( $source['switch-subscription'] ) ? absint( wp_unslash( $source['switch-subscription'] ) ) : 0;
		$item         = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		// phpcs:enable

		return array( 'subscription' => $subscription, 'item' => $item );
	}

	/**
	 * The WooCommerce Subscriptions switch link of the service's subscription item to another variation of the same
	 * product. Empty when Subscriptions is inactive, the item cannot be switched by this customer, or the service has no
	 * subscription item.
	 *
	 * @param Resource              $r      Resource.
	 * @param \WC_Product_Variation $target The variation to switch to.
	 */
	public static function to( Resource $r, \WC_Product_Variation $target ): string {
		if ( ! class_exists( '\WC_Subscriptions_Switcher' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$license      = $r->license();
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$sub_id       = (int) ( $license['subscription_id'] ?? 0 );

		if ( $sub_id < 1 || $variation_id < 1 ) {
			return '';
		}

		$subscription = wcs_get_subscription( $sub_id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		foreach ( $subscription->get_items() as $item_id => $item ) {
			if ( (int) $item->get_variation_id() !== $variation_id ) {
				continue;
			}

			if ( ! \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription ) ) {
				return '';
			}

			return self::for_item( (int) $item_id, $item, $subscription, $target );
		}

		return '';
	}

	/**
	 * The switch link of one subscription item to a variation: the switch arguments of WooCommerce Subscriptions (the
	 * subscription, the item and the nonce) on the checkout address, with the variation to add. Empty when Subscriptions
	 * does not give all three arguments.
	 *
	 * @param int                      $item_id      Subscription item id.
	 * @param \WC_Order_Item_Product   $item         The subscription item.
	 * @param \WC_Subscription         $subscription The subscription.
	 * @param \WC_Product_Variation    $target       The variation to switch to.
	 */
	public static function for_item( int $item_id, \WC_Order_Item_Product $item, \WC_Subscription $subscription, \WC_Product_Variation $target ): string {
		$query = array();
		wp_parse_str( (string) wp_parse_url( \WC_Subscriptions_Switcher::get_switch_url( $item_id, $item, $subscription ), PHP_URL_QUERY ), $query );

		$args = array_intersect_key( $query, array_flip( array( 'switch-subscription', 'item', '_wcsnonce' ) ) );

		if ( 3 !== count( $args ) ) {
			return '';
		}

		$args['add-to-cart']  = $target->get_parent_id();
		$args['variation_id'] = $target->get_id();

		foreach ( $target->get_attributes() as $name => $value ) {
			$args[ 'attribute_' . $name ] = $value;
		}

		return add_query_arg( $args, wc_get_checkout_url() );
	}
}

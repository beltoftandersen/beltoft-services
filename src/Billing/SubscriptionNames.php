<?php
/**
 * The subscription list in My Account names what each subscription is for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions lists subscriptions by number only (#908, #839), so a customer cannot tell them apart. Under
 * the number this prints the names of the subscription's line items: two at most, then how many more.
 */
final class SubscriptionNames {

	/**
	 * Hooks the line under the number.
	 */
	public static function register(): void {
		add_action( 'woocommerce_my_subscriptions_after_subscription_id', array( __CLASS__, 'print_names' ) );
	}

	/**
	 * Prints the item names of one subscription.
	 *
	 * @param mixed $subscription The subscription in the row.
	 */
	public static function print_names( $subscription ): void {
		$text = self::names( $subscription );

		if ( '' !== $text ) {
			echo '<span class="bs-sub-name">' . esc_html( $text ) . '</span>';
		}
	}

	/**
	 * The item names of a subscription as one line, or '' when it has none.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function names( $subscription ): string {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_items' ) ) {
			return '';
		}

		$names = array();

		foreach ( $subscription->get_items() as $item ) {
			$name = trim( wp_strip_all_tags( \BeltoftServices\LineNames::display_name( $item ) ) );

			$name = self::without_none( $name );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		$names = array_values( array_unique( $names ) );

		if ( count( $names ) > 2 ) {
			$more = count( $names ) - 2;
			/* translators: 1: two item names, 2: how many more items. */
			return sprintf( _n( '%1$s and %2$d more', '%1$s and %2$d more', $more, 'beltoft-services' ), implode( ', ', array_slice( $names, 0, 2 ) ), $more );
		}

		return implode( ', ', $names );
	}

	/**
	 * A stored item name without its empty variation values ("VPS hosting - Starter, None" becomes "VPS hosting - Starter").
	 * Danish "Ingen" is the same value.
	 *
	 * @param string $name Item name.
	 */
	public static function without_none( string $name ): string {
		$parts = array_filter(
			array_map( 'trim', explode( ',', $name ) ),
			static function ( string $part ): bool {
				return '' !== $part && ! in_array( strtolower( $part ), array( 'none', 'ingen' ), true );
			}
		);

		return implode( ', ', $parts );
	}
}

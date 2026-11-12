<?php
/**
 * Account credit paid onto the renewal orders of subscriptions.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Product;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Minimum;

defined( 'ABSPATH' ) || exit;

/**
 * With the setting `credit_renewals` on, the renewal order of any subscription is paid from account credit first and the card pays the
 * rest. It is the same mechanism as a usage invoice (Invoice::apply: the fee line "Paid from account credit", the ledger debit once
 * per order, the give back when the order is cancelled, refunded, failed or deleted unpaid): credit is a payment, not a discount, so the
 * line totals and the VAT stay as they are.
 *
 * What may be used:
 * - Paid credit (top-ups) pays any renewal.
 * - Promotional credit (ledger kind promo) pays only the part of the order that belongs to lines of products with the box
 *   "Promotional credit can pay renewals" (meta META_PROMO, read through the parent product of a variation), including their VAT.
 *   Promotional credit is spent first, then paid credit, so an order with marked and other lines takes promo up to the marked lines
 *   and paid credit for the rest. The filter `beltoft_services_promo_credit_line` can change the answer for one line.
 * - A fixed subscription cannot be carried to next month: when credit would cover only part of the order and the rest for the card
 *   would be above 0 and under the minimum (Usage\Minimum::amount(), including VAT), no credit is used on that order. Credit that
 *   covers the whole order is always used.
 *
 * The credit is applied when WooCommerce Subscriptions creates the renewal order (filter wcs_renewal_order_created), after its lines
 * and taxes are final and before it decides what to charge: an order of 0 is completed by WooCommerce Subscriptions itself (no
 * gateway is asked), and a rest is charged to the gateway as the order total. The order of the usage account has its own path
 * (Usage\Renewal) and is skipped here, so a renewal is never credited twice. An early renewal made through the modal is skipped: it
 * charges the card at once and deletes the order when that fails.
 */
final class RenewalCredit {

	/** Product meta: promotional credit may pay this product's renewals ('yes'). */
	public const META_PROMO = '_beltoft_services_promo_credit';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wcs_renewal_order_created', array( __CLASS__, 'filter' ), 30, 2 );
		add_action( 'beltoft_services_product_panel_after_reminder', array( __CLASS__, 'product_field' ), 20, 1 );
		add_action( 'beltoft_services_product_save', array( __CLASS__, 'save_product_field' ), 20, 1 );
		add_action( 'woocommerce_subscription_details_after_subscription_table', array( __CLASS__, 'subscription_note' ), 5 );
	}

	/**
	 * May promotional credit pay this product's renewals?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function promo_allowed( int $product_id ): bool {
		return $product_id > 0 && 'yes' === (string) get_post_meta( Product::parent_id( $product_id ), self::META_PROMO, true );
	}

	/**
	 * Promotional credit that may pay a product line of an order: the product's box, then the filter.
	 *
	 * @param \WC_Order_Item_Product $item  Line.
	 * @param \WC_Order              $order Order.
	 */
	public static function line_allows_promo( \WC_Order_Item_Product $item, \WC_Order $order ): bool {
		$allowed = self::promo_allowed( (int) ( $item->get_variation_id() ?: $item->get_product_id() ) );

		/**
		 * Whether promotional credit may pay a line of a renewal order. The default is the product's box "Promotional credit can pay
		 * renewals".
		 *
		 * @param bool                   $allowed The box of the line's product.
		 * @param \WC_Order_Item_Product $item    The line.
		 * @param \WC_Order              $order   The renewal order.
		 */
		return (bool) apply_filters( 'beltoft_services_promo_credit_line', $allowed, $item, $order );
	}

	/**
	 * What promotional credit may pay of an order, in cents: the lines that allow it, with their VAT.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function promo_cap( \WC_Order $order ): int {
		$sum = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && self::line_allows_promo( $item, $order ) ) {
				$sum += Balance::cents( (float) $item->get_total() + (float) $item->get_total_tax() );
			}
		}

		return $sum;
	}

	/**
	 * Pays a new renewal order from credit.
	 *
	 * @param mixed $order        Renewal order.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public static function filter( $order, $subscription ) {
		if ( ! $order instanceof \WC_Order || ! $subscription instanceof \WC_Subscription || ! Config::renewals() ) {
			return $order;
		}

		$user_id = (int) $subscription->get_customer_id();

		// The usage account has its own path; an order that already holds credit or carried usage is not touched again; the early
		// renewal of the modal charges at once and deletes a failed order (see the class comment).
		if (
			$user_id < 1
			|| Account::is_account( $subscription )
			|| Invoice::used( $order ) > 0
			|| '' !== (string) $order->get_meta( Invoice::META_USED )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads whether WooCommerce Subscriptions' own request is running.
			|| isset( $_GET['process_early_renewal'] )
		) {
			return $order;
		}

		/**
		 * Whether credit may pay this renewal order at all. The default is true for a renewal order while credit pays renewals.
		 *
		 * @param bool      $applies Default true.
		 * @param \WC_Order $order   The renewal order.
		 * @param int       $user_id The customer.
		 */
		if ( ! apply_filters( 'beltoft_services_credit_applies_to_order', true, $order, $user_id ) ) {
			return $order;
		}

		$min = (int) round( Minimum::amount( (string) $order->get_currency() ) * 100 );

		if ( Invoice::apply( $order, $user_id, $min, self::promo_cap( $order ) ) > 0 ) {
			$order->save();
		}

		return $order;
	}

	/**
	 * The box on the product form.
	 *
	 * @param mixed $product The product being edited, null for a new one.
	 */
	public static function product_field( $product = null ): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_PROMO,
				'label'       => __( 'Promotional credit can pay renewals', 'beltoft-services' ),
				'description' => __( 'Let promotional credit (credit codes and grants) pay the renewals of this product. Credit a customer paid for can pay any renewal. Needs the setting that lets credit pay renewals.', 'beltoft-services' ),
				'value'       => $product && 'yes' === $product->get_meta( self::META_PROMO ) ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Saves the box.
	 *
	 * @param mixed $product The product.
	 */
	public static function save_product_field( $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		if ( $product instanceof \WC_Product ) {
			$product->update_meta_data( self::META_PROMO, isset( $_POST[ self::META_PROMO ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * One sentence on a subscription's page when credit would pay its next renewal: the customer has paid credit, or promotional credit and
	 * every product of the subscription allows it.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function subscription_note( $subscription ): void {
		if ( ! $subscription instanceof \WC_Subscription || ! Config::renewals() || Account::is_account( $subscription ) ) {
			return;
		}

		$user_id  = (int) $subscription->get_customer_id();
		$currency = (string) $subscription->get_currency();

		if ( $user_id < 1 || Balance::get_in( $user_id, $currency ) < 1 ) {
			return;
		}

		$promo = Balance::promo_left( $user_id );
		$paid  = Balance::get( $user_id ) - $promo;
		$lines = $subscription->get_items( 'line_item' );
		$all   = (bool) $lines;

		foreach ( $lines as $item ) {
			$all = $all && $item instanceof \WC_Order_Item_Product && self::line_allows_promo( $item, $subscription );
		}

		if ( $paid > 0 || ( $all && $promo > 0 ) ) {
			echo '<p class="beltoft-services-credit-renewals">' . esc_html__( 'Renewals are paid from your account credit first, and your card pays the rest.', 'beltoft-services' ) . '</p>';
		}
	}
}

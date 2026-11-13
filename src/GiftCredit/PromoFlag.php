<?php
/**
 * The product box "promotional credit can pay orders and renewals".
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\GiftCredit;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Free cards (promotional credit) pay only the lines of products with this box ticked, including their VAT. Paid cards pay any line. The
 * meta key is the one the older credit code used, so the boxes already ticked keep working. The filter beltoft_services_promo_credit_line
 * can change the answer for one line.
 */
final class PromoFlag {

	/** Product meta: free cards may pay this product's lines ('yes'). */
	public const META_PROMO = '_beltoft_services_promo_credit';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// The older credit code draws and saves the same box; this one replaces it.
		remove_action( 'beltoft_services_product_panel_after_reminder', array( '\\BeltoftServices\\Credit\\RenewalCredit', 'product_field' ), 20 );
		remove_action( 'beltoft_services_product_save', array( '\\BeltoftServices\\Credit\\RenewalCredit', 'save_product_field' ), 20 );
		add_action( 'beltoft_services_product_panel_after_reminder', array( __CLASS__, 'product_field' ), 20, 1 );
		add_action( 'beltoft_services_product_save', array( __CLASS__, 'save_product_field' ), 20, 1 );
	}

	/**
	 * May free cards pay this product's lines?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function promo_allowed( int $product_id ): bool {
		return $product_id > 0 && 'yes' === (string) get_post_meta( Product::parent_id( $product_id ), self::META_PROMO, true );
	}

	/**
	 * May free cards pay a product line of an order: the product's box, then the filter.
	 *
	 * @param \WC_Order_Item_Product $item  Line.
	 * @param \WC_Order              $order Order.
	 */
	public static function line_allows_promo( \WC_Order_Item_Product $item, \WC_Order $order ): bool {
		$allowed = self::promo_allowed( (int) ( $item->get_variation_id() ?: $item->get_product_id() ) );

		/**
		 * Whether free credit may pay a line of an order. The default is the product's box "Promotional credit can pay orders and renewals".
		 *
		 * @param bool                   $allowed The box of the line's product.
		 * @param \WC_Order_Item_Product $item    The line.
		 * @param \WC_Order              $order   The order.
		 */
		return (bool) apply_filters( 'beltoft_services_promo_credit_line', $allowed, $item, $order );
	}

	/**
	 * The add-on's question "may a card pay this line": a paid card always may, a free card by the box.
	 *
	 * @param mixed $allowed Answer so far.
	 * @param mixed $paid    Whether the card is a paid card.
	 * @param mixed $item    The line.
	 * @param mixed $order   The order.
	 * @return mixed
	 */
	public static function card_may_pay_line( $allowed, $paid = false, $item = null, $order = null ) {
		if ( ! $allowed || $paid || ! $item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order ) {
			return $allowed;
		}

		return self::line_allows_promo( $item, $order );
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
				'label'       => __( 'Promotional credit can pay orders and renewals', 'beltoft-services' ),
				'description' => __( 'Let free account credit (free gift cards) pay the first order and the renewals of this product. Credit a customer paid for can pay any order. Needs account credit as gift cards.', 'beltoft-services' ),
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
}

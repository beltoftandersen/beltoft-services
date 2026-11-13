<?php
/**
 * The pay as you go feature's hooks into the core's extension points.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * The core (the product form, the line names) never names pay as you go; this class answers its filters and actions.
 */
final class CoreHooks {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_product_panel_after_reminder', array( __CLASS__, 'premium_field' ), 10, 2 );
		add_action( 'beltoft_services_product_save', array( __CLASS__, 'save_premium' ), 30, 2 );
		add_filter( 'beltoft_services_line_is_usage', array( __CLASS__, 'line_is_usage' ), 10, 2 );
		add_filter( 'beltoft_services_line_usage_note', array( __CLASS__, 'line_usage_note' ), 10, 2 );
		add_filter( 'beltoft_services_line_account_product_id', array( __CLASS__, 'line_account_product_id' ) );
	}

	/**
	 * Is a line billed by use?
	 *
	 * @param mixed $usage   What an earlier listener said.
	 * @param mixed $product Product, variation or id.
	 * @return mixed
	 */
	public static function line_is_usage( $usage, $product = null ) {
		return Rates::is_usage( $product );
	}

	/**
	 * The note under a pay-as-you-go line.
	 *
	 * @param mixed $note    The note so far.
	 * @param mixed $context 'order' or 'cart'.
	 */
	public static function line_usage_note( $note = '', $context = 'order' ): string {
		// The cart and an order line say what the figures next to them are: the shop's tax display for the cart (an order page, the
		// thank-you page and order emails show the order's figures the same way).
		if ( 'incl' === \BeltoftServices\Billing\TaxNote::in_cart( static fn() => \BeltoftServices\Billing\TaxNote::display() ) ) {
			/* translators: %s: how pay as you go is billed */
			return sprintf( __( '%s, incl. VAT', 'beltoft-services' ), Rates::billed_note() );
		}

		/* translators: %s: how pay as you go is billed */
		return sprintf( __( '%s, excl. VAT', 'beltoft-services' ), Rates::billed_note() );
	}

	/**
	 * The hidden pay-as-you-go account product.
	 *
	 * @param mixed $id The id so far.
	 */
	public static function line_account_product_id( $id = 0 ): int {
		return (int) get_option( Account::OPTION_PRODUCT );
	}

	/**
	 * The premium field of the Service panel.
	 *
	 * @param mixed $product   The product being edited, null for a new one.
	 * @param mixed $driver_id The saved driver id.
	 */
	public static function premium_field( $product = null, $driver_id = '' ): void {
		if ( '' === $driver_id ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'                => Rates::META_PREMIUM,
				'label'             => __( 'Pay as you go premium (%)', 'beltoft-services' ),
				'description'       => __( 'Added to the monthly price for the hourly rate and the monthly cap of pay as you go variations. Empty means 10.', 'beltoft-services' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0', 'step' => '0.1' ),
				'value'             => $product ? $product->get_meta( Rates::META_PREMIUM ) : '',
			)
		);
	}

	/**
	 * Saves the premium.
	 *
	 * @param mixed $product   The product.
	 * @param mixed $driver_id The saved driver id.
	 */
	public static function save_premium( $product, $driver_id = '' ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		if ( $product instanceof \WC_Product && '' !== $driver_id && isset( $_POST[ Rates::META_PREMIUM ] ) ) {
			$premium = trim( sanitize_text_field( wp_unslash( $_POST[ Rates::META_PREMIUM ] ) ) );
			$product->update_meta_data( Rates::META_PREMIUM, is_numeric( $premium ) ? (string) max( 0, (float) $premium ) : '' );
		}
		// phpcs:enable
	}
}

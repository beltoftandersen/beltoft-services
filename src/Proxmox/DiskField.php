<?php
/** Extra disk selector for a new-server classic checkout. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

final class DiskField {
	public const AJAX = 'beltoft_services_disk_field';
	private const NONCE = 'beltoft-services-disk-field';
	private static int $server_swap_depth = 0;

	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ) );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'clean_orphan' ) );
	}

	/** The sole new server in the cart, independent of whether backups are offered. */
	public static function cart_server(): ?array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart || OsField::cart_is_existing_server() ) {
			return null;
		}
		$found = null;
		foreach ( $cart->get_cart() as $key => $item ) {
			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );
			if ( ! $driver || SshKeyField::DRIVER !== $driver->id() ) {
				continue;
			}
			if ( null !== $found || ! empty( $item['subscription_renewal'] ) || ! empty( $item['subscription_switch'] ) || ! empty( $item['subscription_resubscribe'] ) ) {
				return null;
			}
			$found = array( 'key' => $key, 'item' => $item );
		}
		return $found;
	}

	/** Published, purchasable variations whose plan specifies a positive disk size. */
	public static function sizes(): array {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 30, 'meta_key' => Product::META_DRIVER, 'meta_value' => DiskDriver::ID ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Service catalogue is small and bounded.
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product_Variable || ! class_exists( '\\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
				continue;
			}
			foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
				$plan = Product::plan( (int) $id, $variation->get_id() );
				if ( \WC_Subscriptions_Product::is_subscription( $variation ) && $variation->is_purchasable() && $variation->is_in_stock() && (int) ( $plan['disk_gb'] ?? 0 ) > 0 ) {
					$out[ $variation->get_id() ] = $variation;
				}
			}
		}
		return $out;
	}

	private static function selected_key(): string {
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $key => $item ) {
			if ( 'cart_server' === ( $item['beltoft_services_disk_for'] ?? null ) ) {
				return (string) $key;
			}
		}
		return '';
	}

	public static function render(): void {
		if ( ! self::cart_server() || ! self::sizes() ) {
			return;
		}
		$key = self::selected_key();
		$selected = $key ? (int) ( WC()->cart->get_cart()[ $key ]['variation_id'] ?? 0 ) : 0;
		echo '<p class="form-row form-row-wide beltoft-services-disk-field"><label for="beltoft_services_disk_size">' . esc_html__( 'Extra disk', 'beltoft-services' ) . '</label>';
		echo '<select id="beltoft_services_disk_size" data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"><option value="0">' . esc_html__( 'None', 'beltoft-services' ) . '</option>';
		foreach ( self::sizes() as $id => $variation ) {
			$plan = Product::plan( $variation->get_parent_id(), $id );
			$period = class_exists( '\\WC_Subscriptions_Product' ) && \WC_Subscriptions_Product::is_subscription( $variation )
				? ' / ' . (string) wcs_get_subscription_period_strings( max( 1, (int) \WC_Subscriptions_Product::get_interval( $variation ) ), (string) \WC_Subscriptions_Product::get_period( $variation ) )
				: '';
			$label = sprintf( '%d GB: %s%s %s', (int) $plan['disk_gb'], wp_strip_all_tags( wc_price( wc_get_price_excluding_tax( $variation ) ) ), $period, __( 'excl. VAT', 'beltoft-services' ) );
			echo '<option value="' . esc_attr( (string) $id ) . '" ' . selected( $selected, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! self::cart_server() || ! self::sizes() ) {
			return;
		}
		wp_enqueue_script( 'beltoft-services-disk-field', plugins_url( 'assets/disk-field.js', BELTOFT_SERVICES_FILE ), array( 'jquery' ), \BeltoftServices\Assets::version( 'assets/disk-field.js' ), true );
		wp_localize_script( 'beltoft-services-disk-field', 'beltoftServicesDiskField', array( 'url' => \WC_AJAX::get_endpoint( self::AJAX ), 'failed' => __( 'The extra disk could not be changed. Reload checkout and try again.', 'beltoft-services' ) ) );
	}

	public static function ajax(): void {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		$id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		wp_send_json( array( 'ok' => self::choose( $id ) ), 200 );
	}

	public static function choose( int $variation_id ): bool {
		$cart = WC()->cart;
		if ( ! $cart || ! self::cart_server() ) {
			return false;
		}
		$variation = 0 === $variation_id ? null : ( self::sizes()[ $variation_id ] ?? false );
		if ( false === $variation ) {
			return false;
		}
		$key = self::selected_key();
		if ( null === $variation ) {
			if ( $key ) {
				$cart->remove_cart_item( $key );
			}
			return true;
		}
		$attributes = array();
		foreach ( $variation->get_attributes() as $name => $value ) {
			$attributes[ 'attribute_' . $name ] = $value;
		}
		$added = $cart->add_to_cart( $variation->get_parent_id(), 1, $variation_id, $attributes, array( 'beltoft_services_disk_for' => 'cart_server' ) );
		if ( $added && $key ) {
			$cart->remove_cart_item( $key );
		}
		return (bool) $added;
	}

	/** Keep an attached disk while a server cart line is being replaced. */
	public static function during_server_swap( callable $swap ): bool {
		self::$server_swap_depth++;
		try {
			return (bool) $swap();
		} finally {
			self::$server_swap_depth--;
			if ( 0 === self::$server_swap_depth ) {
				self::clean_orphan();
			}
		}
	}

	/** Checkout's one-item cart rule must leave the attached disk during this replacement. */
	public static function server_swap_in_progress(): bool {
		return self::$server_swap_depth > 0;
	}

	/** A removed server must not leave an unprovisionable disk in the cart. */
	public static function clean_orphan(): void {
		if ( self::$server_swap_depth > 0 ) {
			return;
		}
		if ( self::cart_server() ) {
			return;
		}
		$key = self::selected_key();
		if ( $key && WC()->cart ) {
			WC()->cart->remove_cart_item( $key );
		}
	}
}

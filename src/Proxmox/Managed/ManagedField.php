<?php
/**
 * The "Managed server" box of the classic checkout, for the new server of the cart.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Managed;

use BeltoftServices\Checkout\CheckBox;
use BeltoftServices\Proxmox\Backup\CartSwap;
use BeltoftServices\Proxmox\Disk\DiskField;

defined( 'ABSPATH' ) || exit;

/**
 * One box: ticked, the cart holds one managed server line bought `cart_server`, with the new server (also for a visitor;
 * the account is made at checkout). After the order the service finds its server in the same order (ManagedMeta::server_of).
 * A server that is removed takes the line with it. The block checkout is not covered (classic hook only, as the other lines).
 */
final class ManagedField {

	public const AJAX  = 'beltoft_services_managed_field';
	public const NONCE = 'beltoft-services-managed-field';

	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ) );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'clean_orphan' ) );
	}

	/** Is there a box: the product is sold and the cart holds one new server. */
	public static function offered(): bool {
		return null !== ManagedCart::product() && null !== DiskField::cart_server();
	}

	/** How many managed lines the cart holds for the new server. */
	public static function chosen(): int {
		$n = 0;

		foreach ( function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$n += 'cart_server' === ( $item['beltoft_services_managed_for'] ?? null ) ? 1 : 0;
		}

		return $n;
	}

	public static function render(): void {
		if ( ! self::offered() ) {
			return;
		}

		$product = ManagedCart::product();
		$price   = CheckBox::price( $product );

		$html = CheckBox::html(
			'beltoft_services_managed',
			self::AJAX,
			self::NONCE,
			self::chosen() > 0,
			/* translators: %s: the monthly price, such as 299 kr. */
			sprintf( __( 'Managed server, %s a month', 'beltoft-services' ), $price ),
			__( 'We install operating system updates, act on the server\'s alerts and check that the backups run. Help with your own software is billed per started 15 minutes.', 'beltoft-services' )
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in CheckBox::html().
	}

	public static function assets(): void {
		if ( CheckBox::on_checkout() && self::offered() ) {
			CheckBox::enqueue();
		}
	}

	public static function ajax(): void {
		$answer = CheckBox::answer( self::NONCE, static fn( bool $on ): array => CartSwap::locked( static fn(): array => self::choose( $on ) ) ?? array( 'ok' => false, 'on' => self::chosen() > 0 ) );

		wp_send_json( $answer[0], $answer[1] );
	}

	/**
	 * Makes the cart hold one line (on) or none (off) for the new server.
	 *
	 * @param bool $on Wanted.
	 * @return array{ok: bool, on: bool}
	 */
	public static function choose( bool $on ): array {
		$cart    = function_exists( 'WC' ) ? WC()->cart : null;
		$product = ManagedCart::product();

		if ( ! $cart || ( $on && ( null === $product || ! self::offered() ) ) ) {
			return array( 'ok' => false, 'on' => self::chosen() > 0 );
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( 'cart_server' === ( $item['beltoft_services_managed_for'] ?? null ) && ( ! $on || self::chosen() > 1 ) ) {
				$cart->remove_cart_item( (string) $key );
			}
		}

		if ( $on && 0 === self::chosen() ) {
			$parent    = $product instanceof \WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
			$variation = $product instanceof \WC_Product_Variation ? $product->get_id() : 0;
			$attrs     = array();

			foreach ( $product instanceof \WC_Product_Variation ? $product->get_attributes() : array() as $name => $value ) {
				$attrs[ 'attribute_' . $name ] = $value;
			}

			if ( ! $cart->add_to_cart( $parent, 1, $variation, $attrs, array( 'beltoft_services_managed_for' => 'cart_server' ) ) ) {
				return array( 'ok' => false, 'on' => false );
			}
		}

		return array( 'ok' => true, 'on' => self::chosen() > 0 );
	}

	/** A removed server must not leave a managed line that has no server. */
	public static function clean_orphan(): void {
		if ( DiskField::server_swap_in_progress() || ! function_exists( 'WC' ) || ! WC()->cart || null !== DiskField::cart_server() ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( 'cart_server' === ( $item['beltoft_services_managed_for'] ?? null ) ) {
				WC()->cart->remove_cart_item( (string) $key );
			}
		}
	}
}

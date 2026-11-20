<?php
/**
 * The "IPv4 address" line of the classic checkout, for a new server or a new database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backup\CartSwap;
use BeltoftServices\Proxmox\Db\DbIpField;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\Panels;
use BeltoftServices\Proxmox\Vm\VpsProfile;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * One line, the same for every product: a number for a dedicated server (any number up to the limit), a box for a VPS Basic
 * and a database (one). Each address is one cart line of the add-on bought `cart_server`, with the new server or database;
 * the line changes the cart through a wc-ajax call and the page then refreshes the order review. A server that is removed
 * takes its address lines with it. The block checkout is not covered (classic hook only, as the other lines).
 */
final class Ipv4Field {

	public const AJAX   = 'beltoft_services_ipv4_field';
	public const NONCE  = 'beltoft-services-ipv4-field';
	public const HANDLE = 'beltoft-services-ipv4-field';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ) );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'clean_orphan' ) );
	}

	/**
	 * What the line is for: the one new server or database of the cart, how many addresses it can take, and whether it is a box.
	 *
	 * @return array{kind: string, max: int, box: bool}|null Null: no line.
	 */
	public static function target(): ?array {
		if ( null === Ipv4Cart::product( Ipv4Cart::cart_parent_usage() ) ) {
			return null;
		}

		$server = DiskField::cart_server();

		// A new server and a new database in one cart: an address line could not say which one it is for.
		if ( null !== $server && null !== DbIpField::new_database() ) {
			return null;
		}

		if ( null !== $server ) {
			$dedicated = VpsProfile::plan_has_ipv4( Product::plan( (int) ( $server['item']['product_id'] ?? 0 ), (int) ( $server['item']['variation_id'] ?? 0 ) ) );

			return array( 'kind' => 'server', 'max' => $dedicated ? max( 1, (int) apply_filters( 'beltoft_services_ipv4_max_per_server', 16, null ) ) : 1, 'box' => ! $dedicated );
		}

		return null !== DbIpField::new_database() ? array( 'kind' => 'db', 'max' => 1, 'box' => true ) : null;
	}

	/**
	 * Does the cart hold both a new server and a new database (no address line is possible then)?
	 */
	public static function both(): bool {
		return null !== DiskField::cart_server() && null !== DbIpField::new_database();
	}

	/**
	 * How many address lines the cart holds for the new server or database.
	 */
	public static function chosen(): int {
		return Ipv4Cart::in_cart( 'cart_server' );
	}

	/**
	 * The line.
	 */
	public static function render(): void {
		$target = self::target();

		if ( null === $target ) {
			return;
		}

		$product = Ipv4Cart::product( Ipv4Cart::cart_parent_usage() );
		$price   = $product instanceof \WC_Product_Variation && Rates::is_usage( $product ) ? Rates::addon_terms( $product ) : ( $product instanceof \WC_Product_Variation ? Panels::price_text( $product ) : ( $product ? wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ) . Panels::per( $product ) : '' ) );
		$chosen  = self::chosen();
		$free    = IpPool::available_for_addon() > 0 || $chosen > 0;
		/* translators: %s: the price, such as 19 kr. a month */
		$label = '' === $price ? __( 'IPv4 address', 'beltoft-services' ) : sprintf( __( 'IPv4 address, %s', 'beltoft-services' ), $price );

		if ( ! $free ) {
			Ipv4Alerts::sold_out();
		}

		echo '<div class="beltoft-services-ipv4-field">';

		if ( $target['box'] ) {
			printf(
				'<p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="beltoft_services_ipv4" data-nonce="%1$s" data-was="%2$d" %3$s %4$s> <span>%5$s</span></label></p>',
				esc_attr( wp_create_nonce( self::NONCE ) ),
				(int) min( 1, $chosen ),
				checked( $chosen > 0, true, false ),
				disabled( $free, false, false ),
				esc_html( $label )
			);
		} else {
			printf(
				'<p class="form-row form-row-wide"><label for="beltoft_services_ipv4">%1$s</label><input type="number" class="input-text" id="beltoft_services_ipv4" min="0" max="%2$d" step="1" value="%3$d" data-nonce="%4$s" data-was="%3$d" %5$s></p>',
				esc_html( $label ),
				(int) $target['max'],
				(int) $chosen,
				esc_attr( wp_create_nonce( self::NONCE ) ),
				disabled( $free, false, false )
			);
		}

		if ( 'db' === $target['kind'] ) {
			echo '<p class="description">' . esc_html__( 'Reach the database from anywhere, not only from your servers with us. Access stays closed until you add addresses to the allow-list in your dashboard.', 'beltoft-services' ) . '</p>';
		} elseif ( $target['box'] ) {
			echo '<p class="description">' . esc_html__( 'A server with an IPv6 address only can be reached over IPv6. An IPv4 address makes it reachable over IPv4 too. The first IPv4 address becomes the main address of the server.', 'beltoft-services' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Your server has one IPv4 address. Extra addresses are set up in the server itself, which the dashboard explains.', 'beltoft-services' ) . '</p>';
		}

		if ( ! $free ) {
			echo '<p class="description">' . esc_html__( 'No IPv4 address is free right now. You can add one later from your dashboard.', 'beltoft-services' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The script, on the checkout page only, and only when the line is shown.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || null === self::target() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/ipv4-field.js', BELTOFT_SERVICES_FILE ), array( 'jquery', 'beltoft-services-status' ), \BeltoftServices\Assets::version( 'assets/ipv4-field.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesIpv4',
			array(
				'url'    => \WC_AJAX::get_endpoint( self::AJAX ),
				'failed' => __( 'The IPv4 address could not be changed. Reload the page and try again.', 'beltoft-services' ),
			)
		);
	}

	/**
	 * The wc-ajax endpoint: answers with JSON.
	 */
	public static function ajax(): void {
		$answer = self::respond();

		wp_send_json( $answer[0], $answer[1] );
	}

	/**
	 * Nonce checked, then the change.
	 *
	 * @return array{0: array<string, mixed>, 1: int}
	 */
	public static function respond(): array {
		return CartSwap::answer(
			self::NONCE,
			static function (): array {
				$count = isset( $_POST['count'] ) ? absint( wp_unslash( $_POST['count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in CartSwap::answer().

				// Alone on the session's latest cart, saved before the lock is let go (CartSwap): two quick changes never drop one.
				return CartSwap::locked( static fn(): array => self::choose( $count ) ) ?? array( 'ok' => false, 'count' => self::chosen() );
			}
		);
	}

	/**
	 * Makes the cart hold this many address lines for the new server or database: lines are added or taken away.
	 *
	 * @param int $count Wanted.
	 * @return array{ok: bool, count: int}
	 */
	public static function choose( int $count ): array {
		$target  = self::target();
		$cart    = function_exists( 'WC' ) ? WC()->cart : null;
		$product = Ipv4Cart::product( Ipv4Cart::cart_parent_usage() );

		if ( null === $target || ! $cart || null === $product ) {
			return array( 'ok' => false, 'count' => 0 );
		}

		$count = min( $count, $target['max'] );

		if ( $count > self::chosen() && IpPool::available_for_addon() < $count ) {
			return array( 'ok' => false, 'count' => self::chosen() );
		}

		foreach ( array_reverse( $cart->get_cart(), true ) as $key => $item ) {
			if ( self::chosen() <= $count ) {
				break;
			}

			if ( 'cart_server' === ( $item['beltoft_services_ipv4_for'] ?? null ) ) {
				$cart->remove_cart_item( (string) $key );
			}
		}

		$parent     = $product instanceof \WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
		$variation  = $product instanceof \WC_Product_Variation ? $product->get_id() : 0;
		$attributes = array();

		foreach ( $product instanceof \WC_Product_Variation ? $product->get_attributes() : array() as $name => $value ) {
			$attributes[ 'attribute_' . $name ] = $value;
		}

		while ( self::chosen() < $count ) {
			if ( ! $cart->add_to_cart( $parent, 1, $variation, $attributes, array( 'beltoft_services_ipv4_for' => 'cart_server' ) ) ) {
				return array( 'ok' => false, 'count' => self::chosen() );
			}
		}

		return array( 'ok' => true, 'count' => self::chosen() );
	}

	/**
	 * A removed server or database must not leave an address line that cannot be delivered.
	 */
	public static function clean_orphan(): void {
		if ( DiskField::server_swap_in_progress() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		// What the lines may be now: none without exactly one new server or database, else up to its limit.
		$target = self::target();
		$max    = null === $target ? 0 : (int) $target['max'];
		$kept   = 0;

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( 'cart_server' === ( $item['beltoft_services_ipv4_for'] ?? null ) && ++$kept > $max ) {
				WC()->cart->remove_cart_item( (string) $key );
			}
		}
	}
}

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
 * One line, the same for every product: a dropdown for a dedicated server (0 up to the limit), a box for a VPS Basic
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
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'trim_excess' ), 5 );
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

			return array( 'kind' => 'server', 'max' => $dedicated ? Ipv4Meta::max_extra() : 1, 'box' => ! $dedicated );
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
		if ( $target['box'] ) {
			/* translators: %s: the price, such as 19 kr. a month */
			$label = '' === $price ? __( 'IPv4 address', 'beltoft-services' ) : sprintf( __( 'IPv4 address, %s', 'beltoft-services' ), $price );
		} else {
			// A dedicated server already has one address in its plan: the number counts extra ones.
			/* translators: %s: the price of one address, such as 19 kr. a month */
			$label = '' === $price ? __( 'Extra IPv4 addresses', 'beltoft-services' ) : sprintf( __( 'Extra IPv4 addresses, %s each', 'beltoft-services' ), $price );
		}

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
			$available = IpPool::available_for_addon() + $chosen;
			$options   = '';

			for ( $n = 0; $n <= (int) $target['max']; $n++ ) {
				// More than the pool can give is shown but cannot be picked (the server checks it again).
				$options .= sprintf( '<option value="%1$d" %2$s %3$s>%1$d</option>', $n, selected( $chosen, $n, false ), disabled( $n > $available, true, false ) );
			}

			printf(
				'<p class="form-row form-row-wide"><label for="beltoft_services_ipv4">%1$s</label><select class="select" id="beltoft_services_ipv4" data-nonce="%2$s" data-was="%3$d" %4$s>%5$s</select></p>',
				esc_html( $label ),
				esc_attr( wp_create_nonce( self::NONCE ) ),
				(int) $chosen,
				disabled( $free, false, false ),
				$options // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from integers and WordPress helpers.
			);
		}

		if ( 'db' === $target['kind'] ) {
			echo '<p class="description">' . esc_html__( 'Reach the database from anywhere, not only from your servers with us. Access stays closed until you add addresses to the allow-list in your dashboard.', 'beltoft-services' ) . '</p>';
		} elseif ( $target['box'] ) {
			echo '<p class="description">' . esc_html__( 'A server with an IPv6 address only can be reached over IPv6. An IPv4 address makes it reachable over IPv4 too. The first IPv4 address becomes the main address of the server.', 'beltoft-services' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Your server includes one IPv4 address. Choose how many extra addresses you want. They are set up in the server itself, which the dashboard explains.', 'beltoft-services' ) . '</p>';
		}

		if ( ! $free ) {
			echo '<p class="description">' . esc_html__( 'No IPv4 address is free right now. You can add one later from your dashboard.', 'beltoft-services' ) . '</p>';
		}

		if ( $target['box'] && '' !== Ipv6Test::url() ) {
			self::notice( 'db' === $target['kind'], $free, $price );
		}

		echo '</div>';
	}

	/**
	 * The notice of a visitor whose connection has no IPv6 (ipv6-test.js shows it, never before the test has failed): what it means
	 * and one button that ticks the IPv4 box, so the address is added the way the box adds it. Nothing is added by itself.
	 *
	 * @param bool   $database Whether the cart holds a database (else a server).
	 * @param bool   $free     Whether an IPv4 address is free.
	 * @param string $price    The price of the address with its period, or ''.
	 */
	private static function notice( bool $database, bool $free, string $price ): void {
		echo '<div class="beltoft-services-ipv6notice" data-ipv6-notice role="status" hidden>';
		echo '<p>' . esc_html( $database ? __( 'Your internet connection has no IPv6. This database can only be reached over IPv6 unless you add an IPv4 address.', 'beltoft-services' ) : __( 'Your internet connection has no IPv6. This server can only be reached over IPv6 unless you add an IPv4 address.', 'beltoft-services' ) ) . '</p>';

		if ( $free ) {
			/* translators: %s: the price of the address with its period, such as 5 EUR a month */
			$label = '' === $price ? __( 'Buy an IPv4 address', 'beltoft-services' ) : sprintf( __( 'Buy an IPv4 address (+%s)', 'beltoft-services' ), $price );
			echo '<p><button type="button" class="button" data-ipv6-add>' . esc_html( $label ) . '</button></p>';
		}

		// Checked again on request; the same words as everywhere else.
		echo '<p>' . wp_kses( Ipv6Test::button( $database ? 'database' : 'server', __( 'Check again', 'beltoft-services' ) ), array( 'span' => array( 'class' => true, 'data-ipv6-result' => true, 'role' => true ), 'button' => array( 'type' => true, 'class' => true, 'data-ipv6-check' => true, 'data-checking' => true, 'data-ipv6' => true, 'data-none' => true, 'data-unknown' => true ) ) ) . '</p>';

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
		Ipv6Test::enqueue();

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
				// A negative number, an array or text means none (absint would turn -7 into 7).
				$count = isset( $_POST['count'] ) && is_scalar( $_POST['count'] ) ? max( 0, (int) wp_unslash( $_POST['count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in CartSwap::answer(); cast to int.

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
		self::trim();
	}

	/**
	 * Cart and checkout: address lines beyond the limit (a crafted add to cart, a lowered setting) are taken away with a notice.
	 * Checkout validation refuses such a cart as well, so an order never holds more.
	 */
	public static function trim_excess(): void {
		if ( self::trim() > 0 && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'There were more extra IPv4 addresses in your cart than one server can have. The extra ones were removed.', 'beltoft-services' ), 'notice' );
		}
	}

	/**
	 * Takes away the new-server address lines beyond what the cart may hold: none without exactly one new server or database, else up to its limit.
	 *
	 * @return int How many lines were removed.
	 */
	private static function trim(): int {
		if ( DiskField::server_swap_in_progress() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$target  = self::target();
		$max     = null === $target ? 0 : (int) $target['max'];
		$kept    = 0;
		$removed = 0;

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( 'cart_server' === ( $item['beltoft_services_ipv4_for'] ?? null ) && ++$kept > $max ) {
				WC()->cart->remove_cart_item( (string) $key );
				++$removed;
			}
		}

		return $removed;
	}
}

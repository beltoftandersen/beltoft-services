<?php
/**
 * The "Public IPv4 address" checkbox at checkout, for a new managed database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * The public IP add-on is a sibling variation of the database plan (see DbIp and Backups::sibling_by()). Like
 * BackupField, the classic checkout shows a checkbox for a cart that holds one new database whose variation has a
 * sibling; ticking it swaps the cart item through a wc-ajax endpoint and the page then runs `update_checkout`. With
 * no address free in the pool the box is disabled (the customer can add one later from the dashboard). A renewal, a
 * switch or a resubscribe never gets the field.
 *
 * The block checkout is not covered: the field is added through the classic hook only.
 */
final class DbIpField {

	/**
	 * The wc-ajax action (WC_AJAX::get_endpoint()).
	 */
	public const AJAX = 'beltoft_services_db_ip';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'beltoft-services-db-ip';

	/**
	 * Script handle.
	 */
	public const HANDLE = 'beltoft-services-db-ip-field';

	/**
	 * The variation attribute that tells the two variations of a plan apart (name "Public IP", values "No" and "Yes").
	 * The cart shows it as its own line instead.
	 */
	public const ATTRIBUTE = 'public-ip';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ) );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
	}

	/** Show the public IP choice as its own line, with the surcharge inside the database price. */
	public static function item_data( array $data, array $item ): array {
		$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );
		if ( ! $driver || DbDriver::ID !== $driver->id() ) {
			return $data;
		}
		$variation = wc_get_product( (int) ( $item['variation_id'] ?? 0 ) );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return $data;
		}
		$label = wc_attribute_label( self::ATTRIBUTE, $variation );
		$data  = array_values( array_filter( $data, static fn( $row ) => ! is_array( $row ) || ! in_array( (string) ( $row['key'] ?? '' ), array( $label, 'Public IP' ), true ) ) );
		$plan  = Product::plan( (int) $item['product_id'], $variation->get_id() );
		if ( ! DbIp::plan_wants( $plan ) ) {
			return $data;
		}
		$without = Backups::sibling_by( $variation, 'public_ip', false );
		$value   = $without ? Backups::price_difference( $without, $variation ) : __( 'Enabled', 'beltoft-services' );
		$data[]  = array( 'key' => __( 'Public IPv4 address', 'beltoft-services' ), 'value' => $value );
		return $data;
	}

	// -- The cart --------------------------------------------------------------------------------

	/**
	 * The one new database in the cart: not a renewal, a switch or a resubscribe. Null when the cart holds none or
	 * more than one. (DbDiskField uses it too.)
	 *
	 * @return array{key: string, item: array<string, mixed>}|null
	 */
	public static function new_database(): ?array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || OsField::cart_is_existing_server() ) {
			return null;
		}

		$found = null;

		foreach ( $cart->get_cart() as $key => $item ) {
			$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

			if ( ! $driver || DbDriver::ID !== $driver->id() ) {
				continue;
			}

			// Two databases on one order: which one would the box mean?
			if ( null !== $found || ! empty( $item['subscription_renewal'] ) || ! empty( $item['subscription_switch'] ) || ! empty( $item['subscription_resubscribe'] ) ) {
				return null;
			}

			$found = array(
				'key'  => (string) $key,
				'item' => $item,
			);
		}

		return $found;
	}

	/**
	 * Does the cart hold exactly one new database (whether or not it can have a public address)? For a theme's or
	 * the checkout builder's condition on the "Your database" step.
	 */
	public static function cart_has_new_database(): bool {
		return null !== self::new_database();
	}

	/**
	 * The one new database in the cart that the public IP can be switched on or off for.
	 *
	 * @return array{key: string, item: array<string, mixed>, variation: \WC_Product_Variation, has: bool, with: \WC_Product_Variation, without: \WC_Product_Variation}|null Null: no field.
	 */
	public static function cart_database(): ?array {
		$found = self::new_database();

		if ( null === $found || (int) ( $found['item']['variation_id'] ?? 0 ) < 1 ) {
			return null;
		}

		$variation = wc_get_product( (int) $found['item']['variation_id'] );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return null;
		}

		$plan    = Product::plan( (int) $found['item']['product_id'], $variation->get_id() );
		$has     = DbIp::plan_wants( $plan );
		$sibling = Backups::sibling_by( $variation, 'public_ip', ! $has );

		// Stock is not asked here: with the pool empty the variation with the address is out of stock, and the box is then shown disabled.

		if ( null === $sibling ) {
			return null;
		}

		return array(
			'key'       => $found['key'],
			'item'      => $found['item'],
			'variation' => $variation,
			'has'       => $has,
			'with'      => $has ? $variation : $sibling,
			'without'   => $has ? $sibling : $variation,
		);
	}

	/**
	 * Can a new database be given an address now (one is free and the variation can be bought)? A cart that already has
	 * one keeps it.
	 *
	 * @param array<string, mixed> $database cart_database().
	 */
	private static function address_free( array $database ): bool {
		return $database['has'] || ( IpPool::available_for_db() > 0 && $database['with']->is_purchasable() && $database['with']->is_in_stock() );
	}

	// -- Rendering -------------------------------------------------------------------------------

	/**
	 * The checkbox, with the price difference of the public IP variation (excluding tax).
	 */
	public static function render(): void {
		$database = self::cart_database();

		if ( null === $database ) {
			return;
		}

		$price = Backups::price_difference( $database['without'], $database['with'] );
		$free  = self::address_free( $database );

		echo '<div class="beltoft-services-db-ip">';
		printf(
			'<p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="beltoft_services_db_ip" data-nonce="%1$s" %2$s %3$s> <span>%4$s</span></label></p>',
			esc_attr( wp_create_nonce( self::NONCE ) ),
			checked( $database['has'], true, false ),
			disabled( $free, false, false ),
			esc_html(
				/* translators: %s: price difference such as +€5.00 / month */
				sprintf( __( 'Public IPv4 address (%s)', 'beltoft-services' ), $price )
			)
		);
		echo '<p class="description">' . esc_html__( 'Reach the database from anywhere, not only from your servers with us. Access stays closed until you add addresses to the allow-list in your dashboard.', 'beltoft-services' ) . '</p>';

		if ( ! $free ) {
			echo '<p class="description">' . esc_html__( 'No public IPv4 address is free right now. You can add one later from your dashboard.', 'beltoft-services' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The script, on the checkout page only, and only when the box is shown.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || null === self::cart_database() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/db-ip-field.js', BELTOFT_SERVICES_FILE ), array( 'jquery' ), \BeltoftServices\Assets::version( 'assets/db-ip-field.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesDbIp',
			array(
				'url'    => \WC_AJAX::get_endpoint( self::AJAX ),
				'failed' => __( 'The public IPv4 option could not be changed. Please reload the page and try again.', 'beltoft-services' ),
			)
		);
	}

	// -- The swap --------------------------------------------------------------------------------

	/**
	 * The wc-ajax endpoint: answers with JSON.
	 */
	public static function ajax(): void {
		$answer = self::respond();

		wp_send_json( $answer[0], $answer[1] );
	}

	/**
	 * Nonce checked, then the swap.
	 *
	 * @return array{0: array<string, mixed>, 1: int} The reply and its HTTP status.
	 */
	public static function respond(): array {
		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			return array( array( 'ok' => false ), 403 );
		}

		$with   = isset( $_POST['with'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['with'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$result = self::swap( $with );

		return array( $result, $result['ok'] ? 200 : 409 );
	}

	/**
	 * Swaps the cart's database for its sibling variation with or without the public IP. Quantity and other item
	 * data are kept. When the add is refused (stock, say) the first item is put back. A swap to the public IP is
	 * refused while no address is free.
	 *
	 * @param bool $with Whether the cart should end up with the public IP.
	 * @return array{ok: bool, with: bool, variation_id: int}
	 */
	public static function swap( bool $with ): array {
		$database = self::cart_database();
		$cart     = function_exists( 'WC' ) ? WC()->cart : null;

		if ( null === $database || ! $cart ) {
			return array(
				'ok'           => false,
				'with'         => $with,
				'variation_id' => 0,
			);
		}

		if ( $database['has'] === $with ) {
			return array(
				'ok'           => true,
				'with'         => $with,
				'variation_id' => $database['variation']->get_id(),
			);
		}

		if ( $with && ! self::address_free( $database ) ) {
			return array(
				'ok'           => false,
				'with'         => $database['has'],
				'variation_id' => $database['variation']->get_id(),
			);
		}

		$target = $with ? $database['with'] : $database['without'];

		if ( ! BackupField::replace_line( $cart, $database, $target ) ) {
			return array(
				'ok'           => false,
				'with'         => $database['has'],
				'variation_id' => $database['variation']->get_id(),
			);
		}

		return array(
			'ok'           => true,
			'with'         => $with,
			'variation_id' => $target->get_id(),
		);
	}
}

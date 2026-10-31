<?php
/**
 * The "Daily backups" checkbox at checkout, for a new VM purchase.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Backups are sold as a sibling variation of the server plan (see Backups::sibling()). The classic
 * checkout shows a checkbox for a cart that holds one new server whose variation has a sibling; ticking
 * it swaps the cart item for the sibling through a wc-ajax endpoint, and the page then runs
 * `update_checkout`, so the order review shows the new name and price. The OS and SSH key choices are
 * posted fields, not cart data, so they are kept by the swap; other item data is carried over. A
 * renewal, an early renewal or a switch never gets the field (it pays for a server that exists; the
 * account panel changes backups with a switch), nor a cluster without a backup storage (it could
 * not deliver them).
 *
 * The block checkout is not covered: the field is added through the classic hook only.
 */
final class BackupField {

	/**
	 * The wc-ajax action (WC_AJAX::get_endpoint()).
	 */
	public const AJAX = 'beltoft_services_backups';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'beltoft-services-backups';

	/**
	 * Script handle.
	 */
	public const HANDLE = 'beltoft-services-backup-field';

	/**
	 * Cart item keys WooCommerce sets itself: a swap never copies them.
	 */
	private const CART_KEYS = array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ) );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
	}

	/** Show the paid backup choice as its own line, with the surcharge inside the server price. */
	public static function item_data( array $data, array $item ): array {
		$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );
		if ( ! $driver || SshKeyField::DRIVER !== $driver->id() ) {
			return $data;
		}
		$variation = wc_get_product( (int) ( $item['variation_id'] ?? 0 ) );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return $data;
		}
		$backup_label = wc_attribute_label( 'backups', $variation );
		$data = array_values( array_filter( $data, static fn( $row ) => ! is_array( $row ) || (string) ( $row['key'] ?? '' ) !== $backup_label ) );
		$plan = Product::plan( (int) $item['product_id'], $variation->get_id() );
		if ( (int) ( $plan['backup_days'] ?? 0 ) < 1 ) {
			return $data;
		}
		$without = Backups::sibling( $variation, false, true );
		$value = $without ? Backups::price_difference( $without, $variation ) : __( 'Enabled', 'beltoft-services' );
		$data[] = array( 'key' => __( 'Daily backups', 'beltoft-services' ), 'value' => $value );
		return $data;
	}

	// -- The cart --------------------------------------------------------------------------------

	/**
	 * The one server in the cart that backups can be switched on or off for.
	 *
	 * @return array{key: string, item: array<string, mixed>, variation: \WC_Product_Variation, has: bool, with: \WC_Product_Variation|null, without: \WC_Product_Variation|null}|null Null: no field.
	 */
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

			// Two servers on one order: which one would the box mean?
			if ( null !== $found ) {
				return null;
			}

			$found = array(
				'key'  => (string) $key,
				'item' => $item,
			);
		}

		if ( null === $found || (int) ( $found['item']['variation_id'] ?? 0 ) < 1 ) {
			return null;
		}

		$variation = wc_get_product( (int) $found['item']['variation_id'] );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return null;
		}

		$plan    = Product::plan( (int) $found['item']['product_id'], $variation->get_id() );
		$cluster = Clusters::get( (string) ( $plan['cluster'] ?? '' ) );

		if ( null === $cluster || '' === (string) $cluster['backup_storage'] ) {
			return null;
		}

		$has     = (int) ( $plan['backup_days'] ?? 0 ) > 0;
		$sibling = Backups::sibling( $variation, ! $has, true );

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
			'days'      => $has ? (int) $plan['backup_days'] : (int) ( Product::plan( (int) $found['item']['product_id'], $sibling->get_id() )['backup_days'] ?? 0 ),
		);
	}

	// -- Rendering -------------------------------------------------------------------------------

	/**
	 * The checkbox, with the price difference of the backup variation (excluding tax) and its period.
	 */
	public static function render(): void {
		$server = self::cart_server();

		if ( null === $server ) {
			return;
		}

		$price = Backups::price_difference( $server['without'], $server['with'] );

		echo '<div class="beltoft-services-backups">';
		printf(
			'<p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="beltoft_services_backups" data-nonce="%1$s" %2$s> <span>%3$s</span></label></p>',
			esc_attr( wp_create_nonce( self::NONCE ) ),
			checked( $server['has'], true, false ),
			esc_html(
				/* translators: %s: price difference such as +€12.00 / month */
				sprintf( __( 'Daily backups (%s)', 'beltoft-services' ), $price )
			)
		);

		if ( $server['days'] > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of days */
					_n( 'The server\'s boot disk is backed up every night and kept for %d day. Extra disks are not included. You can restore the server from your account.', 'The server\'s boot disk is backed up every night and kept for %d days. Extra disks are not included. You can restore the server from your account.', $server['days'], 'beltoft-services' ),
					$server['days']
				)
			) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The script, on the checkout page only, and only when the box is shown.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || null === self::cart_server() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/backup-field.js', BELTOFT_SERVICES_FILE ), array( 'jquery' ), \BeltoftServices\Assets::version( 'assets/backup-field.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesBackups',
			array(
				'url'    => \WC_AJAX::get_endpoint( self::AJAX ),
				'failed' => __( 'The backups option could not be changed. Please reload the page and try again.', 'beltoft-services' ),
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
	 * Swaps the cart's server for its sibling variation with or without backups. Quantity and other
	 * item data are kept. When the add is refused (stock, say) the first item is put back.
	 *
	 * @param bool $with Whether the cart should end up with backups.
	 * @return array{ok: bool, with: bool, variation_id: int}
	 */
	public static function swap( bool $with ): array {
		$server = self::cart_server();
		$cart   = function_exists( 'WC' ) ? WC()->cart : null;

		if ( null === $server || ! $cart ) {
			return array(
				'ok'           => false,
				'with'         => $with,
				'variation_id' => 0,
			);
		}

		if ( $server['has'] === $with ) {
			return array(
				'ok'           => true,
				'with'         => $with,
				'variation_id' => $server['variation']->get_id(),
			);
		}

		$target = $with ? $server['with'] : $server['without'];
		$added  = self::replace_line( $cart, $server, $target );

		if ( ! $added ) {

			return array(
				'ok'           => false,
				'with'         => $server['has'],
				'variation_id' => $server['variation']->get_id(),
			);
		}

		return array(
			'ok'           => true,
			'with'         => $with,
			'variation_id' => $target->get_id(),
		);
	}

	/**
	 * Replaces the cart line of $server (cart_server() shape: key, item, variation) with $target, the same product's
	 * other variation. Quantity and other item data are kept. When the add is refused (stock, say) the first item is
	 * put back. Totals are recalculated.
	 *
	 * @param \WC_Cart             $cart   Cart.
	 * @param array<string, mixed> $server Key, item and variation of the line.
	 * @param \WC_Product_Variation $target Variation to swap to.
	 * @return bool Whether the target is in the cart.
	 */
	public static function replace_line( \WC_Cart $cart, array $server, \WC_Product_Variation $target ): bool {
		$item = $server['item'];
		$qty  = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$data = array_diff_key( $item, array_flip( self::CART_KEYS ) );

		$added = DiskField::during_server_swap( static function () use ( $cart, $server, $item, $qty, $target, $data ): bool {
			$cart->remove_cart_item( $server['key'] );
			$new_key = $cart->add_to_cart( (int) $item['product_id'], $qty, $target->get_id(), self::attributes( $target ), $data );
			if ( false !== $new_key ) {
				self::server_first( $cart, (string) $new_key );
				return true;
			}
			// Put the first one back: the customer keeps what they had.
			$restored_key = $cart->add_to_cart( (int) $item['product_id'], $qty, $server['variation']->get_id(), self::attributes( $server['variation'] ), $data );
			if ( false !== $restored_key ) {
				self::server_first( $cart, (string) $restored_key );
			}
			return false;
		} );
		$cart->calculate_totals();

		return $added;
	}

	/**
	 * A variation's attributes as add_to_cart() wants them: attribute_<name> => value.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array<string, string>
	 */
	public static function attributes( \WC_Product_Variation $variation ): array {
		$out = array();

		foreach ( $variation->get_attributes() as $name => $value ) {
			$out[ 'attribute_' . $name ] = (string) $value;
		}

		return $out;
	}

	/** Keep the server above its extra disk after replacing the variation. */
	public static function server_first( \WC_Cart $cart, string $key ): void {
		$contents = $cart->get_cart();
		if ( ! isset( $contents[ $key ] ) ) {
			return;
		}
		$item = $contents[ $key ];
		unset( $contents[ $key ] );
		$cart->set_cart_contents( array( $key => $item ) + $contents );
	}
}

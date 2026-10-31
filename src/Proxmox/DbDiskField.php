<?php
/**
 * The "Extra storage" choice at checkout, for a new managed database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * The extra storage is a ladder of variations of the database plan (see DbDisk::ladder()). Like DbIpField, the classic
 * checkout shows a select for a cart that holds one new database whose variation has a ladder; choosing a step swaps the
 * cart item through a wc-ajax endpoint (keeping the public IP choice, which is part of the variation) and the page then
 * runs `update_checkout`. A step that does not fit on the storage is disabled, and the checkout refuses one that does not
 * fit. A renewal, a switch or a resubscribe never gets the field (a switch is the dashboard's "Make larger").
 *
 * The block checkout is not covered: the field is added through the classic hook only.
 */
final class DbDiskField {

	/**
	 * The wc-ajax action (WC_AJAX::get_endpoint()).
	 */
	public const AJAX = 'beltoft_services_db_disk';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'beltoft-services-db-disk';

	/**
	 * Script handle.
	 */
	public const HANDLE = 'beltoft-services-db-disk-field';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render' ), 11 );
		add_action( 'wc_ajax_' . self::AJAX, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 21, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
	}

	// -- The cart --------------------------------------------------------------------------------

	/**
	 * The one new database in the cart that has extra storage steps.
	 *
	 * @return array{key: string, item: array<string, mixed>, variation: \WC_Product_Variation, extra: int, ladder: array<int, \WC_Product_Variation>}|null Null: no field.
	 */
	public static function cart_database(): ?array {
		$found = DbIpField::new_database();

		if ( null === $found || (int) ( $found['item']['variation_id'] ?? 0 ) < 1 ) {
			return null;
		}

		$variation = wc_get_product( (int) $found['item']['variation_id'] );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return null;
		}

		$ladder = DbDisk::ladder( $variation );

		if ( count( $ladder ) < 2 ) {
			return null;
		}

		return array(
			'key'       => $found['key'],
			'item'      => $found['item'],
			'variation' => $variation,
			'extra'     => DbDisk::extra( Product::plan( (int) $found['item']['product_id'], $variation->get_id() ) ),
			'ladder'    => $ladder,
		);
	}

	/**
	 * Can a step be chosen now: it can be bought and its data disk fits on a node? Without extra storage always.
	 *
	 * @param \WC_Product_Variation $step Step.
	 */
	private static function available( \WC_Product_Variation $step ): bool {
		$plan = Product::plan( $step->get_parent_id(), $step->get_id() );

		return 0 === DbDisk::extra( $plan ) || ( $step->is_purchasable() && $step->is_in_stock() && DbDisk::fits_new( $plan ) );
	}

	// -- Rendering -------------------------------------------------------------------------------

	/**
	 * The select, with the price difference of each step (excluding tax) from the plan without extra storage.
	 */
	public static function render(): void {
		$database = self::cart_database();

		if ( null === $database ) {
			return;
		}

		$base = $database['ladder'][0] ?? null;

		echo '<div class="beltoft-services-db-disk">';
		printf( '<p class="form-row"><label for="beltoft_services_db_disk">%s</label> ', esc_html__( 'Extra storage', 'beltoft-services' ) );
		printf(
			'<select id="beltoft_services_db_disk" class="input-select" data-nonce="%1$s" data-current="%2$d">',
			esc_attr( wp_create_nonce( self::NONCE ) ),
			(int) $database['extra']
		);

		$blocked = false;

		foreach ( $database['ladder'] as $extra => $step ) {
			if ( 0 === $extra ) {
				$label = __( 'No extra storage', 'beltoft-services' );
			} else {
				/* translators: 1: extra storage in GB, 2: price difference such as +€4.00 / month */
				$label = null === $base ? sprintf( __( '+%d GB', 'beltoft-services' ), $extra ) : sprintf( __( '+%1$d GB (%2$s)', 'beltoft-services' ), $extra, DbDisk::price_label( $base, $step ) );
			}

			$free     = $extra === $database['extra'] || self::available( $step );
			$blocked  = $blocked || ! $free;
			printf(
				'<option value="%1$d"%2$s%3$s>%4$s</option>',
				(int) $extra,
				selected( $extra, $database['extra'], false ),
				disabled( $free, false, false ),
				esc_html( $label )
			);
		}

		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'More room for your data. You can add more later from your dashboard. Storage can only be made larger, not smaller.', 'beltoft-services' ) . '</p>';

		if ( $blocked ) {
			echo '<p class="description">' . esc_html__( 'Some sizes are not available right now.', 'beltoft-services' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The script, on the checkout page only, and only when the select is shown.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || null === self::cart_database() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/db-disk-field.js', BELTOFT_SERVICES_FILE ), array( 'jquery' ), \BeltoftServices\Assets::version( 'assets/db-disk-field.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesDbDisk',
			array(
				'url'    => \WC_AJAX::get_endpoint( self::AJAX ),
				'failed' => __( 'The extra storage could not be changed. Please reload the page and try again.', 'beltoft-services' ),
			)
		);
	}

	/**
	 * Shows the choice as its own line, with the surcharge inside the database price, and hides the raw attribute row.
	 *
	 * @param array<int, mixed>    $data Rows.
	 * @param array<string, mixed> $item Cart item.
	 * @return array<int, mixed>
	 */
	public static function item_data( array $data, array $item ): array {
		$driver = Product::driver_for( (int) ( $item['product_id'] ?? 0 ) );

		if ( ! $driver || DbDriver::ID !== $driver->id() ) {
			return $data;
		}

		$variation = wc_get_product( (int) ( $item['variation_id'] ?? 0 ) );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return $data;
		}

		$label = wc_attribute_label( DbDisk::ATTRIBUTE, $variation );
		$data  = array_values( array_filter( $data, static fn( $row ) => ! is_array( $row ) || ! in_array( (string) ( $row['key'] ?? '' ), array( $label, 'Extra storage' ), true ) ) );
		$extra = DbDisk::extra( Product::plan( (int) $item['product_id'], $variation->get_id() ) );

		if ( $extra < 1 ) {
			return $data;
		}

		$base  = DbDisk::ladder( $variation )[0] ?? null;
		$value = null === $base ? sprintf( '+%d GB', $extra ) : sprintf( '+%d GB (%s)', $extra, DbDisk::price_label( $base, $variation ) );

		$data[] = array(
			'key'   => __( 'Extra storage', 'beltoft-services' ),
			'value' => $value,
		);

		return $data;
	}

	/**
	 * The checkout refuses extra storage that the settings do not allow or that does not fit.
	 *
	 * @param mixed $data   Posted data.
	 * @param mixed $errors WP_Error of the checkout.
	 */
	public static function validate( $data, $errors ): void {
		unset( $data );

		$found = DbIpField::new_database();

		if ( null === $found || (int) ( $found['item']['variation_id'] ?? 0 ) < 1 || ! $errors instanceof \WP_Error ) {
			return;
		}

		$plan = Product::plan( (int) $found['item']['product_id'], (int) $found['item']['variation_id'] );

		if ( DbDisk::extra( $plan ) < 1 ) {
			return;
		}

		if ( ! DbDisk::within_limits( $plan ) ) {
			$errors->add( 'beltoft_services_db_disk', __( 'This amount of extra storage is not available. Please choose another size.', 'beltoft-services' ) );
		} elseif ( ! DbDisk::fits_new( $plan ) ) {
			$errors->add( 'beltoft_services_db_disk', __( 'This amount of extra storage is not available right now. Please choose a smaller size or try again later.', 'beltoft-services' ) );
		}
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

		$posted = isset( $_POST['extra'] ) ? sanitize_text_field( wp_unslash( $_POST['extra'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$extra  = 1 === preg_match( '/^[0-9]{1,6}$/D', $posted ) ? (int) $posted : -1;
		$result = self::swap( $extra );

		return array( $result, $result['ok'] ? 200 : 409 );
	}

	/**
	 * Swaps the cart's database for the step with this extra storage. Quantity and other item data are kept; the public IP
	 * choice is part of the variation and is kept too. A step that is not on the ladder, cannot be bought or does not
	 * fit is refused, and the cart is left as it was.
	 *
	 * @param int $extra Extra storage in GB (0: none).
	 * @return array{ok: bool, extra: int, variation_id: int}
	 */
	public static function swap( int $extra ): array {
		$database = self::cart_database();
		$cart     = function_exists( 'WC' ) ? WC()->cart : null;

		if ( null === $database || ! $cart || ! isset( $database['ladder'][ $extra ] ) ) {
			return array(
				'ok'           => false,
				'extra'        => null === $database ? $extra : $database['extra'],
				'variation_id' => null === $database ? 0 : $database['variation']->get_id(),
			);
		}

		if ( $database['extra'] === $extra ) {
			return array(
				'ok'           => true,
				'extra'        => $extra,
				'variation_id' => $database['variation']->get_id(),
			);
		}

		$target = $database['ladder'][ $extra ];

		if ( ! self::available( $target ) || ! BackupField::replace_line( $cart, $database, $target ) ) {
			return array(
				'ok'           => false,
				'extra'        => $database['extra'],
				'variation_id' => $database['variation']->get_id(),
			);
		}

		return array(
			'ok'           => true,
			'extra'        => $extra,
			'variation_id' => $target->get_id(),
		);
	}
}

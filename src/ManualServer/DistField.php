<?php
/**
 * The operating system choice at checkout, for dedicated server products.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Checkout\BlockField;
use BeltoftServices\Checkout\ClassicPost;
use BeltoftServices\Checkout\OrderFieldMeta;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Proxmox\Vm\SshKeyField;
use BeltoftServices\Proxmox\Vm\Tiles;

defined( 'ABSPATH' ) || exit;

/**
 * Built like Proxmox\Vm\OsField, but the choices are what the supplier lists for the product (DistCatalog, the `dist` of GET /order/server/product), never
 * the VPS template list. Family tiles with a version select inside (Tiles::family_items). The posted value is the exact string of the list: it is
 * saved on the order line (classic) or the order (block checkout), copied to the resource meta `dedicated_dist` and sent unchanged as `dist` in the
 * order. A product whose list is empty or not readable asks nothing: the plan's `server_dist` or the setting applies.
 */
final class DistField {

	public const POST_KEY       = 'beltoft_services_dedicated_dist';
	public const ITEM_META      = '_beltoft_services_dedicated_dist';
	public const BLOCK_FIELD_ID = 'beltoft-services/dedicated-system';
	public const META           = 'dedicated_dist';

	/** Hooks. */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_classic' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 10, 4 );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
	}

	/** The tiles' style and script on a checkout that asks. */
	public static function assets(): void {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && null !== self::cart_choice() && ! OsField::cart_is_existing_server() ) {
			Tiles::enqueue();
		}
	}

	/**
	 * The supplier product id of a cart or order line of a dedicated server, or empty.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 */
	private static function product_ref( int $product_id, int $variation_id ): string {
		$driver = Product::driver_for( $product_id );

		if ( ! $driver || ManualServerDriver::ID !== $driver->id() ) {
			return '';
		}

		return (string) ( Product::plan( $product_id, $variation_id > 0 ? $variation_id : null )['server_ref'] ?? '' );
	}

	/**
	 * What the cart offers: the strings that every dedicated server in it lists. Null when there is none to choose (no such server, a list that is
	 * empty or could not be read, or a renewal).
	 *
	 * @return array{options: string[]}|null
	 */
	public static function cart_choice(): ?array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || OsField::cart_is_existing_server() ) {
			return null;
		}

		$shared = null;

		foreach ( $cart->get_cart() as $item ) {
			$ref = self::product_ref( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );

			if ( '' === $ref ) {
				continue;
			}

			$list = DistCatalog::for_product( $ref );

			// A product the supplier does not list (or lists nothing for) asks nothing: the plan's or the setting's system applies.
			if ( array() === $list ) {
				continue;
			}

			$shared = null === $shared ? $list : array_values( array_intersect( $shared, $list ) );
		}

		return null === $shared || array() === DistCatalog::every() ? null : array( 'options' => $shared );
	}

	/**
	 * @param mixed $raw     Input.
	 * @param array $choice  What is offered.
	 */
	private static function refusal( $raw, array $choice ): ?string {
		if ( is_string( $raw ) && in_array( trim( $raw ), $choice['options'], true ) ) {
			return null;
		}

		return array() === $choice['options'] ? __( "The servers in your cart don't share an operating system. Order them separately.", 'beltoft-services' ) : __( 'Choose an operating system for your server.', 'beltoft-services' );
	}

	/** The classic checkout: the tiles. */
	public static function render_classic(): void {
		$choice = self::cart_choice();

		if ( null === $choice || array() === $choice['options'] ) {
			if ( null !== $choice ) {
				echo '<p class="beltoft-services-os woocommerce-error">' . esc_html( self::refusal( '', $choice ) ) . '</p>';
			}

			return;
		}

		$posted = trim( ClassicPost::raw( self::POST_KEY ) );

		echo '<div class="beltoft-services-os form-row form-row-wide validate-required" id="' . esc_attr( self::POST_KEY ) . '_field">';
		Tiles::print_group(
			array(
				'kind'   => 'os',
				'id'     => self::POST_KEY,
				'name'   => self::POST_KEY,
				'legend' => __( 'Operating system', 'beltoft-services' ),
				'items'  => Tiles::family_items( DistCatalog::entries( $choice['options'] ), in_array( $posted, $choice['options'], true ) ? $posted : '' ),
			)
		);
		echo '<span class="description">' . esc_html__( 'The system installed on your server.', 'beltoft-services' ) . '</span>';
		echo '</div>';
	}

	/**
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate_classic( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$choice = self::cart_choice();
		$why    = null === $choice ? null : self::refusal( trim( ClassicPost::raw( self::POST_KEY ) ), $choice );

		if ( null !== $why && $errors instanceof \WP_Error ) {
			$errors->add( 'beltoft_services_dedicated_dist', $why );
		}
	}

	/**
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key (unused).
	 * @param array<string, mixed>   $values Cart item (unused).
	 * @param \WC_Order              $order  Order (unused).
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$ref    = self::product_ref( (int) $item->get_product_id(), (int) $item->get_variation_id() );
		$choice = self::cart_choice();
		$posted = trim( ClassicPost::raw( self::POST_KEY ) );

		if ( '' !== $ref && null !== $choice && in_array( $posted, $choice['options'], true ) ) {
			$item->add_meta_data( self::ITEM_META, $posted, true );
		}
	}

	/**
	 * Product ids (parents and variations) of the dedicated server products, for the block field's condition.
	 *
	 * @return int[]
	 */
	private static function product_ids(): array {
		$parents = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => Product::META_DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one small lookup.
					'meta_value'     => ManualServerDriver::ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);

		if ( ! $parents ) {
			return array();
		}

		$variations = get_posts( array( 'post_type' => 'product_variation', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'post_parent__in' => $parents ) );

		return array_values( array_unique( array_merge( $parents, array_map( 'intval', $variations ) ) ) );
	}

	/**
	 * Arguments of the block checkout field: a required select hidden unless the cart holds a dedicated server (and not a renewal).
	 *
	 * @return array<string, mixed>
	 */
	public static function block_field_args(): array {
		$options = array();

		foreach ( DistCatalog::every() as $dist ) {
			$options[] = array( 'value' => $dist, 'label' => $dist );
		}

		$ids    = self::product_ids();
		$hidden = SshKeyField::block_field_args()['hidden'];
		// Same condition as the SSH key, with the dedicated products instead of every product that takes a key.
		$hidden['cart']['anyOf'][0]['properties']['items']['not']['contains']['enum'] = $ids ? $ids : array( 0 );

		return array(
			'id'                         => self::BLOCK_FIELD_ID,
			'label'                      => __( 'Operating system', 'beltoft-services' ),
			'optionalLabel'              => __( 'Operating system', 'beltoft-services' ),
			'placeholder'                => __( 'Choose an operating system', 'beltoft-services' ),
			'location'                   => 'order',
			'type'                       => 'select',
			'options'                    => $options,
			'required'                   => true,
			'show_in_order_confirmation' => true,
			'hidden'                     => $hidden,
			'sanitize_callback'          => array( __CLASS__, 'block_sanitize' ),
			'validate_callback'          => array( __CLASS__, 'block_validate' ),
		);
	}

	/** Registers the block field when the supplier lists systems. */
	public static function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! self::product_ids() || array() === DistCatalog::every() ) {
			return;
		}

		BlockField::register( self::block_field_args() );
	}

	/**
	 * @param mixed $value Entry.
	 */
	public static function block_sanitize( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * @param mixed $value Entry.
	 * @return true|\WP_Error
	 */
	public static function block_validate( $value ) {
		$choice = self::cart_choice();

		if ( null === $choice ) {
			return true;
		}

		$why = self::refusal( $value, $choice );

		return null === $why ? true : new \WP_Error( 'beltoft_services_dedicated_dist', $why );
	}

	/**
	 * Hands the choice to the new resource, checked once more against the list of that product.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || ManualServerDriver::ID !== $driver->id() ) {
			return $meta;
		}

		$order = ! empty( $license['order_id'] ) ? wc_get_order( (int) $license['order_id'] ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return $meta;
		}

		$product_id = (int) ( $license['product_id'] ?? 0 );
		$variation  = (int) ( $license['variation_id'] ?? 0 );
		$ref        = self::product_ref( $product_id, $variation );

		foreach ( OrderFieldMeta::candidates( $order, $product_id, $variation, self::ITEM_META, self::BLOCK_FIELD_ID ) as $candidate ) {
			if ( is_string( $candidate ) && '' !== $ref && DistCatalog::offers( $ref, $candidate ) ) {
				$meta[ self::META ] = $candidate;
				break;
			}
		}

		return $meta;
	}
}

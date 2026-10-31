<?php
/**
 * The licence tick box at checkout for a database engine that has terms (SQL Server Express).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A required checkbox in the classic checkout for a cart that holds a new database of such an engine (not a renewal or a
 * switch). The time of the acceptance is saved on the order line and becomes the database's `db_terms_accepted`; the
 * build refuses a database without it (DbBuild::plan_error()) and the guest refuses an init job without it. An admin
 * who makes a database by hand accepts the terms for the customer with the filter `beltoft_services_db_terms_accepted`.
 * The box sits above the Place order button, where WooCommerce puts its own terms box: that part of the checkout is
 * always shown, whatever steps a theme hides. WooCommerce redraws it on every checkout refresh, so a small script
 * keeps the tick. The block checkout is not covered, so such a database cannot be bought through it.
 */
final class DbTermsField {

	public const POST_KEY  = 'beltoft_services_db_terms';
	public const ITEM_META = '_beltoft_services_db_terms';
	public const META      = 'db_terms_accepted';

	public static function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'keep_tick' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 10, 4 );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
	}

	/**
	 * The terms of the engine of a product's plan, or null when it has none.
	 */
	private static function terms_of( int $product_id, int $variation_id ): ?DbEngineTerms {
		$driver = Product::driver_for( $product_id );

		if ( ! $driver || DbDriver::ID !== $driver->id() ) {
			return null;
		}

		$engine = DbEngines::for_plan( Product::plan( $product_id, $variation_id > 0 ? $variation_id : null ) );

		return $engine instanceof DbEngineTerms ? $engine : null;
	}

	/**
	 * The terms the cart needs accepted (the first database with some), or null.
	 */
	public static function cart_terms(): ?DbEngineTerms {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || OsField::cart_is_existing_server() ) {
			return null;
		}

		foreach ( $cart->get_cart() as $item ) {
			$terms = self::terms_of( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );

			if ( null !== $terms ) {
				return $terms;
			}
		}

		return null;
	}

	/**
	 * Has the licence of this database's engine been accepted?
	 */
	public static function accepted( Resource $r ): bool {
		/**
		 * Lets an admin accept the licence terms of an engine for a database made without checkout.
		 *
		 * @param bool     $accepted Whether the customer accepted at checkout.
		 * @param Resource $r        Resource.
		 */
		return (bool) apply_filters( 'beltoft_services_db_terms_accepted', (int) $r->get( self::META ) > 0, $r );
	}

	public static function render(): void {
		$terms = self::cart_terms();

		if ( null === $terms ) {
			return;
		}

		$link  = '<a href="' . esc_url( $terms->terms_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'terms', 'beltoft-services' ) . '</a>';
		$label = wp_kses( sprintf( $terms->terms_label(), $link ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) );

		echo '<p class="form-row validate-required beltoft-services-db-terms" id="' . esc_attr( self::POST_KEY ) . '_field"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
		echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="' . esc_attr( self::POST_KEY ) . '" value="1" required /> ';
		echo '<span>' . $label . '</span>&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'beltoft-services' ) . '">*</abbr></label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}

	/**
	 * The checkout refresh redraws the box unticked: tick it again when the customer had ticked it.
	 */
	public static function keep_tick(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! wp_script_is( 'wc-checkout', 'enqueued' ) ) {
			return;
		}

		$name = wp_json_encode( self::POST_KEY );
		wp_add_inline_script(
			'wc-checkout',
			'jQuery(function($){var n=' . $name . ',on=false;$(document.body).on("change","input[name="+n+"]",function(){on=this.checked;}).on("updated_checkout",function(){$("input[name="+n+"]").prop("checked",on);});});'
		);
	}

	/**
	 * Was the box ticked? Only read inside WooCommerce's own checkout run (it verified its nonce).
	 */
	private static function ticked(): bool {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout::process_checkout() verified the nonce; only "1" counts.
		return isset( $_POST[ self::POST_KEY ] ) && '1' === wp_unslash( $_POST[ self::POST_KEY ] );
	}

	/**
	 * Refuses a classic checkout of such a database without the box ticked.
	 *
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( null !== self::cart_terms() && ! self::ticked() && $errors instanceof \WP_Error ) {
			$errors->add( 'beltoft_services_db_terms', __( 'Please accept the licence terms to order this database.', 'beltoft-services' ) );
		}
	}

	/**
	 * Saves the time of the acceptance on each database line of such an engine.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key (unused).
	 * @param array<string, mixed>   $values Cart item (unused).
	 * @param \WC_Order              $order  Order (unused).
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $item instanceof \WC_Order_Item_Product || null === self::terms_of( (int) $item->get_product_id(), (int) $item->get_variation_id() ) || ! self::ticked() ) {
			return;
		}

		$item->add_meta_data( self::ITEM_META, (string) time(), true );
	}

	/**
	 * A new database starts with the time its order line carries.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || DbDriver::ID !== $driver->id() ) {
			return $meta;
		}

		$order      = ! empty( $license['order_id'] ) ? wc_get_order( (int) $license['order_id'] ) : false;
		$product_id = (int) ( $license['product_id'] ?? 0 );
		$variation  = (int) ( $license['variation_id'] ?? 0 );

		if ( ! $order instanceof \WC_Order || null === self::terms_of( $product_id, $variation ) ) {
			return $meta;
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && ( 0 === $variation || (int) $item->get_variation_id() === $variation ) ) {
				$at = $item->get_meta( self::ITEM_META, true );

				if ( is_string( $at ) && ctype_digit( $at ) && (int) $at > 0 ) {
					$meta[ self::META ] = (int) $at;
					break;
				}
			}
		}

		return $meta;
	}
}

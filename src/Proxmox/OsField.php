<?php
/**
 * The operating system choice at checkout, for VM products.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * A required "Operating system" select for carts that hold a VM, built the way SshKeyField is: a
 * classic checkout field saved on the order line item, and a block checkout additional field of
 * location "order" shown only for VM carts. The options are the template list of the product's
 * cluster (Settings → Proxmox clusters), labelled with the list's own labels; the preselected one
 * is the cluster's default template.
 *
 * The chosen template VMID is copied to the new resource as meta `os_template` through the filter
 * beltoft_services_initial_meta. It is checked here against the list, and checked again when the
 * VM is built, against the cluster's list of that moment (an unknown template fails the
 * provisioning with an admin alert). The product's own Template VMID is the fallback when no
 * choice exists: an order from before this field, or a license issued by an admin.
 *
 * One choice per order: every VM product on it gets the same template, so only a template that
 * every VM product's cluster lists is accepted (never a silent change after payment). WooCommerce's block checkout has no default
 * value for additional select fields (the registration takes none), so there the default is
 * listed first and the customer picks explicitly.
 */
final class OsField {

	/**
	 * Classic checkout field name.
	 */
	public const POST_KEY = 'beltoft_services_os_template';

	/**
	 * Order line item meta.
	 */
	public const ITEM_META = '_beltoft_services_os_template';

	/**
	 * Block checkout field id.
	 */
	public const BLOCK_FIELD_ID = 'beltoft-services/operating-system';

	/**
	 * Resource meta key holding the chosen template VMID.
	 */
	public const META = 'os_template';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_classic' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 10, 4 );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
	}

	// -- Options -------------------------------------------------------------------------------

	/**
	 * The templates a cluster offers, the default first: VMID => label.
	 *
	 * @param string $cluster Cluster id.
	 * @return array<int, string>
	 */
	public static function options_for( string $cluster ): array {
		$templates = Clusters::templates( $cluster );
		$default   = Clusters::default_template( $cluster );

		if ( isset( $templates[ $default ] ) ) {
			$templates = array( $default => $templates[ $default ] ) + $templates;
		}

		return $templates;
	}

	/**
	 * The cluster id of a VM product (and variation), or empty.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id, 0 for none.
	 */
	private static function cluster_of( int $product_id, int $variation_id = 0 ): string {
		$driver = Product::driver_for( $product_id );

		if ( ! $driver || SshKeyField::DRIVER !== $driver->id() ) {
			return '';
		}

		$plan = Product::plan( $product_id, $variation_id > 0 ? $variation_id : null );

		return (string) ( $plan['cluster'] ?? '' );
	}

	/**
	 * What the current cart offers: the templates valid for EVERY VM product in it (the
	 * intersection of their clusters' lists, by VMID), the first cluster's default first when it
	 * is among them. The system is never changed after payment, so a template one of the servers'
	 * clusters lacks is not offered.
	 *
	 * @return array{cluster: string, options: array<int, string>, default: int, any: array<int, string>}|null Null: no VM in the cart, or no cluster of it has templates. `options` is empty when the clusters share none; `any` is every template of any of them.
	 */
	public static function cart_choice(): ?array {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart ) {
			return null;
		}

		$first  = '';
		$shared = null;
		$any    = array();

		foreach ( $cart->get_cart() as $item ) {
			$cluster = self::cluster_of( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );

			if ( '' === $cluster ) {
				continue;
			}

			$list = Clusters::templates( $cluster );

			// A cluster with no template list offers no choice: its product's template applies, and
			// it must not empty what the other servers' clusters share.
			if ( array() === $list ) {
				continue;
			}

			$first  = '' === $first ? $cluster : $first;
			$any   += $list;
			$shared = null === $shared ? $list : array_intersect_key( $shared, $list );
		}

		if ( null === $shared || array() === $any ) {
			return null;
		}

		$default = Clusters::default_template( $first );

		if ( isset( $shared[ $default ] ) ) {
			$shared = array( $default => $shared[ $default ] ) + $shared;
		}

		return array(
			'cluster' => $first,
			'options' => $shared,
			'default' => isset( $shared[ $default ] ) ? $default : (int) array_key_first( $shared ),
			'any'     => $any,
		);
	}

	/**
	 * Cart item data keys of a renewal (also early) and a switch of WooCommerce Subscriptions
	 * (WCS_Cart_Renewal, WCS_Cart_Switch; what wcs_cart_contains_renewal(), _early_renewal() and
	 * _switches() look for). They pay for a server that already runs, whose system is never
	 * changed after payment. A resubscribe is not listed: it can build a new server.
	 */
	private const EXISTING_SERVER_KEYS = array( 'subscription_renewal', 'subscription_renewal_early', 'subscription_switch' );

	/**
	 * Does the cart pay for a server that already exists: a renewal, an early renewal or a switch?
	 */
	public static function cart_is_existing_server(): bool {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $item ) {
			foreach ( self::EXISTING_SERVER_KEYS as $key ) {
				if ( ! empty( $item[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Should checkout ask for an operating system (and an SSH key)? True only when the cart holds a
	 * VM product with templates and is not a renewal or switch. Safe for a theme to call.
	 */
	public static function cart_needs_choice(): bool {
		return ! self::cart_is_existing_server() && null !== self::cart_choice();
	}

	/**
	 * The refusal for a choice: the all-servers message when it is a template of some server's
	 * cluster, otherwise the plain "choose" message. Null when it is acceptable.
	 *
	 * @param mixed                                                                                 $raw    Input.
	 * @param array{options: array<int, string>, any: array<int, string>} $choice The cart's choice.
	 */
	private static function refusal( $raw, array $choice ): ?string {
		if ( self::pick( $raw, $choice['options'] ) > 0 ) {
			return null;
		}

		return array() === $choice['options'] || self::pick( $raw, $choice['any'] ) > 0 ? self::not_all_message() : self::invalid_message();
	}

	/**
	 * The message when a template is not valid for all servers of the cart.
	 */
	private static function not_all_message(): string {
		return __( "The chosen operating system isn't available for all servers in your cart.", 'beltoft-services' );
	}

	/**
	 * Every template of every cluster, the first cluster's default first: what the block field
	 * (registered once, before any cart exists) can offer.
	 *
	 * @return array<int, string>
	 */
	private static function all_options(): array {
		$out = array();

		foreach ( array_keys( Clusters::all() ) as $cluster ) {
			foreach ( self::options_for( (string) $cluster ) as $vmid => $label ) {
				if ( ! isset( $out[ $vmid ] ) ) {
					$out[ $vmid ] = $label;
				}
			}
		}

		return $out;
	}

	/**
	 * A template VMID from customer input: a positive integer in a list, else 0.
	 *
	 * @param mixed                $raw     Input.
	 * @param array<int, string> $options Allowed.
	 */
	private static function pick( $raw, array $options ): int {
		if ( is_int( $raw ) ) {
			$raw = (string) $raw;
		}

		if ( ! is_string( $raw ) || ! ctype_digit( trim( $raw ) ) ) {
			return 0;
		}

		$vmid = (int) trim( $raw );

		return isset( $options[ $vmid ] ) ? $vmid : 0;
	}

	/**
	 * The message for a missing or unknown choice.
	 */
	private static function invalid_message(): string {
		return __( 'Choose an operating system for your server.', 'beltoft-services' );
	}

	// -- Classic checkout ---------------------------------------------------------------------

	/**
	 * Shows the select when the cart holds a VM with templates.
	 */
	public static function render_classic(): void {
		$choice = self::cart_needs_choice() ? self::cart_choice() : null;

		if ( null === $choice ) {
			return;
		}

		if ( array() === $choice['options'] ) {
			echo '<p class="beltoft-services-os woocommerce-error">' . esc_html( self::not_all_message() ) . '</p>';
			return;
		}

		echo '<div class="beltoft-services-os">';

		woocommerce_form_field(
			self::POST_KEY,
			array(
				'type'        => 'select',
				'label'       => __( 'Operating system', 'beltoft-services' ),
				'description' => __( 'The system your server is installed with. It is used for every server of this product on the order.', 'beltoft-services' ),
				'required'    => true,
				'options'     => $choice['options'],
			),
			(string) $choice['default']
		);

		echo '</div>';
	}

	/**
	 * The submitted classic value, only inside WooCommerce's own checkout run (it verified its
	 * nonce before firing woocommerce_checkout_process).
	 */
	private static function posted(): string {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce; the value is only used through pick(), which accepts nothing but a listed VMID.
		$value = isset( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Refuses a classic checkout without a listed operating system, when the cart offers one.
	 *
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate_classic( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$choice = self::cart_needs_choice() ? self::cart_choice() : null;

		$why = null === $choice ? null : self::refusal( self::posted(), $choice );

		if ( null !== $why && $errors instanceof \WP_Error ) {
			$errors->add( 'beltoft_services_os', $why );
		}
	}

	/**
	 * Saves the choice on each VM line item of a classic checkout.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param string                 $key    Cart item key (unused).
	 * @param array<string, mixed>   $values Cart item (unused).
	 * @param \WC_Order              $order  Order (unused).
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$cluster = self::cluster_of( (int) $item->get_product_id(), (int) $item->get_variation_id() );

		// No cluster, or one with no template list (its product template applies).
		if ( '' === $cluster || array() === Clusters::templates( $cluster ) ) {
			return;
		}

		$choice = self::cart_needs_choice() ? self::cart_choice() : null;
		$vmid   = null === $choice ? 0 : self::pick( self::posted(), $choice['options'] );

		if ( $vmid > 0 ) {
			$item->add_meta_data( self::ITEM_META, (string) $vmid, true );
		}
	}

	// -- Block checkout -----------------------------------------------------------------------

	/**
	 * Arguments for woocommerce_register_additional_checkout_field(): a required select of
	 * location "order", hidden unless the cart holds a VM product (the same rule as the SSH key).
	 *
	 * @return array<string, mixed>
	 */
	public static function block_field_args(): array {
		$options = array();

		foreach ( self::all_options() as $vmid => $label ) {
			$options[] = array(
				'value' => (string) $vmid,
				'label' => $label,
			);
		}

		$args = SshKeyField::block_field_args();

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
			'hidden'                     => $args['hidden'],
			'sanitize_callback'          => array( __CLASS__, 'block_sanitize' ),
			'validate_callback'          => array( __CLASS__, 'block_validate' ),
		);
	}

	/**
	 * Registers the block field when this WooCommerce has the API and a template is configured
	 * (a select without options is not accepted).
	 */
	public static function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || array() === self::all_options() ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field( self::block_field_args() );
		} catch ( \Exception $e ) {
			unset( $e ); // The classic checkout still works; WooCommerce already reported why.
		}
	}

	/**
	 * The trimmed entry.
	 *
	 * @param mixed $value Entry.
	 */
	public static function block_sanitize( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Refused unless a listed template.
	 *
	 * @param mixed $value Entry.
	 * @return true|\WP_Error
	 */
	public static function block_validate( $value ) {
		// A renewal or switch never changes the system: nothing is asked, nothing refused.
		if ( self::cart_is_existing_server() ) {
			return true;
		}

		// The select lists every configured template (registered once, before any cart); the cart decides here.
		$choice = self::cart_choice();

		if ( null !== $choice ) {
			$why = self::refusal( $value, $choice );

			return null === $why ? true : new \WP_Error( 'beltoft_services_os', $why );
		}

		return self::pick( $value, self::all_options() ) > 0 ? true : new \WP_Error( 'beltoft_services_os', self::invalid_message() );
	}

	// -- Hand-over to the resource ---------------------------------------------------------------

	/**
	 * Adds the customer's choice to the initial meta of a VM resource: the license's order line
	 * (classic) or the block checkout field on the order. Only a positive VMID goes through;
	 * whether the cluster lists it is decided when the VM is built.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || SshKeyField::DRIVER !== $driver->id() ) {
			return $meta;
		}

		$order = ! empty( $license['order_id'] ) ? wc_get_order( (int) $license['order_id'] ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return $meta;
		}

		$candidates = array();
		$product_id = (int) ( $license['product_id'] ?? 0 );
		$variation  = (int) ( $license['variation_id'] ?? 0 );
		$cluster    = self::cluster_of( $product_id, $variation );

		// A cluster with no template list takes no choice: its product's template applies.
		if ( '' === $cluster || array() === Clusters::templates( $cluster ) ) {
			return $meta;
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && ( 0 === $variation || (int) $item->get_variation_id() === $variation ) ) {
				$candidates[] = $item->get_meta( self::ITEM_META, true );
			}
		}

		$prefix       = class_exists( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class ) ? \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::get_group_key( 'other' ) : '_wc_other/';
		$candidates[] = $order->get_meta( $prefix . self::BLOCK_FIELD_ID, true );

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && ctype_digit( $candidate ) && (int) $candidate >= 100 ) {
				$meta[ self::META ] = (int) $candidate;
				break;
			}
		}

		return $meta;
	}
}

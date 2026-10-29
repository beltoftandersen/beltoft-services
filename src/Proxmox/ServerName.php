<?php
/**
 * The optional server name: a display label for a VM, chosen at checkout and renamed in My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Resource;
use BeltoftLicenses\Account\View;

defined( 'ABSPATH' ) || exit;

/**
 * The optional server name, chosen at checkout and changed in My Account. It is the VM's name in
 * Proxmox (the clone is created with it), which cloud-init uses as the guest's hostname, so ssh
 * shows `root@web-01`. It is also stored as resource meta `server_name`, shown as the heading of the
 * server card and where a customer email or the thank-you page names the server, and falls back to
 * the product name (and, for the VM name, to `vm-<uuid>`) when empty.
 *
 * Accepted: a host name label, 1 to 63 characters, lowercase a-z, digits and hyphens, starting and
 * ending with a letter or digit. The entry is lowercased and spaces and underscores become hyphens;
 * everything else is refused with a message, never silently cut.
 *
 * The checkout field is built the way OsField and SshKeyField are: a classic field saved on the
 * order line item and a block checkout additional field of location "order". It is asked only for
 * a new server (DiskField::cart_server(): the one server of the cart, not a renewal, switch or
 * resubscribe). The customer renames later through the action `rename` (Actions::run(): a job under
 * the resource lock that changes the VM name; the hostname follows at the next reboot).
 */
final class ServerName {

	/**
	 * Classic checkout field name.
	 */
	public const POST_KEY = 'beltoft_services_server_name';

	/**
	 * Order line item meta.
	 */
	public const ITEM_META = '_beltoft_services_server_name';

	/**
	 * Block checkout field id.
	 */
	public const BLOCK_FIELD_ID = 'beltoft-services/server-name';

	/**
	 * Resource meta key.
	 */
	public const META = 'server_name';

	/**
	 * Resource meta key: the name the VM has been renamed to while the guest still has the old hostname.
	 */
	public const PENDING = 'server_name_pending';

	/**
	 * The customer action.
	 */
	public const ACTION = 'rename';

	/**
	 * Longest name (a host name label).
	 */
	public const MAX = 63;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_classic' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_on_item' ), 10, 4 );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
		add_filter( 'beltoft_licenses_account_title', array( __CLASS__, 'title' ), 10, 2 );
	}

	// -- Validation ---------------------------------------------------------------------------

	/**
	 * The canonical name, '' for an empty entry, or null when the entry is not acceptable.
	 *
	 * @param mixed $raw What the customer entered.
	 * @return string|null
	 */
	public static function normalise( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$name = trim( $raw );

		if ( '' === $name ) {
			return '';
		}

		$name = strtolower( (string) preg_replace( '/[ _]/', '-', $name ) );

		// A host name label: also refuses control characters, line breaks, tags, dots and non-ASCII letters.
		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name ) ? $name : null;
	}

	/**
	 * The message for a refused name (the entry itself is never repeated).
	 */
	public static function invalid_message(): string {
		/* translators: %d: the longest name, in characters. */
		return sprintf( __( 'The server name is used as the hostname. Use up to %d letters (a to z), digits and hyphens, starting and ending with a letter or digit.', 'beltoft-services' ), self::MAX );
	}

	// -- Display ------------------------------------------------------------------------------

	/**
	 * The name of a server resource, or '' (not a VM, or none set).
	 *
	 * @param Resource $r Resource.
	 */
	public static function of( Resource $r ): string {
		if ( SshKeyField::DRIVER !== $r->get( 'driver' ) ) {
			return '';
		}

		$name = $r->get( self::META );

		return is_string( $name ) && null !== self::normalise( $name ) ? (string) self::normalise( $name ) : '';
	}

	/**
	 * Listener of beltoft_licenses_account_title: the server's name as the heading of its card, the
	 * product name when there is none.
	 *
	 * @param mixed $title   Heading so far.
	 * @param mixed $license License row.
	 * @return mixed
	 */
	public static function title( $title, $license ) {
		$r    = is_array( $license ) && ! empty( $license['id'] ) ? Resource::for_license( (int) $license['id'] ) : null;
		$name = $r ? self::of( $r ) : '';

		return '' !== $name ? $name : $title;
	}

	/**
	 * What a customer message calls this service: the server name, else the product name.
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $license License row.
	 */
	public static function display( Resource $r, array $license ): string {
		$name = self::of( $r );

		return '' !== $name ? $name : View::product_name( $license );
	}

	// -- Classic checkout ---------------------------------------------------------------------

	/**
	 * Shows the field for a new server.
	 */
	public static function render_classic(): void {
		if ( null === DiskField::cart_server() ) {
			return;
		}

		echo '<div class="beltoft-services-server-name">';

		woocommerce_form_field(
			self::POST_KEY,
			array(
				'type'              => 'text',
				'label'             => __( 'Server name', 'beltoft-services' ),
				'description'       => __( "Optional. Used as the server's hostname, for example web-01. You can change it later.", 'beltoft-services' ),
				'required'          => false,
				'custom_attributes' => array(
					'maxlength'    => self::MAX,
					'autocomplete' => 'off',
					'spellcheck'   => 'false',
				),
			),
			''
		);

		echo '</div>';
	}

	/**
	 * The submitted classic value, only inside WooCommerce's own checkout run.
	 */
	private static function posted(): string {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce; the value is only used through normalise().
		$value = isset( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Refuses a classic checkout with a name that is not acceptable. Empty is fine.
	 *
	 * @param mixed     $data   Posted data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate_classic( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( $errors instanceof \WP_Error && null !== DiskField::cart_server() && null === self::normalise( self::posted() ) ) {
			$errors->add( 'beltoft_services_server_name', self::invalid_message() );
		}
	}

	/**
	 * Saves the name on the line item of the new server.
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

		$server = DiskField::cart_server();

		if ( null === $server || (int) ( $server['item']['product_id'] ?? 0 ) !== (int) $item->get_product_id() ) {
			return;
		}

		$name = self::normalise( self::posted() );

		if ( null !== $name && '' !== $name ) {
			$item->add_meta_data( self::ITEM_META, $name, true );
		}
	}

	// -- Block checkout -----------------------------------------------------------------------

	/**
	 * Arguments for woocommerce_register_additional_checkout_field(): an optional text field of
	 * location "order", hidden unless the cart holds a VM, or when it pays a renewal or switch (the
	 * same rule as the SSH key).
	 *
	 * @return array<string, mixed>
	 */
	public static function block_field_args(): array {
		$args = SshKeyField::block_field_args();

		return array(
			'id'                         => self::BLOCK_FIELD_ID,
			'label'                      => __( 'Server name', 'beltoft-services' ),
			'optionalLabel'              => __( 'Server name (optional)', 'beltoft-services' ),
			'location'                   => 'order',
			'type'                       => 'text',
			'required'                   => false,
			'show_in_order_confirmation' => true,
			'hidden'                     => $args['hidden'],
			'sanitize_callback'          => array( __CLASS__, 'block_sanitize' ),
			'validate_callback'          => array( __CLASS__, 'block_validate' ),
		);
	}

	/**
	 * Registers the block field when this WooCommerce has the API.
	 */
	public static function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field( self::block_field_args() );
		} catch ( \Exception $e ) {
			unset( $e ); // The classic checkout still works; WooCommerce already reported why.
		}
	}

	/**
	 * The canonical name when acceptable, otherwise the trimmed entry (the validator then refuses it).
	 *
	 * @param mixed $value Entry.
	 */
	public static function block_sanitize( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return self::normalise( $value ) ?? trim( $value );
	}

	/**
	 * Optional; refused when not acceptable.
	 *
	 * @param mixed $value Entry.
	 * @return true|\WP_Error
	 */
	public static function block_validate( $value ) {
		if ( OsField::cart_is_existing_server() ) {
			return true;
		}

		return null === self::normalise( $value ) ? new \WP_Error( 'beltoft_services_server_name', self::invalid_message() ) : true;
	}

	// -- Hand-over to the resource ---------------------------------------------------------------

	/**
	 * Adds the customer's name to the initial meta of a VM resource: the license's order line, or
	 * the block checkout field on the order. It is checked again here.
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

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $product_id && ( 0 === $variation || (int) $item->get_variation_id() === $variation ) ) {
				$candidates[] = $item->get_meta( self::ITEM_META, true );
			}
		}

		$prefix       = class_exists( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class ) ? \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::get_group_key( 'other' ) : '_wc_other/';
		$candidates[] = $order->get_meta( $prefix . self::BLOCK_FIELD_ID, true );

		foreach ( $candidates as $candidate ) {
			$name = self::normalise( $candidate );

			if ( null !== $name && '' !== $name ) {
				$meta[ self::META ] = $name;
				break;
			}
		}

		return $meta;
	}
}

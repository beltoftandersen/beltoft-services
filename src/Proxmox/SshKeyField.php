<?php
/**
 * The optional SSH public key at checkout, for VM products.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Captures an SSH public key on the classic checkout (a textarea, saved on the order line item)
 * and on the block checkout (an additional checkout field of location "order", shown only when the
 * cart holds a VM), validates it strictly, and hands it to the new resource through the filter
 * beltoft_services_initial_meta.
 *
 * Accepted keys, one line `<type> <base64 blob> [comment]` (the authorized_keys form without
 * options), at most 8192 bytes:
 *
 * - ssh-ed25519: RFC 8709 section 4, a 32 byte key.
 * - ecdsa-sha2-nistp256 / nistp384 / nistp521: RFC 5656 section 3.1, curve name matching the type
 *   and an uncompressed point (0x04, 65 / 97 / 133 bytes).
 * - ssh-rsa: RFC 4253 section 6.6, odd public exponent, modulus of at least 3072 bits.
 *
 * The blob is decoded strictly (exact base64, no trailing bytes, the type inside it equal to the
 * type on the line) and the stored value is the canonical `type base64`, without the comment.
 * Nothing is logged; the value is public key material but not echoed back in errors.
 */
final class SshKeyField {

	/**
	 * Driver id of the Proxmox driver.
	 */
	public const DRIVER = 'proxmox';

	/**
	 * Classic checkout field name.
	 */
	public const POST_KEY = 'beltoft_services_ssh_key';

	/**
	 * Order line item meta (underscore: hidden from the order screen and emails).
	 */
	public const ITEM_META = '_beltoft_services_ssh_key';

	/**
	 * Block checkout field id (namespace/name).
	 */
	public const BLOCK_FIELD_ID = 'beltoft-services/ssh-public-key';

	private const MAX_BYTES = 8192;
	private const MIN_RSA   = 3072;

	private const ECDSA = array(
		'nistp256' => 65,
		'nistp384' => 97,
		'nistp521' => 133,
	);

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

	// -- Validation -----------------------------------------------------------------------------

	/**
	 * The canonical form of a public key, or null when it is not an accepted key.
	 *
	 * @param mixed $raw What the customer entered.
	 * @return string|null `type base64`.
	 */
	public static function normalise( $raw ): ?string {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES ) {
			return null;
		}

		$line = trim( $raw );

		if ( '' === $line || false !== strpbrk( $line, "\r\n\0" ) ) {
			return null;
		}

		$parts = preg_split( '/[ \t]+/', $line, 3 );

		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return null;
		}

		list( $type, $encoded ) = $parts;

		$known = array_merge( array( 'ssh-ed25519', 'ssh-rsa' ), array_map( static fn( $curve ) => 'ecdsa-sha2-' . $curve, array_keys( self::ECDSA ) ) );

		if ( ! in_array( $type, $known, true ) || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $encoded ) ) {
			return null;
		}

		$blob = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Parsing a public key, not obfuscation.

		if ( false === $blob || base64_encode( $blob ) !== $encoded ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Canonical form check.
			return null;
		}

		$canonical = self::canonical_blob( $type, $blob );

		// Stored re-encoded from the parsed fields, never the customer's bytes.
		return null === $canonical ? null : $type . ' ' . base64_encode( $canonical ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a public key blob.
	}

	/**
	 * The blob re-encoded from its parsed fields, or null when it is not a well-formed key of
	 * this type.
	 *
	 * @param string $type Key type from the line.
	 * @param string $blob Decoded blob.
	 */
	private static function canonical_blob( string $type, string $blob ): ?string {
		if ( ! self::blob_is_valid( $type, $blob, $fields ) ) {
			return null;
		}

		$out = self::pack_string( $type );

		foreach ( $fields as $field ) {
			$out .= self::pack_string( $field );
		}

		return $out;
	}

	/**
	 * One SSH string.
	 *
	 * @param string $value Bytes.
	 */
	private static function pack_string( string $value ): string {
		return pack( 'N', strlen( $value ) ) . $value;
	}

	/**
	 * Is the decoded blob a well-formed key of this type?
	 *
	 * @param string             $type   Key type from the line.
	 * @param string             $blob   Decoded blob.
	 * @param array<int, string> $fields Out: the strings after the type, in order.
	 */
	private static function blob_is_valid( string $type, string $blob, ?array &$fields = null ): bool {
		$fields = array();
		$offset = 0;
		$inner  = self::read_string( $blob, $offset );

		if ( $inner !== $type ) {
			return false;
		}

		if ( 'ssh-ed25519' === $type ) {
			$key = self::read_string( $blob, $offset );

			$fields = array( (string) $key );

			return null !== $key && 32 === strlen( $key ) && strlen( $blob ) === $offset;
		}

		if ( 'ssh-rsa' === $type ) {
			$e = self::read_string( $blob, $offset );
			$n = self::read_string( $blob, $offset );

			$fields = array( (string) $e, (string) $n );

			return null !== $e && null !== $n && strlen( $blob ) === $offset && self::rsa_is_valid( $e, $n );
		}

		$curve = self::read_string( $blob, $offset );
		$point = self::read_string( $blob, $offset );

		$fields = array( (string) $curve, (string) $point );

		return 'ecdsa-sha2-' . $curve === $type
			&& isset( self::ECDSA[ $curve ] )
			&& null !== $point
			&& self::ECDSA[ $curve ] === strlen( $point )
			&& "\x04" === $point[0]
			&& strlen( $blob ) === $offset;
	}

	/**
	 * Exponent and modulus (SSH mpints: big endian, a leading zero byte when the top bit is set).
	 *
	 * @param string $e Exponent.
	 * @param string $n Modulus.
	 */
	private static function rsa_is_valid( string $e, string $n ): bool {
		if ( '' === $e || '' === $n || ord( $e[0] ) >= 0x80 || ord( $n[0] ) >= 0x80 ) {
			return false; // Empty or negative.
		}

		// RFC 4251 section 5: no unnecessary leading zero bytes (one only when the next byte has its top bit set).
		if ( "\0" === $e[0] && ( strlen( $e ) < 2 || ord( $e[1] ) < 0x80 ) || "\0" === $n[0] && ( strlen( $n ) < 2 || ord( $n[1] ) < 0x80 ) ) {
			return false;
		}

		$e = ltrim( $e, "\0" );
		$n = ltrim( $n, "\0" );

		if ( '' === $e || '' === $n || 0 === ( ord( $e[ strlen( $e ) - 1 ] ) & 1 ) || 1 === strlen( $e ) && ord( $e ) < 3 ) {
			return false; // The exponent must be odd and at least 3.
		}

		$bits = ( strlen( $n ) - 1 ) * 8 + strlen( decbin( ord( $n[0] ) ) );

		return $bits >= self::MIN_RSA;
	}

	/**
	 * Reads one SSH string (uint32 length, bytes) at an offset.
	 *
	 * @param string $blob   Data.
	 * @param int    $offset Position, moved on.
	 * @return string|null Null when it does not fit.
	 */
	private static function read_string( string $blob, int &$offset ): ?string {
		if ( strlen( $blob ) < $offset + 4 ) {
			return null;
		}

		$length = unpack( 'N', substr( $blob, $offset, 4 ) );
		$length = is_array( $length ) ? (int) $length[1] : -1;

		if ( $length < 0 || strlen( $blob ) < $offset + 4 + $length ) {
			return null;
		}

		$value   = substr( $blob, $offset + 4, $length );
		$offset += 4 + $length;

		return $value;
	}

	/**
	 * The message for a refused key (the entry itself is never repeated).
	 */
	public static function invalid_message(): string {
		return __( 'The SSH public key is not valid. Paste the single line from your .pub file: ssh-ed25519, ecdsa-sha2-nistp256/384/521, or ssh-rsa with at least 3072 bits.', 'beltoft-services' );
	}

	// -- Cart ----------------------------------------------------------------------------------

	/**
	 * Is this product a VM (its driver is the Proxmox one)?
	 *
	 * @param int $product_id Product or variation id.
	 */
	private static function is_vm_product( int $product_id ): bool {
		$driver = Product::driver_for( $product_id );

		return $driver && self::DRIVER === $driver->id();
	}

	/**
	 * Does the current cart hold a VM?
	 */
	private static function cart_has_vm(): bool {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( self::is_vm_product( (int) ( $item['product_id'] ?? 0 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Ids of VM products and their variations: what the block field's rule compares the cart to.
	 *
	 * @return int[]
	 */
	private static function vm_ids(): array {
		$parents = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Product::META_DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one small lookup of VM products.
				'meta_value'     => self::DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$parents = array_map( 'intval', $parents );

		if ( ! $parents ) {
			return array();
		}

		$variations = get_posts(
			array(
				'post_type'       => 'product_variation',
				'post_status'     => 'any',
				'posts_per_page'  => -1,
				'fields'          => 'ids',
				'no_found_rows'   => true,
				'post_parent__in' => $parents,
			)
		);

		return array_values( array_unique( array_merge( $parents, array_map( 'intval', $variations ) ) ) );
	}

	// -- Classic checkout ---------------------------------------------------------------------

	/**
	 * Shows the textarea when the cart holds a VM.
	 */
	public static function render_classic(): void {
		if ( ! self::cart_has_vm() ) {
			return;
		}

		echo '<div class="beltoft-services-ssh-key">';

		woocommerce_form_field(
			self::POST_KEY,
			array(
				'type'              => 'textarea',
				'label'             => __( 'SSH public key', 'beltoft-services' ),
				'description'       => __( 'Optional. Paste the single line from your .pub file to log in to your server without a password. The key is used for every server of this product on the order.', 'beltoft-services' ),
				'required'          => false,
				'custom_attributes' => array(
					'rows'         => 3,
					'maxlength'    => self::MAX_BYTES,
					'autocomplete' => 'off',
					'spellcheck'   => 'false',
				),
			),
			''
		);

		echo '</div>';
	}

	/**
	 * The submitted classic value, only inside WooCommerce's own checkout run, which has
	 * verified its nonce before firing woocommerce_checkout_process.
	 */
	private static function posted(): string {
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC_Checkout::process_checkout() verified the nonce before woocommerce_checkout_process; the value is only ever used through normalise(), which accepts nothing but a strictly parsed key.
		$value = isset( $_POST[ self::POST_KEY ] ) ? wp_unslash( $_POST[ self::POST_KEY ] ) : '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Refuses a classic checkout with a key that is not valid. Empty is fine; a cart without a
	 * VM ignores the field.
	 *
	 * @param mixed     $data   Posted checkout data (unused).
	 * @param \WP_Error $errors Errors.
	 */
	public static function validate_classic( $data, $errors ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		$value = self::posted();

		if ( '' !== $value && $errors instanceof \WP_Error && self::cart_has_vm() && null === self::normalise( $value ) ) {
			$errors->add( 'beltoft_services_ssh_key', self::invalid_message() );
		}
	}

	/**
	 * Saves the canonical key on each VM line item of a classic checkout.
	 *
	 * @param \WC_Order_Item_Product $item     Line item.
	 * @param string                 $key      Cart item key (unused).
	 * @param array<string, mixed>   $values   Cart item (unused).
	 * @param \WC_Order              $order    Order (unused).
	 */
	public static function save_on_item( $item, $key = '', $values = array(), $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $item instanceof \WC_Order_Item_Product || ! self::is_vm_product( (int) $item->get_product_id() ) ) {
			return;
		}

		$canonical = self::normalise( self::posted() );

		if ( null !== $canonical ) {
			$item->add_meta_data( self::ITEM_META, $canonical, true );
		}
	}

	// -- Block checkout -----------------------------------------------------------------------

	/**
	 * Arguments for woocommerce_register_additional_checkout_field() (WooCommerce
	 * src/Blocks/Domain/Services/functions.php): a text field of location "order", optional,
	 * hidden by a JSON schema rule unless the cart holds a VM product.
	 *
	 * @return array<string, mixed>
	 */
	public static function block_field_args(): array {
		$ids = self::vm_ids();

		return array(
			'id'                         => self::BLOCK_FIELD_ID,
			'label'                      => __( 'SSH public key', 'beltoft-services' ),
			'optionalLabel'              => __( 'SSH public key (optional)', 'beltoft-services' ),
			'location'                   => 'order',
			'type'                       => 'text',
			'required'                   => false,
			'show_in_order_confirmation' => false,
			// Hidden when the cart's product ids contain none of the VM products. 0 never is a product id.
			'hidden'                     => array(
				'cart' => array(
					'properties' => array(
						'items' => array(
							'not' => array(
								'contains' => array( 'enum' => $ids ? $ids : array( 0 ) ),
							),
						),
					),
				),
			),
			'sanitize_callback'          => array( __CLASS__, 'block_sanitize' ),
			'validate_callback'          => array( __CLASS__, 'block_validate' ),
		);
	}

	/**
	 * Registers the block checkout field, when this WooCommerce has the API.
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
	 * Canonical form when valid, otherwise the trimmed entry (the validator then refuses it).
	 *
	 * @param mixed $value Entry.
	 */
	public static function block_sanitize( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return self::normalise( $value ) ?? substr( $value, 0, self::MAX_BYTES );
	}

	/**
	 * Optional; refused when not a valid key.
	 *
	 * @param mixed $value Entry.
	 * @return true|\WP_Error
	 */
	public static function block_validate( $value ) {
		if ( is_string( $value ) && '' === trim( $value ) ) {
			return true;
		}

		return null === self::normalise( $value ) ? new \WP_Error( 'beltoft_services_ssh_key', self::invalid_message() ) : true;
	}

	// -- Hand-over to the resource ---------------------------------------------------------------

	/**
	 * Adds the customer's key to the initial meta of a VM resource: the license's order line (or
	 * the block checkout field on the order). For a subscription the license's order is the
	 * parent order. The key is validated again, so nothing stored by anything else is used.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || self::DRIVER !== $driver->id() ) {
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
			$key = self::normalise( $candidate );

			if ( null !== $key ) {
				$meta['ssh_key'] = $key;
				break;
			}
		}

		return $meta;
	}
}

<?php
/**
 * The SSH key at checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\DocumentObject;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation;
use BeltoftServices\Lifecycle;
use BeltoftServices\Proxmox\SshKeyField;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Jobs;
use BeltoftServices\Tests\TestCase;

/**
 * Key validation, checkout capture (classic and block) and the hand-over to the resource.
 */
class SshKeyFieldTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// These tests are about checkout fields, not stock (StockTest covers that).
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		unset( $_POST[ SshKeyField::POST_KEY ] );
		remove_all_filters( 'beltoft_services_initial_meta' );
		parent::tear_down();
	}

	private static function str( string $s ): string {
		return pack( 'N', strlen( $s ) ) . $s;
	}

	/**
	 * A key line with a synthetic blob.
	 *
	 * @param string $type    Key type.
	 * @param string $payload Blob after the type string.
	 * @param string $comment Comment.
	 */
	private static function line( string $type, string $payload, string $comment = 'me@host' ): string {
		return trim( $type . ' ' . base64_encode( self::str( $type ) . $payload ) . ' ' . $comment );
	}

	private static function ed25519( string $comment = 'me@host' ): string {
		return self::line( 'ssh-ed25519', self::str( random_bytes( 32 ) ), $comment );
	}

	private static function rsa( int $bits ): string {
		$n = "\x80" . random_bytes( intdiv( $bits, 8 ) - 1 );

		return self::line( 'ssh-rsa', self::str( "\x01\x00\x01" ) . self::str( "\x00" . $n ) );
	}

	private static function ecdsa( string $curve, int $point_len ): string {
		return self::line( 'ecdsa-sha2-' . $curve, self::str( $curve ) . self::str( "\x04" . random_bytes( $point_len - 1 ) ) );
	}

	public function test_ssh_key_validation_accepts_ed25519_refuses_short_rsa_and_garbage(): void {
		$ed = self::ed25519( 'laptop' );

		$this->assertSame( strtok( $ed, ' ' ) . ' ' . explode( ' ', $ed )[1], SshKeyField::normalise( $ed ), 'Canonical: type and key, no comment.' );
		$this->assertSame( SshKeyField::normalise( $ed ), SshKeyField::normalise( "  \t" . $ed . "  \r\n" ), 'Surrounding whitespace is trimmed.' );
		$this->assertNotNull( SshKeyField::normalise( self::rsa( 3072 ) ) );
		$this->assertNotNull( SshKeyField::normalise( self::rsa( 4096 ) ) );
		$this->assertNotNull( SshKeyField::normalise( self::ecdsa( 'nistp256', 65 ) ) );
		$this->assertNotNull( SshKeyField::normalise( self::ecdsa( 'nistp384', 97 ) ) );
		$this->assertNotNull( SshKeyField::normalise( self::ecdsa( 'nistp521', 133 ) ) );

		$refused = array(
			'short rsa 2048'            => self::rsa( 2048 ),
			'short rsa 3064'            => self::rsa( 3064 ),
			'empty'                     => '',
			'garbage'                   => 'hello world',
			'only a type'               => 'ssh-ed25519',
			'dsa'                       => self::line( 'ssh-dss', self::str( 'x' ) ),
			'sk key'                    => self::line( 'sk-ssh-ed25519@openssh.com', self::str( random_bytes( 32 ) ) ),
			'bad base64'                => 'ssh-ed25519 !!!notbase64!!! me',
			'type differs from blob'    => 'ssh-rsa ' . explode( ' ', $ed )[1],
			'ed25519 wrong length'      => self::line( 'ssh-ed25519', self::str( random_bytes( 31 ) ) ),
			'ed25519 trailing bytes'    => self::line( 'ssh-ed25519', self::str( random_bytes( 32 ) ) . 'x' ),
			'ecdsa wrong point length'  => self::ecdsa( 'nistp256', 33 ),
			'ecdsa curve mismatch'      => self::line( 'ecdsa-sha2-nistp256', self::str( 'nistp384' ) . self::str( "\x04" . random_bytes( 64 ) ) ),
			'option prefix'             => 'command="rm -rf /" ' . $ed,
			'two lines'                 => $ed . "\n" . $ed,
			'newline in the middle'     => "ssh-ed25519\n" . explode( ' ', $ed )[1],
			'oversized'                 => $ed . ' ' . str_repeat( 'a', 9000 ),
			'private key header'        => '-----BEGIN OPENSSH PRIVATE KEY-----',
		);

		foreach ( $refused as $why => $value ) {
			$this->assertNull( SshKeyField::normalise( $value ), $why );
		}

		// Non-canonical base64 (padding stripped) is refused too: what is stored is what was checked.
		$parts = explode( ' ', $ed );
		$this->assertNull( SshKeyField::normalise( $parts[0] . ' ' . rtrim( $parts[1], '=' ) . 'A' ) );
	}

	public function test_rsa_refuses_a_non_minimal_mpint_and_stores_the_re_encoded_key(): void {
		$n = "\x80" . random_bytes( 383 );

		$good = self::line( 'ssh-rsa', self::str( "\x01\x00\x01" ) . self::str( "\x00" . $n ) );
		$this->assertNotNull( SshKeyField::normalise( $good ) );

		// RFC 4251 section 5: extra leading zero bytes are not the minimal form.
		$this->assertNull( SshKeyField::normalise( self::line( 'ssh-rsa', self::str( "\x01\x00\x01" ) . self::str( "\x00\x00" . $n ) ) ), 'modulus with two zero bytes' );
		$this->assertNull( SshKeyField::normalise( self::line( 'ssh-rsa', self::str( "\x00\x01\x00\x01" ) . self::str( "\x00" . $n ) ) ), 'exponent with a needless zero' );
		$this->assertNull( SshKeyField::normalise( self::line( 'ssh-rsa', self::str( "\x01\x00\x01" ) . self::str( "\x00\x7f" . random_bytes( 383 ) ) ) ), 'zero before a byte without the top bit' );

		// What is stored is rebuilt from the parsed fields: exactly the blob the key consists of.
		$normal = (string) SshKeyField::normalise( $good );
		$this->assertSame( explode( ' ', $good )[1], explode( ' ', $normal )[1] );
		$this->assertSame( self::str( 'ssh-rsa' ) . self::str( "\x01\x00\x01" ) . self::str( "\x00" . $n ), base64_decode( explode( ' ', $normal )[1] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	public function test_checkout_field_says_the_key_applies_to_every_vm_of_the_product(): void {
		$this->fake_driver( 'proxmox' );
		$vm = $this->vm_product();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $vm->get_id() );

		ob_start();
		SshKeyField::render_classic();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'every server of this product on the order', $html );
		WC()->cart->empty_cart();
	}

	private function vm_product(): \WC_Product {
		return $this->service_product( 'proxmox' );
	}

	public function test_classic_field_is_shown_only_when_the_cart_holds_a_vm(): void {
		$this->fake_driver( 'proxmox' );
		$vm    = $this->vm_product();
		$plain = $this->service_product( 'fake' );
		$this->fake_driver( 'fake' );

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $plain->get_id() );
		ob_start();
		SshKeyField::render_classic();
		$this->assertSame( '', ob_get_clean() );

		WC()->cart->add_to_cart( $vm->get_id() );
		ob_start();
		SshKeyField::render_classic();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringContainsString( 'name="' . SshKeyField::POST_KEY . '"', $html );
		WC()->cart->empty_cart();
	}

	public function test_classic_validation_refuses_a_bad_key_only_for_vm_carts(): void {
		$this->fake_driver( 'proxmox' );
		$vm = $this->vm_product();

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $vm->get_id() );
		do_action( 'woocommerce_checkout_process' ); // WooCommerce fires it after verifying its nonce.

		$_POST[ SshKeyField::POST_KEY ] = 'not a key';
		$errors                         = new \WP_Error();
		SshKeyField::validate_classic( array(), $errors );
		$this->assertTrue( $errors->has_errors() );
		$this->assertStringNotContainsString( 'not a key', implode( ' ', $errors->get_error_messages() ), 'The entry is not echoed back.' );

		$_POST[ SshKeyField::POST_KEY ] = self::ed25519();
		$errors                         = new \WP_Error();
		SshKeyField::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors() );

		$_POST[ SshKeyField::POST_KEY ] = '';
		$errors                         = new \WP_Error();
		SshKeyField::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors(), 'The key is optional.' );

		WC()->cart->empty_cart();
		$_POST[ SshKeyField::POST_KEY ] = 'not a key';
		$errors                         = new \WP_Error();
		SshKeyField::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors(), 'No VM in the cart: ignored.' );
	}

	public function test_classic_key_is_saved_on_the_vm_line_item_only(): void {
		$this->fake_driver( 'proxmox' );
		$this->fake_driver( 'fake' );
		$vm    = $this->vm_product();
		$plain = $this->service_product( 'fake' );
		$order = wc_create_order();
		$key   = self::ed25519( 'laptop' );

		$_POST[ SshKeyField::POST_KEY ] = $key . "\n";

		// Outside WooCommerce's checkout run (its nonce not verified) the posted value is ignored.
		$probe = new \WC_Order_Item_Product();
		$probe->set_product( $vm );
		SshKeyField::save_on_item( $probe, 'k', array(), $order );
		$this->assertSame( '', $probe->get_meta( SshKeyField::ITEM_META ) );

		do_action( 'woocommerce_checkout_process' );

		foreach ( array( $vm, $plain ) as $product ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product( $product );
			SshKeyField::save_on_item( $item, 'k', array( 'data' => $product ), $order );
			$items[ $product->get_id() ] = $item;
		}

		$this->assertSame( SshKeyField::normalise( $key ), $items[ $vm->get_id() ]->get_meta( SshKeyField::ITEM_META ) );
		$this->assertSame( '', $items[ $plain->get_id() ]->get_meta( SshKeyField::ITEM_META ) );

		$_POST[ SshKeyField::POST_KEY ] = 'garbage';
		$item                            = new \WC_Order_Item_Product();
		$item->set_product( $vm );
		SshKeyField::save_on_item( $item, 'k', array( 'data' => $vm ), $order );
		$this->assertSame( '', $item->get_meta( SshKeyField::ITEM_META ), 'An invalid key is never stored.' );
	}

	public function test_block_field_is_registered_with_a_cart_rule_and_validated(): void {
		$this->fake_driver( 'proxmox' );
		$vm = $this->vm_product();

		$args = SshKeyField::block_field_args();

		$this->assertSame( SshKeyField::BLOCK_FIELD_ID, $args['id'] );
		$this->assertSame( 'order', $args['location'] );
		$this->assertSame( 'text', $args['type'] );
		$this->assertFalse( $args['required'] );
		$this->assertIsCallable( $args['sanitize_callback'] );
		$this->assertIsCallable( $args['validate_callback'] );

		// The hidden rule, run through WooCommerce's own evaluator: hidden unless the cart holds a VM product.
		$rule = function ( array $items ) use ( $args ): bool {
			$doc = new class( $items ) extends DocumentObject {
				private $items;
				public function __construct( array $items ) {
					$this->items = $items;
				}
				public function get_data() {
					return array( 'cart' => array( 'items' => $this->items ) );
				}
			};

			return true === Validation::validate_document_object( $doc, $args['hidden'] );
		};

		$this->assertTrue( $rule( array() ), 'Empty cart: hidden.' );
		$this->assertTrue( $rule( array( 999999 ) ), 'Other product: hidden.' );
		$this->assertFalse( $rule( array( 999999, $vm->get_id() ) ), 'A VM in the cart: shown.' );

		$good = self::ed25519( 'x' );
		$this->assertSame( SshKeyField::normalise( $good ), call_user_func( $args['sanitize_callback'], " $good ", $args ) );
		$this->assertTrue( call_user_func( $args['validate_callback'], SshKeyField::normalise( $good ), $args ) );
		$this->assertTrue( call_user_func( $args['validate_callback'], '', $args ), 'Optional.' );
		$this->assertInstanceOf( \WP_Error::class, call_user_func( $args['validate_callback'], 'junk', $args ) );
	}

	public function test_block_field_registers_with_woocommerce(): void {
		$this->fake_driver( 'proxmox' );
		$this->vm_product();

		$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
		if ( $fields->is_field( SshKeyField::BLOCK_FIELD_ID ) ) {
			// The plugin's own woocommerce_init hook registered it at bootstrap.
			__internal_woocommerce_blocks_deregister_checkout_field( SshKeyField::BLOCK_FIELD_ID );
		}

		SshKeyField::register_block_field();
		$this->assertTrue( $fields->is_field( SshKeyField::BLOCK_FIELD_ID ), 'WooCommerce accepted the field options.' );
		$this->assertTrue( $fields->is_conditional_field( SshKeyField::BLOCK_FIELD_ID ), 'It carries a rule.' );

		__internal_woocommerce_blocks_deregister_checkout_field( SshKeyField::BLOCK_FIELD_ID );
		SshKeyField::register_block_field(); // Back to the bootstrap state.
	}

	/**
	 * A paid order with one VM line, and its license.
	 *
	 * @param array<string, mixed> $item_meta  Meta for the line item.
	 * @param array<string, mixed> $order_meta Meta for the order.
	 * @return array{0: array<string, mixed>, 1: \WC_Order}
	 */
	private function order_license( array $item_meta, array $order_meta = array() ): array {
		$vm    = $this->vm_product();
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $vm );
		$item->set_quantity( 1 );

		foreach ( $item_meta as $k => $v ) {
			$item->add_meta_data( $k, $v );
		}

		$order->add_item( $item );

		foreach ( $order_meta as $k => $v ) {
			$order->update_meta_data( $k, $v );
		}

		$order->save();

		return array( $this->service_license( $vm, array( 'fire' => false, 'order_id' => $order->get_id() ) ), $order );
	}

	public function test_ssh_key_reaches_the_resource_meta_on_provision_and_on_replacement(): void {
		$this->fake_driver( 'proxmox' );
		$key = SshKeyField::normalise( self::ed25519() );

		list( $license ) = $this->order_license( array( SshKeyField::ITEM_META => $key ) );

		Lifecycle::provide( $license );
		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertNotNull( $resource );
		$this->assertSame( $key, $resource->get( 'ssh_key' ) );
		$this->assertSame( 'requested', $resource->get( 'state' ), 'Reserved keys keep their defaults.' );

		// The service is deleted and replaced on the active license: the replacement gets the key too.
		$resource->set( array( 'state' => 'paused' ) );
		$resource->desire( 'deleted', 'Retention over', true );
		Jobs::run( $resource->id() );
		$this->run_jobs();

		$replacement = Resource::for_license( (int) $license['id'] );
		$this->assertNotNull( $replacement );
		$this->assertNotSame( $resource->id(), $replacement->id() );
		$this->assertSame( $key, $replacement->get( 'ssh_key' ) );
	}

	public function test_block_checkout_key_on_the_order_reaches_the_resource(): void {
		$this->fake_driver( 'proxmox' );
		$key = SshKeyField::normalise( self::ed25519() );

		list( $license ) = $this->order_license( array(), array( '_wc_other/' . SshKeyField::BLOCK_FIELD_ID => $key ) );
		Lifecycle::provide( $license );

		$this->assertSame( $key, Resource::for_license( (int) $license['id'] )->get( 'ssh_key' ) );
	}

	public function test_an_invalid_stored_key_is_never_handed_over(): void {
		$this->fake_driver( 'proxmox' );

		list( $license ) = $this->order_license( array( SshKeyField::ITEM_META => 'garbage' ) );
		Lifecycle::provide( $license );

		$this->assertNull( Resource::for_license( (int) $license['id'] )->get( 'ssh_key' ) );
	}

	public function test_a_license_without_an_order_or_key_gets_no_ssh_key(): void {
		$this->fake_driver( 'proxmox' );
		$vm      = $this->vm_product();
		$license = $this->service_license( $vm, array( 'fire' => false ) );

		Lifecycle::provide( $license );

		$this->assertNull( Resource::for_license( (int) $license['id'] )->get( 'ssh_key' ) );
	}

	public function test_initial_meta_cannot_set_reserved_keys(): void {
		$this->fake_driver( 'proxmox' );
		add_filter(
			'beltoft_services_initial_meta',
			static function ( $meta ) {
				$meta['state']    = 'ready';
				$meta['uuid']     = 'attacker';
				$meta['desired']  = 'deleted';
				$meta['custom']   = 'kept';
				return $meta;
			}
		);

		$vm      = $this->vm_product();
		$license = $this->service_license( $vm, array( 'fire' => false ) );
		$driver  = \BeltoftServices\Product::driver_for( $vm->get_id() );

		$meta = Lifecycle::initial_meta( $license, $driver );
		$this->assertSame( array( 'custom' => 'kept' ), $meta );
		foreach ( Reconciler::RESERVED as $key ) {
			$this->assertArrayNotHasKey( $key, $meta );
		}

		Lifecycle::provide( $license );
		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'requested', $resource->get( 'state' ) );
		$this->assertNotSame( 'attacker', $resource->get( 'uuid' ) );
		$this->assertSame( 'kept', $resource->get( 'custom' ) );
	}
}

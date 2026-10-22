<?php
/**
 * The operating system choice at checkout.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\DocumentObject;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation;
use BeltoftServices\Lifecycle;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Options, classic and block capture, validation and the hand-over to the resource. Documentation values only.
 */
class OsFieldTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Clusters::OPTION );
		$this->save_cluster( 'lab', "9000 = Ubuntu 26.04\n9001 = Ubuntu 24.04", '9000' );
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) {
				$drivers['proxmox'] = new ProxmoxDriver();
				return $drivers;
			}
		);
	}

	public function tear_down(): void {
		unset( $_POST[ OsField::POST_KEY ] );
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Clusters::OPTION );
		WC()->cart->empty_cart();
		remove_all_filters( 'beltoft_services_initial_meta' );
		parent::tear_down();
	}

	private function save_cluster( string $id, string $templates, string $default = '' ): void {
		$this->assertTrue(
			Clusters::save(
				$id,
				array(
					'label'             => 'Lab ' . $id,
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => 'secret-secret-secret-1',
					'client'            => 'fake',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'gateway'           => '192.0.2.1',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => $templates,
					'default_template'  => $default,
				)
			)
		);
	}

	private function vm( string $cluster = 'lab', string $slug = 'test-service' ): \WC_Product {
		return $this->service_product( 'proxmox', array( 'cluster' => $cluster, 'template' => 9001 ), array( 'slug' => $slug ) );
	}

	private function html(): string {
		ob_start();
		OsField::render_classic();

		return (string) ob_get_clean();
	}

	public function test_options_list_the_default_first_with_the_admin_labels(): void {
		$this->assertSame( array( 9000 => 'Ubuntu 26.04', 9001 => 'Ubuntu 24.04' ), OsField::options_for( 'lab' ) );

		$this->save_cluster( 'lab', "9000 = Ubuntu 26.04\n9001 = Ubuntu 24.04", '9001' );
		$options = OsField::options_for( 'lab' );
		$this->assertSame( array( 9001, 9000 ), array_keys( $options ), 'The default leads.' );
		$this->assertSame( 'Ubuntu 24.04', $options[9001] );
		$this->assertSame( array(), OsField::options_for( 'nope' ) );
	}

	public function test_classic_select_is_required_preselected_and_only_for_vm_carts_with_templates(): void {
		$plain = $this->service_product( 'fake' );
		$this->fake_driver( 'fake' );
		WC()->cart->add_to_cart( $plain->get_id() );
		$this->assertSame( '', $this->html(), 'No VM in the cart.' );

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->vm()->get_id() );
		$html = $this->html();

		$this->assertStringContainsString( '<select', $html );
		$this->assertStringContainsString( 'name="' . OsField::POST_KEY . '"', $html );
		$this->assertStringContainsString( 'Operating system', $html );
		$this->assertMatchesRegularExpression( '#<option value="9000"[^>]*selected[^>]*>Ubuntu 26\.04#', $html, 'The default is preselected.' );
		$this->assertStringContainsString( '>Ubuntu 24.04</option>', $html );
		$this->assertStringContainsString( 'validate-required', $html, 'Marked required.' );

		// A marker on the other template moves the preselection.
		$this->save_cluster( 'lab', "9000 = Ubuntu 26.04\n9001 = Ubuntu 24.04", '9001' );
		$this->assertMatchesRegularExpression( '#<option value="9001"[^>]*selected[^>]*>Ubuntu 24\.04#', $this->html() );

		// No templates configured: no field.
		delete_option( Clusters::OPTION );
		$this->assertSame( '', $this->html() );
	}

	private const NOT_ALL = "The chosen operating system isn't available for all servers in your cart.";

	/**
	 * Posts a value through the classic validation.
	 *
	 * @param mixed $value Value, null for none.
	 * @return string[] Error messages.
	 */
	private function check( $value ): array {
		do_action( 'woocommerce_checkout_process' );

		if ( null === $value ) {
			unset( $_POST[ OsField::POST_KEY ] );
		} else {
			$_POST[ OsField::POST_KEY ] = $value;
		}

		$errors = new \WP_Error();
		OsField::validate_classic( array(), $errors );

		return $errors->get_error_messages();
	}

	public function test_a_mixed_cluster_cart_offers_and_accepts_only_the_shared_templates(): void {
		$this->save_cluster( 'lab2', "9000 = Ubuntu 26.04\n9002 = Debian 12", '9000' );
		WC()->cart->add_to_cart( $this->vm( 'lab', 'vm-a' )->get_id() );
		WC()->cart->add_to_cart( $this->vm( 'lab2', 'vm-b' )->get_id() );

		$this->assertSame( array( 9000 => 'Ubuntu 26.04' ), OsField::cart_choice()['options'], 'The intersection.' );

		$html = $this->html();
		$this->assertStringContainsString( 'value="9000"', $html );
		$this->assertStringNotContainsString( 'value="9001"', $html );
		$this->assertStringNotContainsString( 'value="9002"', $html );

		$this->assertSame( array(), $this->check( '9000' ), 'Valid for both clusters.' );
		$this->assertSame( array( self::NOT_ALL ), $this->check( '9001' ), 'Only the first cluster has it.' );
		$this->assertSame( array( self::NOT_ALL ), $this->check( '9002' ), 'Only the second cluster has it.' );
		$this->assertCount( 1, $this->check( '9005' ), 'Unknown everywhere.' );
		$this->assertNotSame( array( self::NOT_ALL ), $this->check( '9005' ) );
		$this->assertCount( 1, $this->check( null ) );

		// Saved on the lines only when valid for all.
		$item = new \WC_Order_Item_Product();
		$item->set_product( $this->vm( 'lab', 'vm-a' ) );
		$_POST[ OsField::POST_KEY ] = '9001';
		OsField::save_on_item( $item );
		$this->assertSame( '', $item->get_meta( OsField::ITEM_META ) );
		$_POST[ OsField::POST_KEY ] = '9000';
		OsField::save_on_item( $item );
		$this->assertSame( '9000', $item->get_meta( OsField::ITEM_META ) );
	}

	public function test_a_cart_without_a_shared_template_is_refused_for_any_choice(): void {
		$this->save_cluster( 'lab2', '9002 = Debian 12', '9002' );
		WC()->cart->add_to_cart( $this->vm( 'lab', 'vm-a' )->get_id() );
		WC()->cart->add_to_cart( $this->vm( 'lab2', 'vm-b' )->get_id() );

		$this->assertSame( array(), OsField::cart_choice()['options'] );
		$this->assertStringNotContainsString( '<select', $this->html() );
		$this->assertStringContainsString( 'isn&#039;t available for all servers', $this->html() );

		foreach ( array( '9000', '9001', '9002' ) as $value ) {
			$this->assertSame( array( self::NOT_ALL ), $this->check( $value ), $value );
		}
	}

	public function test_block_validation_uses_the_templates_shared_by_the_cart(): void {
		$this->save_cluster( 'lab2', "9000 = Ubuntu 26.04\n9002 = Debian 12", '9000' );
		$args = OsField::block_field_args();

		// Without a cart: every configured template.
		$this->assertTrue( call_user_func( $args['validate_callback'], '9002', $args ) );

		WC()->cart->add_to_cart( $this->vm( 'lab', 'vm-a' )->get_id() );
		WC()->cart->add_to_cart( $this->vm( 'lab2', 'vm-b' )->get_id() );

		$this->assertTrue( call_user_func( $args['validate_callback'], '9000', $args ) );

		foreach ( array( '9001', '9002' ) as $value ) {
			$result = call_user_func( $args['validate_callback'], $value, $args );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( self::NOT_ALL, $result->get_error_message() );
		}
	}

	public function test_classic_validation_needs_a_listed_template(): void {
		WC()->cart->add_to_cart( $this->vm()->get_id() );
		do_action( 'woocommerce_checkout_process' );

		$check = function ( $value ): bool {
			if ( null === $value ) {
				unset( $_POST[ OsField::POST_KEY ] );
			} else {
				$_POST[ OsField::POST_KEY ] = $value;
			}

			$errors = new \WP_Error();
			OsField::validate_classic( array(), $errors );

			return $errors->has_errors();
		};

		$this->assertFalse( $check( '9000' ) );
		$this->assertFalse( $check( '9001' ) );
		$this->assertTrue( $check( null ), 'Required.' );
		$this->assertTrue( $check( '' ) );
		$this->assertTrue( $check( '9002' ), 'Not in the list.' );
		$this->assertTrue( $check( '9000 ' . "\n" . '9001' ) );
		$this->assertTrue( $check( array( '9000' ) ) );
		$this->assertTrue( $check( '-9000' ) );
		$this->assertTrue( $check( '1e3' ) );

		WC()->cart->empty_cart();
		$this->assertFalse( $check( 'junk' ), 'No VM in the cart: ignored.' );
	}

	public function test_classic_choice_is_saved_on_the_vm_line_item_only(): void {
		$vm    = $this->vm();
		$plain = $this->service_product( 'fake' );
		$this->fake_driver( 'fake' );
		WC()->cart->add_to_cart( $vm->get_id() );
		$_POST[ OsField::POST_KEY ] = '9001';

		$probe = new \WC_Order_Item_Product();
		$probe->set_product( $vm );
		OsField::save_on_item( $probe );
		$this->assertSame( '', $probe->get_meta( OsField::ITEM_META ), 'Outside WooCommerce\'s checkout run the post is ignored.' );

		do_action( 'woocommerce_checkout_process' );

		$items = array();

		foreach ( array( $vm, $plain ) as $product ) {
			$items[ $product->get_id() ] = new \WC_Order_Item_Product();
			$items[ $product->get_id() ]->set_product( $product );
			OsField::save_on_item( $items[ $product->get_id() ] );
		}

		$this->assertSame( '9001', $items[ $vm->get_id() ]->get_meta( OsField::ITEM_META ) );
		$this->assertSame( '', $items[ $plain->get_id() ]->get_meta( OsField::ITEM_META ) );

		$_POST[ OsField::POST_KEY ] = '9002';
		$again                      = new \WC_Order_Item_Product();
		$again->set_product( $vm );
		OsField::save_on_item( $again );
		$this->assertSame( '', $again->get_meta( OsField::ITEM_META ), 'An unlisted value is never stored.' );
	}

	public function test_block_field_is_a_required_select_with_a_cart_rule(): void {
		$vm   = $this->vm();
		$args = OsField::block_field_args();

		$this->assertSame( OsField::BLOCK_FIELD_ID, $args['id'] );
		$this->assertSame( 'select', $args['type'] );
		$this->assertSame( 'order', $args['location'] );
		$this->assertTrue( $args['required'] );
		$this->assertSame(
			array(
				array( 'value' => '9000', 'label' => 'Ubuntu 26.04' ),
				array( 'value' => '9001', 'label' => 'Ubuntu 24.04' ),
			),
			$args['options']
		);

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

		$this->assertTrue( $rule( array() ) );
		$this->assertFalse( $rule( array( $vm->get_id() ) ) );

		$this->assertTrue( call_user_func( $args['validate_callback'], '9001', $args ) );
		$this->assertInstanceOf( \WP_Error::class, call_user_func( $args['validate_callback'], '', $args ) );
		$this->assertInstanceOf( \WP_Error::class, call_user_func( $args['validate_callback'], '9002', $args ) );
		$this->assertSame( '9001', call_user_func( $args['sanitize_callback'], ' 9001 ', $args ) );
	}

	public function test_block_field_registers_with_woocommerce_only_when_templates_exist(): void {
		$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );

		if ( $fields->is_field( OsField::BLOCK_FIELD_ID ) ) {
			__internal_woocommerce_blocks_deregister_checkout_field( OsField::BLOCK_FIELD_ID );
		}

		OsField::register_block_field();
		$this->assertTrue( $fields->is_field( OsField::BLOCK_FIELD_ID ), 'WooCommerce accepted the select options.' );
		$this->assertTrue( $fields->is_required_field( OsField::BLOCK_FIELD_ID ) );
		$this->assertTrue( $fields->is_conditional_field( OsField::BLOCK_FIELD_ID ) );

		__internal_woocommerce_blocks_deregister_checkout_field( OsField::BLOCK_FIELD_ID );
		delete_option( Clusters::OPTION );
		OsField::register_block_field();
		$this->assertFalse( $fields->is_field( OsField::BLOCK_FIELD_ID ), 'Nothing to choose from: no field.' );
	}

	/**
	 * A paid order with one VM line, and its license.
	 *
	 * @param array<string, mixed> $item_meta  Line item meta.
	 * @param array<string, mixed> $order_meta Order meta.
	 * @return array<string, mixed> The license row.
	 */
	private function license( array $item_meta, array $order_meta = array() ): array {
		$vm    = $this->vm();
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

		return $this->service_license( $vm, array( 'fire' => false, 'order_id' => $order->get_id() ) );
	}

	public function test_the_choice_reaches_the_resource_from_the_classic_item_or_the_block_order(): void {
		$classic = $this->license( array( OsField::ITEM_META => '9001' ) );
		Lifecycle::provide( $classic );
		$this->assertSame( 9001, Resource::for_license( (int) $classic['id'] )->get( 'os_template' ) );

		$block = $this->license( array(), array( '_wc_other/' . OsField::BLOCK_FIELD_ID => '9000' ) );
		Lifecycle::provide( $block );
		$this->assertSame( 9000, Resource::for_license( (int) $block['id'] )->get( 'os_template' ) );
	}

	public function test_nothing_usable_stored_means_no_choice_and_the_plan_template_applies(): void {
		foreach ( array( 'junk', '12', '9000x', '-9001', '' ) as $bad ) {
			$license = $this->license( array( OsField::ITEM_META => $bad ) );
			Lifecycle::provide( $license );
			$this->assertNull( Resource::for_license( (int) $license['id'] )->get( 'os_template' ), 'Refused: ' . $bad );
		}

		$no_order = $this->service_license( $this->vm(), array( 'fire' => false ) );
		Lifecycle::provide( $no_order );
		$this->assertNull( Resource::for_license( (int) $no_order['id'] )->get( 'os_template' ) );
	}
}

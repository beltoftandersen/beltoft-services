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

		// These tests are about checkout fields, not stock (StockTest covers that).
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );

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
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
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

	public function test_a_cluster_without_a_template_list_does_not_empty_the_choice_and_keeps_its_own_template(): void {
		$this->save_cluster( 'bare', '' );
		$this->assertSame( array(), Clusters::templates( 'bare' ) );

		$with = $this->vm( 'lab', 'vm-a' );
		$bare = $this->vm( 'bare', 'vm-b' );
		WC()->cart->add_to_cart( $with->get_id() );
		WC()->cart->add_to_cart( $bare->get_id() );

		$choice = OsField::cart_choice();
		$this->assertNotNull( $choice );
		$this->assertSame( array( 9000 => 'Ubuntu 26.04', 9001 => 'Ubuntu 24.04' ), $choice['options'], 'The cluster with a list decides; the bare one is skipped.' );
		$this->assertSame( 'lab', $choice['cluster'] );
		$this->assertSame( array(), $this->check( '9001' ) );
		$this->assertCount( 1, $this->check( '9005' ), 'Still only listed templates.' );

		// The bare cluster's line keeps no choice (it would fail at provision): its product template applies.
		$_POST[ OsField::POST_KEY ] = '9001';
		do_action( 'woocommerce_checkout_process' );
		$line_with = new \WC_Order_Item_Product();
		$line_with->set_product( $with );
		OsField::save_on_item( $line_with );
		$line_bare = new \WC_Order_Item_Product();
		$line_bare->set_product( $bare );
		OsField::save_on_item( $line_bare );
		$this->assertSame( '9001', $line_with->get_meta( OsField::ITEM_META ) );
		$this->assertSame( '', $line_bare->get_meta( OsField::ITEM_META ) );

		// Even an order-wide block choice does not reach the bare cluster's service.
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $bare );
		$item->set_quantity( 1 );
		$order->add_item( $item );
		$order->update_meta_data( '_wc_other/' . OsField::BLOCK_FIELD_ID, '9000' );
		$order->save();
		$license = $this->service_license( $bare, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		Lifecycle::provide( $license );
		$this->assertNull( Resource::for_license( (int) $license['id'] )->get( 'os_template' ) );

		// And the same order choice still reaches a cluster that has a list.
		$order2 = wc_create_order();
		$item2  = new \WC_Order_Item_Product();
		$item2->set_product( $with );
		$item2->set_quantity( 1 );
		$order2->add_item( $item2 );
		$order2->update_meta_data( '_wc_other/' . OsField::BLOCK_FIELD_ID, '9000' );
		$order2->save();
		$license2 = $this->service_license( $with, array( 'fire' => false, 'order_id' => $order2->get_id() ) );
		Lifecycle::provide( $license2 );
		$this->assertSame( 9000, Resource::for_license( (int) $license2['id'] )->get( 'os_template' ) );
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

	// -- Renewal, early renewal, switch and resubscribe carts --------------------------------------

	private function flow_cart( string $flow ): void {
		WC()->cart->empty_cart();

		if ( 'subscription_switch' === $flow ) {
			// WooCommerce Subscriptions drops a switch item whose switch it cannot validate (no real
			// subscription and variable plan here), so the flag is set on a plain item.
			$key = WC()->cart->add_to_cart( $this->vm()->get_id() );
			$this->assertNotFalse( $key );
			WC()->cart->cart_contents[ $key ]['subscription_switch'] = array( 'subscription_id' => 1 );
			return;
		}

		$data = 'new' === $flow ? array() : array( $flow => array( 'subscription_id' => 1, 'item_id' => 0, 'next_payment_timestamp' => 0, 'upgraded_or_downgraded' => '' ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->vm()->get_id(), 1, 0, array(), $data ) );
	}

	private static function valid_ssh_key(): string {
		return 'ssh-ed25519 ' . base64_encode( pack( 'N', 11 ) . 'ssh-ed25519' . pack( 'N', 32 ) . random_bytes( 32 ) ) . ' me@host';
	}

	/**
	 * What both classic fields do for a cart.
	 *
	 * @return array{os: bool, key: bool, os_errors: string[], key_errors: string[], os_saved: string, key_saved: string}
	 */
	private function fields_for_cart(): array {
		ob_start();
		do_action( 'woocommerce_after_order_notes' );
		$html = (string) ob_get_clean();

		do_action( 'woocommerce_checkout_process' );
		$_POST[ OsField::POST_KEY ]                = '9001';
		$_POST[ \BeltoftServices\Proxmox\SshKeyField::POST_KEY ] = self::valid_ssh_key();
		$errors = new \WP_Error();
		OsField::validate_classic( array(), $errors );
		$os_errors = $errors->get_error_messages();

		// No OS posted at all, and a junk key.
		unset( $_POST[ OsField::POST_KEY ] );
		$_POST[ \BeltoftServices\Proxmox\SshKeyField::POST_KEY ] = 'junk';
		$errors = new \WP_Error();
		OsField::validate_classic( array(), $errors );
		\BeltoftServices\Proxmox\SshKeyField::validate_classic( array(), $errors );
		$missing = $errors->get_error_messages();

		$_POST[ OsField::POST_KEY ]                = '9001';
		$_POST[ \BeltoftServices\Proxmox\SshKeyField::POST_KEY ] = self::valid_ssh_key();
		$item = new \WC_Order_Item_Product();
		$item->set_product( $this->vm() );
		OsField::save_on_item( $item );
		\BeltoftServices\Proxmox\SshKeyField::save_on_item( $item );

		unset( $_POST[ \BeltoftServices\Proxmox\SshKeyField::POST_KEY ] );

		return array(
			'os'         => str_contains( $html, OsField::POST_KEY ),
			'key'        => str_contains( $html, \BeltoftServices\Proxmox\SshKeyField::POST_KEY ),
			'os_errors'  => $missing,
			'key_errors' => $os_errors,
			'os_saved'   => (string) $item->get_meta( OsField::ITEM_META ),
			'key_saved'  => (string) $item->get_meta( \BeltoftServices\Proxmox\SshKeyField::ITEM_META ),
		);
	}

	public function test_a_new_purchase_shows_validates_and_saves_both_fields(): void {
		$this->flow_cart( 'new' );

		$this->assertTrue( OsField::cart_needs_choice() );
		$r = $this->fields_for_cart();
		$this->assertTrue( $r['os'] );
		$this->assertTrue( $r['key'] );
		$this->assertCount( 2, $r['os_errors'], 'Missing OS and junk key are refused.' );
		$this->assertSame( array(), $r['key_errors'], 'A listed OS and a valid key pass.' );
		$this->assertSame( '9001', $r['os_saved'] );
		$this->assertNotSame( '', $r['key_saved'] );
	}

	/**
	 * @dataProvider existing_server_flows
	 */
	public function test_renewal_early_renewal_and_switch_carts_ask_for_nothing( string $flow ): void {
		$this->flow_cart( $flow );

		$this->assertTrue( OsField::cart_is_existing_server() );
		$this->assertFalse( OsField::cart_needs_choice() );
		$r = $this->fields_for_cart();
		$this->assertFalse( $r['os'], 'No OS select.' );
		$this->assertFalse( $r['key'], 'No SSH key field.' );
		$this->assertSame( array(), $r['os_errors'], 'Nothing is validated.' );
		$this->assertSame( '', $r['os_saved'], 'Nothing is saved on the item.' );
		$this->assertSame( '', $r['key_saved'] );

		// The block checkout validators accept the empty entries too.
		$this->assertTrue( OsField::block_validate( '' ) );
		$this->assertTrue( \BeltoftServices\Proxmox\SshKeyField::block_validate( 'junk' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function existing_server_flows(): array {
		return array(
			'renewal'       => array( 'subscription_renewal' ),
			'early renewal' => array( 'subscription_renewal_early' ),
			'switch'        => array( 'subscription_switch' ),
		);
	}

	public function test_a_resubscribe_cart_keeps_both_fields_because_it_can_build_a_new_server(): void {
		$this->flow_cart( 'subscription_resubscribe' );

		$this->assertFalse( OsField::cart_is_existing_server() );
		$this->assertTrue( OsField::cart_needs_choice() );
		$r = $this->fields_for_cart();
		$this->assertTrue( $r['os'] );
		$this->assertTrue( $r['key'] );
		$this->assertSame( '9001', $r['os_saved'] );
	}

	public function test_cart_needs_choice_is_false_without_a_vm_or_templates(): void {
		$this->assertFalse( OsField::cart_needs_choice(), 'Empty cart.' );

		$this->flow_cart( 'new' );
		delete_option( Clusters::OPTION );
		$this->assertFalse( OsField::cart_needs_choice(), 'No templates.' );
	}

	public function test_the_block_fields_are_hidden_for_a_renewal_or_switch_cart(): void {
		$vm   = $this->vm();
		$args = OsField::block_field_args();

		$hidden = function ( array $cart ) use ( $args ): bool {
			$doc = new class( $cart ) extends DocumentObject {
				private $data_cart;
				public function __construct( array $cart ) {
					$this->data_cart = $cart;
				}
				public function get_data() {
					return array( 'cart' => $this->data_cart );
				}
			};

			return true === Validation::validate_document_object( $doc, $args['hidden'] );
		};

		$ns = \BeltoftServices\Proxmox\SshKeyField::EXTENSION;
		$this->assertFalse( $hidden( array( 'items' => array( $vm->get_id() ), 'extensions' => (object) array( $ns => array( 'existing_server' => false ) ) ) ) );
		$this->assertTrue( $hidden( array( 'items' => array( $vm->get_id() ), 'extensions' => (object) array( $ns => array( 'existing_server' => true ) ) ) ) );
		$this->assertTrue( $hidden( array( 'items' => array( 999999 ), 'extensions' => (object) array() ) ) );

		// The flag the rule reads is served by the Store API cart extension data.
		$this->flow_cart( 'subscription_renewal' );
		$data = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class )->get_endpoint_data( 'cart' );
		$flag = json_decode( (string) wp_json_encode( $data ), true );
		$this->assertTrue( $flag[ $ns ]['existing_server'] );
	}
}

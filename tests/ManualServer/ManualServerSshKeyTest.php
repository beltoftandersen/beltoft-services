<?php
/**
 * The optional SSH key of a dedicated server: the shared checkout field, the registration at the supplier and the order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\Jobs;
use BeltoftServices\Lifecycle;
use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\ManualServer\ManualServerDelivery;
use BeltoftServices\ManualServer\ManualServerDriver;
use BeltoftServices\ManualServer\ManualServerPanel;
use BeltoftServices\ManualServer\RobotOrder;
use BeltoftServices\Proxmox\Vm\SshKeyField;
use BeltoftServices\Resource;

require_once __DIR__ . '/ManualServerTestCase.php';

final class ManualServerSshKeyTest extends ManualServerTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->automatic();
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		unset( $_POST[ SshKeyField::POST_KEY ] );
		remove_all_filters( 'beltoft_services_key' );
		FakeRobotClient::reset();
		parent::tear_down();
	}

	private static function key( string $comment = 'me@host' ): string {
		$type = 'ssh-ed25519';
		$blob = pack( 'N', strlen( $type ) ) . $type . pack( 'N', 32 ) . random_bytes( 32 );

		return $type . ' ' . base64_encode( $blob ) . ' ' . $comment;
	}

	/**
	 * A waiting server whose resource holds the customer's key.
	 *
	 * @param string $key Key line, or '' for none.
	 */
	private function server_with_key( string $key ): Resource {
		$v   = $this->server_product();
		$r   = $this->waiting_server( $v['u'], $this->new_user() );
		$r->set( '' === $key ? array() : array( 'ssh_key' => (string) SshKeyField::normalise( $key ) ) );
		Jobs::run( $r->id() );

		return Resource::find( $r->id() );
	}

	public function test_the_checkout_field_is_the_shared_one_and_a_bad_key_is_refused(): void {
		$v = $this->server_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $v['u']->get_parent_id(), 1, $v['u']->get_id(), array( 'attribute_server' => 'AMD Ryzen 7, 64 GB', 'attribute_billing' => 'Pay as you go' ) );

		$this->assertTrue( SshKeyField::cart_needs_key(), 'A dedicated server asks for the key like a VPS.' );
		ob_start();
		SshKeyField::render_classic();
		$this->assertStringContainsString( 'name="' . SshKeyField::POST_KEY . '"', (string) ob_get_clean() );

		do_action( 'woocommerce_checkout_process' ); // WooCommerce fires it after verifying its nonce.
		$_POST[ SshKeyField::POST_KEY ] = 'not a key';
		$errors                         = new \WP_Error();
		SshKeyField::validate_classic( array(), $errors );
		$this->assertTrue( $errors->has_errors(), 'An invalid key is refused at checkout.' );
		$this->assertStringNotContainsString( 'not a key', implode( ' ', $errors->get_error_messages() ) );

		$_POST[ SshKeyField::POST_KEY ] = self::key();
		$errors                         = new \WP_Error();
		SshKeyField::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors() );

		$_POST[ SshKeyField::POST_KEY ] = '';
		$errors                         = new \WP_Error();
		SshKeyField::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors(), 'The key is optional.' );
		WC()->cart->empty_cart();
	}

	public function test_the_key_on_the_order_line_reaches_the_dedicated_resource(): void {
		$v      = $this->server_product();
		$user   = $this->new_user();
		$parent = wc_get_product( $v['u']->get_parent_id() );
		$canon  = (string) SshKeyField::normalise( self::key() );
		$order  = wc_create_order( array( 'customer_id' => $user ) );
		$line   = new \WC_Order_Item_Product();
		$line->set_props( array( 'product_id' => $parent->get_id(), 'variation_id' => $v['u']->get_id(), 'quantity' => 1, 'total' => 54 ) );
		$line->add_meta_data( SshKeyField::ITEM_META, $canon, true );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $parent, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id(), 'variation_id' => $v['u']->get_id(), 'source' => 'usage' ) );

		$meta = Lifecycle::initial_meta( $license, new ManualServerDriver() );

		$this->assertSame( $canon, $meta['ssh_key'] );
	}

	public function test_no_key_means_a_generated_password_and_no_key_call(): void {
		$r = $this->server_with_key( '' );

		$this->assertNotNull( ManualServerDelivery::root_password( $r ) );
		$this->assertSame( array(), FakeRobotClient::registered_keys() );
		$this->assertSame( array(), FakeRobotClient::orders()[0]['authorized_key'] );
	}

	public function test_a_key_is_registered_and_ordered_instead_of_a_password(): void {
		$r = $this->server_with_key( self::key() );

		$keys = FakeRobotClient::registered_keys();
		$this->assertCount( 1, $keys );
		$print = (string) array_key_first( $keys );
		$this->assertSame( $print, $r->get( RobotOrder::KEY_FINGERPRINT ) );
		$this->assertSame( array( $print ), FakeRobotClient::orders()[0]['authorized_key'] );
		$this->assertNull( ManualServerDelivery::root_password( $r ), 'The documentation: a key or a password. With a key there is no password.' );
		$this->assertFalse( ManualServerDelivery::has_root_password( $r ) );

		FakeRobotClient::finish( (string) $r->get( RobotOrder::TRANSACTION ) );
		$r->set( array( 'next_attempt_at' => null ) );
		Jobs::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		wp_set_current_user( (int) $fresh->license()['user_id'] );
		ob_start();
		ManualServerPanel::account( $fresh );
		$card = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-manual-reveal', $card, 'No password to show.' );
		$this->assertStringContainsString( 'Log in as root with your SSH key.', $card );
	}

	public function test_a_key_that_is_already_registered_is_found_by_its_data(): void {
		$line = self::key( 'first' );
		$had  = ( new FakeRobotClient() )->add_key( 'older-key', $line );
		$this->assertSame( 'older-key', $had['name'] );

		$r = $this->server_with_key( $line );

		$this->assertCount( 1, FakeRobotClient::registered_keys(), 'The same key is not registered twice.' );
		$this->assertSame( $had['fingerprint'], $r->get( RobotOrder::KEY_FINGERPRINT ) );
		$this->assertSame( array( $had['fingerprint'] ), FakeRobotClient::orders()[0]['authorized_key'] );
		$this->assertSame( 'provisioning', $r->get( 'state' ) );
	}

	public function test_the_live_client_registers_a_key_and_sends_it_in_the_order(): void {
		$seen = array();
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen[] = array( $args['method'], $url, (string) ( $args['body'] ?? '' ) );

				return array( 'response' => array( 'code' => 201, 'message' => '' ), 'body' => wp_json_encode( array( 'key' => array( 'fingerprint' => 'aa:bb' ), 'transaction' => array( 'id' => 'B1' ) ) ), 'headers' => array() );
			},
			1,
			3
		);
		$client = new \BeltoftServices\ManualServer\LiveRobotClient( 'https://robot-ws.example.test', 'u', 'p' );
		$client->add_key( 'my key', 'ssh-ed25519 AAAA me@host' );
		$client->keys();
		$client->order( array( 'product_id' => 'AX42-1', 'authorized_key' => array( 'aa:bb' ), 'addon' => array( 'primary_ipv4' ) ) );

		$this->assertSame( array( 'POST', 'https://robot-ws.example.test/key', 'name=my%20key&data=ssh-ed25519%20AAAA%20me%40host' ), $seen[0] );
		$this->assertSame( array( 'GET', 'https://robot-ws.example.test/key', '' ), $seen[1] );
		$this->assertSame( 'product_id=AX42-1&authorized_key%5B%5D=aa%3Abb&addon%5B%5D=primary_ipv4', $seen[2][2] );
	}
}

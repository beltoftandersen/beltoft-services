<?php
/**
 * The order received page: "What happens next", and the order progress route.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;
use BeltoftServices\ThankYou;

/**
 * Nothing here calls a server: resource meta and the registered drivers only.
 */
final class ThankYouTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		$this->fake_driver();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function order( string $status = 'completed' ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => self::factory()->user->create() ) );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * A service of an order. $driver is the real driver whose kind the row shows (the product uses the fake one).
	 *
	 * @param \WC_Order            $order  Order.
	 * @param string               $driver Driver id stored in the meta.
	 * @param array<string, mixed> $meta   Meta.
	 */
	private function service( \WC_Order $order, string $driver, array $meta = array() ): Resource {
		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'ty-' . wp_generate_password( 6, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $order->get_customer_id() ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array_merge( array( 'state' => 'ready' ), $meta ) );

		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'driver' => $driver ) );

		return Resource::find( $resource->id() );
	}

	private function route( int $order_id, string $key ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/orders/' . $order_id . '/progress' );
		$request->set_query_params( array( 'key' => $key ) );

		return rest_get_server()->dispatch( $request );
	}

	private function page( int $order_id ): string {
		ob_start();
		$order = wc_get_order( $order_id );
		global $wp;
		$wp->query_vars['order-received'] = $order_id;
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
		if ( $order ) {
			do_action( 'woocommerce_order_details_before_order_table', $order );
		}
		do_action( 'woocommerce_thankyou', $order_id );
		remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		unset( $wp->query_vars['order-received'] );

		return (string) ob_get_clean();
	}

	public function test_a_wrong_or_empty_key_gets_403_and_no_data(): void {
		$order = $this->order();
		$this->service( $order, 'fake' );

		foreach ( array( 'wc_order_wrong', '', substr( $order->get_order_key(), 0, 10 ) ) as $key ) {
			$response = $this->route( $order->get_id(), $key );
			$this->assertSame( 403, $response->get_status() );
			$this->assertArrayNotHasKey( 'services', $response->get_data() );
		}

		$this->assertSame( 403, $this->route( 99999999, 'wc_order_x' )->get_status(), 'A missing order answers like a wrong key.' );
	}

	public function test_another_orders_resources_are_never_returned(): void {
		$one = $this->order();
		$two = $this->order();
		$a   = $this->service( $one, 'fake' );
		$b   = $this->service( $two, 'fake' );

		$data = $this->route( $one->get_id(), $one->get_order_key() )->get_data();
		$this->assertSame( array( $a->id() ), array_column( $data['services'], 'id' ) );
		$this->assertStringNotContainsString( (string) $b->get( 'uuid' ), wp_json_encode( $data ) );

		$this->assertSame( 403, $this->route( $two->get_id(), $one->get_order_key() )->get_status(), 'One order\'s key does not open another order.' );
	}

	public function test_a_guest_with_the_key_is_served(): void {
		$order = $this->order();
		$this->service( $order, 'fake' );
		wp_set_current_user( 0 );

		$response = $this->route( $order->get_id(), $order->get_order_key() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['services'] );
		$this->assertContains( 'no-store', array_map( 'trim', explode( ',', (string) ( $response->get_headers()['Cache-Control'] ?? '' ) ) ) );
	}

	public function test_the_route_is_rate_limited_per_visitor_address(): void {
		add_filter( 'beltoft_services_order_progress_rate_limit', static fn() => 3 );
		$order = $this->order();
		$this->service( $order, 'fake' );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->route( $order->get_id(), $order->get_order_key() )->get_status() );
		}

		$this->assertSame( 429, $this->route( $order->get_id(), $order->get_order_key() )->get_status() );
		$this->assertSame( 429, $this->route( $order->get_id(), 'wc_order_wrong' )->get_status(), 'Wrong keys count too, so guessing is slowed.' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
		$this->assertSame( 200, $this->route( $order->get_id(), $order->get_order_key() )->get_status(), 'Another address has its own count.' );
	}

	public function test_the_payload_per_kind_and_state(): void {
		$order = $this->order();
		$vm    = $this->service( $order, 'proxmox', array( 'state' => 'provisioning', 'steps' => array( 'ip' ) ) );
		$disk  = $this->service( $order, 'proxmox-disk', array( 'state' => 'ready' ) );
		$dom   = $this->service( $order, 'domain', array( 'state' => 'ready', 'domain' => 'example.com' ) );
		$dns   = $this->service( $order, 'dns', array( 'state' => 'failed', 'domain' => 'example.org', 'failed_op' => 'provision' ) );

		$rows = array_column( $this->route( $order->get_id(), $order->get_order_key() )->get_data()['services'], null, 'id' );

		$this->assertSame( 'vm', $rows[ $vm->id() ]['kind'] );
		$this->assertFalse( $rows[ $vm->id() ]['ready'] );
		$this->assertSame( 'getting-ready', $rows[ $vm->id() ]['group'] );
		$this->assertSame( 'build', $rows[ $vm->id() ]['progress']['kind'] );
		$this->assertTrue( $rows[ $vm->id() ]['progress']['active'] );
		$this->assertSame( 'vmid', $rows[ $vm->id() ]['progress']['current'] );

		$this->assertSame( 'disk', $rows[ $disk->id() ]['kind'] );
		$this->assertTrue( $rows[ $disk->id() ]['ready'] );
		$this->assertSame( 'Ready', $rows[ $disk->id() ]['label'] );
		$this->assertStringContainsString( '#beltoft-service-' . $disk->id(), $rows[ $disk->id() ]['url'] );
		$this->assertStringContainsString( '#beltoft-disk-guide-' . $disk->id(), $rows[ $disk->id() ]['disk']['guide_url'] );

		$this->assertSame( 'example.com', $rows[ $dom->id() ]['name'] );
		$this->assertSame( 'domain', $rows[ $dom->id() ]['kind'] );

		$this->assertSame( 'dns', $rows[ $dns->id() ]['kind'] );
		$this->assertSame( 'failed', $rows[ $dns->id() ]['group'] );
		$this->assertNotSame( '', $rows[ $dns->id() ]['message'] );
		$this->assertArrayNotHasKey( 'disk', $rows[ $dns->id() ] );
	}

	public function test_a_reply_never_holds_a_secret_an_address_or_the_last_error(): void {
		$order = $this->order();
		$this->service(
			$order,
			'proxmox',
			array(
				'state'      => 'failed',
				'failed_op'  => 'provision',
				'last_error' => 'Proxmox said: token-secret-xyz',
				'ip'         => '192.0.2.77',
				'vmid'       => 4242,
				'ssh_key'    => 'ssh-ed25519 AAAAkeymaterial',
				'reveal'     => 'bs1:encrypted-password',
			)
		);

		$json = wp_json_encode( $this->route( $order->get_id(), $order->get_order_key() )->get_data() );

		foreach ( array( 'token-secret-xyz', '192.0.2.77', '4242', 'AAAAkeymaterial', 'encrypted-password', 'last_error' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}

		$this->assertStringNotContainsString( 'token-secret-xyz', $this->page( $order->get_id() ) );
	}

	public function test_failed_and_cancelled_orders_show_nothing(): void {
		foreach ( array( 'failed', 'cancelled' ) as $status ) {
			$order = $this->order( $status );
			$this->service( $order, 'fake' );

			$this->assertSame( array(), $this->route( $order->get_id(), $order->get_order_key() )->get_data()['services'] );
			$this->assertStringNotContainsString( 'beltoft-next', $this->page( $order->get_id() ) );
		}
	}

	public function test_the_page_prints_the_area_once_with_progress_while_building(): void {
		$order = $this->order();
		$vm    = $this->service( $order, 'proxmox', array( 'state' => 'provisioning', 'steps' => array( 'ip', 'vmid' ) ) );

		$html = $this->page( $order->get_id() );

		$this->assertSame( 1, substr_count( $html, 'id="beltoft-next-title"' ) );
		$this->assertStringContainsString( 'What happens next', $html );
		$this->assertStringContainsString( 'Your services', $html );
		$this->assertSame( 1, substr_count( $html, 'data-service="' . $vm->id() . '"' ) );
		$this->assertStringContainsString( 'data-progress', $html );
		$this->assertStringContainsString( 'Create the server', $html );
		$this->assertStringContainsString( rawurlencode( $order->get_order_key() ), $html );
		$this->assertMatchesRegularExpression( '#orders(/|%2F)' . $order->get_id() . '(/|%2F)progress#', $html );
		$this->assertMatchesRegularExpression( '/data-service-open hidden/', $html, 'No link while it is being set up.' );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_a_ready_service_says_ready_with_a_link_to_its_card(): void {
		$order = $this->order();
		$vm    = $this->service( $order, 'proxmox', array( 'state' => 'ready' ) );

		$html = $this->page( $order->get_id() );

		$this->assertStringContainsString( '<span class="beltoft-service__state" data-service-state>Ready</span>', $html );
		$this->assertStringContainsString( 'Open your server', $html );
		$this->assertStringContainsString( '#beltoft-service-' . $vm->id(), $html );
		$this->assertDoesNotMatchRegularExpression( '/data-service-open hidden/', $html );
	}

	public function test_an_extra_disk_says_where_to_find_it(): void {
		$order  = $this->order();
		$server = $this->service( $order, 'proxmox', array( 'state' => 'ready' ) );
		$disk   = $this->service( $order, 'proxmox-disk', array( 'state' => 'ready', 'server_license' => $server->license()['id'] ) );

		$html = $this->page( $order->get_id() );

		$this->assertStringContainsString( 'Find your disk in My account &gt; VPS &gt; your server &gt; Disks.', $html );
		$this->assertSame( 1, substr_count( $html, 'Find your disk in' ), 'Once, for the one disk.' );
		$this->assertStringContainsString( esc_url( \BeltoftServices\Proxmox\DiskGuide::server_url( $disk ) ), $html );
		$this->assertStringContainsString( esc_url( \BeltoftServices\Proxmox\DiskGuide::guide_url( $disk ) ), $html );
		$this->assertStringContainsString( 'Disk setup guide', $html );
	}

	public function test_the_dashboard_button_opens_the_new_servers_page_even_while_it_builds(): void {
		$order = $this->order();
		$vm    = $this->service( $order, 'proxmox', array( 'state' => 'provisioning', 'steps' => array( 'ip' ) ) );
		$url   = \BeltoftServices\Account\Servers::url( $vm->license() );

		$html = $this->page( $order->get_id() );

		$this->assertStringContainsString( 'Go to your dashboard', $html );
		$this->assertStringContainsString( 'class="beltoft-next__dashboard"><a href="' . esc_url( $url ) . '"', $html );
		$this->assertSame( 1, substr_count( $html, 'Go to your dashboard' ) );
	}

	public function test_the_dashboard_button_picks_the_server_among_several_services(): void {
		$order  = $this->order();
		$this->service( $order, 'proxmox-disk', array( 'state' => 'ready' ) );
		$server = $this->service( $order, 'proxmox', array( 'state' => 'ready' ) );

		$html = $this->page( $order->get_id() );

		$this->assertStringContainsString( esc_url( \BeltoftServices\Account\Servers::url( $server->license() ) ) . '"', $html );
	}

	public function test_the_dashboard_button_follows_a_domain_to_its_card(): void {
		$order  = $this->order();
		$domain = $this->service( $order, 'openprovider', array( 'state' => 'ready' ) );

		$html = $this->page( $order->get_id() );

		$this->assertStringContainsString( 'Go to your dashboard', $html );
		$this->assertStringContainsString( 'beltoft-next__dashboard', $html );
		$this->assertNotNull( $domain );
	}

	public function test_an_order_without_a_service_prints_nothing(): void {
		$this->assertStringNotContainsString( 'beltoft-next', $this->page( $this->order()->get_id() ) );
		$this->assertStringNotContainsString( 'beltoft-next', $this->page( 0 ) );
	}

	public function test_the_dns_block_prints_inside_the_area_once(): void {
		\BeltoftServices\Domains\DnsSchema::maybe_upgrade();
		$order = $this->order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'DNS hosting' );
		$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => 'example.com', 'mode' => 'dns' ), true );
		$order->add_item( $item );
		$order->save();
		$this->service( $order, 'dns', array( 'state' => 'provisioning' ) );

		$html = $this->page( $order->get_id() );

		$this->assertSame( 1, substr_count( $html, 'id="beltoft-next-title"' ) );
		$this->assertSame( 1, substr_count( $html, 'Next steps for example.com' ) );
		$this->assertGreaterThan( strpos( $html, 'beltoft-next-title' ), strpos( $html, 'Next steps for example.com' ) );
	}

	public function test_a_later_view_of_the_order_prints_nothing(): void {
		$order = $this->order();
		$this->service( $order, 'fake' );

		ob_start();
		do_action( 'woocommerce_order_details_before_order_table', $order );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_open_labels_per_kind(): void {
		$this->assertSame( 'Open your server', ThankYou::open_label( 'vm' ) );
		$this->assertSame( 'Open your domain', ThankYou::open_label( 'domain' ) );
		$this->assertSame( 'Open your DNS hosting', ThankYou::open_label( 'dns' ) );
		$this->assertSame( 'Open your disk', ThankYou::open_label( 'disk' ) );
	}
}

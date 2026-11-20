<?php
/**
 * The order section of the Overview, the owner progress route and the page an add-on order returns to.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Overview;
use BeltoftServices\Account\OverviewOrder;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\Addons;
use BeltoftServices\OrderHandoff;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Nothing here calls a server: orders, resource meta and the fake driver only.
 */
final class OverviewOrderTest extends TestCase {

	/**
	 * The customer.
	 *
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		$this->fake_driver();
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_GET['order'] );
		wp_set_current_user( 0 );
		Overview::reset();

		parent::tear_down();
	}

	/**
	 * A paid order of the customer with services in the given states.
	 *
	 * @param string[] $states Resource states, one service each.
	 * @param int      $user   Customer (default the logged-in one).
	 */
	private function order( array $states, int $user = 0 ): \WC_Order {
		$user  = $user ?: $this->user;
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_status( 'processing' );
		$order->save();

		foreach ( $states as $state ) {
			$product  = $this->service_product( 'fake', array(), array( 'slug' => 'oo-' . wp_generate_password( 6, false ) ) );
			$license  = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $user ) );
			$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => $state ) );
			$resource->set( array( 'driver' => 'vm', 'desired' => 'failed' === $state ? 'ready' : 'ready' ) );
		}

		return wc_get_order( $order->get_id() );
	}

	private function section(): string {
		Overview::reset();

		return Overview::section( 'new_order' );
	}

	private function route( string $path, ?int $user = null ): \WP_REST_Response {
		if ( null !== $user ) {
			wp_set_current_user( $user );
		}

		return rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/beltoft-services/v1/' . $path ) );
	}

	// -- The owner route -----------------------------------------------------------------------

	public function test_the_owner_route_answers_the_customer_with_no_key_and_the_same_body_as_the_key_route(): void {
		$order = $this->order( array( 'ready', 'provisioning' ) );

		$mine = $this->route( 'my/orders/' . $order->get_id() . '/progress' );
		$this->assertSame( 200, $mine->get_status() );
		$this->assertSame( 'no-store', $mine->get_headers()['Cache-Control'] );

		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/orders/' . $order->get_id() . '/progress' );
		$request->set_query_params( array( 'key' => $order->get_order_key() ) );
		$keyed = rest_get_server()->dispatch( $request );

		$this->assertSame( $keyed->get_data(), $mine->get_data() );
		$this->assertCount( 2, $mine->get_data()['services'] );
	}

	public function test_the_owner_route_refuses_another_customer_and_a_visitor_and_never_leaks(): void {
		$order = $this->order( array( 'ready' ) );

		$this->assertSame( 403, $this->route( 'my/orders/' . $order->get_id() . '/progress', self::factory()->user->create() )->get_status() );
		$this->assertSame( 403, $this->route( 'my/orders/' . $order->get_id() . '/progress', 0 )->get_status() );
		$this->assertSame( 403, $this->route( 'my/orders/999999/progress', $this->user )->get_status() );

		$json = (string) wp_json_encode( $this->route( 'my/orders/' . $order->get_id() . '/progress', $this->user )->get_data() );
		$this->assertStringNotContainsString( 'last_error', $json );
		$this->assertStringNotContainsString( 'password', $json );
		$this->assertStringNotContainsString( $order->get_order_key(), $json );
	}

	public function test_the_owner_route_is_rate_limited(): void {
		$order = $this->order( array( 'ready' ) );
		add_filter( 'beltoft_services_order_progress_rate_limit', static fn() => 2 );

		$codes = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$codes[] = $this->route( 'my/orders/' . $order->get_id() . '/progress' )->get_status();
		}

		$this->assertContains( 429, $codes );
	}

	// -- Which orders show ---------------------------------------------------------------------

	public function test_the_address_names_the_order_and_the_section_has_a_line_per_service_and_no_order_key(): void {
		$order = $this->order( array( 'ready', 'provisioning' ) );
		$_GET['order'] = (string) $order->get_id();

		$html = $this->section();

		$this->assertSame( 2, substr_count( $html, 'data-service="' ) );
		$this->assertStringContainsString( 'bs-flow', $html );
		$this->assertStringContainsString( 'role="progressbar"', $html );
		$this->assertStringContainsString( 'See the order', $html );
		$this->assertStringContainsString( '/my/orders/' . $order->get_id() . '/progress', $html );
		$this->assertStringNotContainsString( $order->get_order_key(), $html );
		$this->assertStringNotContainsString( 'key=', $html );
	}

	public function test_another_customers_order_in_the_address_is_ignored(): void {
		$other = $this->order( array( 'provisioning' ), self::factory()->user->create() );
		$_GET['order'] = (string) $other->get_id();

		$this->assertSame( '', $this->section() );
	}

	public function test_an_order_still_running_shows_without_the_address_and_after_a_reload(): void {
		$this->order( array( 'provisioning' ) );

		$this->assertStringContainsString( 'data-service="', $this->section() );
		$this->assertStringContainsString( 'data-service="', $this->section() );
	}

	public function test_a_finished_order_shows_its_closing_line_once_then_leaves_with_a_user_meta_mark(): void {
		$order = $this->order( array( 'ready', 'ready' ) );

		$first = $this->section();
		$this->assertStringContainsString( 'Everything in your order is ready', $first );
		$this->assertStringNotContainsString( 'data-order-closing hidden', $first );
		$this->assertMatchesRegularExpression( '#bs-order__closing-list.*Open your server.*Open your server.*</ul>#s', $first, 'Each service has its open button under the line.' );
		$this->assertArrayHasKey( $order->get_id(), (array) get_user_meta( $this->user, OverviewOrder::SEEN, true ) );

		$this->assertSame( '', $this->section(), 'The first load after it was seen leaves it out.' );
	}

	public function test_some_ready_and_one_failed_says_so_with_the_failed_lines_link(): void {
		$this->order( array( 'ready', 'failed' ) );
		$html = $this->section();

		$this->assertStringContainsString( 'Some of your order is ready. One service needs attention.', $html );
		$this->assertSame( 'mixed', OverviewOrder::state( array(
			array( 'group' => 'ready', 'ready' => true, 'progress' => array( 'active' => false ) ),
			array( 'group' => 'failed', 'ready' => false, 'progress' => array( 'active' => false ) ),
		) ) );
	}

	public function test_the_state_rule(): void {
		$ready  = array( 'group' => 'ready', 'ready' => true, 'progress' => array( 'active' => false ) );
		$busy   = array( 'group' => 'ready', 'ready' => true, 'progress' => array( 'active' => true ) );
		$wait   = array( 'group' => 'getting-ready', 'ready' => false, 'progress' => array( 'active' => false ) );
		$failed = array( 'group' => 'failed', 'ready' => false, 'progress' => array( 'active' => false ) );

		$this->assertSame( 'running', OverviewOrder::state( array() ) );
		$this->assertSame( 'ready', OverviewOrder::state( array( $ready ) ) );
		$this->assertSame( 'running', OverviewOrder::state( array( $ready, $busy ) ), 'A pending domain step keeps the order running.' );
		$this->assertSame( 'running', OverviewOrder::state( array( $ready, $wait ) ) );
		$this->assertSame( 'failed', OverviewOrder::state( array( $failed ) ) );
	}

	public function test_the_route_marks_a_finished_order_seen_but_not_a_running_one(): void {
		$running = $this->order( array( 'provisioning' ) );
		$this->route( 'my/orders/' . $running->get_id() . '/progress' );
		$this->assertArrayNotHasKey( $running->get_id(), (array) get_user_meta( $this->user, OverviewOrder::SEEN, true ) );

		$done = $this->order( array( 'ready' ) );
		$this->route( 'my/orders/' . $done->get_id() . '/progress' );
		$this->assertArrayHasKey( $done->get_id(), (array) get_user_meta( $this->user, OverviewOrder::SEEN, true ) );
	}

	public function test_an_order_without_services_yet_shows_the_setting_up_line_for_the_address_order(): void {
		$order = wc_create_order( array( 'customer_id' => $this->user ) );
		$order->set_status( 'processing' );
		$order->save();
		$_GET['order'] = (string) $order->get_id();

		$html = $this->section();

		$this->assertStringContainsString( 'bs-order__waiting', $html );
		$this->assertStringContainsString( 'Setting up your order', $html );
		$this->assertStringNotContainsString( 'data-service="', $html );
	}

	public function test_the_attention_list_does_not_repeat_the_services_of_the_section(): void {
		$order = $this->order( array( 'provisioning' ) );
		$_GET['order'] = (string) $order->get_id();
		Overview::reset();

		$this->assertNotSame( '', Overview::section( 'new_order' ) );
		$this->assertSame( '', Overview::section( 'attention' ) );
	}

	public function test_the_section_is_the_first_part_of_the_whole_overview(): void {
		$this->order( array( 'provisioning' ) );
		Overview::reset();

		$all = Overview::all();
		$this->assertStringStartsWith( '<div class="beltoft-services-overview">', trim( $all ) );
		$this->assertLessThan( strpos( $all, 'beltoft-services-ov__attention' ) ?: PHP_INT_MAX, strpos( $all, 'bs-order' ) );
	}

	// -- Where an order from the dashboard returns ---------------------------------------------

	public function test_a_hosting_add_on_order_returns_to_the_page_of_its_account(): void {
		$this->settings( array( OrderHandoff::SETTING => 1 ) );
		$order = wc_create_order( array( 'customer_id' => $this->user ) );
		$order->set_status( 'processing' );
		$order->save();

		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'oo-' . wp_generate_password( 6, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
		$order->update_meta_data( Addons::ORDER_META, array( 'resource' => $resource->id(), 'sub' => 1, 'kind' => 'domain', 'target' => '' ) );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		$url = OrderHandoff::target( $order );

		$this->assertNotSame( '', $url );
		$this->assertStringNotContainsString( 'order=', $url );
		$this->assertTrue( OrderHandoff::applies( $order ) );
	}

	public function test_a_disk_for_a_server_returns_to_the_server_and_a_mix_does_not(): void {
		$server_product = $this->service_product( 'fake', array(), array( 'slug' => 'oo-' . wp_generate_password( 6, false ) ) );
		$server_license = $this->service_license( $server_product, array( 'fire' => false, 'user_id' => $this->user ) );
		Resource::create( $server_license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );

		$order = $this->order( array( 'ready' ) );
		$disk  = Resource::for_license( (int) \BeltoftLicenses\Licenses::where( 'order_id', $order->get_id() )[0]['id'] );
		$disk->set( array( 'server_license' => (int) $server_license['id'] ) );

		$target = \BeltoftServices\Account\OrderTarget::license_for( $order );
		$this->assertSame( (int) $server_license['id'], (int) $target['id'] );

		// Another customer's server is never the target.
		$other = $this->order( array( 'ready' ), self::factory()->user->create() );
		wp_set_current_user( $this->user );
		$foreign = Resource::for_license( (int) \BeltoftLicenses\Licenses::where( 'order_id', $other->get_id() )[0]['id'] );
		$foreign->set( array( 'server_license' => (int) $server_license['id'] ) );
		$this->assertNull( \BeltoftServices\Account\OrderTarget::license_for( $other ) );
	}
}

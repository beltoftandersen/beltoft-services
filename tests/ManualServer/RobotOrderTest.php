<?php
/**
 * The automatic order, delivery and cancellation through the Robot client (the in-process fake and a stubbed HTTP layer).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftLicenses\Licenses;
use BeltoftServices\Jobs;
use BeltoftServices\ManualServer\DedicatedClients;
use BeltoftServices\ManualServer\DedicatedSpendGuard;
use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\ManualServer\LiveRobotClient;
use BeltoftServices\ManualServer\ManualServerDelivery;
use BeltoftServices\ManualServer\RobotOrder;
use BeltoftServices\Resource;
use BeltoftServices\Usage\CustomerDelete;

require_once __DIR__ . '/ManualServerTestCase.php';

final class RobotOrderTest extends ManualServerTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->automatic();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		FakeRobotClient::reset();
		parent::tear_down();
	}

	private function waiting(): Resource {
		$v = $this->server_product();
		$r = $this->waiting_server( $v['u'], $this->new_user() );
		Jobs::run( $r->id() );

		return Resource::find( $r->id() );
	}

	/** Runs the poll again (the retry time is cleared). */
	private function again( Resource $r ): Resource {
		Resource::find( $r->id() )->set( array( 'next_attempt_at' => null ) );
		Jobs::run( $r->id() );

		return Resource::find( $r->id() );
	}

	public function test_the_order_is_placed_once_with_the_documented_fields(): void {
		$r = $this->waiting();

		$this->assertSame( 'provisioning', $r->get( 'state' ) );
		$this->assertNotSame( '', (string) $r->get( RobotOrder::TRANSACTION ) );
		$this->assertNull( $r->get( RobotOrder::ORDERING ), 'The marker is cleared once the answer is stored.' );

		$r = $this->again( $r );
		$r = $this->again( $r );

		$this->assertCount( 1, FakeRobotClient::orders(), 'However often it is looked at, one order.' );
		$order = FakeRobotClient::orders()[0];
		$this->assertSame( 'AX42-1', $order['product_id'] );
		$this->assertSame( 'FSN1', $order['location'] );
		$this->assertSame( array( 'primary_ipv4' ), $order['addon'] );
		$this->assertArrayNotHasKey( 'password', $order, 'The fake never keeps the password.' );
		$this->assertCount( 0, $this->mails_with( 'Dedicated server to set up' ), 'Staff are not asked to buy it.' );
	}

	public function test_the_server_is_delivered_when_the_transaction_is_ready_and_billing_starts(): void {
		$r = $this->waiting();
		FakeRobotClient::finish( (string) $r->get( RobotOrder::TRANSACTION ) );
		$r = $this->again( $r );

		$this->assertSame( 'ready', $r->get( 'state' ) );
		$this->assertGreaterThanOrEqual( 400000, (int) $r->get( RobotOrder::NUMBER ) );
		$this->assertSame( (string) $r->get( RobotOrder::NUMBER ), $r->get( ManualServerDelivery::PROVIDER_NO ) );
		$this->assertStringStartsWith( '192.0.2.', ManualServerDelivery::ips( $r )[0] );
		$this->assertStringStartsWith( '2001:db8:', (string) $r->get( RobotOrder::IPV6 ) );
		$this->assertNotNull( $r->get( \BeltoftServices\Usage\Meter::READY_AT ) );
		$this->assertNotEmpty( $this->mails_with( 'is ready' ) );
	}

	public function test_the_root_password_is_generated_encrypted_and_never_sent_to_anyone_else(): void {
		$r        = $this->waiting();
		$password = ManualServerDelivery::root_password( $r );

		$this->assertNotNull( $password );
		$this->assertSame( 24, strlen( $password ) );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( Licenses::activation( $r->id() ) ) );

		FakeRobotClient::finish( (string) $r->get( RobotOrder::TRANSACTION ) );
		$this->again( $r );

		foreach ( $this->staff_mails as $mail ) {
			$this->assertStringNotContainsString( $password, $mail['subject'] . $mail['body'] );
		}
	}

	public function test_a_cancelled_transaction_fails_and_staff_can_still_deliver_by_hand(): void {
		$r = $this->waiting();
		FakeRobotClient::cancel_transaction( (string) $r->get( RobotOrder::TRANSACTION ) );
		$r = $this->again( $r );

		$this->assertSame( 'failed', $r->get( 'state' ) );
		$this->assertNotEmpty( $this->mails_with( 'failed' ), 'Staff are told.' );

		$this->assertTrue( ManualServerDelivery::save( $r, '203.0.113.9', 'By hand.', 'S-9', 'tester', true ) );
		Jobs::run( $r->id() );
		$fresh = Resource::find( $r->id() );

		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'tester', $fresh->get( 'manual_ready_by' ) );
	}

	public function test_a_lost_answer_is_never_ordered_again(): void {
		$r = $this->waiting();
		// The marker without a transaction: the call went out, the answer was not stored.
		$r->set( array( RobotOrder::TRANSACTION => '', RobotOrder::ORDERING => time(), 'state' => 'provisioning', 'next_attempt_at' => null ) );
		FakeRobotClient::reset();
		$r = $this->again( $r );

		$this->assertSame( 'failed', $r->get( 'state' ) );
		$this->assertCount( 0, FakeRobotClient::orders(), 'Nothing was sent again.' );
		$this->assertStringContainsString( 'not sent again', (string) $r->get( 'last_error' ) );
	}

	public function test_a_refused_order_clears_the_marker_and_an_unknown_outcome_keeps_it(): void {
		// 412 through the real HTTP client stub.
		$this->use_http( 412, array( 'error' => array( 'status' => 412, 'code' => 'PRECONDITION_FAILED', 'message' => 'Order cannot be accepted.' ) ) );
		$r = $this->waiting();
		$this->assertSame( 'failed', $r->get( 'state' ) );
		$this->assertNull( $r->get( RobotOrder::ORDERING ), 'A documented refusal placed nothing.' );
		$this->assertSame( '', (string) $r->get( RobotOrder::TRANSACTION ) );

		// 500: the outcome is not known.
		$this->use_http( 500, array( 'error' => array( 'status' => 500, 'code' => 'INTERNAL_ERROR', 'message' => 'The transaction failed due to an internal error' ) ) );
		$r2 = $this->waiting();
		$this->assertSame( 'failed', $r2->get( 'state' ) );
		$this->assertGreaterThan( 0, (int) $r2->get( RobotOrder::ORDERING ), 'The marker stays: never ordered again.' );
		$this->assertStringContainsString( 'not known', (string) $r2->get( 'last_error' ) );
	}

	public function test_the_customer_cancel_cancels_at_the_supplier_at_once_and_billing_stops(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );
		Jobs::run( $r->id() );
		FakeRobotClient::finish( (string) Resource::find( $r->id() )->get( RobotOrder::TRANSACTION ) );
		$r = $this->again( $r );
		$this->assertSame( 'ready', $r->get( 'state' ) );
		$number = (int) $r->get( RobotOrder::NUMBER );

		$this->assertTrue( CustomerDelete::run( $r, $user, 'DELETE' ) );
		Jobs::run( $r->id() );

		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertSame( array( $number ), FakeRobotClient::cancelled() );
		$this->assertNotNull( Licenses::activation( $r->id() )['deactivated_at'] );
		$this->assertCount( 1, $this->mails_with( 'cancelled at the supplier' ), 'An audit mail, not a task.' );
		$this->assertCount( 0, $this->mails_with( 'Cancel a dedicated server' ) );
	}

	public function test_a_server_delivered_by_hand_is_cancelled_by_staff_not_through_the_api(): void {
		$r = $this->waiting();
		$this->assertTrue( ManualServerDelivery::save( $r, '203.0.113.9', '', 'S-9', 'tester', true ) );
		Jobs::run( $r->id() );
		$r = Resource::find( $r->id() );
		$r->desire( 'deleted', 'admin' );
		Jobs::run( $r->id() );

		$this->assertSame( array(), FakeRobotClient::cancelled() );
		$this->assertCount( 1, $this->mails_with( 'Cancel a dedicated server' ) );
	}

	public function test_the_live_client_sends_the_documented_requests(): void {
		$seen = array();
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen[] = array( 'url' => $url, 'method' => $args['method'], 'body' => (string) ( $args['body'] ?? '' ), 'auth' => (string) ( $args['headers']['Authorization'] ?? '' ), 'type' => (string) ( $args['headers']['Content-Type'] ?? '' ) );

				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'transaction' => array( 'id' => 'B1', 'status' => 'in process' ), 'server' => array( 'server_ip' => '192.0.2.1' ), 'cancellation' => array( 'cancelled' => true ) ) ), 'headers' => array() );
			},
			1,
			3
		);
		$client = new LiveRobotClient( 'https://robot-ws.example.test/', 'user-x', 'pass-y' );

		$client->order( array( 'product_id' => 'AX42-1', 'location' => 'FSN1', 'dist' => 'Debian 12 minimal', 'password' => 'p w&d', 'addon' => array( 'primary_ipv4' ), 'test' => true ) );
		$client->transaction( 'B1' );
		$client->server( 321 );
		$client->cancel( 321 );

		$this->assertSame( 'https://robot-ws.example.test/order/server/transaction', $seen[0]['url'] );
		$this->assertSame( 'POST', $seen[0]['method'] );
		$this->assertSame( 'product_id=AX42-1&location=FSN1&dist=Debian%2012%20minimal&password=p%20w%26d&addon%5B%5D=primary_ipv4&test=true', $seen[0]['body'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $seen[0]['type'] );
		$this->assertSame( 'Basic ' . base64_encode( 'user-x:pass-y' ), $seen[0]['auth'] );
		$this->assertSame( array( 'GET', 'https://robot-ws.example.test/order/server/transaction/B1' ), array( $seen[1]['method'], $seen[1]['url'] ) );
		$this->assertSame( array( 'GET', 'https://robot-ws.example.test/server/321' ), array( $seen[2]['method'], $seen[2]['url'] ) );
		$this->assertSame( array( 'POST', 'https://robot-ws.example.test/server/321/cancellation', 'cancellation_date=now&reserve_location=false' ), array( $seen[3]['method'], $seen[3]['url'], $seen[3]['body'] ) );

		$this->expectException( \InvalidArgumentException::class );
		new LiveRobotClient( 'http://robot-ws.example.test', 'u', 'p' );
	}

	public function test_errors_carry_the_documented_code_and_status_and_never_the_request(): void {
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn() => array( 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'body' => '{"error":{"status":403,"code":"RATE_LIMIT_EXCEEDED","max_request":20,"interval":86400,"message":"rate limit exceeded"}}', 'headers' => array() ), 1 );
		$client = new LiveRobotClient( 'https://robot-ws.example.test', 'u', 'secret-pass' );
		$error  = $client->order( array( 'product_id' => 'X', 'password' => 'topsecret' ) );

		$this->assertWPError( $error, wp_json_encode( $error->get_error_data() ) . $error->get_error_message() );
		$this->assertSame( 'RATE_LIMIT_EXCEEDED', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'topsecret', $error->get_error_message() );
	}

	public function test_the_live_gate(): void {
		$this->settings( array( 'dedicated_client' => 'live', 'dedicated_api_url' => 'https://robot-ws.your-server.de' ) );
		$this->assertSame( 'unavailable', DedicatedClients::kind( false ), 'The live setting without the constant is never a client.' );

		$this->settings( array( 'dedicated_client' => 'test_server', 'dedicated_api_url' => 'https://robot-ws.your-server.de', 'dedicated_api_user' => \BeltoftServices\Crypto::encrypt( 'u' ), 'dedicated_api_password' => \BeltoftServices\Crypto::encrypt( 'p' ) ) );
		$this->assertSame( 'unavailable', DedicatedClients::kind( true ), 'The test server mode refuses the real host.' );

		$this->settings( array( 'dedicated_api_url' => 'http://127.0.0.1:18081' ) );
		$this->assertSame( 'test_server', DedicatedClients::kind( false ) );
		$this->assertFalse( DedicatedClients::is_live() );

		$this->settings( array( 'dedicated_client' => 'live', 'dedicated_api_url' => 'http://127.0.0.1:18081' ) );
		$this->assertSame( 'unavailable', DedicatedClients::kind( true ), 'Live talks only to the real host.' );

		$this->settings( array( 'dedicated_client' => 'fake' ) );
		$this->assertInstanceOf( FakeRobotClient::class, DedicatedClients::build( true ) );
		$this->assertFalse( DedicatedClients::live_allowed(), 'The constant is off in the tests.' );
	}

	public function test_the_spend_guard_refuses_without_costs_caps_or_a_covering_payment(): void {
		$r = $this->waiting();

		// Costs and the plan are there; the paid line is missing in this fixture, so a live order is refused.
		$this->assertStringContainsString( 'paid order line', (string) DedicatedSpendGuard::problem( $r ) );

		// Every cap is 0 until the owner sets it.
		$this->assertStringContainsString( 'cap on dedicated servers', (string) DedicatedSpendGuard::budget_problem( $r ) );
		$this->settings( array( 'dedicated_max_servers' => 5 ) );
		$this->assertStringContainsString( 'monthly cost', (string) DedicatedSpendGuard::budget_problem( $r ) );
		$this->settings( array( 'dedicated_max_monthly_cost' => 98 ) );
		$this->assertStringContainsString( 'monthly cost', (string) DedicatedSpendGuard::budget_problem( $r ), 'A 99 EUR server passes a 98 cap.' );
		$this->settings( array( 'dedicated_max_monthly_cost' => 100 ) );
		$this->assertStringContainsString( 'per day', (string) DedicatedSpendGuard::budget_problem( $r ) );
		$this->settings( array( 'dedicated_max_new_per_day' => 1 ) );
		$this->assertNull( DedicatedSpendGuard::budget_problem( $r ) );
		DedicatedSpendGuard::count_created();
		$this->assertStringContainsString( 'per day', (string) DedicatedSpendGuard::budget_problem( $r ), 'One order a day is used up.' );
	}

	public function test_the_spend_guard_compares_the_paid_setup_fee_with_the_cost(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );
		$cases = array( array( 40.0, 'below' ), array( 49.0, '' ), array( 54.0, '' ) );

		foreach ( $cases as $case ) {
			$order = wc_create_order( array( 'customer_id' => $user ) );
			$order->set_currency( 'EUR' );
			$line = new \WC_Order_Item_Product();
			$line->set_props( array( 'name' => 'Server', 'quantity' => 1, 'subtotal' => $case[0], 'total' => $case[0] ) );
			$order->add_item( $line );
			$order->save();
			Licenses::update( (int) $r->license()['id'], array( 'order_id' => $order->get_id() ) );
			$r->set( array( 'order_item_id' => (int) $line->get_id() ) );
			$fresh = Resource::find( $r->id() );

			$problem = (string) DedicatedSpendGuard::problem( $fresh );
			'' === $case[1] ? $this->assertSame( '', $problem, (string) $case[0] ) : $this->assertStringContainsString( $case[1], $problem );
		}
	}

	/**
	 * Every Robot call answers one stubbed HTTP reply, with the test server mode and the fake staging login.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $body   Body.
	 */
	private function use_http( int $status, array $body ): void {
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn() => array( 'response' => array( 'code' => $status, 'message' => '' ), 'body' => wp_json_encode( $body ), 'headers' => array() ), 1 );
		$this->settings( array( 'dedicated_client' => 'test_server', 'dedicated_api_url' => 'http://127.0.0.1:18081', 'dedicated_api_user' => \BeltoftServices\Crypto::encrypt( 'u' ), 'dedicated_api_password' => \BeltoftServices\Crypto::encrypt( 'p' ) ) );
	}
}

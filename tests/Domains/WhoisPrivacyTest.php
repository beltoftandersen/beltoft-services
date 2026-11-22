<?php
/** Free WHOIS privacy: checkout default, create and transfer bodies, the dashboard switch, REST and MCP. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Actions;
use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\WhoisPrivacy;
use BeltoftServices\Jobs;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class WhoisPrivacyTest extends TestCase {
	private function table(): void {
		$d = array( 'allowed' => true, 'keys' => true, 'digests' => false, 'algs' => array( 13 ), 'max' => 2 );
		update_option( Prices::OPTION, array(
			'com' => array( 'price' => 14, 'restricted' => false, 'create' => 9.0, 'renew' => 9.0, 'transfer' => 9.0, 'dnssec' => $d, 'whois' => true ),
			'org' => array( 'price' => 13, 'restricted' => false, 'create' => 9.0, 'renew' => 9.0, 'transfer' => 9.0, 'dnssec' => $d, 'whois' => false ),
			'dk'  => array( 'price' => 13, 'restricted' => false, 'create' => 9.0, 'renew' => 9.0, 'transfer' => 9.0, 'dnssec' => $d, 'whois' => false ),
		) );
	}

	private function domain( string $name, array $meta = array() ): Resource {
		$license = $this->service_license( $this->service_product( 'domain' ), array( 'fire' => false ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'state' => 'ready', 'driver' => 'domain', 'domain' => $name, 'remote_id' => '123' ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	public function test_the_line_carries_the_choice_only_for_an_extension_that_allows_it_and_defaults_on(): void {
		$this->table();
		$on = Cart::line_data( 'privacy-example.com', 'register' );
		$this->assertTrue( $on['beltoft_services_domain']['whois_privacy'] );
		$off = Cart::line_data( 'privacy-example.com', 'register', '', '', 0, false );
		$this->assertFalse( $off['beltoft_services_domain']['whois_privacy'] );
		$org = Cart::line_data( 'privacy-example.org', 'register' );
		$this->assertArrayNotHasKey( 'whois_privacy', $org['beltoft_services_domain'], 'An extension without WHOIS privacy carries no choice.' );
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 't', 32 ) ) );
		$tr = Cart::line_data( 'privacy-example.com', 'transfer', 'ABCDEF123' );
		$this->assertTrue( $tr['beltoft_services_domain']['whois_privacy'] );
	}

	private function order_body( string $name, string $mode, array $meta ): array {
		$this->table();
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 't', 32 ) ) );
		$op = new FakeClient();
		$op->reply( 'GET', '/domains', array( 'results' => array() ) );
		$op->reply( 'POST', 'transfer' === $mode ? '/domains/transfer' : '/domains', array( 'id' => 123, 'status' => 'REQ' ) );
		$op->reply( 'GET', '/domains/123', array( 'id' => 123, 'status' => 'REQ' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		add_filter( 'beltoft_services_domains_dns', static fn() => new FakeDns() );
		add_filter( 'beltoft_services_dns_proof_result', static fn() => array( 'status' => 'ok', 'reason' => 'x', 'ds' => false, 'ours' => false, 'ns' => array( 'ns1.old.example', 'ns2.old.example' ), 'ips' => array() ) );
		add_filter( 'beltoft_services_dns_query', static fn( $value, $server, $domain, $type ) => 6 === $type ? 1 : ( 48 === $type ? 1 : time() + 30 * DAY_IN_SECONDS ), 10, 4 );
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Domain', 'quantity' => 1, 'subtotal' => 50.0, 'total' => 50.0 ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$base = array( 'domain' => $name, 'mode' => $mode, 'order_item_id' => (int) $line->get_id() );
		if ( 'transfer' === $mode ) { $base['auth'] = \BeltoftServices\Crypto::encrypt( 'transfer-secret' ); }
		$r = Resource::create( $license, new DomainDriver(), array(), $base + $meta );
		$this->assertInstanceOf( Resource::class, $r );
		( new DomainDriver() )->provision( $r );
		foreach ( $op->calls() as $call ) { if ( 'POST' === $call['method'] ) { return $call['body']; } }
		return array( 'no_post' => true );
	}

	public function test_registration_and_transfer_send_is_private_whois_enabled_on_by_default(): void {
		$register = $this->order_body( 'priv-reg.com', 'register', array() );
		$this->assertFalse( $register['is_dnssec_enabled'] ?? null );
		$this->assertSame( true, $register['is_private_whois_enabled'] ?? null );
		$transfer = $this->order_body( 'priv-tr.com', 'transfer', array() );
		$this->assertSame( true, $transfer['is_private_whois_enabled'] ?? null );
	}

	public function test_a_no_at_checkout_is_sent_as_false_and_an_extension_without_it_sends_no_field(): void {
		$this->assertSame( false, $this->order_body( 'priv-off.com', 'register', array( 'whois_privacy' => false ) )['is_private_whois_enabled'] ?? null );
		$this->assertArrayNotHasKey( 'is_private_whois_enabled', $this->order_body( 'priv-none.org', 'register', array() ) );
	}

	public function test_the_choice_is_kept_on_the_service_from_the_order_line(): void {
		$this->table();
		$this->assertFalse( WhoisPrivacy::wanted( $this->domain( 'a-example.com', array( 'whois_privacy' => false ) ) ) );
		$this->assertTrue( WhoisPrivacy::wanted( $this->domain( 'b-example.com' ) ) );
	}

	public function test_the_registrars_answer_is_stored_when_the_domain_is_active_and_at_the_daily_sync(): void {
		$meta = WhoisPrivacy::detail_meta( array( 'is_private_whois_allowed' => true, 'is_private_whois_enabled' => true ) );
		$this->assertSame( array( 'whois_privacy_allowed' => true, 'whois_privacy_enabled' => true ), $meta );
		$this->assertSame( array(), WhoisPrivacy::detail_meta( array( 'status' => 'ACT' ) ), 'No answer, no change.' );
		$this->assertSame( array( 'whois_privacy_allowed' => false, 'whois_privacy_enabled' => false ), WhoisPrivacy::detail_meta( array( 'is_private_whois_allowed' => false ) ) );
	}

	// -- The dashboard switch --------------------------------------------------------------------------

	public function test_the_switch_is_offered_only_when_the_domain_allows_it(): void {
		$this->table();
		$this->assertArrayNotHasKey( 'whois_privacy', Actions::offered( $this->domain( 'x-example.dk', array( 'whois_privacy_allowed' => false ) ) ) );
		$this->assertArrayNotHasKey( 'whois_privacy', Actions::offered( $this->domain( 'y-example.com' ) ), 'Not known yet: not offered.' );
		$ok = $this->domain( 'z-example.com', array( 'whois_privacy_allowed' => true, 'whois_privacy_enabled' => true ) );
		$this->assertArrayHasKey( 'whois_privacy', Actions::offered( $ok ) );
		$this->assertInstanceOf( \WP_Error::class, Actions::refusal( null, $this->domain( 'x2-example.dk' ), 'whois_privacy', array( 'enabled' => '1' ) ) );
		$this->assertInstanceOf( \WP_Error::class, Actions::refusal( null, $ok, 'whois_privacy', array( 'enabled' => 'maybe' ) ) );
		$this->assertNull( Actions::refusal( null, $ok, 'whois_privacy', array( 'enabled' => '0' ) ) );
	}

	public function test_the_job_sends_the_update_field_and_stores_what_the_registrar_holds(): void {
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$op->reply( 'GET', '/domains/123', array( 'is_private_whois_allowed' => true, 'is_private_whois_enabled' => false ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$r = $this->domain( 'sw-example.com', array( 'whois_privacy_allowed' => true, 'whois_privacy_enabled' => true ) );
		$result = Actions::run( $r, 'whois_privacy', array( 'enabled' => '0' ) );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( array( 'is_private_whois_enabled' => false ), $op->calls()[0]['body'] );
		$this->assertFalse( $result->meta()['whois_privacy_enabled'] );
	}

	public function test_a_registrar_that_did_not_apply_the_change_fails_the_action_and_a_failed_call_retries(): void {
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		$op->reply( 'GET', '/domains/123', array( 'is_private_whois_allowed' => true, 'is_private_whois_enabled' => true ) );
		$op->reply( 'PUT', '/domains/123', new \WP_Error( 'http_500', 'down' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$r = $this->domain( 'sw2-example.com', array( 'whois_privacy_allowed' => true, 'whois_privacy_enabled' => true ) );
		$this->assertTrue( Actions::run( $r, 'whois_privacy', array( 'enabled' => '0' ) )->is_failed() );
		$this->assertTrue( Actions::run( $r, 'whois_privacy', array( 'enabled' => '0' ) )->is_retry() );
	}

	public function test_the_switch_needs_no_emailed_code_over_rest(): void {
		$op = new FakeClient();
		$op->reply( 'PUT', '/domains/123', array( 'success' => true ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		$r = $this->domain( 'rest-example.com', array( 'whois_privacy_allowed' => true, 'whois_privacy_enabled' => true ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/whois_privacy' );
		$request->set_param( 'args', array( 'enabled' => '0' ) );
		$this->assertSame( 202, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertTrue( Jobs::acting( $r->id() ) );
	}

	// -- The map of the connected app ------------------------------------------------------------------

	public function test_the_connected_app_gets_whois_privacy_and_never_dnssec(): void {
		$this->assertSame( 'domain_settings', ActionMap::actions()['whois_privacy'] );
		foreach ( array( 'dnssec_enable', 'dnssec_disable', 'dnssec_key_add', 'dnssec_key_remove' ) as $action ) {
			$this->assertStringStartsWith( 'never:', ActionMap::excluded()[ $action ] );
			$this->assertArrayNotHasKey( $action, ActionMap::actions() );
			$this->assertFalse( ActionMap::permits( 'domain_settings', 'POST', '/resources/1/actions/' . $action ) );
		}
		$this->assertTrue( ActionMap::permits( 'domain_settings', 'POST', '/resources/1/actions/whois_privacy' ) );
	}
}

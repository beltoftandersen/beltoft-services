<?php
/** Transfers that wait for something the customer fixes, and the fresh lock check. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Crypto;
use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\TransferDns;
use BeltoftServices\Domains\TransferFix;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class TransferFixTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	protected function setUp(): void {
		parent::setUp();
		add_filter( 'beltoft_services_transfer_registrar', '__return_false' );
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 't', 32 ) ) );
		add_filter( 'pre_wp_mail', function ( $pre, $atts ) { $this->mails[] = $atts; return true; }, 10, 2 );
		$this->mails = array();
		$this->delegation();
		add_filter( 'beltoft_services_domains_dns', static fn() => new FakeDns() );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function prices(): void {
		$table = get_option( Prices::OPTION, array() );
		$table = is_array( $table ) ? $table : array();
		foreach ( array( 'com', 'dk', 'nl' ) as $tld ) {
			$table[ $tld ] = ( $table[ $tld ] ?? array() ) + array( 'create' => 10.0, 'renew' => 10.0, 'transfer' => 9.0, 'restricted' => false, 'price' => 16 );
		}
		update_option( Prices::OPTION, $table );
	}

	private function resource( string $domain ): Resource {
		$this->prices();
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
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $domain, 'mode' => 'transfer', 'order_item_id' => (int) $line->get_id(), 'auth' => Crypto::encrypt( 'transfer-secret' ) ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	/** The parent delegation a fake resolver reports. */
	private function delegation(): void {
		add_filter( 'beltoft_services_dns_proof_result', static function ( $pre, $call ) {
			return 'delegation' !== $call ? $pre : array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.old-host.example', 'ns2.old-host.example' ), 'ips' => array(), 'ds' => false, 'ours' => false );
		}, 10, 3 );
	}

	/** A fake registrar: the answers of POST /domains/transfer in order, then the detail of the order. */
	private function registrar( array $posts, array $detail = array() ): FakeClient {
		$fake = new FakeClient();
		foreach ( $posts as $post ) {
			$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
			$fake->reply( 'POST', '/domains/transfer', $post );
		}
		foreach ( $detail as $d ) { $fake->reply( 'GET', '/domains/123', $d ); }
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		return $fake;
	}

	private function refused( string $desc ): \WP_Error {
		return new \WP_Error( 'op_1000', 'Registrar request failed.', array( 'status' => 400, 'desc' => $desc ) );
	}

	private function transfers( FakeClient $fake ): int {
		return count( array_filter( $fake->calls(), static fn( $c ) => 'POST' === $c['method'] && '/domains/transfer' === $c['path'] ) );
	}

	private function text(): string {
		return (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $this->mails[0]['message'] ?? '' ) ) );
	}

	private function name(): string {
		return 'fix-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
	}

	public function test_a_locked_domain_waits_and_the_customer_is_told_once(): void {
		$locked = 'Domain is locked: clientTransferProhibited';
		$fake   = $this->registrar( array( $this->refused( $locked ), $this->refused( $locked ), array( 'id' => 123, 'status' => 'REQ' ) ) );
		$r      = $this->resource( $this->name() );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry(), 'Not failed.' );
		$this->assertSame( TransferFix::EVERY, $result->delay() );
		Resource::find( $r->id() )->set( $result->meta() );
		$saved = Resource::find( $r->id() );
		$this->assertSame( 'locked', $saved->get( 'transfer_fix' ) );
		$this->assertNotSame( '', (string) $saved->get( 'auth' ), 'The code is still good and is kept.' );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'cannot start because the domain is locked at your current registrar', $this->text() );
		$this->assertStringContainsString( 'click Check now', $this->text() );
		$this->assertStringNotContainsStringIgnoringCase( 'Openprovider', $this->text() );
		( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertCount( 1, $this->mails, 'The same failure is not mailed again on a retry.' );
		$this->assertSame( 2, $this->transfers( $fake ) );
		( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertSame( 3, $this->transfers( $fake ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'transfer_fix' ), 'Unlocked: the wait is over.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'transfer_fix_sent' ) );
	}

	public function test_a_refused_code_drops_the_code_sends_one_email_and_waits_for_a_new_one(): void {
		$fake   = $this->registrar( array( $this->refused( 'Invalid authorization code' ) ) );
		$r      = $this->resource( $this->name() );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry() );
		Resource::find( $r->id() )->set( $result->meta() );
		$saved = Resource::find( $r->id() );
		$this->assertSame( 'auth_refused', $saved->get( 'transfer_fix' ) );
		$this->assertNull( $saved->get( 'auth' ), 'The refused code is not kept.' );
		$this->assertCount( 1, $this->mails );
		$text = $this->text();
		$this->assertStringContainsString( 'was not accepted', $text );
		$this->assertStringContainsString( 'Ask your current registrar for a new transfer code', $text );
		$this->assertStringContainsString( 'enter the new code', $text );
		$this->assertStringNotContainsString( 'transfer-secret', $text );
		$again = ( new DomainDriver() )->provision( $saved );
		$this->assertTrue( $again->is_retry(), 'Without a code it waits and does not fail.' );
		$this->assertSame( 1, $this->transfers( $fake ), 'Nothing is sent without a code.' );
		$this->assertCount( 1, $this->mails );
	}

	public function test_another_registrar_error_still_fails_for_staff(): void {
		$this->registrar( array( $this->refused( 'The name is reserved' ) ) );
		$r = $this->resource( $this->name() );
		$this->assertTrue( ( new DomainDriver() )->provision( $r )->is_failed() );
		$this->assertSame( 0, $this->fix_mails() );
	}

	private function fix_mails(): int {
		return count( array_filter( $this->mails, static fn( $m ) => str_contains( (string) $m['subject'], 'needs your action' ) ) );
	}

	public function test_a_transfer_the_registry_failed_over_the_code_is_sent_again_with_a_new_code(): void {
		$fake  = $this->registrar( array( array( 'id' => 123, 'status' => 'REQ' ), array( 'id' => 123, 'status' => 'REQ' ) ), array( array( 'id' => 123, 'status' => 'FAI', 'reason' => 'Authorization code is not correct' ) ) );
		$r     = $this->resource( $this->name() );
		$first = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $first->is_retry() );
		$saved = Resource::find( $r->id() );
		$saved->set( $first->meta() );
		$saved = Resource::find( $r->id() );
		$this->assertSame( 'auth_refused', $saved->get( 'transfer_fix' ) );
		$this->assertNotContains( 'order', (array) $saved->get( 'steps' ) );
		$this->assertEmpty( $saved->get( 'remote_id' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertTrue( $saved->set( array( 'auth' => Crypto::encrypt( 'new-code-123' ), 'transfer_fix' => null, 'transfer_fix_sent' => null, 'transfer_fix_since' => null ) ) );
		( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertSame( 2, $this->transfers( $fake ), 'The order is sent again.' );
	}

	public function test_a_pending_transfer_is_not_a_fixable_failure(): void {
		$this->registrar( array( array( 'id' => 123, 'status' => 'REQ' ) ), array( array( 'id' => 123, 'status' => 'REQ', 'reason' => 'locked' ) ) );
		$r      = $this->resource( $this->name() );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertNull( Resource::find( $r->id() )->get( 'transfer_fix' ) );
		$this->assertSame( 0, $this->fix_mails() );
	}

	public function test_the_wait_ends_after_14_days_and_the_code_is_gone(): void {
		$this->registrar( array( $this->refused( 'Domain is locked' ) ) );
		$r = $this->resource( $this->name() );
		$this->assertTrue( $r->set( array( 'transfer_fix' => 'locked', 'transfer_fix_since' => time() - 15 * DAY_IN_SECONDS ) ) );
		$this->assertTrue( ( new DomainDriver() )->provision( Resource::find( $r->id() ) )->is_failed() );
		$this->assertNull( Resource::find( $r->id() )->get( 'auth' ) );
	}

	public function test_the_new_code_route_saves_it_encrypted_and_clears_the_wait(): void {
		$r = $this->resource( $this->name() );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->assertSame( 409, $this->call( $r, array( 'auth_code' => 'new-code-123' ) )->get_status(), 'Nothing asked for a code.' );
		$this->assertTrue( $r->set( array( 'auth' => null, 'transfer_fix' => 'auth_refused', 'transfer_fix_sent' => 'auth_refused', 'transfer_fix_since' => time() ) ) );
		$this->assertSame( 400, $this->call( $r, array( 'auth_code' => 'abc' ) )->get_status() );
		$ok = $this->call( $r, array( 'args' => array( 'auth_code' => ' new-code-123 ' ) ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertStringNotContainsString( 'new-code-123', (string) wp_json_encode( $ok->get_data() ) );
		$saved = Resource::find( $r->id() );
		$this->assertSame( 'new-code-123', Crypto::decrypt( (string) $saved->get( 'auth' ) ) );
		$this->assertNull( $saved->get( 'transfer_fix' ) );
		$this->assertNull( $saved->get( 'transfer_fix_sent' ), 'A new failure is mailed again.' );
	}

	public function test_the_new_code_route_refuses_someone_else(): void {
		$r = $this->resource( $this->name() );
		$this->assertTrue( $r->set( array( 'auth' => null, 'transfer_fix' => 'auth_refused' ) ) );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->call( $r, array( 'auth_code' => 'new-code-123' ) )->get_status() );
		$this->assertNull( Resource::find( $r->id() )->get( 'auth' ) );
	}

	public function test_check_now_answers_for_a_locked_domain_that_is_still_locked(): void {
		$locked = 'Domain is locked';
		$this->registrar( array( $this->refused( $locked ), $this->refused( $locked ), $this->refused( $locked ) ) );
		$r = $this->resource( $this->name() );
		$r->set( array( 'state' => 'provisioning', 'desired' => 'ready' ) );
		$first = ( new DomainDriver() )->provision( Resource::find( $r->id() ) );
		Resource::find( $r->id() )->set( $first->meta() );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/transfer-registry-check' );
		$reply   = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $reply->get_status() );
		$this->assertSame( 'waiting', $reply->get_data()['status'] );
		$this->assertStringContainsString( 'The domain is locked at your current registrar.', $reply->get_data()['message'] );
		$this->assertCount( 1, $this->mails, 'Check now does not mail again.' );
	}

	public function test_the_page_shows_the_code_form_or_check_now(): void {
		$r = $this->resource( $this->name() );
		$r->set( array( 'state' => 'provisioning', 'desired' => 'ready', 'transfer_fix' => 'auth_refused' ) );
		$html = $this->render( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'The transfer code was not accepted.', $html );
		$this->assertStringContainsString( 'data-transfer-code', $html );
		$r->set( array( 'transfer_fix' => 'locked' ) );
		$html = $this->render( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'The domain is locked at your current registrar.', $html );
		$this->assertStringContainsString( 'data-transfer-registry', $html );
		$this->assertStringNotContainsString( 'data-transfer-code', $html );
		$r->set( array( 'transfer_fix' => null ) );
		$this->assertStringNotContainsString( 'data-transfer-fix', $this->render( Resource::find( $r->id() ) ) );
	}

	private function render( Resource $r ): string {
		$dns_only         = false;
		$zone_unavailable = false;
		$rows             = array();
		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/domain.php';
		return (string) ob_get_clean();
	}

	private function call( Resource $r, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/transfer-code' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function rdap( bool $locked ): array {
		$data = array( 'status' => $locked ? array( 'client transfer prohibited' ) : array( 'active' ), 'entities' => array( array( 'roles' => array( 'registrar' ), 'vcardArray' => array( 'vcard', array( array( 'fn', array(), 'text', 'Acme Registrar' ) ) ) ) ) );
		return array( 'headers' => array(), 'body' => (string) wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}

	public function test_adding_a_transfer_asks_the_lock_again_and_does_not_trust_the_cache(): void {
		remove_filter( 'beltoft_services_transfer_registrar', '__return_false' );
		$name = $this->name();
		set_transient( 'beltoft_services_rdap_' . md5( $name ), array( 'name' => 'Acme Registrar', 'url' => '' ), DAY_IN_SECONDS );
		$this->assertArrayNotHasKey( 'locked', (array) TransferDns::cached_registrar( $name ) );
		$this->respond_with( fn() => $this->rdap( true ) );
		$added = Cart::add_line( $name, 'transfer', 'AUTH-123456' );
		$this->assertWPError( $added );
		$this->assertSame( 'domain_locked', $added->get_error_code() );
		$calls = array_values( array_filter( $this->http_calls(), static fn( $c ) => str_contains( $c['url'], 'rdap.org' ) ) );
		$this->assertCount( 1, $calls );
		$this->assertLessThanOrEqual( 4, $calls[0]['args']['timeout'], 'A short timeout.' );
		$this->assertTrue( (bool) TransferDns::cached_registrar( $name )['locked'], 'The answer is stored for the page.' );
	}

	public function test_the_cached_answer_is_used_when_the_fresh_lookup_fails(): void {
		remove_filter( 'beltoft_services_transfer_registrar', '__return_false' );
		$name = $this->name();
		set_transient( 'beltoft_services_rdap_' . md5( $name ), array( 'name' => 'Acme Registrar', 'url' => '', 'locked' => true ), DAY_IN_SECONDS );
		$this->respond_with( static fn() => new \WP_Error( 'http_request_failed', 'timeout' ) );
		$this->assertTrue( (bool) TransferDns::fresh_registrar( $name )['locked'] );
		$this->respond_with( static fn() => array( 'headers' => array(), 'body' => 'busy', 'response' => array( 'code' => 503, 'message' => 'x' ), 'cookies' => array(), 'filename' => null ) );
		$this->assertTrue( (bool) TransferDns::fresh_registrar( $name )['locked'], 'An error answer is no answer.' );
		$added = Cart::add_line( $name, 'transfer', 'AUTH-123456' );
		$this->assertSame( 'domain_locked', $added->get_error_code() );
	}

	public function test_an_unlocked_domain_is_let_through_even_when_the_cache_says_locked(): void {
		remove_filter( 'beltoft_services_transfer_registrar', '__return_false' );
		$name = $this->name();
		set_transient( 'beltoft_services_rdap_' . md5( $name ), array( 'name' => 'Acme Registrar', 'url' => '', 'locked' => true ), DAY_IN_SECONDS );
		$this->respond_with( fn() => $this->rdap( false ) );
		$this->assertArrayNotHasKey( 'locked', (array) TransferDns::fresh_registrar( $name ) );
		$this->assertArrayNotHasKey( 'locked', (array) TransferDns::cached_registrar( $name ), 'The stored answer was renewed.' );
	}

	public function test_checkout_validation_stops_a_transfer_that_got_locked_after_it_was_added(): void {
		remove_filter( 'beltoft_services_transfer_registrar', '__return_false' );
		$name = $this->name();
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); }
		WC()->cart->cart_contents = array( 'k1' => array( 'beltoft_services_domain' => array( 'domain' => $name, 'mode' => 'transfer' ) ) );
		$this->respond_with( fn() => $this->rdap( false ) );
		$errors = new \WP_Error();
		Cart::validate_transfer_lock_checkout( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
		$this->respond_with( fn() => $this->rdap( true ) );
		Cart::validate_transfer_lock_checkout( array(), $errors );
		$this->assertSame( array( 'domain_locked' ), $errors->get_error_codes() );
		$this->assertStringContainsString( 'is locked at Acme Registrar. Unlock it there', $errors->get_error_message( 'domain_locked' ) );
		WC()->cart->cart_contents = array();
	}

	public function test_classify(): void {
		$this->assertSame( 'locked', TransferFix::classify( 'op_1 domain status clientTransferProhibited' ) );
		$this->assertSame( 'auth_refused', TransferFix::classify( 'Wrong EPP code' ) );
		$this->assertSame( 'auth_refused', TransferFix::classify( 'Authorization info is invalid' ) );
		$this->assertSame( '', TransferFix::classify( 'Balance too low' ) );
		$this->assertSame( '', TransferFix::classify( 'Author unknown' ) );
	}
}

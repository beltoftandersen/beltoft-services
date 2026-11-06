<?php
/** The S/MIME Personal profile of the SSL driver: mailbox request, email validation, delivery, key as PKCS#12, cancel, reissue. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Cli\SslCommand;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\Csr;
use BeltoftServices\Ssl\Keys;
use BeltoftServices\Ssl\Operations;
use BeltoftServices\Ssl\Panels;
use BeltoftServices\Ssl\Provision;
use BeltoftServices\Ssl\SslCatalog;
use BeltoftServices\Ssl\SslDriver;
use BeltoftServices\Ssl\SslField;

require_once __DIR__ . '/SslTestCase.php';

class SmimeTest extends SslTestCase {

	private const BOX = 'christian.beltoft@gmail.com';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_smime_mailbox_allowed' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		unset( $_POST[ SslField::POST_KEY ] );
		parent::tear_down();
	}

	/** @param array<string, mixed> $opts */
	private function smime( array $opts = array() ): Resource {
		$meta = ( $opts['meta'] ?? array() ) + array( 'ssl_name' => self::BOX, 'ssl_domain' => 'gmail.com', 'ssl_validation' => 'email', 'ssl_approver' => self::BOX, 'ssl_person' => 'Test Person' );
		unset( $opts['meta'] );

		return $this->ssl_resource( $opts + array( 'product' => 60, 'total' => 29.0, 'meta' => $meta ) );
	}

	/** @param array<string, mixed> $opts */
	private function smime_built( array $opts = array() ): Resource {
		$r = $this->smime( $opts );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertTrue( $r->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) ) );

		return Resource::find( $r->id() );
	}

	/** @param array<string, mixed> $params */
	private function call( string $path, int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_get_server()->dispatch( $request );
	}

	// -- Catalog -----------------------------------------------------------------------------------

	public function test_only_product_60_is_the_smime_profile(): void {
		$this->assertTrue( SslCatalog::is_smime( 60 ) );
		$this->assertSame( 'smime', SslCatalog::profile( 60 ) );
		foreach ( array( 41, 42, 20, 24, 58, 61, 59 ) as $other ) {
			$this->assertFalse( SslCatalog::is_smime( $other ), (string) $other );
			$this->assertSame( 'website', SslCatalog::profile( $other ) );
		}
		$this->assertFalse( SslCatalog::is_website( 60 ), 'It names no host.' );
		$this->assertTrue( SslCatalog::is_sellable( 60 ) );
		$this->assertFalse( SslCatalog::is_sellable( 58 ), 'Code signing is not sold.' );
		$this->assertArrayNotHasKey( 60, SslCatalog::sellable(), 'The website certificate list does not hold it.' );
		$this->assertSame( 18.33, SslCatalog::cost( 60 ) );
	}

	public function test_the_variation_is_added_with_the_price_given_and_never_below_cost(): void {
		SslCommand::create( array( 'dv' => '19' ) );
		$this->assertSame( 'below_cost', SslCommand::check_variation( 60, '10' )->get_error_code() );
		$this->assertSame( 'not_website', SslCommand::check_variation( 58, '119' )->get_error_code() );
		$this->assertFalse( SslCatalog::is_sellable( 61 ), 'S/MIME Pro is never sold.' );
		$vid = SslCommand::add_variation( 60, '29' );
		$this->assertIsInt( $vid );
		$parent = SslCommand::existing();
		$plan = Product::plan( $parent, $vid );
		$this->assertSame( 60, (int) $plan['ssl_product'] );
		$this->assertSame( 'dv', $plan['ssl_level'] );
		$this->assertSame( '29', (string) wc_get_product( $vid )->get_regular_price() );
		$this->assertSame( 'exists', SslCommand::check_variation( 60, '29' )->get_error_code() );
	}

	// -- Requests ----------------------------------------------------------------------------------

	public function test_the_generated_request_carries_the_mailbox_in_the_subject_and_as_the_only_email_name(): void {
		$made = Csr::generate_email( 'Christian.Beltoft@Gmail.com', 'Test Person', 'dk' );
		$this->assertIsArray( $made );
		$read = Csr::parse( $made['csr'] );
		$this->assertSame( self::BOX, $read['subject']['emailAddress'] );
		$this->assertSame( 'Test Person', $read['subject']['CN'] );
		$this->assertSame( 'DK', $read['subject']['C'] );
		$this->assertSame( 2048, $read['bits'] );
		$this->assertSame( array( self::BOX ), Csr::sans( $made['csr'], true ) );
		$this->assertSame( array( '#other' ), Csr::sans( $made['csr'] ), 'It names no DNS name: the email entry never matches a host.' );
		$this->assertIsArray( Csr::check_email( $made['csr'], self::BOX ) );
		$this->assertWPError( Csr::generate_email( 'not an address', '', 'DK' ) );
		$blank = Csr::generate_email( self::BOX, '', 'DK' );
		$this->assertArrayNotHasKey( 'CN', Csr::parse( $blank['csr'] )['subject'] );
	}

	public function test_a_request_for_another_mailbox_or_with_host_names_is_refused(): void {
		$other = Csr::generate_email( 'other@example.com', 'X', 'DK' );
		$this->assertSame( 'ssl_csr_mailbox', Csr::check_email( $other['csr'], self::BOX )->get_error_code() );
		$website = Csr::generate( 'example.com', array( 'countryName' => 'DK' ) );
		$this->assertSame( 'ssl_csr_mailbox', Csr::check_email( $website['csr'], self::BOX )->get_error_code(), 'A website request has no mailbox.' );
		$dns = Csr::generate( 'example.com', array( 'countryName' => 'DK', 'emailAddress' => self::BOX ), array( 'example.com', 'www.example.com' ) );
		$this->assertSame( 'ssl_csr_mailbox', Csr::check_email( $dns['csr'], self::BOX )->get_error_code(), 'Host names are not allowed.' );
		$nocountry = Csr::generate_email( self::BOX, 'X', '' );
		$this->assertSame( 'ssl_csr_country', Csr::check_email( $nocountry['csr'], self::BOX )->get_error_code() );
		$this->assertWPError( Csr::check_email( 'not a request', self::BOX ) );
	}

	// -- Checkout ----------------------------------------------------------------------------------

	public function test_the_mailbox_is_bound_to_the_account_or_billing_email(): void {
		$ok = SslField::resolve_smime( array( 'mailbox' => ' Christian.Beltoft@Gmail.com ', 'person' => ' Test <b>Person</b> ', 'key_mode' => 'generate' ), 0, self::BOX, '' );
		$this->assertSame( array( 'profile' => 'smime', 'domain' => 'gmail.com', 'name' => self::BOX, 'person' => 'Test Person', 'validation' => 'email', 'approver' => self::BOX, 'key_mode' => 'generate', 'csr' => '' ), $ok );
		$this->assertIsArray( SslField::resolve_smime( array( 'mailbox' => self::BOX ), 0, 'x@example.com', self::BOX ), 'The account email is allowed.' );
		$this->assertSame( 'ssl_mailbox_bound', SslField::resolve_smime( array( 'mailbox' => 'boss@example.com' ), 0, self::BOX, self::BOX )->get_error_code() );
		$this->assertSame( 'ssl_mailbox', SslField::resolve_smime( array( 'mailbox' => 'nope' ), 0, self::BOX, '' )->get_error_code() );
		$this->assertSame( 'ssl_mailbox', SslField::resolve_smime( array( 'mailbox' => '' ), 0, self::BOX, '' )->get_error_code() );
		add_filter( 'beltoft_services_smime_mailbox_allowed', '__return_true' );
		$this->assertIsArray( SslField::resolve_smime( array( 'mailbox' => 'boss@example.com' ), 0, self::BOX, '' ), 'A site can loosen the rule.' );
	}

	public function test_an_own_request_is_checked_against_the_mailbox_at_checkout(): void {
		$mine = Csr::generate_email( self::BOX, 'Me', 'DK' );
		$ok = SslField::resolve_smime( array( 'mailbox' => self::BOX, 'key_mode' => 'own', 'csr' => $mine['csr'] ), 0, self::BOX, '' );
		$this->assertSame( 'own', $ok['key_mode'] );
		$this->assertSame( trim( $mine['csr'] ), $ok['csr'] );
		$other = Csr::generate_email( 'other@example.com', 'Me', 'DK' );
		$this->assertWPError( SslField::resolve_smime( array( 'mailbox' => self::BOX, 'key_mode' => 'own', 'csr' => $other['csr'] ), 0, self::BOX, '' ) );
		$this->assertWPError( SslField::resolve_smime( array( 'mailbox' => self::BOX, 'key_mode' => 'own', 'csr' => '' ), 0, self::BOX, '' ) );
	}

	public function test_it_is_refused_when_switched_off_and_when_a_live_certificate_exists_for_the_mailbox(): void {
		$r = $this->smime_built();
		$user = (int) $r->license()['user_id'];
		$again = SslField::resolve_smime( array( 'mailbox' => self::BOX ), $user, self::BOX, '' );
		$this->assertSame( 'ssl_refused', $again->get_error_code() );
		$this->settings( array( 'ssl_enabled' => 0 ) );
		$this->assertSame( 'ssl_off', SslField::resolve_smime( array( 'mailbox' => self::BOX ), 0, self::BOX, '' )->get_error_code() );
	}

	public function test_the_cart_line_shows_the_mailbox_field_and_saves_the_choice_on_the_order_line(): void {
		$product = $this->service_product( 'ssl', array( 'ssl_product' => 60, 'ssl_level' => 'dv' ), array( 'slug' => 'smime-cart-' . wp_generate_password( 4, false ) ) );
		WC()->cart->empty_cart();
		$key = WC()->cart->add_to_cart( $product->get_id() );

		if ( ! $key ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		do_action( 'woocommerce_checkout_process' );
		$_POST[ SslField::POST_KEY ] = array( $key => array( 'mailbox' => 'boss@example.com', 'key_mode' => 'generate' ) );
		$errors = new \WP_Error();
		SslField::validate( array( 'billing_email' => self::BOX ), $errors );
		$this->assertNotEmpty( $errors->get_error_messages(), 'Not the billing email.' );
		$_POST[ SslField::POST_KEY ] = array( $key => array( 'mailbox' => self::BOX, 'person' => 'Test Person', 'key_mode' => 'generate' ) );
		$errors = new \WP_Error();
		SslField::validate( array( 'billing_email' => self::BOX ), $errors );
		$this->assertSame( array(), $errors->get_error_messages() );
		$order = new \WC_Order();
		$order->set_billing_email( self::BOX );
		$item = new \WC_Order_Item_Product();
		SslField::save_on_item( $item, (string) $key, WC()->cart->get_cart()[ $key ], $order );
		$saved = $item->get_meta( SslField::ITEM_META );
		$this->assertSame( self::BOX, $saved['name'] );
		$this->assertSame( 'email', $saved['validation'] );
		ob_start();
		SslField::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '[mailbox]', $html );
		$this->assertStringNotContainsString( '[domain]', $html );
		$this->assertStringNotContainsString( 'wildcard', strtolower( $html ) );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
		WC()->cart->empty_cart();
	}

	public function test_the_choices_reach_the_resource_through_the_initial_meta(): void {
		$user = (int) self::factory()->user->create();
		$product = $this->service_product( 'ssl', array( 'ssl_product' => 60, 'ssl_level' => 'dv' ), array( 'slug' => 'smime-meta-' . wp_generate_password( 4, false ) ) );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item = new \WC_Order_Item_Product();
		$item->set_props( array( 'product_id' => $product->get_id(), 'quantity' => 1, 'total' => 29 ) );
		$item->add_meta_data( SslField::ITEM_META, array( 'profile' => 'smime', 'domain' => 'gmail.com', 'name' => self::BOX, 'person' => 'Test Person', 'validation' => 'email', 'approver' => self::BOX, 'key_mode' => 'generate', 'csr' => '' ) );
		$order->add_item( $item );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$meta = SslField::initial_meta( array(), $license, new SslDriver() );
		$this->assertSame( self::BOX, $meta['ssl_name'] );
		$this->assertSame( self::BOX, $meta['ssl_approver'] );
		$this->assertSame( 'email', $meta['ssl_validation'] );
		$this->assertSame( 'Test Person', $meta['ssl_person'] );
	}

	// -- Build -------------------------------------------------------------------------------------

	public function test_the_order_is_placed_without_host_names_and_started_with_the_mailbox(): void {
		$r = $this->smime();
		$first = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $first->is_retry(), 'The authority waits for the mailbox: ' . $first->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( 'csr', 'contact', 'order', 'request', 'validate' ), $fresh->get( 'steps' ) );
		$this->assertSame( 'REQ', $fresh->get( 'ssl_status' ) );

		$post = $this->calls( 'POST', '#^/ssl/orders$#' );
		$this->assertCount( 1, $post );
		$body = $post[0]['body'];
		$this->assertSame( 60, $body['product_id'] );
		$this->assertSame( self::BOX, $body['approver_email'] );
		$this->assertArrayNotHasKey( 'domain_validation_methods', $body );
		$this->assertArrayNotHasKey( 'domain_amount', $body );
		$this->assertNotSame( '', $body['software_id'] );

		$put = $this->calls( 'PUT', '#^/ssl/orders/\d+$#' );
		$this->assertTrue( $put[0]['body']['start_provision'] );
		$this->assertSame( self::BOX, $put[0]['body']['approver_email'] );

		$rows = $fresh->get( 'ssl_validation_rows' );
		$this->assertSame( 'email', $rows[0]['type'] );
		$this->assertSame( self::BOX, $rows[0]['value'] );
		$this->assertSame( 'email', $fresh->get( 'ssl_action_sent' ) );
		$this->assertSame( 'Waiting for the certificate authority', Panels::status_text( $fresh ) );
	}

	public function test_a_mailbox_request_is_made_here_with_the_billing_name_and_the_key_is_stored_encrypted(): void {
		$r = $this->smime( array( 'meta' => array( 'ssl_person' => '' ) ) );
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$csr = Csr::parse( (string) $fresh->get( 'ssl_csr' ) );
		$this->assertSame( self::BOX, $csr['subject']['emailAddress'] );
		$this->assertSame( 'Test Customer', $csr['subject']['CN'], 'The billing name is the name on the certificate.' );
		$this->assertSame( 'DK', $csr['subject']['C'] );
		$this->assertStringContainsString( 'PRIVATE KEY', (string) Keys::existing( $fresh ) );
		$this->assertStringNotContainsString( 'PRIVATE KEY', (string) $fresh->get( Keys::META_KEY ) );
		foreach ( $this->registrar->calls() as $call ) {
			$this->assertStringNotContainsString( 'PRIVATE KEY', (string) wp_json_encode( $call ) );
		}
	}

	public function test_the_certificate_is_delivered_after_the_authority_is_done_and_names_the_mailbox(): void {
		$r = $this->smime_built();
		$this->assertSame( array( 'csr', 'contact', 'order', 'request', 'validate', 'active', 'deliver', 'dates' ), $r->get( 'steps' ) );
		$this->assertSame( 'ACT', $r->get( 'ssl_status' ) );
		$this->assertTrue( Csr::certificate_has_mailbox( (string) $r->get( 'ssl_cert' ), self::BOX ) );
		$this->assertFalse( Csr::certificate_has_mailbox( (string) $r->get( 'ssl_cert' ), 'other@example.com' ) );
		$this->assertTrue( Keys::revealable( $r ) );
		$this->assertCount( 1, $this->calls( 'POST', '#^/ssl/orders$#' ), 'One order.' );
	}

	public function test_a_customers_own_request_is_used_and_no_key_is_made(): void {
		$mine = Csr::generate_email( self::BOX, 'Me', 'DK' );
		$r = $this->smime_built( array( 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $mine['csr'] ) ) );
		$this->assertSame( '', (string) $r->get( Keys::META_KEY ) );
		$this->assertSame( trim( $mine['csr'] ), trim( $this->calls( 'POST', '#^/ssl/orders$#' )[0]['body']['csr'] ) );
		$this->assertSame( Csr::parse( $mine['csr'] )['public'], Csr::certificate( (string) $r->get( 'ssl_cert' ) )['public'] );
	}

	public function test_a_request_for_another_mailbox_fails_before_anything_is_ordered(): void {
		$other = Csr::generate_email( 'other@example.com', 'Me', 'DK' );
		$r = $this->smime( array( 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $other['csr'] ) ) );
		$this->assertTrue( ( new SslDriver() )->provision( $r )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_nothing_is_ordered_when_the_paid_line_is_below_the_cost(): void {
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r = $this->smime( array( 'total' => 5.0 ) );
		$result = ( new SslDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_a_certificate_that_does_not_carry_the_mailbox_is_not_stored(): void {
		$r = $this->smime_built();
		$r->set( array( 'ssl_name' => 'other@example.com' ) );
		$detail = $this->registrar->request( 'GET', '/ssl/orders/' . $r->get( 'remote_id' ) );
		$result = Provision::store_certificate( Resource::find( $r->id() ), $detail );
		$this->assertTrue( $result->is_failed() );
	}

	public function test_a_website_product_with_a_mailbox_name_fails(): void {
		$r = $this->ssl_resource( array( 'product' => 41, 'meta' => array( 'ssl_name' => self::BOX, 'ssl_domain' => 'gmail.com' ) ) );
		$this->assertTrue( ( new SslDriver() )->provision( $r )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ) );
	}

	public function test_a_lost_order_is_found_by_its_request_not_by_its_name(): void {
		$r = $this->smime();
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$id = (string) $fresh->get( 'remote_id' );
		// The answer to the order was lost: the marker is set and the order id is not known.
		$fresh->set( array( 'remote_id' => '', 'steps' => array( 'csr', 'contact' ), 'ssl_post_attempted' => $this->now ) );
		$this->registrar->request( 'POST', '/ssl/orders/' . $id . '/cancel' );
		$state = ( new SslDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $state->is_retry(), 'It waits for staff when no live order matches.' );
		$this->assertCount( 1, $this->calls( 'POST', '#^/ssl/orders$#' ), 'No second order was posted.' );
		$this->assertTrue( Provision::resolve_marker( Resource::find( $r->id() ), 0 ) );
	}

	public function test_the_marker_is_adopted_by_request_text(): void {
		$r = $this->smime();
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$id = (int) $fresh->get( 'remote_id' );
		$fresh->set( array( 'remote_id' => '', 'steps' => array( 'csr', 'contact' ) ) );
		$this->assertTrue( Provision::resolve_marker( Resource::find( $r->id() ), $id ) );
		$this->assertSame( (string) $id, Resource::find( $r->id() )->get( 'remote_id' ) );
		$this->assertWPError( Provision::resolve_marker( Resource::find( $r->id() ), $id ) );
	}

	// -- Validation actions ------------------------------------------------------------------------

	public function test_no_validation_change_and_no_resend_is_offered_or_run(): void {
		$r = $this->smime();
		( new SslDriver() )->provision( $r );
		$fresh = Resource::find( $r->id() );
		$offered = Operations::offered( $fresh );
		$this->assertArrayNotHasKey( 'ssl_change_validation', $offered );
		$this->assertArrayNotHasKey( 'ssl_resend_email', $offered );
		$this->assertTrue( Operations::resend( $fresh )->is_failed() );
		$this->assertTrue( Operations::change_validation( $fresh, array( 'method' => 'dns' ) )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#approver-email/resend$#' ) );
		$this->assertCount( 1, $this->calls( 'PUT', '#^/ssl/orders/\d+$#' ), 'Only the start was sent.' );
	}

	public function test_the_actions_route_refuses_them_at_once(): void {
		$r = $this->smime_built();
		$user = (int) $r->license()['user_id'];
		$this->assertSame( 403, $this->call( $r->id() . '/actions/ssl_change_validation', $user, array( 'args' => array( 'method' => 'dns' ) ) )->get_status(), 'Not an action it offers.' );
		$this->assertSame( 403, $this->call( $r->id() . '/actions/ssl_resend_email', $user )->get_status() );
		$this->assertSame( 422, \BeltoftServices\Ssl\SslRoutes::refusal( null, $r, 'ssl_resend_email', array() )->get_error_data()['status'], 'And the refusal says so too.' );
		$other = Csr::generate_email( 'other@example.com', 'X', 'DK' );
		$this->assertSame( 422, $this->call( $r->id() . '/actions/ssl_reissue', $user, array( 'args' => array( 'csr' => $other['csr'] ) ) )->get_status() );
	}

	// -- Key as PKCS#12 ----------------------------------------------------------------------------

	public function test_the_key_is_taken_once_as_a_password_protected_pkcs12_with_the_certificate(): void {
		$r = $this->smime_built();
		$user = (int) $r->license()['user_id'];
		$short = $this->call( $r->id() . '/ssl-pkcs12', $user, array( 'password' => 'short' ) );
		$this->assertSame( 422, $short->get_status() );
		$this->assertTrue( Keys::revealable( Resource::find( $r->id() ) ), 'A refused password takes nothing.' );

		$one = $this->call( $r->id() . '/ssl-pkcs12', $user, array( 'password' => 'a long password 123' ) );
		$this->assertSame( 200, $one->get_status() );
		$data = $one->get_data();
		$this->assertArrayNotHasKey( 'password', $data, 'The customer chose it.' );
		$this->assertTrue( openssl_pkcs12_read( base64_decode( $data['pkcs12'] ), $out, 'a long password 123' ) );
		$this->assertSame( trim( (string) $r->get( 'ssl_cert' ) ), trim( $out['cert'] ) );
		$this->assertNotEmpty( $out['extracerts'], 'The chain is in the file.' );
		$this->assertSame( Csr::certificate( $out['cert'] )['public'], openssl_pkey_get_details( openssl_pkey_get_private( $out['pkey'] ) )['key'] );
		$this->assertFalse( openssl_pkcs12_read( base64_decode( $data['pkcs12'] ), $none, 'wrong password 123' ) );
		$this->assertSame( 'no-store', (string) ( $one->get_headers()['Cache-Control'] ?? '' ) );

		$this->assertSame( '', (string) Resource::find( $r->id() )->get( Keys::META_KEY ), 'Wiped.' );
		$this->assertSame( 409, $this->call( $r->id() . '/ssl-pkcs12', $user )->get_status() );
	}

	public function test_a_password_is_made_when_none_is_given_and_returned_once(): void {
		$r = $this->smime_built();
		$user = (int) $r->license()['user_id'];
		$data = $this->call( $r->id() . '/ssl-pkcs12', $user )->get_data();
		$this->assertGreaterThanOrEqual( 20, strlen( $data['password'] ) );
		$this->assertTrue( openssl_pkcs12_read( base64_decode( $data['pkcs12'] ), $out, $data['password'] ) );
		$this->assertStringNotContainsString( $data['password'], (string) wp_json_encode( Resource::find( $r->id() )->get( 'plan' ) ) );
		$events = \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] );
		$this->assertStringNotContainsString( $data['password'], (string) wp_json_encode( $events ) );
	}

	public function test_the_pkcs12_route_is_for_the_owner_of_an_smime_certificate_only(): void {
		$r = $this->smime_built();
		$this->assertSame( 403, $this->call( $r->id() . '/ssl-pkcs12', (int) self::factory()->user->create() )->get_status() );
		$this->assertSame( 409, $this->call( $r->id() . '/ssl-key', (int) $r->license()['user_id'] )->get_status(), 'The raw key route is not used for S/MIME.' );
		$this->assertTrue( Keys::revealable( Resource::find( $r->id() ) ) );
		$web = $this->built();
		$this->assertSame( 404, $this->call( $web->id() . '/ssl-pkcs12', (int) $web->license()['user_id'] )->get_status() );
		$this->assertFalse( Tokens::reachable( '/beltoft-services/v1/resources/5/ssl-pkcs12' ), 'Never with an API token.' );
	}

	public function test_a_customers_own_key_has_no_pkcs12(): void {
		$mine = Csr::generate_email( self::BOX, 'Me', 'DK' );
		$r = $this->smime_built( array( 'meta' => array( 'ssl_key_mode' => 'own', 'ssl_csr' => $mine['csr'] ) ) );
		$this->assertSame( 409, $this->call( $r->id() . '/ssl-pkcs12', (int) $r->license()['user_id'] )->get_status() );
	}

	// -- Page and emails ---------------------------------------------------------------------------

	public function test_the_page_asks_for_the_mailbox_link_then_offers_the_pkcs12_and_shows_no_secret(): void {
		$r = $this->smime();
		( new SslDriver() )->provision( $r );
		ob_start();
		Panels::account( Resource::find( $r->id() ) );
		$waiting = (string) ob_get_clean();
		$this->assertStringContainsString( self::BOX, $waiting );
		$this->assertStringContainsString( 'confirm that the mailbox is yours', $waiting );
		$this->assertStringContainsString( 'data-ssl-panel', $waiting, 'The authority page is the fallback.' );
		$this->assertStringNotContainsString( 'data-ssl-action="ssl_resend_email"', $waiting );

		$built = $this->smime_built();
		$plain = (string) Keys::existing( $built );
		ob_start();
		Panels::account( $built );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-ssl-p12', $html );
		$this->assertStringNotContainsString( 'data-ssl-key', $html );
		$this->assertStringNotContainsString( 'at most 199 days', $html );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $html );
		$this->assertStringNotContainsString( trim( explode( "\n", $plain )[1] ), $html );
		$this->assertStringNotContainsString( '—', $html . $waiting );
		$this->assertStringNotContainsString( '·', $html . $waiting );
		$this->assertStringNotContainsString( 'Openprovider', $html . $waiting );
		$this->assertSame( 'S/MIME certificate', \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Ssl::class )->label_of( $built ) );
		$this->assertSame( self::BOX, \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Ssl::class )->title_of( $built, array() ) );
	}

	public function test_the_emails_speak_of_the_mailbox_and_carry_no_secret(): void {
		$r = $this->smime_built();
		$this->mails = array();
		$this->assertTrue( Emails::send( 'beltoft_services_ssl_action_needed', $r, array( 'ssl_reason' => 'email' ) ) );
		$this->assertTrue( Emails::send( 'beltoft_services_ssl_issued', $r ) );
		$this->assertCount( 2, $this->mails );
		$key = (string) Keys::existing( $r );

		foreach ( $this->mails as $mail ) {
			$all = $mail['subject'] . $mail['message'];
			$this->assertStringNotContainsString( 'PRIVATE KEY', $all );
			$this->assertStringNotContainsString( trim( explode( "\n", $key )[1] ), $all );
			$this->assertStringNotContainsString( '—', $all );
			$this->assertStringNotContainsString( '·', $all );
			$this->assertStringNotContainsString( 'Openprovider', $all );
			$this->assertStringContainsString( self::BOX, $all );
		}

		$this->assertStringContainsString( 'click the link', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'validated', $this->mails[0]['message'], 'No domain talk.' );
		$this->assertStringContainsString( '.p12', $this->mails[1]['message'] );
		$this->assertStringNotContainsString( 'your server', $this->mails[1]['message'] );
	}

	// -- Cancel, refund window, reissue ------------------------------------------------------------

	public function test_it_can_be_cancelled_inside_the_free_window_and_not_after(): void {
		$r = $this->smime_built();
		$this->assertArrayHasKey( 'ssl_cancel', Operations::offered( $r ) );
		$result = Operations::cancel( $r, array( 'confirm' => 'CANCEL' ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( (bool) $fresh->get( 'ssl_cancelled' ) );
		$this->assertSame( '', (string) $fresh->get( Keys::META_KEY ), 'The key goes with the certificate.' );
		$this->assertCount( 1, $this->calls( 'POST', '#/cancel$#' ) );

		$late = $this->smime_built();
		$late->set( array( 'ssl_first_issued_at' => time() - 31 * DAY_IN_SECONDS ) );
		$this->assertArrayNotHasKey( 'ssl_cancel', Operations::offered( Resource::find( $late->id() ) ) );
		$this->assertTrue( Operations::cancel( Resource::find( $late->id() ), array( 'confirm' => 'CANCEL' ) )->is_failed() );
	}

	public function test_a_reissue_makes_a_new_mailbox_request_without_host_names_and_delivers_the_new_certificate(): void {
		$r = $this->smime_built();
		$old = (string) $r->get( 'ssl_serial' );
		$result = Operations::reissue( $r, array() );
		$this->assertTrue( $result->is_done(), $result->error() );
		$call = $this->calls( 'POST', '#/reissue$#' )[0]['body'];
		$this->assertArrayNotHasKey( 'domain_validation_methods', $call );
		$this->assertSame( self::BOX, $call['approver_email'] );
		$this->assertSame( self::BOX, Csr::parse( $call['csr'] )['subject']['emailAddress'] );
		$this->now += 120;
		$this->assertTrue( Operations::refresh( Resource::find( $r->id() ) )->is_done() );
		$fresh = Resource::find( $r->id() );
		$this->assertNotSame( $old, (string) $fresh->get( 'ssl_serial' ) );
		$this->assertTrue( Csr::certificate_has_mailbox( (string) $fresh->get( 'ssl_cert' ), self::BOX ) );
		$this->assertTrue( Keys::revealable( $fresh ), 'The new key can be taken.' );
	}

	public function test_a_reissue_with_the_customers_own_request_is_checked_for_the_mailbox(): void {
		$r = $this->smime_built();
		$other = Csr::generate_email( 'other@example.com', 'X', 'DK' );
		$this->assertTrue( Operations::reissue( $r, array( 'csr' => $other['csr'] ) )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '#/reissue$#' ) );
		$mine = Csr::generate_email( self::BOX, 'Me', 'DK' );
		$this->assertTrue( Operations::reissue( $r, array( 'csr' => $mine['csr'] ) )->is_done() );
	}

	// -- Registrar model ---------------------------------------------------------------------------

	public function test_the_fake_registrar_behaves_as_the_sandbox_did(): void {
		$mine = Csr::generate_email( self::BOX, 'Test User', 'DK' );
		$refused = $this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 60, 'period' => 1, 'csr' => $mine['csr'], 'domain_validation_methods' => array( array( 'host_name' => self::BOX, 'method' => 'email' ) ) ) );
		$this->assertSame( 'op_1044', $refused->get_error_code() );
		$made = $this->registrar->request( 'POST', '/ssl/orders', array( 'product_id' => 60, 'period' => 1, 'csr' => $mine['csr'], 'organization_handle' => 'H1' ) );
		$id = (int) $made['id'];
		$this->assertSame( 'op_1045', $this->registrar->request( 'PUT', '/ssl/orders/' . $id, array( 'start_provision' => true ) )->get_error_code(), 'No approver.' );
		$this->assertSame( 'op_1004', $this->registrar->request( 'PUT', '/ssl/orders/' . $id, array( 'start_provision' => true, 'approver_email' => self::BOX ) )->get_error_code(), 'No software.' );
		$this->assertIsArray( $this->registrar->request( 'PUT', '/ssl/orders/' . $id, array( 'start_provision' => true, 'approver_email' => self::BOX, 'software_id' => 'linux' ) ) );
		$order = $this->registrar->request( 'GET', '/ssl/orders/' . $id );
		$this->assertSame( 'REQ', $order['status'] );
		$this->assertSame( 'test user', $order['common_name'], 'Named after the request, lowercased, as the sandbox shows.' );
		$this->assertSame( 'op_1099', $this->registrar->request( 'POST', '/ssl/orders/' . $id . '/approver-email/resend', array( 'id' => $id ) )->get_error_code() );
	}
}

<?php
/** Plesk as a paid app: the Openprovider licence behind a server's tier. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftLicenses\Licenses;
use BeltoftServices\Cli\PleskProductCommand;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\FakeClient as Registrar;
use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Jobs;
use BeltoftServices\Apps\AppInstall;
use BeltoftServices\Apps\AppLicence;
use BeltoftServices\Apps\AppTab;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Apps\PleskLicence;
use BeltoftServices\Resource;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * No network: the Openprovider fake client (documented replies, made-up values) and the Proxmox fake only.
 */
final class PleskLicenceTest extends PaidAppCase {

	private Registrar $op;

	/** @var int Calls of the fake registrar before this number are not looked at by op_calls(). */
	private int $op_from = 0;

	public function set_up(): void {
		parent::set_up();
		$this->op_from = 0;
		delete_option( Registrar::OPTION );
		$this->op = new Registrar( true );
		add_filter( 'beltoft_services_domains_client', fn() => $this->op );
		PleskLicence::register();
		$this->settings( array( 'plesk_costs' => "pro=10\nadmin=6\nhost=20" ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_client' );
		delete_option( Registrar::OPTION );
		parent::tear_down();
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	/**
	 * The Plesk product from the CLI function, priced and published as the owner would.
	 *
	 * @param array<string, string> $prices Tier key => monthly price.
	 * @return array<string, \WC_Product_Variation>
	 */
	private function plesk_tiers( array $prices = array( 'admin' => '8', 'pro' => '12', 'host' => '25' ) ): array {
		$id      = PleskProductCommand::create();
		$product = wc_get_product( $id );
		$product->set_status( 'publish' );
		$product->save();
		$out = array();

		foreach ( $product->get_children() as $vid ) {
			$v   = wc_get_product( $vid );
			$key = (string) get_post_meta( $vid, \BeltoftServices\Product::META_PLAN . 'tier', true );
			$v->set_regular_price( $prices[ $key ] );
			update_post_meta( $vid, '_subscription_price', $prices[ $key ] );
			$v->save();
			$out[ $key ] = wc_get_product( $vid );
		}

		\WC_Product_Variable::sync( $id );

		return $out;
	}

	/**
	 * A server running Plesk with a tier line, the order in EUR.
	 *
	 * @return array{server: Resource, licence: Resource, user: int, order: \WC_Order, tiers: array<string, \WC_Product_Variation>}
	 */
	private function plesk_order( string $tier = 'pro' ): array {
		$tiers = $this->plesk_tiers();
		$o     = $this->ordered( $tiers[ $tier ], $tier );
		$o['order']->set_currency( 'EUR' );
		$o['order']->save();
		$this->assertTrue( $o['server']->set( array( 'app_id' => 'plesk', 'app_version' => '18', 'app_state' => 'pending', OsField::META => 9001, 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ) ) ) );
		$o['server'] = $this->fresh( $o['server'] );
		$o['tiers']  = $tiers;

		return $o;
	}

	private function provisioned( string $tier = 'pro' ): array {
		$o = $this->plesk_order( $tier );
		Jobs::run( $o['licence']->id() );
		$o['licence'] = $this->fresh( $o['licence'] );

		return $o;
	}

	/**
	 * Openprovider calls of a method.
	 *
	 * @param string $method HTTP method.
	 * @return array<int, array<string, mixed>>
	 */
	private function op_calls( string $method, string $path = '' ): array {
		return array_values( array_filter( array_slice( $this->op->calls(), $this->op_from ), static fn( $c ) => $c['method'] === $method && ( '' === $path || $c['path'] === $path ) ) );
	}

	private function licences(): array {
		$state = get_option( Registrar::OPTION, array() );

		return (array) ( $state['licences'] ?? array() );
	}

	// -- Create -----------------------------------------------------------------------------------

	public function test_the_licence_is_created_once_for_the_tiers_item_bound_to_the_servers_address(): void {
		$o = $this->provisioned( 'pro' );

		$this->assertSame( 'ready', $o['licence']->get( 'state' ), (string) $o['licence']->get( 'last_error' ) );
		$posts = $this->op_calls( 'POST', '/licenses/plesk' );
		$this->assertCount( 1, $posts );
		$this->assertSame( array( 'PLESK-12-VPS-WEB-PRO-1M' ), $posts[0]['body']['items'] );
		$this->assertSame( '192.0.2.10', $posts[0]['body']['ip_address_binding'] );
		$this->assertSame( 1, $posts[0]['body']['period'] );
		$this->assertStringStartsWith( 'licence ' . (int) $o['licence']->license()['id'] . ' ', $posts[0]['body']['title'] );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertGreaterThan( 0, $key_id );
		$this->assertArrayHasKey( $key_id, $this->licences() );
		$this->assertSame( 'PLESK-12-VPS-WEB-PRO-1M', $o['licence']->get( 'plesk_item' ) );

		Jobs::run( $o['licence']->id() );
		$this->assertCount( 1, $this->op_calls( 'POST', '/licenses/plesk' ), 'Run again, no second licence.' );
	}

	public function test_a_crash_after_the_order_finds_the_licence_by_its_title_and_never_orders_twice(): void {
		$o     = $this->plesk_order( 'pro' );
		$title = 'licence ' . (int) $o['licence']->license()['id'] . ' server';
		// The order went through at Openprovider but the answer was lost: the licence exists, our meta does not know it.
		$made = $this->op->request( 'POST', '/licenses/plesk', array( 'title' => $title, 'items' => array( 'PLESK-12-VPS-WEB-PRO-1M' ), 'period' => 1, 'ip_address_binding' => '192.0.2.10' ) );
		$this->assertIsArray( $made );
		$before = count( $this->op_calls( 'POST', '/licenses/plesk' ) );

		Jobs::run( $o['licence']->id() );

		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 'ready', $licence->get( 'state' ), (string) $licence->get( 'last_error' ) );
		$this->assertSame( (int) $made['key_id'], (int) $licence->get( 'plesk_key_id' ) );
		$this->assertCount( $before, $this->op_calls( 'POST', '/licenses/plesk' ), 'No second order.' );
		$this->assertCount( 1, $this->licences() );
	}

	public function test_an_order_error_leaves_the_licence_to_be_tried_again(): void {
		$o = $this->plesk_order( 'pro' );
		$this->op->reply( 'POST', '/licenses/plesk', new \WP_Error( 'transport', 'Registrar request could not be completed.' ) );

		Jobs::run( $o['licence']->id() );
		$this->assertSame( 0, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );
		$this->assertNotSame( 'ready', $this->fresh( $o['licence'] )->get( 'state' ) );
		$this->assertCount( 0, $this->licences() );
		$this->assertSame( 'provisioning', $this->fresh( $o['licence'] )->get( 'state' ), 'It is tried again later; the title finds a licence that went through.' );
	}

	public function test_a_server_without_an_address_waits_and_orders_nothing(): void {
		$o = $this->plesk_order( 'pro' );
		global $wpdb;
		\BeltoftServices\Proxmox\Network\IpPool::release( $o['server']->id() );

		Jobs::run( $o['licence']->id() );

		$this->assertCount( 0, $this->op_calls( 'POST', '/licenses/plesk' ) );
		$this->assertNotSame( 'ready', $this->fresh( $o['licence'] )->get( 'state' ) );
	}

	// -- The spend guard ---------------------------------------------------------------------------

	public function test_a_line_paid_below_the_cost_orders_nothing_and_alerts(): void {
		$this->settings( array( 'plesk_costs' => 'pro=15' ) );
		$o = $this->plesk_order( 'pro' );
		$this->mails = array();

		Jobs::run( $o['licence']->id() );

		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 'failed', $licence->get( 'state' ) );
		$this->assertCount( 0, $this->op_calls( 'POST', '/licenses/plesk' ) );
		$this->assertStringContainsString( 'below the Openprovider cost', (string) $licence->get( 'last_error' ) );
		$this->assertNotEmpty( $this->mails );
	}

	public function test_a_vendor_step_that_fails_for_good_cancels_the_undelivered_subscription_and_names_the_order(): void {
		$this->settings( array( 'plesk_costs' => 'pro=15' ) );
		$o = $this->plesk_order( 'pro' );
		$this->mails = array();
		$id = (int) ( $o['licence']->license()['subscription_id'] ?? 0 );
		$this->assertGreaterThan( 0, $id, 'The order has a subscription.' );

		Jobs::run( $o['licence']->id() );

		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 'failed', $licence->get( 'state' ) );
		$this->assertStringContainsString( 'review and refund order #', (string) $licence->get( 'last_error' ) );
		$this->assertSame( 'cancelled', wcs_get_subscription( $id )->get_status() );
	}

	public function test_an_unknown_cost_is_refused_except_on_the_fake_client_and_a_non_euro_order_is_refused(): void {
		$o = $this->plesk_order( 'pro' );
		$this->settings( array( 'plesk_costs' => '' ) );
		$this->assertNull( PleskLicence::spend_problem( $o['licence'], 'pro' ), 'The fake client spends nothing.' );

		$this->settings( array( 'domain_api_mode' => 'sandbox' ) );
		$this->assertSame( 'The Openprovider cost of this Plesk tier is not set.', PleskLicence::spend_problem( $o['licence'], 'pro' ) );

		$this->settings( array( 'domain_api_mode' => 'fake', 'plesk_costs' => 'pro=10' ) );
		$o['order']->set_currency( 'USD' );
		$o['order']->save();
		$this->assertStringContainsString( 'not in EUR', (string) PleskLicence::spend_problem( $this->fresh( $o['licence'] ), 'pro' ) );
	}

	// -- The key -----------------------------------------------------------------------------------

	public function test_the_key_is_kept_encrypted_and_is_in_no_mail_no_admin_row_and_no_request(): void {
		$o      = $this->provisioned( 'pro' );
		$stored = (string) $o['licence']->get( 'plesk_key' );
		$plain  = Crypto::decrypt( $stored );

		$this->assertStringStartsWith( 'bs1:', $stored );
		$this->assertStringContainsString( 'FAKE-KEY-', (string) $plain );
		$this->assertStringNotContainsString( 'FAKE-KEY-', (string) wp_json_encode( $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) ) );
		global $wpdb;
		$row = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT meta FROM %i WHERE id = %d', \BeltoftLicenses\Install::tables()['activations'], $o['licence']->id() ) ); // phpcs:ignore WordPress.DB
		$this->assertStringNotContainsString( 'FAKE-KEY-', $row, 'Only the encrypted value is in the database.' );
		$this->assertStringNotContainsString( 'FAKE-KEY-', (string) wp_json_encode( $this->op->calls() ), 'No request to Openprovider carries a key.' );
		$this->assertStringNotContainsString( 'FAKE-KEY-', (string) wp_json_encode( $this->mails ) );

		ob_start();
		( new \BeltoftServices\Apps\AppLicenceDriver() )->render_admin( $o['licence'] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Openprovider licence id', $html );
		$this->assertStringContainsString( (string) $o['licence']->get( 'plesk_key_id' ), $html );
		$this->assertStringNotContainsString( 'FAKE-KEY-', $html );
	}

	public function test_the_install_job_carries_the_key_and_the_tier(): void {
		$o = $this->provisioned( 'pro' );
		$this->assertTrue( $o['server']->set( array( 'app_state' => 'pending' ) ) );

		$result = AppInstall::queue( $this->fresh( $o['server'] ), $this->fake );
		$this->assertTrue( $result->is_done(), $result->error() );
		$job = json_decode( (string) $this->fake->guest_file( 5000, Apps::JOB ), true );

		$this->assertSame( 'plesk', $job['app'] );
		$this->assertSame( 'pro', $job['tier'] );
		$this->assertSame( Crypto::decrypt( (string) $o['licence']->get( 'plesk_key' ) ), $job['licence']['key'] );
		$this->assertArrayNotHasKey( 'tier_label', $job['licence'] );
	}

	public function test_without_a_key_the_job_carries_an_empty_one_and_the_key_follows_when_the_app_is_ready(): void {
		$o = $this->provisioned( 'pro' );
		$this->assertTrue( $o['licence']->set( array( 'plesk_key' => null ) ) );
		$this->assertTrue( $o['server']->set( array( 'app_state' => 'pending' ) ) );

		$result = AppInstall::queue( $this->fresh( $o['server'] ), $this->fake );
		$this->assertTrue( $result->is_done(), $result->error() );
		$job = json_decode( (string) $this->fake->guest_file( 5000, Apps::JOB ), true );
		$this->assertSame( '', $job['licence']['key'], 'Plesk starts on its trial.' );

		// The server reports ready; the key is fetched and written for the guest.
		$this->assertTrue( $this->fresh( $o['server'] )->set( array( 'app_state' => 'ready' ) ) );
		PleskLicence::run_key( $o['licence']->id() );
		$licence = $this->fresh( $o['licence'] );
		$this->assertNotSame( '', (string) $licence->get( 'plesk_key' ) );
		PleskLicence::run_key( $o['licence']->id() );

		$request = json_decode( (string) $this->fake->guest_file( 5000, PleskLicence::KEY_FILE ), true );
		$this->assertSame( 1, $request['v'] );
		$key = (string) Crypto::decrypt( (string) $this->fresh( $o['licence'] )->get( 'plesk_key' ) );
		$this->assertSame( $key, $request['key'] );
		$this->assertSame( hash( 'sha256', $key ), $request['hash'] );
		$this->assertSame( 'applying', $this->fresh( $o['licence'] )->get( 'plesk_key_state' ) );

		// The guest answers with the hash, not the key.
		$this->fake->set_guest_file( 5000, PleskLicence::STATUS_FILE, wp_json_encode( array( 'hash' => hash( 'sha256', $key ), 'state' => 'ready' ) ) );
		PleskLicence::run_key( $o['licence']->id() );
		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 'installed', $licence->get( 'plesk_key_state' ) );
		$this->assertSame( hash( 'sha256', $key ), $licence->get( 'plesk_key_job' ) );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $this->mails ) );
	}

	public function test_a_key_the_install_put_on_is_not_sent_again(): void {
		$o = $this->provisioned( 'pro' );
		$this->assertTrue( $o['server']->set( array( 'app_state' => 'ready' ) ) );
		$key = (string) Crypto::decrypt( (string) $o['licence']->get( 'plesk_key' ) );
		$this->fake->set_guest_file( 5000, PleskLicence::STATUS_FILE, wp_json_encode( array( 'hash' => hash( 'sha256', $key ), 'state' => 'ready' ) ) );

		PleskLicence::run_key( $o['licence']->id() );

		$this->assertNull( $this->fake->guest_file( 5000, PleskLicence::KEY_FILE ) );
		$this->assertSame( 'installed', $this->fresh( $o['licence'] )->get( 'plesk_key_state' ) );
	}

	public function test_the_key_openprovider_sends_base64_encoded_is_stored_and_sent_as_the_key_file(): void {
		$xml = '<?xml version="1.0"?><plesk-key>' . str_repeat( 'x', 100 ) . '</plesk-key>';
		$this->assertSame( $xml, PleskLicence::normalise_key( base64_encode( $xml ) ) );
		$this->assertSame( $xml, PleskLicence::normalise_key( chunk_split( base64_encode( $xml ), 76, "\n" ) ), 'Line breaks in the base64 are fine.' );
		$this->assertSame( $xml, PleskLicence::normalise_key( $xml ), 'The XML itself stays.' );
		$this->assertSame( 'ABCD-1234-EFGH-5678-IJKL', PleskLicence::normalise_key( 'ABCD-1234-EFGH-5678-IJKL' ), 'An activation code stays.' );
		$not_xml = base64_encode( str_repeat( 'plain text, not a key file. ', 5 ) );
		$this->assertSame( $not_xml, PleskLicence::normalise_key( $not_xml ), 'Base64 of something that is not a key file is not guessed at.' );

		// End to end: the fake answers base64 like Openprovider; what is stored and what goes to the guest is the file, which the guest script installs.
		$o = $this->provisioned( 'pro' );
		$key = (string) Crypto::decrypt( (string) $o['licence']->get( 'plesk_key' ) );
		$this->assertStringStartsWith( '<', $key, 'The stored key is the XML, not the base64 line.' );
		$this->assertTrue( $o['licence']->set( array( 'plesk_key_job' => null ) ) );
		$this->assertTrue( $o['server']->set( array( 'app_state' => 'ready' ) ) );
		PleskLicence::run_key( $o['licence']->id() );
		$request = json_decode( (string) $this->fake->guest_file( 5000, PleskLicence::KEY_FILE ), true );
		$this->assertStringStartsWith( '<', $request['key'] );
		$this->assertSame( hash( 'sha256', $request['key'] ), $request['hash'] );

		// A key stored earlier as the base64 line (before this fix) is read as the file too.
		$this->assertTrue( $o['licence']->set( array( 'plesk_key' => Crypto::encrypt( base64_encode( $xml ) ), 'plesk_key_job' => null ) ) );
		PleskLicence::run_key( $o['licence']->id() );
		$again = json_decode( (string) $this->fake->guest_file( 5000, PleskLicence::KEY_FILE ), true );
		$this->assertSame( $xml, $again['key'] );
	}

	public function test_a_key_the_server_refuses_is_loud_on_the_server_and_a_corrected_key_gets_new_tries(): void {
		$mails = array();
		add_filter( 'pre_wp_mail', static function ( $pre, $args ) use ( &$mails ) { $mails[] = $args; return true; }, 10, 2 );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$o = $this->provisioned( 'pro' );
		$this->assertTrue( $o['server']->set( array( 'app_state' => 'ready' ) ) );
		$key = (string) Crypto::decrypt( (string) $o['licence']->get( 'plesk_key' ) );
		$this->assertTrue( $o['licence']->set( array( 'plesk_key_job' => null, 'plesk_key_pushes' => 3, 'plesk_key_tried' => hash( 'sha256', $key ), 'plesk_key_state' => 'applying', 'plesk_key_at' => time() - 3600 ) ) );
		$this->fake->set_guest_file( 5000, PleskLicence::STATUS_FILE, wp_json_encode( array( 'hash' => hash( 'sha256', $key ), 'state' => 'failed' ) ) );

		PleskLicence::run_key( $o['licence']->id() );

		$this->assertSame( 'failed', $this->fresh( $o['licence'] )->get( 'plesk_key_state' ) );
		$server = $this->fresh( $o['server'] );
		$this->assertStringContainsString( 'NOT installed', (string) $server->get( 'app_error' ), 'The server shows it in the admin box, not only a mail about the licence.' );
		$this->assertNotEmpty( array_filter( $mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'NOT installed' ) ) );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $mails ) );

		// The key is corrected (a different key text): it is tried afresh, not blocked by the old three.
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_key' => Crypto::encrypt( '<?xml version="1.0"?><plesk-key>' . str_repeat( 'y', 100 ) . '</plesk-key>' ) ) ) );
		$this->fake->set_guest_file( 5000, PleskLicence::KEY_FILE, '' );
		PleskLicence::run_key( $o['licence']->id() );
		$request = json_decode( (string) $this->fake->guest_file( 5000, PleskLicence::KEY_FILE ), true );
		$this->assertIsArray( $request );
		$this->assertStringContainsString( str_repeat( 'y', 100 ), $request['key'] );
		$this->assertSame( 1, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_pushes' ) );
		remove_all_filters( 'pre_wp_mail' );
	}

	// -- Tier switch, hold, cancel, delete ----------------------------------------------------------

	public function test_a_tier_switch_puts_the_new_item_and_fetches_the_key_again(): void {
		$o      = $this->provisioned( 'admin' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertSame( 'PLESK-12-VPS-WEB-ADMIN-1M', $this->licences()[ $key_id ]['items'][0] );

		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'variation_id' => $o['tiers']['host']->get_id() ) ) );
		Jobs::run( $o['licence']->id() );

		$puts = $this->op_calls( 'PUT', '/licenses/plesk/' . $key_id );
		$this->assertCount( 1, $puts );
		$this->assertSame( array( 'PLESK-12-VPS-WEB-HOST-1M' ), $puts[0]['body']['items'] );
		$this->assertSame( array( 'PLESK-12-VPS-WEB-HOST-1M' ), $this->licences()[ $key_id ]['items'] );
		$this->assertSame( 'PLESK-12-VPS-WEB-HOST-1M', $this->fresh( $o['licence'] )->get( 'plesk_item' ) );
		$this->assertGreaterThanOrEqual( 2, count( $this->op_calls( 'GET', '/licenses/plesk/key/' . $key_id ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertCount( 1, $this->op_calls( 'PUT', '/licenses/plesk/' . $key_id ), 'The same tier again is no change.' );
	}

	public function test_a_switch_to_a_tier_priced_below_its_cost_changes_nothing(): void {
		$o = $this->provisioned( 'admin' );
		$this->settings( array( 'plesk_costs' => "admin=6\nhost=99" ) );

		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'variation_id' => $o['tiers']['host']->get_id() ) ) );
		Jobs::run( $o['licence']->id() );

		$this->assertCount( 0, $this->op_calls( 'PUT' ) );
	}

	public function test_a_switch_refused_by_the_cost_guard_is_tried_again_after_the_costs_are_fixed(): void {
		$o      = $this->provisioned( 'admin' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->settings( array( 'plesk_costs' => "admin=6\nhost=99" ) );

		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'variation_id' => $o['tiers']['host']->get_id() ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertCount( 0, $this->op_calls( 'PUT' ) );
		$this->assertTrue( Jobs::pending_action( PleskLicence::SYNC_HOOK, array( $o['licence']->id() ) ), 'The refused switch is retried later.' );
		$this->assertSame( array( array( $o['licence']->id() ) ), PleskLicence::sync_due(), 'The watchdog lists a licence whose tier item differs.' );

		$this->settings( array( 'plesk_costs' => "admin=6\nhost=1" ) );
		PleskLicence::run_sync( $o['licence']->id() );
		$this->assertCount( 1, $this->op_calls( 'PUT', '/licenses/plesk/' . $key_id ) );
		$this->assertSame( array(), PleskLicence::sync_due() );
	}

	public function test_a_key_that_is_not_refetched_after_a_tier_switch_is_cleared_and_asked_again(): void {
		$o      = $this->provisioned( 'admin' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertNotSame( '', (string) $this->fresh( $o['licence'] )->get( 'plesk_key' ) );
		$this->op->reply( 'GET', '/licenses/plesk/key/' . $key_id, new \WP_Error( 'http_500', 'down', array( 'status' => 500 ) ) );

		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'variation_id' => $o['tiers']['host']->get_id() ) ) );
		Jobs::run( $o['licence']->id() );

		$this->assertCount( 1, $this->op_calls( 'PUT', '/licenses/plesk/' . $key_id ) );
		$this->assertSame( '', (string) $this->fresh( $o['licence'] )->get( 'plesk_key' ), 'The old edition key is not kept as the new one.' );
		$this->assertTrue( Jobs::pending_action( PleskLicence::KEY_HOOK, array( $o['licence']->id() ) ), 'The key is asked again.' );
		$this->assertSame( array( array( $o['licence']->id() ) ), PleskLicence::key_due() );
	}

	public function test_used_up_key_fetches_do_not_stop_the_deletion_of_a_ended_licence(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_key_tries' => 36 ) ) );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . $key_id, new \WP_Error( 'transport', 'down' ) );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) ) );
		Jobs::run( $o['licence']->id() );

		$this->assertSame( 1, (int) $this->fresh( $o['licence'] )->get( 'plesk_delete_tries' ) );
		$this->assertTrue( Jobs::pending_action( PleskLicence::SYNC_HOOK, array( $o['licence']->id() ) ), 'The deletion is retried.' );
		$this->assertSame( array( array( $o['licence']->id() ) ), PleskLicence::sync_due() );
	}

	public function test_a_hold_keeps_the_licence_and_a_resume_changes_nothing(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$license = (int) $o['licence']->license()['id'];

		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_SUSPENDED ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ) );
		$this->assertArrayHasKey( $key_id, $this->licences(), 'Kept while on hold.' );

		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_ACTIVE ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ) );
		$this->assertCount( 0, $this->op_calls( 'PUT' ) );
	}

	public function test_a_cancelled_licence_deletes_the_openprovider_licence_once(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );

		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) ) );
		Jobs::run( $o['licence']->id() );

		$this->assertCount( 1, $this->op_calls( 'DELETE', '/licenses/plesk/' . $key_id ) );
		$this->assertArrayNotHasKey( $key_id, $this->licences() );
		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 0, (int) $licence->get( 'plesk_key_id' ) );
		$this->assertSame( '', (string) $licence->get( 'plesk_key' ), 'The key is forgotten with the licence.' );
		$this->assertGreaterThan( 0, (int) $licence->get( 'plesk_deleted' ) );
		Jobs::run( $o['licence']->id() );
		$this->assertCount( 1, $this->op_calls( 'DELETE' ) );
	}

	public function test_a_lapsed_licence_keeps_the_vendor_licence_and_a_paid_renewal_needs_no_new_order(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$license = (int) $o['licence']->license()['id'];

		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_EXPIRED ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ), 'A lapse keeps the licence until retention.' );
		$this->assertSame( array(), PleskLicence::sync_due() );
		$this->assertSame( $key_id, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );

		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_ACTIVE ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ) );
		$this->assertCount( 1, $this->op_calls( 'POST', '/licenses/plesk' ), 'No second order.' );
		$this->assertSame( $key_id, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );
	}

	public function test_a_revoked_licence_that_is_given_back_orders_the_vendor_licence_again_on_resume(): void {
		$o      = $this->provisioned( 'pro' );
		$license = (int) $o['licence']->license()['id'];

		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_REVOKED ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertSame( 0, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );

		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_ACTIVE ) ) );
		Jobs::run( $o['licence']->id() );
		$licence = $this->fresh( $o['licence'] );
		$this->assertGreaterThan( 0, (int) $licence->get( 'plesk_key_id' ), (string) $licence->get( 'last_error' ) );
		$this->assertCount( 2, $this->op_calls( 'POST', '/licenses/plesk' ) );
		$this->assertSame( 'ready', $licence->get( 'state' ) );
	}

	public function test_a_failed_change_on_an_active_licence_never_deletes_the_vendor_licence(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$o['licence']->set( array( 'state' => 'failed', 'failed_op' => 'update_plan' ) );

		$this->assertSame( array(), PleskLicence::sync_due() );
		PleskLicence::sync( $this->fresh( $o['licence'] ) );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ) );
		$this->assertSame( $key_id, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );

		$this->fresh( $o['licence'] )->set( array( 'failed_op' => 'provision' ) );
		$this->assertSame( array( array( $o['licence']->id() ) ), PleskLicence::sync_due(), 'A provision that failed after the order is still released.' );
	}

	public function test_deleting_the_server_keeps_the_vendor_licence_while_the_licence_is_kept(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );

		do_action( 'beltoft_services_state_changed', $this->fresh( $o['server'] ), 'ready', 'deleted' );

		$this->assertCount( 0, $this->op_calls( 'DELETE' ), 'The vendor licence is not released while the licence is kept.' );
		$this->assertArrayHasKey( $key_id, $this->licences() );
		$this->assertSame( $key_id, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );
		$this->assertFalse( AppLicence::release_due( $this->fresh( $o['licence'] ) ) );
		$this->assertSame( array(), PleskLicence::sync_due() );
	}

	public function test_a_licence_that_cannot_be_kept_still_deletes_the_vendor_licence_with_its_server(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		wcs_get_subscription( (int) $o['licence']->license()['subscription_id'] )->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );

		do_action( 'beltoft_services_state_changed', $this->fresh( $o['server'] ), 'ready', 'deleted' );

		$this->assertCount( 1, $this->op_calls( 'DELETE', '/licenses/plesk/' . $key_id ) );
		$this->assertArrayNotHasKey( $key_id, $this->licences() );
	}

	public function test_a_licence_already_gone_counts_as_deleted(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . $key_id, new \WP_Error( 'op_3108', 'Registrar request failed.', array( 'status' => 404, 'desc' => 'License not found.' ) ) );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) ) );
		Jobs::run( $o['licence']->id() );
		$this->assertSame( 0, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );

	}

	public function test_a_failed_delete_is_scheduled_again_and_made_later(): void {
		$o2      = $this->provisioned( 'admin' );
		$key_id2 = (int) $o2['licence']->get( 'plesk_key_id' );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . $key_id2, new \WP_Error( 'transport', 'down' ) );
		$this->assertTrue( Licenses::update( (int) $o2['licence']->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) ) );
		Jobs::run( $o2['licence']->id() );
		$this->assertSame( $key_id2, (int) $this->fresh( $o2['licence'] )->get( 'plesk_key_id' ), 'Still known: it is tried again.' );
		$this->assertSame( 1, (int) $this->fresh( $o2['licence'] )->get( 'plesk_delete_tries' ) );
		PleskLicence::run_sync( $o2['licence']->id() );
		$this->assertSame( 0, (int) $this->fresh( $o2['licence'] )->get( 'plesk_key_id' ) );
	}

	// -- Reinstall ---------------------------------------------------------------------------------

	public function test_a_reinstall_that_keeps_plesk_resets_the_hardware_id_before_the_key_goes_on(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		do_action( 'beltoft_services_reinstall_started', $this->fresh( $o['server'] ), array() );
		$this->assertSame( 'active', AppLicence::state( $this->fresh( $o['licence'] ) ), 'The same app keeps its licence.' );
		$this->assertTrue( $this->fresh( $o['server'] )->set( array( 'app_state' => 'pending' ) ) );

		$result = AppInstall::queue( $this->fresh( $o['server'] ), $this->fake );

		$this->assertTrue( $result->is_done(), $result->error() );
		$resets = $this->op_calls( 'POST', '/licenses/hwids/reset/plesk/' . $key_id );
		$this->assertCount( 1, $resets );
		$this->assertSame( 1, $this->licences()[ $key_id ]['hwid_resets'] );
		$job = json_decode( (string) $this->fake->guest_file( 5000, Apps::JOB ), true );
		$this->assertNotSame( '', $job['licence']['key'] );
	}

	// -- A kept licence used on another server --------------------------------------------------------

	/**
	 * A Plesk licence kept after its server was deleted, and a second ready server of the same customer that runs Plesk and chose it.
	 *
	 * @return array{licence: Resource, server2: Resource, key_id: int, user: int}
	 */
	private function kept_for_new_server(): array {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		do_action( 'beltoft_services_state_changed', $this->fresh( $o['server'] ), 'ready', 'deleted' );
		$licence = $this->fresh( $o['licence'] );
		$this->assertTrue( \BeltoftServices\Apps\AppLicenceKeep::kept( $licence ) );

		$vm      = $this->vm_product();
		$order   = wc_create_order( array( 'customer_id' => $o['user'] ) );
		$order->add_product( $vm, 1 );
		$order->save();
		$row     = $this->service_license( $vm, array( 'fire' => false, 'user_id' => $o['user'], 'order_id' => $order->get_id(), 'email' => 'christian.beltoft@gmail.com' ) );
		$driver  = \BeltoftServices\Drivers\Registry::get( 'proxmox' );
		$server2 = Resource::create( $row, $driver, \BeltoftServices\Product::plan( $vm->get_id() ), \BeltoftServices\Lifecycle::initial_meta( $row, $driver ) );
		$this->assertSame( 5001, \BeltoftServices\Proxmox\Vm\VmRecords::allocate( $server2->id(), 'lab', 5001, 5001, array(), static fn() => true ) );
		$this->assertTrue( $server2->set( array( 'state' => 'ready', 'desired' => 'ready', 'ip' => '192.0.2.11', 'app_id' => 'plesk', 'app_version' => '18', 'app_state' => 'pending', OsField::META => 9001, \BeltoftServices\Apps\AppLicenceKeep::USE => $licence->id(), 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ) ) ) );
		\BeltoftServices\Proxmox\Network\IpPool::add( '192.0.2.11' );
		$this->assertNotNull( \BeltoftServices\Proxmox\Network\IpPool::hold( $server2->id() ) );
		$this->assertTrue( \BeltoftServices\Proxmox\Vm\VmRecords::move( $server2->id(), 'node-a' ) );
		$this->fake->add_vm( array( 'vmid' => 5001, 'node' => 'node-a', 'status' => 'running', 'config' => array( 'description' => \BeltoftServices\Proxmox\Pve\Ownership::tag( $server2 ), 'memory' => 4096, 'cores' => 2, 'agent' => '1' ) ) );
		$this->op_from = count( $this->op->calls() );

		return array( 'licence' => $licence, 'server2' => $this->fresh( $server2 ), 'key_id' => $key_id, 'user' => $o['user'] );
	}

	public function test_a_kept_licence_is_rebound_to_the_new_address_and_the_hardware_id_reset_before_the_key_goes_on(): void {
		$k = $this->kept_for_new_server();

		$result = AppInstall::queue( $k['server2'], $this->fake );

		$this->assertTrue( $result->is_done(), $result->error() );
		$puts = $this->op_calls( 'PUT', '/licenses/plesk/' . $k['key_id'] );
		$this->assertCount( 1, $puts );
		$this->assertSame( array( 'ip_address_binding' => '192.0.2.11', 'restrict_ip_binding' => true ), $puts[0]['body'], 'Only the address: no items and no period, which would order.' );
		$this->assertCount( 0, $this->op_calls( 'POST', '/licenses/plesk' ), 'Nothing is ordered.' );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ), 'Nothing is released.' );
		$this->assertCount( 1, $this->op_calls( 'POST', '/licenses/hwids/reset/plesk/' . $k['key_id'] ) );
		$this->assertSame( '192.0.2.11', $this->licences()[ $k['key_id'] ]['ip_address_binding'] );
		$job = json_decode( (string) $this->fake->guest_file( 5001, Apps::JOB ), true );
		$this->assertNotSame( '', $job['licence']['key'] );
		$this->assertSame( 'pro', $job['tier'] );
		$this->assertNull( $this->fresh( $k['licence'] )->get( 'plesk_rebind' ) );
	}

	public function test_a_temporary_rebind_error_waits_and_a_final_refusal_alerts_fails_and_loses_nothing(): void {
		$k = $this->kept_for_new_server();
		$this->op->reply( 'PUT', '/licenses/plesk/' . $k['key_id'], new \WP_Error( 'transport', 'down' ) );

		$wait = AppInstall::queue( $k['server2'], $this->fake );
		$this->assertFalse( $wait->is_done() || $wait->is_failed(), 'A temporary error is waited for.' );
		$this->assertSame( 1, (int) $this->fresh( $k['licence'] )->get( 'plesk_rebind' ) );

		$this->op->reply( 'PUT', '/licenses/plesk/' . $k['key_id'], new \WP_Error( 'op_3108', 'Registrar request failed.', array( 'status' => 404, 'desc' => 'License not found.' ) ) );
		$this->mails = array();
		$failed = AppInstall::queue( $this->fresh( $k['server2'] ), $this->fake );

		$this->assertTrue( $failed->is_failed() );
		$this->assertStringContainsString( 'could not be moved to this server', $failed->error() );
		$this->assertNotSame( array(), $this->mails, 'The admin is told.' );
		$licence = $this->fresh( $k['licence'] );
		$this->assertSame( (int) $k['server2']->license()['id'], (int) $licence->get( 'server_license' ), 'The licence stays on the server and is paid.' );
		$this->assertSame( 'active', AppLicence::state( $licence ) );
		$this->assertSame( 1, (int) $licence->get( 'plesk_rebind' ), 'The next try repeats it.' );
		$this->assertSame( $k['key_id'], (int) $licence->get( 'plesk_key_id' ) );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ) );

		// Fixed at the vendor: the next try works.
		$this->op->reply( 'PUT', '/licenses/plesk/' . $k['key_id'], array( 'success' => true ) );
		$again = AppInstall::queue( $this->fresh( $k['server2'] ), $this->fake );
		$this->assertTrue( $again->is_done(), $again->error() );
		$this->assertNull( $this->fresh( $k['licence'] )->get( 'plesk_rebind' ) );
	}

	public function test_a_kept_licence_that_ran_out_releases_the_vendor_licence_at_once(): void {
		$k = $this->kept_for_new_server();
		$this->assertTrue( Licenses::update( (int) $k['licence']->license()['id'], array( 'status' => Licenses::STATUS_EXPIRED ) ) );
		wcs_get_subscription( (int) $k['licence']->license()['subscription_id'] )->update_status( 'expired' );

		Jobs::run( $k['licence']->id() );

		$this->assertCount( 1, $this->op_calls( 'DELETE', '/licenses/plesk/' . $k['key_id'] ), 'Not kept through the retention days: its month is over.' );
		$this->assertArrayNotHasKey( $k['key_id'], $this->licences() );
		$this->assertSame( 0, (int) $this->fresh( $k['licence'] )->get( 'plesk_key_id' ) );
	}

	// -- The app, the product and the client ---------------------------------------------------------

	public function test_the_recipe_is_valid_and_its_checksum_matches(): void {
		$app = Apps::version( 'plesk', '18' );
		$this->assertNotNull( $app, 'The checksum in app.json matches the script.' );
		$this->assertSame( 'plesk', AppLicence::slug( 'plesk' ) );
		$this->assertTrue( AppLicence::paid( 'plesk' ) );
		$this->assertSame( array( 'ubuntu-24.04' ), $app['os'] );
		$this->assertSame( array( 'admin_user', 'admin_password' ), $app['credentials'] );
		$this->assertSame( array( 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 40 ), $app['min'] );

		$file = BELTOFT_SERVICES_PATH . 'apps/plesk/18/install.script';
		$line = hash_file( 'sha256', $file ) . '  plesk/18/install.script';
		$this->assertStringContainsString( $line, (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
		$check = array();
		$code  = 1;
		exec( 'bash -n ' . escapeshellarg( $file ), $check, $code );
		$this->assertSame( 0, $code );

		$script = (string) file_get_contents( $file );
		$raw    = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/plesk/app.json' );
		preg_match_all( '/ufw allow|for port in ([0-9 ]+); do/', $script, $m );
		$this->assertStringContainsString( 'for port in 22 80 443 8443; do', $script );
		$this->assertStringContainsString( 'plesk bin init_conf --init', $script );
		$this->assertStringContainsString( 'plesk bin license --install', $script );
		$this->assertStringContainsString( 'autoinstall.plesk.com/one-click-installer', $script );
		$this->assertStringContainsString( 'sha256sum -c', $script );
		$this->assertDoesNotMatchRegularExpression( '/curl[^\n]*\|\s*(?:sh|bash)|\becho\b[^\n]*(key|password)/i', $script );
		foreach ( array( $raw, $script ) as $text ) {
			$this->assertStringNotContainsString( "\u{2014}", $text );
			$this->assertStringNotContainsString( "\u{00B7}", $text );
			$this->assertStringNotContainsStringIgnoringCase( 'hetzner', $text );
		}
		$this->assertSame( array( 'os', 'packages', 'download', 'plesk', 'credentials', 'licence', 'firewall', 'verify', 'install', 'complete' ), $app['steps'] );
		$this->assertSame( array( 22, 80, 443, 8443 ), array_column( $app['ports'], 'port' ) );
	}

	public function test_the_app_tab_opens_plesk_over_https_on_port_8443(): void {
		$o = $this->provisioned( 'pro' );
		$this->assertTrue( $o['server']->set( array( 'ip' => '192.0.2.10', 'app_state' => 'ready' ) ) );

		$this->assertSame( 'https://192.0.2.10:8443/', AppTab::url( $this->fresh( $o['server'] ), Apps::definition( 'plesk' ) ) );
		$this->assertSame( 'http://192.0.2.10:8000/', AppTab::url( $this->fresh( $o['server'] ), array( 'open' => array( 'port' => 8000 ) ) ), 'Other apps are unchanged.' );
		$view = AppTab::view( $this->fresh( $o['server'] ) );
		$this->assertSame( 'active', $view['licence']['state'] );
		$this->assertSame( 'Web Pro', $view['licence']['tier'] );
		$this->assertSame( array( 'admin', 'pro', 'host' ), array_keys( AppLicence::tiers( 'plesk' ) ) );
	}

	public function test_the_product_command_makes_a_draft_without_prices_and_runs_once(): void {
		$id      = PleskProductCommand::ensure();
		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( 'plesk', get_post_meta( $id, \BeltoftLicenses\Software::META_SLUG, true ) );
		$this->assertSame( 'proxmox-app', get_post_meta( $id, \BeltoftServices\Product::META_DRIVER, true ) );
		$this->assertSame( 'plesk', get_post_meta( $id, \BeltoftServices\Product::META_PLAN . 'app', true ) );
		$tiers = array();
		foreach ( $product->get_children() as $vid ) {
			$this->assertSame( '', (string) get_post_meta( $vid, '_regular_price', true ), 'No price is set.' );
			$this->assertSame( '', (string) get_post_meta( $vid, '_subscription_price', true ) );
			$tiers[ (string) get_post_meta( $vid, \BeltoftServices\Product::META_PLAN . 'tier', true ) ] = (string) get_post_meta( $vid, \BeltoftServices\Product::META_PLAN . 'tier_label', true );
		}
		$this->assertSame( array( 'admin' => 'Web Admin', 'pro' => 'Web Pro', 'host' => 'Web Host' ), $tiers );
		$this->assertSame( $id, PleskProductCommand::ensure(), 'Run twice, one product.' );
	}

	public function test_the_items_and_costs_follow_the_settings_and_the_filters(): void {
		$this->assertSame( 'PLESK-12-VPS-WEB-HOST-1M', PleskLicence::items()['host'] );
		$this->settings( array( 'plesk_items' => "pro=PLESK-TEST-1M\nbad=not valid!" ) );
		$this->assertSame( 'PLESK-TEST-1M', PleskLicence::items()['pro'] );
		$this->assertArrayNotHasKey( 'bad', PleskLicence::items() );
		$this->assertSame( 10.0, PleskLicence::costs()['pro'] );
		add_filter( 'beltoft_services_plesk_costs', static fn( $c ) => $c + array( 'extra' => 1 ) );
		$this->assertSame( 1.0, PleskLicence::costs()['extra'] );
		remove_all_filters( 'beltoft_services_plesk_costs' );
	}

	public function test_the_live_client_deletes_only_a_plesk_licence_and_never_a_domain(): void {
		$client = new LiveClient( 'sandbox', '', '' );

		foreach ( array( '/licenses/plesk/1; rm', '/domains/1', '/domains/example.com', '/licenses/plesk/key/1', '/licenses/plesk' ) as $path ) {
			$this->assertSame( 'bad_request', $client->request( 'DELETE', $path )->get_error_code(), $path );
		}
		$this->assertSame( 'bad_request', $client->request( 'TRACE', '/licenses/plesk/1' )->get_error_code() );
		$this->assertSame( 'credentials_missing', $client->request( 'DELETE', '/licenses/plesk/1' )->get_error_code(), 'A licence delete is allowed; it stops at the missing login, before any request.' );
	}

	public function test_the_licence_api_wants_item_ids_and_a_period_and_reports_a_missing_licence_as_op_3108(): void {
		// As the Openprovider sandbox answers (checked live): a SKU is no item, a period is required, a missing key is op_3108.
		$sku = $this->op->request( 'POST', '/licenses/plesk', array( 'title' => 't', 'items' => array( 'PLSKVPS-ADM-M' ), 'period' => 1 ) );
		$this->assertWPError( $sku );
		$this->assertSame( 'op_3103', $sku->get_error_code() );

		$none = $this->op->request( 'POST', '/licenses/plesk', array( 'title' => 't', 'items' => array( 'PLESK-12-VPS-WEB-ADMIN-1M' ) ) );
		$this->assertSame( 'op_3105', $none->get_error_code() );

		$missing = $this->op->request( 'DELETE', '/licenses/plesk/999999' );
		$this->assertSame( 'op_3108', $missing->get_error_code() );
		$this->assertTrue( PleskLicence::gone( $missing ) );
		$this->assertTrue( PleskLicence::gone( new \WP_Error( 'http_404', 'x', array( 'status' => 404 ) ) ) );
		$this->assertFalse( PleskLicence::gone( new \WP_Error( 'op_199', 'x', array( 'status' => 500 ) ) ), 'An unknown error is not a gone licence.' );

		// The three editions are the item ids of the sandbox's listing, not the price list's SKUs.
		$this->assertSame( array( 'admin' => 'PLESK-12-VPS-WEB-ADMIN-1M', 'pro' => 'PLESK-12-VPS-WEB-PRO-1M', 'host' => 'PLESK-12-VPS-WEB-HOST-1M' ), PleskLicence::items() );
	}

	public function test_the_product_command_sets_the_given_prices_and_stays_a_draft(): void {
		$this->assertSame( array( 'admin' => '17', 'pro' => '30', 'host' => '60' ), PleskProductCommand::parse_prices( 'admin:17, pro:30,host:60' ) );
		$this->assertSame( array( 'pro' => '14.5' ), PleskProductCommand::parse_prices( 'pro:14.50' ) );

		foreach ( array( 'gold:10', 'pro:abc', 'pro:0', 'pro:-3', 'pro', 'pro:1.234' ) as $bad ) {
			$this->assertWPError( PleskProductCommand::parse_prices( $bad ), $bad );
		}

		$id      = PleskProductCommand::create( array( 'admin' => '17', 'pro' => '30', 'host' => '60' ) );
		$product = wc_get_product( $id );
		$this->assertSame( 'draft', $product->get_status() );

		$prices = array();
		foreach ( $product->get_children() as $vid ) {
			$v                                                  = wc_get_product( $vid );
			$prices[ (string) $v->get_meta( '_beltoft_services_plan_tier' ) ] = array( $v->get_regular_price(), get_post_meta( $vid, '_subscription_price', true ) );
		}
		$this->assertSame( array( 'admin' => array( '17', '17' ), 'pro' => array( '30', '30' ), 'host' => array( '60', '60' ) ), $prices );

		// Run twice: the command refuses when the product exists, so nothing doubles.
		$this->assertSame( $id, PleskProductCommand::ensure() );
	}

	public function test_an_order_in_another_currency_is_compared_at_the_guard_rate_and_refused_without_one(): void {
		$o = $this->plesk_order( 'pro' );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->settings( array( 'domain_api_mode' => 'fake', 'admin_email' => 'christian.beltoft@gmail.com', 'currency_guard_rates' => '' ) );
		$o['order']->set_currency( 'DKK' );
		$o['order']->save();
		$licence = $this->fresh( $o['licence'] );
		$paid    = (float) \BeltoftServices\Domains\SpendGuard::item_of_resource( $licence )->get_total();

		$this->settings( array( 'plesk_costs' => 'pro=10' ) );
		$this->assertStringContainsString( 'not in EUR', (string) PleskLicence::spend_problem( $licence, 'pro' ), 'No guard rate: refused.' );

		$this->settings( array( 'currency_guard_rates' => 'DKK=8', 'plesk_costs' => sprintf( 'pro=%.2f', $paid / 8 - 0.5 ) ) );
		$this->assertNull( PleskLicence::spend_problem( $licence, 'pro' ), 'Paid more than the cost at the guard rate.' );

		$this->settings( array( 'plesk_costs' => sprintf( 'pro=%.2f', $paid / 8 + 0.5 ) ) );
		$this->assertStringContainsString( 'below the Openprovider cost', (string) PleskLicence::spend_problem( $licence, 'pro' ) );
		remove_filter( 'pre_wp_mail', '__return_true' );
	}

	// -- Characterisation of the vendor calls (licence vendor layer, step S0): pins today's behaviour ---------------

	/**
	 * Seconds until the next job of a hook for a licence, or null when none waits.
	 *
	 * @param string $hook Hook.
	 * @param int    $id   Licence resource id.
	 */
	private function due_in( string $hook, int $id ): ?int {
		$at = as_next_scheduled_action( $hook, array( $id ), Jobs::GROUP );

		return is_int( $at ) ? $at - time() : null;
	}

	private function clear_jobs(): void {
		as_unschedule_all_actions( PleskLicence::KEY_HOOK );
		as_unschedule_all_actions( PleskLicence::SYNC_HOOK );
	}

	public function test_s0_an_error_on_the_find_and_on_the_order_alerts_or_retries_by_kind(): void {
		$o      = $this->plesk_order( 'pro' );
		$server = $o['server'];
		$err    = new \WP_Error( 'op_199', 'x', array( 'status' => 400, 'desc' => 'nope' ) );

		$this->op->reply( 'GET', '/licenses/plesk', $err );
		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $server, 'plesk' );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'op_199: nope', (string) $this->mails[0]['message'] );

		$this->mails = array();
		$this->op->reply( 'GET', '/licenses/plesk', new \WP_Error( 'transport', 'down' ) );
		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $server, 'plesk' );
		$this->assertSame( 300, $result->delay() );
		$this->assertSame( array(), $this->mails, 'A temporary error tells nobody.' );

		$this->op->reply( 'POST', '/licenses/plesk', $err );
		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $server, 'plesk' );
		$this->assertSame( 3600, $result->delay() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Openprovider did not order the Plesk licence. (op_199: nope)', (string) $this->mails[0]['message'] );

		$this->mails = array();
		$this->op->reply( 'POST', '/licenses/plesk', new \WP_Error( 'http_503', 'down', array( 'status' => 503 ) ) );
		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $server, 'plesk' );
		$this->assertSame( 300, $result->delay() );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 0, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );
	}

	public function test_s0_a_failed_item_change_is_counted_and_scheduled_not_alerted_at_the_first_try(): void {
		$o      = $this->provisioned( 'admin' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->clear_jobs();
		$this->mails = array();
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'variation_id' => $o['tiers']['host']->get_id() ) ) );
		$this->clear_jobs();
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_sync_tries' => null ) ) );
		$this->op->reply( 'PUT', '/licenses/plesk/' . $key_id, new \WP_Error( 'op_199', 'x', array( 'status' => 400, 'desc' => 'nope' ) ) );

		$this->assertFalse( PleskLicence::sync( $this->fresh( $o['licence'] ) ) );

		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 1, (int) $licence->get( 'plesk_sync_tries' ), 'A failed PUT is counted, it does not go through the step failure path.' );
		$this->assertSame( 'PLESK-12-VPS-WEB-ADMIN-1M', $licence->get( 'plesk_item' ) );
		$this->assertNotEmpty( (string) $licence->get( 'plesk_key' ), 'A failed change keeps the key.' );
		$this->assertEqualsWithDelta( 300, (int) $this->due_in( PleskLicence::SYNC_HOOK, $licence->id() ), 5 );
		$this->assertSame( array(), $this->mails, 'The alert comes at the twelfth try.' );
		$this->assertCount( 0, $this->op_calls( 'DELETE' ) );
	}

	public function test_s0_the_key_asks_alert_at_twelve_and_stop_at_thirty_six_with_the_delays(): void {
		$o  = $this->provisioned( 'pro' );
		$id = $o['licence']->id();
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_key' => null ) ) );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		for ( $i = 0; $i < 8; $i++ ) {
			$this->op->reply( 'GET', '/licenses/plesk/key/' . $key_id, array() );
		}

		foreach ( array( array( 0, 120 ), array( 10, 1320 ), array( 11, 1440 ), array( 34, 4200 ), array( 35, null ) ) as list( $before, $delay ) ) {
			$this->clear_jobs();
			$this->mails = array();
			$tries       = $before;
			$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_key_tries' => $tries ) ) );
			PleskLicence::run_key( $id );
			$this->assertSame( $tries + 1, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_tries' ) );

			if ( null === $delay ) {
				$this->assertNull( $this->due_in( PleskLicence::KEY_HOOK, $id ), 'The thirty sixth ask is the last.' );
			} else {
				$this->assertEqualsWithDelta( $delay, (int) $this->due_in( PleskLicence::KEY_HOOK, $id ), 5, 'after ' . $tries );
			}

			$this->assertCount( 11 === $before ? 1 : 0, $this->mails, 'Staff are told exactly at the twelfth ask (try ' . ( $tries + 1 ) . ').' );
		}
	}

	public function test_s0_the_delete_counter_alerts_at_twelve_and_waits_longer_each_time_and_stops_at_thirty_six(): void {
		$o      = $this->provisioned( 'pro' );
		$id     = $o['licence']->id();
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) ) );

		foreach ( array( array( 0, 300, 0 ), array( 11, 43200, 1 ), array( 34, 86400, 0 ), array( 35, null, 0 ) ) as list( $before, $delay, $mails ) ) {
			$this->clear_jobs();
			$this->mails = array();
			$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_key_id' => $key_id, 'plesk_delete_tries' => $before ) ) );
			$this->op->reply( 'DELETE', '/licenses/plesk/' . $key_id, new \WP_Error( 'transport', 'down' ) );
			$this->assertFalse( PleskLicence::sync( $this->fresh( $o['licence'] ) ) );
			$this->assertSame( $before + 1, (int) $this->fresh( $o['licence'] )->get( 'plesk_delete_tries' ) );

			if ( null === $delay ) {
				$this->assertNull( $this->due_in( PleskLicence::SYNC_HOOK, $id ) );
			} else {
				$this->assertEqualsWithDelta( $delay, (int) $this->due_in( PleskLicence::SYNC_HOOK, $id ), 5, 'after ' . $before );
			}

			$this->assertCount( $mails, $this->mails );
		}
	}

	public function test_s0_a_refused_switch_alerts_at_the_first_try_then_hourly_then_daily_and_stops_at_thirty_six(): void {
		$o  = $this->provisioned( 'admin' );
		$id = $o['licence']->id();
		$this->settings( array( 'plesk_costs' => "admin=6\nhost=99" ) );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'variation_id' => $o['tiers']['host']->get_id() ) ) );

		foreach ( array( array( 0, 3600, 1 ), array( 5, 3600, 0 ), array( 11, 86400, 0 ), array( 34, 86400, 0 ), array( 35, null, 0 ) ) as list( $before, $delay, $mails ) ) {
			$this->clear_jobs();
			$this->mails = array();
			$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_sync_tries' => $before, 'alerted' => null ) ) );
			$this->assertFalse( PleskLicence::sync( $this->fresh( $o['licence'] ) ) );
			$this->assertSame( $before + 1, (int) $this->fresh( $o['licence'] )->get( 'plesk_sync_tries' ) );

			if ( null === $delay ) {
				$this->assertNull( $this->due_in( PleskLicence::SYNC_HOOK, $id ) );
			} else {
				$this->assertEqualsWithDelta( $delay, (int) $this->due_in( PleskLicence::SYNC_HOOK, $id ), 5, 'after ' . $before );
			}

			$this->assertCount( $mails, $this->mails, 'try ' . ( $before + 1 ) );
		}

		$this->assertCount( 0, $this->op_calls( 'PUT' ) );
	}

	public function test_s0_the_order_body_and_the_title_are_exactly_these_strings(): void {
		$o       = $this->provisioned( 'pro' );
		$license = (int) $o['licence']->license()['id'];
		$posts   = $this->op_calls( 'POST', '/licenses/plesk' );

		$this->assertCount( 1, $posts );
		$this->assertEquals(
			array(
				'title'               => 'licence ' . $license . ' server',
				'comment'             => sprintf( 'Beltoft Services licence %d, server %d', $license, (int) $o['server']->license()['id'] ),
				'items'               => array( 'PLESK-12-VPS-WEB-PRO-1M' ),
				'period'              => 1,
				'ip_address_binding'  => '192.0.2.10',
				'restrict_ip_binding' => true,
			),
			$posts[0]['body']
		);
		$this->assertSame( 'licence ' . $license . ' server', $this->fresh( $o['licence'] )->get( 'plesk_title' ) );
		$find = $this->op_calls( 'GET', '/licenses/plesk' );
		$this->assertSame( array( 'title_pattern' => 'licence ' . $license . ' server', 'limit' => 10 ), $find[0]['query'] );
	}

	public function test_s0_a_missing_item_fails_and_cancels_the_subscription_and_orders_nothing(): void {
		add_filter( 'beltoft_services_plesk_items', static function ( $items ) { unset( $items['pro'] ); return $items; } );
		$o  = $this->plesk_order( 'pro' );
		$id = (int) ( $o['licence']->license()['subscription_id'] ?? 0 );

		Jobs::run( $o['licence']->id() );

		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 'failed', $licence->get( 'state' ) );
		$this->assertStringContainsString( 'No Openprovider item is set for the Plesk tier "pro"', (string) $licence->get( 'last_error' ) );
		$this->assertCount( 0, $this->op_calls( 'POST' ) );
		$this->assertCount( 0, $this->op_calls( 'GET' ) );
		$this->assertSame( 'cancelled', wcs_get_subscription( $id )->get_status() );
		remove_all_filters( 'beltoft_services_plesk_items' );
	}

	public function test_s0_without_the_encryption_key_nothing_is_ordered_and_it_is_tried_again_in_an_hour(): void {
		$o = $this->plesk_order( 'pro' );
		remove_all_filters( 'beltoft_services_key' );
		add_filter( 'beltoft_services_key', '__return_empty_string' );
		$this->assertFalse( \BeltoftServices\Crypto::available() );

		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $o['server'], 'plesk' );

		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertSame( array(), $this->op->calls(), 'Not even a look at Openprovider.' );
		remove_all_filters( 'beltoft_services_key' );
	}

	public function test_s0_a_server_without_an_address_is_waited_for_until_the_deadline_and_then_fails_and_cancels(): void {
		$o = $this->plesk_order( 'pro' );
		\BeltoftServices\Proxmox\Network\IpPool::release( $o['server']->id() );
		$id = (int) ( $o['licence']->license()['subscription_id'] ?? 0 );

		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $o['server'], 'plesk' );
		$until  = (int) $this->fresh( $o['licence'] )->get( 'plesk_wait_until' );
		$this->assertFalse( $result->is_failed() );
		$this->assertSame( $until, $result->wait_until() );
		$this->assertSame( 60, $result->delay() );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $until, 5 );

		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_wait_until' => time() + 60 ) ) );
		$this->assertFalse( PleskLicence::provision( null, $this->fresh( $o['licence'] ), $o['server'], 'plesk' )->is_failed(), 'Still waiting a minute before the deadline.' );

		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_wait_until' => time() - 1 ) ) );
		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $o['server'], 'plesk' );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'The server has no public address, so no Plesk licence was ordered.', $result->error() );
		$this->assertSame( array(), $this->op_calls( 'POST' ) );

		Jobs::run( $o['licence']->id() );
		$this->assertSame( 'failed', $this->fresh( $o['licence'] )->get( 'state' ) );
		$this->assertSame( 'cancelled', wcs_get_subscription( $id )->get_status() );
	}

	public function test_s0_a_successful_delete_clears_exactly_these_meta_values_and_sets_the_mark(): void {
		$o = $this->provisioned( 'pro' );
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_key_job' => 'h', 'plesk_key_state' => 'installed', 'plesk_sync_tries' => 2, 'plesk_delete_tries' => 3, 'plesk_key_tries' => 4, 'plesk_key_pushes' => 1 ) ) );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) ) );

		Jobs::run( $o['licence']->id() );

		$licence = $this->fresh( $o['licence'] );

		foreach ( array( 'plesk_key_id', 'plesk_key', 'plesk_key_job', 'plesk_key_state', 'plesk_sync_tries', 'plesk_delete_tries' ) as $name ) {
			$this->assertSame( '', (string) $licence->get( $name ), $name );
		}

		$this->assertGreaterThan( 0, (int) $licence->get( 'plesk_deleted' ) );
		$this->assertSame( 'PLESK-12-VPS-WEB-PRO-1M', $licence->get( 'plesk_item' ), 'The item and the title stay as a record.' );
		$this->assertNotSame( '', (string) $licence->get( 'plesk_title' ) );
		$this->assertSame( 4, (int) $licence->get( 'plesk_key_tries' ), 'The key asks counter is not part of a delete.' );
	}

	public function test_s0_a_failed_vendor_delete_does_not_hold_up_the_delete_of_the_licence_resource(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'state' => 'deleting' ) ) );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . $key_id, new \WP_Error( 'transport', 'down' ) );

		$result = ( new \BeltoftServices\Apps\AppLicenceDriver() )->delete( $this->fresh( $o['licence'] ) );

		$this->assertTrue( $result->is_done(), 'The server cascade is not blocked; a job and the watchdog repeat the delete.' );
		$this->assertSame( $key_id, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );
		$this->assertTrue( Jobs::pending_action( PleskLicence::SYNC_HOOK, array( $o['licence']->id() ) ) );
	}

	public function test_s0_the_key_is_fetched_with_one_request_and_the_licence_record_is_not_read(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );

		$this->assertCount( 1, $this->op_calls( 'GET', '/licenses/plesk/key/' . $key_id ) );
		$this->assertCount( 0, $this->op_calls( 'GET', '/licenses/plesk/' . $key_id ) );
		$this->assertSame( '', (string) $this->fresh( $o['licence'] )->get( 'plesk_shape' ) );
	}

	public function test_s9_a_licence_whose_retries_ran_out_before_anything_was_ordered_cancels_its_subscription(): void {
		// Retries that run out (APPS-08): the licence fails and, as nothing was ordered, the subscription is cancelled.
		$o  = $this->plesk_order( 'pro' );
		$id = (int) ( $o['licence']->license()['subscription_id'] ?? 0 );
		for ( $i = 0; $i < 12; $i++ ) {
			$this->op->reply( 'POST', '/licenses/plesk', new \WP_Error( 'transport', 'down' ) );
		}

		for ( $i = 0; $i < 8 && 'failed' !== $this->fresh( $o['licence'] )->get( 'state' ); $i++ ) {
			$this->fresh( $o['licence'] )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $o['licence']->id() );
		}

		$this->assertSame( 'failed', $this->fresh( $o['licence'] )->get( 'state' ) );
		$this->assertSame( 0, (int) $this->fresh( $o['licence'] )->get( 'plesk_key_id' ) );
		$this->assertSame( 'cancelled', wcs_get_subscription( $id )->get_status() );
	}

	public function test_s0_release_due_for_every_resource_state_and_licence_status(): void {
		$o       = $this->provisioned( 'pro' );
		$license = (int) $o['licence']->license()['id'];
		$states  = array(
			'ready'              => array( 'ready', '' ),
			'deleting'           => array( 'deleting', '' ),
			'deleted'            => array( 'deleted', '' ),
			'failed provision'   => array( 'failed', 'provision' ),
			'failed delete'      => array( 'failed', 'delete' ),
			'failed update_plan' => array( 'failed', 'update_plan' ),
			'failed resume'      => array( 'failed', 'resume' ),
			'failed pause'       => array( 'failed', 'pause' ),
		);
		$gone    = array( 'deleting', 'deleted', 'failed provision', 'failed delete' );

		foreach ( array( Licenses::STATUS_ACTIVE, Licenses::STATUS_SUSPENDED, Licenses::STATUS_EXPIRED, Licenses::STATUS_REVOKED ) as $status ) {
			$this->assertTrue( Licenses::update( $license, array( 'status' => $status ) ) );

			foreach ( $states as $name => list( $state, $op ) ) {
				$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'state' => $state, 'failed_op' => $op ) ) );
				$expected = in_array( $name, $gone, true ) || Licenses::STATUS_REVOKED === $status;
				$this->assertSame( $expected, AppLicence::release_due( $this->fresh( $o['licence'] ) ), $name . ' / ' . $status );
			}
		}

		// A lapse (expired, on hold) of a working licence keeps the Plesk licence; the ended mark of a deleted server releases it.
		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_ACTIVE ) ) );
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'state' => 'ready', 'failed_op' => '' ) ) );
		$this->assertFalse( AppLicence::release_due( $this->fresh( $o['licence'] ) ) );
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( AppLicence::ENDED => 'server deleted' ) ) );
		$this->assertTrue( AppLicence::release_due( $this->fresh( $o['licence'] ) ) );
	}

	public function test_a_tier_key_of_up_to_forty_characters_is_read_like_the_key_catalogue_reads_it(): void {
		$long = str_repeat( 'a', 40 );
		$this->settings( array( 'plesk_items' => $long . "=LONG-ITEM-1M\n" . str_repeat( 'b', 41 ) . '=TOO-LONG-1M', 'plesk_costs' => $long . '=3.5' ) );

		$this->assertSame( 'LONG-ITEM-1M', PleskLicence::items()[ $long ] );
		$this->assertArrayNotHasKey( str_repeat( 'b', 41 ), PleskLicence::items() );
		$this->assertSame( 3.5, PleskLicence::costs()[ $long ] );
		$this->assertSame( array( $long, 'admin', 'pro', 'host' ), array_keys( PleskLicence::items() ), 'The setting comes first, as before.' );
		$this->assertSame( \BeltoftServices\Licences\KeyCatalog::lines( "x=1\n" . $long . '=2' ), \BeltoftServices\Domains\LicenceCatalog::lines( "x=1\n" . $long . '=2' ) );
	}

	/**
	 * One cost (10 EUR) against nine paid lines: below, equal, inside the one cent tolerance, above, another currency with and
	 * without a guard rate (both sides of the cost), no line at all, and an unknown cost on the fake and on the sandbox client.
	 *
	 * @return array<int, array{0: string, 1: string, 2: float, 3: ?string}> label, currency, paid, expected message part (null: covered)
	 */
	private function spend_table(): array {
		return array(
			array( 'below', 'EUR', 9.98, 'paid below the Openprovider cost' ),
			array( 'equal', 'EUR', 10.0, null ),
			array( 'inside the tolerance', 'EUR', 9.995, null ),
			array( 'above', 'EUR', 12.0, null ),
			array( 'DKK without a guard rate', 'DKK', 100.0, 'not in EUR' ),
			array( 'DKK above at the guard rate', 'DKK', 100.0, null ),
			array( 'DKK below at the guard rate', 'DKK', 70.0, 'paid below the Openprovider cost' ),
		);
	}

	public function test_s3_the_spend_check_table_for_the_plesk_family(): void {
		$o = $this->plesk_order( 'pro' );
		$this->settings( array( 'plesk_costs' => 'pro=10', 'currency_guard_rates' => '' ) );
		add_filter( 'pre_wp_mail', '__return_true' );

		foreach ( $this->spend_table() as $i => list( $label, $currency, $paid, $expected ) ) {
			$licence = $this->fresh( $o['licence'] );
			$item    = \BeltoftServices\Domains\SpendGuard::item_of_resource( $licence );
			$item->set_total( $paid );
			$item->save();
			$order = $item->get_order();
			$order->set_currency( $currency );
			$order->save();
			$this->settings( array( 'currency_guard_rates' => 'DKK' === $currency && $i >= 5 ? 'DKK=8' : '' ) );
			$problem = PleskLicence::spend_problem( $licence, 'pro' );

			if ( null === $expected ) {
				$this->assertNull( $problem, $label );
			} else {
				$this->assertStringContainsString( $expected, (string) $problem, $label );
			}
		}

		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'order_item_id' => 0 ) ) );
		$this->assertSame( 'The paid order line for this Plesk licence is unavailable.', PleskLicence::spend_problem( $this->fresh( $o['licence'] ), 'pro' ) );

		$this->settings( array( 'plesk_costs' => '', 'domain_api_mode' => 'fake' ) );
		$this->assertNull( PleskLicence::spend_problem( $this->fresh( $o['licence'] ), 'pro' ) );
		$this->settings( array( 'domain_api_mode' => 'sandbox' ) );
		$this->assertSame( 'The Openprovider cost of this Plesk tier is not set.', PleskLicence::spend_problem( $this->fresh( $o['licence'] ), 'pro' ) );
		remove_filter( 'pre_wp_mail', '__return_true' );
	}

	public function test_s4_a_licence_found_by_its_title_makes_no_spend_check_even_when_the_cost_is_too_high(): void {
		$this->settings( array( 'plesk_costs' => 'pro=15' ) );
		$o     = $this->plesk_order( 'pro' );
		$title = 'licence ' . (int) $o['licence']->license()['id'] . ' server';
		$made  = $this->op->request( 'POST', '/licenses/plesk', array( 'title' => $title, 'items' => array( 'PLESK-12-VPS-WEB-PRO-1M' ), 'period' => 1, 'ip_address_binding' => '192.0.2.10' ) );
		$before = count( $this->op_calls( 'POST', '/licenses/plesk' ) );

		$result = PleskLicence::provision( null, $this->fresh( $o['licence'] ), $o['server'], 'plesk' );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( (int) $made['key_id'], (int) $result->meta()['plesk_key_id'] );
		$this->assertCount( $before, $this->op_calls( 'POST', '/licenses/plesk' ) );
		$this->assertSame( array(), $this->mails, 'A found licence is not a spend, so no refusal is mailed.' );
	}

	public function test_s7_the_twelfth_failed_delete_is_told_in_the_one_wording_of_both_families(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) ) );
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_delete_tries' => 11 ) ) );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . $key_id, new \WP_Error( 'transport', 'down' ) );
		$this->mails = array();

		$this->assertFalse( PleskLicence::sync( $this->fresh( $o['licence'] ) ) );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Openprovider did not answer the licence deletion after 12 tries (transport). Delete the licence at Openprovider, or it keeps billing.', (string) $this->mails[0]['message'] );
	}

	public function test_s8_the_vendor_id_of_a_servers_plesk_licence_is_its_id_minus_one_while_ordering_and_zero_when_none(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );
		$this->assertGreaterThan( 0, $key_id );
		$this->assertSame( $key_id, PleskLicence::vendor_id_for_server( $this->fresh( $o['server'] ) ) );

		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'plesk_key_id' => null ) ) );
		$this->assertSame( -1, PleskLicence::vendor_id_for_server( $this->fresh( $o['server'] ) ), 'A live licence that is not ordered yet.' );

		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( AppLicence::ENDED => 'server deleted' ) ) );
		$this->assertSame( 0, PleskLicence::vendor_id_for_server( $this->fresh( $o['server'] ) ), 'An ended licence is no parent.' );
	}
}

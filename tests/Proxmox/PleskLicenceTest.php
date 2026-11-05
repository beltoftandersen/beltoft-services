<?php
/** Plesk as a paid app: the Openprovider licence behind a server's tier. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Cli\PleskProductCommand;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\FakeClient as Registrar;
use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\AppInstall;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppTab;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\PleskLicence;
use BeltoftServices\Resource;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * No network: the Openprovider fake client (documented replies, made-up values) and the Proxmox fake only.
 */
final class PleskLicenceTest extends PaidAppCase {

	private Registrar $op;

	public function set_up(): void {
		parent::set_up();
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
		return array_values( array_filter( $this->op->calls(), static fn( $c ) => $c['method'] === $method && ( '' === $path || $c['path'] === $path ) ) );
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
		\BeltoftServices\Proxmox\IpPool::release( $o['server']->id() );

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
		( new \BeltoftServices\Proxmox\AppLicenceDriver() )->render_admin( $o['licence'] );
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

	public function test_deleting_the_server_deletes_the_licence(): void {
		$o      = $this->provisioned( 'pro' );
		$key_id = (int) $o['licence']->get( 'plesk_key_id' );

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
		$this->assertSame( 1, (int) $this->fresh( $o2['licence'] )->get( 'plesk_sync_tries' ) );
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
}

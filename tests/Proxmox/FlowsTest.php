<?php
/**
 * Flow intake, detection and the network quarantine. Fake client only; mails go to the owner's address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Admin\Panel;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\Flows;
use BeltoftServices\Proxmox\Quarantine;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

require_once __DIR__ . '/FirewallTestCase.php';

class FlowsTest extends FirewallTestCase {

	private const OWNER  = 'christian.beltoft@gmail.com';
	private const SECRET_VALUE = 'aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44';

	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'BELTOFT_SERVICES_FLOW_SECRET' ) ) {
			define( 'BELTOFT_SERVICES_FLOW_SECRET', self::SECRET_VALUE );
		}

		$this->mails = array();
		add_filter( 'pre_wp_mail', function ( $pre, $args ) { $this->mails[] = $args; return true; }, 10, 2 );
		$this->settings( array( 'admin_email' => self::OWNER, 'traffic_quarantine' => 'report', 'flow_sources' => '10.10.10.1', 'pause_all' => 0 ) );
		delete_transient( Flows::SEEN_TRANSIENT );
		$_SERVER['REMOTE_ADDR'] = '10.10.10.1';
	}

	// -- Helpers -------------------------------------------------------------------------------------

	/** A ready server whose license mail goes to the owner. */
	private function server( array $meta = array() ): Resource {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );
		Jobs::run( $resource->id() );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->mails = array();

		return $fresh;
	}

	private function counts( array $over = array() ): array {
		return array_merge( array( 'entries' => 40, 'dsts' => 10, 'dports' => 5, 'unreplied' => 0, 'udp_dsts' => 0 ), $over );
	}

	/** Posts a signed body from the sender address. */
	private function post( array $payload, ?string $signature = null, ?string $body = null ): \WP_REST_Response {
		$body    = $body ?? (string) wp_json_encode( $payload );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/flows' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-beltoft-signature', $signature ?? hash_hmac( 'sha256', $body, self::SECRET_VALUE ) );
		$request->set_body( $body );

		return rest_get_server()->dispatch( $request );
	}

	private function payload( array $sources, ?int $at = null ): array {
		return array( 'v' => 1, 'at' => $at ?? time(), 'sources' => $sources ?: new \stdClass() );
	}

	/** Feeds $minutes one-minute samples straight to the detection, ending now. */
	private function feed( Resource $r, int $minutes, array $counts, int $end = 0 ): void {
		$end = $end ?: time();

		for ( $i = $minutes - 1; $i >= 0; $i-- ) {
			Flows::ingest( $r, $end - $i * 60, $counts );
		}
	}

	private function mails_with( string $needle ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['subject'], $needle ) ) );
	}

	private function options( Resource $r ): array {
		return $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_options'];
	}

	// -- The route -----------------------------------------------------------------------------------

	public function test_a_signed_body_from_the_sender_is_accepted(): void {
		$r        = $this->server();
		$response = $this->post( $this->payload( array( (string) $r->get( 'ip' ) => $this->counts() ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['used'] );
		$this->assertCount( 1, Flows::samples( $r->id() ) );
	}

	public function test_a_wrong_or_missing_signature_is_refused(): void {
		$payload = $this->payload( array() );

		$this->assertSame( 403, $this->post( $payload, str_repeat( '0', 64 ) )->get_status() );
		$this->assertSame( 403, $this->post( $payload, '' )->get_status() );
		$this->assertSame( 403, $this->post( $payload, hash_hmac( 'sha256', 'other', self::SECRET_VALUE ) )->get_status() );
	}

	public function test_a_sender_that_is_not_in_the_setting_is_refused(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->assertSame( 403, $this->post( $this->payload( array() ) )->get_status() );

		$this->settings( array( 'flow_sources' => '203.0.113.7, 10.10.10.1' ) );
		$this->assertSame( 200, $this->post( $this->payload( array() ) )->get_status() );
	}

	public function test_a_time_outside_the_window_and_a_replayed_time_are_refused(): void {
		$this->assertSame( 400, $this->post( $this->payload( array(), time() - 300 ) )->get_status() );
		$this->assertSame( 400, $this->post( $this->payload( array(), time() + 300 ) )->get_status() );

		$at = time() - 30;
		$this->assertSame( 200, $this->post( $this->payload( array(), $at ) )->get_status() );
		$this->assertSame( 409, $this->post( $this->payload( array(), $at ) )->get_status(), 'The same time again is a replay.' );
		$this->assertSame( 200, $this->post( $this->payload( array(), $at + 1 ) )->get_status() );
	}

	public function test_the_shape_is_checked_strictly(): void {
		$ok = $this->counts();

		foreach (
			array(
				'not an ipv4'      => array( 'fe80::1' => $ok ),
				'a name'           => array( 'example.com' => $ok ),
				'a negative count' => array( '192.0.2.10' => $this->counts( array( 'dsts' => -1 ) ) ),
				'a string count'   => array( '192.0.2.10' => $this->counts( array( 'dsts' => '5' ) ) ),
				'a float count'    => array( '192.0.2.10' => $this->counts( array( 'dsts' => 5.5 ) ) ),
				'a missing count'  => array( '192.0.2.10' => array_diff_key( $ok, array( 'dsts' => 1 ) ) ),
				'an extra count'   => array( '192.0.2.10' => $ok + array( 'dest_list' => 3 ) ),
			) as $why => $sources
		) {
			$this->assertSame( 400, $this->post( $this->payload( $sources ) )->get_status(), $why );
		}

		$many = array();
		for ( $i = 1; $i <= 65; $i++ ) {
			$many[ '192.0.2.' . $i ] = $ok;
		}
		$this->assertSame( 400, $this->post( $this->payload( $many ) )->get_status(), 'At most 64 sources.' );

		$this->assertSame( 400, $this->post( array(), null, '{"v":1,"at":' . time() . ',"sources":{},"x":1}' )->get_status(), 'No extra keys.' );
		$this->assertSame( 400, $this->post( array(), null, '{"v":2,"at":' . time() . ',"sources":{}}' )->get_status(), 'Version 1 only.' );
		$this->assertSame( 400, $this->post( array(), null, '{"v":1,"at":"now","sources":{}}' )->get_status() );
		$this->assertSame( 400, $this->post( array(), null, '[1,2,3]' )->get_status() );
	}

	public function test_a_body_over_64_kb_is_refused(): void {
		$big = '{"v":1,"at":' . time() . ',"sources":{},"pad":"' . str_repeat( 'a', 70000 ) . '"}';
		$this->assertSame( 413, $this->post( array(), null, $big )->get_status() );
	}

	public function test_an_unknown_address_a_busy_server_and_an_adopted_server_are_ignored(): void {
		$r = $this->server();
		$this->assertSame( 0, $this->post( $this->payload( array( '192.0.2.99' => $this->counts( array( 'dsts' => 9999 ) ) ) ) )->get_data()['used'], 'Not in the pool.' );

		$this->assertTrue( $r->set( array( 'linked' => true ) ) );
		$this->assertSame( 0, $this->post( $this->payload( array( (string) $r->get( 'ip' ) => $this->counts() ), time() - 1 ) )->get_data()['used'], 'Adopted servers are left alone.' );
		$this->assertTrue( $r->set( array( 'linked' => null, 'state' => 'paused' ) ) );
		$this->assertSame( 0, $this->post( $this->payload( array( (string) $r->get( 'ip' ) => $this->counts() ), time() - 2 ) )->get_data()['used'], 'Not ready.' );
		$this->assertSame( array(), Flows::samples( $r->id() ) );
	}

	public function test_mode_off_stores_and_does_nothing(): void {
		$r = $this->server();
		$this->settings( array( 'traffic_quarantine' => 'off' ) );
		$this->assertSame( 0, $this->post( $this->payload( array( (string) $r->get( 'ip' ) => $this->counts() ) ) )->get_data()['used'] );
		$this->assertSame( array(), Flows::samples( $r->id() ) );
	}

	// -- Detection -----------------------------------------------------------------------------------

	/**
	 * @dataProvider signals
	 */
	public function test_each_signal_fires_only_after_the_minutes_in_a_row( string $signal, array $over ): void {
		$r = $this->server();
		$this->feed( $r, 4, $this->counts( $over ) );
		$this->assertCount( 0, $this->mails_with( 'Connection signal' ), 'Four minutes are not enough.' );

		Flows::ingest( $r, time() + 60, $this->counts( $over ) );
		$mails = $this->mails_with( 'Connection signal' );
		$this->assertCount( 1, $mails, 'The fifth minute fires it.' );
		$this->assertSame( self::OWNER, $mails[0]['to'] );
		$this->assertStringContainsString( 'Signal: ' . $signal, $mails[0]['message'] );
		$this->assertStringNotContainsString( '—', $mails[0]['message'] );

		Flows::ingest( $r, time() + 120, $this->counts( $over ) );
		$this->assertCount( 1, $this->mails_with( 'Connection signal' ), 'Once per episode.' );
	}

	public static function signals(): array {
		return array(
			'scanning by addresses' => array( 'scan', array( 'dsts' => 501 ) ),
			'scanning by ports'     => array( 'scan', array( 'dports' => 201 ) ),
			'SYN flood'             => array( 'syn', array( 'unreplied' => 2001 ) ),
			'UDP flood'             => array( 'udp', array( 'udp_dsts' => 5001 ) ),
		);
	}

	public function test_values_at_the_limit_and_one_quiet_minute_do_not_count(): void {
		$r = $this->server();
		$this->feed( $r, 6, $this->counts( array( 'dsts' => 500, 'dports' => 200, 'unreplied' => 2000, 'udp_dsts' => 5000 ) ) );
		$this->assertCount( 0, $this->mails_with( 'Connection signal' ), 'Over means more than the limit.' );

		$now = time() + 600;
		$this->feed( $r, 3, $this->counts( array( 'dsts' => 900 ) ), $now - 120 );
		Flows::ingest( $r, $now + 60, $this->counts() );
		$this->feed( $r, 3, $this->counts( array( 'dsts' => 900 ) ), $now + 240 );
		$this->assertCount( 0, $this->mails_with( 'Connection signal' ), 'A quiet minute starts the count again.' );
	}

	public function test_a_gap_in_the_samples_starts_the_count_again(): void {
		$r = $this->server();
		$now = time();
		foreach ( array( -540, -480, -420, -60, 0 ) as $offset ) {
			Flows::ingest( $r, $now + $offset, $this->counts( array( 'dsts' => 900 ) ) );
		}
		$this->assertCount( 0, $this->mails_with( 'Connection signal' ), 'Five samples, but not in a row.' );
	}

	public function test_the_number_of_minutes_is_a_setting(): void {
		$r = $this->server();
		$this->settings( array( 'flow_minutes' => 2, 'flow_scan_dsts' => 50 ) );
		$this->feed( $r, 2, $this->counts( array( 'dsts' => 51 ) ) );
		$this->assertCount( 1, $this->mails_with( 'Connection signal' ) );
	}

	public function test_report_mode_mails_and_changes_nothing(): void {
		$r = $this->server();
		$before = $this->options( $r );
		$this->feed( $r, 5, $this->counts( array( 'dsts' => 812, 'entries' => 1500 ) ) );

		$mails = $this->mails_with( 'Connection signal' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( '812 destination addresses', $mails[0]['message'] );
		$this->assertStringContainsString( 'Report only', $mails[0]['message'] );
		$this->assertCount( 1, $this->mails, 'Only the admin is mailed.' );
		$this->assertFalse( Quarantine::active( Resource::find( $r->id() ) ) );
		$this->assertSame( $before, $this->options( $r ) );
		$this->assertNotContains( 'bs-sys:quarantine-dns-udp-192.0.2.53', $this->comments( (int) $r->get( 'vmid' ) ) );
		$this->assertSame( array(), array_filter( $this->fake->writes, static fn( $w ) => false !== strpos( $w, 'quarantine' ) ) );
	}

	public function test_an_alert_comes_again_after_six_quiet_hours(): void {
		$r = $this->server();
		$t = time();
		$this->feed( $r, 5, $this->counts( array( 'udp_dsts' => 9000 ) ), $t );
		$this->assertCount( 1, $this->mails_with( 'Connection signal' ) );

		Flows::ingest( $r, $t + 3600, $this->counts() ); // Quiet: the clear time is noted.
		Flows::ingest( $r, $t + 3600 + 7 * 3600, $this->counts() ); // Still quiet after six hours: re-armed.
		$this->feed( $r, 5, $this->counts( array( 'udp_dsts' => 9000 ) ), $t + 3600 + 8 * 3600 );
		$this->assertCount( 2, $this->mails_with( 'Connection signal' ) );
	}

	// -- The quarantine ------------------------------------------------------------------------------

	private function quarantine( Resource $r ): Resource {
		$this->settings( array( 'traffic_quarantine' => 'on' ) );
		$this->feed( $r, 5, $this->counts( array( 'dsts' => 812, 'dports' => 40, 'entries' => 1500 ) ) );
		$this->run_jobs();

		return Resource::find( $r->id() );
	}

	public function test_mode_on_blocks_outgoing_traffic_and_tells_everyone(): void {
		$r     = $this->server();
		$vmid  = (int) $r->get( 'vmid' );
		$fresh = $this->quarantine( $r );

		$options = $this->options( $r );
		$this->assertSame( 'DROP', $options['policy_out'] );
		$this->assertSame( 1, (int) $options['enable'] );
		$comments = $this->comments( $vmid );
		$this->assertContains( 'bs-sys:quarantine-dns-udp-192.0.2.53', $comments );
		$this->assertContains( 'bs-sys:quarantine-dns-tcp-192.0.2.53', $comments );
		$this->assertFalse( $this->fake->fake_outbound_allowed( $vmid, 'tcp', 443 ), 'Other outgoing traffic is blocked.' );

		$this->assertTrue( Quarantine::active( $fresh ) );
		$this->assertTrue( $fresh->get( Quarantine::META )['notified'] );
		$this->assertSame( 'scan', $fresh->get( Quarantine::META )['signal'] );

		$customer = $this->mails_with( 'Outgoing traffic from your server is blocked' );
		$this->assertCount( 1, $customer );
		$this->assertSame( self::OWNER, $customer[0]['to'] );
		foreach ( array( '812', 'DNS', 'keeps running', 'contact support' ) as $word ) {
			$this->assertStringContainsStringIgnoringCase( $word, $customer[0]['message'] );
		}
		$this->assertStringNotContainsString( '—', $customer[0]['message'] );
		$this->assertStringNotContainsString( 'quarantine', strtolower( $customer[0]['message'] ) );

		$admin = $this->mails_with( 'Network quarantine' );
		$this->assertCount( 1, $admin );
		$this->assertSame( self::OWNER, $admin[0]['to'] );
		$this->assertCount( 0, $this->mails_with( 'repeat' ), 'The first one is not a repeat.' );

		$events = \BeltoftLicenses\Events::for_license( (int) ( $fresh->license()['id'] ?? 0 ) );
		$this->assertStringContainsString( 'Network quarantine', wp_json_encode( $events ) );
	}

	public function test_a_quarantined_server_keeps_its_quarantine_through_every_reconcile(): void {
		$r = $this->server();
		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$r = Resource::find( $r->id() );
		$rules = Firewall::normalise( array( $this->rule( 'tcp', 8080 ) ), $r );
		$this->assertGreaterThan( 0, Firewall::store( $r, true, $rules, true, 1 ) );
		Jobs::act( $r->id(), 'fw_apply', array() );
		$this->assertSame( 'ACCEPT', $this->options( $r )['policy_out'] );

		$r = $this->quarantine( Resource::find( $r->id() ) );
		$this->assertSame( 'DROP', $this->options( $r )['policy_out'] );

		// The customer's own apply, and a reset.
		Jobs::act( $r->id(), 'fw_apply', array() );
		$this->assertSame( 'DROP', $this->options( $r )['policy_out'], 'A normal apply does not undo it.' );
		Jobs::act( $r->id(), 'fw_reset', array() );
		$this->assertSame( 'DROP', $this->options( $r )['policy_out'], 'A reset does not undo it.' );
		$this->assertContains( 'bs-sys:quarantine-dns-udp-192.0.2.53', $this->comments( (int) $r->get( 'vmid' ) ) );

		// The build step (a rebuild or a restore), both paths: the managed one and the plain one.
		$record = VmRecords::for_resource( $r->id() );
		$fw     = new Firewall( Resource::find( $r->id() ), $this->fake, Clusters::get( 'lab' ) );
		$this->assertIsArray( $fw->ensure( $record, array( 'node' => 'node-a', 'config' => array() ) ) );
		$this->assertSame( 'DROP', $this->options( $r )['policy_out'] );

		$plain = $this->server();
		$plain = $this->quarantine( $plain );
		$fw    = new Firewall( $plain, $this->fake, Clusters::get( 'lab' ) );
		$this->assertIsArray( $fw->ensure( VmRecords::for_resource( $plain->id() ), array( 'node' => 'node-a', 'config' => array() ) ) );
		$this->assertSame( 'DROP', $this->options( $plain )['policy_out'], 'The legacy path keeps it too.' );
	}

	public function test_inbound_is_not_changed_by_the_quarantine(): void {
		$r      = $this->server();
		$before = $this->options( $r );
		$this->quarantine( $r );
		$after = $this->options( $r );

		$this->assertSame( $before['policy_in'] ?? null, $after['policy_in'] ?? null );
		$this->assertSame( $before['macfilter'] ?? null, $after['macfilter'] ?? null );
	}

	public function test_the_quarantine_is_not_started_twice_while_it_lasts(): void {
		$r = $this->server();
		$this->quarantine( $r );
		$count = count( $this->mails_with( 'Network quarantine' ) );
		$this->feed( Resource::find( $r->id() ), 3, $this->counts( array( 'dsts' => 900 ) ), time() + 600 );
		$this->run_jobs();
		$this->assertCount( $count, $this->mails_with( 'Network quarantine' ) );
		$this->assertCount( 1, $this->mails_with( 'Outgoing traffic from your server is blocked' ) );
	}

	public function test_the_lift_restores_the_firewall_and_clears_the_flag(): void {
		$r     = $this->server();
		$vmid  = (int) $r->get( 'vmid' );
		$fresh = $this->quarantine( $r );
		$this->assertSame( 'DROP', $this->options( $r )['policy_out'] );

		$this->assertTrue( Panel::act( $fresh, 'quarantine_lift' ) );
		$this->run_jobs();

		$this->assertSame( 'ACCEPT', $this->options( $r )['policy_out'] );
		foreach ( $this->comments( $vmid ) as $comment ) {
			$this->assertStringStartsNotWith( 'bs-sys:quarantine', $comment );
		}
		$this->assertFalse( Quarantine::active( Resource::find( $r->id() ) ) );
		$this->assertSame( 1, (int) $this->options( $r )['enable'] );
		$this->assertContains( 'beltoft-services: block outgoing SMTP', $this->comments( $vmid ), 'The other plugin rules stay.' );
	}

	public function test_lifting_a_server_that_is_not_quarantined_is_refused(): void {
		$r = $this->server();
		$this->assertWPError( Panel::act( $r, 'quarantine_lift' ) );
	}

	public function test_a_second_quarantine_within_seven_days_also_mails_a_repeat_alert_and_sets_no_hold(): void {
		$r = $this->server();
		$fresh = $this->quarantine( $r );
		$this->assertCount( 0, $this->mails_with( 'repeat' ) );

		$this->assertTrue( Panel::act( $fresh, 'quarantine_lift' ) );
		$this->run_jobs();
		$this->assertFalse( Quarantine::active( Resource::find( $r->id() ) ) );

		$this->mails = array();
		$this->feed( Resource::find( $r->id() ), 5, $this->counts( array( 'dsts' => 900 ) ), time() + 600 ); // Still scanning after the lift.
		$this->run_jobs();

		$this->assertTrue( Quarantine::active( Resource::find( $r->id() ) ) );
		$this->assertCount( 1, $this->mails_with( 'repeat' ) );
		$this->assertFalse( Resource::find( $r->id() )->on_hold(), 'No automatic breach hold.' );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ) );
	}

	public function test_a_quarantine_after_seven_days_is_not_a_repeat(): void {
		$r = $this->server();
		$this->assertTrue( $r->set( array( Quarantine::LOG => array( time() - 8 * DAY_IN_SECONDS ) ) ) );
		$this->quarantine( Resource::find( $r->id() ) );
		$this->assertCount( 1, $this->mails_with( 'Network quarantine' ) );
		$this->assertCount( 0, $this->mails_with( 'repeat' ) );
	}

	public function test_the_settings_are_sanitised(): void {
		$this->settings( array( 'traffic_quarantine' => 'nonsense' ) );
		$clean = \BeltoftServices\Proxmox\ProxmoxSettings::sanitize( array( 'traffic_quarantine' => 'nonsense', 'flow_sources' => "bad, 10.0.0.5 10.0.0.5\n2001:db8::1", 'flow_minutes' => 0 ) );
		$this->assertSame( 'report', $clean['traffic_quarantine'] );
		$this->assertSame( '10.0.0.5', $clean['flow_sources'] );
		$this->assertSame( 1, $clean['flow_minutes'] );
		$this->assertSame( '10.10.10.1', \BeltoftServices\Proxmox\ProxmoxSettings::clean_addresses( 'nothing valid' ) );
	}
}

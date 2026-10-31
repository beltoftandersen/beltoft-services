<?php
/** The www name of an app on a bare domain. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Servers;
use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Proxmox\AppDomainRelease;
use BeltoftServices\Proxmox\AppInstall;
use BeltoftServices\Proxmox\AppOwnDomain;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\AppTab;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\AppWww;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

/** Documentation names and addresses only. DNS is FakeDns (ours) or a filter (public), Proxmox is FakeClient. */
final class AppWwwTest extends MonitoringTestCase {

	private const IP   = '192.0.2.10';
	private const ZONE = 'mine.example';
	private const OWN  = 'elsewhere.test';

	/** @var array<string, array<int, string>> Public answers by "TYPE name". */
	private array $public = array();

	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( FakeDns::OPTION );
		$this->public = array();
		$this->mails  = array();
		add_filter(
			'beltoft_services_dns_resolve',
			fn( $pre, $name, $type ) => $this->public[ $type . ' ' . $name ] ?? array(),
			20,
			3
		);
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;
				return true;
			},
			10,
			2
		);
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04",
			'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress', 'coolify', 'n8n' ),
			'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) ) );
		$this->clear_ips();
	}

	public function tear_down(): void {
		$this->clear_ips();
		delete_option( FakeDns::OPTION );
		unset( $GLOBALS['wp']->query_vars['vps'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function clear_ips(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/** A ready server with an app, at 192.0.2.10. */
	private function server( array $meta = array(), string $app = 'wordpress', int $vmid = 5000 ): Resource {
		$r  = $this->ready_vm( $vmid, 'christian.beltoft@gmail.com' );
		$ip = '192.0.2.' . ( 10 + $vmid - 5000 );
		$this->assertTrue( VmRecords::move( $r->id(), 'node-a' ) );
		$versions = array( 'wordpress' => '7.1.4', 'n8n' => '2', 'docker' => '29', 'coolify' => '4.5.1' );
		$this->assertTrue( $r->set( array_merge(
			array(
				'ip'          => $ip,
				'app_id'      => $app,
				'app_version' => $versions[ $app ],
				'app_state'   => 'pending',
				OsField::META => 9001,
				'plan'        => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ),
			),
			$meta
		) ) );
		IpPool::add( $ip );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$this->assertTrue( IpPool::assign( $r->id() ) );
		return Resource::find( $r->id() );
	}

	/** A server whose app domain is the bare zone of ours (or $want inside it). */
	private function zone_server( string $want = self::ZONE, array $meta = array(), int $vmid = 5000 ): Resource {
		$r = $this->server( array_merge( array( 'app_zone' => self::ZONE, 'app_domain_want' => $want ), $meta ), 'wordpress', $vmid );
		$product = $this->service_product( 'domain', array() );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => (int) $r->license()['user_id'] ) );
		$d = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => self::ZONE, 'mode' => 'register', 'zone_ready' => true ) );
		$this->assertInstanceOf( Resource::class, $d );
		$d->set( array( 'state' => 'ready' ) );
		$this->assertTrue( DnsClients::dns()->create_zone( self::ZONE, array( 'ns1.example.net', 'ns2.example.net' ) ) );
		return $r;
	}

	private function own_server( string $name = self::OWN, array $meta = array(), int $vmid = 5000 ): Resource {
		return $this->server( array_merge( array_filter( AppOwnDomain::fresh_wait( $name ), static fn( $v ) => null !== $v ), array( 'app_domain_dns_since' => time() ), $meta ), 'wordpress', $vmid );
	}

	/** @return array<int, array<string, mixed>> */
	private function rrsets(): array {
		$zone = DnsClients::dns()->zone( self::ZONE );
		return is_array( $zone ) ? (array) $zone['rrsets'] : array();
	}

	/** The (name, type, value) of every record in the test zone. */
	private function records(): array {
		$out = array();
		foreach ( $this->rrsets() as $set ) {
			foreach ( (array) ( $set['records'] ?? array() ) as $rec ) {
				$out[] = rtrim( (string) $set['name'], '.' ) . ' ' . $set['type'] . ' ' . ( $rec['content'] ?? '' );
			}
		}
		sort( $out );
		return $out;
	}

	private function job(): array {
		return json_decode( (string) $this->fake->guest_file( 5000, Apps::JOB ), true );
	}

	// -- Which names ----------------------------------------------------------------------------

	public function test_only_a_bare_domain_gets_a_www_name_and_only_for_apps_with_a_web_server(): void {
		$wp = $this->own_server( 'example.com' );
		$this->assertSame( 'www.example.com', AppWww::name_for( $wp, 'example.com' ) );
		$this->assertSame( '', AppWww::name_for( $wp, 'shop.example.com' ), 'A subdomain has no www.' );
		$this->assertSame( '', AppWww::name_for( $wp, '' ) );

		$n8n = $this->server( array(), 'n8n', 5001 );
		$this->assertSame( 'www.example.org', AppWww::name_for( $n8n, 'example.org' ) );

		foreach ( array( 'docker' => 5002, 'coolify' => 5003 ) as $app => $vmid ) {
			$this->assertSame( '', AppWww::name_for( $this->server( array(), $app, $vmid ), 'example.com' ), $app );
			$this->assertArrayNotHasKey( 'www', (array) Apps::definition( $app ) );
		}
	}

	public function test_a_server_installed_before_the_recipe_that_can_do_it_is_left_alone(): void {
		$r = $this->own_server( 'example.com', array( 'app_recipe' => 1 ) );
		$this->assertSame( '', AppWww::name_for( $r, 'example.com' ) );
		$this->assertSame( '', AppWww::view( $r )['name'] );
	}

	public function test_a_zone_of_ours_is_an_apex_only_for_the_zone_itself(): void {
		$r = $this->zone_server( 'shop.' . self::ZONE );
		$this->assertSame( '', AppWww::name_for( $r, 'shop.' . self::ZONE ) );
		$r->set( array( 'app_domain_want' => self::ZONE ) );
		$this->assertSame( 'www.' . self::ZONE, AppWww::name_for( Resource::find( $r->id() ), self::ZONE ) );
	}

	// -- On our name servers ------------------------------------------------------------------------

	public function test_www_is_written_next_to_the_apex_record_and_goes_in_the_job(): void {
		$r = $this->zone_server();
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );

		$this->assertSame( array( 'mine.example A ' . self::IP, 'www.mine.example A ' . self::IP ), $this->records() );
		$job = $this->job();
		$this->assertSame( self::ZONE, $job['domain'] );
		$this->assertSame( 'www.' . self::ZONE, $job['www'] );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'live', $fresh->get( 'app_www' ) );
		$this->assertTrue( $fresh->get( 'app_www_made' ) );
		$this->assertSame( self::IP, $fresh->get( 'app_www_ip' ) );
	}

	public function test_a_subdomain_gets_no_www_record(): void {
		$r = $this->zone_server( 'shop.' . self::ZONE );
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		$this->assertSame( array( 'shop.mine.example A ' . self::IP ), $this->records() );
		$this->assertSame( '', $this->job()['www'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'app_www' ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function foreign_records(): array {
		return array(
			'cname' => array( 'CNAME', 'shops.example.net.' ),
			'txt'   => array( 'TXT', '"keep me"' ),
			'other a' => array( 'A', '198.51.100.9' ),
		);
	}

	/** @dataProvider foreign_records */
	public function test_a_www_that_has_any_record_is_never_overwritten_and_the_install_goes_on( string $type, string $content ): void {
		$r = $this->zone_server();
		DnsClients::dns()->replace_rrsets( self::ZONE, array( array( 'name' => 'www.' . self::ZONE . '.', 'type' => $type, 'ttl' => 600, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => $content, 'disabled' => false ) ) ) ) );
		$before = DnsClients::dns()->zone( self::ZONE )['rrsets'];

		$result = AppInstall::queue( $r, $this->fake );
		$this->assertTrue( $result->is_done(), 'The install is not blocked by a www that is not ours.' );
		$this->assertSame( 'www.' . self::ZONE . ' ' . $type . ' ' . $content, $this->wwwRecord() );
		$this->assertSame( '', $this->job()['www'], 'It is not served until it points at the server.' );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'waiting', $fresh->get( 'app_www' ) );
		$this->assertNotTrue( $fresh->get( 'app_www_made' ) );
		$this->assertCount( count( $before ) + 1, DnsClients::dns()->zone( self::ZONE )['rrsets'], 'Only the apex record was added.' );
	}

	private function wwwRecord(): string {
		foreach ( $this->records() as $line ) {
			if ( str_starts_with( $line, 'www.' . self::ZONE . ' ' ) ) {
				return $line;
			}
		}
		return '';
	}

	public function test_a_www_record_that_is_already_ours_value_is_adopted_and_not_marked_ours(): void {
		$r = $this->zone_server();
		DnsClients::dns()->replace_rrsets( self::ZONE, array( array( 'name' => 'www.' . self::ZONE . '.', 'type' => 'A', 'ttl' => 600, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => self::IP, 'disabled' => false ) ) ) ) );
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'www.' . self::ZONE, $this->job()['www'] );
		$this->assertSame( 'live', $fresh->get( 'app_www' ) );
		$this->assertNotTrue( $fresh->get( 'app_www_made' ), 'It was not written by us, so it is never removed.' );
	}

	public function test_a_zone_that_does_not_answer_leaves_www_waiting_and_the_install_goes_on(): void {
		$r = $this->zone_server();
		$dns = new class( true ) extends FakeDns {
			public int $reads = 0;
			public function zone( string $name ): array|\WP_Error|null {
				++$this->reads;
				return $this->reads > 1 ? new \WP_Error( 'down', 'down' ) : parent::zone( $name );
			}
		};
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
		$fresh = Resource::find( $r->id() );
$this->assertSame( 'retry', $fresh->get( 'app_www_reason' ) );
		$this->assertSame( '', $this->job()['www'] );
	}

	// -- Removal ------------------------------------------------------------------------------------

	public function test_both_records_go_when_the_app_moves_to_another_domain_and_only_while_they_are_ours(): void {
		$r = $this->zone_server();
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		$r = Resource::find( $r->id() );
		$r->set( array( 'app_domain' => self::ZONE, 'app_domain_made' => true ) );
		$r = Resource::find( $r->id() );

		$meta = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$names = array_column( (array) $meta['app_domain_old'], 'name' );
		sort( $names );
		$this->assertSame( array( self::ZONE, 'www.' . self::ZONE ), $names );
		$this->assertNull( $meta['app_www_made'] );

		// The customer changed www to a CNAME in the meantime: that one stays.
		DnsClients::dns()->replace_rrsets( self::ZONE, array( array( 'name' => 'www.' . self::ZONE . '.', 'type' => 'A', 'changetype' => 'DELETE' ), array( 'name' => 'www.' . self::ZONE . '.', 'type' => 'CNAME', 'ttl' => 600, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => 'shops.example.net.', 'disabled' => false ) ) ) ) );
		$r->set( $meta );
		AppDomainRelease::run( $r->id() );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array( 'www.mine.example CNAME shops.example.net.' ), $this->records() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'app_domain_old' ) );
	}

	public function test_an_untouched_www_record_is_removed_with_the_apex_record(): void {
		$r = $this->zone_server();
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		$r = Resource::find( $r->id() );
		$r->set( array( 'app_domain' => self::ZONE, 'app_domain_made' => true ) );
		$meta = AppReinstall::meta( array(), Resource::find( $r->id() ), array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$r->set( $meta );
		AppDomainRelease::run( $r->id() );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), $this->records() );
	}

	public function test_a_www_that_was_not_ours_is_not_queued_for_removal(): void {
		$r = $this->zone_server();
		$r->set( array( 'app_domain' => self::ZONE, 'app_domain_made' => true, 'app_www' => 'live', 'app_www_made' => false ) );
		$meta = AppReinstall::meta( array(), Resource::find( $r->id() ), array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$this->assertSame( array( self::ZONE ), array_column( (array) $meta['app_domain_old'], 'name' ) );
	}

	public function test_keeping_the_domain_keeps_the_www_record_and_its_ownership(): void {
		$r = $this->zone_server();
		$r->set( array( 'app_domain' => self::ZONE, 'app_domain_made' => true, 'app_www' => 'live', 'app_www_made' => true, 'app_www_ip' => self::IP ) );
		$meta = AppReinstall::meta( array(), Resource::find( $r->id() ), array( 'app_version' => 'keep', 'app_domain_mode' => 'keep' ) );
		$this->assertArrayNotHasKey( 'app_domain_old', $meta );
		$this->assertArrayNotHasKey( 'app_www_made', $meta );
		$this->assertNull( $meta['app_www'], 'The state starts again with the new install.' );
	}

	// -- Own domain ---------------------------------------------------------------------------------

	public function test_the_apex_goes_live_alone_when_www_does_not_resolve(): void {
		$r = $this->own_server( 'example.com', array( 'app_domain' => 'example.com', 'app_domain_made' => false, 'app_domain_dns' => 'live' ) );
		$this->public['A www.example.com'] = array( '198.51.100.7' );

		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		$job = $this->job();
		$this->assertSame( 'example.com', $job['domain'] );
		$this->assertSame( '', $job['www'] );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'waiting', $fresh->get( 'app_www' ) );
		$this->assertSame( 'other', $fresh->get( 'app_www_reason' ) );
		$this->assertSame( array(), $this->records(), 'No DNS is written for an own domain.' );
	}

	public function test_a_www_that_already_resolves_is_served_from_the_start(): void {
		$r = $this->own_server( 'example.com', array( 'app_domain' => 'example.com', 'app_domain_made' => false, 'app_domain_dns' => 'live' ) );
		$this->public['A www.example.com'] = array( self::IP );
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		$this->assertSame( 'www.example.com', $this->job()['www'] );
		$this->assertSame( 'live', Resource::find( $r->id() )->get( 'app_www' ) );
	}

	public function test_an_own_subdomain_has_no_www(): void {
		$r = $this->own_server( 'shop.example.com', array( 'app_domain' => 'shop.example.com', 'app_domain_made' => false, 'app_domain_dns' => 'live' ) );
		$this->assertTrue( AppInstall::queue( $r, $this->fake )->is_done() );
		$this->assertSame( '', $this->job()['www'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'app_www' ) );
	}

	// -- Later --------------------------------------------------------------------------------------

	private function ready_waiting( string $reason = 'none' ): Resource {
		return $this->own_server( 'example.com', array( 'app_domain' => 'example.com', 'app_domain_made' => false, 'app_domain_dns' => 'live', 'app_state' => 'ready', 'app_www' => 'waiting', 'app_www_reason' => $reason ) );
	}

	public function test_a_www_that_starts_to_resolve_is_added_later(): void {
		$r = $this->ready_waiting();

		AppWww::run( $r->id() );
		$this->assertNull( $this->fake->guest_file( 5000, AppWww::FILE ) );
		$this->assertSame( 'none', Resource::find( $r->id() )->get( 'app_www_reason' ) );
		$this->assertTrue( false !== as_next_scheduled_action( AppWww::HOOK, array( $r->id() ), \BeltoftServices\Jobs::GROUP ), 'The next look is queued.' );

		$r->set( array( 'app_www_checked' => time() - 700 ) );
		$this->public['A www.example.com'] = array( self::IP );
		AppWww::run( $r->id() );
		$request = json_decode( (string) $this->fake->guest_file( 5000, AppWww::FILE ), true );
		$this->assertSame( array( 'v' => 1, 'domain' => 'example.com', 'www' => 'www.example.com' ), $request );
		$this->assertSame( 'applying', Resource::find( $r->id() )->get( 'app_www' ) );

		$this->fake->set_guest_file( 5000, AppWww::STATUS, wp_json_encode( array( 'www' => 'www.example.com', 'state' => 'ready' ) ) );
		AppWww::run( $r->id() );
		$this->assertSame( 'live', Resource::find( $r->id() )->get( 'app_www' ) );
	}

	public function test_a_guest_that_fails_or_stays_silent_sends_www_back_to_waiting(): void {
		$r = $this->ready_waiting();
		$r->set( array( 'app_www' => 'applying', 'app_www_at' => time() - 100 ) );
		$this->fake->set_guest_file( 5000, AppWww::STATUS, wp_json_encode( array( 'www' => 'www.example.com', 'state' => 'failed' ) ) );
		AppWww::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'waiting', $fresh->get( 'app_www' ) );
		$this->assertSame( 'apply', $fresh->get( 'app_www_reason' ) );

		$silent = $this->own_server( 'example.org', array( 'app_domain' => 'example.org', 'app_domain_dns' => 'live', 'app_state' => 'ready', 'app_www' => 'applying', 'app_www_at' => time() - AppWww::APPLY_WAIT - 5 ), 5001 );
		AppWww::run( $silent->id() );
		$this->assertSame( 'waiting', Resource::find( $silent->id() )->get( 'app_www' ) );
	}

	public function test_a_look_is_rate_limited_and_skips_servers_that_are_not_ready(): void {
		$r = $this->ready_waiting();
		$r->set( array( 'app_www_checked' => time() - 10 ) );
		$this->public['A www.example.com'] = array( self::IP );
		AppWww::run( $r->id() );
		$this->assertNull( $this->fake->guest_file( 5000, AppWww::FILE ), 'Too soon after the last look.' );

		$r->set( array( 'app_www_checked' => time() - 700, 'state' => 'paused' ) );
		AppWww::run( $r->id() );
		$this->assertNull( $this->fake->guest_file( 5000, AppWww::FILE ) );
	}

	public function test_the_sweep_finds_a_lost_look_and_the_ready_report_starts_one(): void {
		$r = $this->ready_waiting();
		AppWww::sweep();
		$this->assertNotFalse( as_next_scheduled_action( AppWww::HOOK, array( $r->id() ), \BeltoftServices\Jobs::GROUP ) );

		$other = $this->own_server( 'example.org', array( 'app_domain' => 'example.org', 'app_www' => 'waiting' ), 5001 );
		AppWww::installed( $other );
		$this->assertNotFalse( as_next_scheduled_action( AppWww::HOOK, array( $other->id() ), \BeltoftServices\Jobs::GROUP ) );
	}

	// -- What the customer reads --------------------------------------------------------------------

	private function pane( Resource $r ): string {
		wp_set_current_user( (int) $r->license()['user_id'] );
		$view = View::license( $r->license() );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		ob_start();
		Servers::render( array( $view ) );
		$html = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '#<section[^>]*data-tab="app"[^>]*>(.*?)</section>#s', $html, $m ) );

		return $m[1];
	}

	public function test_the_waiting_for_dns_view_names_both_records_and_the_address(): void {
		$r    = $this->own_server( 'example.com' );
		$pane = $this->pane( $r );
		$this->assertStringContainsString( 'data-app-state="dns"', $pane );
		$this->assertStringContainsString( 'data-app-own-www', $pane );
		$this->assertStringContainsString( 'example.com', $pane );
		$this->assertStringContainsString( 'www.example.com', $pane );
		$this->assertStringContainsString( self::IP, $pane );
		$this->assertStringNotContainsString( "\u{2014}", $pane );

		$sub = $this->own_server( 'shop.example.com', array(), 5001 );
		$this->assertStringNotContainsString( 'www.', $this->pane( Resource::find( $sub->id() ) ) );
	}

	public function test_a_ready_app_shows_the_state_of_www(): void {
		$r = $this->ready_waiting( 'none' );
		$this->assertSame( 'ready', AppTab::view( $r )['kind'] );
		$pane = $this->pane( $r );
		$this->assertStringContainsString( 'www.example.com: waiting for the A record', $pane );
		$this->assertStringContainsString( self::IP, $pane );

		$r->set( array( 'app_www' => 'live' ) );
		$this->assertStringContainsString( 'www.example.com is served too and redirects to example.com.', $this->pane( Resource::find( $r->id() ) ) );
	}

	public function test_the_live_email_shows_both_names_and_the_address_while_www_waits(): void {
		$r = $this->ready_waiting( 'none' );
		AppOwnDomain::installed( $r );
		$this->assertCount( 1, $this->mails );
		$text = wp_strip_all_tags( $this->mails[0]['message'] );
		$this->assertStringContainsString( 'https://example.com', $text );
		$this->assertStringContainsString( 'www.example.com: waiting for the A record', $text );
		$this->assertStringContainsString( self::IP, $text );
		$this->assertStringNotContainsString( "\u{2014}", $this->mails[0]['message'] );
	}

	public function test_the_live_email_says_when_www_is_served(): void {
		$r = $this->ready_waiting();
		$r->set( array( 'app_www' => 'live' ) );
		AppOwnDomain::installed( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'www.example.com is served too and redirects to example.com.', wp_strip_all_tags( $this->mails[0]['message'] ) );
	}

	public function test_the_own_domain_texts_in_the_forms_name_both_records(): void {
		$r = $this->server();
		ob_start();
		AppReinstall::fields( $r->id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'example.com and www.example.com', $html );
	}

	// -- What the guest runs ----------------------------------------------------------------------------

	/** Runs the Caddyfile generator that an install script writes, in a scratch directory with a stub caddy. */
	private function generate( string $app, string $version, string $domain, string $www ): string {
		$script = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/' . $app . '/' . $version . '/install.script' );
		$this->assertSame( 1, preg_match( "/<<'CADDY'\n(.*?)\nCADDY\n/s", $script, $gen ) );
		$this->assertSame( 1, preg_match( "/printf '(  [^']*)' > \/etc\/caddy\/beltoft-site\.body/", $script, $body ) );
		$dir = trailingslashit( get_temp_dir() ) . 'bs-www-' . wp_generate_password( 8, false );
		mkdir( $dir . '/bin', 0700, true );
		file_put_contents( $dir . '/bin/caddy', "#!/bin/sh\nexit 0\n" );
		chmod( $dir . '/bin/caddy', 0700 );
		file_put_contents( $dir . '/gen.sh', str_replace( '/etc/caddy', $dir, $gen[1] ) );
		chmod( $dir . '/gen.sh', 0700 );
		file_put_contents( $dir . '/beltoft-site.body', stripcslashes( $body[1] ) );
		$out  = array();
		$code = 1;
		exec( 'PATH=' . escapeshellarg( $dir . '/bin' ) . ':$PATH ' . escapeshellarg( $dir . '/gen.sh' ) . ' ' . escapeshellarg( $domain ) . ( '' === $www ? '' : ' ' . escapeshellarg( $www ) ) . ' 2>&1', $out, $code );
		$file = (string) @file_get_contents( $dir . '/Caddyfile' );
		exec( 'rm -rf ' . escapeshellarg( $dir ) );
		return 0 === $code ? $file : 'ERROR ' . implode( "\n", $out );
	}

	public function test_each_web_app_serves_and_certifies_both_names_and_redirects_www(): void {
		foreach ( array( array( 'wordpress', '7.1.4', 'php_fastcgi' ), array( 'n8n', '2', 'reverse_proxy 127.0.0.1:5678' ) ) as list( $app, $version, $body ) ) {
			$caddy = $this->generate( $app, $version, 'example.com', 'www.example.com' );
			$this->assertStringContainsString( "example.com {\n", $caddy, $app );
			$this->assertStringContainsString( $body, $caddy, $app );
			$this->assertStringContainsString( "www.example.com {\n  redir https://example.com{uri} permanent\n}", $caddy, $app );

			$plain = $this->generate( $app, $version, 'example.com', '' );
			$this->assertStringNotContainsString( 'www.', $plain, $app . ': without www the site is as it was.' );
			$this->assertStringContainsString( $body, $plain );

			$this->assertStringStartsWith( 'ERROR', $this->generate( $app, $version, 'example.com', 'www.other.com' ), $app . ': a www name of another domain is refused.' );
		}
	}

	public function test_the_install_scripts_read_www_from_the_job_and_can_add_it_later_without_a_template_change(): void {
		foreach ( array( 'wordpress/7.1.4', 'n8n/2' ) as $dir ) {
			$file   = BELTOFT_SERVICES_PATH . 'apps/' . $dir . '/install.script';
			$script = (string) file_get_contents( $file );
			$this->assertStringContainsString( "www=\$(jq -er '.www // \"\"' \"\$job\")", $script, $dir );
			$this->assertStringContainsString( '[ "$www" = "www.$domain" ]', $script, $dir );
			$this->assertStringContainsString( 'PathExists=/var/lib/beltoft-app/www.json', $script, $dir );
			$this->assertStringContainsString( AppWww::STATUS, str_replace( '$state/www.status.json', AppWww::STATUS, str_replace( '"$state/', '"/var/lib/beltoft-app/', $script ) ) );
			$out  = array();
			$code = 1;
			exec( 'bash -n ' . escapeshellarg( $file ), $out, $code );
			$this->assertSame( 0, $code, $dir );
		}
		$snippet = BELTOFT_SERVICES_PATH . '.internal/proxmox-snippets/vm-vendor-cloudinit-v2.yaml';
		$this->assertFalse( file_exists( $snippet ), 'The vendor snippet is not part of the plugin and is not changed.' );
	}

	public function test_the_apex_test_uses_the_public_suffix_list_when_it_is_there(): void {
		$this->assertTrue( AppWww::is_apex( 'example.com' ) );
		$this->assertFalse( AppWww::is_apex( 'www.example.com' ) );
		$this->assertFalse( AppWww::is_apex( 'shop.example.com' ) );
		$this->assertFalse( AppWww::is_apex( '' ) );
		$this->assertTrue( AppWww::is_apex( 'example.co.uk' ) );
		$this->assertFalse( AppWww::is_apex( 'shop.example.co.uk' ) );
	}
}

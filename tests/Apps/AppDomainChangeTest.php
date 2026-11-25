<?php
/** Add or change the domain of a running app: the flow, the DNS writes and the guards. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppDomainChange;
use BeltoftServices\Apps\AppDomainRelease;
use BeltoftServices\Apps\AppInstall;
use BeltoftServices\Apps\AppOwnDomain;
use BeltoftServices\Apps\AppReinstall;
use BeltoftServices\Apps\AppWww;
use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records as DnsRecords;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once __DIR__ . '/../Proxmox/Network/MonitoringTestCase.php';

/**
 * Documentation names and addresses only. DNS is FakeDns (ours) or a filter (public), Proxmox is FakeClient. The guest side
 * (AppRename, builder B) is a test double set on AppDomainChange::$guest: it records the requests and answers what the test says.
 */
final class AppDomainChangeTest extends MonitoringTestCase {

	private const IP   = '192.0.2.10';
	private const ZONE = 'mine.example';
	private const OWN  = 'elsewhere.test';

	/** @var array<string, array<int, string>> Public answers by "TYPE name". */
	private array $public = array();

	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	/** @var array<int, string> Public lookups made. */
	private array $lookups = array();

	/** The double of the guest side. */
	private object $guest;

	public function set_up(): void {
		parent::set_up();
		delete_option( FakeDns::OPTION );
		$this->public  = array();
		$this->mails   = array();
		$this->lookups = array();
		add_filter(
			'beltoft_services_dns_resolve',
			function ( $pre, $name, $type ) {
				$this->lookups[] = $type . ' ' . $name;
				return $this->public[ $type . ' ' . $name ] ?? array();
			},
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
		$this->guest = new class() {
			public ?string $unsupported = null;
			/** @var array<int, array<string, mixed>> */
			public array $pushed = array();
			public bool $push_fails = false;
			/** @var array<string, mixed>|null What read() answers (run and domain are filled in). */
			public ?array $answer = null;
			public int $reads = 0;
			public function supported( $r ): ?string {
				return $this->unsupported;
			}
			public function push( $r, array $want ) {
				if ( $this->push_fails ) {
					return new \WP_Error( 'agent', 'down' );
				}
				$run            = bin2hex( random_bytes( 12 ) );
				$this->pushed[] = $want + array( 'run' => $run );
				return $run;
			}
			public function read( $r, string $run ): ?array {
				++$this->reads;
				return null === $this->answer ? null : $this->answer + array( 'run' => $run );
			}
		};
		AppDomainChange::$guest = $this->guest;
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
		AppDomainChange::$guest = null;
		$this->clear_ips();
		delete_option( FakeDns::OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function clear_ips(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/** A ready server with a ready app at 192.0.2.10, and the customer's zone with us. */
	private function server( array $meta = array(), string $app = 'wordpress', int $vmid = 5000, bool $zone = true ): Resource {
		$r  = $this->ready_vm( $vmid, 'christian.beltoft@gmail.com' );
		$ip = '192.0.2.' . ( 10 + $vmid - 5000 );
		$this->assertTrue( VmRecords::move( $r->id(), 'node-a' ) );
		$versions = array( 'wordpress' => '7.1.4', 'n8n' => '2', 'docker' => '29', 'coolify' => '4.5.1' );
		$this->assertTrue( $r->set( array_merge(
			array(
				'ip'          => $ip,
				'agent'       => 'ok',
				'app_id'      => $app,
				'app_version' => $versions[ $app ],
				'app_state'   => 'ready',
				OsField::META => 9001,
				'plan'        => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ),
			),
			$meta
		) ) );
		IpPool::add( $ip );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$this->assertTrue( IpPool::assign( $r->id() ) );
		if ( $zone ) {
			$product = $this->service_product( 'domain', array() );
			$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => (int) $r->license()['user_id'] ) );
			$d = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => self::ZONE, 'mode' => 'register', 'zone_ready' => true ) );
			$this->assertInstanceOf( Resource::class, $d );
			$d->set( array( 'state' => 'ready' ) );
			$this->assertTrue( DnsClients::dns()->create_zone( self::ZONE, array( 'ns1.example.net', 'ns2.example.net' ) ) );
		}
		return Resource::find( $r->id() );
	}

	/** @return array<int, array<string, mixed>> */
	private function rrsets(): array {
		$zone = DnsClients::dns()->zone( self::ZONE );
		return is_array( $zone ) ? (array) $zone['rrsets'] : array();
	}

	/** The (name, type, value) of every record in the test zone, sorted. */
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

	private function pending( int $id, string $hook ): int {
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' ) );
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	private function change( string $sub = 'shop' ): array {
		return array( 'app_domain_mode' => 'change', 'app_zone' => self::ZONE, 'app_sub' => $sub );
	}

	private function own( string $name = self::OWN ): array {
		return array( 'app_domain_mode' => 'own', 'app_own' => $name );
	}

	private function started( Resource $r, array $args ): StepResult {
		return AppDomainChange::start( $r, $args );
	}

	/** Runs the job until it has nothing left to do right now. */
	private function job( Resource $r ): void {
		AppDomainChange::run( $r->id() );
	}

	// -- DNS writes ---------------------------------------------------------------------------

	public function test_a_zone_name_is_written_only_in_a_zone_of_the_customer_and_only_when_free(): void {
		$r = $this->server();

		// A zone that is not the customer's: refused by check() and by start(), nothing is written.
		$foreign = array( 'app_domain_mode' => 'change', 'app_zone' => 'not-ours.example', 'app_sub' => 'shop' );
		$this->assertSame( 'Choose one of your domains that uses our name servers.', AppDomainChange::check( $r, $foreign ) );
		$this->assertTrue( $this->started( $r, $foreign )->is_refusal() );
		$this->assertSame( array(), $this->rrsets() );

		// A name that has an address record of any kind: refused before any write.
		foreach ( array( 'A' => '192.0.2.99', 'AAAA' => '2001:db8::1', 'CNAME' => 'other.example.' ) as $type => $value ) {
			$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'busy', 'type' => $type, 'ttl' => 300, 'value' => $value ) ) ) );
			$before = $this->records();
			$this->assertSame( 'busy.mine.example already has a DNS record. Choose another subdomain.', AppDomainChange::check( $r, $this->change( 'busy' ) ), $type );
			$this->assertTrue( $this->started( $r, $this->change( 'busy' ) )->is_refusal(), $type );
			$this->assertSame( $before, $this->records(), 'Nothing changed for ' . $type );
			$this->assertSame( '', (string) $this->fresh( $r )->get( 'app_chg_state' ) );
		}

		// A free name: one A record, ttl 300, at the server address, and nothing else.
		$before = $this->records();
		$result = $this->started( $r, $this->change( 'shop' ) );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( $this->sorted( array_merge( $before, array( 'shop.mine.example A ' . self::IP ) ) ), $this->records() );
		$this->assertContains( 'shop.mine.example A ' . self::IP, $this->records() );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'applying', $fresh->get( 'app_chg_state' ) );
		$this->assertTrue( $fresh->get( 'app_chg_made' ) );
		$this->assertNotFalse( as_has_scheduled_action( AppDomainChange::HOOK, array( $r->id() ), Jobs::GROUP ) );
	}

	private function sorted( array $list ): array {
		sort( $list );
		return $list;
	}

	public function test_the_old_meta_stays_until_the_guest_reports_ready(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE, 'app_domain_made' => true ) );
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ) ) ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );

		$this->job( $r );
		$this->assertCount( 1, $this->guest->pushed );
		$want = $this->guest->pushed[0];
		$this->assertSame( 'shop.mine.example', $want['domain'] );
		$this->assertSame( 'old.mine.example', $want['old_domain'] );
		$this->assertSame( self::IP, $want['ip'] );
		$this->assertSame( 'wordpress', $want['app'] );
		$this->assertSame( $want['run'], $this->fresh( $r )->get( 'app_chg_run' ) );

		// The guest has not answered, then says it is running: still the old name.
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'running', 'step' => 'certificate', 'reason' => 'none', 'domain' => 'shop.mine.example' );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'old.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertTrue( $fresh->get( 'app_domain_made' ) );
		$this->assertSame( 'applying', $fresh->get( 'app_chg_state' ) );
		$this->assertCount( 1, $this->guest->pushed, 'The request is handed over once.' );

		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'shop.mine.example' );
		$this->job( $r );
		$this->assertSame( 'shop.mine.example', $this->fresh( $r )->get( 'app_domain' ) );
	}

	public function test_commit_sets_the_new_name_and_queues_only_records_we_wrote(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE, 'app_domain_made' => true ) );
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ) ) ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'shop.mine.example' );
		$this->job( $r );

		$fresh = $this->fresh( $r );
		$this->assertSame( 'shop.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertSame( self::ZONE, $fresh->get( 'app_zone' ) );
		$this->assertTrue( $fresh->get( 'app_domain_made' ) );
		$this->assertSame( '', (string) $fresh->get( 'app_chg_state' ) );
		$this->assertNull( $fresh->get( 'app_chg_name' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'app_domain_changed_at' ) );
		$this->assertSame( array( array( 'zone' => self::ZONE, 'name' => 'old.mine.example', 'ip' => self::IP, 'tries' => 0 ) ), $fresh->get( 'app_domain_old' ) );
		$this->assertNotFalse( as_has_scheduled_action( AppDomainRelease::HOOK, array( $r->id() ), Jobs::GROUP ) );

		// The release job removes the old record (it is still {300, ip}) and keeps the new one.
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array( 'shop.mine.example A ' . self::IP ), $this->records() );
	}

	public function test_commit_leaves_an_old_record_that_was_not_ours_and_an_own_old_name(): void {
		$fired = array();
		add_action( 'beltoft_services_app_domain_changed', static function ( $r, $old, $new ) use ( &$fired ) {
			$fired[] = array( $old, $new );
		}, 10, 3 );

		// Old name in our zone, but the record was not ours (adopted at install): queued nothing, noted as left.
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE, 'app_domain_made' => false ) );
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ) ) ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'shop.mine.example' );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'shop.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertEmpty( (array) $fresh->get( 'app_domain_old' ) );
		$this->assertSame( array( 'zone' => self::ZONE, 'name' => 'old.mine.example' ), $fresh->get( 'app_domain_left' ) );
		$this->assertContains( 'old.mine.example A ' . self::IP, $this->records(), 'The record that was not ours stays.' );
		$this->assertSame( array( array( 'old.mine.example', 'shop.mine.example' ) ), $fired );

	}

	public function test_commit_from_an_own_old_name_queues_nothing_and_notes_nothing(): void {
		$o = $this->server( array_merge( array_filter( AppOwnDomain::fresh_wait( self::OWN ), static fn( $v ) => null !== $v ), array( 'app_domain' => self::OWN, 'app_domain_made' => false, 'app_domain_dns' => 'live' ) ) );
		$this->assertTrue( $this->started( $o, $this->change( 'two' ) )->is_done() );
		$this->job( $o );
		$this->job( $o );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'two.mine.example' );
		$this->job( $o );
		$fresh = $this->fresh( $o );
		$this->assertSame( 'two.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertEmpty( (array) $fresh->get( 'app_domain_old' ) );
		$this->assertNull( $fresh->get( 'app_domain_left' ) );
		$this->assertNull( $fresh->get( 'app_domain_own' ) );
	}

	public function test_a_failed_guest_run_removes_the_record_we_wrote_for_the_new_name_and_keeps_the_old_one(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE, 'app_domain_made' => true ) );
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ) ) ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->assertContains( 'shop.mine.example A ' . self::IP, $this->records() );
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'failed', 'step' => 'rollback', 'reason' => 'certificate', 'domain' => 'shop.mine.example' );
		$this->job( $r );

		$fresh = $this->fresh( $r );
		$this->assertSame( 'failed', $fresh->get( 'app_chg_state' ) );
		$this->assertSame( 'certificate', $fresh->get( 'app_chg_reason' ) );
		$this->assertSame( 'old.mine.example', $fresh->get( 'app_domain' ), 'The old name and its meta are untouched.' );
		$this->assertTrue( $fresh->get( 'app_domain_made' ) );
		$this->assertSame( array( array( 'zone' => self::ZONE, 'name' => 'shop.mine.example', 'ip' => self::IP, 'tries' => 0 ) ), $fresh->get( 'app_domain_old' ) );

		AppDomainRelease::run( $r->id() );
		$this->assertSame( array( 'old.mine.example A ' . self::IP ), $this->records(), 'Only the record we wrote for the new name is removed.' );
	}

	public function test_a_failure_does_not_remove_a_record_that_was_there_before(): void {
		$r = $this->server();
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ) ) ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->assertFalse( $this->fresh( $r )->get( 'app_chg_made' ), 'An adopted record is not marked ours.' );
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'failed', 'step' => 'rollback', 'reason' => 'certificate', 'domain' => 'shop.mine.example' );
		$this->job( $r );
		$this->assertEmpty( (array) $this->fresh( $r )->get( 'app_domain_old' ) );
		$this->assertContains( 'shop.mine.example A ' . self::IP, $this->records() );
	}

	public function test_a_record_the_customer_edited_in_between_is_not_removed(): void {
		$r = $this->server();
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'failed', 'step' => 'rollback', 'reason' => 'caddy', 'domain' => 'shop.mine.example' );
		$this->job( $r );
		// The customer made the name theirs: another address, and a TXT beside it.
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.77' ) ) ) );
		AppDomainRelease::run( $r->id() );
		$this->assertContains( 'shop.mine.example A 192.0.2.77', $this->records() );
	}

	public function test_a_silent_guest_and_a_refused_push_end_as_failed_with_the_old_name_working(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->job( $r );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_chg_at' => time() - AppDomainChange::APPLY_WAIT - 5 ) ) );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'failed', $fresh->get( 'app_chg_state' ) );
		$this->assertSame( 'agent', $fresh->get( 'app_chg_reason' ) );
		$this->assertSame( 'old.mine.example', $fresh->get( 'app_domain' ) );

	}

	public function test_a_refused_push_ends_as_failed_after_the_tries(): void {
		$p = $this->server();
		$this->guest->push_fails = true;
		$this->assertTrue( $this->started( $p, $this->change( 'two' ) )->is_done() );
		for ( $i = 0; $i < AppDomainChange::PUSH_TRIES; $i++ ) {
			$this->job( $p );
		}
		$this->assertSame( 'failed', $this->fresh( $p )->get( 'app_chg_state' ) );
		$this->assertSame( 'agent', $this->fresh( $p )->get( 'app_chg_reason' ) );
		$this->assertSame( array(), $this->guest->pushed );
	}

	// -- Own domain ---------------------------------------------------------------------------

	public function test_own_domain_waits_reads_dns_at_the_bounded_rate_and_never_writes_dns(): void {
		$r      = $this->server();
		$before = $this->records();
		$store  = get_option( FakeDns::OPTION );
		$this->assertTrue( $this->started( $r, $this->own() )->is_done() );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'waiting', $fresh->get( 'app_chg_state' ) );
		$this->assertSame( self::OWN, $fresh->get( 'app_chg_name' ) );
		$this->assertNull( $fresh->get( 'app_chg_made' ) );

		// None, other, aaaa: each a plain reason, one read apiece, gap between reads honoured.
		$cases = array(
			'none'  => array(),
			'other' => array( 'A elsewhere.test' => array( '192.0.2.99' ) ),
			'aaaa'  => array( 'A elsewhere.test' => array( self::IP ), 'AAAA elsewhere.test' => array( '2001:db8::1' ) ),
		);
		foreach ( $cases as $reason => $answers ) {
			$this->public  = $answers;
			$this->lookups = array();
			$this->job( $r );
			$this->assertSame( $reason, $this->fresh( $r )->get( 'app_chg_reason' ), $reason );
			$this->assertSame( 'waiting', $this->fresh( $r )->get( 'app_chg_state' ) );
			$this->assertNotEmpty( $this->lookups );
			// A second run at once reads nothing.
			$this->lookups = array();
			$this->job( $r );
			$this->assertSame( array(), $this->lookups, 'Gap of MIN_GAP: no second read.' );
			$this->assertTrue( $this->fresh( $r )->set( array( 'app_chg_checked' => time() - AppOwnDomain::MIN_GAP - 1 ) ) );
		}
		$this->assertSame( array(), $this->guest->pushed );

		// Live: only now the guest is asked.
		$this->public = array( 'A elsewhere.test' => array( self::IP ) );
		$this->job( $r );
		$this->assertSame( 'applying', $this->fresh( $r )->get( 'app_chg_state' ) );
		$this->job( $r );
		$this->assertCount( 1, $this->guest->pushed );
		$this->assertSame( self::OWN, $this->guest->pushed[0]['domain'] );
		$this->assertSame( $before, $this->records(), 'No DNS record was written.' );
		$this->assertSame( $store, get_option( FakeDns::OPTION ), 'The DNS store was not written at all.' );
	}

	public function test_own_domain_that_never_resolves_times_out_with_everything_unchanged(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE, 'app_domain_made' => true ) );
		$this->assertTrue( $this->started( $r, $this->own() )->is_done() );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_chg_since' => time() - ( AppOwnDomain::WAIT_DAYS * DAY_IN_SECONDS ) - 10 ) ) );
		$this->lookups = array();
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'failed', $fresh->get( 'app_chg_state' ) );
		$this->assertSame( 'timeout', $fresh->get( 'app_chg_reason' ) );
		$this->assertSame( 'old.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertTrue( $fresh->get( 'app_domain_made' ) );
		$this->assertEmpty( (array) $fresh->get( 'app_domain_old' ) );
		$this->assertSame( array(), $this->lookups );
		$this->assertSame( array(), $this->guest->pushed );
	}

	public function test_own_domain_commit_sends_the_existing_live_email_once(): void {
		$r = $this->server();
		$this->public = array( 'A elsewhere.test' => array( self::IP ) );
		$this->assertTrue( $this->started( $r, $this->own() )->is_done() );
		$this->job( $r );
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => self::OWN );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( self::OWN, $fresh->get( 'app_domain' ) );
		$this->assertSame( self::OWN, $fresh->get( 'app_domain_own' ) );
		$this->assertSame( 'live', $fresh->get( 'app_domain_dns' ) );
		$this->assertFalse( $fresh->get( 'app_domain_made' ) );
		$this->assertNull( $fresh->get( 'app_zone' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'Your app is live on your domain', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'https://' . self::OWN, wp_strip_all_tags( $this->mails[0]['message'] ) );
		AppOwnDomain::installed( $this->fresh( $r ) );
		$this->job( $r );
		$this->assertCount( 1, $this->mails, 'Once.' );
	}

	public function test_a_name_in_our_zone_sends_no_email(): void {
		$r = $this->server();
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->job( $r );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'shop.mine.example' );
		$this->job( $r );
		$this->assertSame( 'shop.mine.example', $this->fresh( $r )->get( 'app_domain' ) );
		$this->assertCount( 0, $this->mails );
	}

	// -- Concurrency --------------------------------------------------------------------------

	public function test_a_reinstall_is_refused_while_applying_and_drops_a_waiting_change(): void {
		$r = $this->server();
		$this->assertTrue( $this->started( $r, $this->own() )->is_done() );
		$r = $this->fresh( $r );
		// Waiting for DNS does not stop a reinstall, and the wait is dropped with the wiped app.
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) ) );
		$meta = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$this->assertArrayHasKey( 'app_chg_state', $meta );
		$this->assertNull( $meta['app_chg_state'] );
		$this->assertNull( $meta['app_chg_name'] );

		$this->assertTrue( $r->set( array( 'app_chg_state' => 'applying' ) ) );
		$r = $this->fresh( $r );
		$this->assertSame( 'The domain of the app is being changed. Try again in a few minutes.', AppReinstall::check( null, $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) ) );
		$this->assertSame( 'The domain of the app is being changed. Try again in a few minutes.', Actions::check( $r, 'reinstall', array( 'confirm' => Actions::CONFIRM, 'app_version' => 'keep' ) ) );
	}

	public function test_a_second_change_and_a_running_www_add_are_refused(): void {
		$r = $this->server();
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->assertSame( 'A change of domain is already in progress.', AppDomainChange::check( $this->fresh( $r ), $this->change( 'other' ) ) );
		$this->assertTrue( $this->started( $this->fresh( $r ), $this->change( 'other' ) )->is_refusal() );
		$this->assertNotContains( 'other.mine.example A ' . self::IP, $this->records() );

		$w = $this->server( array( 'app_www' => 'applying' ), 'wordpress', 5001, false );
		$this->assertSame( 'A www name is being added. Try again in a few minutes.', AppDomainChange::check( $w, $this->own() ) );
	}

	public function test_delete_during_a_change_queues_the_pending_records_and_holds_the_ip(): void {
		$r = $this->server();
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$r = $this->fresh( $r );
		$this->assertTrue( AppDomainRelease::hold_ip( false, $r ) );
		$this->assertTrue( $this->fresh( $r )->get( 'app_ip_hold' ) );

		$r->transition( 'deleted', 'Deleted' );
		$r->close();
		do_action( 'beltoft_services_state_changed', Resource::find( $r->id() ), 'deleting', 'deleted' );
		$queued = (array) Resource::find( $r->id() )->get( 'app_domain_old' );
		$this->assertCount( 1, $queued );
		$this->assertSame( 'shop.mine.example', $queued[0]['name'] );
		$this->assertTrue( $queued[0]['final'] );
		$this->assertNotFalse( as_has_scheduled_action( AppDomainRelease::HOOK, array( $r->id() ), Jobs::GROUP ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), $this->records(), 'Our record for the new name is gone with the server.' );
	}

	public function test_pause_hold_and_pause_all_stop_every_phase(): void {
		$r = $this->server();
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$o = $this->server( array(), 'wordpress', 5001, false );
		$this->assertTrue( $this->started( $o, $this->own() )->is_done() );
		$this->lookups = array();

		// The kill switch: nothing is read, pushed or changed, and a start is told to wait.
		$this->settings( array( 'pause_all' => 1 ) );
		$this->job( $r );
		$this->job( $o );
		$this->assertSame( array(), $this->guest->pushed );
		$this->assertSame( array(), $this->lookups );
		$this->assertTrue( $this->started( $this->server( array(), 'wordpress', 5002, false ), $this->change( 'x' ) )->is_retry() );
		$this->settings( array( 'pause_all' => 0 ) );

		// A hold (breach): the same.
		$this->assertTrue( $this->fresh( $r )->set( array( 'breach_at' => time() ) ) );
		$this->assertTrue( $this->fresh( $o )->set( array( 'breach_at' => time() ) ) );
		$this->job( $r );
		$this->job( $o );
		$this->assertSame( array(), $this->guest->pushed );
		$this->assertSame( array(), $this->lookups );
		$this->assertSame( 'applying', $this->fresh( $r )->get( 'app_chg_state' ) );
		$this->assertSame( 'waiting', $this->fresh( $o )->get( 'app_chg_state' ) );

		// A start on a held server is refused.
		$h = $this->server( array( 'breach_at' => time() ), 'wordpress', 5003, false );
		$this->assertTrue( $this->started( $h, $this->change( 'y' ) )->is_refusal() );
	}

	public function test_the_agent_down_or_server_stopped_refuses_the_action(): void {
		$r = $this->server( array( 'agent' => 'missing' ) );
		$this->assertSame( 'The domain cannot be changed on this server right now. Try again later or contact support.', AppDomainChange::check( $r, $this->change() ) );
		$this->assertNotNull( Actions::check( $r, 'app_domain', $this->change() ) );

		$ok = $this->server( array(), 'wordpress', 5001, false );
		$this->guest->unsupported = 'The server is not running.';
		$this->assertSame( 'The server is not running.', AppDomainChange::check( $ok, $this->change() ) );
		$this->assertTrue( $this->started( $ok, $this->change() )->is_refusal() );
		$this->assertSame( array(), $this->rrsets() );
	}

	public function test_changing_back_to_a_name_with_a_pending_removal_is_adopted_not_removed(): void {
		// The server was moved from shop to new; the removal of shop is still queued. Now it goes back to shop.
		$r = $this->server( array( 'app_domain' => 'new.mine.example', 'app_zone' => self::ZONE, 'app_domain_made' => true, 'app_domain_old' => array( array( 'zone' => self::ZONE, 'name' => 'shop.mine.example', 'ip' => self::IP, 'tries' => 0 ) ) ) );
		$this->assertTrue( DnsRecords::replace( self::ZONE, array(
			array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ),
			array( 'name' => 'new', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ),
		) ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->assertFalse( $this->fresh( $r )->get( 'app_chg_made' ), 'The record already points here: adopted, not marked ours.' );

		// The queued removal of shop must not take the record away while the change is under way.
		AppDomainRelease::run( $r->id() );
		$this->assertContains( 'shop.mine.example A ' . self::IP, $this->records() );

		$this->job( $r );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'shop.mine.example' );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'shop.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertFalse( $fresh->get( 'app_domain_made' ), 'Conservative: left, never removed.' );
		// Whatever is queued now (the old name new, which was ours) runs; shop stays.
		AppDomainRelease::run( $r->id() );
		AppDomainRelease::run( $r->id() );
		AppDomainRelease::run( $r->id() );
		$this->assertContains( 'shop.mine.example A ' . self::IP, $this->records() );
		$this->assertNotContains( 'new.mine.example A ' . self::IP, $this->records() );
	}

	// -- www ---------------------------------------------------------------------------------

	public function test_the_www_follow_up_is_blocked_while_a_change_runs_and_resumes_after_commit(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE, 'app_www' => 'waiting', 'app_www_reason' => 'none', 'app_recipe' => 2 ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->lookups = array();
		AppWww::run( $r->id() );
		$this->assertSame( array(), $this->lookups, 'The www look reads nothing while the change runs.' );
		$this->assertSame( 'waiting', $this->fresh( $r )->get( 'app_www' ) );
		$this->assertNotFalse( as_has_scheduled_action( AppWww::HOOK, array( $r->id() ), Jobs::GROUP ), 'The look is queued again.' );
	}

	public function test_a_bare_domain_gets_its_www_record_and_the_guest_is_told(): void {
		$r = $this->server( array( 'app_recipe' => 2 ) );
		$this->assertTrue( $this->started( $r, $this->change( '' ) )->is_done() );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'www.mine.example', $fresh->get( 'app_chg_www' ) );
		$this->assertTrue( $fresh->get( 'app_chg_www_made' ) );
		$this->assertSame( array( 'mine.example A ' . self::IP, 'www.mine.example A ' . self::IP ), $this->records() );
		$this->job( $r );
		$this->assertSame( 'www.mine.example', $this->guest->pushed[0]['www'] );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'mine.example' );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'live', $fresh->get( 'app_www' ) );
		$this->assertTrue( $fresh->get( 'app_www_made' ) );
		$this->assertSame( self::IP, $fresh->get( 'app_www_ip' ) );
	}

	public function test_a_www_name_with_a_record_of_its_own_is_left_to_the_follow_up(): void {
		$r = $this->server( array( 'app_recipe' => 2 ) );
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.99' ) ) ) );
		$this->assertTrue( $this->started( $r, $this->change( '' ) )->is_done() );
		$this->assertEmpty( (string) $this->fresh( $r )->get( 'app_chg_www' ) );
		$this->assertContains( 'www.mine.example A 192.0.2.99', $this->records(), 'Never touched.' );
		$this->job( $r );
		$this->assertSame( '', $this->guest->pushed[0]['www'] );
		$this->guest->answer = array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'mine.example' );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'waiting', $fresh->get( 'app_www' ), 'The existing follow-up adds it later.' );
		$this->assertNotFalse( as_has_scheduled_action( AppWww::HOOK, array( $r->id() ), Jobs::GROUP ) );
	}

	// -- The action --------------------------------------------------------------------------

	public function test_the_action_is_offered_only_for_apps_with_domain_and_a_ready_app_and_has_a_rate_floor(): void {
		$wp = $this->server();
		$this->assertArrayHasKey( 'app_domain', Actions::offered( $wp ) );
		$this->assertSame( 'Change domain', Actions::offered( $wp )['app_domain'] );
		$this->assertArrayNotHasKey( 'app_domain', Actions::buttons( Actions::offered( $wp ), $wp ), 'Only the App tab draws it.' );

		$this->assertArrayHasKey( 'app_domain', Actions::offered( $this->server( array(), 'n8n', 5001, false ) ) );
		$this->assertArrayNotHasKey( 'app_domain', Actions::offered( $this->server( array(), 'coolify', 5002, false ) ) );
		$this->assertArrayNotHasKey( 'app_domain', Actions::offered( $this->server( array(), 'docker', 5003, false ) ) );
		$this->assertArrayNotHasKey( 'app_domain', Actions::offered( $this->server( array( 'app_state' => 'installing' ), 'wordpress', 5004, false ) ) );
		$this->assertArrayNotHasKey( 'app_domain', Actions::offered( $this->server( array( 'app_state' => 'failed' ), 'wordpress', 5005, false ) ) );
		$this->assertArrayNotHasKey( 'app_domain', Actions::offered( $this->server( array( 'app_id' => '', 'app_state' => null ), 'wordpress', 5006, false ) ) );

		// The floor: five an hour.
		wp_set_current_user( (int) $wp->license()['user_id'] );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( Actions::floor_exceeded( $wp, 'app_domain' ), (string) $i );
		}
		$this->assertTrue( Actions::floor_exceeded( $wp, 'app_domain' ) );
	}

	public function test_the_action_runs_through_the_one_action_path(): void {
		$r = $this->server();
		$refusal = Actions::refusal( null, $r, 'app_domain', $this->change( 'shop' ) );
		$this->assertNull( $refusal );
		$this->assertInstanceOf( \WP_Error::class, Actions::refusal( null, $r, 'app_domain', array( 'app_domain_mode' => 'none' ) ), 'No domain is not offered on a running app.' );
		$result = ( new Actions( $r ) )->run( 'app_domain', $this->change( 'shop' ) );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( 'applying', $this->fresh( $r )->get( 'app_chg_state' ) );
	}

	public function test_none_is_never_accepted_on_a_running_app(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE ) );
		$this->assertSame( 'Choose a domain option.', AppDomainChange::check( $r, array( 'app_domain_mode' => 'none' ) ) );
		$this->assertSame( 'Choose a domain option.', AppDomainChange::check( $r, array( 'app_domain_mode' => 'keep' ) ) );
		$this->assertSame( 'Choose a domain option.', AppDomainChange::check( $r, array() ) );
		$this->assertSame( 'That is already the address of the app.', AppDomainChange::check( $r, array( 'app_domain_mode' => 'change', 'app_zone' => self::ZONE, 'app_sub' => 'old' ) ) );
		$this->assertSame( 'That is already the address of the app.', AppDomainChange::check( $r, $this->own( 'old.mine.example' ) ) );
	}

	public function test_the_view_gives_the_tab_what_it_needs(): void {
		$r = $this->server();
		$view = AppDomainChange::view( $r );
		$this->assertTrue( $view['offered'] );
		$this->assertTrue( $view['supported'] );
		$this->assertSame( array( self::ZONE ), $view['zones'] );
		$this->assertSame( '', $view['state'] );
		$this->assertSame( self::IP, $view['ip'] );

		$this->assertTrue( $this->started( $r, $this->own() )->is_done() );
		$view = AppDomainChange::view( $this->fresh( $r ) );
		$this->assertSame( 'waiting', $view['state'] );
		$this->assertSame( 'own', $view['mode'] );
		$this->assertSame( self::OWN, $view['name'] );
		$this->assertGreaterThan( time() + 13 * DAY_IN_SECONDS, $view['deadline'] );

		$this->guest->unsupported = 'Reinstall the app to change its domain.';
		$view = AppDomainChange::view( $this->server( array(), 'wordpress', 5001, false ) );
		$this->assertFalse( $view['supported'] );
		$this->assertSame( 'Reinstall the app to change its domain.', $view['why'] );
		$this->assertFalse( AppDomainChange::view( $this->server( array(), 'coolify', 5002, false ) )['offered'] );
	}

	public function test_the_watchdog_lists_a_lost_change_and_not_a_finished_one(): void {
		$r = $this->server();
		$this->assertSame( array(), AppDomainChange::due() );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->assertContains( array( $r->id() ), AppDomainChange::due() );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_chg_state' => 'failed' ) ) );
		$this->assertNotContains( array( $r->id() ), AppDomainChange::due() );
		$this->assertSame( 0, $this->pending( 999999, AppDomainChange::HOOK ) );
		AppInstall::class;
	}

	// -- The real chain: AppDomainChange -> AppRename -> FakeClient (no double) ----------------------------

	/** Takes the double away: the guest side is AppRename on the fake client. */
	private function real_chain(): void {
		AppDomainChange::$guest = null;
		add_filter( 'beltoft_services_rename_wait', '__return_zero' );
	}

	private function chain_server( array $meta = array() ): Resource {
		$this->real_chain();
		$r = $this->server( array_merge( array( 'app_domain' => 'old.mine.example', 'app_zone' => self::ZONE, 'app_domain_made' => true ), $meta ) );
		$this->assertTrue( DnsRecords::replace( self::ZONE, array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => self::IP ) ) ) );
		return $r;
	}

	public function test_chain_a_zone_name_goes_through_the_guest_and_is_committed(): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, 'ok', array( 'polls' => 1 ) );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );

		$this->job( $r ); // The request is pushed.
		$want = json_decode( (string) $this->fake->guest_file( 5000, \BeltoftServices\Apps\AppRename::WANT ), true );
		$this->assertSame( 'shop.mine.example', $want['domain'] );
		$this->assertSame( 'old.mine.example', $want['old_domain'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}$/', $want['run'] );
		$this->assertSame( $want['run'], $this->fresh( $r )->get( 'app_chg_run' ), 'The token push() made is the one that is stored.' );
		$this->assertSame( array( 'run' => $want['run'], 'domain' => 'shop.mine.example' ), $this->fresh( $r )->get( \BeltoftServices\Apps\AppRename::ASKED ) );

		$this->job( $r ); // The guest says running.
		$this->assertSame( 'old.mine.example', $this->fresh( $r )->get( 'app_domain' ) );
		$this->assertSame( 'applying', $this->fresh( $r )->get( 'app_chg_state' ) );
		$this->job( $r ); // Ready.
		$fresh = $this->fresh( $r );
		$this->assertSame( 'shop.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertSame( '', (string) $fresh->get( 'app_chg_state' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'app_domain_changed_at' ) );
		$this->assertCount( 1, $this->fake->guest_execs( 5000 ), 'The guest was started once.' );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array( 'shop.mine.example A ' . self::IP ), $this->records() );
	}

	public function test_chain_a_bare_domain_is_served_with_its_www_name(): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, 'ok' );
		$this->assertTrue( $this->started( $r, $this->change( '' ) )->is_done() );
		$this->assertContains( 'mine.example A ' . self::IP, $this->records() );
		$this->assertContains( 'www.mine.example A ' . self::IP, $this->records() );
		$this->job( $r );
		$want = json_decode( (string) $this->fake->guest_file( 5000, \BeltoftServices\Apps\AppRename::WANT ), true );
		$this->assertSame( 'mine.example', $want['domain'] );
		$this->assertSame( 'www.mine.example', $want['www'] );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'mine.example', $fresh->get( 'app_domain' ) );
		$this->assertSame( 'live', $fresh->get( 'app_www' ) );
	}

	public function test_chain_a_certificate_failure_removes_our_record_and_keeps_the_old_address(): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, 'certificate' );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->job( $r );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'failed', $fresh->get( 'app_chg_state' ) );
		$this->assertSame( 'certificate', $fresh->get( 'app_chg_reason' ) );
		$this->assertSame( 'old.mine.example', $fresh->get( 'app_domain' ) );
		$this->assertTrue( $fresh->get( 'app_domain_made' ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array( 'old.mine.example A ' . self::IP ), $this->records() );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function failure_modes(): array {
		return array( 'caddy' => array( 'caddy', 'caddy' ), 'app' => array( 'app', 'app' ), 'probe' => array( 'probe', 'probe' ), 'busy' => array( 'busy', 'busy' ), 'rollback_failed' => array( 'rollback_failed', 'app' ) );
	}

	/** @dataProvider failure_modes */
	public function test_chain_the_other_failures_map_onto_reasons_the_view_knows( string $mode, string $reason ): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, $mode );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done(), $mode );
		$this->job( $r );
		$this->job( $r );
		$this->assertSame( 'failed', $this->fresh( $r )->get( 'app_chg_state' ), $mode );
		$this->assertSame( $reason, $this->fresh( $r )->get( 'app_chg_reason' ), $mode );
		$this->assertSame( $reason, AppDomainChange::view( $this->fresh( $r ) )['reason'], $mode );
		$this->assertSame( 'old.mine.example', $this->fresh( $r )->get( 'app_domain' ), $mode );
	}

	/** @return array<string, array{0: string}> */
	public static function silent_modes(): array {
		return array( 'hang' => array( 'hang' ), 'no_status' => array( 'no_status' ), 'other_run' => array( 'other_run' ) );
	}

	/** @dataProvider silent_modes */
	public function test_chain_a_guest_that_never_answers_ends_by_the_wait_limit_as_agent( string $mode ): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, $mode );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done(), $mode );
		$this->job( $r );
		$this->job( $r );
		$this->assertSame( 'applying', $this->fresh( $r )->get( 'app_chg_state' ), $mode );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_chg_at' => time() - AppDomainChange::APPLY_WAIT - 5 ) ) );
		$this->job( $r );
		$this->assertSame( 'failed', $this->fresh( $r )->get( 'app_chg_state' ), $mode );
		$this->assertSame( 'agent', $this->fresh( $r )->get( 'app_chg_reason' ), $mode );
		$this->assertSame( 'old.mine.example', $this->fresh( $r )->get( 'app_domain' ), $mode );
	}

	public function test_chain_a_guest_that_refuses_exec_ends_as_agent_after_the_tries(): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, 'blocked' );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		for ( $i = 0; $i < AppDomainChange::PUSH_TRIES; $i++ ) {
			$this->job( $r );
		}
		$this->assertSame( 'failed', $this->fresh( $r )->get( 'app_chg_state' ) );
		$this->assertSame( 'agent', $this->fresh( $r )->get( 'app_chg_reason' ) );
		$this->assertSame( 'old.mine.example', $this->fresh( $r )->get( 'app_domain' ) );
	}

	public function test_chain_an_own_domain_waits_for_dns_then_goes_through_the_guest(): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, 'ok' );
		$this->assertTrue( $this->started( $r, $this->own() )->is_done() );
		$this->job( $r );
		$this->assertSame( 'waiting', $this->fresh( $r )->get( 'app_chg_state' ) );
		$this->assertNull( $this->fake->guest_file( 5000, \BeltoftServices\Apps\AppRename::WANT ), 'Nothing is sent to the guest while the name does not point here.' );

		$this->public[ 'A ' . self::OWN ] = array( self::IP );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_chg_checked' => time() - 3600 ) ) );
		$this->job( $r );
		$this->assertSame( 'applying', $this->fresh( $r )->get( 'app_chg_state' ) );
		$this->job( $r );
		$this->job( $r );
		$fresh = $this->fresh( $r );
		$this->assertSame( self::OWN, $fresh->get( 'app_domain' ) );
		$this->assertSame( self::OWN, $fresh->get( 'app_domain_own' ) );
	}

	public function test_chain_a_retry_with_the_same_token_after_a_cut_off_run_converges(): void {
		$r = $this->chain_server();
		$this->fake->set_guest_rename( 5000, 'no_status' );
		$this->assertTrue( $this->started( $r, $this->change( 'shop' ) )->is_done() );
		$this->job( $r );
		$run = (string) $this->fresh( $r )->get( 'app_chg_run' );
		$this->assertNotSame( '', $run );
		$this->job( $r );
		$this->assertSame( 'applying', $this->fresh( $r )->get( 'app_chg_state' ) );
		$this->assertCount( 1, $this->fake->guest_execs( 5000 ), 'Looking again never starts the guest again.' );

		// The script, started again by the guest's own means with the same request, ends the run.
		$this->fake->set_guest_file( 5000, \BeltoftServices\Apps\AppRename::STATUS, (string) wp_json_encode( array( 'v' => 1, 'run' => $run, 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => 'shop.mine.example', 'updated' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) ) );
		$this->job( $r );
		$this->assertSame( 'shop.mine.example', $this->fresh( $r )->get( 'app_domain' ) );
		$this->assertCount( 1, $this->fake->guest_execs( 5000 ) );
	}
}

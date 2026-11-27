<?php
/**
 * Managed database public IPv4 add-on and allow-list. Only the fake client is used; mail goes to the owner's address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Db;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Addon\AddonCart;
use BeltoftServices\Proxmox\Managed\ManagedCart;
use BeltoftServices\Proxmox\Managed\ManagedDriver;
use BeltoftServices\Proxmox\Managed\ManagedMeta;
use BeltoftServices\Proxmox\Db\DbCard;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Db\DbGuest;
use BeltoftServices\Proxmox\Db\DbAccess;
use BeltoftServices\Proxmox\Db\DbIp;
use BeltoftServices\Proxmox\Db\DbNeedsServer;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\Stock;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Proxmox\Db\DbProfile;
use BeltoftServices\Proxmox\Vm\Profiles;
use BeltoftServices\Proxmox\Pve\FakeClient;
use BeltoftServices\Proxmox\Network\PrivateNets;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\ProxmoxJobs;
use BeltoftServices\Api\Routes;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;
use BeltoftServices\Proxmox\Backup\BackupJobs;

/**
 * A database driver that does not sleep.
 */
class Phase4DbDriver extends DbDriver {

	public function nap( int $seconds ): void {
		unset( $seconds );
	}
}

class DbPhase4Test extends TestCase {

	private const SECRET = 'secret-secret-secret-1';
	private const OWNER  = 'christian.beltoft@gmail.com';

	/** @var FakeClient */
	private $fake;

	/** @var string How the guest treats the listen job: new, old (refuses it) or silent. */
	private $listen_guest = 'new';

	/** @var string How the guest answers the private job: new (applies it) or old (refuses the job). */
	private $private_guest = 'new';

	/** @var array<int, array<string, mixed>> */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				unset( $pre );
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		add_filter( 'beltoft_services_proxmox_pause', fn() => function ( int $seconds ): void {
			$this->fake->elapse( $seconds );
		}, 20 );

		$this->wipe();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5, 'pause_all' => 0, 'admin_email' => self::OWNER, 'monitor_alerts' => 'off' ) );

		$this->fake = new FakeClient( true );
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		add_filter(
			'beltoft_services_drivers',
			function ( $drivers ) {
				$drivers['proxmox-db'] = new Phase4DbDriver();
				$drivers['proxmox']    = new ProxmoxDriver();
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );
		$this->fake->add_vm(
			array(
				'vmid'     => 9100,
				'node'     => 'node-a',
				'name'     => 'postgres-template',
				'template' => 1,
				'config'   => array(
					'scsi0'  => 'disk-store:vm-9100-disk-0,size=10G',
					'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
					'memory' => 512,
					'cores'  => 1,
				),
			)
		);
		$this->save_cluster();
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		set_transient( \BeltoftServices\Proxmox\Pve\Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'node-a', 'status' => 'online', 'maxmem' => 68719476736, 'mem' => 0 ) ), HOUR_IN_SECONDS );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		ProxmoxJobs::unschedule();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		remove_all_filters( 'beltoft_services_proxmox_pause' );
		remove_all_filters( 'pre_wp_mail' );
		delete_option( Clusters::OPTION );
		$this->wipe();
		ProxmoxJobs::unschedule();
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function wipe(): void {
		global $wpdb;

		foreach ( array( 'vms', 'ips', 'private_ips', 'private_nets' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()[ $table ] ) );
		}
	}

	/**
	 * @param array<string, mixed> $over Cluster overrides.
	 */
	private function save_cluster( array $over = array() ): void {
		$this->assertTrue(
			Clusters::save(
				'lab',
				array_merge(
					array(
						'label'                  => 'Lab cluster',
						'api_url'                => 'https://pve.example.invalid:8006',
						'token_id'               => 'svc@pve!beltoft',
						'token_secret'           => self::SECRET,
						'client'                 => 'fake',
						'pool_api'               => 'pools',
						'nodes'                  => 'node-a',
						'storage'                => 'disk-store',
						'cloudinit_storage'      => 'ci-store',
						'backup_storage'         => 'backup-store',
						'bridge'                 => 'vmbr9',
						'private_bridge'         => 'vmbr2',
						'boot_disk'              => 'scsi0',
						'gateway'                => '192.0.2.1',
						'nameservers'            => '192.0.2.53',
						'prefix'                 => '24',
						'vmid_min'               => '5000',
						'vmid_max'               => '5099',
						'templates'              => '9100 = PostgreSQL 18',
						'template_families_text' => '9100 = postgres-18',
						'vendor_snippet'         => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
						'db_vendor_snippet'      => 'local:snippets/vm-vendor-cloudinit-db-v1.yaml',
						'block_smtp'             => '1',
						// Every database has its public card with an IPv6 address: the cluster has a prefix.
						'ipv6_prefix'            => '2001:db8:430c:1::/64',
						'nameservers6'           => '2001:db8::53',
						'fw_enabled'             => '1',
						'fw_ipfilter'            => '1',
					),
					$over
				)
			)
		);

		// Jobs that walk the clusters only touch one that keeps its fake state.
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'persist' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );
	}

	/**
	 * A database that is built and ready, with a healthy guest.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function ready_db( array $plan = array() ): Resource {
		// A public IPv4 address is an IPv4 add-on now (the plan field public_ip is gone): `public_ip` => 1 links one.
		$with_ip = ! empty( $plan['public_ip'] );
		unset( $plan['public_ip'] );
		$product = $this->service_product(
			'proxmox-db',
			array_merge( array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20 ), $plan ),
			array( 'max' => 3 )
		);
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );

		if ( $with_ip ) {
			$this->public_ip_addon( $r );
		}

		$driver = Registry::get( 'proxmox-db' );
		$first  = $driver->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $first->is_poll(), $first->error() );
		$request = (string) Resource::find( $r->id() )->get( 'db_request' );
		$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $request, 'state' => 'ready', 'step' => 'complete', 'updated' => gmdate( 'c' ) ) ) );
		$this->fake->set_guest_file( 5000, DbGuest::CREDENTIALS, wp_json_encode( array( 'request' => $request, 'password' => 'Abcdefghijklmnopqrstuvwxyz012345', 'ca_pem' => "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ2VydGlmaWNhdGVGb3JUZXN0aW5nMAoGCCqGSM49BAMC\n-----END CERTIFICATE-----\n", 'fingerprint' => '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF' ) ) );
		$this->assertTrue( $driver->provision( Resource::find( $r->id() ) )->is_poll() );
		$this->health( array( 'ok' => true ) );
		$done = $driver->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertTrue( Resource::find( $r->id() )->set( array_merge( $done->meta(), array( 'state' => 'ready', 'desired' => 'ready', 'task' => null ) ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Writes the guest's health file.
	 *
	 * @param array<string, mixed> $over Values over a healthy reading.
	 */
	private function health( array $over = array(), bool $raw = false ): void {
		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, $raw ? (string) $over['raw'] : wp_json_encode( array_merge( array( 'updated' => gmdate( 'c' ), 'ok' => true, 'connections' => 3, 'disk_percent' => 10, 'last_dump' => time() - HOUR_IN_SECONDS, 'bans' => 0, 'cert_expires' => time() + 300 * DAY_IN_SECONDS ), $over ) ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function calls( string $method, string $suffix ): array {
		return array_values( array_filter( $this->fake->calls(), static fn( $c ) => $c['method'] === $method && substr( $c['path'], -strlen( $suffix ) ) === $suffix ) );
	}

	private function follow_up(): void {
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		BackupJobs::backup_next();
	}

	// -- Helpers ---------------------------------------------------------------------------------

	private function pool( int $count = 2, int $first = 10 ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			IpPool::add( '192.0.2.' . ( $first + $i ), '192.0.2.1' );
		}
	}

	/**
	 * A database resource that is only stored (nothing is built), for checks that need no VM.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function bare_db( array $plan = array() ): Resource {
		// A public IPv4 address is an IPv4 add-on now (the plan field public_ip is gone): `public_ip` => 1 links one.
		$with_ip = ! empty( $plan['public_ip'] );
		unset( $plan['public_ip'] );
		$product = $this->service_product(
			'proxmox-db',
			array_merge( array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20 ), $plan ),
			array( 'max' => 3 )
		);
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );

		if ( $with_ip ) {
			$this->public_ip_addon( $r );
		}
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'db_state' => 'ready' ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Answers the guest's allow job of the stored operation, like the guest would.
	 */
	private function answer_guest( Resource $r ): void {
		unset( $r );
		$job = json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );

		if ( ! is_array( $job ) || empty( $job['request'] ) || ! in_array( $job['op'] ?? '', array( 'allow', 'listen', 'private' ), true ) ) {
			return;
		}

		// The listen job: a guest that knows it applies it; one that does not refuses the job (step "job"); a silent one says nothing.
		if ( 'listen' === $job['op'] ) {
			if ( 'silent' === $this->listen_guest ) {
				return;
			}

			$old = 'old' === $this->listen_guest;
			$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $job['request'], 'state' => $old ? 'failed' : 'ready', 'step' => $old ? 'job' : 'listen', 'updated' => gmdate( 'c' ) ) ) );

			return;
		}

		if ( 'private' === $job['op'] && 'old' === $this->private_guest ) {
			$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $job['request'], 'state' => 'failed', 'step' => 'job', 'updated' => gmdate( 'c' ) ) ) );

			return;
		}

		$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $job['request'], 'state' => 'ready', 'step' => 'allow', 'updated' => gmdate( 'c' ) ) ) );
	}

	/**
	 * Runs a driver call until it is done or failed; the guest answers whatever job it was sent.
	 */
	private function drive( Resource $r, string $what = 'update_plan' ): \BeltoftServices\Drivers\StepResult {
		$driver = Registry::get( 'proxmox-db' );
		$res    = null;

		for ( $i = 0; $i < 60; $i++ ) {
			$this->answer_guest( $r );
			$fresh = Resource::find( $r->id() );
			$res   = 'update_plan' === $what ? $driver->update_plan( $fresh ) : $driver->act( $fresh, $what, array() );

			if ( $res->is_done() || $res->is_failed() ) {
				return $res;
			}

			$this->fake->elapse( 30 );
		}

		$this->fail( 'Did not settle: ' . $res->error() );
	}

	/**
	 * A ready database that has been given the public IP add-on (the plan changed, the VM followed).
	 */
	private function db_with_ip( array $plan = array() ): Resource {
		$this->pool( 3 );
		$r = $this->ready_db( $plan );
		$res = $this->add_ip( $r );
		$this->assertTrue( $res->is_done(), 'add_ip: ' . $res->error() . ' ' . wp_json_encode( Resource::find( $r->id() )->get( 'ipv4_op' ) ) );

		return Resource::find( $r->id() );
	}

	private function add_ip( Resource $r ): \BeltoftServices\Drivers\StepResult {
		$fresh = Resource::find( $r->id() );
		$this->public_ip_addon( $fresh );
		// The address is taken the way a server's is: the add-on holds it from the pool first (Ipv4Driver::take), then the server's step.
		$this->assertNotNull( IpPool::hold( $r->id() ) );

		$res = $this->drive( $r, \BeltoftServices\Proxmox\Ipv4\Ipv4Server::MAIN_ADD );

		if ( $res->is_done() ) {
			$this->assertTrue( Resource::find( $r->id() )->set( array_merge( $res->meta(), array( 'plan_dirty' => false ) ) ) );
		}

		return $res;
	}

	private function remove_ip( Resource $r ): \BeltoftServices\Drivers\StepResult {
		$fresh = Resource::find( $r->id() );
		$this->public_ip_off( $fresh );
		$res = $this->drive( $r, \BeltoftServices\Proxmox\Ipv4\Ipv4Server::MAIN_REMOVE );

		if ( $res->is_done() ) {
			$this->assertTrue( Resource::find( $r->id() )->set( array_merge( $res->meta(), array( 'plan_dirty' => false ) ) ) );
		}

		return $res;
	}

	/**
	 * The comments of the rules on net0.
	 *
	 * @return string[]
	 */
	private function net0_rules(): array {
		$vm = $this->fake->vm( 5000 );

		return array_values( array_map( static fn( $rule ) => (string) $rule['comment'], array_filter( (array) $vm['firewall_rules'], static fn( $rule ) => 'net0' === ( $rule['iface'] ?? '' ) ) ) );
	}

	/**
	 * A POST to the access route as the owner.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function post( Resource $r, array $body, ?int $user = null ): \WP_REST_Response {
		// These tests drive the driver's act() by hand, so the marker of the earlier request would stay: the route refuses a second change
		// while one is pending (Jobs::start_action), which DbMssqlTest covers.
		\BeltoftServices\Progress::clear( Resource::find( $r->id() ) ?? $r );
		wp_set_current_user( null === $user ? (int) Resource::find( $r->id() )->license()['user_id'] : $user );
		$request = new \WP_REST_Request( 'POST', '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/db-access' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	private function apply( Resource $r ): \BeltoftServices\Drivers\StepResult {
		return $this->drive( $r, DbAccess::APPLY );
	}

	// -- IpPool and stock ------------------------------------------------------------------------

	public function test_hold_keeps_the_reserve_for_servers(): void {
		$this->pool( 2 );

		$this->assertSame( 1, IpPool::reserve_for_vps(), 'The default keeps one address for servers.' );
		$this->assertSame( 1, IpPool::available_for_addon() );
		$this->assertNotNull( IpPool::hold( 9001, 1 ), 'Two free: one may go.' );
		$this->assertNull( IpPool::hold( 9002, 1 ), 'One free is the reserve.' );
		$this->assertSame( 0, IpPool::available_for_addon() );
		$this->assertNotNull( IpPool::hold( 9001, 1 ), 'A resource that holds an address keeps it.' );
		$this->assertNotNull( IpPool::hold( 9002 ), 'A server (no reserve) takes the last one.' );
		$this->assertSame( 0, IpPool::free_count() );
	}

	public function test_the_reserve_is_a_setting(): void {
		$this->pool( 1 );
		$this->assertSame( 0, IpPool::available_for_addon() );
		$this->settings( array( 'ip_reserve_for_vps' => 0 ) );
		$this->assertSame( 1, IpPool::available_for_addon() );
		$this->assertArrayHasKey( 'ip_reserve_for_vps', apply_filters( 'beltoft_services_settings_fields', array() ) );
	}

	public function test_the_profile_asks_for_an_address_only_with_the_add_on(): void {
		$without = new DbProfile( $this->bare_db() );
		$this->assertFalse( $without->uses_public_ip() );
		$this->assertSame( array( 'net', 'vmid', 'clone', 'configure', 'start', 'ready', 'db_init', 'db_ready' ), $without->steps() );
		$this->pool( 2 );
		$this->assertNull( $without->hold_address( 1 ) );
		$this->assertSame( 2, IpPool::free_count(), 'Nothing was taken from the pool.' );

		$with = new DbProfile( $this->bare_db( array( 'public_ip' => 1 ) ) );
		$this->assertTrue( $with->uses_public_ip() );
		$this->assertSame( array( 'ip', 'net', 'vmid', 'clone', 'configure', 'start', 'ready', 'db_init', 'db_ready' ), $with->steps() );
	}

	public function test_a_database_built_with_the_add_on_has_net0_net1_and_a_closed_firewall(): void {
		$this->pool( 2 );
		$r  = $this->ready_db( array( 'public_ip' => 1 ) );
		$vm = $this->fake->vm( 5000 );

		$this->assertStringContainsString( 'bridge=vmbr9', $vm['config']['net0'] );
		$this->assertStringContainsString( 'firewall=1', $vm['config']['net0'] );
		$this->assertSame( 'ip=192.0.2.10/24,gw=192.0.2.1,ip6=' . self::DB_V6 . '/64,gw6=2001:db8:430c:1::1', $vm['config']['ipconfig0'] );
		$this->assertStringContainsString( 'bridge=vmbr2', $vm['config']['net1'] );
		$this->assertSame( 'ip=10.64.0.2/28', $vm['config']['ipconfig1'] );

		$row = IpPool::for_resource( $r->id() );
		$this->assertSame( 'assigned', $row['state'] );
		$this->assertSame( '192.0.2.10', $r->get( 'ip' ) );
		$this->assertSame( '192.0.2.10', DbAccess::public_address( $r ) );
		$this->assertSame( $row['id'], VmRecords::for_resource( $r->id() )['ip_id'] );

		$job = json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );
		$this->assertSame( array( '10.64.0.2', '192.0.2.10' ), $job['listen'] );
		$this->assertSame( array(), $job['allow'] );
		$this->assertFalse( $job['world'] );

		$this->assertSame( array(), $this->net0_rules(), 'Nothing is allowed until the customer adds an address.' );
		$this->assertSame( 'DROP', $vm['firewall_options']['policy_in'] );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '10.64.0.7', 'net1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 22, '203.0.113.7', 'net0' ) );
	}

	// -- Adding and removing the public IP -------------------------------------------------------

	/**
	 * The jobs written to the guest so far, in order (the job file is replaced each time).
	 *
	 * @return array<int, array{at:int, job:array<string, mixed>}>
	 */
	private function guest_jobs(): array {
		$out = array();

		foreach ( $this->fake->calls() as $i => $c ) {
			if ( 'POST' === $c['method'] && '/agent/file-write' === substr( $c['path'], -17 ) && DbGuest::JOB === ( $c['params']['file'] ?? '' ) ) {
				$out[] = array( 'at' => $i, 'job' => (array) json_decode( (string) $c['params']['content'], true ) );
			}
		}

		return $out;
	}

	/**
	 * Index of the first call that matches, or null.
	 */
	private function first_call( callable $match ): ?int {
		foreach ( $this->fake->calls() as $i => $c ) {
			if ( $match( $c ) ) {
				return $i;
			}
		}

		return null;
	}

	public function test_adding_the_add_on_sends_the_listen_job_after_the_nic_and_before_anything_is_opened(): void {
		$this->pool( 3 );
		$r = $this->ready_db();
		$this->assertTrue( $this->add_ip( $r )->is_done() );

		$private = (string) Resource::find( $r->id() )->get( 'db_address' );
		$this->assertNotSame( '', $private );
		$listen = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'listen' === $j['job']['op'] ) );
		$this->assertCount( 1, $listen );
		$this->assertSame( array( $private, '192.0.2.10' ), $listen[0]['job']['listen'] );
		$this->assertSame( 1, $listen[0]['job']['v'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9-]{36}$/D', $listen[0]['job']['request'] );

		$nic = $this->first_call( static fn( $c ) => 'PUT' === $c['method'] && isset( $c['params']['net0'] ) );
		$this->assertNotNull( $nic );
		$this->assertGreaterThan( $nic, $listen[0]['at'], 'The guest is told after the network card exists.' );

		$allow = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'allow' === $j['job']['op'] ) );
		$this->assertNotEmpty( $allow );
		$this->assertLessThan( $allow[0]['at'], $listen[0]['at'], 'Before the access list is applied.' );

		$rule = $this->first_call( static fn( $c ) => in_array( $c['method'], array( 'POST', 'PUT' ), true ) && false !== strpos( $c['path'], '/firewall/' ) && '/firewall/options' !== substr( $c['path'], -17 ) && false !== strpos( wp_json_encode( $c['params'] ), 'bs-db' ) );
		$this->assertTrue( null === $rule || $rule > $listen[0]['at'], 'No firewall rule was written before the guest listened.' );
		$this->assertNull( Resource::find( $r->id() )->get( DbIp::OP ) );
	}

	public function test_a_blocked_address_is_never_taken_or_freed(): void {
		$this->pool( 3 );
		$this->assertTrue( IpPool::block( '192.0.2.10' ) );
		$r = $this->ready_db();
		$this->assertTrue( $this->add_ip( $r )->is_done() );
		$this->assertSame( '192.0.2.11', IpPool::for_resource( $r->id() )['address'], 'The blocked address was skipped.' );
		$this->assertTrue( $this->remove_ip( $r )->is_done() );
		$this->assertSame( 'blocked', IpPool::find( '192.0.2.10' )['state'] );
		$this->assertSame( 'free', IpPool::find( '192.0.2.11' )['state'] );
	}

	public function test_a_paid_database_takes_the_last_address_even_though_the_shop_keeps_it_for_servers(): void {
		$this->pool( 1 );
		$this->assertSame( 0, IpPool::available_for_addon() );
		$r = $this->ready_db( array( 'public_ip' => 1 ) );
		$this->assertSame( '192.0.2.10', DbAccess::public_address( $r ) );
		$this->assertSame( 0, IpPool::free_count() );
	}

	// -- The add-on product ----------------------------------------------------------------------

	/**
	 * The IPv4 address add-on product: one monthly subscription, 19 a month.
	 */
	private function ipv4_product(): \WC_Product_Variation {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'IPv4 address ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Plan' );
		$attribute->set_options( array( 'Monthly' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-ipv4' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'ipv4-' . wp_generate_password( 5, false ) );
		$product->save();
		$variation = new \WC_Product_Subscription_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'plan' => 'Monthly' ) );
		$variation->set_regular_price( '19' );
		$variation->update_meta_data( '_subscription_price', '19' );
		$variation->update_meta_data( '_subscription_period', 'month' );
		$variation->update_meta_data( '_subscription_period_interval', '1' );
		$variation->update_meta_data( '_subscription_length', '0' );
		$variation->set_status( 'publish' );
		$variation->save();
		\WC_Product_Variable::sync( $product->get_id() );
		Stock::sync();

		return wc_get_product( $variation->get_id() );
	}

	public function test_the_access_tab_offers_the_add_on_with_its_price_and_the_restart_note(): void {
		$this->ipv4_product();
		$this->pool( 3 );
		$r = $this->ready_db();
		wp_set_current_user( (int) $r->license()['user_id'] );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );

		$html = $this->card( $r );
		$this->assertStringContainsString( 'Buy an IPv4 address', $html );
		$this->assertStringContainsString( 'restarts once', $html );
		$this->assertStringContainsString( 'beltoft_services_ipv4_for=' . (int) $r->license()['id'], html_entity_decode( $html ) );
		$this->assertStringContainsString( '19', $html );

		// With no address to give, it says so and offers nothing.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reset.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()['ips'] ) );
		delete_transient( IpPool::FREE_TRANSIENT );
		$html = $this->card( $r );
		$this->assertStringContainsString( 'No IPv4 address is free right now', $html );
		$this->assertStringNotContainsString( 'Buy an IPv4 address', $html );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
	}

	/**
	 * An IPv4 add-on resource for a database, as the order of the add-on would make it.
	 */
	private function ipv4_addon( Resource $db ): Resource {
		$product = $this->service_product( 'proxmox-ipv4', array(), array( 'slug' => 'ipv4-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => (int) $db->license()['user_id'], 'email' => self::OWNER ) );
		$addon   = Resource::create( $license, Registry::get( 'proxmox-ipv4' ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $db->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $addon );

		return $addon;
	}

	public function test_the_ipv4_addon_puts_the_address_on_the_database_and_takes_it_off(): void {
		$this->pool( 3 );
		$r      = $this->ready_db();
		$addon  = $this->ipv4_addon( $r );
		$driver = Registry::get( 'proxmox-ipv4' );

		$first = $driver->provision( Resource::find( $addon->id() ) );
		$this->assertTrue( $first->is_poll(), $first->error() );
		$addon = Resource::find( $addon->id() );
		$this->assertSame( 'main', $addon->get( \BeltoftServices\Proxmox\Ipv4\Ipv4Meta::ROLE ) );
		$row = IpPool::for_resource( $r->id() );
		$this->assertSame( 'held', $row['state'], 'The address is held by the database itself, the way a main address is.' );
		$this->assertSame( $row['address'], (string) $addon->get( \BeltoftServices\Proxmox\Ipv4\Ipv4Meta::ADDRESS ) );
		$this->assertSame( '', DbAccess::public_address( Resource::find( $r->id() ) ) );
		$this->assertTrue( DbIp::wants( Resource::find( $r->id() ) ) );

		// The add-on asks for the server's step: the address on the card, one restart, and the guest listens on it.
		$this->assertSame( \BeltoftServices\Proxmox\Ipv4\Ipv4Server::MAIN_ADD, ( new \ReflectionMethod( $driver, 'action_for' ) )->invoke( $driver, $addon, Resource::find( $r->id() ), true ) );
		$done = $this->drive( $r, \BeltoftServices\Proxmox\Ipv4\Ipv4Server::MAIN_ADD );
		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertTrue( Resource::find( $r->id() )->set( $done->meta() ) );
		$this->assertSame( $row['address'], DbAccess::public_address( Resource::find( $r->id() ) ) );
		$this->assertNotEmpty( $this->calls( 'POST', '/status/reboot' ) );
		$this->assertEmpty( $this->calls( 'POST', '/status/shutdown' ) );

		$second = $driver->provision( Resource::find( $addon->id() ) );
		$this->assertTrue( $second->is_done(), $second->error() );

		// Taking it away: the add-on says so, the database's action removes the NIC, then the address is back in the pool.
		$gone = $driver->delete( Resource::find( $addon->id() ) );
		$this->assertTrue( $gone->is_poll(), $gone->error() );
		$this->assertFalse( DbIp::wants( Resource::find( $r->id() ) ), 'The ended add-on no longer counts.' );
		$removed = $this->drive( $r, \BeltoftServices\Proxmox\Ipv4\Ipv4Server::MAIN_REMOVE );
		$this->assertTrue( $removed->is_done(), $removed->error() );
		$this->assertTrue( Resource::find( $r->id() )->set( $removed->meta() ) );
		$this->assertSame( self::DB_V6, Resource::find( $r->id() )->get( 'ip6' ), 'The card keeps its IPv6 address.' );
		$this->assertSame( '', DbAccess::public_address( Resource::find( $r->id() ) ) );
		$this->assertSame( 'free', IpPool::find( $row['address'] )['state'] );
		$this->assertTrue( $driver->delete( Resource::find( $addon->id() ) )->is_done() );
	}

	public function test_a_second_ipv4_addon_on_one_database_is_not_delivered(): void {
		$this->pool( 3 );
		$r      = $this->ready_db();
		$driver = Registry::get( 'proxmox-ipv4' );
		$one    = $this->ipv4_addon( $r );
		$this->assertTrue( $driver->provision( Resource::find( $one->id() ) )->is_poll() );

		$two = $driver->provision( Resource::find( $this->ipv4_addon( $r )->id() ) );

		$this->assertTrue( $two->is_failed() );
		$this->assertStringContainsString( 'cannot take another IPv4 address', $two->error() );
	}

	// -- The allow-list --------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function cidr_table(): array {
		return array(
			'single address'         => array( '203.0.113.7', '203.0.113.7/32' ),
			'with mask'              => array( '203.0.113.0/24', '203.0.113.0/24' ),
			'a /16'                  => array( '198.51.0.0/16', '198.51.0.0/16' ),
			'a /32 written out'      => array( '203.0.113.7/32', '203.0.113.7/32' ),
			'spaces'                 => array( ' 203.0.113.7 ', '203.0.113.7/32' ),
			'a /15'                  => array( '198.50.0.0/15', null ),
			'a /8'                   => array( '198.0.0.0/8', null ),
			'everything'             => array( '0.0.0.0/0', null ),
			'host bits'              => array( '203.0.113.7/24', null ),
			'private 10'             => array( '10.0.0.5', null ),
			'private 172'            => array( '172.16.4.0/24', null ),
			'private 192.168'        => array( '192.168.1.1', null ),
			'loopback'               => array( '127.0.0.1', null ),
			'link local'             => array( '169.254.1.1', null ),
			'shared space'           => array( '100.64.0.1', null ),
			'multicast'              => array( '224.0.0.1', null ),
			'reserved'               => array( '240.0.0.1', null ),
			'this network'           => array( '0.0.0.5', null ),
			'ipv6'                   => array( '2001:DB8::1', '2001:db8::1/128' ),
			'ipv6 range'             => array( '2001:db8:1:ffff::/48', null ),
			'ipv6 range first'       => array( '2001:db8:1::/48', '2001:db8:1::/48' ),
			'ipv6 /32 is too wide'   => array( '2001:db8::/32', null ),
			'ipv6 anywhere'          => array( '::/0', null ),
			'ipv6 loopback'          => array( '::1', null ),
			'ipv6 link local'        => array( 'fe80::1', null ),
			'ipv6 unique local'      => array( 'fd00::1', null ),
			'ipv6 multicast'         => array( 'ff02::1', null ),
			'ipv6 junk'              => array( '2001:db8::zz', null ),
			'ipv6 with zone'         => array( 'fe80::1%eth0', null ),
			'a name'                 => array( 'example.com', null ),
			'bad octet'              => array( '256.1.1.1', null ),
			'bad mask'               => array( '203.0.113.0/33', null ),
			'leading zeros'          => array( '203.000.113.7x', null ),
			'empty'                  => array( '', null ),
			'a rule smuggled in'     => array( "203.0.113.7\nhostssl all all 0.0.0.0/0", null ),
		);
	}

	/**
	 * @dataProvider cidr_table
	 */
	public function test_allow_list_validation_table( string $input, ?string $expected ): void {
		$this->assertSame( $expected, DbAccess::normalise( $input ) );
	}

	public function test_an_address_is_added_through_the_route_and_reaches_the_guest_before_the_firewall(): void {
		$r = $this->db_with_ip();
		$calls_before = count( $this->fake->calls() );

		$reply = $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7', 'label' => '<b>Office</b>' ) );
		$this->assertSame( 202, $reply->get_status() );
		$this->assertSame( array( '203.0.113.7/32' ), array_column( DbAccess::entries( Resource::find( $r->id() ) ), 'cidr' ) );
		$this->assertSame( 'Office', DbAccess::entries( Resource::find( $r->id() ) )[0]['label'], 'The label is plain text.' );
		$this->assertSame( 'applying', Resource::find( $r->id() )->get( DbAccess::STATE ) );

		$res = $this->apply( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertSame( 'applied', Resource::find( $r->id() )->get( DbAccess::STATE ) );
		$this->assertSame( array( 'bs-db:203.0.113.7' ), $this->net0_rules() );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.8', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 22, '203.0.113.7', 'net0' ), 'Only the database port.' );

		$job = json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );
		$this->assertSame( array( 'v' => 1, 'request' => $job['request'], 'op' => 'allow', 'allow' => array( '203.0.113.7/32' ), 'world' => false ), $job );

		// Adding: the guest first, the firewall last.
		$write = null;
		$rule  = null;

		foreach ( array_slice( $this->fake->calls(), $calls_before ) as $i => $c ) {
			if ( 'POST' === $c['method'] && '/agent/file-write' === substr( $c['path'], -17 ) && false !== strpos( (string) ( $c['params']['content'] ?? '' ), '"allow"' ) ) {
				$write = $i;
			}

			if ( 'POST' === $c['method'] && '/firewall/rules' === substr( $c['path'], -15 ) && 'bs-db:203.0.113.7' === ( $c['params']['comment'] ?? '' ) ) {
				$rule = $i;
			}
		}

		$this->assertNotNull( $write );
		$this->assertNotNull( $rule );
		$this->assertLessThan( $rule, $write );
		$this->assertCount( 1, $this->mails, 'The owner is told.' );
		$this->assertSame( array( self::OWNER ), (array) $this->mails[0]['to'] );
	}

	public function test_removing_an_address_closes_the_firewall_before_the_guest(): void {
		$r = $this->db_with_ip();
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7' ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );
		$before = count( $this->fake->calls() );

		$this->assertSame( 202, $this->post( $r, array( 'op' => 'remove', 'cidr' => '203.0.113.7' ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );

		$this->assertSame( array(), $this->net0_rules() );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ) );

		$delete = null;
		$write  = null;

		foreach ( array_slice( $this->fake->calls(), $before ) as $i => $c ) {
			if ( 'DELETE' === $c['method'] && false !== strpos( $c['path'], '/firewall/rules/' ) ) {
				$delete = $i;
			}

			if ( 'POST' === $c['method'] && '/agent/file-write' === substr( $c['path'], -17 ) && false !== strpos( (string) ( $c['params']['content'] ?? '' ), '"allow"' ) ) {
				$write = $i;
			}
		}

		$this->assertNotNull( $delete );
		$this->assertNotNull( $write );
		$this->assertLessThan( $write, $delete );
	}

	public function test_a_crash_between_the_layers_leaves_the_stricter_one(): void {
		$r = $this->db_with_ip();
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7' ) )->get_status() );

		// The first run sends the guest job and waits for it: the firewall is not open yet.
		$res = Registry::get( 'proxmox-db' )->act( Resource::find( $r->id() ), DbAccess::APPLY, array() );
		$this->assertTrue( $res->is_poll() );
		$this->assertSame( array(), $this->net0_rules() );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ) );
	}

	public function test_the_list_is_applied_again_after_a_restore_wiped_the_firewall(): void {
		$r = $this->db_with_ip();
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7' ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );
		$this->assertSame( array( 'bs-db:203.0.113.7' ), $this->net0_rules() );

		// A restore puts a VM back without its firewall file.
		$all = json_decode( (string) wp_json_encode( $this->fake->state() ), true );
		$this->assertNotEmpty( $all );
		$rules = $this->fake->vm( 5000 )['firewall_rules'];
		$this->assertNotEmpty( $rules );

		$fresh  = Resource::find( $r->id() );
		$record = VmRecords::for_resource( $r->id() );
		$cluster = Clusters::get( 'lab' );
		$client  = $this->fake;
		$v       = \BeltoftServices\Proxmox\Pve\Ownership::verify( $client, $record, $fresh );
		$this->assertIsArray( $v );

		foreach ( array_reverse( array_keys( $rules ) ) as $pos ) {
			$client->request( 'DELETE', '/nodes/node-a/qemu/5000/firewall/rules/' . $pos );
		}

		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '10.64.0.7', 'net1' ), 'Wiped, so the private rule is gone too.' );

		$done = ( new Firewall( $fresh, $client, $cluster ) )->ensure_database( $record, $v );
		$this->assertIsArray( $done );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '10.64.0.7', 'net1' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ) );
		$this->assertSame( array( 'bs-db:203.0.113.7' ), $this->net0_rules() );
	}

	public function test_refused_changes_store_nothing(): void {
		$r   = $this->db_with_ip();
		$rev = (int) $r->get( DbAccess::REV );

		foreach ( array( '10.0.0.4', '0.0.0.0/0', '198.50.0.0/15', 'fe80::1', '2001:db8::/32', '203.0.113.9/24' ) as $bad ) {
			$reply = $this->post( $r, array( 'op' => 'add', 'cidr' => $bad ) );
			$this->assertSame( 422, $reply->get_status(), $bad );
		}

		$this->assertSame( array(), DbAccess::entries( Resource::find( $r->id() ) ) );
		$this->assertSame( $rev, (int) Resource::find( $r->id() )->get( DbAccess::REV ), 'No revision was made.' );
		$this->assertSame( 422, $this->post( $r, array( 'op' => 'nonsense' ) )->get_status() );

		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7' ) )->get_status() );
		$this->assertSame( 422, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7/32' ) )->get_status(), 'A duplicate.' );
		$this->assertSame( 404, $this->post( $r, array( 'op' => 'remove', 'cidr' => '203.0.113.99' ) )->get_status() );
	}

	public function test_the_twenty_first_address_is_refused(): void {
		add_filter( 'beltoft_services_rest_rate_limit', fn() => 0 );
		$r = $this->db_with_ip();

		for ( $i = 1; $i <= 20; $i++ ) {
			$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.' . $i ) )->get_status(), 'Entry ' . $i );
		}

		$reply = $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.99' ) );
		$this->assertSame( 422, $reply->get_status() );
		$this->assertCount( 20, DbAccess::entries( Resource::find( $r->id() ) ) );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
	}

	public function test_the_page_shows_the_translated_open_to_everyone_phrase_and_the_constant_stays_english(): void {
		$filter = static fn( $translated, $text, $domain ) => 'beltoft-services' === $domain && 'OPEN TO EVERYONE' === $text ? 'ÅBEN FOR ALLE' : $translated;
		add_filter( 'gettext', $filter, 10, 3 );
		$r    = $this->db_with_ip();
		$view = DbAccess::view( Resource::find( $r->id() ) );
		remove_filter( 'gettext', $filter, 10 );

		$this->assertSame( 'ÅBEN FOR ALLE', $view['confirm'] );
		$this->assertSame( 'OPEN TO EVERYONE', DbAccess::CONFIRM_WORLD );
		$this->assertSame( 'OPEN TO EVERYONE', DbAccess::view( Resource::find( $r->id() ) )['confirm'] );
	}

	public function test_open_to_everyone_accepts_the_translated_phrase_or_the_english_one_and_refuses_anything_else(): void {
		$r = $this->db_with_ip();

		// English page: the translated phrase is not accepted.
		$this->assertSame( 422, $this->post( $r, array( 'op' => 'world_on', 'confirm' => 'ÅBEN FOR ALLE' ) )->get_status() );

		$filter = static fn( $translated, $text, $domain ) => 'beltoft-services' === $domain && 'OPEN TO EVERYONE' === $text ? 'ÅBEN FOR ALLE' : $translated;
		add_filter( 'gettext', $filter, 10, 3 );

		$wrong = $this->post( $r, array( 'op' => 'world_on', 'confirm' => 'åben for alle' ) );
		$this->assertSame( 422, $wrong->get_status() );
		$this->assertStringContainsString( 'ÅBEN FOR ALLE', $wrong->get_data()['message'] ?? wp_json_encode( $wrong->get_data() ) );
		$this->assertFalse( DbAccess::world( Resource::find( $r->id() ) ) );
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'world_on', 'confirm' => 'ÅBEN FOR ALLE' ) )->get_status() );
		$this->assertTrue( DbAccess::world( Resource::find( $r->id() ) ) );
		remove_filter( 'gettext', $filter, 10 );
	}

	public function test_the_page_script_sends_the_open_to_everyone_phrase_the_route_knows(): void {
		$root = dirname( __DIR__, 3 );
		$js   = (string) file_get_contents( $root . '/assets/db.js' );
		$php  = (string) file_get_contents( $root . '/src/Proxmox/Db/DbCard.php' );
		$mcp  = (string) file_get_contents( $root . '/src/Proxmox/Db/McpTools.php' );

		$this->assertStringContainsString( "'world'   => array( DbAccess::phrase_world(), DbAccess::CONFIRM_WORLD )", $php );
		$this->assertStringContainsString( 'typed === db.world[0]', $js );
		$this->assertStringContainsString( 'typed = db.world[1]', $js );
		$this->assertStringContainsString( 'confirm: typed }, confirm )', $js );
		$this->assertStringContainsString( '$body[\'confirm\'] = DbAccess::CONFIRM_WORLD;', $mcp );
		$this->assertStringNotContainsString( 'phrase_world', $mcp );
	}

	public function test_open_to_everyone_needs_the_typed_words_one_rule_and_an_email(): void {
		$r = $this->db_with_ip();

		$this->assertSame( 422, $this->post( $r, array( 'op' => 'world_on' ) )->get_status() );
		$this->assertSame( 422, $this->post( $r, array( 'op' => 'world_on', 'confirm' => 'open to everyone' ) )->get_status(), 'The exact words.' );
		$this->assertFalse( DbAccess::world( Resource::find( $r->id() ) ) );
		$this->assertSame( array(), $this->mails );

		$this->assertSame( 202, $this->post( $r, array( 'op' => 'world_on', 'confirm' => DbAccess::CONFIRM_WORLD ) )->get_status() );
		$this->assertTrue( DbAccess::world( Resource::find( $r->id() ) ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'open to everyone', strtolower( $this->mails[0]['subject'] ) );
		$this->assertStringNotContainsString( 'Abcdefghijklmnopqrstuvwxyz012345', (string) $this->mails[0]['message'], 'No secret in any email.' );

		$this->assertTrue( $this->apply( $r )->is_done() );
		$this->assertSame( array( Firewall::DB_WORLD ), $this->net0_rules() );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '198.51.100.77', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 22, '198.51.100.77', 'net0' ) );
		$job = json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );
		$this->assertTrue( $job['world'] );

		$this->assertSame( 202, $this->post( $r, array( 'op' => 'world_off' ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );
		$this->assertSame( array(), $this->net0_rules() );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '198.51.100.77', 'net0' ) );
	}

	public function test_the_route_checks_the_owner_the_address_and_the_rate(): void {
		$r = $this->db_with_ip();

		$other = self::factory()->user->create();
		$this->assertSame( 403, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7' ), $other )->get_status() );
		$this->assertSame( 401, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7' ), 0 )->get_status(), 'Logged out.' );
		$this->assertSame( array(), DbAccess::entries( Resource::find( $r->id() ) ) );

		$plain = $this->bare_db();
		$this->assertSame( 403, $this->post( $plain, array( 'op' => 'add', 'cidr' => '203.0.113.7' ) )->get_status(), 'A database without a public address has no list to change.' );

		$status = 0;

		for ( $i = 0; $i < 12; $i++ ) {
			$status = $this->post( $r, array( 'op' => 'remove', 'cidr' => '203.0.113.1' ) )->get_status();
		}

		$this->assertSame( 429, $status );
	}

	public function test_the_access_job_is_not_a_customer_action(): void {
		$r      = $this->db_with_ip();
		$driver = Registry::get( 'proxmox-db' );

		$this->assertArrayNotHasKey( DbAccess::APPLY, $driver->customer_actions( $r ) );
		$this->assertContains( DbAccess::APPLY, $driver->internal_actions() );
	}

	// -- The page --------------------------------------------------------------------------------

	private function card( Resource $r, string $tab = 'access' ): string {
		$view = DbCard::view( Resource::find( $r->id() ) );
		ob_start();
		( static function ( array $view, array $nav, string $active, callable $link ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/db.php';
		} )( $view, array( 'overview' => 'Overview', 'access' => 'Access' ), $tab, static fn( $slug ) => '/x/' . $slug );

		return (string) ob_get_clean();
	}

	/** The Managed add-on product (the one of the servers): a simple monthly subscription of 299. */
	private function managed_product(): \WC_Product {
		$product = new \WC_Product_Subscription();
		$product->set_name( 'Managed' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '299' );
		$product->update_meta_data( '_subscription_price', '299' );
		$product->update_meta_data( '_subscription_period', 'month' );
		$product->update_meta_data( '_subscription_period_interval', '1' );
		$product->update_meta_data( Product::META_DRIVER, ManagedDriver::ID );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'managed-' . wp_generate_password( 5, false ) );
		$product->save();
		AddonCart::reset_products();

		return wc_get_product( $product->get_id() );
	}

	/** The Managed add-on of a database, as its order would make it. */
	private function managed_addon( Resource $db ): Resource {
		$product = $this->service_product( ManagedDriver::ID, array(), array( 'slug' => 'managed-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => (int) $db->license()['user_id'], 'email' => self::OWNER ) );
		$addon   = Resource::create( $license, Registry::get( ManagedDriver::ID ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $db->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $addon );
		Jobs::run( $addon->id() );

		return Resource::find( $addon->id() );
	}

	public function test_the_overview_offers_the_managed_add_on_with_its_price_and_the_backups_tab_says_why_there_are_none(): void {
		$this->managed_product();
		$r = $this->ready_db();
		wp_set_current_user( (int) $r->license()['user_id'] );

		$html = $this->card( $r, 'overview' );
		$this->assertStringContainsString( 'Buy Managed', $html );
		$this->assertStringContainsString( 'beltoft_services_managed_for=' . (int) $r->license()['id'], html_entity_decode( $html ) );
		$this->assertStringContainsString( '299', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		$view = DbCard::view( Resource::find( $r->id() ) );
		$this->assertFalse( $view['backups']['enabled'], 'A plain database has no backups.' );
		ob_start();
		( static function ( array $view, array $nav, string $active, callable $link ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/db.php';
		} )( $view, array( 'overview' => 'Overview', 'backups' => 'Backups' ), 'backups', static fn( $slug ) => '/x/' . $slug );
		$tab = (string) ob_get_clean();
		$this->assertStringContainsString( 'They come with Managed', $tab );
		$this->assertStringContainsString( 'Buy Managed', $tab );
	}

	public function test_the_managed_line_says_what_it_covers_in_cart_order_and_name(): void {
		$product = $this->managed_product();
		$r       = $this->ready_db();
		$on      = \BeltoftServices\Proxmox\Vm\ServerName::email_label( $r, $r->license() );
		$this->assertNotSame( '', $on );

		$this->assertSame( 'Managed: ' . $on, \BeltoftServices\LineNames::label( $product, (int) $r->license()['id'] ) );
		$this->assertSame( '', \BeltoftServices\LineNames::label( $product, 0 ), 'Not known yet: the product name stays.' );
		$this->assertSame( '', \BeltoftServices\LineNames::label( $product, 'cart_server' ) );
		$this->assertSame( '', \BeltoftServices\LineNames::label( $product, 999999 ), 'A licence that is no server or database.' );

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->add_meta_data( '_beltoft_services_managed_server', (int) $r->license()['id'] );
		$this->assertSame( 'Managed: ' . $on, \BeltoftServices\LineNames::display_name( $item ) );
		$this->assertStringContainsString( 'Managed: ' . $on, \BeltoftServices\LineNames::cart_item_name( 'x', array( 'data' => $product, 'beltoft_services_managed_for' => (int) $r->license()['id'] ) ) );
	}

	public function test_no_block_when_the_add_on_is_not_sold_and_the_active_one_is_told(): void {
		$r = $this->ready_db();
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->assertStringNotContainsString( 'Buy Managed', $this->card( $r, 'overview' ) );

		$this->managed_addon( $r );
		$html = $this->card( $r, 'overview' );
		$this->assertStringContainsString( 'This database is managed', $html );
		$this->assertStringNotContainsString( 'Buy Managed', $html );
		$this->assertStringContainsString( 'Daily, kept 30 days', $html, 'The backups row appears with the add-on.' );
	}

	public function test_the_add_on_takes_a_database_of_its_own_ready_customer_only_once_and_not_an_adopted_one(): void {
		$this->managed_product();
		$r = $this->ready_db();
		wp_set_current_user( (int) $r->license()['user_id'] );

		$this->assertNotSame( '', ManagedCart::add_url( Resource::find( $r->id() ) ) );

		$r->set( array( 'linked' => true ) );
		$this->assertNull( \BeltoftServices\Proxmox\Vm\Panels::managed_panel( Resource::find( $r->id() ) ) );
		$r->set( array( 'linked' => null ) );

		$this->managed_addon( $r );
		$this->assertSame( '', ManagedCart::add_url( Resource::find( $r->id() ) ), 'One per database.' );
		$this->assertTrue( \BeltoftServices\Proxmox\Vm\Panels::managed_panel( Resource::find( $r->id() ) )['active'] );
	}

	public function test_a_managed_line_of_a_database_of_another_customer_is_not_delivered(): void {
		$r      = $this->ready_db();
		$other  = self::factory()->user->create();
		$prod   = $this->service_product( ManagedDriver::ID, array(), array( 'slug' => 'managed-' . wp_generate_password( 5, false ) ) );
		$lic    = $this->service_license( $prod, array( 'fire' => false, 'user_id' => $other ) );
		$m      = Resource::create( $lic, Registry::get( ManagedDriver::ID ), Product::plan( $prod->get_id() ), array( 'server_license' => (int) $r->license()['id'] ) );
		Jobs::run( $m->id() );

		$this->assertNotSame( 'ready', Resource::find( $m->id() )->get( 'state' ) );
		$this->assertFalse( ManagedMeta::is_managed( Resource::find( $r->id() ) ) );
	}

	public function test_a_deleted_database_ends_its_managed_line_and_the_line_is_no_row_of_the_servers_list(): void {
		$r       = $this->ready_db();
		$managed = $this->managed_addon( $r );
		$this->assertSame( 'ready', $managed->get( 'state' ), (string) $managed->get( 'last_error' ) );

		$view = array( 'id' => (int) $managed->license()['id'], 'license' => $managed->license() );
		$this->assertSame( array(), \BeltoftServices\Proxmox\Vm\Servers::sort( array( $view ) )['others'], 'Shown on the database page, not as a stray row.' );

		$this->assertTrue( Resource::find( $r->id() )->transition( 'deleted', 'test' ) );
		\BeltoftServices\Reconciler::notify( Resource::find( $r->id() ), 'ready', 'deleted' );

		$this->assertFalse( Resource::find( $managed->id() )->is_live(), 'The managed line is ended and closed with its database.' );
		$this->assertSame( 'revoked', ( \BeltoftLicenses\Licenses::find( (int) $managed->license()['id'] )['status'] ?? '' ) );
	}

	public function test_every_tab_of_a_database_that_is_not_ready_says_so(): void {
		$r = $this->bare_db();

		foreach ( array( 'access', 'overview' ) as $tab ) {
			$html = $this->card( $r, $tab );

			$this->assertSame( 1, substr_count( $html, 'Your database is being set up.' ), $tab );
		}
	}

	public function test_the_access_tab_without_an_address_offers_the_add_on_or_says_sold_out(): void {
		$this->ipv4_product();
		$r = $this->ready_db();
		wp_set_current_user( (int) $r->license()['user_id'] );
		$html = $this->card( $r );

		$this->assertStringContainsString( 'No IPv4 address is free right now', $html, 'The pool is empty.' );
		$this->assertStringContainsString( self::DB_V6, $html, 'The IPv6 address of the card is shown.' );
		$this->assertStringContainsString( 'data-db-access-add', $html, 'The allow-list works over IPv6.' );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_the_access_tab_with_an_address_lists_entries_escapes_labels_and_never_shows_a_password(): void {
		$r = $this->db_with_ip();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7', 'label' => '"><script>alert(1)</script>' ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );

		$html = $this->card( $r );
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertStringContainsString( '192.0.2.10', $html );
		$this->assertStringContainsString( '203.0.113.7', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( 'data-db-access-mine="203.0.113.50"', $html, 'Use my current IP.' );
		$this->assertStringContainsString( 'data-db-access-remove="203.0.113.7"', $html );
		$this->assertMatchesRegularExpression( '#class="[^"]*bs-btn--danger[^"]*" data-db-access-remove="203.0.113.7"#', $html, 'Remove is a compact danger button like Delete in the backups.' );
		$this->assertStringContainsString( 'beltoft-services-db__access-list', $html );
		$this->assertMatchesRegularExpression( '#bs-btn--danger" data-db-access-world="world_on"#', $html );
		$this->assertStringContainsString( 'Open to everyone', $html );
		$this->assertStringContainsString( DbAccess::CONFIRM_WORLD, $html );
		$this->assertStringNotContainsString( 'Abcdefghijklmnopqrstuvwxyz012345', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		$overview = $this->card( $r, 'overview' );
		$this->assertStringContainsString( 'postgresql://app_user:PASSWORD@192.0.2.10:5432/app?sslmode=verify-full', $overview );
		$this->assertStringContainsString( 'postgresql://app_user:PASSWORD@192.0.2.10:5432/app?sslmode=require', $overview );
		$this->assertStringNotContainsString( 'Abcdefghijklmnopqrstuvwxyz012345', $overview );
	}

	public function test_the_world_open_warning_stays_on_the_page_while_it_is_on(): void {
		$r = $this->db_with_ip();
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'world_on', 'confirm' => DbAccess::CONFIRM_WORLD ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );

		$html = $this->card( $r );
		$this->assertStringContainsString( 'The database is open to everyone', $html );
		$this->assertMatchesRegularExpression( '#bs-btn--secondary" data-db-access-world="world_off"#', $html );
	}

	public function test_a_database_without_an_address_shows_only_the_private_connection(): void {
		$r = $this->ready_db();
		$view = DbCard::view( $r );
		$this->assertSame( array(), $view['public_connections'] );
		$this->assertSame( '', $view['access']['address'] );
		$this->assertStringNotContainsString( '@192.0.2', $this->card( $r, 'overview' ) );
	}

	// -- The checkout notice ---------------------------------------------------------------------

	public function test_the_cart_notice_drops_coming_soon_and_a_public_ip_database_gets_none(): void {
		// Nothing with a public IP for sale: the add-on is not promised.
		$this->assertStringContainsString( 'coming soon', DbNeedsServer::message( false ) );
		$this->assertStringNotContainsString( 'add-on', DbNeedsServer::message( false ) );

		$this->ipv4_product();
		$this->assertStringNotContainsString( 'coming soon', DbNeedsServer::message( true ) );
		$this->assertStringNotContainsString( 'coming soon', DbNeedsServer::message( false ) );
		$this->assertStringContainsString( 'add-on', DbNeedsServer::message( false ) );
		$this->assertStringNotContainsString( "\u{2014}", DbNeedsServer::message( true ) );

		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		WC()->cart->empty_cart();
		wc_clear_notices();

		$without = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'slug' => 'dbno-' . wp_generate_password( 5, false ) ) );

		WC()->cart->add_to_cart( $without->get_id() );
		DbNeedsServer::cart_notice();
		$this->assertNotEmpty( wc_get_notices( 'notice' ) );

		WC()->cart->empty_cart();
		wc_clear_notices();
		wp_set_current_user( 0 );
	}

	// -- IPv6 -----------------------------------------------------------------------------------------

	private const DB_V6 = '2001:db8:430c:1:1388::1';

	/** A cluster with a prefix and the firewall's ipfilter on. */
	private function ipv6_cluster(): void {
		$this->save_cluster( array( 'ipv6_prefix' => '2001:db8:430c:1::/64', 'fw_enabled' => '1', 'fw_ipfilter' => '1' ) );
	}

	public function test_with_database_ipv6_on_every_database_is_built_with_its_public_card_and_ipv6(): void {
		$this->ipv6_cluster();
		$r = $this->ready_db();

		$config = (array) $this->fake->vm( 5000 )['config'];
		$this->assertSame( 'ip6=' . self::DB_V6 . '/64,gw6=2001:db8:430c:1::1', $config['ipconfig0'], 'The IPv6 address only, no IPv4.' );
		$this->assertStringContainsString( 'bridge=vmbr9', (string) $config['net0'], 'The public card, like a VPS Basic.' );
		$this->assertStringContainsString( 'firewall=1', (string) $config['net0'] );
		$this->assertArrayHasKey( 'net1', $config, 'The private network stays.' );
		$this->assertSame( self::DB_V6, Resource::find( $r->id() )->get( 'ip6' ) );
		$this->assertTrue( DbIp::has_card( Resource::find( $r->id() ) ) );
		$this->assertSame( '', DbAccess::public_address( Resource::find( $r->id() ) ) );
		$this->assertTrue( DbAccess::reachable( Resource::find( $r->id() ) ), 'Reachable over IPv6: the allow-list can be edited.' );
		$this->assertSame( array(), $this->net0_rules(), 'Closed until the customer allows an address.' );

		// With ipfilter on, the card may send only from the addresses in its set: the IPv6 one must be there from the build on.
		$set = array_map( array( \BeltoftServices\Proxmox\Network\Firewall::class, 'ipset_key' ), array_column( (array) ( $this->fake->vm( 5000 )['firewall_ipsets']['ipfilter-net0'] ?? array() ), 'cidr' ) );
		$this->assertSame( array( self::DB_V6 ), $set, 'The public card may send from its IPv6 address.' );
	}

	public function test_a_cluster_without_ipv6_builds_a_database_without_the_public_card(): void {
		$this->save_cluster( array( 'ipv6_prefix' => '' ) );
		$r = $this->ready_db();

		$this->assertArrayNotHasKey( 'net0', array_filter( (array) $this->fake->vm( 5000 )['config'], static fn( $v, $k ) => 'net0' === $k && false !== strpos( (string) $v, 'vmbr9' ), ARRAY_FILTER_USE_BOTH ) );
		$this->assertArrayNotHasKey( 'ipconfig0', (array) $this->fake->vm( 5000 )['config'] );
		$this->assertFalse( DbIp::has_card( Resource::find( $r->id() ) ) );
	}

	public function test_the_add_on_puts_ipv4_on_the_card_with_one_restart_and_removing_it_keeps_ipv6(): void {
		$this->ipv6_cluster();
		$r = $this->ready_db();
		$this->pool( 3 );
		$reboots = count( $this->calls( 'POST', '/status/reboot' ) );

		$this->assertTrue( $this->add_ip( $r )->is_done() );
		$this->assertSame( 'ip=192.0.2.10/24,gw=192.0.2.1,ip6=' . self::DB_V6 . '/64,gw6=2001:db8:430c:1::1', $this->fake->vm( 5000 )['config']['ipconfig0'] );
		$this->assertSame( '192.0.2.10', DbAccess::public_address( Resource::find( $r->id() ) ) );
		$this->assertCount( $reboots + 1, $this->calls( 'POST', '/status/reboot' ), 'One restart, as for a server.' );
		$this->assertCount( 0, $this->calls( 'POST', '/status/shutdown' ), 'No stop and start to add a card.' );

		$listen = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'listen' === $j['job']['op'] ) );
		$last   = end( $listen );
		$this->assertContains( '192.0.2.10', $last['job']['listen'] );
		$this->assertSame( array( self::DB_V6 ), $last['job']['listen6'] );

		$this->assertTrue( $this->remove_ip( $r )->is_done() );
		$this->assertSame( 'ip6=' . self::DB_V6 . '/64,gw6=2001:db8:430c:1::1', $this->fake->vm( 5000 )['config']['ipconfig0'], 'Back to the card with IPv6 only.' );
		$this->assertSame( self::DB_V6, Resource::find( $r->id() )->get( 'ip6' ) );
		$this->assertNull( IpPool::for_resource( $r->id() ), 'The IPv4 address is back in the pool.' );
	}

	public function test_a_repair_leaves_ipv6_out_when_the_database_has_none(): void {
		$this->ipv6_cluster();
		$r = $this->db_with_ip();
		$r->set( array( 'ip6' => null ) );
		$r       = Resource::find( $r->id() );
		$cluster = (array) Clusters::get( 'lab' );
		$config  = $this->fake->vm( 5000 )['config'];

		$config['ipconfig0'] = 'ip=192.0.2.10/24,gw=192.0.2.1';
		$this->assertNull( DbIp::nic_repair( $r, $cluster, $config ), 'No IPv6 was given, none is wanted.' );
	}

	public function test_a_restore_puts_the_ipv6_part_of_the_public_card_back_and_leaves_a_written_address_when_the_cluster_turns_it_off(): void {
		$this->ipv6_cluster();
		$r      = $this->db_with_ip();
		$config = $this->fake->vm( 5000 )['config'];
		$cluster = (array) Clusters::get( 'lab' );

		$this->assertNull( DbIp::nic_repair( $r, $cluster, $config ), 'Right as it is.' );

		$old              = $config;
		$old['ipconfig0'] = 'ip=192.0.2.10/24,gw=192.0.2.1';
		$repair           = DbIp::nic_repair( $r, $cluster, $old );
		$this->assertSame( 'ip=192.0.2.10/24,gw=192.0.2.1,ip6=' . self::DB_V6 . '/64,gw6=2001:db8:430c:1::1', $repair['ipconfig0'] );

		$spelled              = $config;
		$spelled['ipconfig0'] = 'ip=192.0.2.10/24,gw=192.0.2.1,ip6=2001:DB8:430c:1:1388:0:0:1/64,gw6=2001:db8:430c:1:0:0:0:1';
		$this->assertNull( DbIp::nic_repair( $r, $cluster, $spelled ), 'The same address spelled another way.' );

		$wrong              = $config;
		$wrong['ipconfig0'] = 'ip=192.0.2.10/24,gw=192.0.2.1,ip6=2001:db8:430c:1:9999::1/64,gw6=2001:db8:430c:1::1';
		$this->assertNotNull( DbIp::nic_repair( $r, $cluster, $wrong ), 'A wrong IPv6 address is replaced.' );

		$off = $cluster;
		$off['ipv6_prefix'] = '';
		$this->assertNull( DbIp::nic_repair( $r, $off, $config ), 'IPv6 off: a written address is left alone.' );

		$bad4              = $config;
		$bad4['ipconfig0'] = 'ip=192.0.2.99/24,gw=192.0.2.1,ip6=' . self::DB_V6 . '/64,gw6=2001:db8:430c:1::1';
		$this->assertNotNull( DbIp::nic_repair( $r, $cluster, $bad4 ), 'The IPv4 part must be the pool\'s.' );
	}

	public function test_an_ipv6_entry_is_applied_to_the_firewall_and_goes_to_the_guest_in_its_own_list(): void {
		$this->ipv6_cluster();
		$r = $this->db_with_ip();

		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '2001:DB8:1::/48', 'label' => 'Office v6' ) )->get_status() );
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '203.0.113.7' ) )->get_status() );
		$this->assertSame( 202, $this->post( $r, array( 'op' => 'add', 'cidr' => '2a01:4f8::7' ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );

		$entries = DbAccess::entries( Resource::find( $r->id() ) );
		$this->assertSame( array( '2001:db8:1::/48', '203.0.113.7/32', '2a01:4f8::7/128' ), array_column( $entries, 'cidr' ) );
		$view = DbAccess::view( Resource::find( $r->id() ) );
		$this->assertSame( array( '2001:db8:1::/48', '203.0.113.7', '2a01:4f8::7' ), array_column( $view['entries'], 'cidr' ), 'The customer reads a single host without its length.' );
		$this->assertSame( self::DB_V6, $view['address6'] );

		$this->assertEqualsCanonicalizing( array( 'bs-db:2001:db8:1::/48', 'bs-db:203.0.113.7', 'bs-db:2a01:4f8::7' ), $this->net0_rules() );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '2001:db8:1:77::5', 'net0' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '2a01:4f8::7', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '2a01:4f8::8', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '2001:db8:2::5', 'net0' ) );

		$job = json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );
		$this->assertSame( array( '203.0.113.7/32' ), $job['allow'] );
		$this->assertSame( array( '2001:db8:1::/48', '2a01:4f8::7/128' ), $job['allow6'] );

		// A second apply writes no rule again, and removing an IPv6 entry closes it.
		$before = count( $this->fake->calls() );
		$this->assertTrue( $this->apply( $r )->is_done() );
		$this->assertSame( 0, count( array_filter( array_slice( $this->fake->calls(), $before ), static fn( $c ) => 'POST' === $c['method'] && '/firewall/rules' === substr( $c['path'], -15 ) ) ) );

		$this->assertSame( 202, $this->post( $r, array( 'op' => 'remove', 'cidr' => '2001:db8:1::/48' ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '2001:db8:1:77::5', 'net0' ) );
	}

	public function test_open_to_everyone_is_one_rule_for_both_families(): void {
		$this->ipv6_cluster();
		$r = $this->db_with_ip();

		$this->assertSame( 202, $this->post( $r, array( 'op' => 'world_on', 'confirm' => DbAccess::CONFIRM_WORLD ) )->get_status() );
		$this->assertTrue( $this->apply( $r )->is_done() );

		$this->assertSame( array( 'bs-db:world' ), $this->net0_rules() );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '2001:db8:2::5', 'net0' ) );
	}

	// -- The customer's IPv6 switch on a database --------------------------------------------------------

	public function test_a_database_has_the_servers_network_tab_on_its_one_network(): void {
		$r    = $this->ready_db();
		$view = \BeltoftServices\Proxmox\Network\PrivateAttach::view( $r );

		$this->assertTrue( $view['is_db'] );
		$this->assertSame( 'attached', $view['state'], 'Built on its network: always attached.' );
		$this->assertSame( (string) Resource::find( $r->id() )->get( 'db_address' ), $view['address'] );
		$this->assertSame( '10.64.0.0/28', $view['cidr'] );
		$this->assertFalse( $view['can_attach'] );
		$this->assertFalse( $view['can_detach'] );

		ob_start();
		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/network.php';
		} )( $view );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'The database is always on one private network', $html );
		$this->assertStringContainsString( (string) $view['address'], $html );
		$this->assertStringNotContainsString( 'data-net-attach', $html );
		$this->assertStringNotContainsString( 'data-net-detach', $html );
		$this->assertStringNotContainsString( 'qemu-guest-agent', $html );
	}

	public function test_a_database_moves_to_another_private_network_with_one_restart(): void {
		$this->assertTrue( \BeltoftServices\Proxmox\Network\PrivateNets::add( 1001, '10.64.0.16/28' ) );
		$this->assertFalse( \BeltoftServices\Proxmox\Db\DbPrivateMove::supported( $this->bare_db() ), 'A database from an older template (no public card) is not offered the move.' );
		$this->ipv6_cluster();
		$r     = $this->ready_db();
		$user  = (int) Resource::find( $r->id() )->license()['user_id'];
		$two   = \BeltoftServices\Proxmox\Network\PrivateNets::create_for_user( $user, 'Two' );
		$this->assertNotNull( $two );
		$old   = (string) Resource::find( $r->id() )->get( 'db_address' );
		$this->assertStringStartsWith( '10.64.0.', $old );

		$this->assertSame( 'The database is on that network already.', \BeltoftServices\Proxmox\Db\DbPrivateMove::check( Resource::find( $r->id() ), array( 'net' => (string) \BeltoftServices\Proxmox\Network\PrivateNets::for_resource( $r->id() )['net_id'] ) ) );
		$this->assertNull( \BeltoftServices\Proxmox\Db\DbPrivateMove::check( Resource::find( $r->id() ), array( 'net' => (string) $two['id'] ) ) );
		$this->assertTrue( \BeltoftServices\Proxmox\Network\PrivateAttach::view( Resource::find( $r->id() ) )['can_move'] );

		$reboots = count( $this->calls( 'POST', '/status/reboot' ) );
		$driver  = Registry::get( 'proxmox-db' );
		$res     = null;

		for ( $i = 0; $i < 40; $i++ ) {
			$this->answer_guest( $r );
			$res = $driver->act( Resource::find( $r->id() ), \BeltoftServices\Proxmox\Db\DbPrivateMove::ACTION, array( 'net' => (string) $two['id'] ) );

			if ( $res->is_done() || $res->is_failed() || $res->is_refusal() ) {
				break;
			}

			$this->fake->elapse( 30 );
		}

		$this->assertTrue( $res->is_done(), $res->error() );
		$fresh   = Resource::find( $r->id() );
		$address = (string) $fresh->get( 'db_address' );
		$this->assertStringStartsWith( '10.64.0.', $address );
		$this->assertTrue( \BeltoftServices\Proxmox\Network\PrivateNets::contains_host( '10.64.0.16/28', $address ), 'The new address is in the new network.' );
		$this->assertSame( '10.64.0.16/28', (string) $fresh->get( 'db_cidr' ) );
		$this->assertSame( (int) $two['id'], (int) \BeltoftServices\Proxmox\Network\PrivateNets::for_resource( $r->id() )['net_id'] );
		$this->assertNull( $fresh->get( \BeltoftServices\Proxmox\Db\DbPrivateMove::OP ) );

		$config = (array) $this->fake->vm( 5000 )['config'];
		$this->assertStringContainsString( 'tag=1001', (string) $config['net1'] );
		$this->assertSame( 'ip=' . $address . '/28', $config['ipconfig1'] );
		$this->assertCount( $reboots + 1, $this->calls( 'POST', '/status/reboot' ), 'One restart.' );

		$jobs = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'private' === $j['job']['op'] ) );
		$this->assertCount( 1, $jobs );
		$this->assertSame( $address, $jobs[0]['job']['address'] );
		$this->assertSame( '10.64.0.16/28', $jobs[0]['job']['private_cidr'] );
	}

	/**
	 * Runs the move until it settles.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function move( Resource $r, array $args ): \BeltoftServices\Drivers\StepResult {
		$driver = Registry::get( 'proxmox-db' );
		$res    = null;

		for ( $i = 0; $i < 60; $i++ ) {
			$this->answer_guest( $r );
			$res = $driver->act( Resource::find( $r->id() ), \BeltoftServices\Proxmox\Db\DbPrivateMove::ACTION, $args );

			if ( $res->is_done() || $res->is_failed() || $res->is_refusal() || $res->wait_until() > 0 ) {
				return $res;
			}

			$this->fake->elapse( 30 );
		}

		$this->fail( 'The move did not settle: ' . $res->error() );
	}

	/** A ready database with its card and a second network of the same customer. */
	private function db_and_second_net(): array {
		$this->assertTrue( \BeltoftServices\Proxmox\Network\PrivateNets::add( 1001, '10.64.0.16/28' ) );
		$r    = $this->ready_db();
		$user = (int) Resource::find( $r->id() )->license()['user_id'];
		$two  = \BeltoftServices\Proxmox\Network\PrivateNets::create_for_user( $user, 'Two' );
		$this->assertNotNull( $two );

		return array( $r, $two, $user );
	}

	public function test_only_a_database_marked_at_build_is_offered_the_move(): void {
		$r = $this->ready_db();
		$this->assertTrue( Resource::find( $r->id() )->get( DbIp::CARD ), 'The build marks a database built with its card.' );
		$this->assertTrue( \BeltoftServices\Proxmox\Db\DbPrivateMove::supported( Resource::find( $r->id() ) ) );

		// IPv6 alone (an older database that got it later) does not say the guest knows the private job.
		$this->assertTrue( Resource::find( $r->id() )->set( array( DbIp::CARD => null ) ) );
		$this->assertFalse( \BeltoftServices\Proxmox\Db\DbPrivateMove::supported( Resource::find( $r->id() ) ) );
	}

	public function test_a_move_whose_pool_answer_was_lost_takes_the_address_the_pool_gave(): void {
		list( $r, $two, $user ) = $this->db_and_second_net();
		$row = \BeltoftServices\Proxmox\Network\PrivateNets::for_resource( $r->id() );

		// The job stored where the database came from, the pool moved, then the job stopped before it stored the answer.
		$this->assertTrue( Resource::find( $r->id() )->set( array( \BeltoftServices\Proxmox\Db\DbPrivateMove::OP => array( 'phase' => 'pool', 'net' => (string) $two['id'], 'name' => '', 'old_net_id' => (int) $row['net_id'], 'old_address' => (string) $row['address'] ) ) ) );
		$moved = \BeltoftServices\Proxmox\Network\PrivateNets::move( $user, $r->id(), (int) $two['id'] );
		$this->assertNotNull( $moved );

		$res = $this->move( $r, array() );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertSame( $moved['address'], (string) Resource::find( $r->id() )->get( 'db_address' ), 'The address the pool gave, not a refusal.' );
		$this->assertSame( (int) $two['id'], (int) \BeltoftServices\Proxmox\Network\PrivateNets::for_resource( $r->id() )['net_id'] );
		$this->assertSame( 'ip=' . $moved['address'] . '/28', $this->fake->vm( 5000 )['config']['ipconfig1'] );
	}

	public function test_a_paused_move_leaves_the_pool_alone(): void {
		list( $r, $two ) = $this->db_and_second_net();
		$before = \BeltoftServices\Proxmox\Network\PrivateNets::for_resource( $r->id() );
		$this->settings( array( 'pause_all' => 1 ) );

		$res = $this->move( $r, array( 'net' => (string) $two['id'] ) );
		$this->assertGreaterThan( 0, $res->wait_until(), $res->error() );
		$this->assertSame( $before, \BeltoftServices\Proxmox\Network\PrivateNets::for_resource( $r->id() ), 'The old address stays the database\'s while it is paused.' );
	}

	public function test_the_new_private_address_is_in_the_ipfilter_set_before_the_restart(): void {
		list( $r, $two ) = $this->db_and_second_net();
		$from = count( $this->fake->calls() );

		$this->assertTrue( $this->move( $r, array( 'net' => (string) $two['id'] ) )->is_done() );
		$address = (string) Resource::find( $r->id() )->get( 'db_address' );
		$set     = null;
		$reboot  = null;

		foreach ( array_slice( $this->fake->calls(), $from ) as $i => $c ) {
			if ( null === $set && 'POST' === $c['method'] && false !== strpos( $c['path'], '/firewall/ipset/ipfilter-net1' ) && $address === (string) ( $c['params']['cidr'] ?? '' ) ) {
				$set = $i;
			}

			if ( null === $reboot && 'POST' === $c['method'] && false !== strpos( $c['path'], '/status/reboot' ) ) {
				$reboot = $i;
			}
		}

		$this->assertNotNull( $set );
		$this->assertNotNull( $reboot );
		$this->assertLessThan( $reboot, $set, 'The VM may send from its new address as soon as it has it.' );
	}

	public function test_a_move_the_guest_did_not_follow_is_busy_and_can_be_finished(): void {
		list( $r, $two ) = $this->db_and_second_net();
		$this->private_guest = 'old';

		$res = $this->move( $r, array( 'net' => (string) $two['id'] ) );
		$this->assertTrue( $res->is_failed(), $res->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( \BeltoftServices\Proxmox\Db\DbPrivateMove::unfinished( $fresh ) );
		$this->assertFalse( DbIp::idle( $fresh ), 'Nothing else runs on the database meanwhile.' );
		$view = \BeltoftServices\Proxmox\Network\PrivateAttach::view( $fresh );
		$this->assertTrue( $view['move_unfinished'] );
		$this->assertFalse( $view['can_move'] );
		$this->assertNull( \BeltoftServices\Proxmox\Db\DbPrivateMove::check( $fresh, array() ), 'The Finish button may run it again.' );

		$this->private_guest = 'new';
		$res = $this->move( $r, array() );
		$this->assertTrue( $res->is_done(), $res->error() );
		$fresh = Resource::find( $r->id() );
		$this->assertFalse( \BeltoftServices\Proxmox\Db\DbPrivateMove::unfinished( $fresh ) );
		$this->assertTrue( DbIp::idle( $fresh ) );
		$this->assertSame( '10.64.0.16/28', (string) $fresh->get( 'db_cidr' ) );
	}

	public function test_the_sync_after_a_restore_sends_the_private_range_again(): void {
		$r = $this->ready_db();
		$this->assertTrue( Resource::find( $r->id() )->set( array( DbIp::RESYNC => true ) ) );
		$this->assertSame( 'sync', DbIp::wanted( Resource::find( $r->id() ) ) );

		$res = null;

		for ( $i = 0; $i < 40; $i++ ) {
			$this->answer_guest( $r );
			$res = ( new DbIp( Resource::find( $r->id() ) ) )->run();

			if ( $res->is_done() || $res->is_failed() ) {
				break;
			}

			$this->fake->elapse( 30 );
		}

		$this->assertTrue( $res->is_done(), $res->error() );
		$fresh   = Resource::find( $r->id() );
		$private = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'private' === $j['job']['op'] ) );
		$this->assertCount( 1, $private );
		$this->assertSame( (string) $fresh->get( 'db_address' ), $private[0]['job']['address'] );
		$this->assertSame( (string) $fresh->get( 'db_cidr' ), $private[0]['job']['private_cidr'] );
		$this->assertNull( $fresh->get( DbIp::RESYNC ) );
	}

	public function test_a_database_with_ipv6_only_gets_the_ipv6_name_servers_and_is_not_built_or_sold_without_them(): void {
		$this->ready_db();
		$this->assertSame( '2001:db8::53', (string) $this->fake->vm( 5000 )['config']['nameserver'], 'An IPv4 resolver is out of reach over IPv6.' );

		$this->save_cluster( array( 'nameservers6' => '' ) );
		$cluster = (array) Clusters::get( 'lab' );
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ) );
		$this->assertNotSame( '', ( new \BeltoftServices\Proxmox\Db\DbProfile( $r ) )->address_error( $cluster, 5001 ) );
		$this->assertFalse( ( new \ReflectionMethod( \BeltoftServices\Proxmox\Vm\Stock::class, 'availability' ) )->invoke( null, $product ), 'Not sold where it could not resolve names.' );
	}

	public function test_the_end_of_an_ipv4_change_marks_the_database_sync_in_the_same_write(): void {
		$r = $this->db_with_ip();
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( \BeltoftServices\Proxmox\Ipv4\Ipv4Server::OP ) );

		// The sync is what is left: a job that stopped after the server's step runs only the sync, no second restart.
		$this->assertTrue( $fresh->set( array( DbIp::RESYNC => true ) ) );
		$reboots = count( $this->calls( 'POST', '/status/reboot' ) );
		$res     = $this->drive( $r, \BeltoftServices\Proxmox\Ipv4\Ipv4Server::MAIN_ADD );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertCount( $reboots, $this->calls( 'POST', '/status/reboot' ) );
		$this->assertNull( Resource::find( $r->id() )->get( DbIp::RESYNC ) );
	}

	public function test_ipv4_server_sets_the_database_sync_when_it_ends(): void {
		$r = $this->ready_db();
		$this->pool( 3 );
		$this->public_ip_addon( Resource::find( $r->id() ) );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$driver = Registry::get( 'proxmox-db' );
		$seen   = false;

		// Run only the server's step (as Actions does), never the sync: its end must leave the mark.
		for ( $i = 0; $i < 40; $i++ ) {
			$res = ( new \BeltoftServices\Proxmox\Vm\Actions( Resource::find( $r->id() ) ) )->run( \BeltoftServices\Proxmox\Ipv4\Ipv4Server::MAIN_ADD, array() );

			if ( $res->is_done() || $res->is_failed() ) {
				$seen = true;
				break;
			}

			$this->fake->elapse( 30 );
		}

		unset( $driver );
		$this->assertTrue( $seen && $res->is_done(), $res->error() );
		$this->assertTrue( Resource::find( $r->id() )->get( DbIp::RESYNC ) );
	}
}

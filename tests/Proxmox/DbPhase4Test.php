<?php
/**
 * Managed database public IPv4 add-on and allow-list. Only the fake client is used; mail goes to the owner's address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DbGuest;
use BeltoftServices\Proxmox\DbAccess;
use BeltoftServices\Proxmox\DbIp;
use BeltoftServices\Proxmox\DbNeedsServer;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\Stock;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Proxmox\DbProfile;
use BeltoftServices\Proxmox\Profiles;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Api\Routes;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

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
		set_transient( \BeltoftServices\Proxmox\Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'node-a', 'status' => 'online', 'maxmem' => 68719476736, 'mem' => 0 ) ), HOUR_IN_SECONDS );

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
		$product = $this->service_product(
			'proxmox-db',
			array_merge( array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), $plan ),
			array( 'max' => 3 )
		);
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );

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

	private function backup_of( Resource $r ): string {
		$this->assertIsString( $this->fake->task( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => (int) $r->get( 'vmid' ), 'storage' => 'backup-store' ) ) );
		$list = ( new Backups( Resource::find( $r->id() ) ) )->list();
		$this->assertNotEmpty( $list );

		return $list[0]['volid'];
	}

	private function follow_up(): void {
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		ProxmoxJobs::backup_next();
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
		$product = $this->service_product(
			'proxmox-db',
			array_merge( array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), $plan ),
			array( 'max' => 3 )
		);
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'db_state' => 'ready' ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Answers the guest's allow job of the stored operation, like the guest would.
	 */
	private function answer_guest( Resource $r ): void {
		unset( $r );
		$job = json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );

		if ( ! is_array( $job ) || empty( $job['request'] ) || ! in_array( $job['op'] ?? '', array( 'allow', 'listen' ), true ) ) {
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
		$this->assertTrue( $this->add_ip( $r )->is_done() );

		return Resource::find( $r->id() );
	}

	private function add_ip( Resource $r ): \BeltoftServices\Drivers\StepResult {
		$fresh = Resource::find( $r->id() );
		$plan  = (array) $fresh->get( 'plan' );
		$this->assertTrue( true === $fresh->change_plan( array_merge( $plan, array( 'public_ip' => 1 ) ), 'Plan changed' ) );
		$res = $this->drive( $r );

		if ( $res->is_done() ) {
			$this->assertTrue( Resource::find( $r->id() )->set( array_merge( $res->meta(), array( 'plan_dirty' => false ) ) ) );
		}

		return $res;
	}

	private function remove_ip( Resource $r ): \BeltoftServices\Drivers\StepResult {
		$fresh = Resource::find( $r->id() );
		$plan  = (array) $fresh->get( 'plan' );
		$this->assertTrue( true === $fresh->change_plan( array_merge( $plan, array( 'public_ip' => 0 ) ), 'Plan changed' ) );
		$res = $this->drive( $r );

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
		$this->assertSame( 1, IpPool::available_for_db() );
		$this->assertNotNull( IpPool::hold( 9001, 1 ), 'Two free: one may go.' );
		$this->assertNull( IpPool::hold( 9002, 1 ), 'One free is the reserve.' );
		$this->assertSame( 0, IpPool::available_for_db() );
		$this->assertNotNull( IpPool::hold( 9001, 1 ), 'A resource that holds an address keeps it.' );
		$this->assertNotNull( IpPool::hold( 9002 ), 'A server (no reserve) takes the last one.' );
		$this->assertSame( 0, IpPool::free_count() );
	}

	public function test_the_reserve_is_a_setting(): void {
		$this->pool( 1 );
		$this->assertSame( 0, IpPool::available_for_db() );
		$this->settings( array( 'ip_reserve_for_vps' => 0 ) );
		$this->assertSame( 1, IpPool::available_for_db() );
		$this->assertArrayHasKey( 'ip_reserve_for_vps', apply_filters( 'beltoft_services_settings_fields', array() ) );
	}

	public function test_a_public_ip_plan_is_sold_out_at_the_reserve_and_a_plan_without_is_not(): void {
		$with    = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20, 'public_ip' => 1 ), array( 'slug' => 'dbip-' . wp_generate_password( 5, false ) ) );
		$without = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'slug' => 'dbno-' . wp_generate_password( 5, false ) ) );

		$this->assertFalse( Stock::available( $with ), 'No address at all.' );
		$this->assertTrue( Stock::available( $without ), 'A database without the add-on needs no address.' );

		$this->pool( 1 );
		$this->assertFalse( Stock::available( $with ), 'The last address is kept for servers.' );
		$this->assertTrue( Stock::available( $without ) );

		$this->pool( 1, 20 );
		$this->assertTrue( Stock::available( $with ) );

		$this->settings( array( 'ip_reserve_for_vps' => 2 ) );
		delete_transient( IpPool::FREE_TRANSIENT );
		$this->assertFalse( Stock::available( $with ) );
	}

	public function test_a_switch_to_the_add_on_is_refused_when_no_address_can_be_given(): void {
		$this->pool( 1 );
		$r   = $this->ready_db();
		$sub = wcs_create_subscription( array( 'customer_id' => (int) $r->license()['user_id'], 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( $wpdb->prefix . 'beltoft_licenses', array( 'subscription_id' => $sub->get_id() ), array( 'id' => (int) $r->license()['id'] ) );

		$product = (int) $r->license()['product_id'];
		$target  = array_merge( (array) $r->get( 'plan' ), array( 'public_ip' => 1 ) );

		$this->assertStringContainsString( 'No public IP address is available', DbIp::switch_error( $sub->get_id(), $product, $target, null ) );
		$this->assertSame( '', DbIp::switch_error( $sub->get_id(), $product, array_merge( $target, array( 'public_ip' => 0 ) ), null ), 'A switch to a plan without the add-on is never refused.' );
		$this->pool( 2 );
		$this->assertSame( '', DbIp::switch_error( $sub->get_id(), $product, $target, null ) );
	}

	// -- The profile and the build ---------------------------------------------------------------

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
		$this->assertSame( 'ip=192.0.2.10/24,gw=192.0.2.1', $vm['config']['ipconfig0'] );
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

	public function test_adding_the_add_on_later_takes_an_address_after_a_restart_and_allows_nobody(): void {
		$this->pool( 3 );
		$r = $this->ready_db();
		$this->assertArrayNotHasKey( 'net0', $this->fake->vm( 5000 )['config'] );
		$this->assertSame( 3, IpPool::free_count() );

		$res = $this->add_ip( $r );
		$this->assertTrue( $res->is_done(), $res->error() );

		$vm = $this->fake->vm( 5000 );
		$this->assertSame( 'running', $vm['status'], 'The VM runs again.' );
		$this->assertSame( 'ip=192.0.2.10/24,gw=192.0.2.1', $vm['config']['ipconfig0'] );
		$this->assertStringContainsString( 'bridge=vmbr9', $vm['config']['net0'] );
		$this->assertStringContainsString( 'bridge=vmbr2', $vm['config']['net1'], 'The private NIC is untouched.' );
		$this->assertSame( 'assigned', IpPool::for_resource( $r->id() )['state'] );
		$this->assertSame( 2, IpPool::free_count() );
		$this->assertSame( array(), $this->net0_rules() );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ) );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( '192.0.2.10', $fresh->get( 'ip' ) );
		$this->assertNull( $fresh->get( DbIp::OP ), 'The operation is over.' );
		$this->assertSame( 'allow', json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true )['op'] );
		$this->assertSame( array(), json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true )['allow'] );

		$shutdown = $this->calls( 'POST', '/status/shutdown' );
		$this->assertCount( 1, $shutdown, 'One restart, announced on the page.' );
	}

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

	public function test_removing_the_add_on_sends_the_private_address_only_after_the_nic_is_gone(): void {
		$r = $this->db_with_ip();
		$this->assertTrue( $this->remove_ip( $r )->is_done() );

		$private = (string) Resource::find( $r->id() )->get( 'db_address' );
		$listen  = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'listen' === $j['job']['op'] ) );
		$this->assertCount( 2, $listen, 'One job for the add and one for the removal.' );
		$this->assertSame( array( $private ), $listen[1]['job']['listen'] );

		$gone = null;

		foreach ( $this->fake->calls() as $i => $c ) {
			if ( 'PUT' === $c['method'] && false !== strpos( (string) ( $c['params']['delete'] ?? '' ), 'net0' ) ) {
				$gone = $i; // The last one: the removal (the build deleted a template NIC earlier).
			}
		}

		$allow = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'allow' === $j['job']['op'] && $j['at'] < ( $gone ?? 0 ) ) );
		$this->assertNotNull( $gone );
		$this->assertGreaterThan( $gone, $listen[1]['at'] );
		$this->assertNotEmpty( $allow, 'The list was emptied in the guest before the card went.' );
	}

	public function test_a_guest_that_does_not_know_the_listen_job_fails_an_add_before_anything_is_opened_and_a_retry_works(): void {
		$this->pool( 3 );
		$r = $this->ready_db();
		$this->listen_guest = 'old';

		$res = $this->add_ip( $r );
		$this->assertTrue( $res->is_failed() );
		$this->assertStringContainsString( 'does not know the listen job', $res->error() );
		$this->assertStringContainsString( 'Rebuild the database template', $res->error() );
		$this->assertStringContainsString( 'nothing is open', $res->error() );
		$this->assertSame( array(), $this->net0_rules() );

		$fresh = Resource::find( $r->id() );
		$op    = $fresh->get( DbIp::OP );
		$this->assertIsArray( $op );
		$this->assertArrayNotHasKey( 'listen_request', $op, 'A retry sends the job again.' );
		$this->assertSame( 'listen', $op['phase'] );

		$this->listen_guest = 'new';
		$res                = $this->drive( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertNull( Resource::find( $r->id() )->get( DbIp::OP ) );
		$this->assertCount( 2, array_filter( $this->guest_jobs(), static fn( $j ) => 'listen' === $j['job']['op'] ) );
	}

	public function test_a_guest_that_never_answers_the_listen_job_fails_an_add_after_ten_minutes(): void {
		$this->pool( 3 );
		$r = $this->ready_db();
		$this->listen_guest = 'silent';

		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'public_ip' => 1 ) ), 'Plan changed' ) );

		for ( $i = 0; $i < 40; $i++ ) {
			$res = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );
			$this->assertFalse( $res->is_failed(), $res->error() );
			$op = Resource::find( $r->id() )->get( DbIp::OP );

			if ( is_array( $op ) && ! empty( $op['listen_sent'] ) ) {
				break;
			}

			$this->answer_guest( $r );
			$this->fake->elapse( 30 );
		}

		$this->assertTrue( $res->is_poll(), 'The job was sent and nothing answers.' );
		$op                = Resource::find( $r->id() )->get( DbIp::OP );
		$op['listen_at']   = time() - 700;
		$this->assertTrue( Resource::find( $r->id() )->set( array( DbIp::OP => $op ) ) );

		$res = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_failed() );
		$this->assertStringContainsString( 'did not apply the listen addresses within 10 minutes', $res->error() );
	}

	public function test_a_remove_on_a_guest_without_the_listen_job_tells_the_admin_and_goes_on(): void {
		$r = $this->db_with_ip();
		$this->settings( array( 'admin_email' => self::OWNER ) );
		$mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) use ( &$mails ) {
				$mails[] = $atts;

				return true;
			},
			10,
			2
		);
		$this->listen_guest = 'old';

		$res = $this->remove_ip( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertNull( IpPool::for_resource( $r->id() ), 'The address is given back all the same.' );
		$this->assertNull( Resource::find( $r->id() )->get( DbIp::OP ) );
		$this->assertNotEmpty( $mails );
		$this->assertStringContainsString( 'does not know the listen job', implode( "\n", array_column( $mails, 'message' ) ) );
	}

	public function test_a_running_vm_is_never_written_while_the_shutdown_is_pending(): void {
		$this->pool( 2 );
		$r = $this->ready_db();
		$this->fake->lag_status( 5000, 1000 );

		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'public_ip' => 1 ) ), 'Plan changed' ) );
		$res = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );

		$this->assertFalse( $res->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '/status/shutdown' ) );
		$puts = array_filter( $this->calls( 'PUT', '/config' ), static fn( $c ) => isset( $c['params']['net0'] ) );
		$this->assertSame( array(), $puts, 'No NIC was written while the VM could still be running.' );
	}

	public function test_removing_the_add_on_clears_the_list_then_the_nic_and_only_then_frees_the_address(): void {
		$r = $this->db_with_ip();
		$this->assertTrue( true === Resource::find( $r->id() )->set( array( DbAccess::LIST => array( array( 'cidr' => '203.0.113.7/32', 'label' => 'office', 'added' => time() ) ) ) ) );
		$this->assertTrue( $this->apply( $r )->is_done() );
		$this->assertSame( array( 'bs-db:203.0.113.7' ), $this->net0_rules() );
		$free = IpPool::free_count();

		$res = $this->remove_ip( $r );
		$this->assertTrue( $res->is_done(), $res->error() );

		$vm = $this->fake->vm( 5000 );
		$this->assertArrayNotHasKey( 'net0', $vm['config'] );
		$this->assertArrayNotHasKey( 'ipconfig0', $vm['config'] );
		$this->assertStringContainsString( 'bridge=vmbr2', $vm['config']['net1'] );
		$this->assertSame( 'running', $vm['status'] );
		$this->assertSame( array(), $this->net0_rules() );
		$this->assertNull( IpPool::for_resource( $r->id() ) );
		$this->assertSame( $free + 1, IpPool::free_count() );
		$this->assertNull( VmRecords::for_resource( $r->id() )['ip_id'] );

		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'ip' ) );
		$this->assertSame( array(), DbAccess::entries( $fresh ), 'The list goes with the address.' );
		$this->assertFalse( DbAccess::world( $fresh ) );
		$allow_jobs = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'allow' === $j['job']['op'] ) );
		$this->assertSame( array(), end( $allow_jobs )['job']['allow'] );

		// The firewall rule went before the NIC did.
		$calls   = $this->fake->calls();
		$deleted = null;
		$nic     = null;

		foreach ( $calls as $i => $c ) {
			if ( 'DELETE' === $c['method'] && false !== strpos( $c['path'], '/firewall/rules/' ) ) {
				$deleted = $i;
			}

			if ( 'PUT' === $c['method'] && '/config' === substr( $c['path'], -7 ) && false !== strpos( (string) ( $c['params']['delete'] ?? '' ), 'net0' ) ) {
				$nic = $i;
			}
		}

		$this->assertNotNull( $deleted );
		$this->assertNotNull( $nic );
		$this->assertLessThan( $nic, $deleted );
	}

	public function test_the_address_is_not_given_back_while_the_nic_could_not_be_removed(): void {
		$r = $this->db_with_ip();
		$this->fake->fail_next( 'PUT', '#/qemu/5000/config$#', array( 'status' => 500, 'message' => 'timeout' ) );

		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'public_ip' => 0 ) ), 'Plan changed' ) );
		$res = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );

		$this->assertFalse( $res->is_done() );
		$this->assertArrayHasKey( 'net0', $this->fake->vm( 5000 )['config'], 'The NIC is still there.' );
		$this->assertSame( 'assigned', IpPool::for_resource( $r->id() )['state'], 'So the address is still ours.' );

		// The next run removes it, and only then the address goes back.
		$res = $this->drive( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertNull( IpPool::for_resource( $r->id() ) );
	}

	public function test_a_refused_add_gives_the_address_back_starts_the_vm_again_and_an_unclear_one_keeps_it(): void {
		$this->pool( 3 );
		$r = $this->ready_db();
		$this->fake->fail_next( 'PUT', '#/qemu/5000/config$#', array( 'status' => 422, 'message' => 'invalid' ) );

		$res = $this->add_ip( $r );
		$this->assertTrue( $res->is_failed() );
		$this->assertNull( IpPool::for_resource( $r->id() ), 'A definite refusal frees the address.' );
		$this->assertSame( 3, IpPool::free_count() );
		$this->assertNull( Resource::find( $r->id() )->get( DbIp::OP ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'The change that did not happen leaves the database running.' );
		$this->assertArrayNotHasKey( 'net0', $this->fake->vm( 5000 )['config'] );

		// An unclear answer (the write may have been applied): the address stays reserved until the config shows the card.
		$this->fake->fail_next( 'PUT', '#/qemu/5000/config$#', array( 'status' => 500, 'message' => 'timeout' ) );
		$res = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );
		$this->assertFalse( $res->is_done() );
		$this->assertSame( 'held', IpPool::for_resource( $r->id() )['state'] );
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
		$this->assertSame( 0, IpPool::available_for_db() );
		$r = $this->ready_db( array( 'public_ip' => 1 ) );
		$this->assertSame( '192.0.2.10', DbAccess::public_address( $r ) );
		$this->assertSame( 0, IpPool::free_count() );
	}

	// -- The variation pair ----------------------------------------------------------------------

	/**
	 * A variable database product with a Public IP attribute (No, Yes) and a subscription on the plan without.
	 *
	 * @return array{0: Resource, 1: array<string, \WC_Product_Variation>}
	 */
	private function database_on_pair(): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Database ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Public IP' );
		$attribute->set_options( array( 'No', 'Yes' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20 ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$variations = array();

		foreach ( array( 'No' => array( '12', '0' ), 'Yes' => array( '16', '1' ) ) as $label => $set ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'public-ip' => $label ) );
			$v->set_regular_price( $set[0] );
			$v->set_status( 'publish' );
			$v->update_meta_data( Product::META_PLAN . 'public_ip', $set[1] );
			$v->save();
			$variations[ $label ] = $v;
		}

		$owner = self::factory()->user->create( array( 'user_email' => self::OWNER ) );
		$sub   = wcs_create_subscription( array( 'customer_id' => $owner, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->add_product( $variations['No'], 1 );
		$sub->save();

		$parent  = wc_get_product( $product->get_id() );
		$license = $this->service_license( $parent, array( 'product_slug' => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ), 'variation_id' => $variations['No']->get_id(), 'user_id' => $owner, 'subscription_id' => $sub->get_id(), 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $parent->get_id(), $variations['No']->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'db_state' => 'ready', 'db_address' => '10.64.0.2' ) ) );

		return array( Resource::find( $r->id() ), $variations );
	}

	public function test_the_add_on_is_a_switch_to_the_sibling_variation_with_its_price(): void {
		list( $r, $v ) = $this->database_on_pair();
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );

		$this->assertSame( $v['Yes']->get_id(), DbIp::sibling( $r, true )->get_id() );
		$this->assertNull( DbIp::sibling( $r, false ), 'The plan has no add-on to remove.' );

		$url  = DbIp::switch_url( $r, true );
		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$this->assertSame( wc_get_checkout_url(), strtok( $url, '?' ) );
		$this->assertSame( (string) $v['Yes']->get_id(), (string) $args['variation_id'] );
		$this->assertArrayHasKey( 'switch-subscription', $args );
		$this->assertStringContainsString( '4.00', DbIp::price_label( $r, true ) );
		$this->assertStringStartsWith( '+', DbIp::price_label( $r, true ) );

		// The tab offers it with the price and the restart note, when an address can be given.
		$this->pool( 2 );
		$html = $this->card( $r );
		$this->assertStringContainsString( 'Add a public IP address', $html );
		$this->assertStringContainsString( 'restarts once', $html );
		$this->assertStringContainsString( 'variation_id=' . $v['Yes']->get_id(), html_entity_decode( $html ) );

		// With no address to give, it says so and offers nothing.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reset.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::tables()['ips'] ) );
		delete_transient( IpPool::FREE_TRANSIENT );
		$html = $this->card( $r );
		$this->assertStringContainsString( 'No public IP address is available right now', $html );
		$this->assertStringNotContainsString( 'Add a public IP address', $html );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
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
			'ipv6'                   => array( '2001:db8::1', null ),
			'ipv6 range'             => array( '2001:db8::/32', null ),
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
		$v       = \BeltoftServices\Proxmox\Ownership::verify( $client, $record, $fresh );
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

		foreach ( array( '10.0.0.4', '0.0.0.0/0', '198.50.0.0/15', '2001:db8::1', '203.0.113.9/24' ) as $bad ) {
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

	public function test_the_access_tab_without_an_address_offers_the_add_on_or_says_sold_out(): void {
		$r = $this->ready_db();
		$html = $this->card( $r );

		$this->assertStringContainsString( 'No public IP address is available right now', $html, 'The pool is empty.' );
		$this->assertStringNotContainsString( 'data-db-access-add', $html );
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
		$this->assertStringContainsString( 'data-db-access-world="world_off"', $html );
	}

	public function test_a_database_without_an_address_shows_only_the_private_connection(): void {
		$r = $this->ready_db();
		$view = DbCard::view( $r );
		$this->assertSame( '', $view['public_verify'] );
		$this->assertSame( '', $view['access']['address'] );
		$this->assertStringNotContainsString( '@192.0.2', $this->card( $r, 'overview' ) );
	}

	// -- The checkout notice ---------------------------------------------------------------------

	public function test_the_cart_notice_drops_coming_soon_and_a_public_ip_database_gets_none(): void {
		// Nothing with a public IP for sale: the add-on is not promised.
		$this->assertStringContainsString( 'coming soon', DbNeedsServer::message( false ) );
		$this->assertStringNotContainsString( 'add-on', DbNeedsServer::message( false ) );

		$this->database_on_pair();
		$this->assertStringNotContainsString( 'coming soon', DbNeedsServer::message( true ) );
		$this->assertStringNotContainsString( 'coming soon', DbNeedsServer::message( false ) );
		$this->assertStringContainsString( 'add-on', DbNeedsServer::message( false ) );
		$this->assertStringNotContainsString( "\u{2014}", DbNeedsServer::message( true ) );

		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		WC()->cart->empty_cart();
		wc_clear_notices();

		$with    = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20, 'public_ip' => 1 ), array( 'slug' => 'dbip-' . wp_generate_password( 5, false ) ) );
		$without = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'slug' => 'dbno-' . wp_generate_password( 5, false ) ) );

		WC()->cart->add_to_cart( $with->get_id() );
		DbNeedsServer::cart_notice();
		$this->assertSame( array(), wc_get_notices( 'notice' ), 'No notice for a database with a public IP address.' );

		WC()->cart->empty_cart();
		wc_clear_notices();
		WC()->cart->add_to_cart( $without->get_id() );
		DbNeedsServer::cart_notice();
		$this->assertNotEmpty( wc_get_notices( 'notice' ) );

		WC()->cart->empty_cart();
		wc_clear_notices();
		wp_set_current_user( 0 );
	}
}

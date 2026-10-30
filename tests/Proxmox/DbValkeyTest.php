<?php
/**
 * The Valkey engine of the managed database driver: the engine seam, the build, the card, the firewall port and the
 * monitoring extras. PostgreSQL stays the default and is covered by the other Db tests. Only the fake client is used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\Databases;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbAccess;
use BeltoftServices\Proxmox\DbActions;
use BeltoftServices\Proxmox\DbBuild;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DbEngine;
use BeltoftServices\Proxmox\DbGuest;
use BeltoftServices\Proxmox\DbMonitor;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\PostgresEngine;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\ValkeyEngine;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A database driver that does not sleep.
 */
class ValkeyDbDriver extends DbDriver {

	public function nap( int $seconds ): void {
		unset( $seconds );
	}
}

class DbValkeyTest extends TestCase {

	private const SECRET   = 'secret-secret-secret-1';
	private const OWNER    = 'christian.beltoft@gmail.com';
	private const PASSWORD = 'Abcdefghijklmnopqrstuvwxyz012345';
	private const FP       = '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF';
	private const PEM      = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ2VydGlmaWNhdGVGb3JUZXN0aW5nMAoGCCqGSM49BAMC\n-----END CERTIFICATE-----\n";

	/** @var FakeClient */
	private $fake;

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

		$this->wipe();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5, 'admin_email' => self::OWNER, 'monitor_alerts' => 'off' ) );

		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		add_filter(
			'beltoft_services_drivers',
			function ( $drivers ) {
				$drivers['proxmox-db'] = new ValkeyDbDriver();
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );

		foreach ( array( 9100 => 'postgres-template', 9110 => 'valkey-template' ) as $vmid => $name ) {
			$this->fake->add_vm(
				array(
					'vmid'     => $vmid,
					'node'     => 'node-a',
					'name'     => $name,
					'template' => 1,
					'config'   => array(
						'scsi0'  => 'disk-store:vm-' . $vmid . '-disk-0,size=10G',
						'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
						'memory' => 512,
						'cores'  => 1,
					),
				)
			);
		}

		$this->assertTrue(
			Clusters::save(
				'lab',
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
					'bridge'                 => 'vmbr9',
					'private_bridge'         => 'vmbr2',
					'boot_disk'              => 'scsi0',
					'gateway'                => '192.0.2.1',
					'nameservers'            => '192.0.2.53',
					'prefix'                 => '24',
					'vmid_min'               => '5000',
					'vmid_max'               => '5099',
					'templates'              => "9100 = PostgreSQL 18\n9110 = Valkey",
					'template_families_text' => "9100 = postgres-18\n9110 = valkey",
					'vendor_snippet'         => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
					'db_vendor_snippet'      => 'local:snippets/vm-vendor-cloudinit-db-v1.yaml',
					'block_smtp'             => '1',
				)
			)
		);
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		set_transient( \BeltoftServices\Proxmox\Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'node-a', 'status' => 'online', 'maxmem' => 68719476736, 'mem' => 0 ) ), HOUR_IN_SECONDS );

		as_unschedule_all_actions( \BeltoftServices\Jobs::SWEEP, array(), \BeltoftServices\Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		remove_all_filters( 'beltoft_services_valkey_policy' );
		remove_all_filters( 'beltoft_services_valkey_maxmemory_mb' );
		remove_all_filters( 'pre_wp_mail' );
		delete_option( Clusters::OPTION );
		$this->wipe();
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
	 * @param array<string, mixed> $plan Plan overrides.
	 * @param array<string, mixed> $meta Meta.
	 */
	private function resource( array $plan = array(), array $meta = array() ): Resource {
		$product = $this->service_product(
			'proxmox-db',
			array_merge( array( 'cluster' => 'lab', 'template' => 9110, 'engine' => 'valkey', 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), $plan ),
			array( 'max' => 3 )
		);
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), $meta );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	private function run_provision( Resource $r ): StepResult {
		return Registry::get( 'proxmox-db' )->provision( Resource::find( $r->id() ) );
	}

	private function guest( string $file ): ?string {
		return $this->fake->guest_file( 5000, $file );
	}

	/**
	 * Builds up to the point where the init job is on its way.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function built_until_init( array $plan = array() ): Resource {
		$r      = $this->resource( $plan );
		$result = $this->run_provision( $r );
		$this->assertTrue( $result->is_poll(), 'The init job is sent and the build waits: ' . $result->error() );

		return Resource::find( $r->id() );
	}

	/**
	 * A built, ready database with a healthy guest.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function built( array $plan = array() ): Resource {
		$r       = $this->built_until_init( $plan );
		$request = (string) $r->get( 'db_request' );
		$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $request, 'state' => 'ready', 'step' => 'complete', 'updated' => gmdate( 'c' ) ) ) );
		$this->fake->set_guest_file( 5000, DbGuest::CREDENTIALS, wp_json_encode( array( 'request' => $request, 'password' => self::PASSWORD, 'ca_pem' => self::PEM, 'fingerprint' => self::FP ) ) );
		$this->assertTrue( $this->run_provision( $r )->is_poll() );
		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( array( 'updated' => gmdate( 'c' ), 'ok' => true ) ) );
		$done = $this->run_provision( $r );
		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertTrue( Resource::find( $r->id() )->set( array_merge( $done->meta(), array( 'state' => 'ready', 'desired' => 'ready', 'task' => null ) ) ) );

		return Resource::find( $r->id() );
	}

	private function render( Resource $r ): string {
		ob_start();
		Registry::get( 'proxmox-db' )->render_account( $r );

		return (string) ob_get_clean();
	}

	// -- The seam ------------------------------------------------------------------------------

	public function test_the_plan_field_selects_the_engine_and_postgres_is_the_default(): void {
		$this->assertInstanceOf( PostgresEngine::class, DbEngine::for_plan( array() ) );
		$this->assertInstanceOf( PostgresEngine::class, DbEngine::for_plan( array( 'engine' => 'postgres' ) ) );
		$this->assertInstanceOf( ValkeyEngine::class, DbEngine::for_plan( array( 'engine' => 'valkey' ) ) );
		$this->assertNull( DbEngine::for_plan( array( 'engine' => 'mysql' ) ) );
		$this->assertNull( DbEngine::for_plan( array( 'engine' => '' ) ) );

		$this->assertInstanceOf( ValkeyEngine::class, DbEngine::for_resource( $this->resource() ) );
		$this->assertInstanceOf( PostgresEngine::class, DbEngine::for_resource( $this->resource( array( 'engine' => 'postgres', 'major' => 18, 'template' => 9100 ) ) ) );
		$this->assertInstanceOf( PostgresEngine::class, DbEngine::for_resource( $this->resource( array( 'engine' => 'mysql' ) ) ), 'An engine that is not known reads as PostgreSQL; the build refuses the plan.' );
	}

	public function test_the_engines_describe_themselves(): void {
		$pg = new PostgresEngine();
		$vk = new ValkeyEngine();

		$this->assertSame( array( 5432, 6380 ), array( $pg->port(), $vk->port() ) );
		$this->assertSame( array( 'app', 'app_user' ), array( $pg->database(), $pg->role() ) );
		$this->assertSame( array( '', 'app_user' ), array( $vk->database(), $vk->role() ) );
		$this->assertSame( 'postgres-18', $pg->family( array( 'major' => 18 ) ) );
		$this->assertSame( 'valkey', $vk->family( array( 'major' => 8 ) ) );
		$this->assertSame( 'PostgreSQL 17', $pg->label( array( 'major' => 17 ) ) );
		$this->assertSame( 'Valkey', $vk->label( array() ) );
		$this->assertSame( 'Valkey', $vk->label( array( 'major' => 18 ) ), 'The major field is PostgreSQL\'s.' );
	}

	public function test_a_plan_is_checked_by_its_engine(): void {
		$this->assertNull( DbBuild::plan_error( $this->resource() ) );
		$this->assertSame( 'A Valkey plan needs at least 512 MB of memory', DbBuild::plan_error( $this->resource( array( 'memory_mb' => 256 ) ) ) );
		$this->assertStringContainsString( 'The engine of this plan is not available', (string) DbBuild::plan_error( $this->resource( array( 'engine' => 'mysql' ) ) ) );
		$this->assertStringContainsString( 'Supported: 17, 18', (string) DbBuild::plan_error( $this->resource( array( 'engine' => 'postgres', 'major' => 16 ) ) ), 'PostgreSQL keeps its own check.' );
		$this->assertStringContainsString( 'must be 0 or 1', (string) DbBuild::plan_error( $this->resource( array( 'public_ip' => 2 ) ) ), 'The shared checks come first.' );
	}

	// -- Templates -----------------------------------------------------------------------------

	public function test_the_valkey_template_is_hidden_from_operating_system_lists_and_serves_its_family(): void {
		$this->assertSame( array(), Clusters::templates( 'lab' ) );
		$this->assertSame( array( 9110 => 'Valkey' ), Clusters::service_templates( 'lab', 'valkey' ) );
		$this->assertSame( array( 9100 => 'PostgreSQL 18' ), Clusters::service_templates( 'lab', 'postgres-18' ) );
	}

	public function test_a_valkey_build_needs_a_template_of_its_family_and_a_postgres_one_still_needs_its_own(): void {
		$wrong  = $this->run_provision( $this->resource( array( 'template' => 9100 ) ) );
		$this->assertTrue( $wrong->is_failed() );
		$this->assertStringContainsString( 'with the family valkey', $wrong->error() );

		$pg = $this->run_provision( $this->resource( array( 'engine' => 'postgres', 'major' => 18, 'template' => 9110 ) ) );
		$this->assertTrue( $pg->is_failed() );
		$this->assertStringContainsString( 'with the family postgres-18', $pg->error() );
	}

	// -- The build -----------------------------------------------------------------------------

	public function test_the_init_job_is_exact_and_holds_no_secret(): void {
		$r   = $this->built_until_init();
		$id  = (string) $r->get( 'db_request' );
		$job = (string) $this->guest( DbGuest::JOB );

		$this->assertSame(
			array( 'v' => 1, 'request' => $id, 'op' => 'init', 'role' => 'app_user', 'listen' => array( '10.64.0.2' ), 'private_cidr' => '10.64.0.0/28', 'allow' => array(), 'world' => false, 'maxmemory_mb' => 3072, 'policy' => 'noeviction' ),
			json_decode( $job, true )
		);
		$this->assertStringStartsWith( '{"v":1,"request":"' . $id . '","op":"init","role":"app_user","listen":["10.64.0.2"],', $job, 'Key order is the contract\'s.' );
		$this->assertSame( 'installing', $r->get( 'db_state' ) );

		$vm = $this->fake->vm( 5000 );
		$this->assertSame( 'virtio=BC:24:11:13:88:11,bridge=vmbr2,tag=1000,firewall=1', $vm['config']['net1'] );
		$this->assertArrayNotHasKey( 'net0', $vm['config'] );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 6380, '10.64.0.7', 'net1' ), 'The private network reaches the database.' );
	}

	public function test_memory_limit_and_policy_follow_the_plan_and_filters(): void {
		$this->assertSame( 384, ValkeyEngine::maxmemory_mb( $this->resource( array( 'memory_mb' => 512 ) ) ) );
		$this->assertSame( 768, ValkeyEngine::maxmemory_mb( $this->resource( array( 'memory_mb' => 1024 ) ) ) );
		$this->assertSame( 6144, ValkeyEngine::maxmemory_mb( $this->resource( array( 'memory_mb' => 8192 ) ) ) );

		add_filter( 'beltoft_services_valkey_maxmemory_mb', fn() => 100000 );
		$this->assertSame( 3686, ValkeyEngine::maxmemory_mb( $this->resource() ), 'Never more than 90 percent of the memory.' );
		remove_all_filters( 'beltoft_services_valkey_maxmemory_mb' );
		add_filter( 'beltoft_services_valkey_maxmemory_mb', fn() => 1 );
		$this->assertSame( 64, ValkeyEngine::maxmemory_mb( $this->resource() ) );

		$this->assertSame( 'noeviction', ValkeyEngine::policy( $this->resource() ) );
		add_filter( 'beltoft_services_valkey_policy', fn() => 'allkeys-lru' );
		$this->assertSame( 'allkeys-lru', ValkeyEngine::policy( $this->resource() ) );
		remove_all_filters( 'beltoft_services_valkey_policy' );
		add_filter( 'beltoft_services_valkey_policy', fn() => 'everything; reboot' );
		$this->assertSame( 'noeviction', ValkeyEngine::policy( $this->resource() ), 'A policy that is not on the list is not sent.' );
	}

	public function test_a_database_with_the_public_ip_lists_both_addresses_in_the_init_job(): void {
		IpPool::add( '192.0.2.10', '192.0.2.1' );
		$this->built_until_init( array( 'public_ip' => 1 ) );
		$job = json_decode( (string) $this->guest( DbGuest::JOB ), true );

		$this->assertSame( array( '10.64.0.2', '192.0.2.10' ), $job['listen'] );
		$this->assertSame( 'init', $job['op'] );
	}

	public function test_a_new_password_is_the_same_job_as_for_postgres(): void {
		$r = $this->built();
		$this->assertSame( array( DbActions::RESET => 'New password' ), Registry::get( 'proxmox-db' )->customer_actions( $r ) );
		$this->assertTrue( Registry::get( 'proxmox-db' )->act( $r, DbActions::RESET, array( 'confirm' => DbActions::CONFIRM ) )->is_poll() );
		$request = (string) Resource::find( $r->id() )->get( 'db_reset_request' );
		$this->assertSame( '{"v":1,"request":"' . $request . '","op":"reset_password"}', $this->guest( DbGuest::JOB ) );
	}

	// -- The card ------------------------------------------------------------------------------

	public function test_the_card_shows_the_valkey_connection_and_never_the_password(): void {
		$r    = $this->built();
		$html = $this->render( $r );

		$this->assertStringContainsString( 'rediss://app_user:PASSWORD@10.64.0.2:6380', $html );
		$this->assertStringContainsString( 'valkey-cli --tls --cacert ca.crt -h 10.64.0.2 -p 6380 --user app_user --pass PASSWORD', $html );
		$this->assertStringContainsString( 'Valkey speaks the Redis protocol', $html );
		$this->assertStringContainsString( '<dd>Valkey</dd>', $html );
		$this->assertStringContainsString( '<dd><code>6380</code></dd>', $html );
		$this->assertStringContainsString( 'Download CA certificate', $html );
		$this->assertStringContainsString( self::FP, $html );
		$this->assertStringContainsString( 'data-db-reveal', $html );
		$this->assertStringNotContainsString( '<dt>Database</dt>', $html, 'Valkey has no database name.' );
		$this->assertStringNotContainsString( 'postgresql', strtolower( $html ) );
		$this->assertStringNotContainsString( self::PASSWORD, $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertSame( 2 + 1, substr_count( $html, 'class="beltoft-services-db__block"' ), 'Two connection strings and the fingerprint.' );
		$this->assertSame( 2, substr_count( $html, 'data-db-conn' ) );
	}

	public function test_the_card_of_a_database_with_a_public_address_adds_its_connection(): void {
		IpPool::add( '192.0.2.10', '192.0.2.1' );
		$r = $this->built( array( 'public_ip' => 1 ) );
		$v = DbCard::view( $r );

		$this->assertSame( 'valkey', $v['engine'] );
		$this->assertSame( 'Valkey', $v['engine_label'] );
		$this->assertSame( 6380, $v['port'] );
		$this->assertSame( '', $v['database'] );
		$this->assertSame( 'rediss://app_user:PASSWORD@10.64.0.2:6380', $v['connections'][0]['value'] );
		$this->assertSame( 'rediss://app_user:PASSWORD@192.0.2.10:6380', $v['public_connections'][0]['value'] );
		$this->assertStringContainsString( 'rediss://app_user:PASSWORD@192.0.2.10:6380', $this->render( $r ) );
	}

	public function test_the_database_list_names_the_engine(): void {
		$r = $this->built();
		$this->assertSame( 'Valkey', DbCard::view( $r )['engine_label'] );
		$area = new Databases();
		$this->assertSame( 'Valkey', $area->label_of( $r ) );
	}

	// -- The firewall port ---------------------------------------------------------------------

	public function test_the_allow_list_opens_the_valkey_port_on_the_public_nic_and_nothing_else(): void {
		IpPool::add( '192.0.2.10', '192.0.2.1' );
		$r = $this->built( array( 'public_ip' => 1 ) );
		$this->assertTrue( $r->set( array( DbAccess::LIST => array( array( 'cidr' => '203.0.113.7/32', 'label' => 'Office', 'added' => time() ) ), DbAccess::REV => 1 ) ) );

		$driver = Registry::get( 'proxmox-db' );
		$res    = null;

		for ( $i = 0; $i < 40; $i++ ) {
			$job = json_decode( (string) $this->guest( DbGuest::JOB ), true );

			if ( is_array( $job ) && 'allow' === ( $job['op'] ?? '' ) ) {
				$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $job['request'], 'state' => 'ready', 'step' => 'allow', 'updated' => gmdate( 'c' ) ) ) );
			}

			$res = $driver->act( Resource::find( $r->id() ), DbAccess::APPLY, array() );

			if ( $res->is_done() || $res->is_failed() ) {
				break;
			}

			$this->fake->elapse( 30 );
		}

		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 6380, '203.0.113.7', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 6380, '203.0.113.8', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ), 'The PostgreSQL port is not opened for Valkey.' );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 22, '203.0.113.7', 'net0' ) );

		$sent = json_decode( (string) $this->guest( DbGuest::JOB ), true );
		$this->assertSame( array( '203.0.113.7/32' ), $sent['allow'], 'The guest gets the same allow job as a PostgreSQL guest.' );
	}

	// -- Health and monitoring -----------------------------------------------------------------

	public function test_the_health_reader_takes_the_valkey_extras_only_when_they_are_there(): void {
		$r = $this->built();
		unset( $r );
		$base = array( 'updated' => gmdate( 'c' ), 'ok' => true );

		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( $base ) );
		$bare = DbGuest::health( $this->fake, 'node-a', 5000 );
		$this->assertArrayNotHasKey( 'memory_percent', $bare );
		$this->assertArrayNotHasKey( 'evictions', $bare );

		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( $base + array( 'memory_percent' => 42.5, 'evictions' => 7 ) ) );
		$good = DbGuest::health( $this->fake, 'node-a', 5000 );
		$this->assertSame( 42.5, $good['memory_percent'] );
		$this->assertSame( 7, $good['evictions'] );

		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( $base + array( 'memory_percent' => null, 'evictions' => null ) ) );
		$null = DbGuest::health( $this->fake, 'node-a', 5000 );
		$this->assertNull( $null['memory_percent'] );
		$this->assertNull( $null['evictions'] );

		foreach ( array( array( 'memory_percent' => 140 ), array( 'memory_percent' => '90' ), array( 'memory_percent' => -1 ), array( 'evictions' => -1 ), array( 'evictions' => '5' ), array( 'evictions' => 1.5 ), array( 'evictions' => array( 1 ) ) ) as $over ) {
			$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( $base + $over ) );
			$this->assertNull( DbGuest::health( $this->fake, 'node-a', 5000 ), wp_json_encode( $over ) );
		}
	}

	/**
	 * @param array<string, mixed> $over Values over a healthy reading.
	 * @return array<string, mixed>
	 */
	private function reading( array $over = array() ): array {
		$now = time();

		return array_merge( array( 'ok' => true, 'updated' => $now, 'connections' => 1, 'disk_percent' => 10.0, 'last_dump' => $now - HOUR_IN_SECONDS, 'cert_expires' => $now + 300 * DAY_IN_SECONDS, 'bans' => null ), $over );
	}

	/**
	 * @return array<int, string> Bodies of the mails to the admin.
	 */
	private function bodies(): array {
		return array_map( static fn( $m ) => (string) $m['message'], $this->mails );
	}

	public function test_memory_alert_fires_once_and_rearms(): void {
		$r = $this->built();

		DbMonitor::apply( $r, $this->reading( array( 'memory_percent' => 70.0 ) ) );
		$this->assertCount( 0, $this->mails );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'memory_percent' => 93.0 ) ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'uses 93 percent of its memory limit', $this->bodies()[0] );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'memory_percent' => 95.0 ) ) );
		$this->assertCount( 1, $this->mails, 'Once per episode.' );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'memory_percent' => 85.0 ) ) );
		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'memory_percent' => 91.0 ) ) );
		$this->assertCount( 1, $this->mails, 'Between 80 and 90 the episode is not over.' );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'memory_percent' => 50.0 ) ) );
		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'memory_percent' => 92.0 ) ) );
		$this->assertCount( 2, $this->mails, 'Re-armed below 80.' );
	}

	public function test_eviction_alert_needs_a_rise_after_a_baseline_and_rearms(): void {
		$r = $this->built();

		DbMonitor::apply( $r, $this->reading( array( 'evictions' => 100 ) ) );
		$this->assertCount( 0, $this->mails, 'The first reading is the baseline.' );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'evictions' => 100 ) ) );
		$this->assertCount( 0, $this->mails );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'evictions' => 250 ) ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'evicting keys', $this->bodies()[0] );
		$this->assertStringContainsString( '150 since the last reading', $this->bodies()[0] );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'evictions' => 400 ) ) );
		$this->assertCount( 1, $this->mails, 'Once while it goes on.' );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'evictions' => 400 ) ) );
		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'evictions' => 410 ) ) );
		$this->assertCount( 2, $this->mails, 'A reading without a new eviction re-arms.' );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( array( 'evictions' => 3 ) ) );
		$this->assertCount( 2, $this->mails, 'A restart that resets the count is not an eviction.' );
	}

	public function test_a_postgres_reading_has_no_valkey_alerts(): void {
		$r = $this->built();
		DbMonitor::apply( $r, $this->reading() );
		$this->assertCount( 0, $this->mails );
		$this->assertSame( array(), array_intersect_key( (array) Resource::find( $r->id() )->get( DbMonitor::META ), array_flip( array( 'memory_admin', 'evictions_seen', 'evictions_admin' ) ) ) );
	}
}

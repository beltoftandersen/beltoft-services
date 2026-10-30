<?php
/**
 * The managed database build, guest protocol, login reveal, card and routes. Only the fake client is used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbActions;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DbGuest;
use BeltoftServices\Proxmox\DbProfile;
use BeltoftServices\Proxmox\DbRoutes;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\Profiles;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Proxmox\VpsProfile;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A database driver that does not sleep.
 */
class QuickDbDriver extends DbDriver {

	public function nap( int $seconds ): void {
		unset( $seconds );
	}
}

class DbBuildTest extends TestCase {

	private const SECRET   = 'secret-secret-secret-1';
	private const PASSWORD = 'Abcdefghijklmnopqrstuvwxyz012345';
	private const NEW_PASS = 'ZYXWVUTSRQPONMLKJIHGFEDCBA987654';
	private const FP       = '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF';
	private const PEM      = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ2VydGlmaWNhdGVGb3JUZXN0aW5nMAoGCCqGSM49BAMC\n-----END CERTIFICATE-----\n";

	/** @var FakeClient */
	private $fake;

	/** @var QuickDbDriver */
	private $driver;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5, 'admin_email' => 'christian.beltoft@gmail.com' ) );

		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );

		$this->driver = new QuickDbDriver();
		add_filter(
			'beltoft_services_drivers',
			function ( $drivers ) {
				$drivers['proxmox-db'] = $this->driver;
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
		$this->assertTrue(
			Clusters::save(
				'lab',
				array(
					'label'             => 'Lab cluster',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => self::SECRET,
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'private_bridge'    => 'vmbr2',
					'boot_disk'         => 'scsi0',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => '9100 = PostgreSQL 18',
					'template_families_text' => '9100 = postgres-18',
					'vendor_snippet'    => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
					'db_vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-db-v1.yaml',
					'block_smtp'        => '1',
				)
			)
		);
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		set_transient( \BeltoftServices\Proxmox\Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'node-a', 'status' => 'online', 'maxmem' => 68719476736, 'mem' => 0 ) ), HOUR_IN_SECONDS );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		$this->wipe();
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
	 * @param array<string, mixed> $plan Plan overrides.
	 * @param array<string, mixed> $meta Meta.
	 */
	private function resource( array $plan = array(), array $meta = array() ): Resource {
		$product = $this->service_product(
			'proxmox-db',
			array_merge( array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), $plan ),
			array( 'max' => 3 )
		);
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), $meta );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	private function owner( Resource $r ): int {
		return (int) Resource::find( $r->id() )->license()['user_id'];
	}

	private function run_provision( Resource $r ): StepResult {
		return $this->driver->provision( Resource::find( $r->id() ) );
	}

	private function guest( string $file ): ?string {
		return $this->fake->guest_file( 5000, $file );
	}

	private function status( string $request, string $state, string $step ): void {
		$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $request, 'state' => $state, 'step' => $step, 'updated' => gmdate( 'c' ) ) ) );
	}

	private function credentials( string $request, string $password = self::PASSWORD, array $over = array() ): void {
		$this->fake->set_guest_file( 5000, DbGuest::CREDENTIALS, wp_json_encode( array_merge( array( 'request' => $request, 'password' => $password, 'ca_pem' => self::PEM, 'fingerprint' => self::FP ), $over ) ) );
	}

	/**
	 * Builds up to the point where the init job is on its way.
	 */
	private function built_until_init(): Resource {
		$r      = $this->resource();
		$result = $this->run_provision( $r );
		$this->assertTrue( $result->is_poll(), 'The init job is sent and the build waits: ' . $result->error() );

		return Resource::find( $r->id() );
	}

	private function built(): Resource {
		$r       = $this->built_until_init();
		$request = (string) $r->get( 'db_request' );
		$this->status( $request, 'ready', 'complete' );
		$this->credentials( $request );
		$this->assertTrue( $this->run_provision( $r )->is_poll(), 'Waits for the health file.' );
		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( array( 'updated' => gmdate( 'c' ), 'ok' => true ) ) );
		$done = $this->run_provision( $r );
		$this->assertTrue( $done->is_done(), $done->error() );

		return Resource::find( $r->id() );
	}

	public function test_a_database_server_gets_the_private_only_ipfilter_when_the_cluster_asks(): void {
		$stored                    = get_option( Clusters::OPTION );
		$stored['lab']['fw_ipfilter'] = 1;
		update_option( Clusters::OPTION, $stored );

		$r  = $this->built_until_init();
		$vm = $this->fake->vm( 5000 );

		$this->assertSame( 1, (int) $vm['firewall_options']['ipfilter'] );
		$this->assertSame( array( 'ipfilter-net1' ), array_keys( $vm['firewall_ipsets'] ), 'No public NIC, so no ipfilter-net0.' );
		$this->assertSame( array( (string) PrivateNets::address_for_resource( $r->id() ) ), array_column( $vm['firewall_ipsets']['ipfilter-net1'], 'cidr' ) );
	}

	public function test_a_database_server_gets_no_ipfilter_while_the_cluster_flag_is_off(): void {
		$this->built_until_init();
		$vm = $this->fake->vm( 5000 );

		$this->assertArrayNotHasKey( 'ipfilter', $vm['firewall_options'] );
		$this->assertArrayNotHasKey( 'firewall_ipsets', $vm );
	}

	public function test_database_templates_are_hidden_from_operating_system_lists(): void {
		$this->assertSame( array(), Clusters::templates( 'lab' ), 'A postgres-18 template is never an operating system choice.' );
		$this->assertSame( array( 9100 => 'PostgreSQL 18' ), Clusters::service_templates( 'lab', 'postgres-18' ) );
		$this->assertSame( array(), Clusters::service_templates( 'lab', 'postgres-17' ) );
	}

	public function test_database_build_needs_a_template_of_its_major(): void {
		$r      = $this->resource( array( 'major' => 17 ) );
		$result = $this->run_provision( $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'with the family postgres-17', $result->error() );
	}

	public function test_profile_is_chosen_by_driver_and_vps_is_unchanged(): void {
		$this->assertInstanceOf( DbProfile::class, Profiles::for_resource( $this->resource() ) );
		$this->assertSame( array( 'net', 'vmid', 'clone', 'configure', 'start', 'ready', 'db_init', 'db_ready' ), ( new DbProfile( $this->resource() ) )->steps() );
		$this->assertFalse( ( new DbProfile( $this->resource() ) )->uses_public_ip() );
		$this->assertSame( array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' ), ( new VpsProfile() )->steps() );
	}

	public function test_build_writes_private_nic_data_disk_agent_snippet_and_firewall(): void {
		$r  = $this->built_until_init();
		$vm = $this->fake->vm( 5000 );

		$this->assertSame( array( 'net', 'vmid', 'clone', 'configure', 'start', 'ready' ), $r->get( 'steps' ) );
		$this->assertArrayNotHasKey( 'net0', $vm['config'], 'The template NIC is removed.' );
		$this->assertSame( 'virtio=BC:24:11:13:88:11,bridge=vmbr2,tag=1000,firewall=1', $vm['config']['net1'] );
		$this->assertSame( 'ip=10.64.0.2/28', $vm['config']['ipconfig1'] );
		$this->assertArrayNotHasKey( 'ipconfig0', $vm['config'] );
		$this->assertSame( '1', (string) $vm['config']['agent'] );
		$this->assertSame( 'vendor=local:snippets/vm-vendor-cloudinit-db-v1.yaml', $vm['config']['cicustom'] );
		$this->assertArrayNotHasKey( 'cipassword', $vm['config'] );
		$this->assertMatchesRegularExpression( '#^disk-store:5000/vm-5000-disk-\d\.qcow2,backup=1,size=20G$#', $vm['config']['scsi1'] );
		$this->assertSame( 'disk-store:vm-9100-disk-0,size=10G', $vm['config']['scsi0'], 'The boot disk stays the template\'s.' );
		$this->assertSame( 'running', $vm['status'] );

		$options = $vm['firewall_options'];
		$this->assertSame( 'DROP', $options['policy_in'] );
		$this->assertSame( 'ACCEPT', $options['policy_out'] );
		$this->assertSame( 1, (int) $options['macfilter'] );
		$this->assertSame( 1, (int) $options['enable'] );
		$comments = array_column( $vm['firewall_rules'], 'comment' );
		$this->assertContains( Firewall::PRIVATE_RULE, $comments );
		$this->assertTrue( $r->get( Firewall::LOCKED ) );
		$this->assertSame( '10.64.0.2', $r->get( 'db_address' ) );
		$this->assertSame( '10.64.0.0/28', $r->get( 'db_cidr' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '10.64.0.7', 'net1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '10.64.0.7', 'net0' ) );
	}

	public function test_init_job_is_exact_and_holds_no_secret(): void {
		$r    = $this->built_until_init();
		$job  = $this->guest( DbGuest::JOB );
		$id   = (string) $r->get( 'db_request' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9-]{36}$/', $id );
		$this->assertSame(
			array( 'v' => 1, 'request' => $id, 'op' => 'init', 'db' => 'app', 'role' => 'app_user', 'listen' => array( '10.64.0.2' ), 'private_cidr' => '10.64.0.0/28', 'allow' => array(), 'world' => false, 'max_connections' => 200 ),
			json_decode( (string) $job, true )
		);
		$this->assertStringStartsWith( '{"v":1,"request":"' . $id . '","op":"init","db":"app","role":"app_user","listen":["10.64.0.2"],', (string) $job, 'Key order is the contract\'s.' );
		$this->assertSame( 'installing', $r->get( 'db_state' ) );
	}

	public function test_max_connections_by_memory_and_filter(): void {
		$this->assertSame( 100, DbProfile::max_connections( $this->resource( array( 'memory_mb' => 2048 ) ) ) );
		$this->assertSame( 200, DbProfile::max_connections( $this->resource( array( 'memory_mb' => 4096 ) ) ) );
		$this->assertSame( 300, DbProfile::max_connections( $this->resource( array( 'memory_mb' => 8192 ) ) ) );
		add_filter( 'beltoft_services_db_max_connections', fn() => 150 );
		$this->assertSame( 150, DbProfile::max_connections( $this->resource() ) );
		remove_all_filters( 'beltoft_services_db_max_connections' );
	}

	public function test_status_of_another_request_is_ignored_and_steps_are_validated(): void {
		$r       = $this->built_until_init();
		$request = (string) $r->get( 'db_request' );

		$this->status( wp_generate_uuid4(), 'ready', 'complete' );
		$this->credentials( $request );
		$this->assertTrue( $this->run_provision( $r )->is_poll() );
		$this->assertSame( 'installing', Resource::find( $r->id() )->get( 'db_state' ) );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( DbDriverReveal::KEY ) );

		$this->status( $request, 'ready', 'not-a-step' );
		$this->assertTrue( $this->run_provision( $r )->is_poll() );
		$this->status( $request, 'busy', 'disk' );
		$this->assertTrue( $this->run_provision( $r )->is_poll() );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( DbDriverReveal::KEY ), 'Nothing was taken from an unvalidated status.' );

		$this->status( $request, 'working', 'tls' );
		$poll = $this->run_provision( $r );
		$this->assertTrue( $poll->is_poll() );
		$this->assertSame( 'tls', Resource::find( $r->id() )->get( 'db_step' ) );
	}

	public function test_credentials_are_stored_encrypted_the_guest_file_is_emptied_and_the_reveal_is_once(): void {
		$r = $this->built();

		$this->assertSame( '{}', $this->guest( DbGuest::CREDENTIALS ) );
		$meta = wp_json_encode( Resource::find( $r->id() )->license() ) . wp_json_encode( $this->fake->calls() ) . wp_json_encode( $this->fake->state() );
		$this->assertStringNotContainsString( self::PASSWORD, wp_json_encode( array( $r->get( 'db_reveal' ), $r->get( 'db_ca_pem' ) ) ) );
		$this->assertStringNotContainsString( self::PASSWORD, (string) wp_json_encode( array_filter( $this->fake->calls(), static fn( $c ) => str_contains( $c['path'], 'file-write' ) ) ) );
		unset( $meta );
		$this->assertSame( self::PASSWORD, Crypto::decrypt( (string) $r->get( 'db_reveal' ) ) );
		$this->assertSame( self::PEM, $r->get( 'db_ca_pem' ) );
		$this->assertSame( self::FP, $r->get( 'db_fingerprint' ) );
		$this->assertGreaterThan( time() + 6 * DAY_IN_SECONDS, (int) $r->get( 'db_reveal_until' ) );
		$this->assertSame( array( 'net', 'vmid', 'clone', 'configure', 'start', 'ready', 'db_init', 'db_ready' ), $r->get( 'steps' ) );

		$owner = $this->owner( $r );
		$this->assertNull( DbRoutes::take( Resource::find( $r->id() ), $owner + 1000 ), 'Another user gets nothing.' );
		$this->assertNull( DbRoutes::take( Resource::find( $r->id() ), 0 ) );
		$this->assertSame( self::PASSWORD, DbRoutes::take( Resource::find( $r->id() ), $owner ) );
		$this->assertNull( DbRoutes::take( Resource::find( $r->id() ), $owner ), 'Second time: gone.' );
	}

	public function test_credentials_that_do_not_match_the_contract_are_refused(): void {
		$bad = array(
			array( 'password' => 'short' ),
			array( 'password' => 'Abcdefghijklmnopqrstuvwxyz01234!' ),
			array( 'ca_pem' => 'not a certificate' ),
			array( 'ca_pem' => self::PEM . self::PEM ),
			array( 'fingerprint' => 'aa:bb' ),
			array( 'request' => wp_generate_uuid4() ),
		);

		foreach ( $bad as $over ) {
			$r       = $this->built_until_init();
			$request = (string) $r->get( 'db_request' );
			$this->status( $request, 'ready', 'complete' );
			$this->credentials( $request, self::PASSWORD, $over );
			$this->assertTrue( $this->run_provision( $r )->is_poll() );
			$fresh = Resource::find( $r->id() );
			$this->assertSame( '', (string) $fresh->get( 'db_reveal' ), wp_json_encode( $over ) );
			$this->assertNotContains( 'db_init', (array) $fresh->get( 'steps' ) );

			// Ten minutes later it is a failure.
			$fresh->set( array( 'db_ready_since' => time() - 700 ) );
			$this->assertTrue( $this->run_provision( $r )->is_failed() );
			$this->wipe();
			$this->fake = new FakeClient();
			$this->fake->add_node( 'node-a' );
			$this->fake->add_vm( array( 'vmid' => 9100, 'node' => 'node-a', 'name' => 'postgres-template', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9100-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'memory' => 512, 'cores' => 1 ) ) );
			PrivateNets::add( 1000, '10.64.0.0/28' );
		}
	}

	public function test_db_ready_waits_for_health_and_fails_after_ten_minutes(): void {
		$r       = $this->built_until_init();
		$request = (string) $r->get( 'db_request' );
		$this->status( $request, 'ready', 'complete' );
		$this->credentials( $request );

		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( array( 'updated' => gmdate( 'c' ), 'ok' => false ) ) );
		$this->assertTrue( $this->run_provision( $r )->is_poll() );
		$this->assertContains( 'db_init', (array) Resource::find( $r->id() )->get( 'steps' ) );
		$this->assertNotContains( 'db_ready', (array) Resource::find( $r->id() )->get( 'steps' ) );

		Resource::find( $r->id() )->set( array( 'db_ready_check_since' => time() - 700 ) );
		$this->assertTrue( $this->run_provision( $r )->is_failed() );
	}

	public function test_failed_guest_fails_with_a_fixed_text_and_a_retry_starts_a_new_job(): void {
		$r       = $this->built_until_init();
		$request = (string) $r->get( 'db_request' );
		$this->status( $request, 'failed', 'tls' );
		$result = $this->run_provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'The database server reported a failure at step tls', $result->error() );

		$again = $this->run_provision( $r );
		$this->assertTrue( $again->is_poll() );
		$this->assertNotSame( $request, (string) Resource::find( $r->id() )->get( 'db_request' ) );
		$this->assertStringContainsString( '"op":"init"', (string) $this->guest( DbGuest::JOB ) );
	}

	public function test_no_free_private_network_fails_clearly(): void {
		$this->wipe();
		$r      = $this->resource();
		$result = $this->run_provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'No private network available', $result->error() );
		$this->assertNull( VmRecords::for_resource( $r->id() ), 'Nothing was built.' );
	}

	public function test_public_ip_is_refused_in_phase_three(): void {
		$r      = $this->resource( array( 'public_ip' => 1 ) );
		$result = $this->run_provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'public IP', $result->error() );
		$this->assertNull( VmRecords::for_resource( $r->id() ) );
	}

	public function test_cluster_without_a_private_bridge_fails(): void {
		$all                         = get_option( Clusters::OPTION );
		$this->assertIsArray( $all );
		$all['lab']['private_bridge'] = '';
		update_option( Clusters::OPTION, $all );
		$result = $this->run_provision( $this->resource() );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'private network bridge', $result->error() );
	}

	public function test_reset_password_writes_the_job_and_reveals_the_new_password(): void {
		$r = $this->built();
		$r->set( array( 'state' => 'ready' ) );
		DbRoutes::take( Resource::find( $r->id() ), $this->owner( $r ) );

		$this->assertSame( array( DbActions::RESET => 'New password' ), $this->driver->customer_actions( Resource::find( $r->id() ) ) );
		$first = $this->driver->act( Resource::find( $r->id() ), DbActions::RESET, array( 'confirm' => DbActions::CONFIRM ) );
		$this->assertTrue( $first->is_poll() );
		$request = (string) Resource::find( $r->id() )->get( 'db_reset_request' );
		$this->assertSame( '{"v":1,"request":"' . $request . '","op":"reset_password"}', $this->guest( DbGuest::JOB ) );

		$this->assertTrue( $this->driver->act( Resource::find( $r->id() ), DbActions::RESET, array() )->is_poll(), 'Waiting for the guest.' );
		$this->status( $request, 'ready', 'complete' );
		$this->credentials( $request, self::NEW_PASS );
		$done = $this->driver->act( Resource::find( $r->id() ), DbActions::RESET, array() );
		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertSame( '{}', $this->guest( DbGuest::CREDENTIALS ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'db_reset_request' ) );
		$this->assertSame( self::NEW_PASS, DbRoutes::take( Resource::find( $r->id() ), $this->owner( $r ) ) );
		$this->assertNull( DbRoutes::take( Resource::find( $r->id() ), $this->owner( $r ) ) );
	}

	public function test_reset_password_needs_the_typed_words_and_a_ready_database(): void {
		$r = $this->built();
		$r->set( array( 'state' => 'ready' ) );
		$fresh = Resource::find( $r->id() );

		$refusal = DbActions::refusal( null, $fresh, DbActions::RESET, array( 'confirm' => 'yes' ) );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertNull( DbActions::refusal( null, $fresh, DbActions::RESET, array( 'confirm' => DbActions::CONFIRM ) ) );
		$this->assertNull( DbActions::refusal( null, $fresh, 'start', array() ) );
		$this->assertArrayNotHasKey( DbActions::RESET, DbActions::buttons( array( DbActions::RESET => 'x' ), $fresh ) );

		$fresh->set( array( 'db_state' => 'installing' ) );
		$this->assertSame( array(), DbActions::offered( Resource::find( $r->id() ) ) );
		$this->assertTrue( $this->driver->act( Resource::find( $r->id() ), 'start', array() )->is_failed() );
	}

	private function ready_card_resource(): Resource {
		$r = $this->resource( array(), array(
			'state'          => 'ready',
			'desired'        => 'ready',
			'db_state'       => 'ready',
			'db_address'     => '10.64.0.2',
			'db_ca_pem'      => self::PEM,
			'db_fingerprint' => self::FP,
			'db_reveal'      => Crypto::encrypt( self::PASSWORD ),
			'db_reveal_until' => time() + DAY_IN_SECONDS,
		) );

		return Resource::find( $r->id() );
	}

	public function test_card_shows_facts_and_never_the_password(): void {
		$r = $this->ready_card_resource();
		ob_start();
		$this->driver->render_account( $r );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'postgresql://app_user:PASSWORD@10.64.0.2:5432/app?sslmode=verify-full&amp;sslrootcert=ca.crt', $html );
		$this->assertStringContainsString( 'postgresql://app_user:PASSWORD@10.64.0.2:5432/app?sslmode=require', $html );
		$this->assertStringContainsString( 'Reachable from your servers on the private network.', $html );
		$this->assertStringContainsString( 'Download CA certificate', $html );
		$this->assertStringContainsString( self::FP, $html );
		$this->assertStringContainsString( 'data-db-reveal', $html );
		$this->assertStringContainsString( 'PostgreSQL 18', $html );
		$this->assertStringNotContainsString( self::PASSWORD, $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		DbRoutes::take( Resource::find( $r->id() ), $this->owner( $r ) );
		ob_start();
		$this->driver->render_account( Resource::find( $r->id() ) );
		$after = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-db-reveal', $after );
		$this->assertStringContainsString( 'data-db-reset', $after );
	}

	public function test_card_of_a_database_still_being_built_has_no_connection(): void {
		$r = $this->resource();
		ob_start();
		$this->driver->render_account( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'being set up', $html );
		$this->assertStringNotContainsString( 'postgresql://', $html );
		$this->assertSame( 'building', DbCard::view( $r )['kind'] );
	}

	public function test_ca_route_is_for_the_owner_only_and_serves_a_pem_file(): void {
		$r     = $this->ready_card_resource();
		$owner = $this->owner( $r );
		$path  = '/beltoft-services/v1/resources/' . $r->id() . '/db-ca';

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, rest_get_server()->dispatch( new \WP_REST_Request( 'GET', $path ) )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new \WP_REST_Request( 'GET', $path ) )->get_status() );

		wp_set_current_user( $owner );
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', $path ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::PEM, $response->get_data() );
		$headers = $response->get_headers();
		$this->assertSame( 'application/x-pem-file', $headers['Content-Type'] );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'ca.crt', $headers['Content-Disposition'] );
	}

	public function test_reveal_route_is_for_the_owner_only_and_once(): void {
		$r     = $this->ready_card_resource();
		$owner = $this->owner( $r );
		$path  = '/beltoft-services/v1/resources/' . $r->id() . '/db-reveal';

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $path ) )->get_status() );

		wp_set_current_user( $owner );
		$first = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $path ) );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'password' => self::PASSWORD ), $first->get_data() );
		$this->assertSame( 'no-store', $first->get_headers()['Cache-Control'] );
		$this->assertSame( 409, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', $path ) )->get_status() );
	}

	public function test_the_reveal_expires_after_seven_days(): void {
		$r = $this->ready_card_resource();
		$r->set( array( 'db_reveal_until' => time() - 1 ) );
		$this->assertNull( DbRoutes::take( Resource::find( $r->id() ), $this->owner( $r ) ) );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'db_reveal' ) );
	}

	public function test_cluster_settings_validate_the_database_snippet_and_accept_the_postgres_family(): void {
		$c = Clusters::get( 'lab' );
		$this->assertSame( 'local:snippets/vm-vendor-cloudinit-db-v1.yaml', $c['db_vendor_snippet'] );
		$this->assertSame( 'postgres-18', $c['template_families'][9100] );

		$bad = Clusters::save( 'lab', array_merge( $c, array( 'token_secret' => '', 'nodes' => 'node-a', 'nameservers' => '192.0.2.53', 'templates' => '9100 = PostgreSQL 18', 'db_vendor_snippet' => 'local:snippets/../evil.yaml' ) ) );
		$this->assertTrue( is_wp_error( $bad ) );
		$this->assertSame( 'local:snippets/vm-vendor-cloudinit-db-v1.yaml', Clusters::get( 'lab' )['db_vendor_snippet'] );
	}
}

/**
 * Names the meta key the tests look at.
 */
final class DbDriverReveal {
	public const KEY = 'db_reveal';
}

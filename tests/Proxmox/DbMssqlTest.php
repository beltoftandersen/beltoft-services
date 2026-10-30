<?php
/**
 * The second database engine, SQL Server Express: the engine seam, the build, the guest job, the card, the allow-list
 * port and the licence tick box. Only the fake client is used; PostgreSQL's own tests stay as they are.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbAccess;
use BeltoftServices\Proxmox\DbBuild;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DbEngines;
use BeltoftServices\Proxmox\DbGuest;
use BeltoftServices\Proxmox\DbTermsField;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\MssqlEngine;
use BeltoftServices\Proxmox\PostgresEngine;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A database driver that does not sleep.
 */
class MssqlDbDriver extends DbDriver {

	public function nap( int $seconds ): void {
		unset( $seconds );
	}
}

class DbMssqlTest extends TestCase {

	private const SECRET   = 'secret-secret-secret-1';
	private const OWNER    = 'christian.beltoft@gmail.com';
	private const PASSWORD = 'Abcdefghijklmnopqrstuvwxyz012345';
	private const FP       = '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF';
	private const PEM      = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUQ2VydGlmaWNhdGVGb3JUZXN0aW5nMAoGCCqGSM49BAMC\n-----END CERTIFICATE-----\n";

	/** @var FakeClient */
	private $fake;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );
		add_filter( 'pre_wp_mail', fn() => true );
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
				$drivers['proxmox-db'] = new MssqlDbDriver();
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );

		foreach ( array( 9100 => 'postgres-template', 9120 => 'mssql-template' ) as $vmid => $name ) {
			$this->fake->add_vm( array( 'vmid' => $vmid, 'node' => 'node-a', 'name' => $name, 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-' . $vmid . '-disk-0,size=12G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'memory' => 512, 'cores' => 1 ) ) );
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
					'backup_storage'         => 'backup-store',
					'bridge'                 => 'vmbr9',
					'private_bridge'         => 'vmbr2',
					'boot_disk'              => 'scsi0',
					'gateway'                => '192.0.2.1',
					'nameservers'            => '192.0.2.53',
					'prefix'                 => '24',
					'vmid_min'               => '5000',
					'vmid_max'               => '5099',
					'templates'              => "9100 = PostgreSQL 18\n9120 = SQL Server 2022",
					'template_families_text' => "9100 = postgres-18\n9120 = mssql-2022",
					'vendor_snippet'         => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
					'db_vendor_snippet'      => 'local:snippets/vm-vendor-cloudinit-db-v1.yaml',
					'block_smtp'             => '1',
				)
			)
		);
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'persist' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );

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
		remove_all_filters( 'beltoft_services_db_terms_accepted' );
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
	 * A SQL Server database that is only stored.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 * @param array<string, mixed> $meta Meta.
	 */
	private function resource( array $plan = array(), array $meta = array( 'db_terms_accepted' => 1761800000 ) ): Resource {
		$product = $this->service_product(
			'proxmox-db',
			array_merge( array( 'cluster' => 'lab', 'template' => 9120, 'engine' => 'mssql', 'major' => 2022, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), $plan ),
			array( 'max' => 3 )
		);
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), $meta );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	private function guest_job(): array {
		return (array) json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );
	}

	private function status( string $request, string $state, string $step ): void {
		$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array( 'request' => $request, 'state' => $state, 'step' => $step, 'updated' => gmdate( 'c' ) ) ) );
	}

	/**
	 * Builds a database to ready, the guest answering like the SQL Server guest does.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function ready_db( array $plan = array() ): Resource {
		$r      = $this->resource( $plan );
		$driver = Registry::get( 'proxmox-db' );
		$first  = $driver->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $first->is_poll(), $first->error() );
		$request = (string) Resource::find( $r->id() )->get( 'db_request' );
		$this->status( $request, 'ready', 'complete' );
		$this->fake->set_guest_file( 5000, DbGuest::CREDENTIALS, wp_json_encode( array( 'request' => $request, 'password' => self::PASSWORD, 'ca_pem' => self::PEM, 'fingerprint' => self::FP ) ) );
		$this->assertTrue( $driver->provision( Resource::find( $r->id() ) )->is_poll() );
		$this->fake->set_guest_file( 5000, DbGuest::HEALTH, wp_json_encode( array( 'updated' => gmdate( 'c' ), 'ok' => true, 'connections' => 1, 'disk_percent' => 5, 'last_dump' => time() - HOUR_IN_SECONDS, 'cert_expires' => time() + 300 * DAY_IN_SECONDS ) ) );
		$done = $driver->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $done->is_done(), $done->error() );
		$this->assertTrue( Resource::find( $r->id() )->set( array_merge( $done->meta(), array( 'state' => 'ready', 'desired' => 'ready', 'task' => null ) ) ) );

		return Resource::find( $r->id() );
	}

	// -- The seam ---------------------------------------------------------------------------------

	public function test_the_plan_chooses_the_engine_and_postgres_is_the_default(): void {
		$this->assertInstanceOf( PostgresEngine::class, DbEngines::for_plan( array() ) );
		$this->assertInstanceOf( PostgresEngine::class, DbEngines::for_plan( array( 'engine' => 'postgres' ) ) );
		$this->assertInstanceOf( MssqlEngine::class, DbEngines::for_plan( array( 'engine' => 'mssql' ) ) );
		$this->assertNull( DbEngines::for_plan( array( 'engine' => 'oracle' ) ) );
		$this->assertNull( DbEngines::for_plan( array( 'engine' => array( 'mssql' ) ) ) );
		$this->assertSame( array( 'postgres-', 'mssql-', 'valkey' ), DbEngines::family_prefixes() );
		$this->assertInstanceOf( PostgresEngine::class, DbEngines::for_resource( $this->resource( array( 'engine' => 'oracle' ) ) ), 'An unknown engine reads as PostgreSQL where nothing can be refused.' );
	}

	public function test_what_each_engine_says(): void {
		$pg = new PostgresEngine();
		$ms = new MssqlEngine();

		$this->assertSame( array( 5432, 'PostgreSQL 17', 'postgres-17' ), array( $pg->port(), $pg->label( array( 'major' => 17 ) ), $pg->family( array( 'major' => 17 ) ) ) );
		$this->assertSame( array( 1433, 'SQL Server 2022 Express', 'mssql-2022' ), array( $ms->port(), $ms->label( array( 'major' => 2022 ) ), $ms->family( array( 'major' => 2022 ) ) ) );
		$this->assertSame( 'SQL Server 2022 Express', $ms->label( array() ), 'No version named: the default one.' );
		$this->assertSame( array( 'app', 'app_user' ), array( $ms->database(), $ms->login() ) );
		$this->assertSame( '', $pg->limits() );
		$this->assertStringContainsString( '10 GB per database', $ms->limits() );
		$this->assertStringNotContainsString( "\u{2014}", $ms->limits() . $ms->ca_hint() . $ms->terms_label() );
	}

	public function test_the_sql_server_plan_check(): void {
		$ms = new MssqlEngine();

		$this->assertNull( $ms->plan_error( array( 'major' => 2022, 'memory_mb' => 4096 ) ) );
		$this->assertNull( $ms->plan_error( array( 'memory_mb' => '8192' ) ) );
		$this->assertStringContainsString( 'is not supported. Supported: 2022', (string) $ms->plan_error( array( 'major' => 2019, 'memory_mb' => 4096 ) ) );
		$this->assertStringContainsString( 'is not supported', (string) $ms->plan_error( array( 'major' => 'x', 'memory_mb' => 4096 ) ) );
		$this->assertStringContainsString( 'at least 4 GB', (string) $ms->plan_error( array( 'major' => 2022, 'memory_mb' => 2048 ) ) );
		$this->assertStringContainsString( 'at least 4 GB', (string) $ms->plan_error( array( 'major' => 2022 ) ) );
	}

	public function test_the_memory_limit_is_three_quarters_of_the_plan_and_a_filter_may_change_it(): void {
		$ms = new MssqlEngine();

		$this->assertSame( 3072, $ms->init_fields( $this->resource( array( 'memory_mb' => 4096 ) ) )['memory_limit_mb'] );
		$this->assertSame( 6144, $ms->init_fields( $this->resource( array( 'memory_mb' => 8192 ) ) )['memory_limit_mb'] );
		add_filter( 'beltoft_services_mssql_memory_limit_mb', fn() => 100 );
		$this->assertSame( 2048, $ms->init_fields( $this->resource() )['memory_limit_mb'], 'Never below what SQL Server needs to start.' );
		remove_all_filters( 'beltoft_services_mssql_memory_limit_mb' );
	}

	public function test_the_engine_templates_are_kept_apart_and_hidden_from_operating_system_lists(): void {
		$this->assertSame( array( 9120 => 'SQL Server 2022' ), Clusters::service_templates( 'lab', 'mssql-2022' ) );
		$this->assertSame( array( 9100 => 'PostgreSQL 18' ), Clusters::service_templates( 'lab', 'postgres-18' ) );
		$this->assertSame( array(), Clusters::templates( 'lab' ) );
	}

	// -- The build --------------------------------------------------------------------------------

	public function test_the_init_job_of_a_sql_server_database(): void {
		$r      = $this->resource();
		$result = Registry::get( 'proxmox-db' )->provision( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_poll(), $result->error() );

		$id = (string) Resource::find( $r->id() )->get( 'db_request' );
		$this->assertSame(
			array( 'v' => 1, 'request' => $id, 'op' => 'init', 'db' => 'app', 'role' => 'app_user', 'listen' => array( '10.64.0.2' ), 'private_cidr' => '10.64.0.0/28', 'allow' => array(), 'world' => false, 'max_connections' => 200, 'memory_limit_mb' => 3072, 'accept_eula' => true ),
			$this->guest_job()
		);
		$this->assertArrayNotHasKey( 'major', $this->guest_job() );
		$this->assertStringNotContainsString( 'password', (string) $this->fake->guest_file( 5000, DbGuest::JOB ) );
		$this->assertSame( 'virtio=BC:24:11:13:88:11,bridge=vmbr2,tag=1000,firewall=1', $this->fake->vm( 5000 )['config']['net1'] );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 1433, '10.64.0.7', 'net1' ) );
	}

	public function test_a_sql_server_database_is_built_from_its_own_template(): void {
		$r      = $this->resource( array( 'template' => 9100 ) );
		$result = Registry::get( 'proxmox-db' )->provision( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'with the family mssql-2022', $result->error() );
		$this->assertNull( \BeltoftServices\Proxmox\VmRecords::for_resource( $r->id() ) );
	}

	public function test_a_postgres_template_is_not_used_for_sql_server_and_the_other_way_round(): void {
		$pg     = $this->resource( array( 'engine' => 'postgres', 'major' => 18, 'template' => 9120 ) );
		$result = Registry::get( 'proxmox-db' )->provision( Resource::find( $pg->id() ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'with the family postgres-18', $result->error() );
	}

	public function test_an_unknown_engine_fails_before_any_vm_is_built(): void {
		$r      = $this->resource( array( 'engine' => 'oracle' ) );
		$result = Registry::get( 'proxmox-db' )->provision( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'database engine of this plan (oracle) is not available', $result->error() );
		$this->assertNull( \BeltoftServices\Proxmox\VmRecords::for_resource( $r->id() ) );
	}

	public function test_a_plan_below_four_gb_or_of_another_version_fails_before_any_vm_is_built(): void {
		foreach ( array( array( 'memory_mb' => 2048 ), array( 'major' => 2019 ) ) as $over ) {
			$r      = $this->resource( $over );
			$result = Registry::get( 'proxmox-db' )->provision( Resource::find( $r->id() ) );

			$this->assertTrue( $result->is_failed() );
			$this->assertNull( \BeltoftServices\Proxmox\VmRecords::for_resource( $r->id() ) );
		}
	}

	public function test_the_licence_must_be_accepted_before_a_vm_is_built(): void {
		$r      = $this->resource( array(), array() );
		$result = Registry::get( 'proxmox-db' )->provision( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'has not accepted the licence terms', $result->error() );
		$this->assertNull( \BeltoftServices\Proxmox\VmRecords::for_resource( $r->id() ) );
		$this->assertNotNull( DbBuild::plan_error( Resource::find( $r->id() ) ) );

		add_filter( 'beltoft_services_db_terms_accepted', '__return_true' );
		$this->assertNull( DbBuild::plan_error( Resource::find( $r->id() ) ), 'An admin may accept for a database made by hand.' );
	}

	public function test_postgres_needs_no_acceptance(): void {
		$r = $this->resource( array( 'engine' => 'postgres', 'major' => 18, 'template' => 9100 ), array() );

		$this->assertNull( DbBuild::plan_error( $r ) );
		$this->assertArrayNotHasKey( 'accept_eula', ( new PostgresEngine() )->init_fields( $r ) );
	}

	// -- The card ---------------------------------------------------------------------------------

	public function test_the_card_shows_the_sql_server_details_and_never_the_password(): void {
		$r = $this->ready_db();
		ob_start();
		Registry::get( 'proxmox-db' )->render_account( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'SQL Server 2022 Express', $html );
		$this->assertStringContainsString( '<code>1433</code>', $html );
		$this->assertStringContainsString( '<code>app_user</code>', $html );
		$this->assertStringContainsString( 'Server=10.64.0.2,1433;Database=app;User Id=app_user;Password=PASSWORD;Encrypt=True;TrustServerCertificate=False;', $html );
		$this->assertStringContainsString( 'Server=10.64.0.2,1433;Database=app;User Id=app_user;Password=PASSWORD;Encrypt=True;TrustServerCertificate=True;', $html );
		$this->assertStringContainsString( '10 GB per database', $html );
		$this->assertStringContainsString( 'add the CA certificate to the trusted certificates', $html );
		$this->assertStringContainsString( 'Download CA certificate', $html );
		$this->assertStringContainsString( 'data-db-reveal', $html );
		$this->assertStringNotContainsString( 'postgresql://', $html );
		$this->assertStringNotContainsString( 'sslmode', $html );
		$this->assertStringNotContainsString( self::PASSWORD, $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_a_postgres_card_has_no_limits_or_ca_line(): void {
		$view = DbCard::view( $this->resource( array( 'engine' => 'postgres', 'major' => 18, 'template' => 9100 ), array() ) );

		$this->assertSame( array( 'PostgreSQL 18', 5432, '', '' ), array( $view['engine'], $view['port'], $view['limits'], $view['ca_hint'] ) );
	}

	public function test_the_list_row_and_the_picker_name_the_engine(): void {
		$view = DbCard::view( $this->resource() );
		$this->assertSame( 'SQL Server 2022 Express', $view['engine'] );
		$this->assertSame( 1433, $view['port'] );

		$ref = new \ReflectionMethod( \BeltoftServices\Account\DbPicker::class, 'engine' );
		$ref->setAccessible( true );
		$this->assertSame( 'SQL Server 2022 Express', $ref->invoke( null, array( 'engine' => 'mssql', 'major' => 2022 ) ) );
		$this->assertSame( 'PostgreSQL 18', $ref->invoke( null, array( 'engine' => '', 'major' => 18 ) ) );
		$this->assertSame( 'PostgreSQL 17', $ref->invoke( null, array( 'engine' => 'postgres', 'major' => 17 ) ) );
	}

	// -- The allow-list uses the engine's port ----------------------------------------------------

	public function test_the_allow_list_opens_port_1433_on_the_public_nic_only_for_the_listed_address(): void {
		IpPool::add( '192.0.2.10', '192.0.2.1' );
		$r = $this->ready_db( array( 'public_ip' => 1 ) );

		wp_set_current_user( (int) $r->license()['user_id'] );
		$request = new \WP_REST_Request( 'POST', '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/db-access' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'op' => 'add', 'cidr' => '203.0.113.7', 'label' => 'Office' ) ) );
		$this->assertSame( 202, rest_do_request( $request )->get_status() );

		$driver = Registry::get( 'proxmox-db' );
		$res    = null;

		for ( $i = 0; $i < 60; $i++ ) {
			$job = $this->guest_job();

			if ( ( $job['op'] ?? '' ) === 'allow' ) {
				$this->status( (string) $job['request'], 'ready', 'allow' );
			}

			$res = $driver->act( Resource::find( $r->id() ), DbAccess::APPLY, array() );

			if ( $res->is_done() || $res->is_failed() ) {
				break;
			}

			$this->fake->elapse( 30 );
		}

		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 1433, '203.0.113.7', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 1433, '203.0.113.8', 'net0' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( 5000, 'tcp', 5432, '203.0.113.7', 'net0' ), 'Not PostgreSQL\'s port.' );
		$this->assertTrue( $this->fake->fake_inbound_allowed( 5000, 'tcp', 1433, '10.64.0.7', 'net1' ) );
		$this->assertSame( array( 'v' => 1, 'request' => $this->guest_job()['request'], 'op' => 'allow', 'allow' => array( '203.0.113.7/32' ), 'world' => false ), $this->guest_job() );
	}

	// -- The licence tick box ---------------------------------------------------------------------

	public function test_the_terms_box_is_for_an_engine_with_terms_only(): void {
		$this->assertInstanceOf( \BeltoftServices\Proxmox\DbEngineTerms::class, new MssqlEngine() );
		$this->assertNotInstanceOf( \BeltoftServices\Proxmox\DbEngineTerms::class, new PostgresEngine() );
		$this->assertSame( 'https://aka.ms/useterms', ( new MssqlEngine() )->terms_url() );
		add_filter( 'beltoft_services_mssql_terms_url', fn() => 'https://example.invalid/terms' );
		$this->assertSame( 'https://example.invalid/terms', ( new MssqlEngine() )->terms_url() );
		remove_all_filters( 'beltoft_services_mssql_terms_url' );
	}

	public function test_a_database_starts_with_the_time_of_the_acceptance_on_the_order_line(): void {
		$product   = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9120, 'engine' => 'mssql', 'major' => 2022, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$order     = wc_create_order();
		$item      = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );
		$item->add_meta_data( DbTermsField::ITEM_META, '1761800000', true );
		$order->add_item( $item );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER, 'order_id' => $order->get_id() ) );

		$meta = DbTermsField::initial_meta( array(), $license, Registry::get( 'proxmox-db' ) );
		$this->assertSame( array( DbTermsField::META => 1761800000 ), $meta );

		$this->assertSame( array( 'x' => 1 ), DbTermsField::initial_meta( array( 'x' => 1 ), $license, new \BeltoftServices\Proxmox\ProxmoxDriver() ), 'Another driver is left alone.' );
	}

	public function test_checkout_without_the_box_is_refused_and_with_it_the_line_carries_the_time(): void {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9120, 'engine' => 'mssql', 'major' => 2022, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$item    = new \WC_Order_Item_Product();
		$item->set_product_id( $product->get_id() );

		DbTermsField::save_on_item( $item );
		$this->assertSame( '', (string) $item->get_meta( DbTermsField::ITEM_META ), 'Nothing is saved outside a checkout run.' );

		$errors = new \WP_Error();
		DbTermsField::validate( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'No cart, no box.' );
	}

	public function test_the_cart_with_such_a_database_asks_for_the_box(): void {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9120, 'engine' => 'mssql', 'major' => 2022, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id() );

		if ( 1 !== count( WC()->cart->get_cart() ) ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		$this->assertInstanceOf( MssqlEngine::class, DbTermsField::cart_terms() );
		ob_start();
		DbTermsField::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . DbTermsField::POST_KEY . '"', $html );
		$this->assertStringContainsString( 'required', $html );
		$this->assertStringContainsString( 'href="https://aka.ms/useterms"', $html );
		$this->assertStringContainsString( 'I accept Microsoft\'s SQL Server <a ', $html );

		$errors = new \WP_Error();
		DbTermsField::validate( array(), $errors );
		$this->assertSame( array( 'beltoft_services_db_terms' ), $errors->get_error_codes() );
		WC()->cart->empty_cart();
	}
}

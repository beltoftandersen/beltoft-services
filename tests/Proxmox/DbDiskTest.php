<?php
/**
 * Extra storage of a managed database: the ladder, the grow operation, the plan update and the dashboard. Only the fake client is used; mail goes to the owner's address.
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
use BeltoftServices\Account\DbPicker;
use BeltoftServices\Proxmox\DbDisk;
use BeltoftServices\Proxmox\DbGrow;
use BeltoftServices\Proxmox\DiskCapacity;

/**
 * A database driver that does not sleep.
 */
class DiskDbDriver extends DbDriver {

	public function nap( int $seconds ): void {
		unset( $seconds );
	}
}

class DbDiskTest extends TestCase {

	private const SECRET = 'secret-secret-secret-1';
	private const OWNER  = 'christian.beltoft@gmail.com';

	/** @var FakeClient */
	private $fake;

	/** @var string How the guest treats the jobs: new, old (refuses the grow job), silent, or small (fails the grow job). */
	private $guest = 'new';

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
				$drivers['proxmox-db'] = new DiskDbDriver();
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

	// -- Helpers ---------------------------------------------------------------------------------

	/** @var array<string, \WC_Product_Variation>|null */
	private $ladder_cache = null;

	private function pool( int $count = 2, int $first = 10 ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			IpPool::add( '192.0.2.' . ( $first + $i ), '192.0.2.1' );
		}
	}

	/**
	 * A database product with the ladder: extra storage None, 50, 100 and 250 GB, each with and without the public IP.
	 *
	 * @return array<string, \WC_Product_Variation> Keys such as e0-0, e50-0, e50-1.
	 */
	private function ladder_variations(): array {
		if ( null !== $this->ladder_cache ) {
			return $this->ladder_cache;
		}

		$product = new \WC_Product_Variable();
		$product->set_name( 'Database ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$storage = new \WC_Product_Attribute();
		$storage->set_name( 'Extra storage' );
		$storage->set_options( array( 'None', '50 GB', '100 GB', '250 GB' ) );
		$storage->set_visible( true );
		$storage->set_variation( true );

		$ip = new \WC_Product_Attribute();
		$ip->set_name( 'Public IP' );
		$ip->set_options( array( 'No', 'Yes' ) );
		$ip->set_visible( true );
		$ip->set_variation( true );

		$product->set_attributes( array( $storage, $ip ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20 ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 0 => array( 'None', 12 ), 50 => array( '50 GB', 16 ), 100 => array( '100 GB', 20 ), 250 => array( '250 GB', 30 ) ) as $extra => $set ) {
			foreach ( array( 0, 1 ) as $with ) {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'extra-storage' => $set[0], 'public-ip' => $with ? 'Yes' : 'No' ) );
				$v->set_regular_price( (string) ( $set[1] + ( $with ? 4 : 0 ) ) );
				$v->set_status( 'publish' );
				$v->update_meta_data( Product::META_PLAN . 'public_ip', (string) $with );
				$v->update_meta_data( Product::META_PLAN . 'extra_disk_gb', (string) $extra );
				$v->save();
				$out[ 'e' . $extra . '-' . $with ] = $v;
			}
		}

		$this->ladder_cache = $out;

		return $out;
	}

	/**
	 * A database on the variation of the ladder; built for real (with the fake cluster) or only stored.
	 *
	 * @param string $key   Variation key such as e50-0.
	 * @param bool   $built Whether the VM is built.
	 */
	private function db_on_ladder( string $key, bool $built = true ): Resource {
		$v      = $this->ladder_variations();
		$parent = wc_get_product( $v[ $key ]->get_parent_id() );
		$user   = get_user_by( 'email', self::OWNER );
		$owner  = $user ? (int) $user->ID : (int) self::factory()->user->create( array( 'user_email' => self::OWNER ) );
		$sub    = wcs_create_subscription( array( 'customer_id' => $owner, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->add_product( $v[ $key ], 1 );
		$sub->save();

		$license = $this->service_license( $parent, array( 'product_slug' => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ), 'variation_id' => $v[ $key ]->get_id(), 'user_id' => $owner, 'subscription_id' => $sub->get_id(), 'fire' => false, 'email' => self::OWNER ) );
		$plan    = Product::plan( $parent->get_id(), $v[ $key ]->get_id() );
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), $plan );
		$this->assertInstanceOf( Resource::class, $r );

		if ( ! $built ) {
			$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'db_state' => 'ready', 'db_address' => '10.64.0.2', 'disk_gb' => DbDisk::total( $plan ) ) ) );

			return Resource::find( $r->id() );
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
	 * The jobs written to the guest so far, in order.
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
	 * Answers the guest's job of the moment, like the guest would (see $guest for the grow job).
	 */
	private function answer_guest(): void {
		$job = json_decode( (string) $this->fake->guest_file( 5000, DbGuest::JOB ), true );

		if ( ! is_array( $job ) || empty( $job['request'] ) ) {
			return;
		}

		$status = function ( string $state, string $step, array $more = array() ) use ( $job ): void {
			$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array_merge( array( 'request' => $job['request'], 'state' => $state, 'step' => $step, 'updated' => gmdate( 'c' ) ), $more ) ) );
		};

		if ( 'grow' === ( $job['op'] ?? '' ) ) {
			if ( 'silent' === $this->guest ) {
				return;
			}

			if ( 'old' === $this->guest ) {
				$status( 'failed', 'job' );
			} elseif ( 'small' === $this->guest ) {
				$status( 'failed', 'grow' );
			} else {
				$status( 'ready', 'grow', array( 'size_bytes' => (int) $job['min_gb'] * 1073741824 ) );
			}

			return;
		}

		$status( 'ready', in_array( $job['op'] ?? '', array( 'allow', 'listen' ), true ) ? $job['op'] : 'complete' );
	}

	/**
	 * Runs the driver's plan update until it is done or failed; the guest answers whatever job it was sent.
	 */
	private function drive( Resource $r ): \BeltoftServices\Drivers\StepResult {
		$driver = Registry::get( 'proxmox-db' );
		$res    = null;

		for ( $i = 0; $i < 60; $i++ ) {
			$this->answer_guest();
			$res = $driver->update_plan( Resource::find( $r->id() ) );

			if ( $res->is_done() || $res->is_failed() ) {
				return $res;
			}

			$this->fake->elapse( 30 );
		}

		$this->fail( 'Did not settle: ' . $res->error() );
	}

	/**
	 * Gives the database the plan of another variation (what the subscription switch does) and runs the update.
	 *
	 * @param array<string, mixed> $over Plan fields.
	 */
	private function change( Resource $r, array $over ): \BeltoftServices\Drivers\StepResult {
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), $over ), 'Plan changed' ) );
		$res = $this->drive( $r );

		if ( $res->is_done() ) {
			$this->assertTrue( Resource::find( $r->id() )->set( array_merge( $res->meta(), array( 'plan_dirty' => false ) ) ) );
		}

		return $res;
	}

	/**
	 * What the license log and the final result say (a step that finished before the last call is in the log).
	 */
	private function logged( Resource $r, \BeltoftServices\Drivers\StepResult $res ): string {
		$lines = array( $res->event() );

		foreach ( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) as $event ) {
			$lines[] = (string) ( $event['message'] ?? '' );
		}

		return implode( "\n", $lines );
	}

	private function size_of( string $slot = 'scsi1' ): string {
		return (string) ( $this->fake->vm( 5000 )['config'][ $slot ] ?? '' );
	}

	/**
	 * The text of the mails sent to the admin.
	 */
	private function admin_text(): string {
		return implode( "\n", array_column( $this->mails, 'message' ) );
	}

	// -- The numbers and the ladder --------------------------------------------------------------

	public function test_the_data_disk_is_the_plan_disk_plus_the_extra_storage(): void {
		$this->assertSame( 70, DbDisk::total( array( 'disk_gb' => 20, 'extra_disk_gb' => 50 ) ) );
		$this->assertSame( 20, DbDisk::total( array( 'disk_gb' => 20 ) ) );
		$this->assertSame( 20, DbDisk::total( array( 'disk_gb' => 20, 'extra_disk_gb' => -5 ) ) );
		$this->assertSame( 50, DbDisk::extra( array( 'extra_disk_gb' => '50' ) ) );
		$this->assertArrayHasKey( 'extra_disk_gb', Registry::get( 'proxmox-db' )->plan_fields() );
		$this->assertTrue( Registry::get( 'proxmox-db' )->plan_fields()['extra_disk_gb'][4], 'A variation may set its own.' );
		$this->assertSame( 1000, DbDisk::max_gb() );
		$this->assertSame( 10, DbDisk::step_gb() );
		$this->assertArrayHasKey( 'db_disk_max_gb', apply_filters( 'beltoft_services_settings_fields', array() ) );
		$this->assertArrayHasKey( 'db_disk_step_gb', apply_filters( 'beltoft_services_settings_fields', array() ) );
	}

	public function test_the_ladder_is_the_same_plan_with_other_extra_storage_and_keeps_the_public_ip(): void {
		$v = $this->ladder_variations();

		$this->assertSame( array( 0, 50, 100, 250 ), array_keys( DbDisk::ladder( $v['e0-0'] ) ) );
		$this->assertSame( array( 0, 50, 100, 250 ), array_keys( DbDisk::ladder( $v['e100-0'] ) ) );

		$with = DbDisk::ladder( $v['e50-1'] );
		$this->assertSame( array( 0, 50, 100, 250 ), array_keys( $with ) );

		foreach ( $with as $step ) {
			$this->assertSame( 1, (int) Product::plan( $step->get_parent_id(), $step->get_id() )['public_ip'], 'The public IP choice is kept.' );
		}

		foreach ( DbDisk::ladder( $v['e0-0'] ) as $step ) {
			$this->assertSame( 0, (int) Product::plan( $step->get_parent_id(), $step->get_id() )['public_ip'] );
		}

		$this->assertSame( $v['e100-0']->get_id(), DbDisk::next_larger( $v['e50-0'] )->get_id() );
		$this->assertSame( $v['e50-1']->get_id(), DbDisk::next_larger( $v['e0-1'] )->get_id() );
		$this->assertNull( DbDisk::next_larger( $v['e250-0'] ) );
		$this->assertStringContainsString( '4.00', DbDisk::price_label( $v['e0-0'], $v['e50-0'] ) );
		$this->assertStringContainsString( '8.00', DbDisk::price_label( $v['e0-0'], $v['e100-0'] ) );
	}

	public function test_a_step_that_is_not_published_or_has_no_price_is_not_on_the_ladder(): void {
		$v = $this->ladder_variations();
		$v['e100-0']->set_status( 'private' );
		$v['e100-0']->save();
		$v['e250-0']->set_regular_price( '' );
		$v['e250-0']->save();
		wc_delete_product_transients( $v['e0-0']->get_parent_id() );

		$this->assertSame( array( 0, 50 ), array_keys( DbDisk::ladder( wc_get_product( $v['e0-0']->get_id() ) ) ) );
	}

	public function test_steps_that_break_the_settings_are_hidden_and_refused(): void {
		$v = $this->ladder_variations();
		$this->settings( array( 'db_disk_max_gb' => 90, 'db_disk_step_gb' => 50 ) );

		$this->assertSame( array( 0, 50 ), array_keys( DbDisk::ladder( $v['e0-0'] ) ), '100 GB would make 120 GB.' );
		$this->assertTrue( DbDisk::within_limits( array( 'disk_gb' => 20, 'extra_disk_gb' => 50 ) ) );
		$this->assertFalse( DbDisk::within_limits( array( 'disk_gb' => 20, 'extra_disk_gb' => 100 ) ) );
		$this->assertFalse( DbDisk::within_limits( array( 'disk_gb' => 20, 'extra_disk_gb' => 25 ) ), 'Not a multiple of the step.' );
		$this->assertTrue( DbDisk::within_limits( array( 'disk_gb' => 20 ) ) );

		$r = $this->db_on_ladder( 'e0-0', false );
		$this->assertStringContainsString( 'not available', DbDisk::switch_error( (int) $r->license()['subscription_id'], (int) $r->license()['product_id'], array( 'disk_gb' => 20, 'extra_disk_gb' => 100 ), null ) );
	}

	// -- The shop side: switches, stock, the picker ----------------------------------------------

	public function test_a_switch_to_less_total_disk_is_refused_and_a_larger_one_or_the_public_ip_is_let_through(): void {
		$v = $this->ladder_variations();
		$r = $this->db_on_ladder( 'e100-0', false );
		$this->assertSame( 120, DbDisk::promised_gb( $r ) );
		$sub = wcs_get_subscription( (int) $r->license()['subscription_id'] );
		$items = $sub->get_items();
		$item  = reset( $items );

		$ask = static function ( \WC_Product_Variation $target ) use ( $sub, $item ) {
			return apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $target->get_parent_id(), 1, $target->get_id(), $sub, $item );
		};

		$this->assertStringContainsString( "can't be made smaller", $ask( $v['e50-0'] ) );
		$this->assertStringContainsString( "can't be made smaller", $ask( $v['e0-0'] ) );
		$this->assertSame( '', $ask( $v['e250-0'] ), 'Larger is let through.' );
		$this->assertSame( '', $ask( $v['e100-0'] ), 'The same disk is let through.' );

		// The public IP switch is unchanged: refused only when no address can be given.
		$this->assertStringContainsString( 'No public IP address is available', $ask( $v['e100-1'] ) );
		$this->pool( 2 );
		$this->assertSame( '', $ask( $v['e100-1'] ) );
		$this->assertStringContainsString( "can't be made smaller", $ask( $v['e50-1'] ) );
	}

	public function test_a_switch_to_more_disk_is_refused_when_a_fresh_reading_says_it_does_not_fit(): void {
		$v = $this->ladder_variations();
		$r = $this->db_on_ladder( 'e0-0' );
		$sub   = wcs_get_subscription( (int) $r->license()['subscription_id'] );
		$items = $sub->get_items();
		$item  = reset( $items );
		$ask   = static fn( \WC_Product_Variation $t ) => apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $t->get_parent_id(), 1, $t->get_id(), $sub, $item );

		$this->assertSame( '', $ask( $v['e50-0'] ), 'No reading: the switch goes on and the grow waits.' );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->assertSame( '', $ask( $v['e50-0'] ) );
		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$this->assertStringContainsString( 'No more storage is available right now', $ask( $v['e50-0'] ) );
	}

	public function test_extra_storage_is_sold_out_when_the_disk_does_not_fit_and_a_plan_without_is_not(): void {
		$v = $this->ladder_variations();

		$this->assertTrue( Stock::available( $v['e0-0'] ), 'No extra storage: not asked.' );
		$this->assertFalse( Stock::available( $v['e50-0'] ), 'No reading of the storage: not sold.' );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->assertTrue( Stock::available( $v['e50-0'] ) );
		$this->assertTrue( Stock::available( $v['e250-0'] ) );
		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$this->assertFalse( Stock::available( $v['e50-0'] ) );
		$this->assertTrue( Stock::available( $v['e0-0'] ), 'A plan without extra storage is not asked about the disk.' );
	}

	public function test_the_storage_of_the_nodes_is_read_when_a_variation_has_extra_storage(): void {
		delete_transient( 'beltoft_services_disk_products' );
		$this->assertFalse( DiskCapacity::wanted(), 'Nothing sold with extra storage yet.' );

		$this->ladder_variations();
		delete_transient( 'beltoft_services_disk_products' );
		$this->assertTrue( DiskCapacity::wanted() );
		delete_transient( 'beltoft_services_disk_products' );
	}

	public function test_capacity_counts_what_a_database_was_promised(): void {
		$this->db_on_ladder( 'e0-0' );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$r = $this->db_on_ladder( 'e50-0', false );
		$this->assertSame( 70, DbDisk::promised_gb( $r ) );
		$this->assertTrue( $r->set( array( 'disk_gb' => null ) ) );
		$this->assertSame( 70, DbDisk::promised_gb( Resource::find( $r->id() ) ), 'Without a built size: the plan total.' );
		$this->assertTrue( $r->set( array( 'disk_gb' => 20 ) ) );
		$this->assertSame( 20, DbDisk::promised_gb( Resource::find( $r->id() ) ), 'What was built, until it is grown.' );
	}

	public function test_the_new_database_page_lists_each_plan_once_without_extra_storage_in_its_name(): void {
		$v     = $this->ladder_variations();
		$plans = DbPicker::plans();

		$this->assertArrayHasKey( $v['e0-0']->get_id(), $plans );

		foreach ( array( 'e50-0', 'e100-0', 'e250-0', 'e0-1', 'e50-1' ) as $key ) {
			$this->assertArrayNotHasKey( $v[ $key ]->get_id(), $plans, $key . ' is chosen at checkout.' );
		}

		$this->assertStringNotContainsString( 'None', $plans[ $v['e0-0']->get_id() ]['name'] );
		$this->assertStringNotContainsString( 'GB', $plans[ $v['e0-0']->get_id() ]['name'] );
		$this->assertSame( 20, $plans[ $v['e0-0']->get_id() ]['disk_gb'] );
	}

	public function test_a_new_database_is_built_with_its_data_disk_at_the_total(): void {
		$r = $this->db_on_ladder( 'e50-0' );

		$this->assertSame( 70, (int) $r->get( 'disk_gb' ) );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );
		$this->assertStringContainsString( ',backup=1', $this->size_of() );

	}

	public function test_a_new_database_without_extra_storage_is_built_with_the_plan_disk(): void {
		$r = $this->db_on_ladder( 'e0-0' );

		$this->assertSame( 20, (int) $r->get( 'disk_gb' ) );
		$this->assertStringContainsString( 'size=20G', $this->size_of() );
	}

	// -- The guest's status ----------------------------------------------------------------------

	public function test_the_status_has_the_size_of_a_grow_only_when_it_is_a_whole_number(): void {
		$this->db_on_ladder( 'e0-0' );
		$request = '11111111-1111-1111-1111-111111111111';
		$at      = gmdate( 'c' );
		$read    = function ( array $over ) use ( $request, $at ) {
			$this->fake->set_guest_file( 5000, DbGuest::STATUS, wp_json_encode( array_merge( array( 'request' => $request, 'state' => 'ready', 'step' => 'grow', 'updated' => $at ), $over ) ) );

			return DbGuest::status( $this->fake, 'node-a', 5000, $request );
		};

		$this->assertSame( array( 'state' => 'ready', 'step' => 'grow', 'size_bytes' => 32212254720 ), $read( array( 'size_bytes' => 32212254720 ) ) );
		$this->assertSame( array( 'state' => 'ready', 'step' => 'grow' ), $read( array() ) );

		foreach ( array( '32212254720', -5, 0, 1.5, null, array( 1 ), PHP_INT_MAX ) as $bad ) {
			$this->assertArrayNotHasKey( 'size_bytes', $read( array( 'size_bytes' => $bad ) ), 'Not accepted: ' . wp_json_encode( $bad ) );
		}

		$this->assertContains( 'grow', DbGuest::STEPS );
	}

	// -- The grow operation ----------------------------------------------------------------------

	public function test_a_larger_plan_grows_the_disk_asks_the_guest_and_records_the_size(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$this->assertStringContainsString( 'size=20G', $this->size_of() );
		$this->assertFalse( DbGrow::wanted( $r ) );

		$res = $this->change( $r, array( 'extra_disk_gb' => 50 ) );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertSame( 'Data disk made larger', $res->event() );

		$this->assertStringContainsString( 'size=70G', $this->size_of() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 70, (int) $fresh->get( 'disk_gb' ) );
		$this->assertNull( $fresh->get( DbGrow::OP ) );
		$this->assertNull( $fresh->get( DbGrow::FS ) );
		$this->assertNull( DiskCapacity::reservation( $fresh ), 'The reservation is released.' );
		$this->assertFalse( DbGrow::wanted( $fresh ) );

		$jobs = array_values( array_filter( $this->guest_jobs(), static fn( $j ) => 'grow' === $j['job']['op'] ) );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 70, $jobs[0]['job']['min_gb'] );
		$this->assertSame( 1, $jobs[0]['job']['v'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9-]{36}$/D', $jobs[0]['job']['request'] );

		$resize = $this->calls( 'PUT', '/resize' );
		$this->assertCount( 1, $resize );
		$this->assertSame( 'scsi1', $resize[0]['params']['disk'] );
		$this->assertSame( '70G', $resize[0]['params']['size'] );
		$this->assertStringNotContainsString( "\u{2014}", $res->event() );
	}

	public function test_nothing_is_sent_twice_when_the_update_runs_again(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$this->assertTrue( $this->change( $r, array( 'extra_disk_gb' => 50 ) )->is_done() );
		$jobs = count( $this->guest_jobs() );

		$again = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $again->is_done(), $again->error() );
		$this->assertCount( 1, $this->calls( 'PUT', '/resize' ) );
		$this->assertCount( $jobs, $this->guest_jobs() );

		// DbGrow itself says nothing was needed.
		$run = ( new DbGrow( Resource::find( $r->id() ) ) )->run();
		$this->assertTrue( $run->is_done() );
		$this->assertSame( 'No data disk change was needed', $run->event() );
	}

	public function test_a_run_that_stops_after_the_resize_does_not_resize_again_and_keeps_the_request_id(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'extra_disk_gb' => 50 ) ), 'Plan changed' ) );
		$this->guest = 'silent';
		$driver      = Registry::get( 'proxmox-db' );

		for ( $i = 0; $i < 6; $i++ ) {
			$res = $driver->update_plan( Resource::find( $r->id() ) );
			$this->assertFalse( $res->is_failed(), $res->error() );
			$this->fake->elapse( 30 );
		}

		$this->assertTrue( $res->is_poll() );
		$this->assertCount( 1, $this->calls( 'PUT', '/resize' ) );
		$op      = Resource::find( $r->id() )->get( DbGrow::OP );
		$request = $op['request'];
		$this->assertSame( 'guest', $op['phase'] );

		$this->guest = 'small';
		$this->answer_guest();
		$res = $driver->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_poll(), 'A guest that says the disk is still too small is asked again.' );
		$this->guest = 'new';

		$res = $this->drive( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertCount( 1, $this->calls( 'PUT', '/resize' ) );

		foreach ( array_filter( $this->guest_jobs(), static fn( $j ) => 'grow' === $j['job']['op'] ) as $job ) {
			$this->assertSame( $request, $job['job']['request'], 'The same request id every time.' );
		}
	}

	public function test_a_locked_server_waits_and_the_resize_follows_when_the_lock_is_gone(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'extra_disk_gb' => 50 ) ), 'Plan changed' ) );
		$this->fake->set_lock( 5000, 'backup' );

		$res = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_retry() );
		$this->assertStringContainsString( 'locked', $res->error() );
		$this->assertCount( 0, $this->calls( 'PUT', '/resize' ) );
		$this->assertStringContainsString( 'size=20G', $this->size_of() );

		$this->fake->set_lock( 5000, '' );
		$res = $this->drive( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );
	}

	public function test_no_room_on_the_storage_waits_an_hour_and_changes_nothing(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'extra_disk_gb' => 50 ) ), 'Plan changed' ) );
		$this->settings( array( 'disk_reserve_gb' => 10000 ) );

		$res = Registry::get( 'proxmox-db' )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_retry() );
		$this->assertSame( 3600, $res->delay() );
		$this->assertStringContainsString( 'not enough', $res->error() );
		$this->assertCount( 0, $this->calls( 'PUT', '/resize' ) );
		$this->assertCount( 0, $this->guest_jobs() ? array_filter( $this->guest_jobs(), static fn( $j ) => 'grow' === $j['job']['op'] ) : array() );
		$this->assertNull( DiskCapacity::reservation( Resource::find( $r->id() ) ) );

		$this->settings( array( 'disk_reserve_gb' => 0 ) );
		$res = $this->drive( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
	}

	public function test_a_disk_already_larger_is_never_made_smaller_and_the_admin_is_told(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/resize', array( 'disk' => 'scsi1', 'size' => '100G' ) );
		$this->fake->elapse( 30 );
		$this->assertStringContainsString( 'size=100G', $this->size_of() );

		$res = $this->change( $r, array( 'extra_disk_gb' => 50 ) );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertStringContainsString( 'size=100G', $this->size_of() );
		$this->assertCount( 0, array_filter( $this->calls( 'PUT', '/resize' ), static fn( $c ) => '70G' === ( $c['params']['size'] ?? '' ) ) );
		$this->assertSame( 100, (int) Resource::find( $r->id() )->get( 'disk_gb' ), 'What the disk really is.' );
		$this->assertStringContainsString( 'never made smaller', $this->admin_text() );
	}

	public function test_a_guest_that_does_not_know_the_job_leaves_the_file_system_to_the_admin_without_failing_the_database(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$this->guest = 'old';

		$res = $this->change( $r, array( 'extra_disk_gb' => 50 ) );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'manual', $fresh->get( DbGrow::FS ) );
		$this->assertNull( $fresh->get( DbGrow::OP ) );
		$this->assertSame( 70, (int) $fresh->get( 'disk_gb' ) );
		$this->assertTrue( DbGrow::manual( $fresh ) );
		$this->assertStringContainsString( 'qm guest exec 5000 -- resize2fs /dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_drive-scsi1', $this->admin_text() );
		$this->assertStringContainsString( 'template is older', $this->admin_text() );
		$this->assertSame( 'qm guest exec 77 -- resize2fs /dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_drive-scsi1', DbGrow::manual_command( 77 ) );

		$view = DbDisk::view( $fresh );
		$this->assertTrue( $view['manual'] );
		$this->assertSame( 70, $view['gb'] );
		$this->assertSame( array(), $view['options'], 'Nothing is offered while the storage is being prepared.' );
	}

	public function test_a_guest_agent_that_does_not_answer_in_ten_minutes_does_the_same(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'extra_disk_gb' => 50 ) ), 'Plan changed' ) );
		$this->guest = 'silent';
		$driver      = Registry::get( 'proxmox-db' );

		for ( $i = 0; $i < 6; $i++ ) {
			$res = $driver->update_plan( Resource::find( $r->id() ) );
			$this->fake->elapse( 30 );
		}

		$this->assertTrue( $res->is_poll(), 'The job was sent and nothing answers.' );
		$op       = Resource::find( $r->id() )->get( DbGrow::OP );
		$op['at'] = time() - 700;
		$this->assertTrue( Resource::find( $r->id() )->set( array( DbGrow::OP => $op ) ) );

		$res = $driver->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertSame( 'manual', Resource::find( $r->id() )->get( DbGrow::FS ) );
		$this->assertStringContainsString( 'within 10 minutes', $this->admin_text() );
		$this->assertStringContainsString( 'qm guest exec 5000', $this->admin_text() );
	}

	public function test_an_unreachable_guest_agent_is_waited_for(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( true === $fresh->change_plan( array_merge( (array) $fresh->get( 'plan' ), array( 'extra_disk_gb' => 50 ) ), 'Plan changed' ) );
		$driver = Registry::get( 'proxmox-db' );
		$this->fake->fail_next( 'POST', '#/agent/file-write$#', new \WP_Error( 'http_500', 'QEMU guest agent is not running' ) );

		$res = $driver->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_poll(), $res->error() );
		$this->assertStringContainsString( 'waiting for the guest agent', $res->error() );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );
	}

	public function test_a_restore_that_put_back_a_smaller_disk_is_followed_by_the_grow(): void {
		$r = $this->db_on_ladder( 'e50-0' );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );

		// A backup from before the growth: scsi1 is 20 GB again after the restore, the plan and the record say 70.
		$config = $this->fake->vm( 5000 )['config'];
		$old    = preg_replace( '/size=70G/', 'size=20G', $config['scsi1'] );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi1' => $old ) );
		$this->assertStringContainsString( 'size=20G', $this->size_of() );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->elapse( 30 );
		$this->assertTrue( Resource::find( $r->id() )->set( array( 'was_running' => true ) ) );
		$this->health();

		$res = $this->drive( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'was_running' ) );
		$this->assertSame( 70, (int) $fresh->get( 'disk_gb' ) );
		$this->assertCount( 1, array_filter( $this->guest_jobs(), static fn( $j ) => 'grow' === $j['job']['op'] ) );
		$this->assertStringContainsString( 'Database restored from a backup', $this->logged( $r, $res ) );
		$this->assertStringContainsString( 'Data disk made larger', $this->logged( $r, $res ) );
	}

	public function test_a_restore_without_a_size_difference_changes_nothing_on_the_disk(): void {
		$r = $this->db_on_ladder( 'e50-0' );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->elapse( 30 );
		$this->assertTrue( Resource::find( $r->id() )->set( array( 'was_running' => true ) ) );
		$this->health();

		$res = $this->drive( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertCount( 0, $this->calls( 'PUT', '/resize' ) );
		$this->assertCount( 0, array_filter( $this->guest_jobs(), static fn( $j ) => 'grow' === $j['job']['op'] ) );
		$this->assertSame( 'Database restored from a backup', $res->event() );
	}

	public function test_the_ip_change_comes_first_and_the_grow_follows_in_one_update(): void {
		$this->pool( 3 );
		$r = $this->db_on_ladder( 'e0-0' );

		$res = $this->change( $r, array( 'public_ip' => 1, 'extra_disk_gb' => 50 ) );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertNotEmpty( IpPool::for_resource( $r->id() ) );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );
		$this->assertSame( 70, (int) Resource::find( $r->id() )->get( 'disk_gb' ) );
		$this->assertStringContainsString( 'Public IP address added', $this->logged( $r, $res ) );
		$this->assertStringContainsString( 'Data disk made larger', $this->logged( $r, $res ) );

		$ops = array_column( array_column( $this->guest_jobs(), 'job' ), 'op' );
		$this->assertLessThan( array_search( 'grow', $ops, true ), array_search( 'listen', $ops, true ), 'The listen job comes before the grow job.' );
		$this->assertNull( Resource::find( $r->id() )->get( DbIp::OP ) );
		$this->assertNull( Resource::find( $r->id() )->get( DbGrow::OP ) );
	}

	public function test_a_change_of_the_ip_alone_does_not_touch_the_disk_and_a_change_of_the_disk_alone_not_the_ip(): void {
		$this->pool( 3 );
		$r = $this->db_on_ladder( 'e0-0' );
		$this->assertTrue( $this->change( $r, array( 'public_ip' => 1 ) )->is_done() );
		$this->assertCount( 0, $this->calls( 'PUT', '/resize' ) );
		$this->assertCount( 0, array_filter( $this->guest_jobs(), static fn( $j ) => 'grow' === $j['job']['op'] ) );

		$shutdowns = count( $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertTrue( $this->change( $r, array( 'extra_disk_gb' => 50 ) )->is_done() );
		$this->assertCount( $shutdowns, $this->calls( 'POST', '/status/shutdown' ), 'Growing the disk does not restart the server.' );
		$this->assertStringContainsString( 'size=70G', $this->size_of() );
	}

	public function test_a_plan_difference_that_is_neither_the_address_nor_the_storage_is_not_applied_and_the_admin_is_told(): void {
		$r = $this->db_on_ladder( 'e0-0' );

		$res = $this->change( $r, array( 'cores' => 4 ) );
		$this->assertTrue( $res->is_done() );
		$this->assertSame( 'Plan change on a database was not applied', $res->event() );
		$this->assertStringContainsString( 'cores and memory are not', $this->admin_text() );
		$this->assertCount( 0, $this->calls( 'PUT', '/resize' ) );
	}

	// -- The dashboard ---------------------------------------------------------------------------

	private function card( Resource $r ): string {
		$view = DbCard::view( Resource::find( $r->id() ) );
		ob_start();
		( static function ( array $view, array $nav, string $active, callable $link ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/db.php';
		} )( $view, array(), 'overview', static fn( $slug ) => '/x/' . $slug );

		return (string) ob_get_clean();
	}

	public function test_the_card_shows_the_total_size_and_the_larger_steps_with_their_price_difference(): void {
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
		$v = $this->ladder_variations();
		$r = $this->db_on_ladder( 'e50-0', false );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );

		$view = DbCard::view( $r );
		$this->assertStringContainsString( '70 GB disk', $view['size'] );
		$this->assertSame( 70, $view['storage']['gb'] );
		$this->assertFalse( $view['storage']['growing'] );
		$this->assertCount( 2, $view['storage']['options'], 'Only strictly larger steps.' );
		$this->assertSame( array( 120, 270 ), array_column( $view['storage']['options'], 'total' ) );
		$this->assertStringContainsString( '4.00', $view['storage']['options'][0]['price'], '100 GB costs 4 more than 50 GB.' );

		$args = array();
		wp_parse_str( (string) wp_parse_url( $view['storage']['options'][0]['url'], PHP_URL_QUERY ), $args );
		$this->assertSame( (string) $v['e100-0']->get_id(), (string) $args['variation_id'] );
		$this->assertArrayHasKey( 'switch-subscription', $args );
		$this->assertSame( $view['storage']['options'][0]['url'], DbDisk::grow_url( $r ) );

		$html = $this->card( $r );
		$this->assertStringContainsString( 'Your database has 70 GB of storage.', $html );
		$this->assertStringContainsString( 'Make larger: 120 GB', $html );
		$this->assertStringContainsString( 'Make larger: 270 GB', $html );
		$this->assertStringContainsString( 'cannot be made smaller', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
	}

	public function test_an_option_that_does_not_fit_is_hidden_and_none_left_says_so(): void {
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
		$r = $this->db_on_ladder( 'e50-0' );
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$this->assertNotEmpty( DbDisk::view( $r )['options'] );

		$this->settings( array( 'disk_reserve_gb' => 10000 ) );
		$view = DbDisk::view( Resource::find( $r->id() ) );
		$this->assertSame( array(), $view['options'] );
		$this->assertTrue( $view['full'] );
		$html = $this->card( $r );
		$this->assertStringContainsString( 'No more storage available right now.', $html );
		$this->assertStringNotContainsString( 'Make larger', $html );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
	}

	public function test_the_largest_step_has_nothing_to_offer_and_no_full_notice(): void {
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
		$r    = $this->db_on_ladder( 'e250-0', false );
		$view = DbDisk::view( $r );

		$this->assertSame( array(), $view['options'] );
		$this->assertFalse( $view['full'] );
		$this->assertSame( '', DbDisk::grow_url( $r ) );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
	}

	public function test_while_the_disk_is_being_grown_the_card_says_so_and_offers_nothing(): void {
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
		$r = $this->db_on_ladder( 'e50-0', false );
		$this->assertTrue( $r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'extra_disk_gb' => 100 ) ), 'plan_dirty' => true ) ) );

		$view = DbDisk::view( Resource::find( $r->id() ) );
		$this->assertTrue( $view['growing'], 'The plan wants more than the database has.' );
		$this->assertSame( 70, $view['gb'], 'The size shown is the size it has.' );
		$this->assertSame( array(), $view['options'] );
		$this->assertStringContainsString( 'being made larger', $this->card( $r ) );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
	}

	public function test_a_database_that_is_not_ready_has_no_storage_block(): void {
		$r = $this->db_on_ladder( 'e0-0', false );
		$this->assertTrue( $r->set( array( 'db_state' => null ) ) );
		$html = $this->card( $r );

		$this->assertStringNotContainsString( 'Your database has', $html );
	}

	public function test_the_disk_alert_mail_has_the_make_larger_link(): void {
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
		$r = $this->db_on_ladder( 'e0-0', false );
		$this->settings( array( 'monitor_alerts' => 'on' ) );
		$sent = \BeltoftServices\Emails\Emails::send( \BeltoftServices\Proxmox\Monitor::EMAIL_ID, $r, array( 'reason' => 'db_disk', 'disk_percent' => 90 ) );

		$this->assertTrue( (bool) $sent );
		$mail = end( $this->mails );
		$this->assertStringContainsString( 'Make your storage larger', (string) $mail['message'] );
		$this->assertStringContainsString( 'variation_id=', html_entity_decode( (string) $mail['message'] ) );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
	}

	// -- Manual grow flag ------------------------------------------------------------------------

	/** A database flagged for a manual grow, 70 GB disk, running. */
	private function manual_db(): Resource {
		$r = $this->db_on_ladder( 'e0-0' );
		$this->assertTrue( $r->set( array( DbGrow::FS => 'manual', 'disk_gb' => 70 ) ) );
		$this->fake->set_fsinfo( 5000, $this->fsinfo( 70 ) );

		return Resource::find( $r->id() );
	}

	private function fsinfo( float $data_gb ): array {
		return array( 'result' => array(
			array( 'mountpoint' => '/', 'total-bytes' => 2790285312, 'used-bytes' => 884944896, 'type' => 'ext4' ),
			array( 'mountpoint' => '/srv/db', 'total-bytes' => (int) ( $data_gb * 1073741824 ), 'used-bytes' => 1000, 'type' => 'ext4' ),
		) );
	}

	public function test_the_manual_flag_is_cleared_once_the_data_file_system_covers_the_disk(): void {
		$r = $this->manual_db();
		$this->fake->set_fsinfo( 5000, $this->fsinfo( 67 ) ); // 70 GB less a little overhead.

		$this->assertTrue( DbGrow::check_manual( $r ) );
		$this->assertNull( Resource::find( $r->id() )->get( DbGrow::FS ) );
		$this->assertFalse( DbDisk::view( Resource::find( $r->id() ) )['manual'] );
	}

	public function test_the_manual_flag_stays_while_the_file_system_is_too_small(): void {
		$r = $this->manual_db();
		$this->fake->set_fsinfo( 5000, $this->fsinfo( 20 ) );

		$this->assertFalse( DbGrow::check_manual( $r ) );
		$this->assertSame( 'manual', Resource::find( $r->id() )->get( DbGrow::FS ) );
	}

	public function test_an_agent_error_leaves_the_manual_flag(): void {
		$r = $this->manual_db();
		$this->fake->fail_next( 'GET', '#/agent/get-fsinfo$#', new \WP_Error( 'agent_missing', 'Agent unavailable.' ) );

		$this->assertFalse( DbGrow::check_manual( $r ) );
		$this->assertSame( 'manual', Resource::find( $r->id() )->get( DbGrow::FS ) );
	}

	public function test_an_unflagged_database_makes_no_file_system_call(): void {
		$r = $this->db_on_ladder( 'e0-0' );
		$this->fake->set_fsinfo( 5000, $this->fsinfo( 70 ) );

		$this->assertFalse( DbGrow::check_manual( $r ) );
		$this->assertCount( 0, $this->calls( 'GET', '/agent/get-fsinfo' ) );
	}

	public function test_the_disk_job_clears_the_flag(): void {
		$r = $this->manual_db();
		set_transient( ProxmoxJobs::RUNNING_PREFIX . 'lab', array( array( 'resource' => $r->id(), 'cluster' => 'lab', 'vmid' => 5000 ) ), HOUR_IN_SECONDS );
		// The root reading is fresh, so only the flagged database's data check is due.
		$this->assertTrue( $r->set( array( 'disk_used' => 10, 'disk_total' => 100, 'disk_read_at' => time() ) ) );

		ProxmoxJobs::disk();

		$this->assertNull( Resource::find( $r->id() )->get( DbGrow::FS ) );
		$this->assertCount( 1, $this->calls( 'GET', '/agent/get-fsinfo' ) );
	}
}

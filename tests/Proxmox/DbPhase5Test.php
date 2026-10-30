<?php
/**
 * Managed database backups, restore and monitoring alerts. Only the fake client is used; mail goes to the owner's address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbActions;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DbGuest;
use BeltoftServices\Proxmox\DbMonitor;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A database driver that does not sleep.
 */
class Phase5DbDriver extends DbDriver {

	public function nap( int $seconds ): void {
		unset( $seconds );
	}
}

class DbPhase5Test extends TestCase {

	private const SECRET = 'secret-secret-secret-1';
	private const OWNER  = 'christian.beltoft@gmail.com';

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
		add_filter( 'beltoft_services_proxmox_pause', fn() => function ( int $seconds ): void {
			$this->fake->elapse( $seconds );
		}, 20 );

		$this->wipe();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5, 'pause_all' => 0, 'admin_email' => self::OWNER, 'monitor_alerts' => 'on' ) );

		$this->fake = new FakeClient( true );
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		add_filter(
			'beltoft_services_drivers',
			function ( $drivers ) {
				$drivers['proxmox-db'] = new Phase5DbDriver();
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

	// -- Backups on by default -------------------------------------------------------------------

	public function test_a_database_is_backed_up_without_a_backup_plan_and_keeps_seven_days_by_default(): void {
		$r = $this->ready_db();

		$this->assertSame( 0, (int) ( (array) $r->get( 'plan' ) )['backup_days'], 'The plan names no backup days.' );
		$this->assertTrue( Backups::enabled( $r ) );
		$this->assertSame( 7, Backups::days( $r ) );
		$this->assertTrue( Backups::due( $r, time() ) );
	}

	public function test_the_plan_days_win_for_a_database_and_a_cluster_without_storage_has_no_backups(): void {
		$three = $this->ready_db( array( 'backup_days' => 3 ) );
		$this->assertSame( 3, Backups::days( $three ) );
		$this->assertTrue( Backups::enabled( $three ) );

		$this->save_cluster( array( 'token_secret' => '', 'backup_storage' => '' ) );
		$this->assertFalse( Backups::enabled( Resource::find( $three->id() ) ) );
	}

	public function test_a_server_without_backup_days_is_still_not_backed_up(): void {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$vps     = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $vps );

		$this->assertSame( 0, Backups::days( $vps ), 'The database default never applies to a server.' );
		$this->assertFalse( Backups::enabled( $vps ) );

		$with = Resource::create( $this->service_license( $product, array( 'fire' => false ) ), Registry::get( 'proxmox' ), array_merge( Product::plan( $product->get_id() ), array( 'backup_days' => 5 ) ) );
		$this->assertSame( 5, Backups::days( $with ) );
	}

	public function test_the_nightly_run_backs_up_a_database_and_stops(): void {
		$r = $this->ready_db();

		ProxmoxJobs::backup();

		$dumps = $this->calls( 'POST', '/vzdump' );
		$this->assertCount( 1, $dumps );
		$this->assertSame( 5000, (int) $dumps[0]['params']['vmid'] );
		$this->assertSame( 'backup-store', $dumps[0]['params']['storage'] );

		$fresh = Resource::find( $r->id() );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'backup_ok_at' ) );
		$this->assertCount( 1, (array) $fresh->get( 'backup_list' ) );

		$this->follow_up();
		$this->assertCount( 1, $this->calls( 'POST', '/vzdump' ), 'Not due again.' );
		$this->assertFalse( as_has_scheduled_action( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP ) );
	}

	public function test_backups_are_pruned_to_the_days_of_the_plan(): void {
		$r = $this->ready_db( array( 'backup_days' => 2 ) );

		foreach ( range( 1, 5 ) as $age ) {
			$this->fake->add_backup( 'backup-store', array( 'vmid' => 5000, 'ctime' => time() - $age * DAY_IN_SECONDS ) );
		}

		ProxmoxJobs::backup();

		$prune = $this->calls( 'DELETE', '/prunebackups' );
		$this->assertCount( 1, $prune );
		$this->assertSame( 'keep-daily=2', $prune[0]['params']['prune-backups'] );
		$this->assertCount( 2, $this->fake->state()['backups']['backup-store'], 'Two days are kept.' );
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( 'backup_fails' ) );
	}

	public function test_the_default_days_prune_a_database_whose_plan_names_none(): void {
		$this->ready_db();

		ProxmoxJobs::backup();

		$this->assertSame( 'keep-daily=7', $this->calls( 'DELETE', '/prunebackups' )[0]['params']['prune-backups'] );
	}

	public function test_a_database_that_is_not_ready_or_busy_is_left_alone(): void {
		$r = $this->ready_db();
		$r->set( array( 'op' => 'update_plan' ) );

		ProxmoxJobs::backup();

		$this->assertCount( 0, $this->calls( 'POST', '/vzdump' ) );
	}

	public function test_the_backups_of_a_deleted_database_are_purged_first(): void {
		$r = $this->ready_db();
		$this->backup_of( $r );
		$this->assertCount( 1, $this->fake->state()['backups']['backup-store'] );

		$op = new \BeltoftServices\Proxmox\Operations( Resource::find( $r->id() ) );
		$r->set( array( 'desired' => 'deleted', 'state' => 'deleting' ) );
		$result = $op->delete();

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( array(), (array) ( $this->fake->state()['backups']['backup-store'] ?? array() ), 'The backups went before the VM.' );
		$this->assertNull( $this->fake->vm( 5000 ) );
	}

	// -- The Backups tab, the Overview ---------------------------------------------------------

	private function page( Resource $r, string $tab ): string {
		$view = DbCard::view( $r );
		$nav  = array( 'overview' => 'Overview', 'backups' => 'Backups', 'password' => 'New password' );
		$link = static fn( string $slug ): string => 'https://example.test/' . $slug;

		ob_start();
		( static function ( array $view, array $nav, string $active, callable $link ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/db.php';
		} )( $view, $nav, $tab, $link );

		return (string) ob_get_clean();
	}

	public function test_the_overview_shows_the_backup_facts_and_the_tab_the_list_the_dump_and_the_restore_form(): void {
		$r = $this->ready_db( array( 'backup_days' => 4 ) );
		$this->backup_of( $r );
		ProxmoxJobs::backup();
		$this->health( array( 'last_dump' => 1761800000 ) );
		DbMonitor::check( Resource::find( $r->id() ), $this->fake, 'node-a', 5000, 'running' );
		$fresh = Resource::find( $r->id() );

		$overview = $this->page( $fresh, 'overview' );
		$this->assertStringContainsString( 'Daily, kept 4 days', $overview );
		$this->assertStringContainsString( 'Last backup', $overview );
		$this->assertStringNotContainsString( 'None yet', $overview );
		$this->assertStringNotContainsString( 'Restore from a backup', $overview );

		$tab = $this->page( $fresh, 'backups' );
		$this->assertStringContainsString( 'Last nightly dump:', $tab );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 1761800000 ), $tab );
		$this->assertStringContainsString( 'beltoft-services-vm__backup-list', $tab );
		$this->assertStringContainsString( 'Everything after this backup is lost.', $tab );
		$this->assertStringContainsString( 'The password goes back to the one the backup had.', $tab );
		$this->assertStringContainsString( 'data-db-restore-confirm', $tab );
		$this->assertStringContainsString( 'RESTORE', $tab );
		$this->assertStringNotContainsString( '—', $tab );
		$this->assertStringNotContainsString( 'data-db-reset', $tab );
		$this->assertStringNotContainsString( 'Reachable from your servers', $tab );
	}

	public function test_a_database_without_a_backup_yet_says_so_and_offers_no_restore(): void {
		$r = $this->ready_db();

		$this->assertStringContainsString( 'None yet', $this->page( $r, 'overview' ) );
		$tab = $this->page( $r, 'backups' );
		$this->assertStringContainsString( 'will appear here after tonight', $tab );
		$this->assertStringNotContainsString( 'data-db-restore-confirm', $tab );
		$this->assertStringNotContainsString( 'Last nightly dump', $tab );
	}

	// -- Restore -------------------------------------------------------------------------------

	public function test_restore_is_offered_with_a_typed_word_and_a_listed_backup_only(): void {
		$r     = $this->ready_db();
		$volid = $this->backup_of( $r );
		$r     = Resource::find( $r->id() );

		$this->assertArrayHasKey( DbActions::RESTORE, DbActions::offered( $r ) );
		$this->assertArrayNotHasKey( DbActions::RESTORE, DbActions::buttons( DbActions::offered( $r ), $r ), 'The form draws it, not a generic button.' );

		$refused = DbActions::refusal( null, $r, 'restore', array( 'volid' => $volid, 'confirm' => 'yes' ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertStringContainsString( 'Everything after this backup is lost', $refused->get_error_message() );

		$other = DbActions::refusal( null, $r, 'restore', array( 'volid' => 'backup-store:backup/vm/9999/2026-01-01T00:00:00Z', 'confirm' => 'RESTORE' ) );
		$this->assertInstanceOf( \WP_Error::class, $other, 'A backup that is not this server\'s is refused.' );

		$this->assertNull( DbActions::refusal( null, $r, 'restore', array( 'volid' => $volid, 'confirm' => 'RESTORE' ) ) );
		$this->assertNull( DbActions::refusal( null, $r, 'start', array() ) );
	}

	public function test_restore_is_refused_while_a_new_password_or_another_restore_runs(): void {
		$r     = $this->ready_db();
		$volid = $this->backup_of( $r );
		$args  = array( 'volid' => $volid, 'confirm' => 'RESTORE' );

		$r->set( array( 'db_reset_request' => wp_generate_uuid4() ) );
		$busy = DbActions::refusal( null, Resource::find( $r->id() ), 'restore', $args );
		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( 409, $busy->get_error_data()['status'] );
		$r->set( array( 'db_reset_request' => null ) );

		$r->set( array( 'restore' => array( 'volid' => 'x', 'phase' => 'restored', 'ctime' => 1 ) ) );
		$this->assertInstanceOf( \WP_Error::class, DbActions::refusal( null, Resource::find( $r->id() ), 'restore', $args ) );
	}

	public function test_restore_puts_the_server_back_starts_it_and_waits_for_a_healthy_database(): void {
		$r       = $this->ready_db();
		$volid   = $this->backup_of( $r );
		$before  = Resource::find( $r->id() );
		$address = (string) $before->get( 'db_address' );
		$secret  = $before->get( 'db_reveal' );
		$config  = $this->fake->vm( 5000 )['config'];

		// Someone breaks the private card and the firewall after the backup (a restore of an old state must not be trusted).
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'net1' => 'virtio,bridge=vmbr2,tag=1000,firewall=1', 'ipconfig1' => 'ip=10.64.0.9/28' ) );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/firewall/options', array( 'enable' => 0, 'policy_in' => 'ACCEPT' ) );

		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$mid = Resource::find( $r->id() );
		$this->assertNull( $mid->get( 'restore' ) );
		$this->assertTrue( $mid->get( 'was_running' ), 'The change that starts the server is queued.' );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( 'restoring', DbCard::view( $mid )['kind'] );
		$this->assertStringContainsString( 'being restored', $this->page( $mid, 'overview' ) );
		$this->assertStringContainsString( 'A restore is running', $this->page( $mid, 'backups' ) );

		// The address, the firewall and the credentials are what they were.
		$vm = $this->fake->vm( 5000 );
		$this->assertSame( $config['ipconfig1'], $vm['config']['ipconfig1'] );
		$this->assertSame( 'ip=' . $address . '/28', $vm['config']['ipconfig1'] );
		$this->assertSame( 'DROP', $vm['firewall_options']['policy_in'] );
		$this->assertSame( 1, (int) $vm['firewall_options']['enable'] );
		$this->assertSame( $address, Resource::find( $r->id() )->get( 'db_address' ) );
		$this->assertSame( $secret, Resource::find( $r->id() )->get( 'db_reveal' ) );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'db_state' ) );

		// The server starts, but the database must say it is ok before the restore is over.
		$this->health( array( 'updated' => gmdate( 'c', time() - DAY_IN_SECONDS ) ) );
		Jobs::run( $r->id() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$waiting = Resource::find( $r->id() );
		$this->assertTrue( $waiting->get( 'was_running' ), 'The health file of the backup is old: not done yet.' );
		$this->assertSame( 'restoring', DbCard::view( $waiting )['kind'] );

		$this->health();
		Resource::find( $r->id() )->set( array( 'next_attempt_at' => null ) );
		Jobs::run( $r->id() );
		$done = Resource::find( $r->id() );
		$this->assertNull( $done->get( 'was_running' ) );
		$this->assertNull( $done->get( 'db_restore_since' ) );
		$this->assertSame( 'ready', $done->get( 'state' ) );
		$this->assertSame( 'ready', DbCard::view( $done )['kind'] );
		$this->assertFalse( (bool) $done->get( 'plan_dirty' ) );
		$this->assertSame( 20, (int) ( (array) $done->get( 'plan' ) )['disk_gb'], 'The plan disk is the data disk and stays.' );
		$this->assertSame( 20, (int) $done->get( 'disk_gb' ), 'The boot disk is not recorded as the plan disk.' );
		$this->assertCount( 0, $this->calls( 'PUT', '/resize' ), 'Nothing is grown.' );
	}

	public function test_a_database_that_does_not_come_back_healthy_fails_and_the_admin_is_told(): void {
		$r     = $this->ready_db();
		$volid = $this->backup_of( $r );

		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
		$this->health( array( 'ok' => false ) );
		Jobs::run( $r->id() );
		$this->assertTrue( Resource::find( $r->id() )->get( 'was_running' ) );

		Resource::find( $r->id() )->set( array( 'db_restore_since' => time() - 1000 ) );
		$driver = Registry::get( 'proxmox-db' );
		$result = $driver->update_plan( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'did not become healthy', $result->error() );
	}

	public function test_restore_does_not_touch_the_password_meta_or_send_a_secret(): void {
		$r     = $this->ready_db();
		$volid = $this->backup_of( $r );
		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
		$this->health();
		Jobs::run( $r->id() );

		$this->assertStringNotContainsString( 'Abcdefghijklmnopqrstuvwxyz012345', (string) wp_json_encode( $this->fake->calls() ) );
		$this->assertCount( 0, array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) wp_json_encode( $m ), 'Abcdefghijklmnopqrstuvwxyz012345' ) ) );
	}

	// -- The health file -----------------------------------------------------------------------

	public function test_the_health_reader_accepts_a_good_file_and_rejects_bad_ones(): void {
		$this->ready_db();
		$client = $this->fake;

		$this->health();
		$good = DbGuest::health( $client, 'node-a', 5000 );
		$this->assertIsArray( $good );
		$this->assertTrue( $good['ok'] );
		$this->assertSame( 3, $good['connections'] );
		$this->assertSame( 10.0, $good['disk_percent'] );
		$this->assertSame( 0, $good['bans'] );

		$this->health( array( 'raw' => '{not json' ), true );
		$this->assertNull( DbGuest::health( $client, 'node-a', 5000 ), 'Bad JSON.' );

		$this->health( array( 'raw' => '"text"' ), true );
		$this->assertNull( DbGuest::health( $client, 'node-a', 5000 ), 'Not an object.' );

		$this->health( array( 'raw' => wp_json_encode( array( 'updated' => gmdate( 'c' ), 'ok' => 'yes' ) ) ), true );
		$this->assertNull( DbGuest::health( $client, 'node-a', 5000 ), 'ok is not a boolean.' );

		$this->health( array( 'raw' => wp_json_encode( array( 'updated' => 'tomorrow-ish', 'ok' => true ) ) ), true );
		$this->assertNull( DbGuest::health( $client, 'node-a', 5000 ), 'No date.' );

		foreach ( array( array( 'disk_percent' => 140 ), array( 'disk_percent' => '90' ), array( 'bans' => -1 ), array( 'bans' => '5' ), array( 'last_dump' => 'now' ), array( 'cert_expires' => 1.5 ), array( 'connections' => array( 1 ) ) ) as $over ) {
			$this->health( $over );
			$this->assertNull( DbGuest::health( $client, 'node-a', 5000 ), wp_json_encode( $over ) );
		}

		$this->health( array( 'raw' => str_repeat( ' ', 70000 ) . wp_json_encode( array( 'updated' => gmdate( 'c' ), 'ok' => true ) ) ), true );
		$this->assertNull( DbGuest::health( $client, 'node-a', 5000 ), 'A file over 64 KiB is refused.' );

		$this->health( array( 'raw' => wp_json_encode( array( 'updated' => gmdate( 'c' ), 'ok' => true ) ) ), true );
		$bare = DbGuest::health( $client, 'node-a', 5000 );
		$this->assertIsArray( $bare, 'The optional numbers may be missing.' );
		$this->assertNull( $bare['disk_percent'] );
		$this->assertNull( $bare['last_dump'] );
	}

	// -- Alerts --------------------------------------------------------------------------------

	/**
	 * @return array<int, string> Subjects to the owner.
	 */
	private function subjects(): array {
		return array_map( static fn( $m ) => (string) $m['subject'], $this->mails );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function to_admin(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'Database alert' ) ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function to_customer(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false === strpos( (string) $m['subject'], 'Database alert' ) ) );
	}

	/**
	 * A reading as DbGuest::health() returns it.
	 *
	 * @param array<string, mixed> $over Values.
	 * @return array<string, mixed>
	 */
	private function reading( int $now, array $over = array() ): array {
		return array_merge(
			array( 'ok' => true, 'updated' => $now - 60, 'connections' => 3, 'disk_percent' => 10.0, 'last_dump' => $now - HOUR_IN_SECONDS, 'cert_expires' => $now + 300 * DAY_IN_SECONDS, 'bans' => 0 ),
			$over
		);
	}

	public function test_down_alerts_customer_and_admin_once_after_ten_minutes_and_rearms(): void {
		$r   = $this->ready_db();
		$now = time();

		DbMonitor::apply( $r, $this->reading( $now, array( 'ok' => false ) ), $now );
		DbMonitor::apply( $r, $this->reading( $now + 300, array( 'ok' => false, 'updated' => $now + 240 ) ), $now + 300 );
		$this->assertCount( 0, $this->mails, 'Not for 10 minutes yet.' );

		DbMonitor::apply( $r, $this->reading( $now + 600, array( 'ok' => false, 'updated' => $now + 540 ) ), $now + 600 );
		$this->assertCount( 1, $this->to_admin() );
		$this->assertCount( 1, $this->to_customer() );
		$this->assertSame( 'Your database is not answering', $this->to_customer()[0]['subject'] );
		$this->assertStringContainsString( 'has not answered its health check', (string) $this->to_customer()[0]['message'] );

		DbMonitor::apply( $r, $this->reading( $now + 900, array( 'ok' => false, 'updated' => $now + 840 ) ), $now + 900 );
		$this->assertCount( 2, $this->mails, 'Deduped: once per episode.' );

		DbMonitor::apply( $r, $this->reading( $now + 1200 ), $now + 1200 );
		$this->assertNull( ( (array) Resource::find( $r->id() )->get( DbMonitor::META ) )['down_since'] ?? null, 'Re-armed by a good reading.' );

		DbMonitor::apply( $r, $this->reading( $now + 1500, array( 'ok' => false ) ), $now + 1500 );
		DbMonitor::apply( $r, $this->reading( $now + 2100, array( 'ok' => false ) ), $now + 2100 );
		$this->assertCount( 4, $this->mails, 'A new episode alerts again.' );
	}

	public function test_an_unreadable_or_stale_health_file_counts_as_down_and_an_old_server_is_not_judged_at_once(): void {
		$r   = $this->ready_db();
		$now = time();

		DbMonitor::apply( $r, null, $now );
		DbMonitor::apply( $r, null, $now + 700 );
		$this->assertCount( 1, $this->to_admin() );
		$this->assertStringNotContainsString( 'Abcdef', (string) $this->to_admin()[0]['message'] );

		DbMonitor::apply( $r, $this->reading( $now + 1000 ), $now + 1000 );
		DbMonitor::apply( $r, $this->reading( $now + 1100, array( 'updated' => $now - 3600 ) ), $now + 1100 );
		DbMonitor::apply( $r, $this->reading( $now + 1900, array( 'updated' => $now - 3600 ) ), $now + 1900 );
		$this->assertCount( 2, $this->to_admin(), 'A file that is not rewritten is down too.' );
	}

	public function test_the_customer_down_email_follows_the_site_switch_but_the_admin_is_always_told(): void {
		$this->settings( array( 'monitor_alerts' => 'off' ) );
		$r   = $this->ready_db();
		$now = time();

		DbMonitor::apply( $r, $this->reading( $now, array( 'ok' => false ) ), $now );
		DbMonitor::apply( $r, $this->reading( $now + 700, array( 'ok' => false ) ), $now + 700 );

		$this->assertCount( 1, $this->to_admin() );
		$this->assertCount( 0, $this->to_customer() );
	}

	public function test_disk_alerts_at_85_percent_once_and_rearms_below_80(): void {
		$r   = $this->ready_db();
		$now = time();

		DbMonitor::apply( $r, $this->reading( $now, array( 'disk_percent' => 84.9 ) ), $now );
		$this->assertCount( 0, $this->mails );

		DbMonitor::apply( $r, $this->reading( $now + 900, array( 'disk_percent' => 85.0 ) ), $now + 900 );
		$this->assertCount( 1, $this->to_admin() );
		$this->assertCount( 1, $this->to_customer() );
		$this->assertSame( 'Your database disk is 85 percent full', $this->to_customer()[0]['subject'] );

		DbMonitor::apply( $r, $this->reading( $now + 1800, array( 'disk_percent' => 92 ) ), $now + 1800 );
		DbMonitor::apply( $r, $this->reading( $now + 2700, array( 'disk_percent' => 81 ) ), $now + 2700 );
		DbMonitor::apply( $r, $this->reading( $now + 3600, array( 'disk_percent' => 86 ) ), $now + 3600 );
		$this->assertCount( 2, $this->mails, 'Between 80 and 85 does not re-arm.' );

		DbMonitor::apply( $r, $this->reading( $now + 4500, array( 'disk_percent' => 79.9 ) ), $now + 4500 );
		DbMonitor::apply( $r, $this->reading( $now + 5400, array( 'disk_percent' => 90 ) ), $now + 5400 );
		$this->assertCount( 4, $this->mails );
	}

	public function test_the_customer_usage_switch_stops_the_disk_email_not_the_admin_one(): void {
		$r = $this->ready_db();
		$r->set( array( 'usage_alerts' => 'off' ) );
		$now = time();

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now, array( 'disk_percent' => 90 ) ), $now );

		$this->assertCount( 1, $this->to_admin() );
		$this->assertCount( 0, $this->to_customer() );
	}

	public function test_an_old_dump_alerts_the_admin_only_and_rearms_with_a_new_dump(): void {
		$r   = $this->ready_db();
		$now = time() + 3 * DAY_IN_SECONDS;
		$r->set( array( 'db_ready_at' => time() ) );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now, array( 'last_dump' => $now - 36 * HOUR_IN_SECONDS ) ), $now );
		$this->assertCount( 0, $this->mails, '36 hours is still fine.' );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now + 60, array( 'last_dump' => $now - 36 * HOUR_IN_SECONDS ) ), $now + 60 );
		$this->assertCount( 1, $this->to_admin() );
		$this->assertCount( 0, $this->to_customer() );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now + 900, array( 'last_dump' => $now - 36 * HOUR_IN_SECONDS ) ), $now + 900 );
		$this->assertCount( 1, $this->mails, 'Deduped.' );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now + 1800, array( 'last_dump' => $now + 1000 ) ), $now + 1800 );
		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now + 1800 + 40 * HOUR_IN_SECONDS, array( 'last_dump' => $now + 1000 ) ), $now + 1800 + 40 * HOUR_IN_SECONDS );
		$this->assertCount( 2, $this->mails, 'Re-armed.' );
	}

	public function test_a_database_that_has_never_dumped_is_judged_from_the_time_it_became_ready(): void {
		$r = $this->ready_db();
		$r->set( array( 'db_ready_at' => time() - 20 * HOUR_IN_SECONDS ) );
		$now = time();

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now, array( 'last_dump' => null ) ), $now );
		$this->assertCount( 0, $this->mails );

		DbMonitor::apply( Resource::find( $r->id() ), $this->reading( $now + 20 * HOUR_IN_SECONDS, array( 'last_dump' => 0 ) ), $now + 20 * HOUR_IN_SECONDS );
		$this->assertCount( 1, $this->to_admin() );
		$this->assertStringContainsString( 'none', (string) $this->to_admin()[0]['message'] );
	}

	public function test_a_certificate_ending_within_30_days_alerts_the_admin_once_and_rearms_after_renewal(): void {
		$r   = $this->ready_db();
		$now = time();

		DbMonitor::apply( $r, $this->reading( $now, array( 'cert_expires' => $now + 31 * DAY_IN_SECONDS ) ), $now );
		$this->assertCount( 0, $this->mails );

		DbMonitor::apply( $r, $this->reading( $now + 900, array( 'cert_expires' => $now + 30 * DAY_IN_SECONDS ) ), $now + 900 );
		$this->assertCount( 1, $this->to_admin() );
		$this->assertCount( 0, $this->to_customer() );

		DbMonitor::apply( $r, $this->reading( $now + 1800, array( 'cert_expires' => $now + 29 * DAY_IN_SECONDS ) ), $now + 1800 );
		$this->assertCount( 1, $this->mails );

		DbMonitor::apply( $r, $this->reading( $now + 2700, array( 'cert_expires' => $now + 400 * DAY_IN_SECONDS ) ), $now + 2700 );
		DbMonitor::apply( $r, $this->reading( $now + 3600, array( 'cert_expires' => $now + 10 * DAY_IN_SECONDS ) ), $now + 3600 );
		$this->assertCount( 2, $this->mails );
	}

	public function test_many_bans_alert_the_admin_at_the_setting_default_fifty(): void {
		$r   = $this->ready_db();
		$now = time();

		$this->assertSame( 50, DbMonitor::bans_limit() );
		DbMonitor::apply( $r, $this->reading( $now, array( 'bans' => 49 ) ), $now );
		$this->assertCount( 0, $this->mails );

		DbMonitor::apply( $r, $this->reading( $now + 900, array( 'bans' => 50 ) ), $now + 900 );
		DbMonitor::apply( $r, $this->reading( $now + 1800, array( 'bans' => 80 ) ), $now + 1800 );
		$this->assertCount( 1, $this->to_admin() );
		$this->assertCount( 0, $this->to_customer() );

		DbMonitor::apply( $r, $this->reading( $now + 2700, array( 'bans' => 3 ) ), $now + 2700 );
		DbMonitor::apply( $r, $this->reading( $now + 3600, array( 'bans' => 60 ) ), $now + 3600 );
		$this->assertCount( 2, $this->mails );

		$this->settings( array( 'monitor_db_bans' => 5 ) );
		$this->assertSame( 5, DbMonitor::bans_limit() );
	}

	public function test_the_check_reads_through_the_agent_only_for_a_running_ready_database_and_never_while_restoring(): void {
		$r = $this->ready_db();
		$this->health( array( 'ok' => false ) );
		$now = time();

		$reads = count( $this->calls( 'GET', '/agent/file-read' ) );
		DbMonitor::check( $r, $this->fake, 'node-a', 5000, 'stopped', $now );
		$this->assertCount( $reads, $this->calls( 'GET', '/agent/file-read' ), 'A stopped server is not read.' );

		$r->set( array( 'was_running' => true ) );
		DbMonitor::check( Resource::find( $r->id() ), $this->fake, 'node-a', 5000, 'running', $now );
		$this->assertCount( $reads, $this->calls( 'GET', '/agent/file-read' ), 'A restore is not judged.' );
		$r->set( array( 'was_running' => null, 'restore' => array( 'volid' => 'x' ) ) );
		DbMonitor::check( Resource::find( $r->id() ), $this->fake, 'node-a', 5000, 'running', $now );
		$this->assertCount( $reads, $this->calls( 'GET', '/agent/file-read' ) );
		$r->set( array( 'restore' => null, 'db_state' => 'installing' ) );
		DbMonitor::check( Resource::find( $r->id() ), $this->fake, 'node-a', 5000, 'running', $now );
		$this->assertCount( $reads, $this->calls( 'GET', '/agent/file-read' ) );

		$r->set( array( 'db_state' => 'ready' ) );
		DbMonitor::check( Resource::find( $r->id() ), $this->fake, 'node-a', 5000, 'running', $now );
		$this->assertCount( $reads + 1, $this->calls( 'GET', '/agent/file-read' ) );
		$this->assertSame( $now, (int) ( (array) Resource::find( $r->id() )->get( DbMonitor::META ) )['down_since'] );
	}

	public function test_a_reading_is_remembered_for_the_page_and_the_dump_line_needs_no_proxmox_call(): void {
		$r = $this->ready_db();
		$this->health( array( 'last_dump' => 1761800000 ) );
		DbMonitor::check( $r, $this->fake, 'node-a', 5000, 'running' );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 1761800000, (int) $fresh->get( 'db_last_dump' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'db_health_at' ) );

		$seen = count( $this->fake->calls() );
		DbCard::view( $fresh );
		$this->assertCount( $seen, $this->fake->calls(), 'The card reads stored data only.' );
	}

	public function test_the_power_job_runs_the_database_monitor(): void {
		$r = $this->ready_db();
		$this->health( array( 'ok' => false ) );
		$this->assertTrue( Resource::find( $r->id() )->set( array( 'state' => 'ready' ) ) );

		ProxmoxJobs::power();

		$this->assertGreaterThan( 0, (int) ( (array) Resource::find( $r->id() )->get( DbMonitor::META ) )['down_since'] );
	}
}

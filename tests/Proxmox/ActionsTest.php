<?php
/**
 * The customer's actions and the VM panels. Only the fake client is used: nothing here reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\Panel;
use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A fake cluster that shows the raw parameters of every call to a callback (the recorded calls hide passwords).
 */
class SpyClient extends FakeClient {

	/**
	 * Called with ( method, path, params ) before the request is handled.
	 *
	 * @var callable|null
	 */
	public $spy = null;

	public function request( string $method, string $path, array $params = array() ) {
		if ( $this->spy ) {
			( $this->spy )( $method, $path, $params );
		}

		return parent::request( $method, $path, $params );
	}
}

/**
 * Actions, their checks and the panels, against the fake cluster.
 */
class ActionsTest extends TestCase {

	private const SECRET = 'secret-secret-secret-1';

	/**
	 * The fake cluster.
	 *
	 * @var SpyClient
	 */
	private $fake;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5 ) );

		$this->fake = new SpyClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) {
				$drivers['proxmox'] = new ProxmoxDriver();
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );
		$this->seed_capacity();

		foreach ( array(
			9000 => 'ubuntu-26-template',
			9001 => 'ubuntu-24-template',
		) as $vmid => $name ) {
			$this->fake->add_vm(
				array(
					'vmid'     => $vmid,
					'node'     => 'node-a',
					'name'     => $name,
					'template' => 1,
					'config'   => array(
						'scsi0'  => 'disk-store:vm-' . $vmid . '-disk-0,size=10G',
						'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
						'agent'  => '1',
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
					'label'             => 'Lab cluster',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => self::SECRET,
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'backup_storage'    => 'backup-store',
					'bridge'            => 'vmbr9',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => "9000 = Ubuntu 26.04\n9001 = Ubuntu 24.04",
					'default_template'  => '9000',
					'block_smtp'        => '1',
				)
			)
		);

		foreach ( range( 10, 24 ) as $last_octet ) {
			IpPool::add( '192.0.2.' . $last_octet );
		}

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		$this->wipe_tables();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function wipe_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * A ready, running VM and its owner.
	 *
	 * @param array<string, mixed> $meta Initial meta.
	 * @param array<string, mixed> $plan Plan overrides (the product's plan fields).
	 * @return array{0: Resource, 1: int}
	 */
	private function ready( array $meta = array(), array $plan = array() ): array {
		$product  = $this->service_product( 'proxmox', array_merge( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), $plan ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		return array( $fresh, (int) $license['user_id'] );
	}

	/**
	 * Calls matching a method and a path ending.
	 *
	 * @param string $method Method.
	 * @param string $suffix Path ending.
	 * @return array<int, array<string, mixed>>
	 */
	private function calls( string $method, string $suffix ): array {
		return array_values(
			array_filter(
				$this->fake->calls(),
				static fn( $call ) => $call['method'] === $method && substr( $call['path'], -strlen( $suffix ) ) === $suffix
			)
		);
	}

	private function act( Resource $r, string $action, array $args = array() ): void {
		Jobs::act( $r->id(), $action, $args );
	}

	/**
	 * The action route, as the panel script calls it.
	 *
	 * @param int                  $id     Resource id.
	 * @param int                  $user   User id.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	private function route( int $id, int $user, string $action, array $args = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/actions/' . $action );
		$request->set_param( 'args', $args );

		return rest_get_server()->dispatch( $request );
	}

	private function rebuild_to_ready( int $id ): void {
		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $id )->get( 'state' ); $i++ ) {
			Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $id );
			$this->run_jobs();
		}
	}

	// -- Reinstall ---------------------------------------------------------------------------------

	public function test_reinstall_starts_domain_release_after_storing_meta_but_plain_transition_does_not(): void {
		list( $r ) = $this->ready();
		$r->set( array( 'app_domain_old' => array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 ) ) ) );
		$this->assertFalse( as_has_scheduled_action( \BeltoftServices\Proxmox\AppDomainRelease::HOOK, array( $r->id() ), Jobs::GROUP ) );
		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL', 'os' => '9001' ) );
		$this->assertSame( 'provisioning', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertNotFalse( as_has_scheduled_action( \BeltoftServices\Proxmox\AppDomainRelease::HOOK, array( $r->id() ), Jobs::GROUP ) );
		list( $other ) = $this->ready();
		$other->set( array( 'app_domain_old' => array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.11', 'tries' => 0 ) ) ) );
		$this->assertTrue( $other->transition( 'provisioning', 'Direct transition' ) );
		$this->assertFalse( as_has_scheduled_action( \BeltoftServices\Proxmox\AppDomainRelease::HOOK, array( $other->id() ), Jobs::GROUP ) );
	}

	public function test_medusa_reinstall_ends_ready_on_the_same_vmid_and_ip(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );
		$ip        = (string) $r->get( 'ip' );
		$old_uuid  = $this->fake->vm( $vmid )['config']['description'] ?? '';

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL', 'os' => '9001' ) );

		$this->assertSame( 'provisioning', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertTrue( (bool) Resource::find( $r->id() )->get( 'wipe' ) );

		$this->rebuild_to_ready( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( $vmid, (int) $fresh->get( 'vmid' ), 'The same VMID.' );
		$this->assertSame( $ip, (string) $fresh->get( 'ip' ), 'The same address.' );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertSame( 9001, (int) $fresh->get( 'os_template' ) );
		$this->assertNotSame( '', $old_uuid );

		$clones = $this->calls( 'POST', '/clone' );
		$this->assertSame( '/nodes/node-a/qemu/9001/clone', end( $clones )['path'], 'The chosen system was cloned.' );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'last_reinstall_at' ) );
	}

	public function test_reinstall_wipe_purges_and_destroys_unreferenced_disks(): void {
		list( $r ) = $this->ready();

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild_to_ready( $r->id() );

		$deletes = $this->calls( 'DELETE', '/qemu/' . $r->get( 'vmid' ) );
		$this->assertCount( 1, $deletes );
		$this->assertSame( 1, (int) $deletes[0]['params']['purge'] );
		$this->assertSame( 1, (int) $deletes[0]['params']['destroy-unreferenced-disks'] );
	}

	public function test_reinstall_gives_a_new_password_stored_before_it_is_sent(): void {
		list( $r, $owner ) = $this->ready();
		$first             = Access::take_reveal( Resource::find( $r->id() ), $owner );
		$this->assertNotNull( $first );

		// An unread password of the first build must not be reused either.
		$second_before = Access::reset_password( Resource::find( $r->id() ) );
		Access::finish_reset( Resource::find( $r->id() ) );

		$seen = array();
		$this->fake->spy = function ( $method, $path, $params ) use ( $r, &$seen ) {
			if ( 'PUT' === $method && isset( $params['cipassword'] ) ) {
				$stored = (string) Resource::find( $r->id() )->get( 'reveal' );
				$seen[] = array(
					'sent'   => $params['cipassword'],
					'stored' => '' === $stored ? null : Crypto::decrypt( $stored ),
				);
			}
		};

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'reveal' ), 'The old password is gone at once.' );

		$this->rebuild_to_ready( $r->id() );
		$this->fake->spy = null;

		$this->assertCount( 1, $seen );
		$this->assertSame( $seen[0]['sent'], $seen[0]['stored'], 'Stored encrypted before the call that sends it.' );
		$this->assertNotSame( $first, $seen[0]['sent'] );
		$this->assertNotSame( $second_before, $seen[0]['sent'] );
		$this->assertSame( $seen[0]['sent'], Access::take_reveal( Resource::find( $r->id() ), $owner ) );
	}

	public function test_reinstall_needs_confirmation_and_rate_limit(): void {
		list( $r, $owner ) = $this->ready();

		$refused = $this->route( $r->id(), $owner, 'reinstall', array() );
		$this->assertSame( 422, $refused->get_status() );
		$this->assertStringContainsString( 'REINSTALL', $refused->get_data()['message'] );

		$this->assertSame( 422, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'reinstall' ) )->get_status(), 'Case matters.' );
		$this->assertSame( 422, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL', 'os' => '9999' ) )->get_status(), 'An unlisted system.' );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ), 'Nothing was queued.' );

		$this->assertSame( 202, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL' ) )->get_status() );
		$this->run_jobs();
		$this->rebuild_to_ready( $r->id() );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );

		// The limit lives in the resource, so a flushed cache and cleared transients do not lift it.
		wp_cache_flush();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%beltoft_services%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$again = $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->assertSame( 422, $again->get_status() );
		$this->assertStringContainsString( 'once an hour', $again->get_data()['message'] );

		Resource::find( $r->id() )->set( array( 'last_reinstall_at' => time() - HOUR_IN_SECONDS - 5 ) );
		$this->assertSame( 202, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL' ) )->get_status() );
	}

	public function test_reinstall_missing_confirmation_is_refused_without_alert(): void {
		list( $r ) = $this->ready();
		$seen      = count( $this->fake->calls() );
		$this->mails = array();

		$this->act( $r, 'reinstall', array() );
		$this->act( $r, 'reinstall', array( 'confirm' => 'yes' ) );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertFalse( (bool) Resource::find( $r->id() )->get( 'wipe' ) );
		$this->assertCount( $seen, $this->fake->calls(), 'Not one Proxmox call.' );
		$this->assertSame( array(), $this->mails, 'The admin is not alerted.' );
		$this->assertArrayNotHasKey( $r->id(), Alerts::problems() );

		$events = wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) );
		$this->assertStringContainsString( 'Reinstall refused', (string) $events );
	}

	public function test_reinstall_sends_no_second_ready_email(): void {
		list( $r ) = $this->ready();
		$ready     = count( $this->mails );
		$this->assertGreaterThanOrEqual( 1, $ready, 'The first Ready email went out.' );

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild_to_ready( $r->id() );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertCount( $ready, $this->mails );
	}

	// -- Reset password ------------------------------------------------------------------------------

	public function test_reset_password_stores_before_sending_and_reveals_once(): void {
		list( $r, $owner ) = $this->ready();
		Access::take_reveal( Resource::find( $r->id() ), $owner ); // The first password is read.
		$agent_before = count( $this->calls( 'POST', '/agent/set-user-password' ) );

		$seen = array();
		$this->fake->spy = function ( $method, $path, $params ) use ( $r, &$seen ) {
			if ( isset( $params['cipassword'] ) || isset( $params['password'] ) ) {
				$fresh  = Resource::find( $r->id() );
				$stored = (string) $fresh->get( 'reveal' );
				$seen[] = array(
					'path'    => $path,
					'sent'    => $params['cipassword'] ?? $params['password'],
					'stored'  => '' === $stored ? null : Crypto::decrypt( $stored ),
					'pending' => (bool) $fresh->get( 'password_reset' ),
				);
			}
		};

		$this->act( $r, 'reset_password' );
		$this->fake->spy = null;

		$this->assertCount( 2, $seen, 'cipassword and the guest agent.' );

		foreach ( $seen as $one ) {
			$this->assertSame( $one['sent'], $one['stored'], 'Stored before it is sent: ' . $one['path'] );
			$this->assertTrue( $one['pending'], 'Marked not yet applied while it is sent.' );
		}

		$this->assertSame( $seen[0]['sent'], $seen[1]['sent'] );

		// Only cipassword is written: never ipconfig0, cicustom or any other cloud-init value.
		$put = $this->calls( 'PUT', '/qemu/' . $r->get( 'vmid' ) . '/config' );
		$this->assertSame( array( 'cipassword' ), array_keys( end( $put )['params'] ) );
		$this->assertCount( $agent_before + 1, $this->calls( 'POST', '/agent/set-user-password' ) );
		$this->assertSame( 'root', $this->calls( 'POST', '/agent/set-user-password' )[ $agent_before ]['params']['username'] );

		// Revealed once, by the owner only.
		$this->assertNull( Access::take_reveal( Resource::find( $r->id() ), $owner + 999 ) );
		$response = $this->reveal( $r->id(), $owner );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $seen[0]['sent'], $response->get_data()['password'] );
		$this->assertSame( 409, $this->reveal( $r->id(), $owner )->get_status() );

		$haystack = (string) wp_json_encode( array( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), $this->fake->calls(), $this->mails, Resource::find( $r->id() )->get( 'last_error' ) ) );
		$this->assertStringNotContainsString( $seen[0]['sent'], $haystack );
	}

	private function reveal( int $id, int $user ): \WP_REST_Response {
		wp_set_current_user( $user );

		return rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/reveal' ) );
	}

	public function test_a_reset_password_the_agent_did_not_take_is_not_revealed_and_the_retry_reuses_it(): void {
		list( $r, $owner ) = $this->ready();
		Access::take_reveal( Resource::find( $r->id() ), $owner );
		$this->fake->fail_next( 'POST', '#/agent/set-user-password$#', array( 'status' => 500, 'message' => 'QEMU guest agent is not running' ) );

		$sent = array();
		$this->fake->spy = static function ( $method, $path, $params ) use ( &$sent ) {
			if ( 'PUT' === $method && isset( $params['cipassword'] ) ) {
				$sent[] = $params['cipassword'];
			}
		};

		$this->act( $r, 'reset_password' );
		$this->assertTrue( (bool) Resource::find( $r->id() )->get( 'password_reset' ), 'Not applied yet.' );
		$this->assertSame( 409, $this->reveal( $r->id(), $owner )->get_status(), 'A password the VM does not have is not shown.' );
		$this->assertFalse( Access::revealable( Resource::find( $r->id() ) ) );

		Resource::find( $r->id() )->set( array( 'act_attempts' => 0 ) );
		$this->run_jobs();
		$this->act( $r, 'reset_password' );
		$this->fake->spy = null;

		$this->assertGreaterThanOrEqual( 2, count( $sent ) );
		$this->assertSame( array( $sent[0] ), array_values( array_unique( $sent ) ), 'The same password on the retry.' );
		$this->assertFalse( (bool) Resource::find( $r->id() )->get( 'password_reset' ) );
		$this->assertSame( $sent[0], $this->reveal( $r->id(), $owner )->get_data()['password'] );
	}

	public function test_reset_password_on_a_stopped_vm_stores_cipassword_only(): void {
		list( $r, $owner ) = $this->ready();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
		$agent = count( $this->calls( 'POST', '/agent/set-user-password' ) );

		$this->act( $r, 'reset_password' );

		$this->assertCount( $agent, $this->calls( 'POST', '/agent/set-user-password' ), 'A stopped VM has no agent to ask.' );
		$this->assertNotEmpty( $this->calls( 'PUT', '/config' ) );
		$this->assertTrue( Access::revealable( Resource::find( $r->id() ) ) );
	}

	// -- SSH key -------------------------------------------------------------------------------------

	private static function key(): string {
		$type = 'ssh-ed25519';
		$str  = static fn( string $s ) => pack( 'N', strlen( $s ) ) . $s;

		return $type . ' ' . base64_encode( $str( $type ) . $str( random_bytes( 32 ) ) ) . ' me@host'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_replace_ssh_key_validated(): void {
		list( $r, $owner ) = $this->ready();

		foreach ( array( '', 'not a key', 'ssh-rsa AAAA' ) as $bad ) {
			$answer = $this->route( $r->id(), $owner, 'replace_ssh_key', array( 'key' => $bad ) );
			$this->assertSame( 422, $answer->get_status() );
			$this->assertStringNotContainsString( 'not a key', wp_json_encode( $answer->get_data() ) );
		}

		$puts = count( $this->calls( 'PUT', '/config' ) );
		$this->act( $r, 'replace_ssh_key', array( 'key' => 'garbage' ) );
		$this->assertCount( $puts, $this->calls( 'PUT', '/config' ), 'A bad key reaches no call.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'ssh_key' ) );

		$good = self::key();
		$this->assertSame( 202, $this->route( $r->id(), $owner, 'replace_ssh_key', array( 'key' => $good ) )->get_status() );
		$this->run_jobs();

		$clean = \BeltoftServices\Proxmox\SshKeyField::normalise( $good );
		$put   = $this->calls( 'PUT', '/qemu/' . $r->get( 'vmid' ) . '/config' );
		$this->assertSame( array( 'sshkeys' ), array_keys( end( $put )['params'] ), 'Only the key is written.' );
		$this->assertSame( '***', end( $put )['params']['sshkeys'] );
		$this->assertSame( $clean, Resource::find( $r->id() )->get( 'ssh_key' ) );
		$this->assertSame( 'Replace SSH key', Actions::offered( Resource::find( $r->id() ) )['replace_ssh_key'] );
	}

	public function test_replace_ssh_key_on_a_running_vm_replaces_authorized_keys_through_the_guest_agent(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );
		$old       = self::key();
		$new       = self::key();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $vmid . '/agent/file-write', array( 'file' => '/root/.ssh/authorized_keys', 'content' => $old . "\n" ) );

		$this->act( $r, 'replace_ssh_key', array( 'key' => $new ) );

		$clean = \BeltoftServices\Proxmox\SshKeyField::normalise( $new );
		$this->assertSame( $clean . "\n", $this->fake->guest_file( $vmid, '/root/.ssh/authorized_keys' ), 'The file holds the new key only.' );
		$this->assertStringNotContainsString( explode( ' ', $old )[1], (string) $this->fake->guest_file( $vmid, '/root/.ssh/authorized_keys' ) );
		$this->assertNotEmpty( $this->calls( 'PUT', '/qemu/' . $vmid . '/config' ), 'The cloud-init key is updated too.' );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertSame( $clean, Resource::find( $r->id() )->get( 'ssh_key' ) );
		$this->assertContains( 'Customer changed the SSH key', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), 'message' ) );
	}

	public function test_replace_ssh_key_on_a_stopped_vm_only_updates_the_cloud_init_key_and_says_so(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $vmid . '/status/stop' );
		$files = count( $this->calls( 'POST', '/agent/file-write' ) );

		$this->act( $r, 'replace_ssh_key', array( 'key' => self::key() ) );

		$this->assertCount( $files, $this->calls( 'POST', '/agent/file-write' ), 'A stopped VM has no agent.' );
		$this->assertNotEmpty( $this->calls( 'PUT', '/qemu/' . $vmid . '/config' ) );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$messages = implode( ' | ', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), 'message' ) );
		$this->assertStringContainsString( 'applies when the server next starts', $messages );
	}

	public function test_replace_ssh_key_denied_by_proxmox_names_the_privilege_and_keeps_the_config_key(): void {
		list( $r ) = $this->ready();
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$vmid        = (int) $r->get( 'vmid' );
		$this->fake->fail_next( 'POST', '#/agent/file-write$#', array( 'status' => 403, 'message' => 'Permission check failed (/vms/' . $vmid . ', VM.GuestAgent.FileWrite|VM.GuestAgent.Unrestricted)' ) );

		$this->act( $r, 'replace_ssh_key', array( 'key' => self::key() ) );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ), 'The service stays ready.' );
		$this->assertNotEmpty( $this->calls( 'PUT', '/qemu/' . $vmid . '/config' ) );
		$this->assertNotEmpty( $this->mails, 'The admin is told.' );
		$this->assertStringContainsString( 'VM.GuestAgent.FileWrite', (string) $this->mails[0]['message'] );
		$messages = implode( ' | ', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), 'message' ) );
		$this->assertStringContainsString( 'VM.GuestAgent.FileWrite', $messages );
	}

	public function test_replace_ssh_key_retries_when_the_agent_does_not_answer(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );
		$new       = self::key();
		$this->fake->fail_next( 'POST', '#/agent/file-write$#', array( 'status' => 500, 'message' => 'QEMU guest agent is not running' ) );

		$result = ( new Actions( Resource::find( $r->id() ) ) )->run( 'replace_ssh_key', array( 'key' => $new ) );
		$this->assertTrue( $result->is_retry() );
		$this->assertNull( $this->fake->guest_file( $vmid, '/root/.ssh/authorized_keys' ) );

		$result = ( new Actions( Resource::find( $r->id() ) ) )->run( 'replace_ssh_key', array( 'key' => $new ) );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( \BeltoftServices\Proxmox\SshKeyField::normalise( $new ) . "\n", $this->fake->guest_file( $vmid, '/root/.ssh/authorized_keys' ) );
	}

	public function test_replace_ssh_key_on_a_vm_that_is_not_ours_writes_nothing(): void {
		list( $r ) = $this->ready();
		$this->break_ownership( $r, 'tag' );
		$seen = count( $this->fake->calls() );

		$result = ( new Actions( Resource::find( $r->id() ) ) )->run( 'replace_ssh_key', array( 'key' => self::key() ) );

		$this->assertTrue( $result->is_failed() );

		foreach ( array_slice( $this->fake->calls(), $seen ) as $call ) {
			$this->assertSame( 'GET', $call['method'], $call['method'] . ' ' . $call['path'] );
		}
	}

	public function test_a_resource_without_a_key_offers_add_ssh_key(): void {
		list( $r ) = $this->ready();

		$this->assertSame( 'Add SSH key', Actions::offered( $r )['replace_ssh_key'] );
	}

	// -- Power ---------------------------------------------------------------------------------------

	public function test_start_only_offered_when_stopped(): void {
		list( $r ) = $this->ready();

		$running = array_keys( Actions::offered( Resource::find( $r->id() ) ) );
		$this->assertNotContains( 'start', $running );
		$this->assertContains( 'shutdown', $running );
		$this->assertContains( 'reboot', $running );

		$this->act( $r, 'shutdown' );
		$this->assertSame( 'stopped', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );

		$stopped = array_keys( Actions::offered( Resource::find( $r->id() ) ) );
		$this->assertContains( 'start', $stopped );
		$this->assertNotContains( 'shutdown', $stopped );
		$this->assertNotContains( 'reboot', $stopped );

		$this->act( $r, 'start' );
		$this->assertSame( 'running', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );
		$this->assertNotContains( 'start', array_keys( Actions::offered( Resource::find( $r->id() ) ) ) );

		// Unknown power: everything is offered and each action re-reads the VM.
		delete_transient( \BeltoftServices\Proxmox\Operations::STATUS_PREFIX . $r->id() );
		$this->assertContains( 'start', array_keys( Actions::offered( Resource::find( $r->id() ) ) ) );
	}

	public function test_shutdown_is_graceful_and_a_system_that_stays_up_is_not_plugged_out(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );

		$this->fake->next_task( array( 'exitstatus' => 'VM quit/powerdown failed - got timeout', 'effect' => false ) );
		$this->act( $r, 'shutdown' );

		$this->assertEmpty( $this->calls( 'POST', '/status/stop' ), 'Never a plug pull for a customer.' );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertNotEmpty( $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertArrayNotHasKey( $r->id(), Alerts::problems(), 'Not an error for the shop.' );

		$starts = count( $this->calls( 'POST', '/status/start' ) );
		$this->act( $r, 'start' ); // Already running: idempotent.
		$this->assertCount( $starts, $this->calls( 'POST', '/status/start' ) );
	}

	public function test_reboot_needs_a_running_server(): void {
		list( $r ) = $this->ready();
		$this->act( $r, 'reboot' );
		$this->assertCount( 1, $this->calls( 'POST', '/status/reboot' ) );

		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
		$this->act( $r, 'reboot' );
		$this->assertCount( 1, $this->calls( 'POST', '/status/reboot' ), 'A stopped server is not rebooted.' );
	}

	// -- Refusals ------------------------------------------------------------------------------------

	public function test_actions_refused_when_not_ready(): void {
		foreach ( array( 'paused', 'provisioning', 'failed', 'deleting' ) as $state ) {
			list( $r, $owner ) = $this->ready();
			$r->set( array( 'state' => $state ) );
			$seen = count( $this->fake->calls() );

			foreach ( array( 'start', 'shutdown', 'reboot', 'reset_password', 'replace_ssh_key', 'reinstall' ) as $action ) {
				$response = $this->route( $r->id(), $owner, $action, array( 'key' => self::key(), 'confirm' => 'REINSTALL' ) );
				$this->assertSame( 403, $response->get_status(), $state . ' ' . $action );
				$this->act( $r, $action, array( 'key' => self::key(), 'confirm' => 'REINSTALL' ) );
			}

			$this->assertCount( $seen, $this->fake->calls(), 'No Proxmox call while ' . $state );
		}
	}

	public function test_actions_refuse_visitors_strangers_and_other_customers_resources(): void {
		list( $r, $owner ) = $this->ready();
		$stranger          = self::factory()->user->create();

		$this->assertSame( 401, $this->route( $r->id(), 0, 'reboot' )->get_status() );
		$this->assertSame( 403, $this->route( $r->id(), $stranger, 'reboot' )->get_status() );
		$this->assertSame( 403, $this->route( $r->id() + 999, $owner, 'reboot' )->get_status() );
		$this->assertSame( 403, $this->route( $r->id(), $owner, 'nonsense' )->get_status() );
		$this->assertEmpty( $this->calls( 'POST', '/status/reboot' ) );
	}

	public function test_the_route_needs_the_rest_nonce(): void {
		list( $r, $owner ) = $this->ready();
		wp_set_current_user( $owner );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		// WordPress's own cookie check: a cookie session without a nonce is an anonymous request, which the route refuses.
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$result                         = rest_cookie_check_errors( null );
		unset( $GLOBALS['wp_rest_auth_cookie'] );

		$this->assertTrue( $result );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->route( $r->id(), 0, 'reboot' )->get_status() );
		$this->assertEmpty( $this->calls( 'POST', '/status/reboot' ) );
	}

	public function test_actions_have_a_rate_limit(): void {
		list( $r, $owner ) = $this->ready();
		$statuses          = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$statuses[] = $this->route( $r->id(), $owner, 'reset_password' )->get_status();
		}

		$this->assertSame( 202, $statuses[0] );
		$this->assertContains( 429, $statuses );
	}

	public function test_sensitive_actions_keep_a_floor_when_the_route_limit_is_turned_off(): void {
		list( $r, $owner ) = $this->ready();
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		$statuses = array();

		for ( $i = 0; $i < 7; $i++ ) {
			$statuses[] = $this->route( $r->id(), $owner, 'reset_password' )->get_status();
		}

		remove_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );

		$this->assertSame( array( 202, 202, 202, 202, 202, 429, 429 ), $statuses, 'Five an hour for a password reset.' );
	}

	public function test_a_failed_action_never_shows_the_proxmox_error_to_the_customer(): void {
		list( $r, $owner ) = $this->ready();
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 500, 'message' => 'secret internal pve text: /etc/pve/qemu-server/5000.conf' ) );

		$response = $this->route( $r->id(), $owner, 'reset_password' );
		$this->assertStringNotContainsString( 'internal pve', (string) wp_json_encode( $response->get_data() ) );

		$this->run_jobs();
		$this->assertStringNotContainsString( 'internal pve', (string) wp_json_encode( Panel::message( 'ready' ) ) );
	}

	// -- Fix round 1 ---------------------------------------------------------------------------------

	public function test_reset_password_is_offered_only_when_the_guest_agent_is_known_to_work(): void {
		list( $r, $owner ) = $this->ready();
		$this->assertSame( 'ok', $r->get( 'agent' ) );
		$this->assertArrayHasKey( 'reset_password', Actions::offered( $r ) );
		$this->assertStringContainsString( 'data-action="reset_password"', $this->panel( $r, $owner ) );

		$r->set( array( 'agent' => 'unknown' ) );
		$r = Resource::find( $r->id() );
		$seen = count( $this->fake->calls() );

		$this->assertArrayNotHasKey( 'reset_password', Actions::offered( $r ) );
		$this->assertStringNotContainsString( 'data-action="reset_password"', $this->panel( $r, $owner ) );
		$this->assertSame( 403, $this->route( $r->id(), $owner, 'reset_password' )->get_status(), 'The route refuses it.' );

		// A job that was queued before the agent state changed is refused too, without an alert.
		$this->act( $r, 'reset_password' );
		$this->assertCount( $seen, $this->fake->calls() );
		$this->assertArrayNotHasKey( $r->id(), Alerts::problems() );

		// Dropped, but not silently: the history says so.
		$this->assertContains( 'Customer action "reset_password" was not run: it is not available any more', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), 'message' ) );
	}

	/**
	 * Makes the VM look like somebody else's, or out of range, and returns the state to compare.
	 *
	 * @param string $how tag or range.
	 */
	private function break_ownership( Resource $r, string $how ): void {
		if ( 'tag' === $how ) {
			$this->fake->request( 'PUT', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/config', array( 'description' => 'somebody else' ) );
			return;
		}

		$this->save_range( 6000, 6099 );
	}

	private function save_range( int $min, int $max ): void {
		$this->assertTrue( Clusters::save( 'lab', array_merge( Clusters::get( 'lab' ), array( 'token_secret' => '', 'nodes' => 'node-a', 'nameservers' => '192.0.2.53', 'vlan' => '', 'templates' => "9000 = Ubuntu 26.04\n9001 = Ubuntu 24.04", 'default_template' => '9000', 'vmid_min' => (string) $min, 'vmid_max' => (string) $max ) ) ) );
	}

	public function test_ownership_refusals_fail_without_a_write_for_reinstall_reset_password_and_shutdown(): void {
		foreach ( array( 'tag', 'range' ) as $how ) {
			foreach ( array( 'reinstall', 'reset_password', 'shutdown' ) as $action ) {
				list( $r ) = $this->ready();
				$this->break_ownership( $r, $how );
				$before = Resource::find( $r->id() );
				$steps  = $before->get( 'steps' );
				$seen   = count( $this->fake->calls() );

				$result = ( new Actions( $before ) )->run( $action, array( 'confirm' => 'REINSTALL' ) );

				$this->assertTrue( $result->is_failed(), $how . ' ' . $action );
				$this->assertStringContainsString( 'Ownership check failed', $result->error() );

				foreach ( array_slice( $this->fake->calls(), $seen ) as $call ) {
					$this->assertSame( 'GET', $call['method'], $how . ' ' . $action . ': ' . $call['method'] . ' ' . $call['path'] );
				}

				$after = Resource::find( $r->id() );
				$this->assertSame( 'ready', $after->get( 'state' ), 'State untouched.' );
				$this->assertSame( $steps, $after->get( 'steps' ), 'Steps untouched.' );
				$this->assertEmpty( $after->get( 'wipe' ) );
				$this->assertEmpty( $after->get( 'password_reset' ) );
				$this->assertSame( $before->get( 'reveal' ), $after->get( 'reveal' ), 'The password is untouched.' );
				$this->assertSame( 'running', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );

				// Restore the cluster range for the next round.
				if ( 'range' === $how ) {
					$this->save_range( 5000, 5099 );
				}
			}
		}
	}

	public function test_a_job_dropped_because_the_power_state_changed_leaves_an_event(): void {
		list( $r ) = $this->ready();
		$seen      = count( $this->fake->calls() );

		// The panel offered Shut down while the VM ran; by the time the job runs it is known to be off.
		\BeltoftServices\Proxmox\Operations::remember_power( $r->id(), 'stopped' );
		$this->act( $r, 'shutdown' );

		$this->assertCount( $seen, $this->fake->calls() );
		$events = (string) wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) );
		$this->assertStringContainsString( 'shutdown', $events );
		$this->assertStringContainsString( 'not available any more', $events );
	}

	public function test_the_reinstall_form_falls_back_to_the_cluster_default_when_the_current_os_is_gone(): void {
		list( $r, $owner ) = $this->ready();
		$r->set( array( 'os_template' => 9777 ) );

		$html = $this->panel( $r, $owner );

		$this->assertMatchesRegularExpression( '#<input[^>]*type="radio" name="os" value="9000" checked#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<input[^>]*type="radio" name="os" value="9001" checked#', $html );
	}

	public function test_a_permanent_cipassword_failure_restores_the_unread_first_password(): void {
		list( $r, $owner ) = $this->ready();
		$original          = Crypto::decrypt( (string) $r->get( 'reveal' ) );
		$this->assertNotEmpty( $original );

		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 400, 'message' => 'invalid parameter' ) );
		$result = ( new Actions( Resource::find( $r->id() ) ) )->run( 'reset_password', array() );

		$this->assertTrue( $result->is_failed() );
		$after = Resource::find( $r->id() );
		$this->assertEmpty( $after->get( 'password_reset' ), 'No half-applied reset is left.' );
		$this->assertSame( $original, Crypto::decrypt( (string) $after->get( 'reveal' ) ), 'The unread password is still there.' );
		$this->assertTrue( Access::revealable( $after ) );
		$this->assertSame( $original, Access::take_reveal( $after, $owner ) );
	}

	public function test_reset_password_has_a_limit_kept_in_the_resource(): void {
		list( $r, $owner ) = $this->ready();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->act( $r, 'reset_password' );
			$this->assertFalse( (bool) Resource::find( $r->id() )->get( 'password_reset' ) );
		}

		wp_cache_flush();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%beltoft_services%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$seen = count( $this->calls( 'PUT', '/config' ) );

		$answer = $this->route( $r->id(), $owner, 'reset_password' );
		$this->assertSame( 422, $answer->get_status() );
		$this->assertStringContainsString( 'three times an hour', $answer->get_data()['message'] );

		$this->act( $r, 'reset_password' );
		$this->assertCount( $seen, $this->calls( 'PUT', '/config' ), 'The job refuses it as well.' );

		// A retry of a reset in progress is not a new reset.
		$stamps = (array) Resource::find( $r->id() )->get( 'reset_times' );
		$this->assertCount( 3, $stamps );
		Resource::find( $r->id() )->set( array( 'reset_times' => array( time() - HOUR_IN_SECONDS - 5, time() - 10, time() - 5 ) ) );
		$this->assertSame( 202, $this->route( $r->id(), $owner, 'reset_password' )->get_status() );
	}

	// -- Panels --------------------------------------------------------------------------------------

	/**
	 * The panel as the owner sees it.
	 *
	 * @param Resource $r     Resource.
	 * @param int      $owner User.
	 */
	private function panel( Resource $r, int $owner ): string {
		wp_set_current_user( $owner );
		ob_start();
		Panel::render( $r->license() );

		return (string) ob_get_clean();
	}

	public function test_panel_hides_generic_buttons_for_actions_with_arguments(): void {
		list( $r, $owner ) = $this->ready();
		$html              = $this->panel( $r, $owner );

		foreach ( array( 'reboot', 'shutdown', 'reset_password' ) as $action ) {
			$this->assertStringContainsString( 'class="button woocommerce-button beltoft-services-action" data-action="' . $action . '"', $html );
		}

		foreach ( array( 'replace_ssh_key', 'reinstall' ) as $action ) {
			$this->assertStringNotContainsString( 'beltoft-services-action" data-action="' . $action . '"', $html, 'No generic button.' );
			$this->assertStringContainsString( '<form class="beltoft-services-vm-form" data-action="' . $action . '">', $html );
		}

		$this->assertStringContainsString( 'name="confirm"', $html );
		$this->assertStringContainsString( 'Add SSH key', $html );
		$this->assertStringContainsString( 'name="os"', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*type="radio" name="os" value="9000" checked#', $html, 'The current system is preselected.' );
	}

	public function test_reinstall_form_preselects_the_current_os(): void {
		list( $r, $owner ) = $this->ready( array( 'os_template' => 9001 ) );

		$this->assertMatchesRegularExpression( '#<input[^>]*type="radio" name="os" value="9001" checked#', $this->panel( $r, $owner ) );
		$this->assertDoesNotMatchRegularExpression( '#<input[^>]*type="radio" name="os" value="9000" checked#', $this->panel( $r, $owner ) );
	}

	public function test_panel_never_prints_the_password(): void {
		list( $r, $owner ) = $this->ready();
		$stored            = (string) $r->get( 'reveal' );
		$plain             = (string) Crypto::decrypt( $stored );
		$this->assertNotSame( '', $plain );

		$html = $this->panel( $r, $owner );

		$this->assertStringNotContainsString( $plain, $html );
		$this->assertStringNotContainsString( $stored, $html );
		$this->assertStringContainsString( 'data-vm-reveal', $html, 'The button that asks the server for it once.' );
		$this->assertStringContainsString( (string) $r->get( 'ip' ), $html );
		$this->assertStringContainsString( 'ssh root@' . $r->get( 'ip' ), $html );
		$this->assertStringContainsString( '2 vCPU, 4096 MB RAM, 30 GB disk', $html );

		// A read password has no button any more.
		Access::take_reveal( Resource::find( $r->id() ), $owner );
		$this->assertStringNotContainsString( 'data-vm-reveal', $this->panel( Resource::find( $r->id() ), $owner ) );

		// Nor does a password that is not on the VM yet.
		Access::reset_password( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'data-vm-reveal', $this->panel( Resource::find( $r->id() ), $owner ) );
	}

	public function test_render_account_makes_no_proxmox_call(): void {
		list( $r, $owner ) = $this->ready();
		$seen              = count( $this->fake->calls() );

		$html = $this->panel( $r, $owner );
		ob_start();
		( new ProxmoxDriver() )->render_admin( $r );
		$admin = (string) ob_get_clean();

		$this->assertNotSame( '', $html );
		$this->assertCount( $seen, $this->fake->calls() );
		$this->assertStringContainsString( 'beltoft-services:', $admin );
		$this->assertStringContainsString( (string) $r->get( 'vmid' ), $admin );
		$this->assertStringNotContainsString( (string) Crypto::decrypt( (string) $r->get( 'reveal' ) ), $admin );
	}

	public function test_the_panels_show_the_disk_of_the_current_plan_after_an_upgrade(): void {
		list( $r, $owner ) = $this->ready();
		$this->assertStringContainsString( '30 GB disk', $this->panel( $r, $owner ) );

		$plan = array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 60 ) );
		$this->assertTrue( Resource::find( $r->id() )->change_plan( $plan, 'Plan changed' ) );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$html  = $this->panel( $fresh, $owner );
		$this->assertStringContainsString( '60 GB disk', $html );
		$this->assertStringNotContainsString( '30 GB disk', $html );
		ob_start();
		( new ProxmoxDriver() )->render_admin( $fresh );
		$this->assertStringContainsString( '60 GB disk', (string) ob_get_clean() );
	}

	public function test_a_stale_stored_disk_never_hides_a_bigger_plan_disk(): void {
		list( $r, $owner ) = $this->ready();
		$plan              = array_merge( (array) $r->get( 'plan' ), array( 'disk_gb' => 60 ) );
		$r->set( array( 'plan' => $plan, 'disk_gb' => 30 ) );

		$html = $this->panel( Resource::find( $r->id() ), $owner );

		$this->assertStringContainsString( '60 GB disk', $html );
	}

	public function test_a_disk_bigger_than_the_plan_is_shown_as_it_is(): void {
		list( $r, $owner ) = $this->ready();
		$r->set( array( 'disk_gb' => 80 ) );

		$this->assertStringContainsString( '80 GB disk', $this->panel( Resource::find( $r->id() ), $owner ) );
	}

	public function test_the_panel_of_a_paused_service_has_no_forms_or_buttons(): void {
		list( $r, $owner ) = $this->ready();
		$r->set( array( 'state' => 'paused' ) );
		$r->desire( 'paused', 'test' );

		$html = $this->panel( Resource::find( $r->id() ), $owner );

		$this->assertStringNotContainsString( 'beltoft-services-vm-form', $html );
		$this->assertStringNotContainsString( 'beltoft-services-action"', $html );
	}

	public function test_the_vm_script_and_the_filters_are_registered(): void {
		$this->assertNotFalse( has_action( 'beltoft_services_account_assets' ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_account_buttons' ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_action_refusal' ) );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'templates/account/vm.php' );
	}

	// -- Restore -----------------------------------------------------------------------------------

	/**
	 * A backup of the VM now, through the fake's vzdump (it keeps the config it restores).
	 *
	 * @param Resource $r Resource.
	 * @return string The volume id.
	 */
	private function backup_of( Resource $r ): string {
		$upid = $this->fake->task( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => (int) $r->get( 'vmid' ), 'storage' => 'backup-store' ) );
		$this->assertIsString( $upid );

		$list = ( new \BeltoftServices\Proxmox\Backups( Resource::find( $r->id() ) ) )->list();
		$this->assertNotEmpty( $list );

		return $list[0]['volid'];
	}

	private function events_of( Resource $r ): string {
		return (string) wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) );
	}

	public function test_restore_is_offered_only_with_backups_and_never_for_a_linked_vm(): void {
		list( $with )    = $this->ready( array(), array( 'backup_days' => 7 ) );
		list( $without ) = $this->ready();

		$this->assertArrayHasKey( 'restore', Actions::offered( $with ) );
		$this->assertArrayNotHasKey( 'restore', Actions::offered( $without ) );

		$with->set( array( 'linked' => true ) );
		$linked = Resource::find( $with->id() );
		$this->assertArrayNotHasKey( 'restore', Actions::offered( $linked ) );
		$this->assertNotNull( Actions::check( $linked, 'restore', array( 'confirm' => 'RESTORE', 'volid' => 'backup-store:backup/vm/' . $linked->get( 'vmid' ) . '/x' ) ) );

		$this->assertArrayNotHasKey( 'restore', Actions::buttons( array( 'restore' => 'Restore', 'start' => 'Start' ), Resource::find( $without->id() ) ) );
	}

	public function test_restore_needs_the_word_and_one_of_this_servers_backups(): void {
		list( $r, $owner ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$volid             = $this->backup_of( $r );
		$seen              = count( $this->fake->calls() );

		$no_word = $this->route( $r->id(), $owner, 'restore', array( 'volid' => $volid ) );
		$this->assertSame( 422, $no_word->get_status() );
		$this->assertStringContainsString( 'RESTORE', $no_word->get_data()['message'] );

		$this->assertSame( 422, $this->route( $r->id(), $owner, 'restore', array( 'volid' => $volid, 'confirm' => 'restore' ) )->get_status(), 'Case matters.' );
		$this->assertSame( 422, $this->route( $r->id(), $owner, 'restore', array( 'confirm' => 'RESTORE' ) )->get_status(), 'No backup chosen.' );

		$foreign = 'backup-store:backup/vm/' . ( (int) $r->get( 'vmid' ) + 1 ) . '/2026-01-01T00:00:00Z';
		$bad     = $this->route( $r->id(), $owner, 'restore', array( 'volid' => $foreign, 'confirm' => 'RESTORE' ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertStringContainsString( 'listed backups', $bad->get_data()['message'] );

		$other_storage = str_replace( 'backup-store', 'disk-store', $volid );
		$this->assertSame( 422, $this->route( $r->id(), $owner, 'restore', array( 'volid' => $other_storage, 'confirm' => 'RESTORE' ) )->get_status() );

		$this->assertCount( $seen, $this->fake->calls(), 'The route made no Proxmox call.' );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
	}

	public function test_a_backup_from_before_the_vm_record_cannot_be_restored(): void {
		list( $r ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$vmid      = (int) $r->get( 'vmid' );
		$born      = (int) strtotime( VmRecords::for_resource( $r->id() )['created_at'] . ' UTC' );
		$old       = 'backup-store:backup/vm/' . $vmid . '/2020-01-01T00:00:00Z';
		$config    = $this->fake->vm( $vmid )['config'];
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => $born - 1000, 'volid' => $old, 'config' => $config ) );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();
		$seen        = count( $this->fake->calls() );

		$this->act( $r, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $old ) );

		$this->assertCount( 0, $this->calls( 'POST', '/qemu' ), 'Nothing was restored.' );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'], 'The server was not even switched off.' );
		$this->assertStringContainsString( 'Restore refused', $this->events_of( $r ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ) );
		$this->assertSame( array(), array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ), 'A refusal is no alert.' );
		unset( $seen );
	}

	public function test_restore_runs_off_restore_verify_plan_start(): void {
		list( $r ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$vmid      = (int) $r->get( 'vmid' );
		$volid     = $this->backup_of( $r );

		// Changed after the backup: the restore must undo it.
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/' . $vmid . '/config', array( 'cores' => 8 ) );
		$seen = count( $this->fake->calls() );

		$this->act( $r, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$lines = array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], array_slice( $this->fake->calls(), $seen ) );
		$order = array_values(
			array_filter(
				$lines,
				static fn( $l ) => (bool) preg_match( '#/status/(shutdown|stop|start)$|^POST /nodes/node-a/qemu$#', $l )
			)
		);
		$this->assertSame(
			array( 'POST /nodes/node-a/qemu/' . $vmid . '/status/shutdown', 'POST /nodes/node-a/qemu' ),
			$order,
			'The restore leaves the VM off: the queued plan change applies the plan and starts it.'
		);

		$restore = $this->calls( 'POST', '/nodes/node-a/qemu' );
		$this->assertSame(
			array(
				'vmid'    => $vmid,
				'archive' => $volid,
				'force'   => 1,
				'storage' => 'disk-store',
			),
			$restore[0]['params']
		);

		$this->assertSame( 2, (int) $this->fake->vm( $vmid )['config']['cores'], 'Back to the backup.' );
		$this->assertSame( 'stopped', $this->fake->vm( $vmid )['status'], 'Off until the queued plan change starts it.' );

		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'restore' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertTrue( $fresh->get( 'plan_dirty' ), 'The plan is queued again.' );
		$this->assertSame( 30, (int) $fresh->get( 'disk_gb' ) );
		$this->assertStringContainsString( 'Server restored from the backup of', $this->events_of( $r ) );
		$this->assertStringContainsString( 'Plan re-applied after restore', $this->events_of( $r ) );

		Jobs::run( $r->id() );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
	}

	public function test_a_backup_of_30g_restored_on_a_60g_plan_ends_at_60g_and_the_plan_sizing(): void {
		list( $r ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$vmid      = (int) $r->get( 'vmid' );
		$volid     = $this->backup_of( $r );
		$this->assertStringContainsString( 'size=30G', $this->fake->vm( $vmid )['config']['scsi0'] );

		// An upgrade after the backup: 60 GB, 4 cores.
		$plan = array_merge( (array) Resource::find( $r->id() )->get( 'plan' ), array( 'disk_gb' => 60, 'cores' => 4, 'memory_mb' => 8192 ) );
		$this->assertTrue( Resource::find( $r->id() )->change_plan( $plan, 'Plan changed' ) );
		Jobs::run( $r->id() );
		$this->assertStringContainsString( 'size=60G', $this->fake->vm( $vmid )['config']['scsi0'] );

		$seen = count( $this->fake->calls() );
		$this->act( $r, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$this->assertStringContainsString( 'size=30G', $this->fake->vm( $vmid )['config']['scsi0'], 'The backup is what came back.' );
		$this->assertSame( 30, (int) Resource::find( $r->id() )->get( 'disk_gb' ) );

		Jobs::run( $r->id() ); // The queued plan change.

		$config = $this->fake->vm( $vmid )['config'];
		$this->assertStringContainsString( 'size=60G', $config['scsi0'], 'Grown again, never shrunk.' );
		$this->assertSame( 4, (int) $config['cores'] );
		$this->assertSame( 8192, (int) $config['memory'] );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertFalse( Resource::find( $r->id() )->get( 'plan_dirty' ) );
		$resizes = array_filter( array_slice( $this->fake->calls(), $seen ), static fn( $c ) => 'PUT' === $c['method'] && '/nodes/node-a/qemu/' . $vmid . '/resize' === $c['path'] );
		$this->assertSame( array( '60G' ), array_values( array_map( static fn( $c ) => $c['params']['size'], $resizes ) ), 'Grown to the plan; never asked for a smaller disk.' );
	}

	public function test_a_restore_on_a_bigger_plan_is_started_once_by_the_plan_change_with_no_second_shutdown(): void {
		list( $r ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$vmid      = (int) $r->get( 'vmid' );
		$volid     = $this->backup_of( $r );
		$plan      = array_merge( (array) Resource::find( $r->id() )->get( 'plan' ), array( 'disk_gb' => 60, 'cores' => 4, 'memory_mb' => 8192 ) );
		$this->assertTrue( Resource::find( $r->id() )->change_plan( $plan, 'Plan changed' ) );
		Jobs::run( $r->id() );

		$seen = count( $this->fake->calls() );
		$this->act( $r, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$this->assertSame( 'stopped', $this->fake->vm( $vmid )['status'], 'The restore leaves the server off for the queued plan change.' );
		$this->assertTrue( Resource::find( $r->id() )->get( 'was_running' ), 'The plan change owns the one start.' );

		Jobs::run( $r->id() ); // The queued plan change: one job pickup, no backoff.

		$lines = array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], array_slice( $this->fake->calls(), $seen ) );
		$power = array_values( array_filter( $lines, static fn( $l ) => (bool) preg_match( '#/status/(shutdown|stop|start)$#', $l ) ) );
		$base  = 'POST /nodes/node-a/qemu/' . $vmid . '/status/';
		$this->assertSame( array( $base . 'shutdown', $base . 'start' ), $power, 'One shutdown (the restore\'s own), one start, and none in between.' );

		$fresh  = Resource::find( $r->id() );
		$config = $this->fake->vm( $vmid )['config'];
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertSame( 4, (int) $config['cores'] );
		$this->assertSame( 8192, (int) $config['memory'] );
		$this->assertStringContainsString( 'size=60G', $config['scsi0'] );
		$this->assertFalse( $fresh->get( 'plan_dirty' ) );
		$this->assertEmpty( $fresh->get( 'was_running' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 0, (int) $fresh->get( 'attempts' ) );
	}

	public function test_a_restore_task_that_is_still_running_is_waited_for_not_started_again(): void {
		list( $r ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$vmid      = (int) $r->get( 'vmid' );
		$volid     = $this->backup_of( $r );

		$this->fake->next_task( array() );             // The shutdown.
		$this->fake->next_task( array( 'polls' => 10 ) ); // The restore.

		$this->act( $r, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
		$this->assertCount( 1, $this->calls( 'POST', '/nodes/node-a/qemu' ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ), 'Not restored yet.' );
		$this->assertSame( 'stopped', $this->fake->vm( $vmid )['status'] );

		for ( $i = 0; $i < 4 && ! str_contains( $this->events_of( $r ), 'Server restored' ); $i++ ) {
			$this->act( $r, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
		}

		Jobs::run( $r->id() );
		$this->assertCount( 1, $this->calls( 'POST', '/nodes/node-a/qemu' ), 'One restore, however many rounds.' );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ) );
		$this->assertStringContainsString( 'Server restored from the backup of', $this->events_of( $r ) );
	}

	public function test_a_failed_restore_is_left_alone_and_the_admin_is_alerted(): void {
		list( $r ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$vmid      = (int) $r->get( 'vmid' );
		$volid     = $this->backup_of( $r );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		$this->mails = array();

		$this->fake->next_task( array() );
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: restore failed - storage unavailable' ) );
		$seen = count( $this->fake->calls() );

		$this->act( $r, 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ), 'A failed action never changes the service state.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ) );
		$this->assertCount( 0, array_filter( array_slice( $this->fake->calls(), $seen ), static fn( $c ) => '/nodes/node-a/qemu/' . $vmid . '/status/start' === $c['path'] ), 'No start after a failed restore.' );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ) );
		$this->assertStringContainsString( 'Restore failed', $this->events_of( $r ) );
	}

	public function test_restore_is_rate_limited_per_hour(): void {
		list( $r, $owner ) = $this->ready( array(), array( 'backup_days' => 7 ) );
		$volid             = $this->backup_of( $r );
		$args              = array( 'confirm' => 'RESTORE', 'volid' => $volid );

		foreach ( range( 1, 3 ) as $n ) {
			$this->assertSame( 202, $this->route( $r->id(), $owner, 'restore', $args )->get_status(), 'Request ' . $n );
		}

		$this->assertSame( 429, $this->route( $r->id(), $owner, 'restore', $args )->get_status() );
	}
}

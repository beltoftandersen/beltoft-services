<?php
/**
 * The Proxmox clients: fake, live (guarded) and the factory. No test reaches a network: the
 * recorder in TestCase pre-empts every request.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Client;
use BeltoftServices\Proxmox\Clients;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\LiveClient;
use BeltoftServices\Tests\TestCase;

/**
 * LiveClient with the sleeps recorded instead of slept.
 */
class QuickLiveClient extends LiveClient {

	/**
	 * Pauses asked for.
	 *
	 * @var int[]
	 */
	public $pauses = array();

	protected function pause( int $seconds ): void {
		$this->pauses[] = $seconds;
	}
}

/**
 * LiveClient that records the curl options instead of setting them, and can refuse one.
 */
class RecordingLiveClient extends QuickLiveClient {

	/**
	 * Options set, in order: constant => value.
	 *
	 * @var array<int, array{0: int, 1: mixed}>
	 */
	public $options = array();

	/**
	 * Option constants curl "refuses".
	 *
	 * @var int[]
	 */
	public $refuse = array();

	protected function set_curl_option( $handle, int $option, $value ): bool {
		if ( in_array( $option, $this->refuse, true ) ) {
			return false;
		}

		$this->options[] = array( $option, $value );

		return true;
	}
}

/**
 * A Client that is neither fake nor live.
 */
class StubClient implements Client {

	public function request( string $method, string $path, array $params = array() ) {
		return null;
	}

	public function task( string $method, string $path, array $params = array() ) {
		return new \WP_Error( 'stub', 'stub' );
	}

	public function wait_task( string $upid, int $timeout ) {
		return true;
	}
}

/**
 * LiveClient on a PHP without the curl pin constant.
 */
class NoPinLiveClient extends QuickLiveClient {

	protected function curl_pin_supported(): bool {
		return false;
	}
}

/**
 * Client tests.
 */
class ClientTest extends TestCase {

	const BASE   = 'https://pve.example.test:8006';
	const TOKEN  = 'svc@pve!beltoft';
	const SECRET = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	const UPID   = 'UPID:pve1:0000ABCD:00000001:5F000000:qmclone:123:svc@pve!beltoft:';

	/**
	 * Requests the responder let through.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent = array();
	}

	/**
	 * A live client (allowance true, as only the factory does) on the test cluster.
	 *
	 * @param array<string, mixed> $extra Cluster overrides.
	 */
	private function live( array $extra = array(), string $class = QuickLiveClient::class ): QuickLiveClient {
		return new $class(
			array_merge(
				array(
					'api_url'      => self::BASE,
					'token_id'     => self::TOKEN,
					'token_secret' => self::SECRET,
				),
				$extra
			),
			true
		);
	}

	/**
	 * Answers with a JSON data member; any other host fails the test.
	 *
	 * @param mixed $data   Data member.
	 * @param int   $code   HTTP status.
	 * @param mixed $reason Reason phrase.
	 */
	private function answer( $data, int $code = 200, string $reason = 'OK' ): void {
		$this->respond_with(
			function ( $url, $args ) use ( $data, $code, $reason ) {
				if ( 0 !== strpos( $url, self::BASE . '/api2/json/' ) ) {
					$this->fail( 'Unexpected outbound request: ' . $url );
				}
				$this->sent[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => $data ) ),
					'response' => array(
						'code'    => $code,
						'message' => $reason,
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	// Review focus 5: the guard.

	public function test_live_client_refuses_without_the_allowance(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Live Proxmox client disabled: define BELTOFT_SERVICES_PROXMOX_LIVE in wp-config.php' );

		new LiveClient(
			array(
				'api_url'      => self::BASE,
				'token_id'     => self::TOKEN,
				'token_secret' => self::SECRET,
			),
			false
		);
	}

	public function test_factory_returns_fake_unless_live_and_constant(): void {
		$this->assertFalse( defined( 'BELTOFT_SERVICES_PROXMOX_LIVE' ), 'The test run must never define the live constant.' );
		$this->assertFalse( Clients::live_allowed() );

		$cluster = array(
			'api_url'      => self::BASE,
			'token_id'     => self::TOKEN,
			'token_secret' => self::SECRET,
		);

		$this->assertInstanceOf( FakeClient::class, Clients::for( $cluster ) );
		$this->assertInstanceOf( FakeClient::class, Clients::for( $cluster + array( 'client' => 'fake' ) ) );
		$this->assertInstanceOf( FakeClient::class, Clients::for( $cluster + array( 'client' => 'live' ) ) );

		// A filter cannot bring a live client in either, and may inject a fake.
		add_filter(
			'beltoft_services_proxmox_client',
			static fn( $client ) => new LiveClient( $cluster + array( 'client' => 'live' ), true )
		);
		$this->assertInstanceOf( FakeClient::class, Clients::for( $cluster + array( 'client' => 'live' ) ) );

		// Any other Client implementation is ignored too.
		remove_all_filters( 'beltoft_services_proxmox_client' );
		add_filter( 'beltoft_services_proxmox_client', static fn() => new StubClient() );
		$this->assertInstanceOf( FakeClient::class, Clients::for( $cluster ) );

		// The filter never sees the token secret.
		remove_all_filters( 'beltoft_services_proxmox_client' );
		$given = null;
		add_filter(
			'beltoft_services_proxmox_client',
			static function ( $client, $c ) use ( &$given ) {
				$given = $c;
				return $client;
			},
			10,
			2
		);
		Clients::for( $cluster );
		$this->assertArrayNotHasKey( 'token_secret', $given );
		$this->assertSame( self::TOKEN, $given['token_id'] );

		remove_all_filters( 'beltoft_services_proxmox_client' );
		$injected = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', static fn() => $injected );
		$this->assertSame( $injected, Clients::for( $cluster ) );
	}

	public function test_an_empty_token_secret_refuses_to_construct(): void {
		foreach ( array( '', '   ' ) as $empty ) {
			try {
				$this->live( array( 'token_secret' => $empty ) );
				$this->fail( 'Expected an exception for an empty secret.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'secret', $e->getMessage() );
			}
		}
		$this->assertSame( array(), $this->http_calls() );
	}

	public function test_kind_is_live_only_with_the_setting_and_the_allowance(): void {
		$this->assertSame( 'live', Clients::kind( array( 'client' => 'live' ), true ) );
		$this->assertSame( 'fake', Clients::kind( array( 'client' => 'live' ), false ) );
		$this->assertSame( 'fake', Clients::kind( array( 'client' => 'fake' ), true ) );
		$this->assertSame( 'fake', Clients::kind( array(), true ) );
	}

	public function test_settings_cannot_select_live_without_the_constant(): void {
		// A saved cluster that says live (the setting) still gets the fake without the constant.
		update_option(
			'beltoft_services_test_cluster',
			array(
				'client'       => 'live',
				'api_url'      => self::BASE,
				'token_id'     => self::TOKEN,
				'token_secret' => self::SECRET,
			)
		);
		$client = Clients::for( (array) get_option( 'beltoft_services_test_cluster' ) );

		$this->assertInstanceOf( FakeClient::class, $client );
		$this->assertSame( array(), $this->http_calls() );

		$client->request( 'GET', '/nodes' );
		$this->assertSame( array(), $this->http_calls(), 'The fake never sends an HTTP request.' );
	}

	public function test_new_live_client_is_built_only_by_the_factory(): void {
		$hits = array();
		$dir  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $dir as $file ) {
			if ( 'php' === $file->getExtension() && false !== strpos( (string) file_get_contents( $file->getPathname() ), 'new LiveClient' ) ) {
				$hits[] = $file->getFilename();
			}
		}

		$this->assertSame( array( 'Clients.php' ), $hits );
	}

	// The fake.

	private function seeded(): FakeClient {
		$fake = new FakeClient();
		$fake->add_node( 'pve1', array( 'maxmem' => 64 * 1073741824, 'mem' => 8 * 1073741824, 'maxcpu' => 16, 'cpu' => 0.1 ) );
		$fake->add_pool( 'customers' );
		$fake->add_vm(
			array(
				'vmid'     => 9000,
				'node'     => 'pve1',
				'name'     => 'tpl',
				'template' => 1,
				'config'   => array(
					'cores'  => 1,
					'memory' => 1024,
					'scsi0'  => 'local-lvm:base-9000-disk-0,size=10G',
					'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
				),
			)
		);

		return $fake;
	}

	public function test_fake_records_calls_scrubbed_and_scripts_failures(): void {
		$fake = $this->seeded();
		$fake->add_vm(
			array(
				'vmid'   => 123,
				'node'   => 'pve1',
				'name'   => 'v',
				'config' => array( 'cores' => 1 ),
			)
		);

		$this->assertTrue( $fake instanceof Client );
		$this->assertNull( $fake->request( 'PUT', '/nodes/pve1/qemu/123/config', array( 'cores' => 2, 'cipassword' => 'hunter2hunter2', 'sshkeys' => 'ssh-rsa AAAA' ) ) );

		$calls = $fake->calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( 'PUT', $calls[0]['method'] );
		$this->assertSame( '/nodes/pve1/qemu/123/config', $calls[0]['path'] );
		$this->assertSame( 2, $calls[0]['params']['cores'] );
		$this->assertSame( '***', $calls[0]['params']['cipassword'] );
		$this->assertSame( '***', $calls[0]['params']['sshkeys'] );
		$this->assertStringNotContainsString( 'hunter2', wp_json_encode( $fake->calls() ) );

		// The password is not kept in the state either.
		$config = $fake->request( 'GET', '/nodes/pve1/qemu/123/config' );
		$this->assertSame( 2, $config['cores'] );
		$this->assertStringNotContainsString( 'hunter2', wp_json_encode( $fake->state() ) );

		// Scripted failures: matched by method and regex, consumed once, in order.
		$fake->fail_next( 'GET', '#/status/current$#', new \WP_Error( 'transport', 'boom' ) );
		$fake->fail_next( 'GET', '#/status/current$#', array( 'code' => 'http_500', 'message' => 'VM locked', 'status' => 500 ) );

		$first = $fake->request( 'GET', '/nodes/pve1/qemu/123/status/current' );
		$this->assertWPError( $first );
		$this->assertSame( 'transport', $first->get_error_code() );

		$second = $fake->request( 'GET', '/nodes/pve1/qemu/123/status/current' );
		$this->assertSame( 'http_500', $second->get_error_code() );
		$this->assertSame( 500, $second->get_error_data()['status'] );

		$this->assertSame( 'stopped', $fake->request( 'GET', '/nodes/pve1/qemu/123/status/current' )['status'] );
	}

	public function test_fake_rejects_unknown_and_missing_parameters(): void {
		$fake = $this->seeded();

		// Unknown parameter (a wrong name, as "vmid" for "newid").
		$bad = $fake->request( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'vmid' => 123, 'newid' => 123 ) );
		$this->assertSame( 'http_400', $bad->get_error_code() );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		// Missing required parameter.
		$missing = $fake->request( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'name' => 'x' ) );
		$this->assertSame( 'http_400', $missing->get_error_code() );

		// Out of range and enum values.
		$this->assertSame( 'http_400', $fake->request( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 99 ) )->get_error_code() );
		$this->assertSame( 'http_400', $fake->request( 'GET', '/nodes/pve1/qemu/9000/rrddata', array( 'timeframe' => 'decade' ) )->get_error_code() );
		$this->assertSame( 'http_400', $fake->request( 'GET', '/nodes/pve1/qemu/9000/rrddata' )->get_error_code() );
		$this->assertSame( 'http_400', $fake->request( 'PUT', '/nodes/pve1/qemu/9000/resize', array( 'disk' => 'scsi0' ) )->get_error_code() );

		// A parameter that is documented elsewhere but not on this call.
		$this->assertSame( 'http_400', $fake->request( 'GET', '/nodes', array( 'type' => 'vm' ) )->get_error_code() );

		// An unknown path is 501, a missing VM a 500 (as the API answers).
		$this->assertSame( 'http_501', $fake->request( 'GET', '/nothing/here' )->get_error_code() );
		$this->assertSame( 'http_500', $fake->request( 'GET', '/nodes/pve1/qemu/555/config' )->get_error_code() );

		// Nothing was changed by the refused calls.
		$this->assertCount( 1, $fake->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) ) );
	}

	public function test_fake_vmid_assertion_and_clone_flow(): void {
		$fake = $this->seeded();

		$taken = $fake->request( 'GET', '/cluster/nextid', array( 'vmid' => 9000 ) );
		$this->assertWPError( $taken );
		$this->assertSame( 123, (int) $fake->request( 'GET', '/cluster/nextid', array( 'vmid' => 123 ) ) );

		$tag  = 'beltoft-services:site:uuid-1';
		$upid = $fake->task(
			'POST',
			'/nodes/pve1/qemu/9000/clone',
			array(
				'newid'       => 123,
				'name'        => 'cust-123',
				'full'        => 1,
				'description' => $tag,
				'pool'        => 'customers',
			)
		);
		$this->assertIsString( $upid );
		$this->assertTrue( $fake->wait_task( $upid, 30 ) );

		$config = $fake->request( 'GET', '/nodes/pve1/qemu/123/config' );
		$this->assertSame( $tag, $config['description'] );
		$this->assertSame( 1024, $config['memory'] );

		$list = $fake->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) );
		$this->assertCount( 2, $list );

		// Cloning onto a taken id is refused.
		$again = $fake->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 123 ) );
		$this->assertWPError( $again );

		// Resize is absolute; shrinking is refused.
		$resize = $fake->task( 'PUT', '/nodes/pve1/qemu/123/resize', array( 'disk' => 'scsi0', 'size' => '20G' ) );
		$this->assertTrue( $fake->wait_task( $resize, 30 ) );
		$this->assertStringContainsString( 'size=20G', $fake->request( 'GET', '/nodes/pve1/qemu/123/config' )['scsi0'] );
		$this->assertWPError( $fake->task( 'PUT', '/nodes/pve1/qemu/123/resize', array( 'disk' => 'scsi0', 'size' => '5G' ) ) );

		// Power: start, shutdown, then delete (a running VM is not deleted).
		$this->assertTrue( $fake->wait_task( $fake->task( 'POST', '/nodes/pve1/qemu/123/status/start' ), 30 ) );
		$this->assertSame( 'running', $fake->request( 'GET', '/nodes/pve1/qemu/123/status/current' )['status'] );
		$this->assertWPError( $fake->task( 'DELETE', '/nodes/pve1/qemu/123', array( 'purge' => 1 ) ) );
		$this->assertTrue( $fake->wait_task( $fake->task( 'POST', '/nodes/pve1/qemu/123/status/shutdown', array( 'timeout' => 120 ) ), 30 ) );
		$this->assertTrue( $fake->wait_task( $fake->task( 'DELETE', '/nodes/pve1/qemu/123', array( 'purge' => 1, 'destroy-unreferenced-disks' => 1 ) ), 30 ) );
		$this->assertWPError( $fake->request( 'GET', '/nodes/pve1/qemu/123/config' ) );
	}

	public function test_fake_task_outcomes_are_scriptable(): void {
		$fake = $this->seeded();

		// Running for 3 polls: a short wait is pending, a long one finishes.
		$fake->next_task( array( 'polls' => 3 ) );
		$upid = $fake->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 200 ) );
		$wait = $fake->wait_task( $upid, 2 );
		$this->assertWPError( $wait );
		$this->assertSame( 'task_pending', $wait->get_error_code() );
		$this->assertTrue( $fake->wait_task( $upid, 60 ) );

		// A failed task leaves no VM behind unless it is told to.
		$fake->next_task( array( 'exitstatus' => 'clone failed: no space' ) );
		$failed = $fake->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 201 ) );
		$result = $fake->wait_task( $failed, 60 );
		$this->assertSame( 'task_failed', $result->get_error_code() );
		$this->assertWPError( $fake->request( 'GET', '/nodes/pve1/qemu/201/config' ) );

		$fake->next_task( array( 'exitstatus' => 'clone failed', 'effect' => true ) );
		$left = $fake->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 202 ) );
		$this->assertSame( 'task_failed', $fake->wait_task( $left, 60 )->get_error_code() );
		$this->assertIsArray( $fake->request( 'GET', '/nodes/pve1/qemu/202/config' ) );

		// task() refuses a non-UPID answer.
		$this->assertSame( 'bad_response', $fake->task( 'GET', '/nodes' )->get_error_code() );
	}

	public function test_fake_firewall_pool_and_agent_endpoints(): void {
		$fake = $this->seeded();
		$fake->add_vm( array( 'vmid' => 300, 'node' => 'pve1', 'name' => 'v', 'status' => 'running', 'config' => array( 'cores' => 1 ) ) );

		$this->assertNull( $fake->request( 'PUT', '/nodes/pve1/qemu/300/firewall/options', array( 'enable' => 1 ) ) );
		$this->assertSame( array(), $fake->request( 'GET', '/nodes/pve1/qemu/300/firewall/rules' ) );
		$rule = array( 'action' => 'DROP', 'type' => 'out', 'proto' => 'tcp', 'dport' => '25', 'iface' => 'net0', 'enable' => 1, 'comment' => 'x' );
		$this->assertNull( $fake->request( 'POST', '/nodes/pve1/qemu/300/firewall/rules', $rule ) );
		$this->assertCount( 1, $fake->request( 'GET', '/nodes/pve1/qemu/300/firewall/rules' ) );
		$this->assertSame( 'http_400', $fake->request( 'POST', '/nodes/pve1/qemu/300/firewall/rules', array( 'type' => 'out' ) )->get_error_code() );

		$this->assertNull( $fake->request( 'PUT', '/pools', array( 'poolid' => 'customers', 'vms' => '300' ) ) );
		$this->assertSame( 'customers', $fake->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) )[1]['pool'] );
		$this->assertWPError( $fake->request( 'PUT', '/pools', array( 'poolid' => 'missing', 'vms' => '300' ) ) );
		$this->assertNull( $fake->request( 'PUT', '/pools/customers', array( 'vms' => '300' ) ) );

		$this->assertIsArray( $fake->request( 'POST', '/nodes/pve1/qemu/300/agent/ping' ) );
		$set = $fake->request( 'POST', '/nodes/pve1/qemu/300/agent/set-user-password', array( 'username' => 'root', 'password' => 'secret-secret' ) );
		$this->assertIsArray( $set );
		$this->assertStringNotContainsString( 'secret-secret', wp_json_encode( array( $fake->state(), $fake->calls() ) ) );
		$this->assertSame( array(), $fake->request( 'GET', '/nodes/pve1/qemu/300/rrddata', array( 'timeframe' => 'hour', 'cf' => 'AVERAGE' ) ) );
		$this->assertSame( 'pve1', $fake->request( 'GET', '/nodes' )[0]['node'] );
	}

	public function test_fake_persists_without_secrets_when_asked(): void {
		delete_option( 'beltoft_services_fake_proxmox' );

		$fake = new FakeClient( true );
		$fake->add_node( 'pve1' );
		$fake->add_vm( array( 'vmid' => 400, 'node' => 'pve1', 'name' => 'p', 'config' => array( 'cores' => 1 ) ) );
		$fake->request( 'PUT', '/nodes/pve1/qemu/400/config', array( 'cipassword' => 'persist-secret-1' ) );

		$stored = get_option( 'beltoft_services_fake_proxmox' );
		$this->assertIsArray( $stored );
		$this->assertStringNotContainsString( 'persist-secret', wp_json_encode( $stored ) );

		$other = new FakeClient( true );
		$this->assertSame( 'p', $other->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) )[0]['name'] );

		// Not autoloaded.
		global $wpdb;
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'beltoft_services_fake_proxmox' ) ), array( 'no', 'off' ) );

		// In-memory fakes never touch the option.
		delete_option( 'beltoft_services_fake_proxmox' );
		$memory = new FakeClient();
		$memory->add_node( 'pve1' );
		$this->assertFalse( get_option( 'beltoft_services_fake_proxmox' ) );
	}

	// The live client, with every request pre-empted.

	public function test_get_and_delete_use_the_query_string_post_and_put_the_body(): void {
		$this->answer( array() );
		$live = $this->live();

		$live->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) );
		$live->request( 'DELETE', '/nodes/pve1/qemu/123', array( 'purge' => 1, 'destroy-unreferenced-disks' => true ) );
		$live->request( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 123, 'full' => true, 'skipped' => null ) );
		$live->request( 'PUT', '/nodes/pve1/qemu/123/config', array( 'cores' => 2, 'memory' => 2048 ) );

		$this->assertCount( 4, $this->sent );

		$this->assertSame( 'GET', $this->sent[0]['args']['method'] );
		$this->assertSame( self::BASE . '/api2/json/cluster/resources?type=vm', $this->sent[0]['url'] );
		$this->assertEmpty( $this->sent[0]['args']['body'] );

		$this->assertSame( 'DELETE', $this->sent[1]['args']['method'] );
		$this->assertSame( self::BASE . '/api2/json/nodes/pve1/qemu/123?purge=1&destroy-unreferenced-disks=1', $this->sent[1]['url'] );
		$this->assertEmpty( $this->sent[1]['args']['body'] );

		$this->assertSame( self::BASE . '/api2/json/nodes/pve1/qemu/9000/clone', $this->sent[2]['url'] );
		$this->assertSame( 'newid=123&full=1', $this->sent[2]['args']['body'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $this->sent[2]['args']['headers']['Content-Type'] );

		$this->assertSame( 'PUT', $this->sent[3]['args']['method'] );
		$this->assertSame( 'cores=2&memory=2048', $this->sent[3]['args']['body'] );
		$this->assertSame( self::BASE . '/api2/json/nodes/pve1/qemu/123/config', $this->sent[3]['url'] );
	}

	public function test_request_unwraps_data_and_maps_bad_answers(): void {
		$this->answer( array( array( 'vmid' => 1 ) ) );
		$this->assertSame( array( array( 'vmid' => 1 ) ), $this->live()->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) ) );

		$this->answer( null );
		$this->assertNull( $this->live()->request( 'PUT', '/x' ) );

		$this->respond_with(
			static fn() => array(
				'headers'  => array(),
				'body'     => 'not json',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			)
		);
		$this->assertSame( 'bad_response', $this->live()->request( 'GET', '/nodes' )->get_error_code() );

		$this->respond_with( static fn() => new \WP_Error( 'http_request_failed', 'cURL error 28' ) );
		$this->assertSame( 'transport', $this->live()->request( 'GET', '/nodes' )->get_error_code() );

		$this->answer( null, 500, 'Configuration file does not exist' );
		$error = $this->live()->request( 'GET', '/nodes/pve1/qemu/1/config' );
		$this->assertSame( 'http_500', $error->get_error_code() );
		$this->assertSame( 500, $error->get_error_data()['status'] );

		// Only GET, POST, PUT and DELETE, and an absolute-looking path.
		$this->assertSame( 'bad_method', $this->live()->request( 'PATCH', '/nodes' )->get_error_code() );
		$this->assertSame( 'bad_path', $this->live()->request( 'GET', 'nodes' )->get_error_code() );
	}

	public function test_sshkeys_is_urlencoded_once_before_form_encoding(): void {
		$this->answer( null );
		$this->live()->request( 'PUT', '/nodes/pve1/qemu/123/config', array( 'sshkeys' => 'ssh-ed25519 AAAAC3 me@host', 'cores' => 1 ) );

		// rawurlencode() once ("ssh-ed25519%20AAAAC3%20me%40host"), then the form encoding of that text.
		$this->assertSame( 'sshkeys=ssh-ed25519%2520AAAAC3%2520me%2540host&cores=1', $this->sent[0]['args']['body'] );
	}

	public function test_wait_task_ok_error_and_pending(): void {
		$statuses = array(
			array( 'status' => 'running' ),
			array( 'status' => 'running' ),
			array( 'status' => 'stopped', 'exitstatus' => 'OK' ),
		);
		$this->respond_with(
			function ( $url ) use ( &$statuses ) {
				$this->assertStringStartsWith( self::BASE . '/api2/json/nodes/pve1/tasks/', $url );
				return $this->json_reply( array_shift( $statuses ) );
			}
		);
		$live = $this->live();
		$this->assertTrue( $live->wait_task( self::UPID, 60 ) );
		$this->assertSame( array( 2, 2 ), $live->pauses, 'Polls every 2 seconds.' );
		$this->assertCount( 3, $this->http_calls() );

		// Any exit status but exactly OK is a failure that carries the text.
		$this->respond_with( fn() => $this->json_reply( array( 'status' => 'stopped', 'exitstatus' => 'WARNINGS: 1' ) ) );
		$failed = $this->live()->wait_task( self::UPID, 60 );
		$this->assertSame( 'task_failed', $failed->get_error_code() );
		$this->assertStringContainsString( 'WARNINGS: 1', $failed->get_error_message() );

		$this->respond_with( fn() => $this->json_reply( array( 'status' => 'stopped', 'exitstatus' => 'ok' ) ) );
		$this->assertSame( 'task_failed', $this->live()->wait_task( self::UPID, 60 )->get_error_code(), 'OK is matched exactly.' );

		// Still running when the time is up: a distinct code.
		$this->respond_with( fn() => $this->json_reply( array( 'status' => 'running' ) ) );
		$pending = $this->live();
		$result  = $pending->wait_task( self::UPID, 5 );
		$this->assertSame( 'task_pending', $result->get_error_code() );
		$this->assertSame( array( 2, 2 ), $pending->pauses );

		$this->assertSame( 'bad_response', $this->live()->wait_task( 'not-a-upid', 5 )->get_error_code() );
	}

	public function test_upid_is_encoded_in_the_path_and_node_comes_from_it(): void {
		$this->respond_with( fn() => $this->json_reply( array( 'status' => 'stopped', 'exitstatus' => 'OK' ) ) );
		$this->live()->wait_task( self::UPID, 10 );

		$this->assertSame(
			self::BASE . '/api2/json/nodes/pve1/tasks/' . rawurlencode( self::UPID ) . '/status',
			$this->http_calls()[0]['url']
		);
		$this->assertStringContainsString( 'UPID%3Apve1%3A0000ABCD', $this->http_calls()[0]['url'] );

		$this->assertSame( 'pve-node.2', \BeltoftServices\Proxmox\FakeClient::upid_node( 'UPID:pve-node.2:1:2:3:t:1:u:' ) );
		$this->assertNull( FakeClient::upid_node( 'UPID::1' ) );
		$this->assertNull( FakeClient::upid_node( 'XPID:pve1:1' ) );
	}

	public function test_task_requires_a_upid_answer(): void {
		$this->answer( self::UPID );
		$this->assertSame( self::UPID, $this->live()->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 123 ) ) );

		$this->answer( 'not a task' );
		$this->assertSame( 'bad_response', $this->live()->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 123 ) )->get_error_code() );

		$this->answer( null, 500, 'nope' );
		$this->assertSame( 'http_500', $this->live()->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 123 ) )->get_error_code() );
	}

	public function test_driver_run_makes_no_http_request(): void {
		// A whole provision-shaped sequence on the fake: no HTTP at all.
		$fake = $this->seeded();
		$up   = $fake->task( 'POST', '/nodes/pve1/qemu/9000/clone', array( 'newid' => 500, 'description' => 'beltoft-services:s:u' ) );
		$this->assertTrue( $fake->wait_task( $up, 30 ) );
		$fake->request( 'PUT', '/nodes/pve1/qemu/500/config', array( 'cores' => 2, 'cipassword' => 'abcdefgh1234' ) );
		$fake->task( 'POST', '/nodes/pve1/qemu/500/status/start' );

		$this->assertSame( array(), $this->http_calls() );
	}

	public function test_medusa_tls_verification_is_on_by_default(): void {
		$this->answer( array() );
		$this->live()->request( 'GET', '/nodes' );

		$args = $this->sent[0]['args'];
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 30, $args['timeout'] );
		$this->assertSame( 'PVEAPIToken=' . self::TOKEN . '=' . self::SECRET, $args['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'ca.pem', (string) $args['sslcertificates'], 'WordPress default bundle without a CA file.' );

		// A CA file becomes sslcertificates.
		$ca = wp_tempnam( 'ca.pem' );
		$this->live( array( 'ca_file' => $ca ) )->request( 'GET', '/nodes' );
		$this->assertSame( $ca, $this->sent[1]['args']['sslcertificates'] );
		$this->assertTrue( $this->sent[1]['args']['sslverify'] );
		wp_delete_file( $ca );

		// A CA file that cannot be read refuses to construct.
		try {
			$this->live( array( 'ca_file' => '/nonexistent/ca.pem' ) );
			$this->fail( 'Expected an exception.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'CA', $e->getMessage() );
		}

		// Only https.
		foreach ( array( 'http://pve.example.test:8006', 'ftp://x', '' ) as $bad ) {
			try {
				$this->live( array( 'api_url' => $bad ) );
				$this->fail( 'Expected an exception for ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'https', $e->getMessage() );
			}
		}

		// A token id must look like USER@REALM!TOKENID, and a secret is needed.
		foreach ( array( 'root@pam', 'nobang', '' ) as $bad ) {
			try {
				$this->live( array( 'token_id' => $bad ) );
				$this->fail( 'Expected an exception for ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'token', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		$this->live( array( 'token_secret' => '' ) );
	}

	public function test_pin_is_set_on_curl_only_around_the_request_and_refuses_without_curl_support(): void {
		$pin    = base64_encode( hash( 'sha256', 'key', true ) );
		$seen   = array();
		$client = $this->live( array( 'pin_sha256' => $pin ) );

		// Simulates curl: the transport fires http_api_curl with a real handle.
		$this->respond_with(
			function ( $url, $args ) use ( &$seen, $client ) {
				$seen['during'] = has_action( 'http_api_curl', array( $client, 'pin_key' ) );
				if ( function_exists( 'curl_init' ) ) {
					$handle = curl_init();
					do_action( 'http_api_curl', $handle, $args, $url );
					curl_close( $handle );
				}
				return $this->json_reply( array( 'ok' => 1 ) );
			}
		);

		if ( function_exists( 'curl_init' ) && defined( 'CURLOPT_PINNEDPUBLICKEY' ) ) {
			$this->assertSame( array( 'ok' => 1 ), $client->request( 'GET', '/nodes' ) );
			$this->assertNotFalse( $seen['during'], 'The pin hook is on while the request runs.' );
			$this->assertFalse( has_action( 'http_api_curl', array( $client, 'pin_key' ) ), 'And off right after.' );

			// The hook pins this host only.
			$handle = curl_init();
			$this->assertTrue( $client->pin_key( $handle, array(), self::BASE . '/api2/json/nodes' ) );
			$this->assertFalse( $client->pin_key( $handle, array(), 'https://other.example.test/' ), 'Only this host is pinned.' );
			curl_close( $handle );
		}

		// Fails closed: a transport that never runs the hook (not curl) cannot be trusted.
		$this->respond_with( fn() => $this->json_reply( array( 'secret' => 'data' ) ) );
		$silent = $this->live( array( 'pin_sha256' => $pin ) );
		$result = $silent->request( 'GET', '/nodes' );
		$this->assertWPError( $result );
		$this->assertSame( 'pin_unverified', $result->get_error_code() );
		$this->assertStringNotContainsString( 'data', $result->get_error_message() );
		$this->assertFalse( has_action( 'http_api_curl', array( $silent, 'pin_key' ) ) );

		// The hook is removed even when the request fails, and the transport error stays.
		$this->respond_with( static fn() => new \WP_Error( 'http_request_failed', 'down' ) );
		$failing = $this->live( array( 'pin_sha256' => $pin ) );
		$this->assertSame( 'transport', $failing->request( 'GET', '/nodes' )->get_error_code() );
		$this->assertFalse( has_action( 'http_api_curl', array( $failing, 'pin_key' ) ) );

		// A malformed pin, and a pin on a site without the curl constant, refuse to construct.
		try {
			$this->live( array( 'pin_sha256' => 'short' ) );
			$this->fail( 'Expected an exception.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'pin', $e->getMessage() );
		}

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'CURLOPT_PINNEDPUBLICKEY' );
		$this->live( array( 'pin_sha256' => $pin ), NoPinLiveClient::class );
	}

	public function test_a_pin_without_a_ca_file_replaces_the_chain_check_for_that_request_only(): void {
		$pin    = base64_encode( hash( 'sha256', 'key', true ) );
		$client = $this->live( array( 'pin_sha256' => $pin ), RecordingLiveClient::class );
		$url    = self::BASE . '/api2/json/nodes';

		$this->assertTrue( $client->pin_key( new \stdClass(), array(), $url ) );
		$this->assertSame(
			array(
				array( CURLOPT_PINNEDPUBLICKEY, 'sha256//' . $pin ),
				array( CURLOPT_SSL_VERIFYPEER, false ),
				array( CURLOPT_SSL_VERIFYHOST, 0 ),
			),
			$client->options,
			'The pin first, then the chain and host checks off.'
		);

		// Another host: nothing is touched.
		$client->options = array();
		$this->assertFalse( $client->pin_key( new \stdClass(), array(), 'https://other.example.test/' ) );
		$this->assertSame( array(), $client->options );

		// The request itself: the request arguments keep sslverify on (the disabling happens on the handle, per request).
		$this->respond_with(
			function ( $url, $args ) use ( $client ) {
				do_action( 'http_api_curl', new \stdClass(), $args, $url );
				$this->assertTrue( $args['sslverify'] );
				return $this->json_reply( array( 'ok' => 1 ) );
			}
		);
		$client->options = array();
		$this->assertSame( array( 'ok' => 1 ), $client->request( 'GET', '/nodes' ) );
		$this->assertCount( 3, $client->options );

		// A later request of a client without a pin never touches the verify options.
		$plain = $this->live( array(), RecordingLiveClient::class );
		$this->respond_with(
			function ( $url, $args ) use ( $plain ) {
				do_action( 'http_api_curl', new \stdClass(), $args, $url );
				return $this->json_reply( array( 'ok' => 1 ) );
			}
		);
		$this->assertSame( array( 'ok' => 1 ), $plain->request( 'GET', '/nodes' ) );
		$this->assertSame( array(), $plain->options );
	}

	public function test_a_pin_with_a_ca_file_keeps_full_verification(): void {
		$ca = tempnam( get_temp_dir(), 'ca' );
		file_put_contents( $ca, "-----BEGIN CERTIFICATE-----\n-----END CERTIFICATE-----\n" );
		$pin    = base64_encode( hash( 'sha256', 'key', true ) );
		$client = $this->live( array( 'pin_sha256' => $pin, 'ca_file' => $ca ), RecordingLiveClient::class );

		$this->assertTrue( $client->pin_key( new \stdClass(), array(), self::BASE . '/api2/json/nodes' ) );
		$this->assertSame( array( array( CURLOPT_PINNEDPUBLICKEY, 'sha256//' . $pin ) ), $client->options, 'Only the pin is added.' );
		unlink( $ca );
	}

	public function test_the_pin_still_fails_closed_when_curl_refuses_the_pin_or_a_verify_option(): void {
		$pin = base64_encode( hash( 'sha256', 'key', true ) );

		foreach ( array( CURLOPT_PINNEDPUBLICKEY, CURLOPT_SSL_VERIFYPEER, CURLOPT_SSL_VERIFYHOST ) as $refused ) {
			$client         = $this->live( array( 'pin_sha256' => $pin ), RecordingLiveClient::class );
			$client->refuse = array( $refused );
			$this->respond_with(
				function ( $url, $args ) {
					do_action( 'http_api_curl', new \stdClass(), $args, $url );
					return $this->json_reply( array( 'secret' => 'data' ) );
				}
			);

			$result = $client->request( 'GET', '/nodes' );

			$this->assertWPError( $result );
			$this->assertSame( 'pin_unverified', $result->get_error_code(), 'Refused option ' . $refused );
			$this->assertStringNotContainsString( 'data', $result->get_error_message() );

			if ( CURLOPT_PINNEDPUBLICKEY === $refused ) {
				$this->assertSame( array(), $client->options, 'Verification is never switched off without the pin.' );
			}
		}
	}

	public function test_short_secrets_are_scrubbed_too(): void {
		$this->respond_with( fn() => new \WP_Error( 'http_request_failed', 'bad pw=xy7 done' ) );
		$error = $this->live()->request( 'POST', '/nodes/pve1/qemu/1/agent/set-user-password', array( 'username' => 'root', 'password' => 'xy7' ) );
		$this->assertStringNotContainsString( 'xy7', $error->get_error_message() );
	}

	public function test_error_text_never_contains_the_secret_or_password(): void {
		$leak = self::SECRET . ' and hunter2hunter2 and ' . rawurlencode( 'ssh-rsa AAAAB3 me@host' );

		$this->respond_with( fn() => new \WP_Error( 'http_request_failed', 'cURL error 7: ' . $leak ) );
		$error = $this->live()->request(
			'PUT',
			'/nodes/pve1/qemu/1/config',
			array( 'cipassword' => 'hunter2hunter2', 'sshkeys' => 'ssh-rsa AAAAB3 me@host' )
		);
		$this->assertSame( 'transport', $error->get_error_code() );
		$this->assertNoLeak( $error );

		$this->answer( null, 500, 'bad ' . $leak );
		$error = $this->live()->request(
			'PUT',
			'/nodes/pve1/qemu/1/config',
			array( 'cipassword' => 'hunter2hunter2', 'sshkeys' => 'ssh-rsa AAAAB3 me@host' )
		);
		$this->assertSame( 'http_500', $error->get_error_code() );
		$this->assertNoLeak( $error );

		// The password parameter of the agent call too, on a failed task poll.
		$this->answer( null, 500, 'agent said hunter2hunter2' );
		$error = $this->live()->request( 'POST', '/nodes/pve1/qemu/1/agent/set-user-password', array( 'username' => 'root', 'password' => 'hunter2hunter2' ) );
		$this->assertNoLeak( $error );
	}

	private function assertNoLeak( \WP_Error $error ): void {
		$all = wp_json_encode( array( $error->get_error_messages(), $error->get_error_data(), $error->get_error_codes() ) );
		$this->assertStringNotContainsString( self::SECRET, $all );
		$this->assertStringNotContainsString( 'hunter2', $all );
		$this->assertStringNotContainsString( 'AAAAB3', $all );
	}

	/**
	 * A 200 reply with a data member.
	 *
	 * @param mixed $data Data.
	 * @return array<string, mixed>
	 */
	private function json_reply( $data ): array {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'data' => $data ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}

<?php
/**
 * Shared set-up of the firewall tests: a fake cluster, a ready server, the customer routes. Nothing here reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A client that records the raw calls and can drop a rule write without storing it.
 */
class FirewallClient extends FakeClient {

	/**
	 * Silently drop POSTs of rules (the answer is a success, nothing is stored).
	 *
	 * @var bool
	 */
	public $drop_posts = false;

	/**
	 * The write calls made (method path).
	 *
	 * @var string[]
	 */
	public $writes = array();

	public function request( string $method, string $path, array $params = array() ) {
		if ( 'GET' !== strtoupper( $method ) && false !== strpos( $path, '/firewall' ) ) {
			$this->writes[] = strtoupper( $method ) . ' ' . $path . ' ' . wp_json_encode( $params );

			if ( $this->drop_posts && 'POST' === strtoupper( $method ) && substr( $path, -15 ) === '/firewall/rules' && false !== strpos( (string) ( $params['comment'] ?? '' ), 'bs-fw:' ) ) {
				return null;
			}
		}

		return parent::request( $method, $path, $params );
	}
}

abstract class FirewallTestCase extends TestCase {

	protected const SECRET = 'secret-secret-secret-1';

	/**
	 * @var FirewallClient
	 */
	protected $fake;

	public function set_up(): void {
		parent::set_up();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$key                    = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		add_filter( 'pre_wp_mail', fn() => true );

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5 ) );

		$this->fake = new FirewallClient();
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
		$this->fake->add_vm(
			array(
				'vmid'     => 9000,
				'node'     => 'node-a',
				'name'     => 'ubuntu-26-template',
				'template' => 1,
				'config'   => array(
					'scsi0'  => 'disk-store:vm-9000-disk-0,size=10G',
					'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
					'agent'  => '1',
					'memory' => 512,
					'cores'  => 1,
				),
			)
		);

		$this->save_cluster();

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
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tear_down();
	}

	/**
	 * Saves the cluster (the firewall switch off unless asked).
	 *
	 * @param array<string, mixed> $extra Settings to add.
	 */
	protected function save_cluster( array $extra = array() ): void {
		$this->assertTrue(
			Clusters::save(
				'lab',
				array_merge(
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
						'gateway'           => '192.0.2.1',
						'nameservers'       => '192.0.2.53',
						'prefix'            => '24',
						'vmid_min'          => '5000',
						'vmid_max'          => '5099',
						'templates'         => '9000 = Ubuntu 26.04',
						'default_template'  => '9000',
						'block_smtp'        => '1',
					),
					$extra
				)
			)
		);
	}

	protected function wipe_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * A ready, running server and its owner.
	 *
	 * @param array<string, mixed> $meta Initial meta.
	 * @return array{0: Resource, 1: int}
	 */
	protected function ready( array $meta = array() ): array {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		return array( $fresh, (int) $license['user_id'] );
	}

	/**
	 * The route as the tab script calls it.
	 *
	 * @param string               $method GET or PUT.
	 * @param int                  $id     Resource id.
	 * @param int                  $user   User id.
	 * @param array<string, mixed> $body   JSON body.
	 */
	protected function route( string $method, int $id, int $user, array $body = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, '/beltoft-services/v1/resources/' . $id . '/firewall' );

		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	protected function rule( string $proto, int $from, int $to = 0, array $sources = array(), string $label = '' ): array {
		return array( 'proto' => $proto, 'from' => $from, 'to' => $to ?: $from, 'sources' => $sources, 'label' => $label );
	}

	/**
	 * The comments of the rules the fake has for a VM.
	 *
	 * @param int $vmid VMID.
	 * @return string[]
	 */
	protected function comments( int $vmid ): array {
		return array_map( static fn( $rule ) => (string) ( $rule['comment'] ?? '' ), $this->fake->vm( $vmid )['firewall_rules'] );
	}
}

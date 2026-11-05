<?php
/**
 * Shared fixture of the hosting tests: a fake node, a fake agent, one saved node record. Nothing here reaches a
 * network or a real server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\FakeAgent;
use BeltoftServices\Hosting\FakeNode;
use BeltoftServices\Hosting\HostingDriver;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\Schema;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Base class of the hosting tests.
 */
abstract class HostingCase extends TestCase {

	protected const NODE_SECRET = 'node-token-secret-0001';
	protected const AGENT_KEY   = 'agent-key-secret-0001';

	/**
	 * The fake node API.
	 *
	 * @var FakeNode
	 */
	protected $node;

	/**
	 * The fake agent.
	 *
	 * @var FakeAgent
	 */
	protected $agent;

	/**
	 * The fake DNS server.
	 *
	 * @var FakeDns
	 */
	protected $dns;

	/**
	 * The driver under test.
	 *
	 * @var HostingDriver
	 */
	protected $driver;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe();
		$this->settings(
			array(
				'task_wait'   => 5,
				'admin_email' => 'christian.beltoft@gmail.com',
			)
		);
		$this->settings( array( 'hosting_preview_zone' => 'preview.example.test' ) );

		$this->node  = new FakeNode();
		$this->agent = new FakeAgent();
		add_filter( 'beltoft_services_hosting_client', fn() => $this->node );
		add_filter( 'beltoft_services_hosting_agent', fn() => $this->agent );

		$this->dns = new FakeDns();
		$this->dns->create_zone( 'preview.example.test', array( 'ns1.example.test.' ) );
		add_filter( 'beltoft_services_domains_dns', fn() => $this->dns );

		$this->driver = Registry::get( 'hosting' );

		$this->node->add_node( 'pve1' );
		$this->node->add_pool( 'hosting-pool' );
		$this->node->add_template( 9000, 'pve1' );
		$this->save_node();

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_hosting_client' );
		remove_all_filters( 'beltoft_services_hosting_agent' );
		remove_all_filters( 'beltoft_services_domains_dns' );
		$this->wipe();

		parent::tear_down();
	}

	/**
	 * Empties the hosting tables and options.
	 */
	protected function wipe(): void {
		global $wpdb;

		foreach ( Schema::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
			$wpdb->query( 'DELETE FROM ' . $table );
		}

		delete_option( Nodes::OPTION );
	}

	/**
	 * Saves the node record "n1".
	 *
	 * @param array<string, mixed> $over Form overrides.
	 */
	protected function save_node( array $over = array(), string $id = 'n1' ): void {
		$saved = Nodes::save(
			$id,
			array_merge(
				array(
					'label'        => 'Node one',
					'node_group'   => 'g1',
					'client'       => 'fake',
					'api_url'      => 'https://node.example.invalid:8006',
					'token_id'     => 'svc@pve!hosting',
					'token_secret' => self::NODE_SECRET,
					'pve_node'     => 'pve1',
					'agent_url'    => 'https://node.example.invalid:8765',
					'agent_key_id' => 'k1',
					'agent_key'    => self::AGENT_KEY,
					'pool'         => 'hosting-pool',
					'storage'      => 'local-zfs',
					'bridge'       => 'hbr0',
					'gateway'      => '10.200.0.1',
					'lan_cidr'     => '10.200.0.0/16',
					'ip_first'     => 10,
					'ssh_port_min' => 22001,
					'ssh_port_max' => 22999,
					'vmid_min'     => 1000,
					'vmid_max'     => 1999,
					'template'     => 9000,
					'net_mbit'     => 0,
					'mem_gb'       => 32,
					'disk_gb'      => 1000,
					'db_host'      => '10.200.0.3',
					'edge_ip'      => '198.51.100.7',
					'edge_ipv6'    => '2001:db8::7',
				),
				$over
			)
		);

		$this->assertTrue( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
	}

	/**
	 * Makes a hosting resource: a product on the hosting driver, a license without the creation listeners (the
	 * test drives the driver itself), the resource.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 * @param array<string, mixed> $meta Initial meta.
	 */
	protected function resource( array $plan = array(), array $meta = array() ): Resource {
		$product = $this->service_product(
			'hosting',
			array_merge(
				array(
					'node_group'  => 'g1',
					'sites'       => 3,
					'disk_gb'     => 10,
					'cores'       => 2,
					'memory_mb'   => 2048,
					'swap_mb'     => 0,
					'php_workers' => 20,
					'valkey_mb'   => 64,
				),
				$plan
			),
			array( 'max' => 3 )
		);

		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'hosting' ), Product::plan( $product->get_id() ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	/**
	 * One run of the driver, as the reconciler would make it (a fresh read each time).
	 *
	 * @param Resource $r Resource.
	 */
	protected function provision( Resource $r ): StepResult {
		return $this->driver->provision( Resource::find( $r->id() ) );
	}

	/**
	 * Provisions until it is done, failing the test when it needs more than $rounds runs.
	 *
	 * @param Resource $r      Resource.
	 * @param int      $rounds Most runs.
	 */
	protected function provision_fully( Resource $r, int $rounds = 12 ): StepResult {
		$result = null;

		for ( $i = 0; $i < $rounds; $i++ ) {
			$result = $this->provision( $r );

			if ( ! $result->is_retry() ) {
				break;
			}
		}

		$this->assertTrue( $result->is_done(), 'Provisioning ended: ' . ( $result ? $result->error() : '' ) );

		return $result;
	}

	/**
	 * The container of a resource in the fake node.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	protected function ct( Resource $r ): array {
		$record = \BeltoftServices\Hosting\Records::for_resource( $r->id() );
		$cts    = $this->node->state()['cts'];

		$this->assertArrayHasKey( (int) $record['vmid'], $cts );

		return $cts[ (int) $record['vmid'] ];
	}
}

<?php
/**
 * Shared set-up of the backup tests: a fake cluster with a backup storage and a ready VM with backups on its plan.
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
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Fake cluster `lab` with backup storage `backup-store`. FakeClient only: nothing reaches a Proxmox host.
 */
abstract class BackupTestCase extends TestCase {

	protected const SECRET = 'secret-secret-secret-1';

	/**
	 * The fake cluster.
	 *
	 * @var FakeClient
	 */
	protected $fake;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $mails = array();

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

		$this->backup_wipe();
		delete_option( Clusters::OPTION );
		$this->settings(
			array(
				'task_wait'   => 5,
				'pause_all'   => 0,
				'admin_email' => 'admin@example.com',
			)
		);

		$this->fake = new FakeClient();
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
		ProxmoxJobs::unschedule();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		$this->backup_wipe();
		ProxmoxJobs::unschedule();

		parent::tear_down();
	}

	protected function backup_wipe(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * Saves cluster "lab".
	 *
	 * @param array<string, mixed> $over Overrides.
	 */
	protected function save_cluster( array $over = array() ): void {
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
						'backup_storage'    => 'backup-store',
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
					$over
				)
			)
		);
	}

	/**
	 * A ready, running VM whose plan has backups.
	 *
	 * @param array<string, mixed> $plan Plan overrides (backup_days defaults to 7).
	 * @param array<string, mixed> $meta Initial meta.
	 */
	protected function ready_vm( array $plan = array(), array $meta = array() ): Resource {
		$plan     = array_merge(
			array(
				'cluster'     => 'lab',
				'template'    => 9000,
				'cores'       => 2,
				'memory_mb'   => 4096,
				'disk_gb'     => 30,
				'backup_days' => 7,
			),
			$plan
		);
		$product  = $this->service_product( 'proxmox', $plan, array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		return $fresh;
	}

	/**
	 * Calls matching a method and a path ending.
	 *
	 * @param string $method Method.
	 * @param string $suffix Path ending.
	 * @return array<int, array<string, mixed>>
	 */
	protected function calls( string $method, string $suffix ): array {
		return array_values(
			array_filter(
				$this->fake->calls(),
				static fn( $call ) => $call['method'] === $method && substr( $call['path'], -strlen( $suffix ) ) === $suffix
			)
		);
	}

	/**
	 * A variable product with attributes Tier (a, b) and Backups (None, Daily): four variations.
	 *
	 * @param array<string, string> $prices Price per backups value.
	 * @return array<string, \WC_Product_Variation>
	 */
	protected function variable_product( array $prices = array( 'None' => '10', 'Daily' => '12' ) ): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'VPS ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$tier = new \WC_Product_Attribute();
		$tier->set_name( 'Tier' );
		$tier->set_options( array( 'a', 'b' ) );
		$tier->set_visible( true );
		$tier->set_variation( true );

		$backups = new \WC_Product_Attribute();
		$backups->set_name( 'Backups' );
		$backups->set_options( array( 'None', 'Daily' ) );
		$backups->set_visible( true );
		$backups->set_variation( true );

		$product->set_attributes( array( $tier, $backups ) );
		$product->update_meta_data( \BeltoftServices\Product::META_DRIVER, 'proxmox' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'vps-' . wp_generate_password( 5, false ) );

		foreach ( array(
			'cluster'   => 'lab',
			'template'  => 9000,
			'cores'     => 2,
			'memory_mb' => 4096,
			'disk_gb'   => 30,
		) as $key => $value ) {
			$product->update_meta_data( \BeltoftServices\Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		\BeltoftServices\Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 'a', 'b' ) as $t ) {
			foreach ( array( 'None', 'Daily' ) as $b ) {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'tier' => $t, 'backups' => $b ) );
				$v->set_regular_price( $prices[ $b ] );
				$v->set_status( 'publish' );
				$v->update_meta_data( \BeltoftServices\Product::META_PLAN . 'backup_days', 'Daily' === $b ? '7' : '0' );
				$v->save();
				$out[ $t . '-' . $b ] = $v;
			}
		}

		return $out;
	}
}

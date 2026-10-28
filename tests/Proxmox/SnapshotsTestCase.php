<?php
/**
 * Shared set-up and helpers of the snapshot tests. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

abstract class SnapshotsTestCase extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();

		// The seeded template boots from a raw file, as the real one does: a clone of it is qcow2.
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/9000/config', array( 'scsi0' => 'disk-store:vm-9000-disk-0.raw,size=10G' ) ) );
		delete_transient( 'beltoft_services_disk_products' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_proxmox_disk_format' );
		remove_all_filters( 'beltoft_services_snapshot_paid_slots' );
		remove_all_filters( 'beltoft_services_snapshot_rolled_back' );
		delete_transient( 'beltoft_services_disk_products' );
		parent::tear_down();
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * Switches snapshots on.
	 *
	 * @param array<string, mixed> $more Settings.
	 */
	protected function on( array $more = array() ): void {
		$this->settings( array_merge( array( 'snapshots_enabled' => 1, 'snapshot_free_slots' => 3, 'snapshot_free_days' => 7, 'snapshot_paid_days' => 30, 'snapshot_max_slots' => 10 ), $more ) );
	}

	protected function server(): Resource {
		$this->on();

		return $this->ready_vm();
	}

	protected function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk    = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );

		return $disk;
	}

	protected function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	protected function act( Resource $r, string $action, array $args = array() ): void {
		Jobs::act( $r->id(), $action, $args );
	}

	protected function take( Resource $r, string $label = 'first' ): array {
		$this->act( $r, 'snapshot_take', array( 'label' => $label ) );

		foreach ( Snapshots::entries( $this->fresh( $r ) ) as $entry ) {
			if ( $entry['label'] === $label ) {
				return $entry;
			}
		}

		$this->fail( 'The snapshot "' . $label . '" is not in the list.' );
	}

	protected function config(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}

	protected function lines(): array {
		return array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], $this->fake->calls() );
	}

	protected function events_of( Resource $r ): string {
		return implode( "\n", array_column( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), 'message' ) );
	}

	protected function admin_mails(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ) );
	}

	protected function route( int $id, int $user, string $action, array $args = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/actions/' . $action );
		$request->set_param( 'args', $args );

		return rest_get_server()->dispatch( $request );
	}

	protected function backup_volid( Resource $r ): string {
		$this->assertIsString( $this->fake->task( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => (int) $r->get( 'vmid' ), 'storage' => 'backup-store' ) ) );
		$list = ( new \BeltoftServices\Proxmox\Backups( $this->fresh( $r ) ) )->list();

		return $list[0]['volid'];
	}
}

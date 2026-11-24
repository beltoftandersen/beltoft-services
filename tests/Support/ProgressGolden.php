<?php
/**
 * The resource builder and the golden step payloads of ProgressTest, shared with the hosting part of that fixture.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Progress;
use BeltoftServices\Resource;

/**
 * Needs the methods of BeltoftServices\Tests\TestCase.
 */
trait ProgressGolden {

	/**
	 * A resource of a driver id, with meta.
	 *
	 * @param string               $driver Driver id stored in the meta.
	 * @param array<string, mixed> $meta   Meta.
	 * @return array{0: Resource, 1: FakeDriver, 2: int}
	 */
	private function resource( string $driver, array $meta ): array {
		$fake                   = $this->fake_driver();
		$fake->customer_actions = array( 'reinstall' => 'Reinstall', 'restart' => 'Restart' );
		$product                = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$row                    = $this->service_license( $product, array( 'fire' => false ) );
		$resource               = Resource::create( $row, $fake, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'driver' => $driver ) );
		wp_set_current_user( (int) $row['user_id'] );

		return array( $resource, $fake, (int) $row['user_id'] );
	}

	/**
	 * Every step table, title and text a customer can see, by driver id and state. The fixture was written
	 * from the code before the steps moved into the drivers; it must not change.
	 *
	 * @param string[] $ids Driver ids.
	 * @return array<string, mixed>
	 */
	private function golden_payloads( array $ids ): array {
		$steps = array( 'ip', 'vmid', 'clone', 'place', 'contact', 'csr', 'claim', 'order', 'server', 'slot', 'net' );
		$now   = time();
		$cases = array(
			'queued'          => array( 'state' => 'requested' ),
			'building'        => array( 'state' => 'provisioning', 'steps' => array() ),
			'building-some'   => array( 'state' => 'provisioning', 'steps' => $steps ),
			'failed-build'    => array( 'state' => 'failed', 'failed_op' => 'provision', 'steps' => array( 'ip', 'contact' ) ),
			'failed-update'   => array( 'state' => 'failed', 'failed_op' => 'update_plan' ),
			'reinstall'       => array( 'state' => 'provisioning', 'wipe' => true, 'steps' => array( 'ip' ), 'last_reinstall_at' => 1700000000 ),
			'reinstall-wiped' => array( 'state' => 'provisioning', 'steps' => array( 'ip', 'clone' ), 'last_reinstall_at' => 1700000000 ),
			'restore-start'   => array( 'state' => 'ready', 'restore' => array( 'volid' => 'v', 'phase' => '' ) ),
			'restore-done'    => array( 'state' => 'ready', 'restore' => array( 'volid' => 'v', 'phase' => 'restored' ) ),
			'rollback-start'  => array( 'state' => 'ready', 'snapshot_rollback' => array( 'id' => 'a', 'phase' => 'stopping' ) ),
			'rollback-after'  => array( 'state' => 'ready', 'plan_dirty' => true, 'snapshot_rolled_at' => $now ),
			'update'          => array( 'state' => 'ready', 'plan_dirty' => true ),
			'delete'          => array( 'state' => 'ready', 'desired' => 'deleted' ),
			'pause'           => array( 'state' => 'ready', 'desired' => 'paused' ),
			'resume'          => array( 'state' => 'paused', 'desired' => 'ready' ),
			'marker-label'    => array( 'state' => 'ready', Progress::MARKER => array( 'action' => 'fw_apply', 'at' => $now, 'label' => 'Apply firewall changes' ) ),
			'marker-plain'    => array( 'state' => 'ready', Progress::MARKER => array( 'action' => 'x', 'at' => $now, 'label' => '' ) ),
			'marker-restore'  => array( 'state' => 'ready', Progress::MARKER => array( 'action' => 'restore', 'at' => $now, 'label' => 'Restore' ) ),
			'marker-rollback' => array( 'state' => 'ready', Progress::MARKER => array( 'action' => 'snapshot_rollback', 'at' => $now, 'label' => 'Go back' ) ),
			'restore-failed'  => array( 'state' => 'failed', 'failed_op' => 'update_plan', 'restore' => array( 'volid' => 'v', 'phase' => 'restored' ) ),
			'marker-reinstall' => array( 'state' => 'ready', Progress::MARKER => array( 'action' => 'reinstall', 'at' => $now, 'label' => 'Reinstall' ) ),
			'idle'            => array( 'state' => 'ready' ),
			'app-installing'  => array( 'state' => 'ready', 'app_id' => 'wordpress', 'app_state' => 'installing', 'app_step' => 'database' ),
			'app-pending'     => array( 'state' => 'ready', 'app_id' => 'wordpress', 'app_state' => 'pending' ),
			'app-slow'        => array( 'state' => 'ready', 'app_id' => 'wordpress', 'app_state' => 'unknown', 'app_step' => 'packages' ),
			'app-failed'      => array( 'state' => 'ready', 'app_id' => 'wordpress', 'app_state' => 'failed', 'app_step' => 'packages' ),
			'app-ready'       => array( 'state' => 'ready', 'app_id' => 'wordpress', 'app_state' => 'ready' ),
			'build-with-app'  => array( 'state' => 'provisioning', 'app_id' => 'wordpress', 'app_state' => 'pending', 'steps' => array( 'ip', 'vmid' ) ),
			'reinstall-app'   => array( 'state' => 'provisioning', 'wipe' => true, 'app_id' => 'wordpress', 'app_state' => 'pending', 'last_reinstall_at' => 1700000000 ),
			'queued-app'      => array( 'state' => 'requested', 'app_id' => 'wordpress', 'app_state' => 'pending' ),
		);
		$out   = array();

		foreach ( $ids as $id ) {
			foreach ( $cases as $name => $meta ) {
				list( $r ) = $this->resource( $id, $meta );
				$payload   = Progress::build( Resource::find( $r->id() ) );

				unset( $payload['started'] );
				$out[ $id . ' / ' . $name ] = $payload;
			}
		}

		return $out;
	}
}

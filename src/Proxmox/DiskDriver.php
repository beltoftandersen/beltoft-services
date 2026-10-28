<?php
/** Extra disk service driver. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/** One subscription and resource per extra disk. */
final class DiskDriver implements Driver {
	public const ID = 'proxmox-disk';

	/** Use the server retention default unless the disk product sets its own period. */
	public static function default_retention( $days, $product_id ) {
		$driver = \BeltoftServices\Product::driver_for( (int) $product_id );
		return $driver && self::ID === $driver->id() ? ProxmoxDriver::DEFAULT_RETENTION_DAYS : $days;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Extra disk', 'beltoft-services' );
	}

	public function kind(): string {
		return 'disk';
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: disks used, 2: disks allowed. */
		return sprintf( _n( '%1$d of %2$d disk', '%1$d of %2$d disks', $max, 'beltoft-services' ), $used, $max );
	}

	public function plan_fields(): array {
		return array(
			'disk_gb' => array( 'number', __( 'Disk size (GB)', 'beltoft-services' ), __( 'The disk can grow but cannot shrink.', 'beltoft-services' ), 50, true ),
		);
	}

	public function provision( Resource $r ): StepResult {
		return ( new DiskSteps( $r ) )->provision();
	}

	public function pause( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function resume( Resource $r ): StepResult {
		// The volume name is stored when the slot is claimed, before anything is allocated or attached: only a verified attach counts.
		$steps = (array) $r->get( 'steps' );
		return $r->get( 'volname' ) && in_array( 'attach', $steps, true ) && in_array( 'verify', $steps, true ) ? StepResult::done() : $this->provision( $r );
	}

	public function update_plan( Resource $r ): StepResult {
		return ( new DiskSteps( $r ) )->grow();
	}

	public function delete( Resource $r ): StepResult {
		return ( new DiskSteps( $r ) )->delete();
	}

	public function status( Resource $r ): ?array {
		return null;
	}

	public function customer_actions( Resource $r ): array {
		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::fail( 'This disk action is not available.' );
	}

	public function render_account( Resource $r ): void {
		DiskPanels::account( $r );
	}

	public function render_admin( Resource $r ): void {
		DiskPanels::admin( $r );
	}
}

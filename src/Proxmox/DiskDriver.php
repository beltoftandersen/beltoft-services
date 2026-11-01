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

	/**
	 * Adds the paid-disk lines to the failed-service admin mail when a paid disk was not delivered.
	 *
	 * @param mixed                $lines   Mail body lines.
	 * @param Resource             $r       Failed resource.
	 * @param array<string, mixed> $license License row.
	 * @return array<int, string>
	 */
	public static function failure_alert_lines( $lines, $r, $license ): array {
		$lines = is_array( $lines ) ? $lines : array();

		if ( ! $r instanceof Resource || self::ID !== $r->get( 'driver' ) || 'provision' !== $r->get( 'failed_op' ) || (int) ( is_array( $license ) ? ( $license['order_id'] ?? 0 ) : 0 ) <= 0 ) {
			return $lines;
		}

		$lines[] = sprintf( 'Paid disk not delivered: review and refund order #%d.', (int) $license['order_id'] );
		$order   = wc_get_order( (int) $license['order_id'] );
		$item_id = (int) $r->get( 'order_item_id' );
		$item    = $order && $item_id ? $order->get_item( $item_id ) : null;

		if ( $item instanceof \WC_Order_Item_Product ) {
			$lines[] = sprintf( 'Disk line charged: %s %s including tax.', (string) $order->get_currency(), (string) ( (float) $item->get_total() + (float) $item->get_total_tax() ) );
		}

		return $lines;
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
		return ( new DiskSteps( $r ) )->pause();
	}

	public function resume( Resource $r ): StepResult {
		// Only a verified attach counts; a disk that never got one continues provisioning, one that was detached goes back.
		return ( new DiskSteps( $r ) )->reattach();
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

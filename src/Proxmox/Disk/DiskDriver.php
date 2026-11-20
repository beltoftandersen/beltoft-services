<?php
/** Extra disk service driver. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Disk;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\ProvisionSteps;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/** One subscription and resource per extra disk. */
final class DiskDriver implements Driver, ProvisionSteps, \BeltoftServices\Drivers\UsageItems {

	use \BeltoftServices\Drivers\UsageDefaults;
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

	/**
	 * {@inheritDoc}
	 */
	public function step_labels(): array {
		return array(
			'server'       => __( 'Finding the server', 'beltoft-services' ),
			'slot'         => __( 'Choosing a slot', 'beltoft-services' ),
			'allocate'     => __( 'Creating the disk', 'beltoft-services' ),
			'attach'       => __( 'Attaching it to the server', 'beltoft-services' ),
			'verify'       => __( 'Final checks', 'beltoft-services' ),
		);
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

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource              $r       Resource.
	 * @param \WC_Product_Variation $v       Variation.
	 * @param float                 $monthly Monthly price.
	 * @param float                 $premium Premium.
	 */
	public function usage_items( Resource $r, \WC_Product_Variation $v, float $monthly, float $premium ): array {
		list( $gb, $basis ) = self::usage_basis( $r, $v, $monthly );

		return $gb > 0 ? array( array( 'item' => 'disk', 'quantity' => $gb, 'unit' => 'GB', 'rate' => Rates::hourly( $basis / $gb, $premium ) ) ) : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_words(): array {
		return array( 'disk' => array( 'label' => __( 'extra disk', 'beltoft-services' ), 'word' => __( 'Extra disk', 'beltoft-services' ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_is_addon(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * An extra disk is shown with its server ("Extra disk for web1"): its own product name says nothing to the customer.
	 *
	 * @param Resource $r Resource.
	 */
	public function usage_label( Resource $r ): ?string {
		$server  = Resource::for_license( (int) $r->get( 'server_license' ) );
		$name    = $server ? (string) $server->get( 'server_name' ) : '';
		$name    = '' === $name && $server && '' !== (string) $server->get( 'uuid' ) ? 'vm-' . substr( (string) $server->get( 'uuid' ), 0, 8 ) : $name;
		$parent  = $server ? wc_get_product( (int) ( $server->license()['product_id'] ?? 0 ) ) : null;
		$product = wc_get_product( (int) ( $r->license()['product_id'] ?? 0 ) );

		return '' !== $name ? $name : ( $parent ? $parent->get_name() : ( $product ? $product->get_name() : '#' . $r->id() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function usage_reference( Resource $r ): ?string {
		$server = Resource::for_license( (int) $r->get( 'server_license' ) );
		$uuid   = (string) ( $server ?? $r )->get( 'uuid' );

		return '' !== $uuid ? 'vm-' . substr( $uuid, 0, 8 ) : null;
	}

	/**
	 * The size and the monthly price an extra disk is metered by. Normally the licence's variation: its plan size and its
	 * price. While a grow is pending (the plan asks for more than the disk really has, `size_gb` being what the driver
	 * measured) the disk is metered by the size it really has, at the price of the variation of that size (or, when no such
	 * variation exists, the same price per GB), so the customer pays for the larger size only from the hour the disk is larger.
	 *
	 * @param Resource              $r       Disk.
	 * @param \WC_Product_Variation $v       The licence's variation.
	 * @param float                 $monthly Its monthly price.
	 * @return array{0: float, 1: float} GB and the monthly price for that many.
	 */
	private static function usage_basis( Resource $r, \WC_Product_Variation $v, float $monthly ): array {
		$planned = (float) ( ( (array) $r->get( 'plan' ) )['disk_gb'] ?? 0 );
		$real    = (float) $r->get( 'size_gb' );

		if ( $planned <= 0 || $real <= 0 || $real >= $planned ) {
			return array( $planned, $monthly );
		}

		foreach ( DiskField::sizes_for( true ) as $candidate ) {
			$size = (float) ( Product::plan( $candidate->get_parent_id(), $candidate->get_id() )['disk_gb'] ?? 0 );

			if ( $candidate->get_parent_id() === $v->get_parent_id() && $size === $real ) {
				$price = Rates::monthly( $candidate );

				if ( null !== $price ) {
					return array( $real, $price );
				}
			}
		}

		return array( $real, $monthly * $real / $planned );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_delete(): ?array {
		return array(
			'kind'    => 'disk',
			'text'    => __( 'Removing deletes the extra disk and all its data, including the snapshots that include it. It cannot be undone. Billing stops in the hour it is removed. The server and the usage account are not touched.', 'beltoft-services' ),
			'billing' => __( 'The disk is billed by the hour while it exists. Remove it to stop the charge.', 'beltoft-services' ),
			'stopped' => __( 'A stopped server is still billed. Delete it to stop the charge.', 'beltoft-services' ),
			'busy'    => __( 'Something is still running on this disk, such as a setup or a resize. Please try again when it has finished.', 'beltoft-services' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_breakdown(): ?array {
		return array(
			'extra' => array(
				'key'      => 'disk',
				'label'    => __( 'Extra disk', 'beltoft-services' ),
				/* translators: %d: disk size in gigabytes */
				'sized'    => __( 'Extra disk, %d GB', 'beltoft-services' ),
				'size_key' => 'disk_gb',
			),
		);
	}
}

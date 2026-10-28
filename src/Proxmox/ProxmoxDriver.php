<?php
/**
 * The Proxmox virtual machine driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\InternalActions;
use BeltoftServices\Drivers\NeedsBilling;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One virtual machine per unit, cloned from a template on a configured cluster. This task covers
 * provisioning; pause, resume, delete and the plan change arrive with the next task, and until
 * then they refuse without touching Proxmox. No sign-in link: a VM has none (no Drivers\Opens).
 */
class ProxmoxDriver implements Driver, StopsOnDelete, NeedsBilling, InternalActions {

	/**
	 * Days a VM product is kept after its license ends, unless the product says otherwise.
	 */
	public const DEFAULT_RETENTION_DAYS = 3;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return SshKeyField::DRIVER;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Proxmox VM', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function kind(): string {
		return 'vm';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $used Used.
	 * @param int $max  Allowed.
	 */
	public function unit_label( int $used, int $max ): string {
		/* translators: 1: servers used, 2: servers allowed */
		return sprintf( _n( '%1$d of %2$d server', '%1$d of %2$d servers', $max, 'beltoft-services' ), $used, $max );
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan_fields(): array {
		$clusters  = array();
		$templates = array();

		foreach ( array_keys( Clusters::all() ) as $id ) {
			$clusters[] = $id;
			$list       = array();

			foreach ( Clusters::templates( (string) $id ) as $vmid => $label ) {
				$list[] = $vmid . ' = ' . $label;
			}

			$templates[] = $id . ': ' . ( $list ? implode( ', ', $list ) : '-' );
		}

		$none = __( 'none yet', 'beltoft-services' );

		return array(
			'cluster'   => array(
				'text',
				__( 'Cluster', 'beltoft-services' ),
				/* translators: %s: cluster ids, comma separated. */
				sprintf( __( 'The id of the Proxmox cluster to build on. Configured: %s', 'beltoft-services' ), $clusters ? implode( ', ', $clusters ) : $none ),
				'',
				true,
			),
			'template'  => array(
				'number',
				__( 'Template VMID', 'beltoft-services' ),
				/* translators: %s: templates per cluster. */
				sprintf( __( 'The fallback template VMID, used when the customer made no operating system choice (older orders, licenses issued by an admin). It must be in the cluster\'s template list. Configured: %s', 'beltoft-services' ), $templates ? implode( '; ', $templates ) : $none ),
				'',
				true,
			),
			'cores'     => array(
				'number',
				__( 'CPU cores', 'beltoft-services' ),
				'',
				1,
				true,
			),
			'memory_mb' => array(
				'number',
				__( 'Memory (MB)', 'beltoft-services' ),
				'',
				2048,
				true,
			),
			'disk_gb'   => array(
				'number',
				__( 'Disk (GB)', 'beltoft-services' ),
				__( 'The boot disk grows to this size. It is never shrunk.', 'beltoft-services' ),
				30,
				true,
			),
			'backup_days' => array(
				'number',
				__( 'Backup days', 'beltoft-services' ),
				__( 'Daily backups kept for this many days. 0 means no backups. Needs a backup storage on the cluster.', 'beltoft-services' ),
				0,
				true,
			),
		);
	}

	/**
	 * The default retention of VM products: used when the product's own field is empty. An explicit 0 still means never.
	 *
	 * @param mixed $days       Days so far.
	 * @param mixed $product_id Product or variation id.
	 * @return mixed
	 */
	public static function default_retention( $days, $product_id ) {
		$driver = Product::driver_for( (int) $product_id );

		return $driver && SshKeyField::DRIVER === $driver->id() ? self::DEFAULT_RETENTION_DAYS : $days;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function provision( Resource $r ): StepResult {
		$run = new Provision( $r, array( $this, 'nap' ) );

		return $run->signal( $run->run() );
	}

	/**
	 * Sleeps between guest-agent pings. Public only so it can be passed as a callable; tests override it.
	 *
	 * @param int $seconds Seconds.
	 */
	public function nap( int $seconds ): void {
		sleep( $seconds );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function pause( Resource $r ): StepResult {
		$op = new Operations( $r );

		return $op->signal( $op->pause() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A service paused before its provisioning ended continues provisioning (the reconciler sends
	 * `resume` for it too).
	 *
	 * @param Resource $r Resource.
	 */
	public function resume( Resource $r ): StepResult {
		if ( ! in_array( 'ready', (array) $r->get( 'steps' ), true ) ) {
			return $this->provision( $r );
		}

		$op = new Operations( $r );

		return $op->signal( $op->resume() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function update_plan( Resource $r ): StepResult {
		$op = new Operations( $r );

		return $op->signal( $op->update_plan() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function delete( Resource $r ): StepResult {
		$op = new Operations( $r );

		return $op->signal( $op->delete() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function status( Resource $r ): ?array {
		return ( new Operations( $r ) )->status();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function customer_actions( Resource $r ): array {
		return Actions::offered( $r );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function act( Resource $r, string $action, array $args ): StepResult {
		return ( new Actions( $r ) )->run( $action, $args );
	}

	/**
	 * {@inheritDoc}
	 */
	public function internal_actions(): array {
		return Snapshots::INTERNAL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function render_account( Resource $r ): void {
		Panels::account( $r );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function render_admin( Resource $r ): void {
		Panels::admin( $r );
	}
}

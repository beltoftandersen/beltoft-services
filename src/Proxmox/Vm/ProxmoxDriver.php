<?php
/**
 * The Proxmox virtual machine driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\InternalActions;
use BeltoftServices\Drivers\NeedsBilling;
use BeltoftServices\Drivers\ProvisionSteps;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Snapshot\Snapshots;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * One virtual machine per unit, cloned from a template on a configured cluster. Provisioning,
 * power, the plan change and deletion run through Provision and Operations, and every write to
 * Proxmox is behind Ownership::verify. No sign-in link: a VM has none (no Drivers\Opens).
 */
class ProxmoxDriver implements Driver, StopsOnDelete, NeedsBilling, InternalActions, ProvisionSteps, \BeltoftServices\Drivers\TakesSshKey, \BeltoftServices\Drivers\UsageItems {

	use \BeltoftServices\Drivers\UsageDefaults;

	/**
	 * Driver id of the Proxmox driver.
	 */
	public const ID = 'proxmox';

	/**
	 * Days a VM product is kept after its license ends, unless the product says otherwise.
	 */
	public const DEFAULT_RETENTION_DAYS = 3;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function step_labels(): array {
		return array(
			'ip'           => __( 'Reserving an IP address', 'beltoft-services' ),
			'vmid'         => __( 'Preparing the server', 'beltoft-services' ),
			'clone'        => __( 'Creating the server', 'beltoft-services' ),
			'configure'    => __( 'Applying your settings', 'beltoft-services' ),
			'start'        => __( 'Starting the server', 'beltoft-services' ),
			'ready'        => __( 'Final checks', 'beltoft-services' ),
		);
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
			'net_mbit'  => array(
				'number',
				__( 'Network speed (Mbit/s)', 'beltoft-services' ),
				__( 'Speed limit on the public network card, in Mbit/s. 0 or empty means no limit. The private network is never limited.', 'beltoft-services' ),
				0,
				true,
			),
			'public_ip' => array(
				'number',
				__( 'Public IPv4', 'beltoft-services' ),
				__( '1 (the default) gives the server a public IPv4 address from the pool. 0 makes a server with IPv6 only (VPS Basic); the IPv4 address add-on can give it an address later.', 'beltoft-services' ),
				1,
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

		return $driver && self::ID === $driver->id() ? self::DEFAULT_RETENTION_DAYS : $days;
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
		return array_merge( Snapshots::INTERNAL, Firewall::INTERNAL, \BeltoftServices\Proxmox\Ipv4\Ipv4Server::ACTIONS );
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

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource              $r       Resource.
	 * @param \WC_Product_Variation $v       Variation.
	 * @param float                 $monthly Monthly price.
	 * @param float                 $premium Premium.
	 */
	public function usage_items( Resource $r, \WC_Product_Variation $v, float $monthly, float $premium ): array {
		$server = $monthly;
		$extra  = 0.0;
		$out    = array();

		if ( Backups::days( $r ) > 0 ) {
			$without = Backups::sibling( $v, false );
			$base    = $without ? Rates::monthly( $without ) : null;

			if ( null !== $base && $monthly > $base ) {
				$server = $base;
				$extra  = $monthly - $base;
			}
		}

		$out[] = array( 'item' => 'server', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => Rates::hourly( $server, $premium ) );

		if ( $extra > 0 && Backups::enabled( $r ) ) {
			$out[] = array( 'item' => 'backups', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => Rates::hourly( $extra, $premium ) );
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_words(): array {
		return self::server_words() + array( 'backups' => array( 'label' => __( 'backups', 'beltoft-services' ), 'word' => __( 'Backups', 'beltoft-services' ) ) );
	}

	/**
	 * The words of the server item (shared with the dedicated server, which bills the same item).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function server_words(): array {
		/* translators: %s: server name. */
		return array( 'server' => array( 'label' => __( 'server', 'beltoft-services' ), 'word' => __( 'Server', 'beltoft-services' ), 'plan' => true, 'subject' => __( 'Server %s', 'beltoft-services' ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_delete(): ?array {
		return array(
			'kind'    => 'server',
			'text'    => __( 'Deleting removes the server and all its data, including snapshots and backups. It cannot be undone. Billing stops in the hour it is deleted.', 'beltoft-services' ),
			'billing' => __( 'A stopped server is still billed. Delete it to stop the charge.', 'beltoft-services' ),
			'stopped' => __( 'A stopped server is still billed. Delete it to stop the charge.', 'beltoft-services' ),
			'busy'    => __( 'Something is still running on this server, such as a setup, a reinstall or a restore. Please try again when it has finished.', 'beltoft-services' ),
			'blocker' => self::delete_blocker( ... ),
			'word'    => static function ( Resource $r ): string {
				// A server is confirmed with the name its page shows: the customer's name for it, else vm-<first 8 characters of its uuid>.
				$name = trim( Provision::vm_name( $r ) );

				return 'vm-' === $name ? '' : $name;
			},
			'licence' => self::delete_licence_note( ... ),
		);
	}

	/**
	 * A server with an extra disk is deleted after the disk.
	 *
	 * @param Resource $r Server.
	 */
	private static function delete_blocker( Resource $r ): ?string {
		foreach ( \BeltoftServices\Proxmox\Disk\DiskMeta::for_server( $r ) as $disk ) {
			if ( 'deleted' !== $disk->get( 'state' ) ) {
				return __( 'This server has an extra disk. Remove the disk first, then delete the server.', 'beltoft-services' );
			}
		}

		return null;
	}

	/**
	 * One sentence for a server with a paid app licence of its own subscription: the licence stays on the account until the end of
	 * the month paid and can be used on a new server. '' for a licence still billed by the hour with its server (sold before the
	 * licence became monthly), where the hourly billing note is already true.
	 *
	 * @param Resource $r Server.
	 */
	private static function delete_licence_note( Resource $r ): string {
		$licence = \BeltoftServices\Apps\AppLicence::current( $r );

		if ( ! $licence || \BeltoftServices\Apps\AppLicence::usage_billed( $licence ) || 'ended' === \BeltoftServices\Apps\AppLicence::state( $licence ) ) {
			return '';
		}

		$until = \BeltoftServices\Apps\AppLicenceKeep::keepable( $licence ) ? \BeltoftServices\Apps\AppLicenceKeep::paid_until_text( $licence ) : '';

		if ( '' === $until ) {
			// Nothing to keep (the paid time is over): the licence ends with the server.
			return __( 'The app licence ends with the server.', 'beltoft-services' );
		}

		/* translators: %s: date */
		return sprintf( __( 'The app licence stays on your account until %s, the end of the month you paid for, and can be used on a new server until then. It is not renewed unless it is used.', 'beltoft-services' ), $until );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_breakdown(): ?array {
		return array(
			'single_line' => false,
			'data_disk'   => false,
			'parts'       => static function ( \WC_Product_Variation $usage, callable $price ): array {
				$base    = $price( $usage );
				$backups = 0.0;

				if ( (int) ( Product::plan( $usage->get_parent_id(), $usage->get_id() )['backup_days'] ?? 0 ) > 0 ) {
					$without = Backups::sibling( $usage, false );
					$backups = $without ? $price( $usage ) - $price( $without ) : 0.0;
					$base    = $without ? $price( $without ) : $price( $usage );
				}

				return array( 'base' => $base, 'ip' => 0.0, 'storage' => 0.0, 'backups' => $backups, 'gb' => 0 );
			},
		);
	}
}

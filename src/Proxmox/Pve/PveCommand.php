<?php
/**
 * Proxmox commands: link an existing disk or virtual machine, read VM metrics.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Monitoring\Metrics;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\Disk\DiskMeta;
use BeltoftServices\Proxmox\Vm\GuestDisk;
use BeltoftServices\Proxmox\Vm\LinkVm;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\Provision;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Proxmox commands: link an existing disk or virtual machine, read VM metrics.
 */
final class PveCommand {
	/**
	 * Link an existing disk without changing its VM. Dry run makes no Proxmox call.
	 *
	 * ## OPTIONS
	 * <license-id> : The disk license.
	 * <server-license-id> : Its owner's server license.
	 * <slot> : The existing scsi1 through scsi30 slot.
	 * [--dry-run] : Print the intended read and bookkeeping change.
	 * [--confirm=<text>] : LINK-DISK:<slot> on a live cluster.
	 *
	 * @subcommand link-disk
	 */
	public function link_disk( array $args, array $assoc_args ): void {
		$disk_license = Licenses::find( (int) ( $args[0] ?? 0 ) );
		$server_license = Licenses::find( (int) ( $args[1] ?? 0 ) );
		$slot = (string) ( $args[2] ?? '' );
		if ( ! $disk_license || ! $server_license || ! preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/D', $slot ) ) {
			\WP_CLI::error( 'Give a disk license, a server license and scsi1 through scsi30.' );
		}
		$driver = Product::driver_for( (int) $disk_license['product_id'] );
		$server = Resource::for_license( (int) $server_license['id'] );
		if ( ! $driver || DiskDriver::ID !== $driver->id() || ! $server || ProxmoxDriver::ID !== $server->get( 'driver' ) || $server->get( 'linked' ) || 'ready' !== $server->get( 'state' ) || Licenses::STATUS_ACTIVE !== Licenses::effective_status( $disk_license ) || (int) $disk_license['user_id'] !== (int) $server_license['user_id'] || Resource::for_license( (int) $disk_license['id'] ) ) {
			\WP_CLI::error( 'The disk license must be active, both licenses must belong to one customer, the server must be ready and managed, and the disk must have no resource.' );
		}
		$record = VmRecords::for_resource( $server->id() );
		$cluster = $record ? Clusters::get( $record['cluster'] ) : null;
		if ( ! $record || ! $cluster ) {
			\WP_CLI::error( 'The server VM record or cluster is unavailable.' );
		}
		foreach ( DiskMeta::for_server( $server ) as $other ) {
			if ( $other->get( 'slot' ) === $slot ) {
				\WP_CLI::error( 'Another disk resource already claims that slot.' );
			}
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::log( sprintf( 'Would read VM %d in cluster %s, verify ownership and slot %s, then create a protected disk resource. No remote write.', (int) $record['vmid'], $record['cluster'], $slot ) );
			return;
		}
		if ( 'live' === ( $cluster['client'] ?? 'fake' ) && ( $assoc_args['confirm'] ?? '' ) !== 'LINK-DISK:' . $slot ) {
			\WP_CLI::error( 'Live read requires --confirm=LINK-DISK:' . $slot . ' and per-step authorization.' );
		}
		$gate = Clients::gate( $record['cluster'], $cluster, Clients::live_allowed() );
		if ( $gate ) {
			\WP_CLI::error( $gate );
		}
		$client = Clients::for( $cluster );
		$vm = Ownership::verify( $client, $record, $server );
		if ( is_wp_error( $vm ) ) {
			\WP_CLI::error( 'VM ownership could not be verified: ' . $vm->get_error_message() );
		}
		$value = (string) ( $vm['config'][ $slot ] ?? '' );
		$volid = explode( ',', $value )[0];
		$prefix = (string) $cluster['storage'] . ':' . (int) $record['vmid'] . '/';
		$name = str_starts_with( $volid, $prefix ) ? substr( $volid, strlen( $prefix ) ) : '';
		$size = Provision::gigabytes( $value );
		$variation_id = (int) ( $disk_license['variation_id'] ?? 0 );
		$plan = Product::plan( (int) $disk_license['product_id'], $variation_id > 0 ? $variation_id : null );
		if ( ! preg_match( '/^vm-' . (int) $record['vmid'] . '-[A-Za-z0-9._-]+\.(?:raw|qcow2)$/', $name ) || null === $size || (int) $size !== (int) ( $plan['disk_gb'] ?? 0 ) ) {
			\WP_CLI::error( 'The slot must hold a raw or qcow2 volume on the server storage with exactly the licensed size.' );
		}
		$resource = Resource::create( $disk_license, $driver, $plan, array( 'server_license' => (int) $server_license['id'], 'slot' => $slot, 'volname' => $name, 'size_gb' => (int) $size, 'state' => 'ready', 'desired' => 'ready', 'linked' => true, 'protected' => true, 'steps' => array( 'server', 'slot', 'allocate', 'attach', 'verify' ), 'notified' => 'ready' ) );
		if ( is_wp_error( $resource ) ) {
			\WP_CLI::error( $resource->get_error_message() );
		}
		Events::add( (int) $disk_license['id'], 'resource_linked', sprintf( 'Linked existing disk in %s by WP-CLI without changing the server.', $slot ) );
		\WP_CLI::success( sprintf( 'Linked disk license %d to server license %d in %s.', (int) $disk_license['id'], (int) $server_license['id'], $slot ) );
	}
	/**
	 * Links a license to a virtual machine that already exists on a Proxmox cluster.
	 *
	 * Bookkeeping and the ownership tag only: it records the license as ready and protected, adds a
	 * tag line to the machine's description and, when the cluster has a pool and the machine is in
	 * none, adds it to that pool. It starts, stops, resizes and reconfigures nothing, and the plan is
	 * not pushed. Calls: two reads, one or two writes. A dry run makes no call at all.
	 *
	 * A machine on another network (another bridge, an internal address) that is not in the pool
	 * is adopted with --outside-pool: nothing about its network is touched. IPv4 only.
	 *
	 * ## OPTIONS
	 *
	 * <license-id>
	 * : The license id.
	 *
	 * --cluster=<id>
	 * : Cluster id (Settings, Proxmox clusters).
	 *
	 * --node=<node>
	 * : The node the machine runs on.
	 *
	 * --vmid=<id>
	 * : The machine's VMID (inside the cluster's customer range).
	 *
	 * --ip=<ip>
	 * : The machine's IPv4 address: a free address of the pool, or with --outside-pool one that is not in it.
	 *
	 * [--outside-pool]
	 * : The address is not in the pool (another network).
	 *
	 * [--dry-run]
	 * : Print the calls that would be made. Nothing is read, written or recorded.
	 *
	 * [--confirm=<text>]
	 * : LINK:<vmid>. Required to change a machine on a live cluster.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services link-vm 12 --cluster=main --node=pve1 --vmid=200 --ip=192.0.2.10 --dry-run
	 *     wp beltoft-services link-vm 12 --cluster=main --node=pve1 --vmid=200 --ip=192.0.2.10 --confirm=LINK:200
	 *
	 * @subcommand link-vm
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function link_vm( array $args, array $assoc_args ): void {
		$result = LinkVm::run(
			(int) ( $args[0] ?? 0 ),
			array(
				'cluster'      => (string) ( $assoc_args['cluster'] ?? '' ),
				'node'         => (string) ( $assoc_args['node'] ?? '' ),
				'vmid'         => (int) ( $assoc_args['vmid'] ?? 0 ),
				'ip'           => (string) ( $assoc_args['ip'] ?? '' ),
				'outside_pool' => ! empty( $assoc_args['outside-pool'] ),
				'dry_run'      => ! empty( $assoc_args['dry-run'] ),
				'confirm'      => (string) ( $assoc_args['confirm'] ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		foreach ( $result['lines'] as $line ) {
			\WP_CLI::log( $line );
		}

		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}

		if ( $result['linked'] ) {
			\WP_CLI::success( 'Done.' );
		}
	}

	/**
	 * Read shaped VPS metrics for one license; --disk explicitly asks its guest agent.
	 *
	 * @subcommand vm-metrics
	 */
	public function vm_metrics( array $args, array $assoc_args ): void {
		$license_id = (int) ( $args[0] ?? 0 );
		$resource = $license_id > 0 ? Resource::for_license( $license_id ) : null;
		if ( ! $resource || ProxmoxDriver::ID !== $resource->get( 'driver' ) ) { \WP_CLI::error( 'Give a VPS license with a live resource.' ); }
		$range = (string) ( $assoc_args['range'] ?? 'hour' );
		if ( ! isset( Metrics::RANGES[ $range ] ) ) { \WP_CLI::error( 'Use --range=hour, day or month.' ); }
		$rows = ( new Operations( $resource ) )->series( $range );
		if ( null === $rows ) { \WP_CLI::error( 'VM usage could not be read.' ); }
		$series = Metrics::shape( $rows, $range );
		\WP_CLI::log( sprintf( 'Range: %s; points: %d; latest: %s', $range, count( $series['points'] ), wp_json_encode( $series['points'] ? end( $series['points'] ) : null ) ) );
		if ( isset( $assoc_args['disk'] ) ) {
			$disk = GuestDisk::read( $resource );
			\WP_CLI::log( 'Disk: ' . wp_json_encode( $disk ) );
		}
	}

	/**
	 * Registers each subcommand under `beltoft-services` when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services link-disk', array( self::class, 'link_disk' ) );
			\WP_CLI::add_command( 'beltoft-services link-vm', array( self::class, 'link_vm' ) );
			\WP_CLI::add_command( 'beltoft-services vm-metrics', array( self::class, 'vm_metrics' ) );
		}
	}
}

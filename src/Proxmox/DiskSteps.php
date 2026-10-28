<?php
/** Safe, retryable operations on an extra Proxmox disk. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class DiskSteps {
	use Steps;

	public function __construct( Resource $disk ) {
		$this->r = $disk;
		$this->wait = min( 120, max( 1, (int) Settings::get( 'task_wait' ) ) );
	}

	/** The server license must belong to the same customer and to a managed VM. */
	private function server() {
		$license = $this->r->license();
		$id = (int) $this->r->get( 'server_license' );
		if ( $id < 1 && (int) $this->r->get( 'server_order_id' ) > 0 ) {
			$id = (int) DiskMeta::server_in_order( (int) $this->r->get( 'server_order_id' ), (int) ( $license['user_id'] ?? 0 ) );
			if ( $id > 0 && ! $this->r->set( array( 'server_license' => $id ) ) ) {
				return StepResult::retry( 60, 'The server link could not be stored.' );
			}
		}
		$server_license = $id > 0 ? Licenses::find( $id ) : null;
		$server = $server_license ? Resource::for_license( $id ) : null;
		if ( $server_license && (int) ( $server_license['user_id'] ?? 0 ) !== (int) ( $license['user_id'] ?? 0 ) ) {
			return StepResult::fail( 'The server belongs to another customer.' );
		}
		if ( $server && SshKeyField::DRIVER !== $server->get( 'driver' ) ) {
			return StepResult::fail( 'The linked service is not a server.' );
		}
		if ( $server && $server->get( 'linked' ) ) {
			return StepResult::fail( 'An adopted server cannot receive an extra disk.' );
		}
		if ( $server && in_array( $server->get( 'state' ), array( 'failed', 'deleted' ), true ) ) {
			return StepResult::fail( 'The server failed or was deleted. Refund the disk order #' . (int) ( $license['order_id'] ?? 0 ) . '.' );
		}
		if ( $server && 'ready' === $server->get( 'state' ) && VmRecords::for_resource( $server->id() ) ) {
			return $server;
		}
		$until = (int) $this->r->get( 'server_wait_until' );
		if ( $until < 1 ) {
			$until = time() + 6 * HOUR_IN_SECONDS;
			if ( ! $this->r->set( array( 'server_wait_until' => $until ) ) ) {
				return StepResult::retry( 60, 'The server wait deadline could not be stored.' );
			}
		}
		if ( time() >= $until ) {
			return StepResult::fail( 'The server was not ready within six hours. Refund the disk order #' . (int) ( $license['order_id'] ?? 0 ) . '.' );
		}
		return StepResult::wait( 60, 'Waiting for the server.', $until );
	}

	/** @return array<string,mixed>|StepResult */
	private function context( Resource $server ) {
		$record = VmRecords::for_resource( $server->id() );
		if ( ! $record ) {
			return StepResult::retry( 60, 'The server VM record is missing.' );
		}
		$cluster = Clusters::get( (string) $record['cluster'] );
		if ( ! $cluster || empty( $cluster['storage'] ) ) {
			return StepResult::fail( 'The server storage is not configured.' );
		}
		$gate = Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() );
		if ( $gate ) {
			return StepResult::retry( 300, $gate );
		}
		$this->client = Clients::for( $cluster );
		$this->secrets = array( (string) ( $cluster['token_secret'] ?? '' ) );
		$v = Ownership::verify( $this->client, $record, $server );
		if ( is_wp_error( $v ) ) {
			return in_array( $v->get_error_code(), array( 'not_ours', 'not_recorded', 'out_of_range', 'is_template' ), true )
				? StepResult::fail( 'Server ownership could not be verified: ' . $this->text( $v ) )
				: $this->classify( $v, 'server' );
		}
		return array( 'server' => $server, 'record' => $record, 'cluster' => $cluster, 'node' => (string) $v['node'], 'config' => $v['config'], 'lock' => $v['lock'], 'storage' => (string) $cluster['storage'] );
	}

	private static function config_path( array $ctx ): string {
		return '/nodes/' . rawurlencode( $ctx['node'] ) . '/qemu/' . (int) $ctx['record']['vmid'] . '/config';
	}

	private static function content_path( array $ctx ): string {
		return '/nodes/' . rawurlencode( $ctx['node'] ) . '/storage/' . rawurlencode( $ctx['storage'] ) . '/content';
	}

	private function volume_id( array $ctx ): string {
		return $ctx['storage'] . ':' . (int) $ctx['record']['vmid'] . '/' . (string) $this->r->get( 'volname' );
	}

	/** Claim a free slot and a deterministic name before writing anything remotely. */
	private function claim( array $ctx ): ?StepResult {
		if ( $this->r->get( 'slot' ) && $this->r->get( 'volname' ) ) {
			return null;
		}
		list( $locked, $answer ) = Locks::with( Locks::name( 'server_disks', $ctx['server']->id() ), Locks::allocation_wait(), function () use ( $ctx ) {
			$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
			if ( is_wp_error( $config ) ) {
				return $this->classify( $config, 'disk slot' );
			}
			$occupied = array();
			foreach ( array_keys( $config ) as $key ) {
				if ( preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/', (string) $key ) ) {
					$occupied[ $key ] = true;
				}
			}
			foreach ( Resource::live_ids( 'disk' ) as $id ) {
				if ( $id === $this->r->id() ) {
					continue;
				}
				$other = Resource::find( $id );
				if ( $other && (int) $other->get( 'server_license' ) === (int) $this->r->get( 'server_license' ) && $other->get( 'slot' ) ) {
					$occupied[ (string) $other->get( 'slot' ) ] = true;
				}
			}
			$max = min( 30, max( 1, (int) Settings::get( 'disk_max_extra' ) ) );
			if ( count( $occupied ) >= $max ) {
				return StepResult::fail( 'The server already has the maximum number of extra disks.' );
			}
			for ( $number = 1; $number <= 30; ++$number ) {
				$slot = 'scsi' . $number;
				if ( isset( $occupied[ $slot ] ) ) {
					continue;
				}
				$uuid = preg_replace( '/[^a-f0-9]/i', '', (string) $this->r->get( 'uuid' ) );
				$name = 'vm-' . (int) $ctx['record']['vmid'] . '-bs' . $number . '-' . strtolower( $uuid ) . '.' . Provision::disk_format();
				return $this->r->set( array( 'slot' => $slot, 'volname' => $name ) ) ? null : StepResult::retry( 60, 'The disk slot claim could not be stored.' );
			}
			return StepResult::fail( 'No SCSI disk slot is available.' );
		} );
		return $locked ? $answer : StepResult::retry( 60, 'The server disk lock is busy.' );
	}

	/** @return array<string,mixed>|StepResult */
	private function images( array $ctx ) {
		$images = $this->client->request( 'GET', self::content_path( $ctx ), array( 'content' => 'images', 'vmid' => (int) $ctx['record']['vmid'] ) );
		return is_wp_error( $images ) ? $this->classify( $images, 'disk listing' ) : ( is_array( $images ) ? $images : StepResult::retry( 60, 'The disk listing was invalid.' ) );
	}

	/** The config entry that refers to this resource's named volume, or null. */
	private function attached( array $config, string $volid ): ?array {
		return self::holds( $config, $volid );
	}

	/** The scsi1..30 entry of a VM configuration that refers to a volume, or null. */
	public static function holds( array $config, string $volid ): ?array {
		foreach ( $config as $slot => $value ) {
			if ( preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/', (string) $slot ) && is_string( $value ) && explode( ',', $value )[0] === $volid ) {
				return array( 'slot' => $slot, 'value' => $value );
			}
		}
		return null;
	}

	/**
	 * The extra disks of a server and where their volumes belong: disk resource id, slot and volume id.
	 * With $attached_only only the disks that must be attached to the running VM (ready, verified, not detached by the policy, not adopted).
	 *
	 * @param Resource $server        Server resource.
	 * @param string   $storage       Storage of the cluster.
	 * @param int      $vmid          VMID of the server.
	 * @param bool     $attached_only Only the disks that should be attached now.
	 * @return array<int, array{id: int, slot: string, volid: string}>
	 */
	public static function volumes( Resource $server, string $storage, int $vmid, bool $attached_only ): array {
		$out = array();
		foreach ( DiskMeta::for_server( $server ) as $disk ) {
			$volname = (string) $disk->get( 'volname' );
			if ( '' === $volname || 'deleted' === $disk->get( 'state' ) ) {
				continue;
			}
			if ( $attached_only && ( $disk->get( 'linked' ) || 'ready' !== $disk->get( 'state' ) || $disk->get( 'detached' ) || ! in_array( 'attach', (array) $disk->get( 'steps' ), true ) || ! in_array( 'verify', (array) $disk->get( 'steps' ), true ) ) ) {
				continue;
			}
			$out[] = array( 'id' => $disk->id(), 'slot' => (string) $disk->get( 'slot' ), 'volid' => $storage . ':' . $vmid . '/' . $volname );
		}
		return $out;
	}

	public function provision(): StepResult {
		if ( $this->r->get( 'linked' ) ) {
			return StepResult::fail( 'An adopted disk is not provisioned automatically.' );
		}
		$server = $this->server();
		if ( $server instanceof StepResult ) {
			return $server;
		}
		$ctx = $this->context( $server );
		if ( $ctx instanceof StepResult ) {
			return $ctx;
		}
		$polled = $this->poll_task();
		if ( $polled ) {
			return $polled;
		}
		$size = (int) ( ( (array) $this->r->get( 'plan' ) )['disk_gb'] ?? 0 );
		if ( $size < 1 ) {
			return StepResult::fail( 'The disk size is invalid.' );
		}
		if ( ! DiskCapacity::reserve( $this->r, (string) $ctx['record']['cluster'], $ctx['node'], $ctx['storage'], $size ) ) {
			return StepResult::retry( 3600, 'There is not enough verified storage capacity for this disk.' );
		}
		$claim = $this->claim( $ctx );
		if ( $claim ) {
			return $claim;
		}
		$volid = $this->volume_id( $ctx );
		$images = $this->images( $ctx );
		if ( $images instanceof StepResult ) {
			return $images;
		}
		$found = null;
		foreach ( $images as $image ) {
			if ( is_array( $image ) && ( $image['volid'] ?? '' ) === $volid ) {
				$found = $image;
			}
		}
		if ( $found && (int) ( $found['size'] ?? 0 ) !== $size * 1073741824 ) {
			return StepResult::fail( 'The named disk volume has an unexpected size.' );
		}
		if ( ! $found ) {
			if ( '' !== $ctx['lock'] ) {
				return StepResult::retry( 60, 'The server is locked.' );
			}
			$made = $this->client->request( 'POST', self::content_path( $ctx ), array( 'filename' => $this->r->get( 'volname' ), 'size' => $size . 'G', 'vmid' => (int) $ctx['record']['vmid'], 'format' => Provision::disk_format() ) );
			if ( is_wp_error( $made ) ) {
				return $this->classify( $made, 'disk allocation' );
			}
			if ( $made !== $volid ) {
				return StepResult::fail( 'The allocated volume identity did not match the recorded name.' );
			}
		}
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk attach' );
		}
		$attached = $this->attached( $config, $volid );
		if ( ! $attached ) {
			$slot = (string) $this->r->get( 'slot' );
			if ( isset( $config[ $slot ] ) ) {
				return StepResult::fail( 'Another disk occupies the claimed slot.' );
			}
			if ( '' !== $ctx['lock'] ) {
				return StepResult::retry( 60, 'The server is locked.' );
			}
			$task = $this->run_task( 'disk attach', 'POST', self::config_path( $ctx ), array( $slot => $volid . ',backup=0', 'digest' => (string) ( $config['digest'] ?? '' ) ) );
			if ( $task instanceof StepResult ) {
				return $task;
			}
			$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
			if ( is_wp_error( $config ) ) {
				return $this->classify( $config, 'disk verify' );
			}
			$attached = $this->attached( $config, $volid );
		}
		if ( ! $attached ) {
			return StepResult::retry( 60, 'The named disk volume is not attached yet.' );
		}
		if ( null === Provision::gigabytes( $attached['value'] ) || (int) Provision::gigabytes( $attached['value'] ) !== $size ) {
			return StepResult::retry( 60, 'The attached disk size differs from the plan.' );
		}
		if ( ! $this->r->set( array( 'slot' => $attached['slot'], 'size_gb' => $size ) ) ) {
			return StepResult::retry( 60, 'The attached disk size could not be stored.' );
		}
		DiskCapacity::release( $this->r );
		return StepResult::done( array( 'slot' => $attached['slot'], 'volname' => $this->r->get( 'volname' ), 'size_gb' => $size, 'server_wait_until' => null, 'steps' => array( 'server', 'slot', 'allocate', 'attach', 'verify' ) ), 'Extra disk attached' );
	}

	/**
	 * After a rollback to a snapshot a disk that was grown since has its old size again: grow it to the size it
	 * was paid for. The space was ours before, so no new reservation is asked for. A disk that is not attached
	 * and ready has nothing to grow.
	 */
	public function regrow_after_rollback(): StepResult {
		$steps = (array) $this->r->get( 'steps' );
		if ( $this->r->get( 'linked' ) || 'ready' !== $this->r->get( 'state' ) || ! $this->r->get( 'volname' ) || ! in_array( 'attach', $steps, true ) || ! in_array( 'verify', $steps, true ) || $this->r->get( 'detached' ) ) {
			return StepResult::done( array(), 'Nothing to grow after the rollback' );
		}
		return $this->grow( true );
	}

	public function grow( bool $regrow = false ): StepResult {
		if ( $this->r->get( 'linked' ) ) {
			return StepResult::fail( 'An adopted disk is not resized automatically.' );
		}
		$server = $this->server();
		if ( $server instanceof StepResult ) {
			return $server;
		}
		$ctx = $this->context( $server );
		if ( $ctx instanceof StepResult ) {
			return $ctx;
		}
		$polled = $this->poll_task();
		if ( $polled ) {
			return $polled;
		}
		if ( ! $this->r->get( 'volname' ) ) {
			return StepResult::fail( 'The disk volume identity is missing.' );
		}
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk grow' );
		}
		$attached = $this->attached( $config, $this->volume_id( $ctx ) );
		if ( ! $attached ) {
			return StepResult::fail( 'The named disk volume is not attached.' );
		}
		$actual = Provision::gigabytes( $attached['value'] );
		$target = (int) ( ( (array) $this->r->get( 'plan' ) )['disk_gb'] ?? 0 );
		if ( null === $actual || $target < 1 ) {
			return StepResult::fail( 'The disk size cannot be verified.' );
		}
		if ( $target < $actual ) {
			return StepResult::fail( 'A disk cannot be made smaller.' );
		}
		if ( $target > $actual ) {
			$delta = (int) ceil( $target - $actual );
			if ( ! $regrow && ! DiskCapacity::reserve( $this->r, (string) $ctx['record']['cluster'], $ctx['node'], $ctx['storage'], $delta ) ) {
				return StepResult::retry( 3600, 'There is not enough verified storage capacity to grow the disk.' );
			}
			if ( '' !== $ctx['lock'] ) {
				return StepResult::retry( 60, 'The server is locked.' );
			}
			$task = $this->run_task( 'disk grow', 'PUT', '/nodes/' . rawurlencode( $ctx['node'] ) . '/qemu/' . (int) $ctx['record']['vmid'] . '/resize', array( 'disk' => $attached['slot'], 'size' => $target . 'G' ) );
			if ( $task instanceof StepResult ) {
				return $task;
			}
			$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
			if ( is_wp_error( $config ) ) {
				return $this->classify( $config, 'disk grow verify' );
			}
			$attached = $this->attached( $config, $this->volume_id( $ctx ) );
			if ( ! $attached || (int) Provision::gigabytes( $attached['value'] ) !== $target ) {
				return StepResult::retry( 60, 'The grown disk size could not be verified.' );
			}
		}
		if ( ! $this->r->set( array( 'size_gb' => $target, 'slot' => $attached['slot'] ) ) ) {
			return StepResult::retry( 60, 'The grown disk size could not be stored.' );
		}
		DiskCapacity::release( $this->r );
		return StepResult::done( array( 'size_gb' => $target, 'slot' => $attached['slot'] ), 'Extra disk size updated' );
	}

	private const PENDING_DETACH = 'The disk detach is pending on the running server and applies when the server is restarted.';

	/** A detach that waits for a restart: retried, and the admin is told once it has lasted. */
	private function pending_detach(): StepResult {
		if ( (int) $this->r->get( 'attempts' ) >= 2 ) {
			\BeltoftServices\Alerts::admin( $this->r, self::PENDING_DETACH . ' Restart the server to apply it.' );
		}
		return StepResult::retry( 300, self::PENDING_DETACH );
	}

	/**
	 * Does the running configuration still hold the drive? A detach on a running server without
	 * disk hotplug is only pending: the default view merges it, `current` does not.
	 *
	 * @return bool|StepResult
	 */
	private function running_holds( array $ctx, string $volid ) {
		$running = $this->client->request( 'GET', self::config_path( $ctx ), array( 'current' => 1 ) );
		if ( is_wp_error( $running ) ) {
			return $this->classify( $running, 'disk running configuration' );
		}
		return ! is_array( $running ) || null !== $this->attached( $running, $volid );
	}

	/** Lapsed subscription: the drive is detached and the volume stays on the storage with its data. */
	public function pause(): StepResult {
		if ( $this->r->get( 'linked' ) ) {
			return StepResult::done( array(), 'An adopted disk stays attached' );
		}
		if ( ! $this->r->get( 'volname' ) ) {
			return StepResult::done( array(), 'The disk was never attached, so there is nothing to detach' );
		}
		// Whatever the steps say, a drive that holds the exact volume is detached; without an attach step the server is only looked at, never failed on.
		$never = ! in_array( 'attach', (array) $this->r->get( 'steps' ), true );
		$never_done = static fn() => StepResult::done( array(), 'The disk was never attached, so there is nothing to detach' );
		$server_id = (int) $this->r->get( 'server_license' );
		$server    = $server_id > 0 ? Resource::for_license( $server_id ) : null;
		if ( $server && 'deleted' === $server->get( 'state' ) ) {
			return StepResult::done( array(), 'The server was deleted, so there is nothing to detach' );
		}
		$server_license = $server_id > 0 ? Licenses::find( $server_id ) : null;
		$own_license    = $this->r->license();
		if ( $server && $server_license && (int) ( $server_license['user_id'] ?? 0 ) !== (int) ( $own_license['user_id'] ?? 0 ) ) {
			return StepResult::fail( 'The server belongs to another customer, so the disk is not detached.' );
		}
		if ( ! $server || ! $server_license || $server->get( 'linked' ) || SshKeyField::DRIVER !== $server->get( 'driver' ) || (int) ( $server_license['user_id'] ?? 0 ) < 1 ) {
			return $never ? $never_done() : StepResult::fail( 'The server cannot be verified for detaching the disk.' );
		}
		$ctx = $this->context( $server );
		if ( $ctx instanceof StepResult ) {
			return $never && $ctx->is_failed() ? $never_done() : $ctx;
		}
		$polled = $this->poll_task();
		if ( $polled ) {
			return $polled;
		}
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk detach' );
		}
		$volid    = $this->volume_id( $ctx );
		$attached = $this->attached( $config, $volid );
		if ( ! $attached && $never ) {
			return $never_done();
		}
		if ( $attached ) {
			if ( '' !== $ctx['lock'] ) {
				return StepResult::retry( 60, 'The server is locked.' );
			}
			$done = $this->client->request( 'PUT', self::config_path( $ctx ), array( 'delete' => $attached['slot'], 'digest' => (string) ( $config['digest'] ?? '' ) ) );
			if ( is_wp_error( $done ) ) {
				return $this->classify( $done, 'disk detach' );
			}
			$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
			if ( is_wp_error( $config ) ) {
				return $this->classify( $config, 'disk detach verify' );
			}
			if ( $this->attached( $config, $volid ) ) {
				return $this->pending_detach();
			}
		}
		$holds = $this->running_holds( $ctx, $volid );
		if ( $holds instanceof StepResult ) {
			return $holds;
		}
		if ( $holds ) {
			return $this->pending_detach();
		}
		$images = $this->images( $ctx );
		if ( $images instanceof StepResult ) {
			return $images;
		}
		if ( ! $this->has_image( $images, $volid ) ) {
			return StepResult::fail( 'The disk volume is not on the storage after the detach; nothing was deleted.' );
		}
		$slot = $attached ? $attached['slot'] : (string) ( ( (array) $this->r->get( 'detached' ) )['slot'] ?? $this->r->get( 'slot' ) );
		return StepResult::done( array( 'detached' => array( 'volid' => $volid, 'slot' => $slot ) ), 'Extra disk detached, its data is kept' );
	}

	/** The subscription is active again: the same volume goes back into its slot. */
	public function reattach(): StepResult {
		if ( $this->r->get( 'linked' ) ) {
			return StepResult::done();
		}
		$steps = (array) $this->r->get( 'steps' );
		if ( ! $this->r->get( 'volname' ) || ! in_array( 'attach', $steps, true ) || ! in_array( 'verify', $steps, true ) ) {
			return $this->provision();
		}
		$server = $this->server();
		if ( $server instanceof StepResult ) {
			return $server;
		}
		$ctx = $this->context( $server );
		if ( $ctx instanceof StepResult ) {
			return $ctx;
		}
		$polled = $this->poll_task();
		if ( $polled ) {
			return $polled;
		}
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk reattach' );
		}
		$volid    = $this->volume_id( $ctx );
		$attached = $this->attached( $config, $volid );
		if ( ! $attached ) {
			$images = $this->images( $ctx );
			if ( $images instanceof StepResult ) {
				return $images;
			}
			if ( ! $this->has_image( $images, $volid ) ) {
				return StepResult::fail( 'The disk volume is missing from the storage; it is not recreated.' );
			}
			$marker = (array) $this->r->get( 'detached' );
			$slot   = (string) ( $marker['slot'] ?? $this->r->get( 'slot' ) );
			if ( 1 !== preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/', $slot ) ) {
				return StepResult::fail( 'The disk slot is not known.' );
			}
			if ( isset( $config[ $slot ] ) ) {
				return StepResult::fail( 'Another disk occupies the slot of this disk.' );
			}
			if ( '' !== $ctx['lock'] ) {
				return StepResult::retry( 60, 'The server is locked.' );
			}
			$task = $this->run_task( 'disk reattach', 'POST', self::config_path( $ctx ), array( $slot => $volid . ',backup=0', 'digest' => (string) ( $config['digest'] ?? '' ) ) );
			if ( $task instanceof StepResult ) {
				return $task;
			}
			$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
			if ( is_wp_error( $config ) ) {
				return $this->classify( $config, 'disk reattach verify' );
			}
			$attached = $this->attached( $config, $volid );
			if ( ! $attached ) {
				return StepResult::retry( 60, 'The disk volume is not attached yet.' );
			}
		}
		return StepResult::done( array( 'slot' => $attached['slot'], 'detached' => null ), 'Extra disk attached again' );
	}

	/**
	 * After a restore of the server (it is off): the extra disk that was attached goes back to its own slot.
	 * A forced restore keeps the volumes of backup=0 disks as unusedN entries of the restored configuration.
	 * Done: attached again, or nothing to do (never attached, adopted, detached by policy, failed). Retry: try
	 * again. Fail: the volume is missing or the slot is taken; nothing is guessed and the volume is left alone.
	 *
	 * @param Resource $server The server resource.
	 */
	public function reattach_after_restore( Resource $server ): StepResult {
		$steps = (array) $this->r->get( 'steps' );
		if ( $this->r->get( 'linked' ) || 'ready' !== $this->r->get( 'state' ) || ! $this->r->get( 'volname' ) || ! in_array( 'attach', $steps, true ) || ! in_array( 'verify', $steps, true ) || $this->r->get( 'detached' ) ) {
			return StepResult::done( array(), 'Nothing to attach again after the restore' );
		}
		$ctx = $this->context( $server );
		if ( $ctx instanceof StepResult ) {
			return $ctx;
		}
		$polled = $this->poll_task();
		if ( $polled ) {
			return $polled;
		}
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk after restore' );
		}
		$volid    = $this->volume_id( $ctx );
		$attached = $this->attached( $config, $volid );
		if ( ! $attached ) {
			$held = false;
			foreach ( $config as $key => $value ) {
				if ( 1 === preg_match( '/^unused\d+$/', (string) $key ) && is_string( $value ) && explode( ',', $value )[0] === $volid ) {
					$held = true;
				}
			}
			if ( ! $held ) {
				return StepResult::fail( 'The disk volume ' . $volid . ' is not in the restored server configuration.' );
			}
			$slot = (string) $this->r->get( 'slot' );
			if ( 1 !== preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/', $slot ) ) {
				return StepResult::fail( 'The disk slot is not known.' );
			}
			if ( isset( $config[ $slot ] ) ) {
				return StepResult::fail( 'Another disk occupies the slot ' . $slot . ' of this disk after the restore.' );
			}
			if ( '' !== $ctx['lock'] ) {
				return StepResult::retry( 60, 'The server is locked.' );
			}
			$task = $this->run_task( 'disk reattach', 'POST', self::config_path( $ctx ), array( $slot => $volid . ',backup=0', 'digest' => (string) ( $config['digest'] ?? '' ) ) );
			if ( $task instanceof StepResult ) {
				return $task;
			}
			$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
			if ( is_wp_error( $config ) ) {
				return $this->classify( $config, 'disk after restore verify' );
			}
			$attached = $this->attached( $config, $volid );
			if ( ! $attached ) {
				return StepResult::retry( 60, 'The disk volume is not attached yet.' );
			}
		}
		return StepResult::done( array( 'slot' => $attached['slot'] ), 'Extra disk attached again after the restore' );
	}

	private function has_image( array $images, string $volid ): bool {
		foreach ( $images as $image ) {
			if ( is_array( $image ) && ( $image['volid'] ?? '' ) === $volid ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The volume was allocated but never attached (no attach step was ever recorded), and is in no drive and
	 * no unusedN entry. The plugin's token cannot delete a volume through the storage (live: 403 for any
	 * volume), so it is left where it is, recorded on the disk, and goes with the server (deleting the VM
	 * removes every vm-<vmid>-* volume). Only once it is certain that the running server does not use it.
	 */
	private function remove_orphan( array $ctx, string $volid ): StepResult {
		$uuid = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $this->r->get( 'uuid' ) ) );
		$vmid = (int) $ctx['record']['vmid'];
		if ( '' === $uuid || 1 !== preg_match( '/^vm-' . $vmid . '-bs(?:[1-9]|[12][0-9]|30)-' . $uuid . '\.(?:raw|qcow2)$/', (string) $this->r->get( 'volname' ) ) ) {
			return StepResult::fail( 'The disk volume name does not belong to this disk, so it is not touched.' );
		}
		$holds = $this->running_holds( $ctx, $volid );
		if ( $holds instanceof StepResult ) {
			return $holds;
		}
		if ( $holds ) {
			return $this->pending_detach();
		}
		$gb   = (int) ( ( (array) $this->r->get( 'plan' ) )['disk_gb'] ?? 0 );
		$left = (array) $ctx['server']->get( 'left_volumes' );
		// The volume keeps its space until the server is deleted: it is counted on the server (see DiskCapacity::fits).
		$left[ $volid ] = $gb;
		if ( ! $ctx['server']->set( array( 'left_volumes' => $left ) ) || ! $this->r->set( array( 'left_volume' => $volid ) ) ) {
			return StepResult::retry( 60, 'The left volume could not be recorded.' );
		}
		DiskCapacity::release( $this->r );
		return StepResult::done( array( 'size_gb' => null ), 'Extra disk removed. The unattached volume stays on the storage and is removed with its server.' );
	}

	private function finish_delete(): StepResult {
		DiskCapacity::release( $this->r );
		return StepResult::done( array( 'size_gb' => null ), 'Extra disk removed' );
	}

	/**
	 * D10: the snapshots that cover the disk go before the disk does (a refused delete would stall a billing-driven
	 * removal), under the server's lock because they live in the server's meta and task slot. The customer is told
	 * once per disk.
	 *
	 * @param Resource $server The server.
	 * @param string   $volid  The disk's volume id.
	 * @return StepResult|null Null when none is left.
	 */
	private function drop_snapshots( Resource $server, string $volid ): ?StepResult {
		// Meta only, outside the lock: no snapshot covers this volume, so nothing to drop or to lock for.
		if ( array() === Snapshots::covering( $server, $volid ) ) {
			return null;
		}
		$disk   = $this->r->id();
		$answer = null;
		$ran    = $server->locked(
			static function () use ( $server, $volid, $disk, &$answer ): bool {
				$answer = ( new Snapshots( $server ) )->drop_covering( $volid );
				if ( $answer->is_done() ) {
					// Keyed by the disk resource: a volume id comes back for a later disk, which is told again.
					SnapshotExpiry::notify( $server, 'disk_removed', $disk . ':' . $volid );
				}
				return true;
			}
		);
		if ( null === $ran ) {
			return StepResult::retry( 60, 'The server is busy; its snapshots of this disk are deleted first.' );
		}
		return $answer->is_done() ? null : $answer;
	}

	public function delete(): StepResult {
		if ( $this->r->get( 'linked' ) ) {
			return StepResult::fail( 'An adopted disk is not deleted automatically.' );
		}
		if ( 'on' !== Settings::get( 'deletion_mode' ) ) {
			return StepResult::fail( 'Disk removal requires deletion mode on.' );
		}
		$server_id = (int) $this->r->get( 'server_license' );
		$server = $server_id > 0 ? Resource::for_license( $server_id ) : null;
		if ( ! $server || $server->get( 'linked' ) || SshKeyField::DRIVER !== $server->get( 'driver' ) ) {
			return StepResult::fail( 'The server cannot be verified for disk removal.' );
		}
		$server_license = Licenses::find( $server_id );
		$own_license    = $this->r->license();
		if ( ! $server_license || (int) ( $server_license['user_id'] ?? 0 ) < 1 || (int) ( $server_license['user_id'] ?? 0 ) !== (int) ( $own_license['user_id'] ?? 0 ) ) {
			return StepResult::fail( 'The server belongs to another customer, so the disk is not removed.' );
		}
		$ctx = $this->context( $server );
		if ( $ctx instanceof StepResult ) {
			return $ctx;
		}
		if ( ! $this->r->get( 'volname' ) ) {
			return StepResult::fail( 'The disk volume identity is missing.' );
		}
		$polled = $this->poll_task();
		if ( $polled ) {
			return $polled;
		}
		$volid = $this->volume_id( $ctx );
		$gone  = $this->drop_snapshots( $server, $volid );
		if ( $gone ) {
			return $gone;
		}
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk removal' );
		}
		$attached = $this->attached( $config, $volid );
		if ( $attached ) {
			$done = $this->client->request( 'PUT', self::config_path( $ctx ), array( 'delete' => $attached['slot'], 'digest' => (string) ( $config['digest'] ?? '' ) ) );
			if ( is_wp_error( $done ) ) {
				return $this->classify( $done, 'disk detach' );
			}
			$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
			if ( is_wp_error( $config ) ) {
				return $this->classify( $config, 'disk detach verify' );
			}
			if ( $this->attached( $config, $volid ) ) {
				return $this->pending_detach();
			}
		}
		$unused = null;
		foreach ( $config as $slot => $value ) {
			if ( preg_match( '/^unused\d+$/', (string) $slot ) && $value === $volid ) {
				$unused = $slot;
			}
		}
		if ( $unused ) {
			$done = $this->client->request( 'PUT', self::config_path( $ctx ), array( 'delete' => $unused, 'digest' => (string) ( $config['digest'] ?? '' ) ) );
			if ( is_wp_error( $done ) ) {
				return $this->classify( $done, 'disk volume removal' );
			}
		}
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk removal verify' );
		}
		foreach ( $config as $slot => $value ) {
			if ( preg_match( '/^(?:scsi(?:[1-9]|[12][0-9]|30)|unused\d+)$/', (string) $slot ) && is_string( $value ) && explode( ',', $value )[0] === $volid ) {
				return StepResult::retry( 60, 'The named disk volume is still in the server configuration.' );
			}
		}
		$images = $this->images( $ctx );
		if ( $images instanceof StepResult ) {
			return $images;
		}
		if ( $this->has_image( $images, $volid ) ) {
			if ( $unused ) {
				return StepResult::retry( 60, 'The disk volume still exists.' );
			}
			// A disk that was ever attached is removed only through the configuration (detach, unusedN, delete=unusedN), never by a direct storage delete.
			if ( in_array( 'attach', (array) $this->r->get( 'steps' ), true ) ) {
				$holds = $this->running_holds( $ctx, $volid );
				if ( $holds instanceof StepResult ) {
					return $holds;
				}
				return $holds ? $this->pending_detach() : StepResult::fail( 'The disk volume ' . $volid . ' is on the storage but not linked to the server; remove it by hand.' );
			}
			return $this->remove_orphan( $ctx, $volid );
		}
		return $this->finish_delete();
	}
}

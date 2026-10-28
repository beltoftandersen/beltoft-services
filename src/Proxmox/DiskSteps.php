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
				$name = 'vm-' . (int) $ctx['record']['vmid'] . '-bs' . $number . '-' . strtolower( $uuid ) . '.raw';
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
		foreach ( $config as $slot => $value ) {
			if ( preg_match( '/^scsi(?:[1-9]|[12][0-9]|30)$/', (string) $slot ) && is_string( $value ) && explode( ',', $value )[0] === $volid ) {
				return array( 'slot' => $slot, 'value' => $value );
			}
		}
		return null;
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
			$made = $this->client->request( 'POST', self::content_path( $ctx ), array( 'filename' => $this->r->get( 'volname' ), 'size' => $size . 'G', 'vmid' => (int) $ctx['record']['vmid'], 'format' => 'raw' ) );
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

	public function grow(): StepResult {
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
			if ( ! DiskCapacity::reserve( $this->r, (string) $ctx['record']['cluster'], $ctx['node'], $ctx['storage'], $delta ) ) {
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

	private function has_image( array $images, string $volid ): bool {
		foreach ( $images as $image ) {
			if ( is_array( $image ) && ( $image['volid'] ?? '' ) === $volid ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The volume is in no drive and in no unusedN entry of the server (a volume allocated but never
	 * attached, or one a detach left behind): delete it by its own exact name, once it is certain
	 * that the running server no longer uses it.
	 */
	private function remove_orphan( array $ctx, string $volid, bool $may_delete ): StepResult {
		if ( ! $may_delete ) {
			return StepResult::retry( 60, 'The disk volume still exists.' );
		}
		$uuid = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $this->r->get( 'uuid' ) ) );
		$vmid = (int) $ctx['record']['vmid'];
		if ( '' === $uuid || 1 !== preg_match( '/^vm-' . $vmid . '-bs(?:[1-9]|[12][0-9]|30)-' . $uuid . '\.raw$/', (string) $this->r->get( 'volname' ) ) ) {
			return StepResult::fail( 'The disk volume name does not belong to this disk, so it is not deleted.' );
		}
		if ( '' !== $ctx['lock'] ) {
			return StepResult::retry( 60, 'The server is locked.' );
		}
		// A detach on a running server without disk hotplug is only pending: the running configuration still holds the drive.
		$running = $this->client->request( 'GET', self::config_path( $ctx ), array( 'current' => 1 ) );
		if ( is_wp_error( $running ) ) {
			return $this->classify( $running, 'disk running configuration' );
		}
		if ( ! is_array( $running ) || $this->attached( $running, $volid ) ) {
			return StepResult::retry( 300, self::PENDING_DETACH );
		}
		$task = $this->run_task( 'disk volume delete', 'DELETE', self::content_path( $ctx ) . '/' . rawurlencode( $volid ) );
		if ( $task instanceof StepResult ) {
			return $task;
		}
		$images = $this->images( $ctx );
		if ( $images instanceof StepResult ) {
			return $images;
		}
		return $this->has_image( $images, $volid ) ? StepResult::retry( 60, 'The disk volume still exists.' ) : $this->finish_delete();
	}

	private function finish_delete(): StepResult {
		DiskCapacity::release( $this->r );
		return StepResult::done( array( 'size_gb' => null ), 'Extra disk removed' );
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
		$config = Ownership::config( $this->client, $ctx['node'], (int) $ctx['record']['vmid'] );
		if ( is_wp_error( $config ) ) {
			return $this->classify( $config, 'disk removal' );
		}
		$volid = $this->volume_id( $ctx );
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
				return StepResult::retry( 300, self::PENDING_DETACH );
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
			return $this->remove_orphan( $ctx, $volid, ! $unused );
		}
		return $this->finish_delete();
	}
}

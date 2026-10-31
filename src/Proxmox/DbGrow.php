<?php
/**
 * Makes the data disk of a managed database larger: Proxmox first, then the guest's file system.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The plan's data disk (DbDisk::total()) is bigger than the disk that was built (meta `disk_gb`), or a restore may have
 * put back a smaller one. The stored operation (`db_grow_op`) remembers where it was; Proxmox is read before anything is
 * believed, and each phase is idempotent:
 *
 * - capacity: the real size of scsi1 is read from the config and the growth is reserved on the storage (DiskCapacity).
 *   No room: the run is tried again in an hour.
 * - resize: PUT resize of scsi1 to the target size, then the config is read back. Never smaller: a disk that is already
 *   larger than the target is left as it is and the admin is told.
 * - guest: the `grow` job (rescan, ext4 online grow) once, then its status is waited for (the same request id when it
 *   is sent again, within 10 minutes of the phase's start). A guest that does not know the job (a template older than the script) or does not answer in time
 *   does not fail the database: the admin gets the command to run, and the dashboard says the storage is being prepared.
 * - record: the new size is stored, the reservation released, the operation cleared.
 */
final class DbGrow {

	use Steps;
	use Prepares;

	/** Meta: the operation in progress (phase, target, request id). */
	public const OP = 'db_grow_op';

	/** Meta: `manual` while the file system still has to be grown by hand. */
	public const FS = 'db_grow_fs';

	/** Seconds the guest has for the grow job. */
	private const BUDGET = 600;

	/** The data disk's slot. */
	private const SLOT = 'scsi1';

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Is an operation in progress?
	 *
	 * @param Resource $r Resource.
	 */
	public static function busy( Resource $r ): bool {
		return is_array( $r->get( self::OP ) ) && array() !== $r->get( self::OP );
	}

	/**
	 * Does the guest still need its file system grown by hand?
	 *
	 * @param Resource $r Resource.
	 */
	public static function manual( Resource $r ): bool {
		return 'manual' === $r->get( self::FS );
	}

	/**
	 * Does the plan want a bigger data disk than the database has (from stored data), or is a run on its way?
	 *
	 * @param Resource $r Resource.
	 */
	public static function wanted( Resource $r ): bool {
		return self::busy( $r ) || DbDisk::total( (array) $r->get( 'plan' ) ) > (int) $r->get( 'disk_gb' );
	}

	/**
	 * The command the admin runs when the guest could not be told.
	 *
	 * @param int $vmid VMID.
	 */
	public static function manual_command( int $vmid ): string {
		return 'qm guest exec ' . $vmid . ' -- resize2fs /dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_drive-scsi1';
	}

	/**
	 * Runs the operation from where it was.
	 *
	 * @param bool $verify Compare with the real disk even when the stored data says nothing is wanted (after a restore,
	 *                     which can put back a smaller disk).
	 */
	public function run( bool $verify = false ): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'db_grow: no VM is recorded for this service' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$op = $this->op();

		if ( array() === $op ) {
			if ( ! $verify && ! self::wanted( $this->r ) ) {
				return StepResult::done( array(), 'No data disk change was needed' );
			}

			$target = DbDisk::total( (array) $this->r->get( 'plan' ) );

			if ( $target < 1 ) {
				return StepResult::fail( 'db_grow: the plan has no data disk size' );
			}

			$v = $this->verified( $record, 'db_grow' );

			if ( $v instanceof StepResult ) {
				return $v;
			}

			$actual = $this->actual( $v );

			if ( null === $actual ) {
				return StepResult::fail( 'db_grow: the data disk ' . self::SLOT . ' was not found or has no size' );
			}

			// Nothing to do: the disk has the size and the database knows it.
			if ( $actual >= $target && (int) $this->r->get( 'disk_gb' ) >= $target ) {
				return StepResult::done( array(), 'No data disk change was needed' );
			}

			$op = array( 'phase' => 'capacity', 'target' => $target );

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'db_grow: progress could not be stored' );
			}
		}

		$order = array( 'capacity', 'resize', 'guest', 'record' );
		$at    = array_search( (string) ( $op['phase'] ?? '' ), $order, true );
		$at    = false === $at ? 0 : $at;

		for ( $i = $at; $i < count( $order ); $i++ ) {
			$out = $this->{'phase_' . $order[ $i ]}( $record );

			if ( $out instanceof StepResult ) {
				return $out;
			}

			if ( isset( $order[ $i + 1 ] ) ) {
				$op          = $this->op();
				$op['phase'] = $order[ $i + 1 ];

				if ( ! $this->r->set( array( self::OP => $op ) ) ) {
					return StepResult::retry( 0, 'db_grow: progress could not be stored' );
				}
			}

			$this->progressed = true;
		}

		return $this->finish();
	}

	/**
	 * The stored operation (read fresh).
	 *
	 * @return array<string, mixed>
	 */
	private function op(): array {
		$fresh = Resource::find( $this->r->id() ) ?? $this->r;
		$op    = $fresh->get( self::OP );

		return is_array( $op ) ? $op : array();
	}

	/**
	 * The real size of the data disk in GB, from the VM as read, or null.
	 *
	 * @param array<string, mixed> $v Verified VM.
	 */
	private function actual( array $v ): ?float {
		$disk = $v['config'][ self::SLOT ] ?? null;

		return is_string( $disk ) ? Provision::gigabytes( $disk ) : null;
	}

	/**
	 * The VM's path.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private static function base( array $record, array $v ): string {
		return '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . (int) $record['vmid'];
	}

	/**
	 * Phase capacity: the growth is reserved on the storage. The storage is read first, so a reading is there to count with.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_capacity( array $record ) {
		$v = $this->verified( $record, 'db_grow' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$actual = $this->actual( $v );
		$target = (int) $this->op()['target'];

		if ( null === $actual ) {
			return StepResult::fail( 'db_grow: the data disk ' . self::SLOT . ' was not found or has no size' );
		}

		if ( $actual >= $target ) {
			return null;
		}

		$storage = (string) $this->cluster['storage'];
		$cluster = (string) $record['cluster'];

		DiskCapacity::refresh( $cluster, (string) $v['node'], $storage, $this->client );

		if ( ! DiskCapacity::reserve( $this->r, $cluster, (string) $v['node'], $storage, (int) ceil( $target - $actual ) ) ) {
			return StepResult::retry( 3600, 'db_grow: there is not enough verified storage capacity to make the data disk larger' );
		}

		return null;
	}

	/**
	 * Phase resize: scsi1 is grown to the target and read back.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_resize( array $record ) {
		$v = $this->verified( $record, 'db_grow' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$actual = $this->actual( $v );
		$op     = $this->op();
		$target = (int) $op['target'];

		if ( null === $actual ) {
			return StepResult::fail( 'db_grow: the data disk ' . self::SLOT . ' was not found or has no size' );
		}

		// Never smaller: a disk that is already larger stays as it is.
		if ( $actual > $target + 0.001 ) {
			if ( empty( $op['larger_told'] ) ) {
				Alerts::admin( $this->r, sprintf( 'The data disk is %s GB, more than the %d GB the plan asks for. It was not changed (a disk is never made smaller).', (string) round( $actual, 1 ), $target ) );
				$op['larger_told'] = true;
				$this->r->set( array( self::OP => $op ) );
			}

			return null;
		}

		if ( $actual >= $target - 0.001 ) {
			return null;
		}

		if ( '' !== (string) ( $v['lock'] ?? '' ) ) {
			return StepResult::retry( 60, 'db_grow: the server is locked' );
		}

		$done = $this->run_task( 'db_grow', 'PUT', self::base( $record, $v ) . '/resize', array( 'disk' => self::SLOT, 'size' => $target . 'G' ), 'resize' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$after = $this->verified( $record, 'db_grow' );

		if ( $after instanceof StepResult ) {
			return $after;
		}

		$now = $this->actual( $after );

		if ( null === $now || (int) $now < $target ) {
			return StepResult::retry( 60, 'db_grow: the new size of the data disk could not be confirmed' . ( is_array( $done ) ? ' (the resize task failed: ' . $done['failed'] . ')' : '' ) );
		}

		return null;
	}

	/**
	 * Phase guest: the grow job, sent once, then its status. An old guest or one that does not answer is not a failure of
	 * the database: the admin is told what to run and the file system waits.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_guest( array $record ) {
		$v = $this->verified( $record, 'db_grow' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$record['node'] = $v['node'];
		$op             = $this->op();
		$target         = (int) $op['target'];

		if ( empty( $op['request'] ) ) {
			$op['request'] = wp_generate_uuid4();
			$op['sent']    = false;
			$op['at']      = time();

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'db_grow: progress could not be stored' );
			}
		}

		$request = (string) $op['request'];
		$waited  = time() - (int) ( $op['at'] ?? time() ) > self::BUDGET;

		if ( empty( $op['sent'] ) ) {
			$sent = DbJob::send( $this->client, $record, array( 'v' => 1, 'request' => $request, 'op' => 'grow', 'min_gb' => $target ) );

			if ( is_wp_error( $sent ) ) {
				return $waited ? $this->manual_fs( $record, 'The guest agent did not accept the grow job' ) : StepResult::poll( 10, 'db_grow: waiting for the guest agent', self::BUDGET );
			}

			$op['sent'] = true;

			return $this->r->set( array( self::OP => $op ) ) ? StepResult::poll( 5, 'db_grow: the grow job was sent', self::BUDGET ) : StepResult::retry( 0, 'db_grow: progress could not be stored' );
		}

		$status = DbGuest::status( $this->client, (string) $record['node'], (int) $record['vmid'], $request );

		if ( null !== $status && 'failed' === $status['state'] ) {
			// A guest from before the job existed refuses the job itself (step "job").
			if ( 'job' === $status['step'] ) {
				return $this->manual_fs( $record, 'The database server does not know the grow job: its template is older than the guest script that supports it' );
			}

			// The disk did not show its new size yet, or the file system could not be grown: the same job is sent again.
			if ( $waited ) {
				return $this->manual_fs( $record, 'The database server could not grow its file system (step ' . $status['step'] . ')' );
			}

			$op['sent'] = false;

			return $this->r->set( array( self::OP => $op ) ) ? StepResult::poll( 15, 'db_grow: the database server did not grow its file system yet, sending the job again', self::BUDGET ) : StepResult::retry( 0, 'db_grow: progress could not be stored' );
		}

		if ( null !== $status && 'ready' === $status['state'] && isset( $status['size_bytes'] ) && $status['size_bytes'] >= $target * 1073741824 ) {
			return null;
		}

		return $waited ? $this->manual_fs( $record, 'The database server did not grow its file system within 10 minutes' ) : StepResult::poll( 10, 'db_grow: waiting for the database server', self::BUDGET );
	}

	/**
	 * The guest could not be told or did not do it: the file system is left for the admin, who gets the command. The run
	 * goes on to record the new size (the disk has it), and the dashboard says the storage is being prepared.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param string               $why    Reason.
	 * @return StepResult|null Null: carry on.
	 */
	private function manual_fs( array $record, string $why ) {
		$op           = $this->op();
		$op['manual'] = true;
		$this->r->set( array( self::OP => $op, self::FS => 'manual' ) );

		Alerts::admin( $this->r, $why . '. The data disk was made larger, but its file system was not grown. Run on the Proxmox host: ' . self::manual_command( (int) $record['vmid'] ) );

		return null;
	}

	/**
	 * Phase record: the new size is stored and the reservation released.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_record( array $record ) {
		$v = $this->verified( $record, 'db_grow' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$op     = $this->op();
		$actual = $this->actual( $v );
		$size   = max( (int) $op['target'], null === $actual ? 0 : (int) floor( $actual ) );

		if ( ! $this->r->set( array( 'disk_gb' => $size ) ) ) {
			return StepResult::retry( 0, 'db_grow: the new size could not be stored' );
		}

		DiskCapacity::release( $this->r );

		return null;
	}

	/**
	 * The end: the operation is cleared.
	 */
	private function finish(): StepResult {
		$op     = $this->op();
		$manual = ! empty( $op['manual'] );
		$meta   = array( 'disk_gb' => (int) ( Resource::find( $this->r->id() ) ?? $this->r )->get( 'disk_gb' ) );
		$clear  = array( self::OP => null );

		if ( ! $manual ) {
			$clear[ self::FS ] = null;
		}

		if ( ! $this->r->set( $clear ) ) {
			return StepResult::retry( 0, 'db_grow: progress could not be stored' );
		}

		return StepResult::done( $meta + $clear, 'Data disk made larger' );
	}
}

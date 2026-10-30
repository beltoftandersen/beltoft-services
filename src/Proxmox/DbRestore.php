<?php
/**
 * The end of a restore of a managed database: the server is started and the database must answer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Actions::restore() puts the whole server back to a backup, keeps the private network and the firewall right
 * (RestoreSteps::reapply_database) and queues the plan change with meta `was_running`, as for a server. On a server
 * that change grows the boot disk and starts the VM; a database's plan disk is its data disk, so DbDriver::update_plan
 * sends that change here instead: start the VM, then wait for the guest's health file to say `ok` (written after the
 * start, like the db_ready step of the build). A database that does not answer within BUDGET fails the service, which
 * alerts the admin. The password is the one the backup had; the credentials meta is not touched.
 */
final class DbRestore {

	use Steps;
	use Prepares;

	/** Meta: when the VM was seen running after the restore. */
	public const SINCE = 'db_restore_since';

	/** Seconds the database has to answer after the restore (the guest writes health.json every 5 minutes). */
	public const BUDGET = 900;

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Is a restore of this database being finished?
	 *
	 * @param Resource $r Resource.
	 */
	public static function waiting( Resource $r ): bool {
		return DbActions::is_db( $r ) && true === $r->get( 'was_running' );
	}

	/**
	 * Starts the VM and waits for a healthy database.
	 */
	public function finish(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'db_restore: no VM is recorded for this service', array( 'was_running' => null ) );
		}

		$started = ( new Operations( $this->r ) )->resume();

		if ( ! $started->is_done() ) {
			return $started;
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, 'db_restore' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$since = (int) $this->r->get( self::SINCE );

		if ( $since <= 0 ) {
			$since = time();

			if ( ! $this->r->set( array( self::SINCE => $since ) ) ) {
				return StepResult::retry( 0, 'db_restore: progress could not be stored' );
			}
		}

		if ( DbGuest::healthy( $this->client, (string) $v['node'], (int) $record['vmid'], $since ) ) {
			return StepResult::done(
				array(
					'was_running'   => null,
					self::SINCE     => null,
					DbMonitor::META => null,
					'db_ready_at'   => time(),
				),
				'Database restored from a backup'
			);
		}

		if ( time() - $since > self::BUDGET ) {
			return StepResult::fail( 'The database did not become healthy within 15 minutes after the restore', array( 'was_running' => null, self::SINCE => null ) );
		}

		return StepResult::poll( 15, 'db_restore: waiting for the database', self::BUDGET );
	}
}

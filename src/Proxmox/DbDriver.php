<?php
/**
 * The managed database driver: a database engine (PostgreSQL, SQL Server Express or Valkey, see DbEngines) on its own VM, reachable on the customer's private network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Builds, pauses, resumes and deletes like a VM (Provision with DbProfile, Operations); what differs is the
 * build profile, the card and the one customer action. Backups are daily (Backups), restored with the server's own restore (Actions) and finished by DbRestore.
 */
class DbDriver extends ProxmoxDriver {

	public const ID = 'proxmox-db';

	/**
	 * The PostgreSQL majors the guest and the template carry (the SQL Server version is in MssqlEngine). The guest checks the same list.
	 */
	public const SUPPORTED_MAJORS = array( 17, 18 );

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Managed database', 'beltoft-services' );
	}

	public function kind(): string {
		return 'db';
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: databases used, 2: databases allowed */
		return sprintf( _n( '%1$d of %2$d database', '%1$d of %2$d databases', $max, 'beltoft-services' ), $used, $max );
	}

	public function plan_fields(): array {
		$fields = parent::plan_fields();
		$out    = array(
			'cluster'     => $fields['cluster'],
			'template'    => array( 'number', __( 'Template VMID', 'beltoft-services' ), __( 'The engine\'s template (family postgres-<major>, mssql-<version> or valkey in the cluster settings, for example postgres-17,postgres-18, mssql-2025 or valkey).', 'beltoft-services' ), '', true ),
			'engine'      => array( 'text', __( 'Engine', 'beltoft-services' ), __( 'postgres, mssql (SQL Server Express) or valkey. Empty means postgres.', 'beltoft-services' ), 'postgres', true ),
			'major'       => array( 'number', __( 'Major version', 'beltoft-services' ), __( 'PostgreSQL: the major version, 17 or 18. SQL Server: the version, 2025. Valkey ignores it.', 'beltoft-services' ), 18, true ),
			'cores'       => $fields['cores'],
			'memory_mb'   => $fields['memory_mb'],
			'disk_gb'     => array( 'number', __( 'Data disk (GB)', 'beltoft-services' ), __( 'The disk the database lives on. It is part of the backup. Extra storage is added to it.', 'beltoft-services' ), 20, true ),
			'backup_days' => $fields['backup_days'],
			'public_ip'   => array( 'number', __( 'Public IP', 'beltoft-services' ), __( '0 or 1. 1 gives the database a public IPv4 address (an add-on: make a variation pair that differs only in this field). Access is closed until the customer adds addresses to the allow-list.', 'beltoft-services' ), 0, true ),
			DbDisk::FIELD => array( 'number', __( 'Extra storage (GB)', 'beltoft-services' ), __( 'Storage added to the data disk (an add-on: variations of one plan that differ only in this field and in the attribute "Extra storage", slug extra-storage). The data disk is the data disk above plus this. It can only grow.', 'beltoft-services' ), 0, true ),
		);

		return $out;
	}

	/**
	 * A database paused before its setup ended continues provisioning.
	 */
	public function resume( Resource $r ): StepResult {
		return in_array( 'db_ready', (array) $r->get( 'steps' ), true ) ? parent::resume( $r ) : $this->provision( $r );
	}

	public function customer_actions( Resource $r ): array {
		return DbActions::offered( $r );
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		if ( DbActions::RESTORE === $action ) {
			return ( new Actions( $r ) )->run( 'restore', $args );
		}

		if ( DbAccess::APPLY === $action ) {
			return ( new DbAccess( $r ) )->run();
		}

		if ( DbPolicy::ACTION === $action ) {
			return ( new DbPolicy( $r ) )->run( $args );
		}

		return DbActions::RESET === $action ? ( new DbActions( $r ) )->run() : StepResult::fail( 'This database action is not available.' );
	}

	/**
	 * A restore queues this change to start the server (DbRestore). Then, in one call: a change of the public IP add-on
	 * is made to the VM (DbIp), and a data disk that is smaller than the plan's (the plan's disk and its extra storage) is
	 * made larger (DbGrow, which reads the real disk: a restore can put back a smaller one). Any other plan difference
	 * (cores, memory) is not applied: nothing is changed and the admin is told.
	 */
	public function update_plan( Resource $r ): StepResult {
		$meta   = array();
		$events = array();
		$did    = false;

		$restored = false;

		if ( DbRestore::waiting( $r ) ) {
			$res = ( new DbRestore( $r ) )->finish();

			if ( ! $res->is_done() ) {
				return $res;
			}

			$restored = true;
			$did      = true;
			$meta     = $res->meta();

			if ( '' !== $res->event() ) {
				$events[] = $res->event();
			}
		}

		if ( '' !== DbIp::wanted( $r ) ) {
			if ( array() !== $meta ) {
				$r->set( $meta );
			}

			$res = ( new DbIp( Resource::find( $r->id() ) ?? $r ) )->run();

			if ( ! $res->is_done() ) {
				return $this->settle( $r, $res, $events );
			}

			$did  = true;
			$meta = array_merge( $meta, $res->meta() );

			if ( '' !== $res->event() ) {
				$events[] = $res->event();
			}
		}

		$fresh = Resource::find( $r->id() ) ?? $r;

		if ( $restored || DbGrow::wanted( $fresh ) ) {
			if ( array() !== $meta ) {
				$r->set( $meta );
			}

			$res = ( new DbGrow( Resource::find( $r->id() ) ?? $r ) )->run( $restored );

			if ( ! $res->is_done() ) {
				return $this->settle( $r, $res, $events );
			}

			$did  = true;
			$meta = array_merge( $meta, $res->meta() );

			if ( '' !== $res->event() && 'No data disk change was needed' !== $res->event() ) {
				$events[] = $res->event();
			}
		}

		if ( ! $did ) {
			Alerts::admin( $r, 'The plan of this database changed. Only the public IP address and the storage are applied automatically (cores and memory are not): nothing was changed.' );

			return StepResult::done( array(), 'Plan change on a database was not applied' );
		}

		return StepResult::done( $meta, array() === $events ? '' : implode( '; ', array_unique( $events ) ) );
	}

	/**
	 * A step after the first did not finish: what the earlier ones did is kept (stored, and noted for the license), so a
	 * later call does not repeat it.
	 *
	 * @param Resource           $r      Resource.
	 * @param StepResult         $res    The unfinished result.
	 * @param array<int, string> $events What the earlier steps did.
	 */
	private function settle( Resource $r, StepResult $res, array $events ): StepResult {
		foreach ( $events as $event ) {
			\BeltoftLicenses\Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', $event );
		}

		return $res;
	}

	/**
	 * The job that brings the firewall and the guest to the stored allow-list. Queued by the route that stored it.
	 */
	public function internal_actions(): array {
		return array( DbAccess::APPLY );
	}

	public function render_account( Resource $r ): void {
		DbCard::render( $r );
	}
}

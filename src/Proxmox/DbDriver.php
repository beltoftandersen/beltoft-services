<?php
/**
 * The managed database driver: a database engine (PostgreSQL or SQL Server Express, see DbEngines) on its own VM, reachable on the customer's private network.
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
			'template'    => array( 'number', __( 'Template VMID', 'beltoft-services' ), __( 'The engine\'s template (family postgres-<major> or mssql-<version> in the cluster settings, for example postgres-17,postgres-18 or mssql-2022).', 'beltoft-services' ), '', true ),
			'engine'      => array( 'text', __( 'Engine', 'beltoft-services' ), __( 'postgres or mssql (SQL Server Express). Empty means postgres.', 'beltoft-services' ), 'postgres', true ),
			'major'       => array( 'number', __( 'Major version', 'beltoft-services' ), __( 'PostgreSQL: the major version, 17 or 18. SQL Server: the version, 2022.', 'beltoft-services' ), 18, true ),
			'cores'       => $fields['cores'],
			'memory_mb'   => $fields['memory_mb'],
			'disk_gb'     => array( 'number', __( 'Data disk (GB)', 'beltoft-services' ), __( 'The disk the database lives on. It is part of the backup.', 'beltoft-services' ), 20, true ),
			'backup_days' => $fields['backup_days'],
			'public_ip'   => array( 'number', __( 'Public IP', 'beltoft-services' ), __( '0 or 1. 1 gives the database a public IPv4 address (an add-on: make a variation pair that differs only in this field). Access is closed until the customer adds addresses to the allow-list.', 'beltoft-services' ), 0, true ),
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

		return DbActions::RESET === $action ? ( new DbActions( $r ) )->run() : StepResult::fail( 'This database action is not available.' );
	}

	/**
	 * A restore queues this change to start the server (DbRestore). A change of the public IP add-on is made to the VM
	 * (DbIp). Otherwise the plan's disk is the data disk, and growing it is not built yet: nothing is changed, the
	 * admin is told.
	 */
	public function update_plan( Resource $r ): StepResult {
		if ( DbRestore::waiting( $r ) ) {
			return ( new DbRestore( $r ) )->finish();
		}

		if ( '' !== DbIp::wanted( $r ) ) {
			return ( new DbIp( $r ) )->run();
		}

		Alerts::admin( $r, 'The plan of this database changed. Resizing a database is not automatic yet: nothing was changed.' );

		return StepResult::done( array(), 'Plan change on a database was not applied' );
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

<?php
/**
 * The managed database driver: a database engine (PostgreSQL, SQL Server Express or Valkey, see DbEngines) on its own VM, reachable on the customer's private network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\Ipv6Switch;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
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

	/**
	 * {@inheritDoc}
	 */
	public function step_labels(): array {
		return array(
			'ip'           => __( 'Reserving a public IP address', 'beltoft-services' ),
			'net'          => __( 'Reserving a private address', 'beltoft-services' ),
			'vmid'         => __( 'Preparing the server', 'beltoft-services' ),
			'clone'        => __( 'Creating the server', 'beltoft-services' ),
			'configure'    => __( 'Applying your settings', 'beltoft-services' ),
			'start'        => __( 'Starting the server', 'beltoft-services' ),
			'ready'        => __( 'Final checks', 'beltoft-services' ),
			'db_init'      => __( 'Setting up the database', 'beltoft-services' ),
			'db_ready'     => __( 'Checking the database', 'beltoft-services' ),
		);
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
			'public_ip'   => array( 'number', __( 'Public IP', 'beltoft-services' ), __( 'Only for a database built before the IPv4 address add-on: 1 means it has a public IPv4 address. New databases get theirs from the add-on, not from this field. Access is closed until the customer adds addresses to the allow-list.', 'beltoft-services' ), 0, true ),
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
		// Shut down and start: the server's own actions, after the database's checks again under the lock.
		if ( DbPower::is_power( $action ) ) {
			$why = DbPower::block( $r, false );

			if ( null !== $why ) {
				return StepResult::refused( array(), ucfirst( $action ) . ' refused: ' . $why );
			}

			$result = ( new Actions( $r ) )->run( $action, $args );
			DbPower::after( $r, $action, $result );

			return $result;
		}

		// The IPv4 add-on asks for the address to be put on the VM or taken off it (see Ipv4\Ipv4Driver).
		if ( DbIp::SYNC_ACTION === $action ) {
			return ( new DbIp( Resource::find( $r->id() ) ?? $r ) )->run();
		}

		if ( Ipv6Switch::is_action( $action ) ) {
			return $this->ipv6( $r, $action );
		}

		if ( DbActions::RESTORE === $action ) {
			return ( new Actions( $r ) )->run( 'restore', $args );
		}

		if ( DbActions::BACKUP_DELETE === $action ) {
			return ( new Actions( $r ) )->run( 'backup_delete', $args );
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
	 * The IPv6 switch of a database: the server's own steps (ipconfig0, meta, ipfilter, one restart), then the guest is told
	 * the new listen addresses and the allow list through DbIp's `sync` (the listen job with `listen6`, the access list
	 * with `allow6`, the firewall). A repeat that finds the sync marked finishes the sync only.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action ipv6_add or ipv6_remove.
	 */
	private function ipv6( Resource $r, string $action ): StepResult {
		if ( true !== $r->get( DbIp::RESYNC ) ) {
			$res = ( new Actions( $r ) )->run( $action, array() );

			if ( ! $res->is_done() || $res->is_refusal() ) {
				return $res;
			}

			// The restart is over: the guest follows, with the IPv4 and IPv6 addresses the VM has now.
			$r->set( array( DbIp::RESYNC => true ) );
			$r = Resource::find( $r->id() ) ?? $r;
		}

		$sync = ( new DbIp( Resource::find( $r->id() ) ?? $r ) )->run();

		return $sync->is_done() ? StepResult::done( array( Jobs::TEXT => ( Ipv6Switch::ADD === $action ? __( 'IPv6 was added to the database and it restarted.', 'beltoft-services' ) : __( 'IPv6 was removed from the database and it restarted.', 'beltoft-services' ) ) ), Ipv6Switch::ADD === $action ? 'Customer added IPv6 to the database' : 'Customer removed IPv6 from the database' ) : $sync;
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
	 * The jobs only the plugin queues: the one that brings the firewall and the guest to the stored allow-list (queued by the route that stored it), and the one that puts the IPv4 add-on's address on the VM or takes it off (queued by the add-on).
	 */
	public function internal_actions(): array {
		return array( DbAccess::APPLY, DbIp::SYNC_ACTION );
	}

	public function render_account( Resource $r ): void {
		DbCard::render( $r );
	}
}

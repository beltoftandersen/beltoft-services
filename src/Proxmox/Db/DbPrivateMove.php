<?php
/**
 * Moves a managed database to another of the customer's private networks.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\PrivateAttach;
use BeltoftServices\Proxmox\Network\PrivateNets;
use BeltoftServices\Proxmox\Network\PrivateNic;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\RebootsOnce;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A database is always on one private network. Moving it, in phases that are stored (meta `db_private_op`) so a job that is
 * run again goes on where it stopped:
 *  - pool: the address moves to the chosen network (PrivateNets::move; the old one is let go);
 *  - config: net1 and ipconfig1 of the VM for the new network (PrivateNic::params, as at build time), read back;
 *  - restart: one reboot (RebootsOnce, as for an IPv4 change), so the VM comes up with the new address;
 *  - guest: the guest's `private` job: the database listens on the new address and its own allow-list and certificate follow;
 *  - record: `db_address`, `db_cidr` and the firewall's ipfilter set of net1.
 * The customer's servers on the new network reach the database on its new address; the old address stops answering.
 */
final class DbPrivateMove {

	use Steps;
	use Prepares;
	use RebootsOnce;

	/** The customer action. */
	public const ACTION = 'db_private_move';

	/** Meta: the move in progress. */
	public const OP = 'db_private_op';

	/**
	 * Did a started move stop on a failure (the guest did not follow)? The customer can then finish it (the same action again).
	 *
	 * @param Resource $r Database.
	 */
	public static function unfinished( Resource $r ): bool {
		$op = $r->get( self::OP );

		return is_array( $op ) && ! empty( $op['failed'] );
	}

	/** Seconds the guest has for the private job. */
	private const GUEST_BUDGET = 600;

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Does the database's guest know the `private` job? Only the templates with the public card have it (db-v4, Valkey v2, SQL
	 * Server v2), and a database built from them is marked at build (DbIp::knows_private). An older guest would refuse the job
	 * after the VM had already moved, leaving the database on its old address: the move is not offered there.
	 *
	 * @param Resource $r Database.
	 */
	public static function supported( Resource $r ): bool {
		return DbActions::is_db( $r ) && DbIp::knows_private( $r );
	}

	/**
	 * May the database be moved now, with these arguments? Null, or the reason in words for the customer.
	 *
	 * @param Resource             $r    Database.
	 * @param array<string, mixed> $args net (an id of the customer's networks, or `new`) and name.
	 */
	public static function check( Resource $r, array $args ): ?string {
		if ( ! self::supported( $r ) ) {
			return __( 'This database cannot be moved to another private network.', 'beltoft-services' );
		}

		if ( ! DbActions::is_db( $r ) || 'ready' !== $r->get( 'db_state' ) || ! $r->accepts_actions() ) {
			return __( 'The database can be moved when it is ready.', 'beltoft-services' );
		}

		if ( is_array( $r->get( self::OP ) ) ) {
			return null; // A move that has begun is finished.
		}

		$row = PrivateNets::for_resource( $r->id() );

		if ( null === $row ) {
			return __( 'The private network is not available for this server.', 'beltoft-services' );
		}

		$status = Operations::cached_status( $r->id() );

		if ( null !== $status && 'stopped' === (string) $status['power'] ) {
			return __( 'Start the database first. It is moved while it runs.', 'beltoft-services' );
		}

		$net  = trim( (string) ( $args['net'] ?? '' ) );
		$user = (int) ( $r->license()['user_id'] ?? 0 );

		if ( 'new' === $net ) {
			return '' === PrivateNets::clean_name( (string) ( $args['name'] ?? '' ) ) || ! PrivateNets::can_create( $user ) ? __( 'A new private network cannot be made now.', 'beltoft-services' ) : null;
		}

		$id = (int) $net;

		if ( $id < 1 || null === PrivateNets::net_of_user( $user, $id ) ) {
			return __( 'Choose one of your private networks.', 'beltoft-services' );
		}

		return $id === (int) $row['net_id'] ? __( 'The database is on that network already.', 'beltoft-services' ) : null;
	}

	/**
	 * The job (from DbDriver::act under the resource lock).
	 *
	 * @param array<string, mixed> $args The customer's choice (used to start the move).
	 */
	public function run( array $args ): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'db_private: no VM is recorded for this service' );
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

		if ( ! empty( $op['failed'] ) ) {
			unset( $op['failed'] );

			if ( ! $this->store( $op ) ) {
				return StepResult::retry( 0, 'db_private: progress could not be stored' );
			}
		}

		if ( array() === $op ) {
			$why = self::check( $this->r, $args );

			if ( null !== $why ) {
				return StepResult::refused( array(), 'Move refused: ' . $why );
			}

			$op = array( 'phase' => 'pool', 'net' => trim( (string) ( $args['net'] ?? '' ) ), 'name' => PrivateNets::clean_name( (string) ( $args['name'] ?? '' ) ) );

			if ( ! $this->store( $op ) ) {
				return StepResult::retry( 0, 'db_private: progress could not be stored' );
			}
		}

		$order = array( 'pool', 'config', 'restart', 'guest', 'record' );
		$at    = array_search( (string) ( $op['phase'] ?? '' ), $order, true );

		for ( $i = false === $at ? 0 : $at; $i < count( $order ); $i++ ) {
			$out = $this->{'phase_' . $order[ $i ]}( $record );

			if ( $out instanceof StepResult ) {
				return $out;
			}

			if ( isset( $order[ $i + 1 ] ) ) {
				$op          = $this->op();
				$op['phase'] = $order[ $i + 1 ];

				if ( ! $this->store( $op ) ) {
					return StepResult::retry( 0, 'db_private: progress could not be stored' );
				}
			}
		}

		$this->r->set( array( self::OP => null ) );

		return StepResult::done( array( Jobs::TEXT => __( 'The database was moved to the other private network and restarted.', 'beltoft-services' ) ), 'Database moved to another private network' );
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
	 * @param array<string, mixed> $op Operation.
	 */
	private function store( array $op ): bool {
		return $this->r->set( array( self::OP => $op ) );
	}

	/**
	 * Phase pool: the address moves to the chosen network, once.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_pool( array $record ) {
		unset( $record );

		$op = $this->op();

		if ( ! empty( $op['address'] ) ) {
			return null;
		}

		$row = PrivateNets::for_resource( $this->r->id() );

		if ( null === $row ) {
			$this->r->set( array( self::OP => null ) );

			return StepResult::refused( array(), 'Move refused: ' . __( 'The private network is not available for this server.', 'beltoft-services' ) );
		}

		// The pool moved already, but its answer was not stored (the job stopped in between): the row is on another network now.
		if ( isset( $op['old_net_id'] ) ) {
			if ( (int) $row['net_id'] !== (int) $op['old_net_id'] ) {
				$net = PrivateNets::net_of_user( (int) ( $this->r->license()['user_id'] ?? 0 ), (int) $row['net_id'] );

				if ( null === $net ) {
					return StepResult::fail( 'db_private: the new private network is not recorded' );
				}

				$op['address'] = (string) $row['address'];
				$op['cidr']    = (string) $net['cidr'];

				return $this->store( $op ) ? null : StepResult::retry( 0, 'db_private: progress could not be stored' );
			}
		} else {
			// The network the database leaves, stored before the pool is changed.
			$op['old_net_id']  = (int) $row['net_id'];
			$op['old_address'] = (string) $row['address'];

			if ( ! $this->store( $op ) ) {
				return StepResult::retry( 0, 'db_private: progress could not be stored' );
			}
		}

		// Paused: nothing is changed, not even the pool (the old address stays the database's while it is still on the VM).
		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, 'db_private: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$user = (int) ( $this->r->license()['user_id'] ?? 0 );

		try {
			$moved = 'new' === $op['net'] ? PrivateNets::move( $user, $this->r->id(), 0, (string) $op['name'] ) : PrivateNets::move( $user, $this->r->id(), (int) $op['net'] );
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 30, 'db_private: the private network pool is busy' );
		}

		if ( null === $moved ) {
			$this->r->set( array( self::OP => null ) );

			return StepResult::refused( array(), 'Move refused: ' . __( 'The chosen private network has no free address.', 'beltoft-services' ) );
		}

		$op['address'] = $moved['address'];
		$op['cidr']    = (string) $moved['network']['cidr'];

		return $this->store( $op ) ? null : StepResult::retry( 0, 'db_private: progress could not be stored' );
	}

	/**
	 * Phase config: net1 and ipconfig1 for the new network, written once and read back.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_config( array $record ) {
		$v = $this->verified( $record, 'db_private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$row = PrivateNets::for_resource( $this->r->id() );
		$op  = $this->op();

		if ( null === $row || $row['address'] !== $op['address'] ) {
			return StepResult::fail( 'db_private: the new private address is not recorded' );
		}

		$want = PrivateNic::params( $row, (string) $row['address'], (string) ( $this->cluster['private_bridge'] ?? '' ) );

		if ( is_wp_error( $want ) ) {
			return StepResult::fail( 'db_private: ' . $want->get_error_message() );
		}

		if ( $this->in_place( (array) $v['config'], $want ) ) {
			return $this->filter_new( $record, $v );
		}

		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, 'db_private: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		// The card keeps its MAC: the new VLAN and address are written on the existing net1.
		$mac  = PrivateAttach::mac_of( (string) ( $v['config']['net1'] ?? '' ) );
		$net1 = '' !== $mac ? str_replace( 'virtio,', 'virtio=' . $mac . ',', $want['net1'] ) : $want['net1'];

		$params = array( 'net1' => $net1, 'ipconfig1' => $want['ipconfig1'] );

		if ( ! empty( $v['config']['digest'] ) ) {
			$params['digest'] = (string) $v['config']['digest'];
		}

		$answer = $this->client->request( 'PUT', Actions::base( $record, $v ) . '/config', $params );
		$after  = $this->verified( $record, 'db_private' );

		if ( $after instanceof StepResult ) {
			return $after;
		}

		if ( ! $this->in_place( (array) $after['config'], $want ) ) {
			return is_wp_error( $answer ) ? $this->classify( $answer, 'db_private' ) : StepResult::retry( 0, 'db_private: the private network settings could not be confirmed' );
		}

		return $this->filter_new( $record, $after );
	}

	/**
	 * The firewall's ipfilter set of net1 holds the new address (from the pool) before the VM restarts with it, so its private
	 * traffic is not dropped while the guest and the record follow.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return StepResult|null
	 */
	private function filter_new( array $record, array $v ) {
		$sets = ( new Firewall( $this->r, $this->client, $this->cluster ) )->ensure_ipfilter( $record, $v );

		return $sets instanceof StepResult ? $sets : null;
	}

	/**
	 * Does the VM's config have the new private network: the VLAN tag of net1 and ipconfig1?
	 *
	 * @param array<string, mixed>  $config Config.
	 * @param array<string, string> $want   PrivateNic::params().
	 */
	private function in_place( array $config, array $want ): bool {
		$have = \BeltoftServices\Proxmox\Network\NetConfig::parse( (string) ( $config['net1'] ?? '' ) );
		$need = \BeltoftServices\Proxmox\Network\NetConfig::parse( $want['net1'] );

		return (string) ( $have['tag'] ?? '' ) === (string) ( $need['tag'] ?? '' ) && (string) ( $have['bridge'] ?? '' ) === (string) ( $need['bridge'] ?? '' ) && (string) ( $config['ipconfig1'] ?? '' ) === $want['ipconfig1'];
	}

	/**
	 * Phase restart: one reboot.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_restart( array $record ) {
		return $this->reboot_once( $record, 'db_private', fn(): array => $this->op(), fn( array $op ): bool => $this->store( $op ) );
	}

	/**
	 * Phase guest: the guest's `private` job, sent once, then its status.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_guest( array $record ) {
		$v = $this->verified( $record, 'db_private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$record['node'] = $v['node'];
		$op             = $this->op();

		if ( empty( $op['request'] ) ) {
			$op['request'] = wp_generate_uuid4();
			$op['sent']    = false;
			$op['at']      = time();

			if ( ! $this->store( $op ) ) {
				return StepResult::retry( 0, 'db_private: progress could not be stored' );
			}
		}

		$waited = time() - (int) ( $op['at'] ?? time() ) > self::GUEST_BUDGET;

		if ( empty( $op['sent'] ) ) {
			$sent = DbJob::send( $this->client, $record, array( 'v' => 1, 'request' => (string) $op['request'], 'op' => 'private', 'address' => (string) $op['address'], 'private_cidr' => (string) $op['cidr'] ) );

			if ( is_wp_error( $sent ) ) {
				return $waited ? $this->guest_failed( 'The guest agent did not accept the private network job' ) : StepResult::poll( 10, 'db_private: waiting for the guest agent', self::GUEST_BUDGET );
			}

			$op['sent'] = true;
			$op['at']   = time();

			return $this->store( $op ) ? StepResult::poll( 5, 'db_private: the new private address was sent', self::GUEST_BUDGET ) : StepResult::retry( 0, 'db_private: progress could not be stored' );
		}

		$status = DbGuest::status( $this->client, (string) $record['node'], (int) $record['vmid'], (string) $op['request'] );

		if ( null !== $status && 'failed' === $status['state'] ) {
			return $this->guest_failed( 'job' === $status['step']
				? 'The database server does not know the private network job: its template is older than the guest script that supports it. Rebuild the database template'
				: 'The database server did not apply the new private address (step ' . $status['step'] . ')' );
		}

		if ( null === $status || 'ready' !== $status['state'] ) {
			return $waited ? $this->guest_failed( 'The database server did not apply the new private address within 10 minutes' ) : StepResult::poll( 10, 'db_private: waiting for the database server', self::GUEST_BUDGET );
		}

		return null;
	}

	/**
	 * The guest did not follow: the VM is on the new network, the database still listens on the old address. The admin is told;
	 * the move fails and is marked so (unfinished()): the customer's Finish button runs it again from the guest job, and the
	 * database counts as busy (DbIp::idle) until then.
	 *
	 * @param string $why Reason.
	 */
	private function guest_failed( string $why ): StepResult {
		$op = $this->op();
		unset( $op['request'], $op['sent'], $op['at'] );
		$op['failed'] = true;
		$this->store( $op );
		\BeltoftServices\Alerts::admin( $this->r, $why . '. The VM is on the new private network but the database may not listen on its new address ' . (string) ( $op['address'] ?? '' ) . '.' );

		return StepResult::fail( $why );
	}

	/**
	 * Phase record: the database's stored address and range, and the firewall's ipfilter set for net1.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_record( array $record ) {
		$op = $this->op();

		if ( ! $this->r->set( array( 'db_address' => (string) $op['address'], 'db_cidr' => (string) $op['cidr'] ) ) ) {
			return StepResult::retry( 0, 'db_private: progress could not be stored' );
		}

		$v = $this->verified( $record, 'db_private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$sets = ( new Firewall( $this->r, $this->client, $this->cluster ) )->ensure_ipfilter( $record, $v );

		return $sets instanceof StepResult ? $sets : null;
	}
}

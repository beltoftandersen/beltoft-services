<?php
/**
 * The public IPv4 address of a managed database, applied to the VM as net0.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\IpHistory;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Network\NetConfig;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The address is the IPv4 add-on (Ipv4\Ipv4Driver): a subscription of its own linked to the database. The add-on takes the
 * address from the pool and queues the action `db_ip_sync` on the database, which runs this class; `wanted()` reads the
 * add-on (and, for a database built before the add-on, the plan field `public_ip`) and makes the VM match:
 *
 * - add: take an address from the pool (IpPool::hold), shut the VM down, write net0 and ipconfig0 and read them back,
 *   start it, tell the guest to listen on the new address and to put it in its certificate (the `listen` job), apply an
 *   empty allow-list (nothing is allowed until the customer adds an address), then the firewall.
 * - remove: clear the allow-list in the firewall and the guest, shut the VM down, delete net0 and ipconfig0 and read
 *   the config back, give the address back only after that, start the VM, tell the guest to listen on the private
 *   address only. A guest that cannot be told is reported to the admin (the address is gone anyway).
 *
 * The VM restarts once, and the customer is told before they confirm. Each phase is idempotent and the stored
 * operation (`db_ip_op`) remembers where it was; Proxmox is read before anything is believed.
 */
final class DbIp {

	use Steps;
	use Prepares;

	/** Meta: the operation in progress (action, phase, flags). */
	public const OP = 'db_ip_op';

	/** The plugin action the IPv4 add-on queues on the database to make its VM match the add-on. */
	public const SYNC_ACTION = 'db_ip_sync';

	/** Meta: a restore put the backup's guest layers back; the listen addresses and the allow-list are sent again. */
	public const RESYNC = 'db_resync';

	/** Seconds the guest has for a listen job. */
	private const LISTEN_BUDGET = 600;

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Should the database have a public IPv4 address: the shared rule (Ipv4Meta::main_wanted), an IPv4 add-on holds it.
	 *
	 * @param Resource $r Database.
	 */
	public static function wants( Resource $r ): bool {
		return \BeltoftServices\Proxmox\Ipv4\Ipv4Meta::db_wants( $r );
	}

	/**
	 * The action in progress: add, remove, sync (after a restore), or ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function action( Resource $r ): string {
		$op = $r->get( self::OP );

		return is_array( $op ) && in_array( $op['action'] ?? '', array( 'add', 'remove', 'sync' ), true ) ? (string) $op['action'] : '';
	}

	/**
	 * Is no address change in progress?
	 *
	 * @param Resource $r Resource.
	 */
	public static function idle( Resource $r ): bool {
		return '' === self::action( $r );
	}

	/**
	 * What the VM needs to match the plan: add, remove, or '' (nothing). An operation that has begun is finished.
	 *
	 * @param Resource $r Resource.
	 */
	public static function wanted( Resource $r ): string {
		$action = self::action( $r );

		if ( '' !== $action ) {
			return $action;
		}

		$row = IpPool::for_resource( $r->id() );

		// A database with its public card gets and loses an IPv4 address through Ipv4Server: only the guest sync is left here.
		if ( self::has_card( $r ) ) {
			return true === $r->get( self::RESYNC ) ? 'sync' : '';
		}

		if ( self::wants( $r ) ) {
			$change = null === $row || 'assigned' !== $row['state'] ? 'add' : '';
		} else {
			$change = null !== $row ? 'remove' : '';
		}

		// A restore put the backup's guest layers back (listen addresses, allow-list): the stored ones are sent again.
		return '' === $change && true === $r->get( self::RESYNC ) ? 'sync' : $change;
	}

	/**
	 * What a restored VM's net0 and ipconfig0 must be changed to so that they match the address pool: the public NIC when the
	 * pool holds an address for this database, none when it does not (the backup may carry an address that was given back and
	 * handed to someone else). Null: nothing to change. An address in the middle of an add or a remove is left to DbIp::run.
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $cluster Cluster.
	 * @param array<string, mixed> $config  The VM's config now.
	 * @return array<string, string>|null PUT parameters (without digest), or null.
	 */
	public static function nic_repair( Resource $r, array $cluster, array $config ): ?array {
		if ( '' !== self::action( $r ) ) {
			return null;
		}

		$row = IpPool::for_resource( $r->id() );

		if ( null !== $row && 'assigned' === $row['state'] ) {
			$record = VmRecords::for_resource( $r->id() );
			// IPv6 only when the database has it (meta `ip6`): a repair never gives one that was not given.
			$want   = self::public_params( $cluster, $row, (string) ( $config['net0'] ?? '' ), null === $record || '' === (string) $r->get( 'ip6' ) ? 0 : (int) $record['vmid'] );

			return self::matches( $config, $want ) ? null : $want;
		}

		// A database built with its public card keeps it without an IPv4 address: net0 and the IPv6 part of ipconfig0.
		if ( null === $row && self::has_card( $r ) ) {
			$record = VmRecords::for_resource( $r->id() );
			$want   = self::public_card( $cluster, (string) ( $config['net0'] ?? '' ), null === $record ? 0 : (int) $record['vmid'] );

			return '' === $want['ipconfig0'] || self::matches( $config, $want ) ? null : $want;
		}

		if ( null !== $row || ( empty( $config['net0'] ) && empty( $config['ipconfig0'] ) ) ) {
			return null;
		}

		// A net0 on the private bridge is not ours to remove.
		$props = NetConfig::parse( (string) ( $config['net0'] ?? '' ) );

		if ( '' !== (string) ( $cluster['private_bridge'] ?? '' ) && ( $props['bridge'] ?? '' ) === (string) $cluster['private_bridge'] ) {
			return null;
		}

		return array( 'delete' => 'net0,ipconfig0' );
	}

	/**
	 * net0 and ipconfig0 of a public address: the template's NIC options and MAC stay, the bridge, the firewall flag and
	 * the VLAN tag are the cluster's, and the gateway is the pool row's or the cluster's.
	 *
	 * @param array<string, mixed> $cluster Cluster.
	 * @param array<string, mixed> $ip      Pool row.
	 * @param string               $net0    The VM's net0 now ('' for none).
	 * @param int                  $vmid    The VMID: with it, a cluster that has IPv6 on and Ipv6::databases() (off), ipconfig0 carries the IPv6 address too (0: IPv4 only).
	 * @return array<string, string>
	 */
	public static function public_params( array $cluster, array $ip, string $net0, int $vmid = 0 ): array {
		$card    = self::public_card( $cluster, $net0, $vmid );
		$gateway = null !== $ip['gateway'] && '' !== $ip['gateway'] ? $ip['gateway'] : (string) $cluster['gateway'];
		$ip6     = $card['ipconfig0'];

		return array(
			'ipconfig0' => 'ip=' . $ip['address'] . '/' . (int) $cluster['prefix'] . ',gw=' . $gateway . ( '' === $ip6 ? '' : ',' . $ip6 ),
			'net0'      => $card['net0'],
		);
	}

	/**
	 * The public card of a database (net0 on the public bridge, its firewall on) with the IPv6 part of ipconfig0 only: what every
	 * new database is built with while database IPv6 is on (Ipv6::databases()), like a VPS Basic. ipconfig0 is '' when the
	 * cluster has no IPv6 or no VMID is given.
	 *
	 * @param array<string, mixed> $cluster Cluster.
	 * @param string               $net0    The VM's net0 now ('' for none).
	 * @param int                  $vmid    The VMID.
	 * @return array{net0: string, ipconfig0: string}
	 */
	public static function public_card( array $cluster, string $net0, int $vmid = 0 ): array {
		$props = NetConfig::parse( $net0 );

		if ( array() === $props ) {
			$props['virtio'] = null;
		}

		$props['bridge']   = (string) $cluster['bridge'];
		$props['firewall'] = '1';

		if ( (int) $cluster['vlan'] > 0 ) {
			$props['tag'] = (string) (int) $cluster['vlan'];
		}

		return array(
			'ipconfig0' => $vmid > 0 && Ipv6::databases() ? Ipv6::ipconfig( $cluster, $vmid ) : '',
			'net0'      => NetConfig::format( $props ),
		);
	}

	/**
	 * Was the database built with its public card (an IPv6 address on net0)? Then an IPv4 address is added and taken away the way
	 * a server's main address is (Ipv4Server, one restart), not by adding or removing the card (the add and remove of run()).
	 *
	 * @param Resource $r Database.
	 */
	public static function has_card( Resource $r ): bool {
		return Ipv6::is_address( (string) $r->get( 'ip6' ) );
	}

	/**
	 * Is net0 of this config the public NIC these parameters describe?
	 *
	 * @param array<string, mixed>  $config Config.
	 * @param array<string, string> $want   public_params().
	 */
	private static function matches( array $config, array $want ): bool {
		$have   = NetConfig::parse( (string) ( $config['net0'] ?? '' ) );
		$expect = NetConfig::parse( $want['net0'] );

		$have_ip = (string) ( $config['ipconfig0'] ?? '' );

		// The IPv4 part must be exactly as wanted. The IPv6 part is compared only when one is wanted (a cluster that turned IPv6 off leaves a written address alone) and by value, not by spelling.
		$want_keys = Ipv6::keys( $want['ipconfig0'] );
		$ip6_ok    = '' === $want_keys['ip6'] || Ipv6::keys( $have_ip ) === $want_keys;

		return Ipv6::strip( $have_ip ) === Ipv6::strip( $want['ipconfig0'] ) && $ip6_ok
			&& ( $have['bridge'] ?? '' ) === ( $expect['bridge'] ?? '' ) && ( $have['tag'] ?? '' ) === ( $expect['tag'] ?? '' ) && '1' === ( $have['firewall'] ?? '' );
	}

	// -- The operation ---------------------------------------------------------------------------------

	/**
	 * Runs the operation from where it was.
	 */
	public function run(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'db_ip: no VM is recorded for this service' );
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
			$action = self::wanted( $this->r );

			if ( '' === $action ) {
				return StepResult::done( array(), 'No public IP address change was needed' );
			}

			$op = array( 'action' => $action, 'phase' => 'add' === $action ? 'hold' : ( 'sync' === $action ? 'listen' : 'clear' ) );

			if ( ! $this->r->set( array( self::OP => $op, DbAccess::STATE => '' ) ) ) {
				return StepResult::retry( 0, 'db_ip: progress could not be stored' );
			}
		}

		// A change that cannot go on after the VM was shut down: the VM is started again, then the change fails.
		if ( ! empty( $op['abort'] ) ) {
			$out = $this->phase_start( $record );

			if ( $out instanceof StepResult ) {
				return $out;
			}

			$this->r->set( array( self::OP => null ) );

			return StepResult::fail( (string) $op['abort'] );
		}

		$order = array(
			'add'    => array( 'hold', 'stop', 'nic', 'start', 'listen', 'access', 'firewall' ),
			'remove' => array( 'clear', 'stop', 'nic', 'start', 'listen', 'firewall' ),
			'sync'   => array( 'listen', 'resync', 'firewall' ),
		)[ (string) $op['action'] ];
		$at    = array_search( (string) $op['phase'], $order, true );
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
					return StepResult::retry( 0, 'db_ip: progress could not be stored' );
				}
			}

			$this->progressed = true;
		}

		$added = 'add' === $op['action'];
		$this->r->set( array( self::OP => null, self::RESYNC => null ) );

		if ( 'sync' === $op['action'] ) {
			return StepResult::done( array(), 'Database listen addresses and access list sent again after the restore' );
		}

		return StepResult::done( array( 'ip' => $added ? DbAccess::public_address( $this->r ) : null ), $added ? 'Public IP address added to the database' : 'Public IP address removed from the database' );
	}

	/**
	 * The stored operation.
	 *
	 * @return array<string, mixed>
	 */
	private function op(): array {
		$fresh = Resource::find( $this->r->id() ) ?? $this->r;
		$op    = $fresh->get( self::OP );

		return is_array( $op ) ? $op : array();
	}

	/**
	 * Phase hold (add): the address. The customer has paid for it, so the pool's reserve for servers is not applied here.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_hold( array $record ) {
		unset( $record );

		try {
			$ip = IpPool::hold( $this->r->id() );
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 0, 'db_ip: the IP pool is busy' );
		}

		if ( null === $ip ) {
			$this->r->set( array( self::OP => null ) );

			return StepResult::fail( 'No free public IP address for this database; the plan has the add-on but the VM was not changed' );
		}

		if ( null !== $ip['gateway'] && '' !== $ip['gateway'] && ! NetConfig::gateway_on_network( $ip['address'], $ip['gateway'], (int) $this->cluster['prefix'] ) ) {
			IpPool::release( $this->r->id() );
			$this->r->set( array( self::OP => null ) );

			return StepResult::fail( sprintf( 'db_ip: the gateway %1$s of address %2$s is outside %2$s/%3$d; the address was given back', $ip['gateway'], $ip['address'], (int) $this->cluster['prefix'] ) );
		}

		return null;
	}

	/**
	 * Phase clear (remove): the allow-list is emptied in the firewall and the guest first, so nothing stays open
	 * that has no address behind it. A guest that cannot be told is not a reason to keep the address: the VM
	 * loses the NIC anyway, and the admin is told.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_clear( array $record ) {
		unset( $record );

		return $this->apply_empty_list( true );
	}

	/**
	 * The listen job: the addresses the server listens on and has in its certificate (the private one first, then the
	 * public one when the database has it).
	 *
	 * @param Resource $r       Resource.
	 * @param string   $request Request id.
	 * @return array<string, mixed>
	 */
	public static function listen_job( Resource $r, string $request ): array {
		$listen = array( (string) $r->get( 'db_address' ) );
		$public = DbAccess::public_address( $r );

		if ( '' !== $public ) {
			$listen[] = $public;
		}

		$job = array( 'v' => 1, 'request' => $request, 'op' => 'listen', 'listen' => $listen );

		// The IPv6 address is a list of its own (`listen6`): a guest that does not know the key keeps listening on the IPv4 addresses only.
		// A database has it on its public card, with or without the IPv4 address.
		if ( Ipv6::databases() && Ipv6::is_address( (string) $r->get( 'ip6' ) ) ) {
			$job['listen6'] = array( (string) Ipv6::normalise( (string) $r->get( 'ip6' ) ) );
		}

		return $job;
	}

	/**
	 * Phase listen: the guest listens on, and has in its certificate, the addresses the VM has now. Sent once, then its
	 * status is waited for. An add that cannot be done stops here, before anything is opened; a remove that cannot be
	 * done is reported (the address is gone anyway) and the change goes on.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_listen( array $record ) {
		$v = $this->verified( $record, 'db_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$record['node'] = $v['node'];
		$op             = $this->op();

		if ( empty( $op['listen_request'] ) ) {
			$op['listen_request'] = wp_generate_uuid4();
			$op['listen_sent']    = false;
			$op['listen_at']      = time();

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'db_ip: progress could not be stored' );
			}
		}

		$request = (string) $op['listen_request'];
		$waited  = time() - (int) ( $op['listen_at'] ?? time() ) > self::LISTEN_BUDGET;

		if ( empty( $op['listen_sent'] ) ) {
			$sent = DbJob::send( $this->client, $record, self::listen_job( $this->r, $request ) );

			if ( is_wp_error( $sent ) ) {
				return $waited ? $this->listen_failed( $op, 'The guest agent did not accept the listen job' ) : StepResult::poll( 10, 'db_ip: waiting for the guest agent', self::LISTEN_BUDGET );
			}

			$op['listen_sent'] = true;
			$op['listen_at']   = time();

			return $this->r->set( array( self::OP => $op ) ) ? StepResult::poll( 5, 'db_ip: the listen addresses were sent', self::LISTEN_BUDGET ) : StepResult::retry( 0, 'db_ip: progress could not be stored' );
		}

		$status = DbGuest::status( $this->client, (string) $record['node'], (int) $record['vmid'], $request );

		if ( null !== $status && 'failed' === $status['state'] ) {
			// A guest from before the job existed refuses the job itself (step "job").
			return $this->listen_failed( $op, 'job' === $status['step']
				? 'The database server does not know the listen job: its template is older than the guest script that supports it. Rebuild the database template'
				: 'The database server did not apply the listen addresses (step ' . $status['step'] . ')' );
		}

		if ( null === $status || 'ready' !== $status['state'] ) {
			return $waited ? $this->listen_failed( $op, 'The database server did not apply the listen addresses within 10 minutes' ) : StepResult::poll( 10, 'db_ip: waiting for the database server', self::LISTEN_BUDGET );
		}

		return null;
	}

	/**
	 * The listen job did not work. An add fails (the job is forgotten so that a retry sends it again); a remove tells the
	 * admin and goes on.
	 *
	 * @param array<string, mixed> $op  Stored operation.
	 * @param string               $why Reason.
	 * @return StepResult|null
	 */
	private function listen_failed( array $op, string $why ) {
		if ( 'remove' === ( $op['action'] ?? '' ) ) {
			\BeltoftServices\Alerts::admin( $this->r, $why . '. The public address was taken away, but the server may still list it in listen_addresses and its certificate.' );

			return null;
		}

		// After a restore: the database came back and works, so a guest that cannot be told is reported, not failed.
		if ( 'sync' === ( $op['action'] ?? '' ) ) {
			\BeltoftServices\Alerts::admin( $this->r, $why . '. The restored database may still listen on the addresses of the backup.' );

			return null;
		}

		unset( $op['listen_request'], $op['listen_sent'], $op['listen_at'] );
		$this->r->set( array( self::OP => $op ) );

		return StepResult::fail( $why . '. The public address is attached to the VM, but nothing is open to it.' );
	}

	/**
	 * Phase access (add): an empty list goes to the guest and the firewall; nothing is allowed until the customer
	 * adds an address.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_access( array $record ) {
		unset( $record );

		return $this->apply_empty_list( false );
	}

	/**
	 * Phase resync (sync, after a restore): the stored allow-list, not an empty one, goes to the guest and the firewall. The
	 * revision is raised once per operation, so the layers are brought to the stored list even though they were applied before.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_resync( array $record ) {
		unset( $record );
		$op = $this->op();

		if ( empty( $op['resynced'] ) ) {
			$meta = array(
				DbAccess::REV   => (int) $this->r->get( DbAccess::REV ) + 1,
				DbAccess::STATE => 'applying',
				DbAccess::OP    => null,
				self::OP        => array_merge( $op, array( 'resynced' => true ) ),
			);

			if ( ! $this->r->set( $meta ) ) {
				return StepResult::retry( 0, 'db_ip: progress could not be stored' );
			}
		}

		$done = ( new DbAccess( $this->r ) )->run( true, true );

		return $done->is_done() ? null : $done;
	}

	/**
	 * Stores an empty allow-list (once per operation) and has DbAccess bring the layers to it.
	 *
	 * @param bool $tolerant Whether a guest that cannot be told is only reported.
	 * @return StepResult|null
	 */
	private function apply_empty_list( bool $tolerant ) {
		$op = $this->op();

		if ( empty( $op['cleared'] ) ) {
			$meta = array(
				DbAccess::LIST  => array(),
				DbAccess::WORLD => false,
				DbAccess::REV   => (int) $this->r->get( DbAccess::REV ) + 1,
				DbAccess::STATE => 'applying',
				DbAccess::OP    => null,
				self::OP        => array_merge( $op, array( 'cleared' => true ) ),
			);

			if ( ! $this->r->set( $meta ) ) {
				return StepResult::retry( 0, 'db_ip: progress could not be stored' );
			}
		}

		$done = ( new DbAccess( $this->r ) )->run( true, $tolerant );

		return $done->is_done() ? null : $done;
	}

	/**
	 * Phase stop: one graceful shutdown (never forced), only for a VM that runs; the state it had is remembered.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_stop( array $record ) {
		$v = $this->verified( $record, 'db_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$op = $this->op();

		if ( ! array_key_exists( 'was_running', $op ) ) {
			$op['was_running'] = 'running' === $v['status'];

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'db_ip: progress could not be stored' );
			}
		}

		return $this->power( $record, false );
	}

	/**
	 * Phase start: the VM runs again when it ran before.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_start( array $record ) {
		return empty( $this->op()['was_running'] ) ? null : $this->power( $record, true );
	}

	/**
	 * Takes the VM to the wanted power state with one task, then reads it until it shows it.
	 *
	 * @param array<string, mixed> $record  VM record.
	 * @param bool                 $running Wanted: running.
	 * @return StepResult|null
	 */
	private function power( array $record, bool $running ) {
		$key = $running ? 'start_sent' : 'stop_sent';
		$op  = $this->op();

		// A power task remembered from before this operation is not ours.
		if ( empty( $op[ $key ] ) ) {
			$this->mark_sent( null );
		}

		$v = $this->verified( $record, 'db_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( $running === ( 'running' === $v['status'] ) ) {
			Operations::remember_power( $this->r->id(), $running ? 'running' : 'stopped' );

			return null;
		}

		if ( ! empty( $op[ $key ] ) && $this->sent_recently( $running ? 'start' : 'stop' ) ) {
			$late = $this->reread_power( $record, 'db_ip', $running );

			if ( $late instanceof StepResult ) {
				return $late;
			}

			if ( $running !== ( 'running' === $late['status'] ) ) {
				return $running ? StepResult::poll( 5, 'db_ip: waiting for the server to start', 120 ) : $this->still_running( 'db_ip: waiting for the server to stop' );
			}

			Operations::remember_power( $this->r->id(), $running ? 'running' : 'stopped' );

			return null;
		}

		$base   = '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . (int) $record['vmid'];
		$params = $running ? array() : array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) );
		$done   = $this->run_task( 'db_ip', 'POST', $base . ( $running ? '/status/start' : '/status/shutdown' ), $params, $running ? 'start' : 'shutdown' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$this->r->set( array( self::OP => array_merge( $this->op(), array( $key => true ) ) ) );

		$again = $this->reread_power( $record, 'db_ip', $running );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		if ( $running !== ( 'running' === $again['status'] ) ) {
			return $running ? StepResult::poll( 5, 'db_ip: the start did not leave the server running', 120 ) : $this->still_running( 'db_ip: the server did not power off yet', $done );
		}

		Operations::remember_power( $this->r->id(), $running ? 'running' : 'stopped' );

		return null;
	}

	/**
	 * Phase nic: net0 and ipconfig0 are written (add) or deleted (remove) on the stopped VM and read back. The address
	 * is recorded as in use only after the NIC is seen, and given back only after its removal is seen. A definite refusal
	 * of the write gives the address back; an unclear answer keeps it, and the next run reads the config.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_nic( array $record ) {
		$v = $this->verified( $record, 'db_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' === $v['status'] ) {
			return StepResult::retry( 0, 'db_ip: the server must be off before its network card is changed' );
		}

		return 'add' === $this->op()['action'] ? $this->nic_add( $record, $v ) : $this->nic_remove( $record, $v );
	}

	/**
	 * Gives up the change: the VM is started again (when it ran) on the next run, then the change fails with this
	 * reason. A customer's database is never left switched off by a change that did not happen.
	 *
	 * @param string $why Reason (for the admin).
	 */
	private function abort( string $why ): StepResult {
		$op = array_merge( $this->op(), array( 'abort' => $why ) );

		return $this->r->set( array( self::OP => $op ) ) ? StepResult::retry( 0, $why, array(), true ) : StepResult::retry( 0, 'db_ip: progress could not be stored' );
	}

	/**
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return StepResult|null
	 */
	private function nic_add( array $record, array $v ) {
		$ip = IpPool::for_resource( $this->r->id() );

		if ( null === $ip ) {
			return $this->abort( 'db_ip: the address reservation is gone' );
		}

		$want = self::public_params( $this->cluster, $ip, (string) ( $v['config']['net0'] ?? '' ), (int) $record['vmid'] );

		if ( ! self::matches( (array) $v['config'], $want ) ) {
			if ( Settings::get( 'pause_all' ) ) {
				return StepResult::wait( Jobs::PAUSED_DELAY, 'db_ip: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
			}

			$answer = $this->client->request( 'PUT', self::base( $record, $v ) . '/config', $want + array( 'digest' => (string) ( $v['config']['digest'] ?? '' ) ) );
			$after  = $this->verified( $record, 'db_ip' );

			if ( $after instanceof StepResult ) {
				return $after;
			}

			if ( ! self::matches( (array) $after['config'], $want ) ) {
				if ( is_wp_error( $answer ) && in_array( $answer->get_error_code(), array( 'http_400', 'http_403', 'http_404', 'http_422' ), true ) && empty( $after['config']['net0'] ) ) {
					IpPool::release( $this->r->id() );

					return $this->abort( 'db_ip: Proxmox refused the network card; the address was given back (' . $this->text( $answer ) . ')' );
				}

				return is_wp_error( $answer ) ? $this->classify( $answer, 'db_ip' ) : StepResult::retry( 0, 'db_ip: the network card could not be confirmed' );
			}
		}

		$ip6 = Ipv6::databases() ? Ipv6::address( $this->cluster, (int) $record['vmid'] ) : null;

		if ( ! VmRecords::set_ip( $this->r->id(), $ip['id'] ) || ! IpPool::assign( $this->r->id() ) || ! $this->r->set( array( 'ip' => $ip['address'], 'ip6' => $ip6 ) ) ) {
			return StepResult::retry( 0, 'db_ip: the address could not be recorded as in use' );
		}

		if ( null !== $ip6 ) {
			IpHistory::start_once( Ipv6::KIND, $ip6, $this->r->id() );
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return StepResult|null
	 */
	private function nic_remove( array $record, array $v ) {
		if ( ! empty( $v['config']['net0'] ) || ! empty( $v['config']['ipconfig0'] ) ) {
			// A net0 on the private bridge is not ours to remove.
			$props = NetConfig::parse( (string) ( $v['config']['net0'] ?? '' ) );

			if ( '' !== (string) ( $this->cluster['private_bridge'] ?? '' ) && ( $props['bridge'] ?? '' ) === (string) $this->cluster['private_bridge'] ) {
				return $this->abort( 'db_ip: net0 is on the private bridge; nothing was removed' );
			}

			if ( Settings::get( 'pause_all' ) ) {
				return StepResult::wait( Jobs::PAUSED_DELAY, 'db_ip: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
			}

			$answer = $this->client->request( 'PUT', self::base( $record, $v ) . '/config', array( 'delete' => 'net0,ipconfig0', 'digest' => (string) ( $v['config']['digest'] ?? '' ) ) );
			$after  = $this->verified( $record, 'db_ip' );

			if ( $after instanceof StepResult ) {
				return $after;
			}

			if ( ! empty( $after['config']['net0'] ) || ! empty( $after['config']['ipconfig0'] ) ) {
				return is_wp_error( $answer ) ? $this->classify( $answer, 'db_ip' ) : StepResult::retry( 0, 'db_ip: the network card could not be confirmed removed' );
			}
		}

		// Only now, with the removal seen.
		VmRecords::clear_ip( $this->r->id() );

		if ( ! $this->r->set( array( 'ip' => null, 'ip6' => null ) ) ) {
			return StepResult::retry( 0, 'db_ip: progress could not be stored' );
		}

		IpHistory::end( Ipv6::KIND, $this->r->id() );
		IpPool::release( $this->r->id() );

		return null;
	}

	/**
	 * Phase firewall: the options (and the ipfilter sets, when the cluster asks for them) and the allow-list rules match
	 * the VM as it is now.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_firewall( array $record ) {
		$v = $this->verified( $record, 'db_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$firewall = new Firewall( $this->r, $this->client, $this->cluster );
		$done     = $firewall->ensure_database( $record, $v );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$sets = $firewall->ensure_ipfilter( $record, $v );

		return $sets instanceof StepResult ? $sets : null;
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
}

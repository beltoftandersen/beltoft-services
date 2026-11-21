<?php
/**
 * The public card of a managed database, and the guest sync after a restore or an address change.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Network\NetConfig;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every database is built with its public card (net0 with an IPv6 address, like a VPS Basic) and its private network (net1).
 * An IPv4 address (the IPv4 add-on, Ipv4\Ipv4Driver) is added to and taken from the card the way a server's main address is
 * (Ipv4\Ipv4Server, one restart). What is left here:
 *
 * - the card's parameters (public_card(), public_params()) for the build, a restore (nic_repair()) and Ipv4Server;
 * - the sync (`run()`): after a restore or an address change (meta `db_resync`) the guest is told again what it listens on
 *   (`listen`), which private range it serves (`private`), the stored allow-list goes to the guest and the firewall again, and
 *   the firewall's sets follow the VM.
 *
 * Each phase is idempotent and the stored operation (`db_ip_op`) remembers where it was.
 */
final class DbIp {

	use Steps;
	use Prepares;

	/** Meta: the sync in progress (phase, flags). */
	public const OP = 'db_ip_op';

	/** Meta: a restore or an address change happened; the guest layers and the allow-list are sent again. */
	public const RESYNC = 'db_resync';

	/** Meta: true when the database was built with its public card by a template whose guest knows the `private` job. */
	public const CARD = 'db_card';

	/** Seconds the guest has for a job. */
	private const GUEST_BUDGET = 600;

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
	 * The action in progress: 'sync' or ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function action( Resource $r ): string {
		return is_array( $r->get( self::OP ) ) ? 'sync' : '';
	}

	/**
	 * Is no sync and no private network move in progress?
	 *
	 * @param Resource $r Resource.
	 */
	public static function idle( Resource $r ): bool {
		return '' === self::action( $r ) && ! is_array( $r->get( DbPrivateMove::OP ) );
	}

	/**
	 * What the guest needs: 'sync' after a restore or an address change, '' otherwise. A sync that has begun is finished.
	 *
	 * @param Resource $r Resource.
	 */
	public static function wanted( Resource $r ): string {
		return '' !== self::action( $r ) || true === $r->get( self::RESYNC ) ? 'sync' : '';
	}

	/**
	 * What a restored VM's net0 and ipconfig0 must be changed to so that they match the address pool: the card with the IPv4
	 * address when the pool holds one for this database, the card with IPv6 alone when it does not (the backup may carry an
	 * address that was given back and handed to someone else). Null: nothing to change.
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $cluster Cluster.
	 * @param array<string, mixed> $config  The VM's config now.
	 * @return array<string, string>|null PUT parameters (without digest), or null.
	 */
	public static function nic_repair( Resource $r, array $cluster, array $config ): ?array {
		$record = VmRecords::for_resource( $r->id() );
		// IPv6 only when the database has it (meta `ip6`): a repair never gives one that was not given.
		$vmid = null === $record || ! self::has_card( $r ) ? 0 : (int) $record['vmid'];
		$row  = IpPool::for_resource( $r->id() );

		if ( null !== $row && 'assigned' === $row['state'] ) {
			$want = self::public_params( $cluster, $row, (string) ( $config['net0'] ?? '' ), $vmid );

			return self::matches( $config, $want ) ? null : $want;
		}

		// The card keeps its IPv6 address without an IPv4 one.
		if ( null === $row && $vmid > 0 ) {
			$want = self::public_card( $cluster, (string) ( $config['net0'] ?? '' ), $vmid );

			return '' === $want['ipconfig0'] || self::matches( $config, $want ) ? null : $want;
		}

		if ( null !== $row || ( empty( $config['net0'] ) && empty( $config['ipconfig0'] ) ) ) {
			return null;
		}

		// No card and no address in the pool (a cluster without IPv6): a public NIC from the backup goes. One on the private bridge
		// is not ours to remove.
		$props = NetConfig::parse( (string) ( $config['net0'] ?? '' ) );

		if ( '' !== (string) ( $cluster['private_bridge'] ?? '' ) && ( $props['bridge'] ?? '' ) === (string) $cluster['private_bridge'] ) {
			return null;
		}

		return array( 'delete' => 'net0,ipconfig0' );
	}

	/**
	 * net0 and ipconfig0 of the card with a public IPv4 address: the template's NIC options and MAC stay, the bridge, the
	 * firewall flag and the VLAN tag are the cluster's, and the gateway is the pool row's or the cluster's.
	 *
	 * @param array<string, mixed> $cluster Cluster.
	 * @param array<string, mixed> $ip      Pool row.
	 * @param string               $net0    The VM's net0 now ('' for none).
	 * @param int                  $vmid    The VMID: with it, on a cluster with IPv6, ipconfig0 carries the IPv6 address too (0: IPv4 only).
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
	 * database is built with, like a VPS Basic. ipconfig0 is '' when the cluster has no IPv6 or no VMID is given.
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
			'ipconfig0' => $vmid > 0 ? Ipv6::ipconfig( $cluster, $vmid ) : '',
			'net0'      => NetConfig::format( $props ),
		);
	}

	/**
	 * Does the database have its public card (an IPv6 address on net0)?
	 *
	 * @param Resource $r Database.
	 */
	public static function has_card( Resource $r ): bool {
		return Ipv6::is_address( (string) $r->get( 'ip6' ) );
	}

	/**
	 * Was the database built with its card by a template whose guest knows the `private` job (meta `db_card`, written at build)?
	 *
	 * @param Resource $r Database.
	 */
	public static function knows_private( Resource $r ): bool {
		return true === $r->get( self::CARD ) && self::has_card( $r );
	}

	/**
	 * Is net0 of this config the public NIC these parameters describe?
	 *
	 * @param array<string, mixed>  $config Config.
	 * @param array<string, string> $want   public_params() or public_card().
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

	// -- The sync --------------------------------------------------------------------------------------

	/**
	 * Runs the sync from where it was.
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
			if ( '' === self::wanted( $this->r ) ) {
				return StepResult::done( array(), 'No database sync was needed' );
			}

			$op = array( 'phase' => 'listen' );

			if ( ! $this->r->set( array( self::OP => $op, DbAccess::STATE => '' ) ) ) {
				return StepResult::retry( 0, 'db_ip: progress could not be stored' );
			}
		}

		$order = array( 'listen', 'private', 'resync', 'firewall' );
		$at    = array_search( (string) ( $op['phase'] ?? '' ), $order, true );

		for ( $i = false === $at ? 0 : $at; $i < count( $order ); $i++ ) {
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

		$this->r->set( array( self::OP => null, self::RESYNC => null ) );

		return StepResult::done( array(), 'Database listen addresses, private range and access list sent again' );
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
	 * The listen job: the addresses the server listens on and has in its certificate (the private one first, then the
	 * public IPv4 one when the database has it; the IPv6 address of its card as `listen6`).
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
		if ( self::has_card( $r ) ) {
			$job['listen6'] = array( (string) Ipv6::normalise( (string) $r->get( 'ip6' ) ) );
		}

		return $job;
	}

	/**
	 * The private job: the private address and range the guest serves (as after a move to another private network).
	 *
	 * @param Resource $r       Resource.
	 * @param string   $request Request id.
	 * @return array<string, mixed>
	 */
	public static function private_job( Resource $r, string $request ): array {
		return array( 'v' => 1, 'request' => $request, 'op' => 'private', 'address' => (string) $r->get( 'db_address' ), 'private_cidr' => (string) $r->get( 'db_cidr' ) );
	}

	/**
	 * Phase listen: the guest listens on, and has in its certificate, the addresses the VM has now.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_listen( array $record ) {
		return $this->guest_job( $record, 'listen', fn( string $request ): array => self::listen_job( $this->r, $request ) );
	}

	/**
	 * Phase private: the guest serves the private range of the network the database is on (a backup from before a move to another
	 * private network carries the old one). Only for a guest that knows the job.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_private( array $record ) {
		if ( ! self::knows_private( $this->r ) || '' === (string) $this->r->get( 'db_cidr' ) ) {
			return null;
		}

		return $this->guest_job( $record, 'private', fn( string $request ): array => self::private_job( $this->r, $request ) );
	}

	/**
	 * One guest job, sent once, then its status is waited for. The database works either way, so a guest that cannot be told is
	 * reported to the admin and the sync goes on.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param string               $key    Job name (also the prefix of its flags in the operation).
	 * @param callable             $build  Request id => job.
	 * @return StepResult|null
	 */
	private function guest_job( array $record, string $key, callable $build ) {
		$v = $this->verified( $record, 'db_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$record['node'] = $v['node'];
		$op             = $this->op();

		if ( empty( $op[ $key . '_request' ] ) ) {
			$op[ $key . '_request' ] = wp_generate_uuid4();
			$op[ $key . '_sent' ]    = false;
			$op[ $key . '_at' ]      = time();

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'db_ip: progress could not be stored' );
			}
		}

		$request = (string) $op[ $key . '_request' ];
		$waited  = time() - (int) ( $op[ $key . '_at' ] ?? time() ) > self::GUEST_BUDGET;

		if ( empty( $op[ $key . '_sent' ] ) ) {
			$sent = DbJob::send( $this->client, $record, $build( $request ) );

			if ( is_wp_error( $sent ) ) {
				return $waited ? $this->guest_failed( 'The guest agent did not accept the ' . $key . ' job' ) : StepResult::poll( 10, 'db_ip: waiting for the guest agent', self::GUEST_BUDGET );
			}

			$op[ $key . '_sent' ] = true;
			$op[ $key . '_at' ]   = time();

			return $this->r->set( array( self::OP => $op ) ) ? StepResult::poll( 5, 'db_ip: the ' . $key . ' job was sent', self::GUEST_BUDGET ) : StepResult::retry( 0, 'db_ip: progress could not be stored' );
		}

		$status = DbGuest::status( $this->client, (string) $record['node'], (int) $record['vmid'], $request );

		if ( null !== $status && 'failed' === $status['state'] ) {
			// A guest from before the job existed refuses the job itself (step "job").
			return $this->guest_failed( 'job' === $status['step']
				? 'The database server does not know the ' . $key . ' job: its template is older than the guest script that supports it'
				: 'The database server did not apply the ' . $key . ' job (step ' . $status['step'] . ')' );
		}

		if ( null === $status || 'ready' !== $status['state'] ) {
			return $waited ? $this->guest_failed( 'The database server did not apply the ' . $key . ' job within 10 minutes' ) : StepResult::poll( 10, 'db_ip: waiting for the database server', self::GUEST_BUDGET );
		}

		return null;
	}

	/**
	 * A guest job did not work: the admin is told, the sync goes on.
	 *
	 * @param string $why Reason.
	 * @return null
	 */
	private function guest_failed( string $why ) {
		\BeltoftServices\Alerts::admin( $this->r, $why . '. The database may still listen on, or serve, the addresses it had before.' );

		return null;
	}

	/**
	 * Phase resync: the stored allow-list, not an empty one, goes to the guest and the firewall. The
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

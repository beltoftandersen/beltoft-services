<?php
/**
 * The private network of a customer server (plan B): attach and detach while the server exists.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Proxmox only gets `net1` (never `ipconfig1`: writing cloud-init network config on a booted server
 * regenerates its SSH host keys, plan T4). The address is written into the guest through the guest
 * agent as one fixed file, then the server is restarted once. Everything is re-read from the remote
 * state on each run and recorded in the resource meta, so a retry never repeats a step.
 *
 * Steps of an attach: probe the agent (no change before it answers), reserve and write net1 (PrivateNic),
 * write the guest file, graceful shutdown, start, wait for the agent. Steps of a detach: write the
 * empty-effect file (best effort), graceful shutdown, delete net1 and verify, release the address, start.
 *
 * Endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#/nodes/{node}/qemu/{vmid}):
 * GET agent/get-osinfo, POST agent/file-write (`file`, `content`), PUT config (`net1`, `delete`, `digest`),
 * POST status/shutdown (`timeout`), POST status/start, POST agent/ping.
 */
final class PrivateAttach {

	use Steps;
	use Prepares;

	public const ATTACH = 'private_attach';
	public const DETACH = 'private_detach';
	public const ACTIONS = array( self::ATTACH, self::DETACH );

	public const STATE  = 'private_state';
	public const OP     = 'private_op';
	public const MAC    = 'private_mac';
	public const FAMILY = 'private_family';
	public const NOTICE = 'private_notice';

	public const NETPLAN_FILE = '/etc/netplan/60-beltoft-private.yaml';
	public const NM_FILE      = '/etc/NetworkManager/system-connections/beltoft-private.nmconnection';

	/** Seconds the guest is left alone after its agent first answered on a rebuilt server. */
	private const SETTLE = 60;

	/** Seconds a rebuilt server may take to answer the agent before the private network is given up. */
	private const REBUILD_WAIT = 900;

	/**
	 * Cluster settings, set by prepare().
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Uses a client that is already built (a build in progress).
	 *
	 * @param Client               $client  Client.
	 * @param array<string, mixed> $cluster Cluster settings.
	 */
	public function using( Client $client, array $cluster ): self {
		$this->client  = $client;
		$this->cluster = $cluster;
		$this->secrets = array( (string) ( $cluster['token_secret'] ?? '' ) );

		return $this;
	}

	// -- What is offered ---------------------------------------------------------------------------

	/**
	 * Is the private network a thing for this server: a VPS of ours (not adopted) on a cluster with a private
	 * bridge, for a customer who already has a network or can be given one.
	 *
	 * @param Resource $r Resource.
	 */
	public static function eligible( Resource $r ): bool {
		if ( SshKeyField::DRIVER !== $r->get( 'driver' ) || true === $r->get( 'linked' ) || ! Profiles::for_resource( $r ) instanceof VpsProfile ) {
			return false;
		}

		$record  = VmRecords::for_resource( $r->id() );
		$cluster = null === $record ? null : Clusters::get( (string) $record['cluster'] );
		$bridge  = is_array( $cluster ) ? (string) ( $cluster['private_bridge'] ?? '' ) : '';

		if ( '' === $bridge || $bridge === (string) ( $cluster['bridge'] ?? '' ) ) {
			return false;
		}

		if ( null !== PrivateNets::for_resource( $r->id() ) ) {
			return true;
		}

		$user = (int) ( $r->license()['user_id'] ?? 0 );

		return $user > 0 && PrivateNets::capacity_for_user( $user );
	}

	/**
	 * The actions offered now.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		if ( ! self::eligible( $r ) ) {
			return array();
		}

		return array(
			self::ATTACH => __( 'Attach the private network', 'beltoft-services' ),
			self::DETACH => __( 'Detach the private network', 'beltoft-services' ),
		);
	}

	/**
	 * Why an action cannot be accepted, in words for the customer, or null.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function check( Resource $r, string $action, array $args ): ?string {
		unset( $args );

		if ( ! self::eligible( $r ) ) {
			return __( 'The private network is not available for this server.', 'beltoft-services' );
		}

		if ( self::ATTACH === $action && ! in_array( (string) $r->get( self::STATE ), array( 'attaching', 'attached' ), true ) ) {
			if ( ! Actions::agent_ok( $r ) ) {
				return self::notice_text( 'agent' );
			}

			$status = Operations::cached_status( $r->id() );

			if ( null !== $status && 'stopped' === (string) $status['power'] ) {
				return self::notice_text( 'stopped' );
			}
		}

		return null;
	}

	/**
	 * Fixed words for a notice code, for the customer.
	 *
	 * @param string $code Code.
	 */
	public static function notice_text( string $code ): string {
		$texts = array(
			'agent'       => __( 'The private network needs the guest agent (qemu-guest-agent) running in your server. Start it, then try again.', 'beltoft-services' ),
			'stopped'     => __( 'Start your server first. The private network is set up while it runs.', 'beltoft-services' ),
			'occupied'    => __( 'Your server already has a second network interface that was not added here, so the private network cannot be added.', 'beltoft-services' ),
			'unavailable' => __( 'No private network is available right now. Please contact support.', 'beltoft-services' ),
			'manual'      => __( 'The system on your server is not set up automatically. The network card is attached; add the address shown below to it inside the server.', 'beltoft-services' ),
			'failed'      => __( 'The private network could not be set up again after the reinstall. Make sure the guest agent runs, then press Attach.', 'beltoft-services' ),
		);

		return $texts[ $code ] ?? '';
	}

	// -- Guest files (fixed templates; only a MAC and an address go in) --------------------------------

	/**
	 * The guest's network family from the agent's OS id: netplan, nm (NetworkManager keyfile) or none.
	 *
	 * @param string $id The `id` of get-osinfo (os-release ID).
	 */
	public static function family( string $id ): string {
		$id     = strtolower( trim( $id ) );
		$family = 'none';

		if ( in_array( $id, array( 'ubuntu', 'debian' ), true ) ) {
			$family = 'netplan';
		} elseif ( in_array( $id, array( 'almalinux', 'rhel', 'rocky' ), true ) ) {
			$family = 'nm';
		}

		/**
		 * Lets the operator change the network family of a guest OS id (netplan, nm or none).
		 *
		 * @param string $family Family.
		 * @param string $id     OS id.
		 */
		$family = (string) apply_filters( 'beltoft_services_private_family', $family, $id );

		return in_array( $family, array( 'netplan', 'nm' ), true ) ? $family : 'none';
	}

	/**
	 * Is this a MAC address.
	 *
	 * @param string $mac Value.
	 */
	public static function valid_mac( string $mac ): bool {
		return 1 === preg_match( '/^[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}$/D', $mac );
	}

	/**
	 * The MAC of a VirtIO net property string (`virtio=MAC,bridge=...`), or ''.
	 *
	 * @param string $net Property string.
	 */
	public static function mac_of( string $net ): string {
		$mac = (string) ( NetConfig::parse( $net )['virtio'] ?? '' );

		return self::valid_mac( $mac ) ? strtoupper( $mac ) : '';
	}

	/**
	 * The file of an attach: path and content, or null when the family has none, or a value is not valid.
	 *
	 * @param string $family  netplan, nm or none.
	 * @param string $mac     MAC of the private NIC.
	 * @param string $address Address of the guest.
	 * @param string $cidr    The /28.
	 * @return array{file:string,content:string}|null
	 */
	public static function attach_file( string $family, string $mac, string $address, string $cidr ): ?array {
		if ( ! self::valid_mac( $mac ) || ! PrivateNets::contains_host( $cidr, $address ) ) {
			return null;
		}

		if ( 'netplan' === $family ) {
			return array(
				'file'    => self::NETPLAN_FILE,
				'content' => "# Private network. Managed from your account; removing it there replaces this file.\nnetwork:\n  version: 2\n  ethernets:\n    beltoft-private:\n      match:\n        macaddress: \"" . strtolower( $mac ) . "\"\n      dhcp4: false\n      dhcp6: false\n      accept-ra: false\n      optional: true\n      addresses:\n        - " . $address . "/28\n",
			);
		}

		if ( 'nm' === $family ) {
			return array(
				'file'    => self::NM_FILE,
				'content' => "[connection]\nid=beltoft-private\ntype=ethernet\nautoconnect=true\n\n[ethernet]\nmac-address=" . strtoupper( $mac ) . "\n\n[ipv4]\nmethod=manual\naddress1=" . $address . "/28\nnever-default=true\n\n[ipv6]\nmethod=ignore\n",
			);
		}

		return null;
	}

	/**
	 * The file of a detach, in place of ours: netplan gets an empty configuration, NetworkManager a profile
	 * that does not connect and holds no address (the file stays, a keyfile cannot be removed through the agent).
	 *
	 * @param string $family netplan, nm or none.
	 * @param string $mac    MAC ('' when unknown: then only netplan, which needs none, is written).
	 * @return array{file:string,content:string}|null
	 */
	public static function detach_file( string $family, string $mac ): ?array {
		if ( 'netplan' === $family ) {
			return array(
				'file'    => self::NETPLAN_FILE,
				'content' => "# Private network removed.\nnetwork:\n  version: 2\n",
			);
		}

		if ( 'nm' === $family && self::valid_mac( $mac ) ) {
			return array(
				'file'    => self::NM_FILE,
				'content' => "[connection]\nid=beltoft-private\ntype=ethernet\nautoconnect=false\n\n[ethernet]\nmac-address=" . strtoupper( $mac ) . "\n\n[ipv4]\nmethod=disabled\n\n[ipv6]\nmethod=ignore\n",
			);
		}

		return null;
	}

	// -- The tab's view --------------------------------------------------------------------------------

	/**
	 * What the Network tab shows.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function view( Resource $r ): array {
		$state = (string) $r->get( self::STATE );
		$row   = PrivateNets::for_resource( $r->id() );
		$state = in_array( $state, array( 'attaching', 'attached', 'detaching', 'failed' ), true ) ? $state : '';
		$others = array();

		if ( null !== $row ) {
			foreach ( PrivateNets::members( $row['net_id'] ) as $id => $address ) {
				$other = Resource::find( (int) $id );
				if ( $id === $r->id() || ! $other || 'deleted' === $other->get( 'state' ) ) {
					continue;
				}
				$license = $other->license();
				$label   = ServerName::display( $other, is_array( $license ) ? $license : array() );
				$others[] = array( 'address' => $address, 'name' => $label );
			}
		}

		$notice = (string) $r->get( self::NOTICE );
		if ( 'manual' === $notice && 'attached' !== $state ) {
			$notice = '';
		}

		return array(
			'resource_id' => $r->id(),
			'state'       => $state,
			'address'     => 'attached' === $state || 'attaching' === $state || 'failed' === $state || 'detaching' === $state ? (string) ( $row['address'] ?? '' ) : '',
			'cidr'        => null === $row ? '' : (string) $row['cidr'],
			'others'      => $others,
			'notice'      => self::notice_text( $notice ),
			'mac'         => (string) $r->get( self::MAC ),
			'can_attach'  => 'attached' !== $state && 'detaching' !== $state && 'attaching' !== $state,
			'can_detach'  => in_array( $state, array( 'attached', 'failed' ), true ),
		);
	}

	// -- Run -------------------------------------------------------------------------------------------

	/**
	 * The job of private_attach and private_detach (from Actions::run() under the resource lock).
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function run( string $action, array $args ): StepResult {
		unset( $args );

		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'VM record missing; check Proxmox manually' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		return self::ATTACH === $action ? $this->attach( $record ) : $this->detach( $record );
	}

	/**
	 * Attach.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function attach( array $record ): StepResult {
		$state = (string) $this->r->get( self::STATE );
		$op    = $this->op();

		if ( 'attached' === $state && array() === $op ) {
			return StepResult::done( array(), 'Private network: already attached' );
		}

		if ( 'detaching' === $state ) {
			return StepResult::done( array(), 'Private network attach refused: a detach is still running' );
		}

		if ( 'attaching' !== $state || 'attach' !== ( $op['action'] ?? '' ) || 'nic' === ( $op['phase'] ?? 'nic' ) ) {
			$v = $this->verified( $record, 'private' );

			if ( $v instanceof StepResult ) {
				return $v;
			}

			if ( 'running' !== $v['status'] ) {
				$this->r->set( array( self::NOTICE => 'stopped' ) );

				return StepResult::done( array(), 'Private network attach refused: the server is not running' );
			}

			// Nothing is reserved or changed until the agent answers.
			$info = $this->osinfo( $record, $v );

			if ( null === $info ) {
				$this->r->set( array( self::NOTICE => 'agent' ) );

				return StepResult::done( array(), 'Private network attach refused: the guest agent did not answer' );
			}

			$nic = PrivateNic::attach( $this->r, $this->client, false );

			if ( is_wp_error( $nic ) ) {
				return $this->nic_failed( $nic );
			}

			$again = $this->verified( $record, 'private' );

			if ( $again instanceof StepResult ) {
				return $again;
			}

			$mac = self::mac_of( (string) ( $again['config']['net1'] ?? '' ) );

			if ( '' === $mac ) {
				return StepResult::retry( 0, 'private: the network card has no MAC address yet' );
			}

			// A server with the dashboard firewall drops everything inbound it does not allow: open the private side.
			$open = ( new Firewall( $this->r, $this->client, $this->cluster ) )->ensure_private( $record, $again );

			if ( $open instanceof StepResult ) {
				return $open;
			}

			$op = array( 'action' => 'attach', 'phase' => 'file', 'rebuild' => false, 'family' => self::family( (string) ( $info['id'] ?? '' ) ) );

			if ( ! $this->r->set( array( self::STATE => 'attaching', self::OP => $op, self::MAC => $mac, self::FAMILY => $op['family'], self::NOTICE => null ) ) ) {
				return StepResult::retry( 0, 'private: the state could not be stored' );
			}

			$this->progressed = true;
		}

		return $this->signal( $this->drive( $record, $this->op(), array( 'file', 'stop', 'start', 'agent' ) ) );
	}

	/**
	 * Detach.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function detach( array $record ): StepResult {
		$state = (string) $this->r->get( self::STATE );
		$op    = $this->op();

		if ( 'detaching' !== $state || 'detach' !== ( $op['action'] ?? '' ) ) {
			$v = $this->verified( $record, 'private' );

			if ( $v instanceof StepResult ) {
				return $v;
			}

			if ( '' === $state && empty( $v['config']['net1'] ) && null === PrivateNets::for_resource( $this->r->id() ) ) {
				return StepResult::done( array(), 'Private network: already detached' );
			}

			if ( 'attaching' === $state && 'attach' === ( $op['action'] ?? '' ) && 'file' !== ( $op['phase'] ?? '' ) ) {
				// An attach is mid-restart: it is finished first (the restart is not interrupted).
				return StepResult::done( array(), 'Private network detach refused: an attach is still running' );
			}

			if ( $this->foreign_nic( $v ) ) {
				$this->r->set( array( self::NOTICE => 'occupied' ) );
				Events::add( (int) ( $this->r->license()['id'] ?? 0 ), 'service', 'Private network detach refused: the second network interface is not the private one' );

				return StepResult::done( array(), 'Private network detach refused: the second network interface is not ours' );
			}

			$op = array(
				'action'      => 'detach',
				'phase'       => 'file',
				'was_running' => 'running' === $v['status'],
				'family'      => (string) $this->r->get( self::FAMILY ),
			);

			if ( ! $this->r->set( array( self::STATE => 'detaching', self::OP => $op, self::NOTICE => null ) ) ) {
				return StepResult::retry( 0, 'private: the state could not be stored' );
			}
		}

		return $this->signal( $this->drive( $record, $this->op(), array( 'file', 'stop', 'nic', 'start', 'finish' ) ) );
	}

	/**
	 * Does the VM have a second NIC (or ipconfig1) that is not the one reserved for this server?
	 *
	 * @param array<string, mixed> $v Verified VM.
	 */
	private function foreign_nic( array $v ): bool {
		if ( empty( $v['config']['net1'] ) && empty( $v['config']['ipconfig1'] ) ) {
			return false;
		}

		$row = PrivateNets::for_resource( $this->r->id() );

		if ( null === $row ) {
			return true;
		}

		$wanted = PrivateNic::nic_params( $row, $row['address'], (string) ( $this->cluster['private_bridge'] ?? '' ) );

		return is_wp_error( $wanted ) || ! PrivateNic::matches( $v['config'], $wanted );
	}

	/**
	 * Fixed customer notice and answer for a refused NIC write.
	 *
	 * @param \WP_Error $e Error from PrivateNic::attach().
	 */
	private function nic_failed( \WP_Error $e ): StepResult {
		$code = $e->get_error_code();

		if ( in_array( $code, array( 'network_busy', 'private_nic_unconfirmed', 'private_nic_pending', 'vm_locked' ), true ) ) {
			return StepResult::retry( 0, 'private: ' . $this->text( $e ) );
		}

		$this->r->set( array( self::NOTICE => 'private_nic_occupied' === $code ? 'occupied' : 'unavailable' ) );

		return StepResult::done( array(), 'Private network attach refused: ' . $this->scrub( $e->get_error_message(), 120 ) );
	}

	// -- Rebuild (a reinstall keeps the private network) -------------------------------------------------

	/**
	 * Does a rebuild of this server restore a private network?
	 *
	 * @param Resource $r Resource.
	 */
	public static function rebuild_wanted( Resource $r ): bool {
		return in_array( (string) $r->get( self::STATE ), array( 'attaching', 'attached', 'failed' ), true ) && null !== PrivateNets::for_resource( $r->id() );
	}

	/**
	 * Build step `private`, after configure and before the first start: net1 goes back into the
	 * configuration (the VM was cloned again, or its boot disk replaced), and the guest file is queued.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return array<string, mixed>|StepResult
	 */
	public function rebuild_nic( array $record ) {
		$nic = PrivateNic::attach( $this->r, $this->client, false );

		if ( is_wp_error( $nic ) ) {
			// Never fails the rebuild: the address stays reserved, the customer is told to attach again.
			if ( in_array( $nic->get_error_code(), array( 'network_busy', 'private_nic_unconfirmed', 'private_nic_pending', 'vm_locked' ), true ) ) {
				return StepResult::retry( 0, 'private: ' . $this->text( $nic ) );
			}

			Events::add( (int) ( $this->r->license()['id'] ?? 0 ), 'service', 'Private network not restored after the rebuild: ' . $this->scrub( $nic->get_error_message(), 120 ) );
			Alerts::admin( $this->r, 'The private network could not be put back after a rebuild: ' . $this->scrub( $nic->get_error_message(), 120 ) );

			return $this->r->set( array( self::STATE => 'failed', self::OP => null, self::NOTICE => 'failed' ) ) ? array() : StepResult::retry( 0, 'private: the state could not be stored' );
		}

		$v = $this->verified( $record, 'private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$mac = self::mac_of( (string) ( $v['config']['net1'] ?? '' ) );

		if ( '' === $mac ) {
			return StepResult::retry( 0, 'private: the network card has no MAC address yet' );
		}

		$open = ( new Firewall( $this->r, $this->client, $this->cluster ) )->ensure_private( $record, $v );

		if ( $open instanceof StepResult ) {
			return $open;
		}

		// Stored here, not returned: the build keeps what its steps return until it ends.
		$stored = $this->r->set(
			array(
				self::STATE  => 'attaching',
				self::MAC    => $mac,
				self::NOTICE => null,
				self::OP     => array( 'action' => 'attach', 'phase' => 'file', 'rebuild' => true, 'deadline' => Jobs::now() + self::REBUILD_WAIT ),
			)
		);

		return $stored ? array() : StepResult::retry( 0, 'private: the state could not be stored' );
	}

	/**
	 * Build step `private_guest`, after the start: the guest file, one restart, the agent again. A server
	 * whose agent never answers is not failed: the private network is marked for the customer to attach again.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return array<string, mixed>|StepResult
	 */
	public function rebuild_guest( array $record ) {
		if ( 'attaching' !== $this->r->get( self::STATE ) || ! is_array( $this->r->get( self::OP ) ) ) {
			return array();
		}

		$out = $this->drive( $record, $this->op(), array( 'file', 'stop', 'start', 'agent' ) );

		return $out->is_done() ? array() : $out;
	}

	// -- The phases ------------------------------------------------------------------------------------

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
	 * Runs the phases from the stored one on. Each phase returns null when it is complete.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @param string[]             $order  Phases in order.
	 */
	private function drive( array $record, array $op, array $order ): StepResult {
		$action = (string) ( $op['action'] ?? '' );
		$at     = array_search( (string) ( $op['phase'] ?? $order[0] ), $order, true );
		$at = false === $at ? 0 : $at;

		for ( $i = $at; $i < count( $order ); $i++ ) {
			$out = $this->{'phase_' . $order[ $i ]}( $record, $op );

			if ( $out instanceof StepResult ) {
				return $out;
			}

			$op = $this->op();

			if ( isset( $order[ $i + 1 ] ) ) {
				$op['phase'] = $order[ $i + 1 ];

				if ( ! $this->r->set( array( self::OP => $op ) ) ) {
					return StepResult::retry( 0, 'private: progress could not be stored' );
				}
			}

			$this->progressed = true;
		}

		return StepResult::done( array(), 'attach' === $action ? 'Private network attached' : 'Private network detached' );
	}

	/**
	 * The agent's OS answer, or null when it does not answer.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 * @return array<string, mixed>|null
	 */
	private function osinfo( array $record, array $v ): ?array {
		$reply = $this->client->request( 'GET', self::base( $record, $v ) . '/agent/get-osinfo' );

		if ( is_wp_error( $reply ) || ! is_array( $reply ) || ! is_array( $reply['result'] ?? null ) || ! is_string( $reply['result']['id'] ?? null ) ) {
			return null;
		}

		return $reply['result'];
	}

	/**
	 * The VM's path.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	private static function base( array $record, array $v ): string {
		return '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . $record['vmid'];
	}

	/**
	 * Phase file (attach): the guest file. A rebuilt server first has to answer the agent (and settle).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return StepResult|null
	 */
	private function phase_file( array $record, array $op ) {
		if ( 'detach' === ( $op['action'] ?? '' ) ) {
			return $this->detach_file_phase( $record, $op );
		}

		$v = $this->verified( $record, 'private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			return StepResult::retry( 0, 'private: the server is not running' );
		}

		$info = $this->osinfo( $record, $v );

		if ( null === $info ) {
			if ( ! empty( $op['rebuild'] ) && Jobs::now() > (int) ( $op['deadline'] ?? 0 ) ) {
				$this->r->set( array( self::STATE => 'failed', self::OP => null, self::NOTICE => 'failed' ) );
				Events::add( (int) ( $this->r->license()['id'] ?? 0 ), 'service', 'Private network not restored after the rebuild: the guest agent did not answer' );
				Alerts::admin( $this->r, 'The private network could not be put back after a rebuild: the guest agent did not answer' );

				return StepResult::done( array(), 'Private network not restored: the guest agent did not answer' );
			}

			return StepResult::poll( 10, 'private: waiting for the guest agent', 900 );
		}

		if ( ! empty( $op['rebuild'] ) ) {
			// Let the first boot finish before the one restart.
			$settle = max( 0, (int) apply_filters( 'beltoft_services_private_settle', self::SETTLE ) );
			$up     = (int) ( $op['up_at'] ?? 0 );

			if ( 0 === $up ) {
				$up         = Jobs::now();
				$op['up_at'] = $up;
				$this->r->set( array( self::OP => $op ) );
			}

			if ( Jobs::now() < $up + $settle ) {
				return StepResult::poll( 10, 'private: letting the first boot finish', 900 );
			}
		}

		$family = self::family( (string) $info['id'] );
		$row    = PrivateNets::for_resource( $this->r->id() );
		$mac    = (string) $this->r->get( self::MAC );
		$file   = null === $row ? null : self::attach_file( $family, $mac, $row['address'], $row['cidr'] );

		if ( null === $row ) {
			return StepResult::fail( 'private: the address reservation is gone' );
		}

		if ( null !== $file ) {
			$wrote = $this->write( $record, $v, $file );

			if ( null !== $wrote ) {
				return $wrote;
			}
		}

		return $this->r->set( array( self::FAMILY => $family, self::NOTICE => 'none' === $family ? 'manual' : null ) ) ? null : StepResult::retry( 0, 'private: progress could not be stored' );
	}

	/**
	 * One agent file write.
	 *
	 * @param array<string, mixed>              $record VM record.
	 * @param array<string, mixed>              $v      Verified VM.
	 * @param array{file:string,content:string} $file   File.
	 */
	private function write( array $record, array $v, array $file ): ?StepResult {
		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, 'private: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$answer = $this->client->request( 'POST', self::base( $record, $v ) . '/agent/file-write', array( 'file' => $file['file'], 'content' => $file['content'] ) );

		return is_wp_error( $answer ) ? $this->classify( $answer, 'private' ) : null;
	}

	/**
	 * Phase file (detach): the empty-effect file, best effort. A guest that does not answer is left as it is:
	 * a profile for a card that is gone does nothing.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return StepResult|null
	 */
	private function detach_file_phase( array $record, array $op ) {
		$v = $this->verified( $record, 'private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			return null;
		}

		$info   = $this->osinfo( $record, $v );
		$family = null === $info ? (string) ( $op['family'] ?? '' ) : self::family( (string) $info['id'] );
		$file   = self::detach_file( $family, (string) $this->r->get( self::MAC ) );

		if ( null !== $file ) {
			$answer = $this->client->request( 'POST', self::base( $record, $v ) . '/agent/file-write', array( 'file' => $file['file'], 'content' => $file['content'] ) );

			if ( is_wp_error( $answer ) ) {
				Events::add( (int) ( $this->r->license()['id'] ?? 0 ), 'service', 'Private network detach: the guest file was left as it is (' . $this->text( $answer ) . ')' );
			}
		}

		return null;
	}

	/**
	 * Phase stop: one graceful shutdown. A system that does not power off in time is retried, never forced.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return StepResult|null
	 */
	private function phase_stop( array $record, array $op ) {
		// A shutdown remembered from before this operation (a rebuild stops the old server) is not ours.
		if ( empty( $op['stop_sent'] ) ) {
			$this->mark_sent( null );
		}

		$v = $this->verified( $record, 'private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			Operations::remember_power( $this->r->id(), 'stopped' );

			return null;
		}

		if ( ! empty( $op['stop_sent'] ) && $this->sent_recently( 'stop' ) ) {
			$late = $this->reread_power( $record, 'private', false );

			if ( $late instanceof StepResult ) {
				return $late;
			}

			if ( 'running' === $late['status'] ) {
				return $this->still_running( 'private: waiting for the server to stop' );
			}

			Operations::remember_power( $this->r->id(), 'stopped' );

			return null;
		}

		$done = $this->run_task( 'private', 'POST', self::base( $record, $v ) . '/status/shutdown', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'shutdown' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$this->r->set( array( self::OP => array_merge( $this->op(), array( 'stop_sent' => true ) ) ) );

		$again = $this->reread_power( $record, 'private', false );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		if ( 'running' === $again['status'] ) {
			return $this->still_running( 'private: the server did not power off yet', $done );
		}

		Operations::remember_power( $this->r->id(), 'stopped' );
		$this->r->set( array( 'stopped_by_customer' => null ) );

		return null;
	}

	/**
	 * Phase nic (detach): net1 is removed and the removal verified, then the address is released.
	 * A net1 that is not ours is never touched.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return StepResult|null
	 */
	private function phase_nic( array $record, array $op ) {
		unset( $op );

		$v = $this->verified( $record, 'private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' === $v['status'] ) {
			return StepResult::retry( 0, 'private: the server must be off before the network card is removed' );
		}

		if ( ! empty( $v['config']['net1'] ) || ! empty( $v['config']['ipconfig1'] ) ) {
			if ( $this->foreign_nic( $v ) ) {
				return StepResult::fail( 'private: the second network interface is not the private one; nothing was removed' );
			}

			if ( Settings::get( 'pause_all' ) ) {
				return StepResult::wait( Jobs::PAUSED_DELAY, 'private: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
			}

			$answer = $this->client->request( 'PUT', self::base( $record, $v ) . '/config', array( 'delete' => 'net1', 'digest' => (string) ( $v['config']['digest'] ?? '' ) ) );
			$after  = $this->verified( $record, 'private' );

			if ( $after instanceof StepResult ) {
				return $after;
			}

			if ( ! empty( $after['config']['net1'] ) ) {
				return is_wp_error( $answer ) ? $this->classify( $answer, 'private' ) : StepResult::retry( 0, 'private: the network card could not be confirmed removed' );
			}
		}

		// The private address leaves the ipfilter (when it is on) with the card.
		$gone = $this->verified( $record, 'private' );

		if ( $gone instanceof StepResult ) {
			return $gone;
		}

		$sets = ( new Firewall( $this->r, $this->client, $this->cluster ) )->ensure_ipfilter( $record, $gone );

		if ( $sets instanceof StepResult ) {
			return $sets;
		}

		// Only after the removal is verified.
		try {
			if ( ! PrivateNets::release_and_retire( $this->r->id() ) ) {
				return StepResult::retry( 0, 'private: the address could not be released yet' );
			}
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 0, 'private: the address pool is busy' );
		}

		$this->r->set( array( 'private_nic_pending' => null ) );

		return null;
	}

	/**
	 * Phase start: starts the server again when it was running (a detach of a stopped server leaves it stopped).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return StepResult|null
	 */
	private function phase_start( array $record, array $op ) {
		if ( 'detach' === ( $op['action'] ?? '' ) && empty( $op['was_running'] ) ) {
			return null;
		}

		// A start remembered from before this operation (a rebuild's first start) is not ours.
		if ( empty( $op['start_sent'] ) ) {
			$this->mark_sent( null );
		}

		$v = $this->verified( $record, 'private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' === $v['status'] ) {
			Operations::remember_power( $this->r->id(), 'running' );

			return null;
		}

		if ( ! empty( $op['start_sent'] ) && $this->sent_recently( 'start' ) ) {
			$late = $this->reread_power( $record, 'private', true );

			if ( $late instanceof StepResult ) {
				return $late;
			}

			return 'running' === $late['status'] ? null : StepResult::poll( 5, 'private: waiting for the server to start', 120 );
		}

		$done = $this->run_task( 'private', 'POST', self::base( $record, $v ) . '/status/start', array(), 'start' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$this->r->set( array( self::OP => array_merge( $this->op(), array( 'start_sent' => true ) ) ) );

		$again = $this->reread_power( $record, 'private', true );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		if ( 'running' !== $again['status'] ) {
			return StepResult::poll( 5, 'private: the start did not leave the server running', 120 );
		}

		Operations::remember_power( $this->r->id(), 'running' );
		$this->r->set( array( 'stopped_by_customer' => null ) );

		return null;
	}

	/**
	 * Phase agent (attach): the guest agent answers again; the network is attached.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return StepResult|null
	 */
	private function phase_agent( array $record, array $op ) {
		unset( $op );

		$v = $this->verified( $record, 'private' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			return StepResult::retry( 0, 'private: the server is not running after the restart' );
		}

		$ping = $this->client->request( 'POST', self::base( $record, $v ) . '/agent/ping' );

		if ( is_wp_error( $ping ) ) {
			return StepResult::poll( 5, 'private: waiting for the guest agent after the restart', 300 );
		}

		return $this->r->set( array( self::STATE => 'attached', self::OP => null, 'agent' => 'ok', 'private_nic_pending' => null ) ) ? null : StepResult::retry( 0, 'private: the state could not be stored' );
	}

	/**
	 * Phase finish (detach): nothing is attached any more.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $op     Stored operation.
	 * @return StepResult|null
	 */
	private function phase_finish( array $record, array $op ) {
		unset( $record, $op );

		return $this->r->set( array( self::STATE => '', self::OP => null, self::MAC => null, self::FAMILY => null, self::NOTICE => null ) ) ? null : StepResult::retry( 0, 'private: the state could not be stored' );
	}
}

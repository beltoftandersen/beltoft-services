<?php
/**
 * The customer's switch for IPv6 on a server or a database: add the address, or take it away.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Db\DbAccess;
use BeltoftServices\Proxmox\Db\DbActions;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Vm\ActionsRun;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\Profiles;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Proxmox\Vm\VpsProfile;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Actions `ipv6_add` and `ipv6_remove`, run by Actions::run() (servers) and by DbDriver::act() (databases, which then
 * tell the guest through DbIp's `sync`). Only when the cluster has `ipv6_prefix`. The steps, each of which reads the
 * remote state first and is stored in the resource meta `ipv6_op`, so a repeat never does a step twice:
 *
 * 1. config: only `ip6=` and `gw6=` of ipconfig0 change (the IPv4 part is copied as it is). Proxmox shows the change at
 *    once and keeps nothing pending.
 * 2. meta: `ip6` and the log row (kind public6).
 * 3. firewall: the ipfilter set holds (or no longer holds) the address, and the managed rules get their IPv6 side.
 * 4. restart: ONE API reboot of a running server, which is what applies the change (node 300 results: an in-guest reboot
 *    does not). A server that is off takes it at its next start. The restart gives the guest a new cloud-init
 *    instance, so its SSH host keys are made again: the customer is told before they confirm.
 *
 * A server is never left without an address: removing IPv6 is refused when there is no IPv4 address (a future
 * IPv6-only plan, see removable()).
 */
final class Ipv6Switch {

	use Steps;
	use Prepares;
	use ActionsRun;

	public const ADD     = 'ipv6_add';
	public const REMOVE  = 'ipv6_remove';
	public const ACTIONS = array( self::ADD, self::REMOVE );

	/** Meta: the operation in progress (action, phase, restart_done). */
	public const OP = 'ipv6_op';

	/**
	 * Is this one of the two actions?
	 *
	 * @param mixed $action Action.
	 */
	public static function is_action( $action ): bool {
		return in_array( $action, self::ACTIONS, true );
	}

	// -- What is offered -----------------------------------------------------------------------------

	/**
	 * The cluster of a server or a database, or null.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public static function cluster_of( Resource $r ): ?array {
		$record = VmRecords::for_resource( $r->id() );

		return null === $record ? null : Clusters::get( (string) $record['cluster'] );
	}

	/**
	 * Is the switch a thing for this service at all: a server of ours (not adopted) or a database with a public address
	 * (and IPv6 for databases switched on), on a cluster with an IPv6 prefix, with a VMID that fits the address.
	 *
	 * @param Resource $r Resource.
	 */
	public static function applies( Resource $r ): bool {
		if ( true === $r->get( 'linked' ) ) {
			return false;
		}

		if ( ProxmoxDriver::ID === $r->get( 'driver' ) ) {
			if ( ! Profiles::for_resource( $r ) instanceof VpsProfile ) {
				return false;
			}
		} else {
			// A database always has IPv6 on its public card: nothing to switch.
			return false;
		}

		$record  = VmRecords::for_resource( $r->id() );
		$cluster = null === $record ? null : Clusters::get( (string) $record['cluster'] );

		return null !== $cluster && null !== Ipv6::address( $cluster, (int) $record['vmid'] );
	}

	/**
	 * Does the service have an IPv6 address now?
	 *
	 * @param Resource $r Resource.
	 */
	public static function has( Resource $r ): bool {
		return Ipv6::is_address( (string) $r->get( 'ip6' ) );
	}

	/**
	 * The action in progress ('add', 'remove') or ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function working( Resource $r ): string {
		$op = $r->get( self::OP );

		return is_array( $op ) && in_array( $op['action'] ?? '', array( 'add', 'remove' ), true ) ? (string) $op['action'] : '';
	}

	/**
	 * May IPv6 be taken away: the service has a public IPv4 address (a server never ends up with no address at all).
	 * Filter `beltoft_services_ipv6_removable` lets a plan say no for its own reason (an IPv6-only plan).
	 *
	 * @param Resource $r Resource.
	 */
	public static function removable( Resource $r ): bool {
		$has_v4 = ProxmoxDriver::ID === $r->get( 'driver' ) ? false !== filter_var( (string) $r->get( 'ip' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) : '' !== DbAccess::public_address( $r );

		/**
		 * Whether the IPv6 address of a service may be removed.
		 *
		 * @param bool     $removable True when the service has an IPv4 address.
		 * @param Resource $r         Resource.
		 */
		return (bool) apply_filters( 'beltoft_services_ipv6_removable', $has_v4, $r );
	}

	/**
	 * The actions offered now (label by action name).
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	public static function offered( Resource $r ): array {
		// An action that has begun stays offered: the job runs again to finish it (a pending reboot) after its first steps changed the address.
		$working = self::working( $r );

		if ( '' !== $working ) {
			return array( 'ipv6_' . $working => 'add' === $working ? __( 'Add IPv6', 'beltoft-services' ) : __( 'Remove IPv6', 'beltoft-services' ) );
		}

		if ( ! self::applies( $r ) ) {
			return array();
		}

		if ( ! self::has( $r ) ) {
			return array( self::ADD => __( 'Add IPv6', 'beltoft-services' ) );
		}

		return self::removable( $r ) ? array( self::REMOVE => __( 'Remove IPv6', 'beltoft-services' ) ) : array();
	}

	/**
	 * Why the action cannot be accepted, in words for the customer; null when it can (no Proxmox call).
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action.
	 */
	public static function check( Resource $r, string $action ): ?string {
		// An action that has begun is finished: its own first steps changed what the checks look at.
		if ( 'ipv6_' . self::working( $r ) === $action ) {
			return null;
		}

		if ( ! self::applies( $r ) ) {
			return __( 'IPv6 is not available for this service.', 'beltoft-services' );
		}

		if ( self::ADD === $action ) {
			return self::has( $r ) ? __( 'This service already has an IPv6 address.', 'beltoft-services' ) : null;
		}

		if ( ! self::has( $r ) ) {
			return __( 'This service has no IPv6 address.', 'beltoft-services' );
		}

		return self::removable( $r ) ? null : __( 'IPv6 cannot be removed from this service, because it has no IPv4 address.', 'beltoft-services' );
	}

	/**
	 * What the page prints: whether the section shows at all, the address, and the buttons.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function view( Resource $r ): array {
		$offered = self::offered( $r );
		$busy    = '' !== self::working( $r );

		return array(
			'resource_id' => $r->id(),
			'show'        => self::applies( $r ),
			'address'     => self::has( $r ) ? (string) Ipv6::normalise( (string) $r->get( 'ip6' ) ) : '',
			'can_add'     => ! $busy && isset( $offered[ self::ADD ] ),
			'can_remove'  => ! $busy && isset( $offered[ self::REMOVE ] ),
			'busy'        => $busy,
			'locked'      => self::applies( $r ) && self::has( $r ) && ! self::removable( $r ),
		);
	}

	/**
	 * The confirmation text, shown before the customer confirms. Plain words, one place for the server page and the database page.
	 *
	 * @param string $action Action.
	 * @param bool   $db     True for a database.
	 * @return array{title: string, text: string, confirm: string}
	 */
	public static function words( string $action, bool $db = false ): array {
		if ( self::ADD === $action ) {
			return array(
				'title'   => __( 'Add IPv6?', 'beltoft-services' ),
				'text'    => $db
					? __( 'Your database restarts once and is offline for about a minute. The restart makes it a new installation as far as its first boot goes, so the SSH host keys of the machine are made again.', 'beltoft-services' )
					: __( 'Your server restarts once and is offline for about a minute. The restart makes it a new installation as far as its first boot goes: the SSH host keys are made again, so your SSH program will warn that the host key has changed.', 'beltoft-services' ),
				'confirm' => __( 'Add IPv6', 'beltoft-services' ),
			);
		}

		return array(
			'title'   => __( 'Remove IPv6?', 'beltoft-services' ),
			'text'    => $db
				? __( 'Your database restarts once and is offline for about a minute. It loses its IPv6 address, so applications that connect through that address stop working.', 'beltoft-services' )
				: __( 'Your server restarts once and is offline for about a minute. It loses its IPv6 address, so anything that uses that address stops working. The SSH host keys are made again, so your SSH program will warn that the host key has changed.', 'beltoft-services' ),
			'confirm' => __( 'Remove IPv6', 'beltoft-services' ),
		);
	}

	// -- Run -------------------------------------------------------------------------------------

	/**
	 * Runs the action. Built by Actions::run() after the VM is verified, under the resource lock.
	 *
	 * @internal Public only so Actions can call it; not an API.
	 * @param string               $action Action.
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public function run( string $action, array $record, array $v ): StepResult {
		$op = $this->op();

		if ( ( 'ipv6_' . ( $op['action'] ?? '' ) ) !== $action ) {
			$why = self::check( $this->r, $action );

			if ( null !== $why ) {
				return StepResult::refused( array( Jobs::TEXT => $why ), ucfirst( str_replace( '_', ' ', $action ) ) . ' refused: ' . $why );
			}

			$op = array( 'action' => substr( $action, 5 ), 'phase' => 'config' );

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'ipv6: progress could not be stored' );
			}
		}

		$add   = 'add' === $op['action'];
		$order = array( 'config', 'meta', 'firewall', 'restart' );
		$at    = array_search( (string) $op['phase'], $order, true );

		for ( $i = false === $at ? 0 : $at; $i < count( $order ); $i++ ) {
			$out = $this->{'phase_' . $order[ $i ]}( $record, $add );

			if ( $out instanceof StepResult ) {
				return $out;
			}

			if ( isset( $order[ $i + 1 ] ) ) {
				$op          = $this->op();
				$op['phase'] = $order[ $i + 1 ];

				if ( ! $this->r->set( array( self::OP => $op ) ) ) {
					return StepResult::retry( 0, 'ipv6: progress could not be stored' );
				}
			}
		}

		$restarted = ! empty( $this->op()['restarted'] );
		$failed    = ! empty( $this->op()['failed'] );

		$this->r->set( array( self::OP => null ) );

		if ( $failed ) {
			$text = __( 'The address was changed, but the server did not restart. It takes the change when it restarts.', 'beltoft-services' );
		} elseif ( $restarted ) {
			$text = $add ? __( 'IPv6 was added and the server restarted.', 'beltoft-services' ) : __( 'IPv6 was removed and the server restarted.', 'beltoft-services' );
		} else {
			$text = $add ? __( 'IPv6 was added. The server is off, so it takes the address when it starts.', 'beltoft-services' ) : __( 'IPv6 was removed. The server is off, so the change takes effect when it starts.', 'beltoft-services' );
		}

		return StepResult::done( array( Jobs::TEXT => $text ), $add ? 'Customer added IPv6' : 'Customer removed IPv6' );
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
	 * Phase config: ip6 and gw6 of ipconfig0 are written (add) or taken out (remove), the IPv4 part untouched, and read back.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_config( array $record, bool $add ) {
		$v = $this->verified( $record, 'ipv6' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$have  = (string) ( $v['config']['ipconfig0'] ?? '' );
		$vmid  = (int) $record['vmid'];
		$want  = $add ? Ipv6::with_ipconfig( $have, $this->cluster, $vmid ) : Ipv6::strip( $have );

		// A server must keep an IPv4 address to be reached by: never write a config that has none.
		if ( ! Operations::has_public_ipv4( $have ) ) {
			$this->r->set( array( self::OP => null ) );

			return StepResult::refused( array( Jobs::TEXT => __( 'IPv6 cannot be changed on this service, because its network settings have no IPv4 address.', 'beltoft-services' ) ), 'Ipv6 refused: ipconfig0 has no public IPv4 address' );
		}

		if ( $this->in_place( $have, $add, $vmid ) ) {
			return null;
		}

		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, 'ipv6: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$params = array( 'ipconfig0' => $want );

		if ( ! empty( $v['config']['digest'] ) ) {
			$params['digest'] = (string) $v['config']['digest'];
		}

		$answer = $this->client->request( 'PUT', Actions::base( $record, $v ) . '/config', $params );
		$after  = $this->verified( $record, 'ipv6' );

		if ( $after instanceof StepResult ) {
			return $after;
		}

		$now = (string) ( $after['config']['ipconfig0'] ?? '' );

		if ( ! $this->in_place( $now, $add, $vmid ) ) {
			return is_wp_error( $answer ) ? $this->classify( $answer, 'ipv6' ) : StepResult::retry( 0, 'ipv6: the network settings could not be confirmed' );
		}

		return null;
	}

	/**
	 * Does the ipconfig0 already say what the action wants: the IPv6 part of this server (add), or none (remove)?
	 *
	 * @param string $ipconfig0 The VM's ipconfig0.
	 * @param bool   $add       Add or remove.
	 * @param int    $vmid      VMID.
	 */
	private function in_place( string $ipconfig0, bool $add, int $vmid ): bool {
		return $add ? Ipv6::has_ipconfig( $ipconfig0, $this->cluster, $vmid ) : '' === Ipv6::keys( $ipconfig0 )['ip6'] && '' === Ipv6::keys( $ipconfig0 )['gw6'];
	}

	/**
	 * Phase meta: `ip6` and the log of who held the address (kind public6).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_meta( array $record, bool $add ) {
		$address = (string) Ipv6::address( $this->cluster, (int) $record['vmid'] );

		if ( ! $this->r->set( array( 'ip6' => $add ? $address : null ) ) ) {
			return StepResult::retry( 0, 'ipv6: the address could not be recorded' );
		}

		if ( $add ) {
			IpHistory::start_once( Ipv6::KIND, $address, $this->r->id() );
		} else {
			IpHistory::end( Ipv6::KIND, $this->r->id() );
		}

		return null;
	}

	/**
	 * Phase firewall: the ipfilter set takes the address before the guest can use it (or loses it), and a server the
	 * dashboard manages gets its rules for the new state.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_firewall( array $record, bool $add ) {
		unset( $add );

		$v = $this->verified( $record, 'ipv6' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$firewall = new Firewall( $this->r, $this->client, $this->cluster );
		$fresh    = Resource::find( $this->r->id() ) ?? $this->r;
		$done     = ProxmoxDriver::ID === $fresh->get( 'driver' ) && FirewallRules::managed( $fresh ) && true !== $fresh->get( Firewall::LOCKED ) ? $firewall->ensure( $record, $v ) : $firewall->ensure_ipfilter( $record, $v );

		return $done instanceof StepResult ? $done : null;
	}

	/**
	 * Phase restart: one API reboot of a running server. A server that is off takes the change at its next start.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_restart( array $record, bool $add ) {
		unset( $add );

		$op = $this->op();

		if ( ! empty( $op['restarted'] ) || ! empty( $op['skipped'] ) ) {
			return null;
		}

		// The reboot task of the run before this one has ended.
		if ( is_array( $this->finished ) && 'reboot' === $this->finished['phase'] && ! empty( $op['reboot_sent'] ) ) {
			$op['restarted'] = true;

			return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'ipv6: progress could not be stored' );
		}

		$v = $this->verified( $record, 'ipv6' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			$op['skipped'] = true;

			return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'ipv6: progress could not be stored' );
		}

		$op['reboot_sent'] = true;

		if ( ! $this->r->set( array( self::OP => $op, 'stopped_by_customer' => time() ) ) ) {
			return StepResult::retry( 0, 'ipv6: progress could not be stored' );
		}

		$done = $this->run_task( 'ipv6', 'POST', Actions::base( $record, $v ) . '/status/reboot', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'reboot' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		if ( true !== $done ) {
			// The config is right; the server takes it when it restarts. Go on to the end so the operation is cleared.
			$op            = $this->op();
			$op['skipped'] = true;
			$op['failed']  = true;

			return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'ipv6: progress could not be stored' );
		}

		$op              = $this->op();
		$op['restarted'] = true;

		return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'ipv6: progress could not be stored' );
	}
}

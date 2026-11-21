<?php
/**
 * What a server's VM needs when an IPv4 address is added to it or taken from it: the plugin's own actions, queued by the
 * IPv4 add-on driver, never offered to a customer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\FirewallRules;
use BeltoftServices\Proxmox\Network\IpHistory;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Vm\ActionsRun;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Three actions, run by Actions::run() under the server's lock, each remembering its place in the resource meta `ipv4_op`:
 *
 * - `ipv4_main_add`: a server with IPv6 only (VPS Basic) gets its first IPv4 address, held for it in the pool already.
 *   Phases: config (ipconfig0 gets the IPv4 part, the IPv6 part stays), meta (`ip`, the record and the pool row as in use),
 *   firewall (the ipfilter set holds the address before the guest can use it), restart (ONE API reboot of a running
 *   server, which is what applies an ipconfig0 change; a stopped server takes it at its next start).
 * - `ipv4_main_remove`: the same the other way. config (ipconfig0 keeps the IPv6 part only), meta (`ip` cleared), firewall,
 *   restart, then the address goes back to the pool: only after the server stopped using it.
 * - `ipv4_sync`: an extra address came or went. No restart: the ipfilter set is made to hold the extra addresses that are
 *   in use, and the pool rows of new extra addresses become "assigned". The customer puts the address in the guest's own
 *   network settings.
 */
final class Ipv4Server {

	use Steps;
	use Prepares;
	use ActionsRun;

	public const MAIN_ADD    = 'ipv4_main_add';
	public const MAIN_REMOVE = 'ipv4_main_remove';
	public const SYNC        = 'ipv4_sync';
	public const ACTIONS     = array( self::MAIN_ADD, self::MAIN_REMOVE, self::SYNC );

	/** Meta: the operation in progress (action, phase, restart flags). */
	public const OP = 'ipv4_op';

	/** Meta: the start time of the last run that ended. */
	public const SYNCED = 'ipv4_synced';

	/**
	 * Is this one of the actions?
	 *
	 * @param mixed $action Action.
	 */
	public static function is_action( $action ): bool {
		return in_array( $action, self::ACTIONS, true );
	}

	/**
	 * Is an action in progress on the server (its meta is there until it ends)?
	 *
	 * @param Resource $r Server.
	 */
	public static function working( Resource $r ): string {
		$op = $r->get( self::OP );

		return is_array( $op ) && in_array( $op['action'] ?? '', self::ACTIONS, true ) ? (string) $op['action'] : '';
	}

	/**
	 * Runs the action. Built by Actions::run() after the VM is verified.
	 *
	 * @internal Public only so Actions can call it; not an API.
	 * @param string               $action Action.
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public function run( string $action, array $record, array $v ): StepResult {
		unset( $v );

		$op = $this->op();

		// A sync never replaces a main address change in progress: the change is finished first (its firewall phase covers the
		// extras), then the sync runs again.
		$resync = false;

		if ( self::SYNC === $action && in_array( $op['action'] ?? '', array( self::MAIN_ADD, self::MAIN_REMOVE ), true ) ) {
			$action = (string) $op['action'];
			$resync = true;
		}

		if ( ( $op['action'] ?? '' ) !== $action ) {
			$op = array( 'action' => $action, 'phase' => self::SYNC === $action ? 'firewall' : 'config', 'started' => microtime( true ) );

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'ipv4: progress could not be stored' );
			}
		}

		$orders = array(
			self::MAIN_ADD    => array( 'config', 'meta', 'firewall', 'restart' ),
			self::MAIN_REMOVE => array( 'config', 'meta', 'firewall', 'restart', 'release' ),
			self::SYNC        => array( 'firewall', 'assign' ),
		);
		$order  = $orders[ $action ];
		$at     = array_search( (string) $op['phase'], $order, true );

		for ( $i = false === $at ? 0 : $at; $i < count( $order ); $i++ ) {
			$out = $this->{'phase_' . $order[ $i ]}( $record, self::MAIN_ADD === $action );

			if ( $out instanceof StepResult ) {
				return $out;
			}

			if ( isset( $order[ $i + 1 ] ) ) {
				$op          = $this->op();
				$op['phase'] = $order[ $i + 1 ];

				if ( ! $this->r->set( array( self::OP => $op ) ) ) {
					return StepResult::retry( 0, 'ipv4: progress could not be stored' );
				}
			}
		}

		// `ipv4_synced` is the start time (with microseconds, so a run in the same second as a request is told apart) of the last run that ended: an add-on that asked for a run waits for one that started after its request.
		$this->r->set( array( self::OP => null, self::SYNCED => (float) ( $this->op()['started'] ?? microtime( true ) ) ) );

		if ( $resync ) {
			Jobs::queue_act( $this->r->id(), self::SYNC );
		}

		return StepResult::done( array(), self::MAIN_ADD === $action ? 'IPv4 address added to the server' : ( self::MAIN_REMOVE === $action ? 'IPv4 address removed from the server' : 'Extra IPv4 addresses synced' ) );
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
	 * Phase config: ipconfig0 gets the IPv4 part (add) or loses it (remove), the IPv6 part stays, and the config is read back.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_config( array $record, bool $add ) {
		$v = $this->verified( $record, 'ipv4' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$have = (string) ( $v['config']['ipconfig0'] ?? '' );
		$vmid = (int) $record['vmid'];
		// The IPv6 part only when the server has IPv6 (meta `ip6`): the server keeps whatever IPv6 state it had.
		$fresh = Resource::find( $this->r->id() ) ?? $this->r;
		$has6  = '' !== (string) $fresh->get( 'ip6' );
		$ip6   = $has6 ? Ipv6::ipconfig( $this->cluster, $vmid ) : '';

		if ( $add ) {
			$row = IpPool::for_resource( $this->r->id() );

			if ( null === $row ) {
				$this->r->set( array( self::OP => null ) );

				return StepResult::fail( 'ipv4: the address reservation of this server is gone' );
			}

			// The VM's own profile, as at build time: a server's or a database's ipconfig0 (a database keeps its private net1).
			$want = (string) ( \BeltoftServices\Proxmox\Vm\Profiles::for_resource( $this->r )->network_params( $this->cluster, $row, (string) ( $v['config']['net0'] ?? '' ) )['ipconfig0'] ?? '' );

			if ( ! $has6 ) {
				$want = Ipv6::strip( $want );
			}
		} else {
			$want = $ip6;
		}

		// A database changes its IPv4 address this way only on the public card it was built with (DbIp adds a card to an older one).
		if ( \BeltoftServices\Proxmox\Ipv4\Ipv4Meta::is_db( $fresh ) && '' === (string) ( $v['config']['net0'] ?? '' ) ) {
			$this->r->set( array( self::OP => null ) );

			return StepResult::refused( array(), 'ipv4 refused: this database has no public network card (it was built before databases got one)' );
		}

		// A server must never be left with no address at all: without IPv6 it keeps its IPv4 address.
		if ( '' === $want ) {
			$this->r->set( array( self::OP => null ) );

			return StepResult::refused( array(), 'ipv4 refused: the server would have no address' );
		}

		if ( $this->in_place( $have, $want ) ) {
			return null;
		}

		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, 'ipv4: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$params = array( 'ipconfig0' => $want );

		if ( ! empty( $v['config']['digest'] ) ) {
			$params['digest'] = (string) $v['config']['digest'];
		}

		$answer = $this->client->request( 'PUT', Actions::base( $record, $v ) . '/config', $params );
		$after  = $this->verified( $record, 'ipv4' );

		if ( $after instanceof StepResult ) {
			return $after;
		}

		if ( ! $this->in_place( (string) ( $after['config']['ipconfig0'] ?? '' ), $want ) ) {
			return is_wp_error( $answer ) ? $this->classify( $answer, 'ipv4' ) : StepResult::retry( 0, 'ipv4: the network settings could not be confirmed' );
		}

		return null;
	}

	/**
	 * Is the VM's ipconfig0 what the action wants? The IPv4 part must be equal, the IPv6 part is compared by value.
	 *
	 * @param string $have Now.
	 * @param string $want Wanted.
	 */
	private function in_place( string $have, string $want ): bool {
		return Ipv6::strip( $have ) === Ipv6::strip( $want ) && Ipv6::keys( $have ) === Ipv6::keys( $want );
	}

	/**
	 * Phase meta: `ip` and the record's address (add), or neither (remove). The pool row is "assigned" once the address is in
	 * the config; it is given back later (phase release).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_meta( array $record, bool $add ) {
		unset( $record );

		if ( $add ) {
			$row = IpPool::for_resource( $this->r->id() );

			if ( null === $row || ! VmRecords::set_ip( $this->r->id(), $row['id'] ) || ! IpPool::assign( $this->r->id() ) || ! $this->r->set( array( 'ip' => $row['address'] ) ) ) {
				return StepResult::retry( 0, 'ipv4: the address could not be recorded as in use' );
			}

			return null;
		}

		VmRecords::clear_ip( $this->r->id() );

		return $this->r->set( array( 'ip' => null ) ) ? null : StepResult::retry( 0, 'ipv4: progress could not be stored' );
	}

	/**
	 * Phase firewall: the ipfilter set holds the addresses the server has now (the main one, the extra ones in use, the IPv6
	 * one), and a server the dashboard manages gets its rules for the new state.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_firewall( array $record, bool $add ) {
		unset( $add );

		$v = $this->verified( $record, 'ipv4' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$firewall = new Firewall( $this->r, $this->client, $this->cluster );
		$fresh    = Resource::find( $this->r->id() ) ?? $this->r;

		// A database: its allow-list rules on net0, then the ipfilter sets (as DbIp did).
		if ( Ipv4Meta::is_db( $fresh ) ) {
			$done = $firewall->ensure_database( $record, $v );

			return $done instanceof StepResult ? $done : ( ( $sets = $firewall->ensure_ipfilter( $record, $v ) ) instanceof StepResult ? $sets : null );
		}

		$done     = FirewallRules::managed( $fresh ) && true !== $fresh->get( Firewall::LOCKED ) ? $firewall->ensure( $record, $v ) : $firewall->ensure_ipfilter( $record, $v );

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

			return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'ipv4: progress could not be stored' );
		}

		$v = $this->verified( $record, 'ipv4' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			$op['skipped'] = true;

			return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'ipv4: progress could not be stored' );
		}

		$op['reboot_sent'] = true;

		if ( ! $this->r->set( array( self::OP => $op, 'stopped_by_customer' => time() ) ) ) {
			return StepResult::retry( 0, 'ipv4: progress could not be stored' );
		}

		$done = $this->run_task( 'ipv4', 'POST', Actions::base( $record, $v ) . '/status/reboot', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'reboot' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$op              = $this->op();
		$op['restarted'] = true;

		// A reboot that did not finish in time is left to finish by itself: the config is right, the server takes it when it restarts.
		return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'ipv4: progress could not be stored' );
	}

	/**
	 * Phase release (remove): the address goes back to the pool, after the server stopped using it.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Add or remove.
	 * @return StepResult|null
	 */
	private function phase_release( array $record, bool $add ) {
		unset( $record, $add );

		IpHistory::end( 'public', $this->r->id() );
		IpPool::release( $this->r->id() );

		return null;
	}

	/**
	 * Phase assign (sync): the pool rows of the extra addresses that were taken for add-ons of this server become "assigned"
	 * now that the firewall holds them.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param bool                 $add    Unused.
	 * @return StepResult|null
	 */
	private function phase_assign( array $record, bool $add ) {
		unset( $record, $add );

		$server = Resource::find( $this->r->id() ) ?? $this->r;

		foreach ( Ipv4Meta::reserved_for( $server ) as $addon ) {
			if ( 'extra' === $addon->get( Ipv4Meta::ROLE ) && '' !== (string) $addon->get( Ipv4Meta::ADDRESS ) ) {
				IpPool::assign( $addon->id() );
			}
		}

		return null;
	}
}

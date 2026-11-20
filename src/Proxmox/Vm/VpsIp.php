<?php
/**
 * The public IPv4 add-on of a VPS Basic server: an address added to, or taken from, a server that has IPv6.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\FirewallRules;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\Ipv6;
use BeltoftServices\Proxmox\Network\NetConfig;
use BeltoftServices\Proxmox\Network\PublicIp;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The shop side is Network\PublicIp, shared with the database add-on (Db\DbIp): a pair of variations that differ in the
 * plan field `public_ip`. Switching the subscription changes the plan, and ProxmoxDriver::update_plan hands the change to this
 * class, which makes the server match. A server has its network card all along, so only ipconfig0 changes:
 *
 * - add: take an address from the pool (IpPool::hold), write ipconfig0 (the IPv4 part in front of the IPv6 part) and read
 *   it back, record the address as in use, put it in the ipfilter set, then restart the server once so that the guest takes
 *   it (Proxmox does not apply a changed ipconfig0 to a running guest).
 * - remove: write ipconfig0 with the IPv6 part alone and read it back, restart the server once, give the address back
 *   only after that, then take it out of the ipfilter set.
 *
 * A server that is off is not started for it: the guest takes the change at its next start. Each phase is idempotent and
 * the stored operation (`vps_ip_op`) remembers where it was; Proxmox is read before anything is believed.
 */
final class VpsIp {

	use Steps;
	use Prepares;

	/** Meta: the operation in progress (action, phase, flags). */
	public const OP = 'vps_ip_op';

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * The action in progress: add, remove, or ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function action( Resource $r ): string {
		$op = $r->get( self::OP );

		return is_array( $op ) && in_array( $op['action'] ?? '', array( 'add', 'remove' ), true ) ? (string) $op['action'] : '';
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
	 * What the server needs to match its plan: add, remove, or '' (nothing). An operation that has begun is finished. An
	 * adopted server is never touched; a server with no IPv6 address is never left with none (no remove); an address is
	 * only added to a server that has none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function wanted( Resource $r ): string {
		$action = self::action( $r );

		if ( '' !== $action ) {
			return $action;
		}

		if ( true === $r->get( 'linked' ) ) {
			return '';
		}

		$row = IpPool::for_resource( $r->id() );

		if ( PublicIp::wants( (array) $r->get( 'plan' ), 1 ) ) {
			return ( null === $row || 'assigned' !== $row['state'] ) && '' === (string) $r->get( 'ip' ) && null !== VmRecords::for_resource( $r->id() ) ? 'add' : '';
		}

		return null !== $row && '' !== (string) $r->get( 'ip6' ) ? 'remove' : '';
	}

	/**
	 * The refusal of a switch that would leave a server without any address: taking the IPv4 address from a server that has
	 * no IPv6 one. Empty when the switch may go on (or is not a removal).
	 *
	 * @param int    $subscription_id Subscription id.
	 * @param int    $product_id      Target product.
	 * @param array  $target          Target plan.
	 * @param mixed  $item            The line item switched.
	 */
	public static function switch_error( int $subscription_id, int $product_id, array $target, $item ): string {
		$resources = \BeltoftServices\Billing\SwitchGuard::resources( $subscription_id, $item, ProxmoxDriver::ID, $product_id );

		if ( PublicIp::wants( $target, 1 ) ) {
			return array() === $resources ? '' : PublicIp::pool_error( $subscription_id, $product_id, ProxmoxDriver::ID, $item );
		}

		foreach ( $resources as $resource ) {
			if ( '' !== (string) $resource->get( 'ip' ) && '' === (string) $resource->get( 'ip6' ) ) {
				return __( 'This server has no IPv6 address, so its IPv4 address cannot be removed.', 'beltoft-services' );
			}
		}

		return '';
	}

	// -- The operation ---------------------------------------------------------------------------------

	/**
	 * Runs the operation from where it was.
	 */
	public function run(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'vps_ip: no VM is recorded for this service' );
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

			$op = array( 'action' => $action, 'phase' => 'add' === $action ? 'hold' : 'nic' );

			if ( ! $this->r->set( array( self::OP => $op ) ) ) {
				return StepResult::retry( 0, 'vps_ip: progress could not be stored' );
			}
		}

		$order = array(
			'add'    => array( 'hold', 'nic', 'record', 'firewall', 'reboot' ),
			'remove' => array( 'nic', 'reboot', 'record', 'firewall' ),
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
					return StepResult::retry( 0, 'vps_ip: progress could not be stored' );
				}
			}

			$this->progressed = true;
		}

		$added = 'add' === $op['action'];
		$this->r->set( array( self::OP => null ) );

		return StepResult::done( array(), $added ? 'Public IP address added to the server' : 'Public IP address removed from the server' );
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
	 * Gives up an add that cannot go on: the operation is forgotten and the change fails.
	 *
	 * @param string $why Reason (for the admin).
	 */
	private function give_up( string $why ): StepResult {
		$this->r->set( array( self::OP => null ) );

		return StepResult::fail( $why );
	}

	/**
	 * Phase hold (add): the address. The customer has paid for it, so the pool's reserve for new servers is not applied here.
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

			return StepResult::retry( 0, 'vps_ip: the IP pool is busy' );
		}

		if ( null === $ip ) {
			return $this->give_up( 'No free public IP address for this server; the plan has the add-on but the server was not changed' );
		}

		if ( null !== $ip['gateway'] && '' !== $ip['gateway'] && ! NetConfig::gateway_on_network( $ip['address'], $ip['gateway'], (int) $this->cluster['prefix'] ) ) {
			IpPool::release( $this->r->id() );

			return $this->give_up( sprintf( 'vps_ip: the gateway %1$s of address %2$s is outside %2$s/%3$d; the address was given back', $ip['gateway'], $ip['address'], (int) $this->cluster['prefix'] ) );
		}

		return null;
	}

	/**
	 * The ipconfig0 the server should have after the change: the pool address and its gateway in front, the IPv6 part after
	 * (add), or the IPv6 part alone (remove).
	 *
	 * @param array<string, mixed>      $record VM record.
	 * @param array<string, mixed>|null $ip     The pool row (add) or null (remove).
	 */
	private function wanted_ipconfig( array $record, ?array $ip ): string {
		$ip6 = '' === (string) $this->r->get( 'ip6' ) ? '' : Ipv6::ipconfig( $this->cluster, (int) $record['vmid'] );

		if ( null === $ip ) {
			return $ip6;
		}

		$gateway = null !== $ip['gateway'] && '' !== $ip['gateway'] ? $ip['gateway'] : (string) $this->cluster['gateway'];
		$v4      = 'ip=' . $ip['address'] . '/' . (int) $this->cluster['prefix'] . ',gw=' . $gateway;

		return '' === $ip6 ? $v4 : $v4 . ',' . $ip6;
	}

	/**
	 * Is the config's ipconfig0 what is wanted (the IPv4 part exactly, the IPv6 part by value)?
	 *
	 * @param string $have Config's ipconfig0.
	 * @param string $want Wanted ipconfig0.
	 */
	private static function same( string $have, string $want ): bool {
		return Ipv6::strip( $have ) === Ipv6::strip( $want ) && Ipv6::keys( $have ) === Ipv6::keys( $want );
	}

	/**
	 * Phase nic: ipconfig0 is written and read back. An add that Proxmox definitely refuses gives the address back; an
	 * unclear answer keeps it and the next run reads the config.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_nic( array $record ) {
		$v = $this->verified( $record, 'vps_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$add = 'add' === $this->op()['action'];
		$ip  = $add ? IpPool::for_resource( $this->r->id() ) : null;

		if ( $add && null === $ip ) {
			return $this->give_up( 'vps_ip: the address reservation is gone' );
		}

		$want = $this->wanted_ipconfig( $record, $ip );
		$have = (string) ( $v['config']['ipconfig0'] ?? '' );

		if ( '' === $want || self::same( $have, $want ) ) {
			return null;
		}

		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, 'vps_ip: all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$params = array( 'ipconfig0' => $want );

		if ( ! empty( $v['config']['digest'] ) ) {
			$params['digest'] = (string) $v['config']['digest'];
		}

		$answer = $this->client->request( 'PUT', '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . (int) $record['vmid'] . '/config', $params );
		$after  = $this->verified( $record, 'vps_ip' );

		if ( $after instanceof StepResult ) {
			return $after;
		}

		if ( ! self::same( (string) ( $after['config']['ipconfig0'] ?? '' ), $want ) ) {
			if ( $add && is_wp_error( $answer ) && in_array( $answer->get_error_code(), array( 'http_400', 'http_403', 'http_404', 'http_422' ), true ) ) {
				IpPool::release( $this->r->id() );

				return $this->give_up( 'vps_ip: Proxmox refused the network settings; the address was given back (' . $this->text( $answer ) . ')' );
			}

			return is_wp_error( $answer ) ? $this->classify( $answer, 'vps_ip' ) : StepResult::retry( 0, 'vps_ip: the network settings could not be confirmed' );
		}

		return null;
	}

	/**
	 * Phase record: the address is recorded as in use (add) after the network settings are seen, and given back (remove)
	 * only after the guest restarted without it.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_record( array $record ) {
		unset( $record );

		if ( 'add' === $this->op()['action'] ) {
			$ip = IpPool::for_resource( $this->r->id() );

			if ( null === $ip ) {
				return $this->give_up( 'vps_ip: the address reservation is gone' );
			}

			if ( ! VmRecords::set_ip( $this->r->id(), $ip['id'] ) || ! IpPool::assign( $this->r->id() ) || ! $this->r->set( array( 'ip' => $ip['address'] ) ) ) {
				return StepResult::retry( 0, 'vps_ip: the address could not be recorded as in use' );
			}

			return null;
		}

		VmRecords::clear_ip( $this->r->id() );

		if ( ! $this->r->set( array( 'ip' => null ) ) ) {
			return StepResult::retry( 0, 'vps_ip: progress could not be stored' );
		}

		IpPool::release( $this->r->id() );

		return null;
	}

	/**
	 * Phase firewall: the ipfilter set holds the addresses the server has now (the IPv4 one is in it before the guest can
	 * use it, and out of it after the guest let go), and a server the dashboard manages gets its rules for both families.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_firewall( array $record ) {
		$v = $this->verified( $record, 'vps_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$firewall = new Firewall( $this->r, $this->client, $this->cluster );
		$done     = FirewallRules::managed( $this->r ) && true !== $this->r->get( Firewall::LOCKED ) ? $firewall->ensure( $record, $v ) : $firewall->ensure_ipfilter( $record, $v );

		return $done instanceof StepResult ? $done : null;
	}

	/**
	 * Phase reboot: one graceful restart so that the guest takes the new network settings, only for a server that runs. A
	 * server that is off takes them at its next start.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return StepResult|null
	 */
	private function phase_reboot( array $record ) {
		$v = $this->verified( $record, 'vps_ip' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$op = $this->op();

		if ( 'running' !== $v['status'] || ! empty( $op['reboot_sent'] ) ) {
			return null;
		}

		$done = $this->run_task( 'vps_ip', 'POST', '/nodes/' . rawurlencode( (string) $v['node'] ) . '/qemu/' . (int) $record['vmid'] . '/status/reboot', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'reboot' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$op['reboot_sent'] = true;

		return $this->r->set( array( self::OP => $op ) ) ? null : StepResult::retry( 0, 'vps_ip: progress could not be stored' );
	}
}

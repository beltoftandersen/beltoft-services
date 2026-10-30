<?php
/**
 * The public IPv4 add-on of a managed database: a variation of the plan, applied to the VM as net0.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The add-on is a pair of variations that differ in the plan field `public_ip` (0 or 1), like Backups. Switching the
 * subscription changes the plan, and DbDriver::update_plan hands the change to this class, which makes the VM match:
 *
 * - add: take an address from the pool (IpPool::hold), shut the VM down, write net0 and ipconfig0 and read them back,
 *   start it, apply an empty allow-list (nothing is allowed until the customer adds an address), then the firewall.
 * - remove: clear the allow-list in the firewall and the guest, shut the VM down, delete net0 and ipconfig0 and read
 *   the config back, give the address back only after that, start the VM.
 *
 * The VM restarts once, and the customer is told before they confirm. Each phase is idempotent and the stored
 * operation (`db_ip_op`) remembers where it was; Proxmox is read before anything is believed.
 */
final class DbIp {

	use Steps;
	use Prepares;

	/** Meta: the operation in progress (action, phase, flags). */
	public const OP = 'db_ip_op';

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Does the plan have the public IP add-on?
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function plan_wants( array $plan ): bool {
		return (int) ( $plan['public_ip'] ?? 0 ) > 0;
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

		if ( self::plan_wants( (array) $r->get( 'plan' ) ) ) {
			return null === $row || 'assigned' !== $row['state'] ? 'add' : '';
		}

		return null !== $row ? 'remove' : '';
	}

	/**
	 * net0 and ipconfig0 of a public address: the template's NIC options and MAC stay, the bridge, the firewall flag and
	 * the VLAN tag are the cluster's, and the gateway is the pool row's or the cluster's.
	 *
	 * @param array<string, mixed> $cluster Cluster.
	 * @param array<string, mixed> $ip      Pool row.
	 * @param string               $net0    The VM's net0 now ('' for none).
	 * @return array<string, string>
	 */
	public static function public_params( array $cluster, array $ip, string $net0 ): array {
		$props = NetConfig::parse( $net0 );

		if ( array() === $props ) {
			$props['virtio'] = null;
		}

		$props['bridge']   = (string) $cluster['bridge'];
		$props['firewall'] = '1';

		if ( (int) $cluster['vlan'] > 0 ) {
			$props['tag'] = (string) (int) $cluster['vlan'];
		}

		$gateway = null !== $ip['gateway'] && '' !== $ip['gateway'] ? $ip['gateway'] : (string) $cluster['gateway'];

		return array(
			'ipconfig0' => 'ip=' . $ip['address'] . '/' . (int) $cluster['prefix'] . ',gw=' . $gateway,
			'net0'      => NetConfig::format( $props ),
		);
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

		return (string) ( $config['ipconfig0'] ?? '' ) === $want['ipconfig0']
			&& ( $have['bridge'] ?? '' ) === ( $expect['bridge'] ?? '' ) && ( $have['tag'] ?? '' ) === ( $expect['tag'] ?? '' ) && '1' === ( $have['firewall'] ?? '' );
	}

	// -- The shop side --------------------------------------------------------------------------------

	/**
	 * The refusal of a switch to a variation with the add-on while no address can be given (an existing customer's
	 * switch is let through by Stock, so the pool is checked here). Empty when the switch may go on.
	 *
	 * @param int                  $subscription_id Subscription id.
	 * @param int                  $product_id      Target product.
	 * @param array<string, mixed> $target          Target plan.
	 * @param mixed                $item            The line item switched.
	 */
	public static function switch_error( int $subscription_id, int $product_id, array $target, $item ): string {
		if ( ! self::plan_wants( $target ) ) {
			return '';
		}

		$item_product = is_object( $item ) && method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;

		foreach ( Licenses::where( 'subscription_id', $subscription_id ) as $license ) {
			if ( ( $item_product > 0 && (int) ( $license['product_id'] ?? 0 ) !== $item_product ) || (int) ( $license['product_id'] ?? 0 ) !== $product_id ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( $resource && DbDriver::ID === $resource->get( 'driver' ) && null === IpPool::for_resource( $resource->id() ) && 0 === IpPool::available_for_db() ) {
				return __( 'No public IP address is available right now. Please try again later.', 'beltoft-services' );
			}
		}

		return '';
	}

	/**
	 * The link that switches the subscription to the variation with or without the public IP (the same WooCommerce
	 * Subscriptions switch as for backups). Empty when there is none.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the target has the public IP.
	 */
	public static function switch_url( Resource $r, bool $with ): string {
		$sibling = self::sibling( $r, $with );

		return null === $sibling ? '' : Backups::switch_to( $r, $sibling );
	}

	/**
	 * "+€4.00 / month" of the variation with the public IP, or its removal. Empty when there is none.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the target has the public IP.
	 */
	public static function price_label( Resource $r, bool $with ): string {
		$variation = wc_get_product( (int) ( $r->license()['variation_id'] ?? 0 ) );
		$sibling   = self::sibling( $r, $with );

		return $variation instanceof \WC_Product_Variation && null !== $sibling ? Backups::price_difference( $variation, $sibling ) : '';
	}

	/**
	 * The other variation of the pair.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the sibling has the public IP.
	 */
	public static function sibling( Resource $r, bool $with ): ?\WC_Product_Variation {
		$id        = (int) ( $r->license()['variation_id'] ?? 0 );
		$variation = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		return $variation instanceof \WC_Product_Variation ? Backups::sibling_by( $variation, 'public_ip', $with ) : null;
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

			$op = array( 'action' => $action, 'phase' => 'add' === $action ? 'hold' : 'clear' );

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

		$order = 'add' === $op['action'] ? array( 'hold', 'stop', 'nic', 'start', 'access', 'firewall' ) : array( 'clear', 'stop', 'nic', 'start', 'firewall' );
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
		$this->r->set( array( self::OP => null ) );

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

		if ( null !== $ip['gateway'] && '' !== $ip['gateway'] && ! self::gateway_ok( $ip['address'], $ip['gateway'], (int) $this->cluster['prefix'] ) ) {
			IpPool::release( $this->r->id() );
			$this->r->set( array( self::OP => null ) );

			return StepResult::fail( sprintf( 'db_ip: the gateway %1$s of address %2$s is outside %2$s/%3$d; the address was given back', $ip['gateway'], $ip['address'], (int) $this->cluster['prefix'] ) );
		}

		return null;
	}

	/**
	 * Is the gateway on the address's network?
	 *
	 * @param string $address Address.
	 * @param string $gateway Gateway.
	 * @param int    $prefix  Prefix.
	 */
	private static function gateway_ok( string $address, string $gateway, int $prefix ): bool {
		$a = ip2long( $address );
		$g = ip2long( $gateway );

		if ( false === $a || false === $g || $prefix < 1 || $prefix > 32 ) {
			return false;
		}

		$mask = ( 0xFFFFFFFF << ( 32 - $prefix ) ) & 0xFFFFFFFF;

		return ( $a & $mask ) === ( $g & $mask );
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

		$want = self::public_params( $this->cluster, $ip, (string) ( $v['config']['net0'] ?? '' ) );

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

		if ( ! VmRecords::set_ip( $this->r->id(), $ip['id'] ) || ! IpPool::assign( $this->r->id() ) || ! $this->r->set( array( 'ip' => $ip['address'] ) ) ) {
			return StepResult::retry( 0, 'db_ip: the address could not be recorded as in use' );
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

		if ( ! $this->r->set( array( 'ip' => null ) ) ) {
			return StepResult::retry( 0, 'db_ip: progress could not be stored' );
		}

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

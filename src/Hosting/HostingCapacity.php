<?php
/**
 * Capacity and placement of hosting accounts on nodes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Alerts;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plan section 15.1, from the records and the settings, with no call to a node (a node's size is a field of its
 * record). Everything here is in MB.
 *
 * - Memory committed on a node = the sum of the container memory caps of its accounts divided by the memory
 *   overcommit factor, plus the shared database buffer share of each account (not overcommitted).
 * - Memory budget = the node memory minus the reserve.
 * - Disk committed = the sum of root disk plus dump mount (a quarter of it, at least 1 GB) divided by the disk
 *   overcommit factor. The database size is part of the plan storage, so it is already inside the disk. The
 *   hourly measured database disk of the plan comes with the usage job (not in this slice).
 * - A plan fits a node when its own committed memory and disk fit in what is free.
 * - Placement: the enabled node of the plan's group with the most free memory that fits.
 */
final class HostingCapacity {

	/**
	 * Usage of each node already computed in this process, by node id (null for an unknown node).
	 *
	 * @var array<string, array<string, float|int>|null>
	 */
	private static $memo = array();

	/**
	 * Hooks that empty the memo. See forget() for why this list is enough.
	 */
	public static function register(): void {
		// A job starts: one worker process runs many jobs, and another process may have written since the last one.
		add_action( 'action_scheduler_before_execute', array( __CLASS__, 'forget' ) );
		// A resource's state or plan was stored (Resource::set(), the one writer of both).
		add_action( 'beltoft_services_resource_saved', array( __CLASS__, 'resource_saved' ), 10, 2 );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'forget' ) );
		// Node settings and hosting settings (reserve, overcommit) are inputs of usage().
		foreach ( array( Nodes::OPTION, \BeltoftServices\Settings::OPTION ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'forget' ) );
			add_action( 'add_option_' . $option, array( __CLASS__, 'forget' ) );
			add_action( 'delete_option_' . $option, array( __CLASS__, 'forget' ) );
		}
	}

	/**
	 * Empties the memo of usage(), so the next answer is read from the records again.
	 *
	 * Why the memo is safe. usage() is a pure function of four inputs: the hosting records (Records), the state and
	 * plan of each recorded resource, the node settings and the hosting settings. Every writer of an input forgets:
	 * Records::open() and Records::remove() (records), Resource::set() through beltoft_services_resource_saved when a
	 * state or plan is stored (so also transition(), which sets the state, and a plan change at a switch), the update,
	 * add and delete hooks of the node option and the settings option, and beltoft_services_state_changed. Another
	 * PHP process is the one writer this process cannot see, so the memo also dies with the request, and
	 * action_scheduler_before_execute empties it before each job of a long-lived worker. A decision that reserves
	 * capacity must not rest on an answer older than its own lock: Provision places inside the placement lock after
	 * forget(), HostingSwitch::refusal() forgets first, and Stock forgets when an item is added to the cart and when
	 * the cart is checked, so what a page showed earlier in the request never decides an order. A display (a label or
	 * a sold-out state) may use the memo: it was true when the request started, as before.
	 */
	public static function forget(): void {
		self::$memo = array();
	}

	/**
	 * Action beltoft_services_resource_saved: a state or plan changed.
	 *
	 * @param mixed $resource Resource.
	 * @param mixed $meta     Values stored.
	 */
	public static function resource_saved( $resource = null, $meta = array() ): void {
		unset( $resource );

		if ( is_array( $meta ) && ( array_key_exists( 'state', $meta ) || array_key_exists( 'plan', $meta ) ) ) {
			self::forget();
		}
	}

	/**
	 * The mount point size of an account in GB, as Provision::step_configure() makes it.
	 *
	 * @param int $disk_gb Plan disk in GB.
	 */
	public static function dump_gb( int $disk_gb ): int {
		return max( 1, intdiv( $disk_gb, 4 ) );
	}

	/**
	 * What one plan commits on a node, in MB.
	 *
	 * @param array<string, int|string|bool> $plan Typed plan.
	 * @return array{memory: float, disk: float}
	 */
	public static function cost( array $plan ): array {
		$mem  = max( 1.0, Config::number( 'mem_overcommit', 1.0 ) );
		$disk = max( 1.0, Config::number( 'disk_overcommit', 1.0 ) );

		return array(
			'memory' => (int) $plan['memory_mb'] / $mem + (int) $plan['db_buffer_mb'],
			'disk'   => ( (int) $plan['disk_gb'] + self::dump_gb( (int) $plan['disk_gb'] ) ) * 1024 / $disk,
		);
	}

	/**
	 * What a node has and what its accounts commit.
	 *
	 * @param string $node_id Node record id.
	 * @return array{memory_budget: float, memory_committed: float, disk_budget: float, disk_committed: float, accounts: int}|null Null for an unknown node.
	 */
	public static function usage( string $node_id ): ?array {
		if ( array_key_exists( $node_id, self::$memo ) ) {
			return self::$memo[ $node_id ];
		}

		return self::$memo[ $node_id ] = self::compute( $node_id );
	}

	/**
	 * The usage of a node, read from the records now.
	 *
	 * @param string $node_id Node record id.
	 * @return array{memory_budget: float, memory_committed: float, disk_budget: float, disk_committed: float, accounts: int}|null
	 */
	private static function compute( string $node_id ): ?array {
		$node = Nodes::all()[ $node_id ] ?? null;

		if ( null === $node ) {
			return null;
		}

		$memory = 0.0;
		$disk   = 0.0;
		$count  = 0;

		// Every account of the node in one read.
		foreach ( Resource::find_many( Records::resource_ids( $node_id ) ) as $r ) {
			// A deleted or gone resource commits nothing; its record is released by its delete.
			if ( 'deleted' === $r->get( 'state' ) ) {
				continue;
			}

			$cost    = self::cost( Plan::typed( (array) $r->get( 'plan' ) ) );
			$memory += $cost['memory'];
			$disk   += $cost['disk'];
			++$count;
		}

		return array(
			'memory_budget'    => max( 0.0, (int) $node['mem_gb'] * 1024 - Config::number( 'reserve_gb' ) * 1024 ),
			'memory_committed' => $memory,
			'disk_budget'      => (float) ( (int) $node['disk_gb'] * 1024 ),
			'disk_committed'   => $disk,
			'accounts'         => $count,
		);
	}

	/**
	 * Free memory on a node in MB (0 when full or unknown).
	 *
	 * @param string $node_id Node record id.
	 */
	public static function free_memory( string $node_id ): float {
		$u = self::usage( $node_id );

		return null === $u ? 0.0 : max( 0.0, $u['memory_budget'] - $u['memory_committed'] );
	}

	/**
	 * Does a plan fit on a node now?
	 *
	 * @param string                         $node_id Node record id.
	 * @param array<string, int|string|bool> $plan    Typed plan.
	 */
	public static function fits( string $node_id, array $plan ): bool {
		$u = self::usage( $node_id );

		if ( null === $u ) {
			return false;
		}

		$cost = self::cost( $plan );

		return $u['memory_budget'] - $u['memory_committed'] >= $cost['memory'] && $u['disk_budget'] - $u['disk_committed'] >= $cost['disk'];
	}

	/**
	 * Does an upgrade fit on the node the account is on? Only the growth counts (the account already holds the old plan).
	 *
	 * @param string                         $node_id Node record id.
	 * @param array<string, int|string|bool> $have    Typed current plan.
	 * @param array<string, int|string|bool> $target  Typed target plan.
	 */
	public static function fits_upgrade( string $node_id, array $have, array $target ): bool {
		$u = self::usage( $node_id );

		if ( null === $u ) {
			return false;
		}

		$old = self::cost( $have );
		$new = self::cost( $target );

		$memory = $new['memory'] - $old['memory'];
		$disk   = $new['disk'] - $old['disk'];

		return ( $memory <= 0 || $u['memory_budget'] - $u['memory_committed'] >= $memory ) && ( $disk <= 0 || $u['disk_budget'] - $u['disk_committed'] >= $disk );
	}

	/**
	 * The node for a new account: of the plan's group, enabled, with room, and the most free memory (the first
	 * in stored order on a tie).
	 *
	 * @param array<string, int|string|bool> $plan Typed plan.
	 * @return string|null Node id, or null when no node has room.
	 */
	public static function place( array $plan ): ?string {
		$best = null;
		$most = -1.0;

		foreach ( Nodes::in_group( (string) $plan['node_group'] ) as $id ) {
			if ( ! self::fits( $id, $plan ) ) {
				continue;
			}

			$free = self::free_memory( $id );

			if ( $free > $most ) {
				$best = $id;
				$most = $free;
			}
		}

		return $best;
	}

	/**
	 * Can a new account of this plan be built now? (The stock rule.)
	 *
	 * @param array<string, int|string|bool> $plan Typed plan.
	 */
	public static function available( array $plan ): bool {
		return null !== self::place( $plan );
	}

	/**
	 * Mails the admin once a day per node when committed memory or disk reaches the alert share (plan 15.1).
	 *
	 * @return string[] Node ids an alert was sent for.
	 */
	public static function alert(): array {
		$limit = Config::number( 'alert_percent', 10.0 ) / 100;
		$sent  = array();

		foreach ( array_keys( Nodes::all() ) as $id ) {
			$u = self::usage( (string) $id );

			if ( null === $u || ( $u['memory_budget'] <= 0 && $u['disk_budget'] <= 0 ) ) {
				continue;
			}

			$mem  = $u['memory_budget'] > 0 ? $u['memory_committed'] / $u['memory_budget'] : 1.0;
			$disk = $u['disk_budget'] > 0 ? $u['disk_committed'] / $u['disk_budget'] : 1.0;

			if ( max( $mem, $disk ) < $limit ) {
				continue;
			}

			$key = 'beltoft_services_hosting_capacity_alert_' . md5( (string) $id );

			$mail = Alerts::notify(
				sprintf( 'Hosting node %s is filling up', $id ),
				array(
					sprintf( 'Hosting node %s has committed %d%% of its memory budget and %d%% of its disk.', $id, (int) round( $mem * 100 ), (int) round( $disk * 100 ) ),
					'Add a node or raise the capacity before more accounts are sold.',
				),
				null,
				$key
			);

			if ( ! $mail ) {
				continue;
			}

			$sent[] = (string) $id;
		}

		return $sent;
	}
}

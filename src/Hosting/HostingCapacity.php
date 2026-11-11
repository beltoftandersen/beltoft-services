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
 * - Placement: the enabled node of the plan's group with the most free memory that fits. When no node fits, the one
 *   with the most room left (the least over its budget): capacity never stops a sale or a placement (owner decision
 *   2026-11-11, hosting is oversold on purpose). What is over budget is reported to the admin (overage_alerts()).
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
	 * forget(). Nothing else decides on it: since 2026-11-11 no page, cart or checkout asks for capacity, so only the
	 * placement and the alerts read usage(), and a page render reads nothing of it at all.
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
	 * The node for a new account: of the plan's group and enabled, the one with the most free memory among those where
	 * the plan fits (the first in stored order on a tie). When none fits, the one with the most room left once the plan
	 * is added (the least over its budget, the worse of memory and disk), so a new account is always placed while the
	 * group has a node. Null only when the group has no enabled node.
	 *
	 * @param array<string, int|string|bool> $plan Typed plan.
	 * @return string|null Node id.
	 */
	public static function place( array $plan ): ?string {
		$best = null;
		$most = -1.0;
		$over = null;
		$room = null;
		$cost = self::cost( $plan );

		foreach ( Nodes::in_group( (string) $plan['node_group'] ) as $id ) {
			$u = self::usage( $id );

			if ( null === $u ) {
				continue;
			}

			if ( self::fits( $id, $plan ) ) {
				$free = self::free_memory( $id );

				if ( $free > $most ) {
					$best = $id;
					$most = $free;
				}

				continue;
			}

			// Share of the budget left after this plan (negative when over); the worse of memory and disk.
			$left = min(
				$u['memory_budget'] > 0 ? ( $u['memory_budget'] - $u['memory_committed'] - $cost['memory'] ) / $u['memory_budget'] : -1.0,
				$u['disk_budget'] > 0 ? ( $u['disk_budget'] - $u['disk_committed'] - $cost['disk'] ) / $u['disk_budget'] : -1.0
			);

			if ( null === $room || $left > $room ) {
				$over = $id;
				$room = $left;
			}
		}

		return $best ?? $over;
	}

	/**
	 * Mails the admin once a day per node when committed memory or disk reaches the alert share (plan 15.1). An early
	 * warning only: nothing is stopped at that share, or at 100 percent (see overage_alerts()).
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
					'Add a node or raise the capacity. New accounts are still placed, on the node with the most room.',
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

	/**
	 * Mails the admin when the memory or the disk committed on a node passes its budget (100 percent), and again at each
	 * further 10 percent, once per step, forgotten only when the share has fallen 5 points under the step (Alerts::overage()).
	 *
	 * @param string|null $only One node id, or null for every node.
	 * @return string[] Keys ("node:memory" or "node:disk") a mail was sent for.
	 */
	public static function overage_alerts( ?string $only = null ): array {
		$sent = array();

		if ( null === $only ) {
			// A node that was removed no longer keeps a remembered step.
			$live = array();

			foreach ( array_keys( Nodes::all() ) as $node_id ) {
				$live[] = 'hosting:' . $node_id . ':memory';
				$live[] = 'hosting:' . $node_id . ':disk';
			}

			Alerts::overage_prune( 'hosting:', $live );
		}

		foreach ( array_keys( Nodes::all() ) as $id ) {
			$id = (string) $id;
			$u  = ( null === $only || $only === $id ) ? self::usage( $id ) : null;

			if ( null === $u ) {
				continue;
			}

			$reserve = Config::number( 'reserve_gb' );
			$node    = Nodes::all()[ $id ];

			$kinds = array(
				'memory' => array(
					$u['memory_committed'],
					$u['memory_budget'],
					sprintf(
						/* translators: 1: node memory in GB, 2: reserve in GB. */
						__( 'The budget is the memory of the node (%1$d GB) minus the reserve (%2$s GB). It is set by the node size on the Hosting nodes screen and by the setting "Memory reserve per node, in GB"; the setting "Memory overcommit" divides what each account commits.', 'beltoft-services' ),
						(int) $node['mem_gb'],
						(string) $reserve
					),
				),
				'disk'   => array(
					$u['disk_committed'],
					$u['disk_budget'],
					sprintf(
						/* translators: %d: node disk in GB. */
						__( 'The budget is the disk of the node (%d GB), set on the Hosting nodes screen; the setting "Disk overcommit" divides what each account commits.', 'beltoft-services' ),
						(int) $node['disk_gb']
					),
				),
			);

			foreach ( $kinds as $kind => $row ) {
				if ( $row[1] <= 0 ) {
					continue;
				}

				$ok = Alerts::overage(
					'hosting:' . $id . ':' . $kind,
					$row[0] / $row[1],
					/* translators: 1: node id, 2: memory or disk. */
					sprintf( __( 'Hosting node %1$s is over its committed %2$s budget', 'beltoft-services' ), $id, self::kind_label( $kind ) ),
					array(
						/* translators: 1: node id, 2: memory or disk, 3: committed in GB, 4: budget in GB. */
						sprintf( __( 'Hosting node %1$s has %2$s committed to accounts: %3$.1f GB of a budget of %4$.1f GB.', 'beltoft-services' ), $id, self::kind_label( $kind ), $row[0] / 1024, $row[1] / 1024 ),
						$row[2],
						__( 'Nothing is stopped: new accounts are placed on the node with the most room left. Add a node or raise the capacity.', 'beltoft-services' ),
					)
				);

				if ( $ok ) {
					$sent[] = $id . ':' . $kind;
				}
			}
		}

		return $sent;
	}

	/**
	 * What the plugin counts as committed on a node, next to what the node reports itself, mailed when the real free
	 * memory or disk is under the threshold. $host comes from the agent's health answer (member `host`, see
	 * NodeHealth::check()); a member that is missing is not checked.
	 *
	 * @param string               $id   Node id.
	 * @param array<string, mixed> $host mem_total_mb, mem_free_mb, disk_total_gb, disk_free_gb.
	 * @return string[] Kinds a mail was sent for.
	 */
	public static function cross_check( string $id, array $host ): array {
		$u = self::usage( $id );

		if ( null === $u ) {
			return array();
		}

		$sent = array();

		if ( isset( $host['mem_total_mb'], $host['mem_free_mb'] ) && \BeltoftServices\Proxmox\Pve\CapacityCheck::report( 'hosting:' . $id, sprintf( /* translators: %s: node id. */ __( 'Hosting node %s', 'beltoft-services' ), $id ), 'memory', (float) $host['mem_total_mb'], (float) $host['mem_free_mb'], $u['memory_committed'], $u['memory_budget'] ) ) {
			$sent[] = 'memory';
		}

		if ( isset( $host['disk_total_gb'], $host['disk_free_gb'] ) && \BeltoftServices\Proxmox\Pve\CapacityCheck::report( 'hosting:' . $id, sprintf( /* translators: %s: node id. */ __( 'Hosting node %s', 'beltoft-services' ), $id ), 'disk', (float) $host['disk_total_gb'] * 1024, (float) $host['disk_free_gb'] * 1024, $u['disk_committed'], $u['disk_budget'] ) ) {
			$sent[] = 'disk';
		}

		return $sent;
	}

	/**
	 * The word for memory or disk in a mail, translated.
	 *
	 * @param string $kind memory or disk.
	 */
	public static function kind_label( string $kind ): string {
		return 'disk' === $kind ? __( 'disk', 'beltoft-services' ) : __( 'memory', 'beltoft-services' );
	}
}

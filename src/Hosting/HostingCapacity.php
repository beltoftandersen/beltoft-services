<?php
/**
 * Capacity and placement of hosting accounts on nodes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Resource;
use BeltoftServices\Settings;

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
		$node = Nodes::all()[ $node_id ] ?? null;

		if ( null === $node ) {
			return null;
		}

		$memory = 0.0;
		$disk   = 0.0;
		$count  = 0;

		foreach ( Records::resource_ids( $node_id ) as $resource_id ) {
			$r = Resource::find( $resource_id );

			// A deleted or gone resource commits nothing; its record is released by its delete.
			if ( ! $r || 'deleted' === $r->get( 'state' ) ) {
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
		$to    = (string) Settings::get( 'admin_email' );
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

			if ( false !== get_transient( $key ) || ! is_email( $to ) ) {
				continue;
			}

			set_transient( $key, 1, DAY_IN_SECONDS );

			wp_mail(
				$to,
				sprintf( '[%s] Hosting node %s is filling up', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $id ),
				sprintf( "Hosting node %s has committed %d%% of its memory budget and %d%% of its disk.\nAdd a node or raise the capacity before more accounts are sold.\n", $id, (int) round( $mem * 100 ), (int) round( $disk * 100 ) )
			);

			$sent[] = (string) $id;
		}

		return $sent;
	}
}

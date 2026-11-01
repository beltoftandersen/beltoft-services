<?php
/**
 * The pay-as-you-go usage ledger.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Proxmox\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One row per resource, item and UTC hour (table {prefix}bs_usage). The unique key on
 * (resource_id, item, hour_bucket) makes writing idempotent. `order_id` stays NULL until a renewal
 * order bills the row. The amount of a row is quantity x rate (the rate is per unit and hour).
 */
final class Ledger {

	/**
	 * Table name.
	 */
	public static function table(): string {
		return Schema::tables()['usage'];
	}

	/**
	 * Start of the UTC hour a time falls in.
	 *
	 * @param int $time Unix time.
	 */
	public static function hour( int $time ): int {
		return $time - ( $time % HOUR_IN_SECONDS );
	}

	/**
	 * Writes one row; a row for the same resource, item and hour is left as it is.
	 *
	 * @param int    $resource_id Activation id of the resource.
	 * @param int    $user_id     Customer.
	 * @param string $item        server, backups, disk or snapshots.
	 * @param float  $quantity    Units.
	 * @param string $unit        Unit label.
	 * @param float  $rate        Price per unit and hour.
	 * @param int    $hour        Start of the hour (unix time).
	 * @return bool Whether a row was added.
	 */
	public static function record( int $resource_id, int $user_id, string $item, float $quantity, string $unit, float $rate, int $hour ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$done = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (resource_id, user_id, item, quantity, unit, rate, hour_bucket, created) VALUES (%d, %d, %s, %s, %s, %s, %s, %s)',
				self::table(),
				$resource_id,
				$user_id,
				$item,
				number_format( $quantity, 4, '.', '' ),
				$unit,
				number_format( $rate, 8, '.', '' ),
				gmdate( 'Y-m-d H:i:s', self::hour( $hour ) ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return 1 === (int) $done;
	}

	/**
	 * Hours already written for a resource since a time, as "item|unix hour" keys.
	 *
	 * @param int $resource_id Resource.
	 * @param int $since       Unix time.
	 * @return array<string, true>
	 */
	public static function written( int $resource_id, int $since ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT item, hour_bucket FROM %i WHERE resource_id = %d AND hour_bucket >= %s', self::table(), $resource_id, gmdate( 'Y-m-d H:i:s', $since ) ), ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['item'] . '|' . strtotime( $row['hour_bucket'] . ' UTC' ) ] = true;
		}

		return $out;
	}

	/**
	 * Rows of a customer that no order has billed yet, from hours that ended before $before.
	 *
	 * @param int $user_id Customer.
	 * @param int $before  Unix time (the start of an hour).
	 * @return array<int, array<string, mixed>>
	 */
	public static function unbilled( int $user_id, int $before ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND order_id IS NULL AND hour_bucket < %s ORDER BY resource_id, item, hour_bucket', self::table(), $user_id, gmdate( 'Y-m-d H:i:s', $before ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Customers with rows that no order has billed.
	 *
	 * @return int[]
	 */
	public static function open_users(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE order_id IS NULL', self::table() ) ) );
	}

	/**
	 * Claims rows for an order: only rows still unbilled are taken, so two runs never bill a row twice.
	 *
	 * @param int[] $ids      Row ids.
	 * @param int   $order_id Order.
	 * @return array<int, array<string, mixed>> The rows the order now holds (among $ids).
	 */
	public static function claim( array $ids, int $order_id ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		$in = implode( ',', $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; $in holds integers only.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET order_id = %d WHERE order_id IS NULL AND id IN ($in)", self::table(), $order_id ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d AND id IN ($in) ORDER BY resource_id, item, hour_bucket", self::table(), $order_id ), ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Frees the rows of an order (it was cancelled), so the next renewal bills them.
	 *
	 * @param int $order_id Order.
	 */
	public static function release( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET order_id = NULL WHERE order_id = %d', self::table(), $order_id ) );
	}

	/**
	 * What earlier orders have taken from a resource's item in a UTC calendar month (the amount of the rows they hold).
	 *
	 * @param int    $resource_id Resource.
	 * @param string $item        Item.
	 * @param int    $month_start Unix time of the first hour of the month.
	 * @param int    $month_end   Unix time of the first hour of the next month.
	 * @param int    $except      An order whose rows do not count.
	 */
	public static function billed( int $resource_id, string $item, int $month_start, int $month_end, int $except = 0 ): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$sum = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(quantity * rate) FROM %i WHERE resource_id = %d AND item = %s AND order_id IS NOT NULL AND order_id <> %d AND hour_bucket >= %s AND hour_bucket < %s', self::table(), $resource_id, $item, $except, gmdate( 'Y-m-d H:i:s', $month_start ), gmdate( 'Y-m-d H:i:s', $month_end ) ) );

		return (float) $sum;
	}

	/**
	 * Rows of a customer in a time range, newest hours last (for reports).
	 *
	 * @param int $user_id Customer.
	 * @param int $from    Unix time.
	 * @param int $to      Unix time (exclusive).
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $user_id, int $from, int $to ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND hour_bucket >= %s AND hour_bucket < %s ORDER BY resource_id, item, hour_bucket', self::table(), $user_id, gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}

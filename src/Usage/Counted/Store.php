<?php
/**
 * The table of counted usage events: one row per item a service reported (for example a processed order).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Counted;

use BeltoftServices\Proxmox\Vm\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Table {prefix}bs_usage_events. Two unique keys make writing idempotent: (resource, remote sequence) and
 * (resource, store, order id). `received_at` is the time the plugin received the event and is the billing time:
 * an hour that has been written to the ledger is never changed by a late event.
 *
 * States: `new` (received, not yet judged), `free` (inside the free intro), `held` (over the daily cap, waiting for
 * staff), `billable` (to be written to the ledger once old enough), `billed` (in the ledger), `credited` (taken out
 * of billing by staff, the row stays).
 */
final class Store {

	public const NEW      = 'new';
	public const FREE     = 'free';
	public const HELD     = 'held';
	public const BILLABLE = 'billable';
	public const BILLED   = 'billed';
	public const CREDITED = 'credited';

	/**
	 * Table name.
	 */
	public static function table(): string {
		return Schema::tables()['usage_events'];
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
	 * Adds an event. A second event with the same remote sequence, or the same store and order for the resource, is ignored.
	 *
	 * @param array<string, mixed> $event Fields: resource_id, user_id, remote_seq, store_key, odoo_key, woo_order_id, order_number,
	 *                                    order_date (Y-m-d H:i:s or empty), status, total, currency, odoo_order_name, source, received_at (unix), state, free_reason.
	 * @return bool Whether a row was added.
	 */
	public static function add( array $event ): bool {
		global $wpdb;

		$received = (int) ( $event['received_at'] ?? time() );
		$date     = (string) ( $event['order_date'] ?? '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$done = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (resource_id, user_id, remote_seq, store_key, odoo_key, woo_order_id, order_number, order_date, status, total, currency, odoo_order_name, source, received_at, hour_bucket, state, free_reason) VALUES (%d, %d, %d, %s, %s, %s, %s, NULLIF(%s, \'\'), %s, %s, %s, %s, %s, %s, %s, %s, %s)',
				...array_merge(
					array(
						self::table(),
						(int) $event['resource_id'],
						(int) ( $event['user_id'] ?? 0 ),
						(int) $event['remote_seq'],
						(string) $event['store_key'],
						(string) ( $event['odoo_key'] ?? '' ),
						(string) $event['woo_order_id'],
						(string) ( $event['order_number'] ?? '' ),
					),
					array( $date ),
					array(
						(string) ( $event['status'] ?? '' ),
						(string) ( $event['total'] ?? '' ),
						(string) ( $event['currency'] ?? '' ),
						(string) ( $event['odoo_order_name'] ?? '' ),
						(string) ( $event['source'] ?? '' ),
						gmdate( 'Y-m-d H:i:s', $received ),
						gmdate( 'Y-m-d H:i:s', self::hour( $received ) ),
						(string) ( $event['state'] ?? self::NEW ),
						(string) ( $event['free_reason'] ?? '' ),
					)
				)
			)
		);

		return 1 === (int) $done;
	}

	/**
	 * How many events a resource has, optionally in one state.
	 *
	 * @param int    $resource_id Resource.
	 * @param string $state       State, '' for all.
	 */
	public static function count( int $resource_id, string $state = '' ): int {
		global $wpdb;

		if ( '' === $state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %d', self::table(), $resource_id ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %d AND state = %s', self::table(), $resource_id, $state ) );
	}

	/**
	 * Events received in a period that were taken for billing (billable or billed): not held, not free, not credited.
	 *
	 * @param int $resource_id Resource.
	 * @param int $from        Unix time.
	 * @param int $to          Unix time (exclusive).
	 */
	public static function accepted_between( int $resource_id, int $from, int $to ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %d AND state IN (%s, %s) AND received_at >= %s AND received_at < %s', self::table(), $resource_id, self::BILLABLE, self::BILLED, gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) ) );
	}

	/**
	 * The highest remote sequence a resource has, or 0.
	 *
	 * @param int $resource_id Resource.
	 */
	public static function high( int $resource_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(remote_seq) FROM %i WHERE resource_id = %d', self::table(), $resource_id ) );
	}

	/**
	 * How many events a resource received since a time (the daily cap).
	 *
	 * @param int $resource_id Resource.
	 * @param int $since       Unix time.
	 */
	public static function received_since( int $resource_id, int $since ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %d AND received_at >= %s', self::table(), $resource_id, gmdate( 'Y-m-d H:i:s', $since ) ) );
	}

	/**
	 * Billable events of a resource, per hour, for hours that ended before a time. The caller passes the start of the hour
	 * (now minus the hold window), so every event of such an hour is old enough and no new event can arrive in it.
	 *
	 * @param int $resource_id Resource.
	 * @param int $before_hour Unix time: only events of hours before this one.
	 * @return array<int, array{hour_bucket: string, n: int}>
	 */
	public static function billable_hours( int $resource_id, int $before_hour ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT hour_bucket, COUNT(*) AS n FROM %i WHERE resource_id = %d AND state = %s AND hour_bucket < %s GROUP BY hour_bucket ORDER BY hour_bucket', self::table(), $resource_id, self::BILLABLE, gmdate( 'Y-m-d H:i:s', $before_hour ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many events of a resource were billed in hours of a month before a given hour: the running count the price steps start from.
	 *
	 * @param int $resource_id Resource.
	 * @param int $month_start Unix time, first second of the UTC month.
	 * @param int $before_hour Unix time: hours before this one only.
	 */
	public static function billed_in_month( int $resource_id, int $month_start, int $before_hour ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %d AND state = %s AND hour_bucket >= %s AND hour_bucket < %s', self::table(), $resource_id, self::BILLED, gmdate( 'Y-m-d H:i:s', $month_start ), gmdate( 'Y-m-d H:i:s', $before_hour ) ) );
	}

	/**
	 * Marks the billable events of one hour as billed.
	 *
	 * @param int $resource_id Resource.
	 * @param int $hour        Start of the hour (unix time).
	 * @return int Rows changed.
	 */
	public static function mark_billed( int $resource_id, int $hour ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = %s, billed_hour = %s WHERE resource_id = %d AND state = %s AND hour_bucket = %s', self::table(), self::BILLED, gmdate( 'Y-m-d H:i:s', $hour ), $resource_id, self::BILLABLE, gmdate( 'Y-m-d H:i:s', $hour ) ) );
	}

	/**
	 * The events of a resource, newest first.
	 *
	 * @param int $resource_id Resource.
	 * @param int $limit       Rows.
	 * @param int $offset      Skip.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $resource_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d ORDER BY received_at DESC, id DESC LIMIT %d OFFSET %d', self::table(), $resource_id, max( 1, $limit ), max( 0, $offset ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many events a resource has in each state.
	 *
	 * @param int $resource_id Resource.
	 * @return array<string, int> state => count.
	 */
	public static function states( int $resource_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT state, COUNT(*) AS n FROM %i WHERE resource_id = %d GROUP BY state', self::table(), $resource_id ), ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['state'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Does a store and order already have an event for the resource?
	 *
	 * @param int    $resource_id  Resource.
	 * @param string $store_key    Store.
	 * @param string $woo_order_id Order id.
	 */
	public static function has_order( int $resource_id, string $store_key, string $woo_order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %d AND store_key = %s AND woo_order_id = %s', self::table(), $resource_id, $store_key, $woo_order_id ) ) > 0;
	}
}

<?php
/**
 * Who held which address, and when.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * A log of address holdings, public (IpPool) and private (PrivateNets): a row opens when a resource
 * takes an address and closes when it gives it back. It is bookkeeping for abuse reports only:
 * nothing reads it to allocate, and a failed write never stops an allocation. Times are UTC.
 */
final class IpHistory {

	/**
	 * Opens a holding.
	 *
	 * @param string $kind        'public' or 'private'.
	 * @param string $address     Address.
	 * @param int    $resource_id Activation id.
	 */
	public static function start( string $kind, string $address, int $resource_id ): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own log table.
		$wpdb->insert(
			Schema::tables()['ip_log'],
			array(
				'kind'        => $kind,
				'address'     => $address,
				'resource_id' => $resource_id,
				'started_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Closes the open holdings of a resource.
	 *
	 * @param string $kind        'public' or 'private'.
	 * @param int    $resource_id Activation id.
	 */
	public static function end( string $kind, int $resource_id ): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own log table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET ended_at = %s WHERE kind = %s AND resource_id = %d AND ended_at IS NULL', Schema::tables()['ip_log'], gmdate( 'Y-m-d H:i:s' ), $kind, $resource_id ) );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Every holding of an address, oldest first.
	 *
	 * @param string $address Address.
	 * @return array<int, array{kind: string, address: string, resource_id: int, started_at: string, ended_at: ?string}>
	 */
	public static function for_address( string $address ): array {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an abuse lookup must be fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, address, resource_id, started_at, ended_at FROM %i WHERE address = %s ORDER BY started_at ASC, id ASC', Schema::tables()['ip_log'], $address ), ARRAY_A );
		$wpdb->suppress_errors( $suppress );

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'kind'        => (string) $row['kind'],
				'address'     => (string) $row['address'],
				'resource_id' => (int) $row['resource_id'],
				'started_at'  => (string) $row['started_at'],
				'ended_at'    => null === $row['ended_at'] ? null : (string) $row['ended_at'],
			);
		}

		return $out;
	}
}

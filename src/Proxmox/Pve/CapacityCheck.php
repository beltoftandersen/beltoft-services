<?php
/**
 * The nightly cross-check of what the plugin counts as committed against what the machines report.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

use BeltoftServices\Alerts;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Capacity never stops a sale (owner decision 2026-11-11), so the admin is told instead. This class compares the
 * committed share the plugin counts (virtual machines: the capacity cache of CapacityJobs; hosting: the records, see
 * Hosting\HostingCapacity) with what the node says it really has free, and mails when the real free memory or the
 * real free disk is under a threshold (settings `capacity_free_memory_percent` and `capacity_free_disk_percent`,
 * filter `beltoft_services_capacity_free_percent`). It reads only what the jobs already fetched: the capacity cache
 * (node memory in use, from the hourly node listing) and the storage reading of DiskCapacity (when disk products
 * are sold). It makes no remote call. Each node and kind is mailed at most once a day (the hold lasts 20 hours, a little
 * under the nightly period, so a standing problem is never skipped on alternate nights). A cluster whose capacity cache
 * is missing or stale on two nights in a row is reported once (see run()).
 */
final class CapacityCheck {

	/**
	 * Seconds a mail of the same node and kind is held back: 20 hours, under the nightly period.
	 */
	public const HOLD = 20 * HOUR_IN_SECONDS;

	/**
	 * Option with the nights in a row each cluster had no usable capacity data.
	 */
	public const GAPS_OPTION = 'beltoft_services_capacity_check_gaps';

	/**
	 * Percent free below which the admin is told, by kind (memory or disk).
	 *
	 * @param string $kind memory or disk.
	 */
	public static function threshold( string $kind ): float {
		$key   = 'disk' === $kind ? 'capacity_free_disk_percent' : 'capacity_free_memory_percent';
		$value = (float) Settings::get( $key );
		$value = $value > 0 ? $value : 10.0;

		/**
		 * The share of free memory or disk, in percent, under which the nightly cross-check mails the admin.
		 *
		 * @param float  $percent Percent free.
		 * @param string $kind    memory or disk.
		 */
		return (float) apply_filters( 'beltoft_services_capacity_free_percent', $value, $kind );
	}

	/**
	 * Mails the admin when the real free share is under the threshold: what is really free, what the plugin counts as
	 * committed and what its budget is. At most once a day per subject and kind.
	 *
	 * @param string $subject   What is measured, for the once-a-day key, for example "hosting:node-1".
	 * @param string $label     What it is called in the mail.
	 * @param string $kind      memory or disk.
	 * @param float  $total_mb  Real total in MB.
	 * @param float  $free_mb   Real free in MB.
	 * @param float  $committed Committed by the plugin, in MB.
	 * @param float  $budget    Budget of the plugin, in MB.
	 * @return bool Whether a mail was sent.
	 */
	public static function report( string $subject, string $label, string $kind, float $total_mb, float $free_mb, float $committed, float $budget ): bool {
		if ( $total_mb <= 0 ) {
			return false;
		}

		$free      = max( 0.0, min( 100.0, $free_mb / $total_mb * 100 ) );
		$word      = 'disk' === $kind ? __( 'disk', 'beltoft-services' ) : __( 'memory', 'beltoft-services' );
		$threshold = self::threshold( $kind );

		if ( $free >= $threshold ) {
			return false;
		}

		return Alerts::notify(
			/* translators: 1: node or cluster name, 2: memory or disk. */
			sprintf( __( '%1$s: real free %2$s is low', 'beltoft-services' ), $label, $word ),
			array(
				/* translators: 1: node or cluster name, 2: memory or disk, 3: percent free, 4: free in GB, 5: total in GB. */
				sprintf( __( '%1$s reports %3$.1f%% free %2$s: %4$.1f GB of %5$.1f GB.', 'beltoft-services' ), $label, $word, $free, $free_mb / 1024, $total_mb / 1024 ),
				/* translators: 1: committed in GB, 2: budget in GB. */
				sprintf( __( 'The plugin counts %1$.1f GB committed against a budget of %2$.1f GB.', 'beltoft-services' ), $committed / 1024, $budget / 1024 ),
				/* translators: %s: threshold in percent. */
				sprintf( __( 'You are told when the real free share is under %s%%: settings "Alert when real free memory is under" and "Alert when real free disk is under". Nothing is stopped; selling and placing go on.', 'beltoft-services' ), rtrim( rtrim( number_format( $threshold, 1, '.', '' ), '0' ), '.' ) ),
			),
			null,
			'beltoft_services_capacity_check_' . md5( $subject . '|' . $kind ),
			array( 'ttl' => self::HOLD )
		);
	}

	/**
	 * The nightly job for the virtual machine clusters: the node memory in use and the storage reading, both from the
	 * caches the hourly capacity job fills.
	 *
	 * @return string[] "cluster:node:kind" of each mail sent.
	 */
	public static function run(): array {
		$sent    = array();
		$reserve = max( 0, (int) Settings::get( 'host_reserve_gb' ) ) * 1024;

		$gaps = (array) get_option( self::GAPS_OPTION, array() );

		foreach ( Clusters::all() as $id => $cluster ) {
			$id   = (string) $id;
			$rows = Capacity::rows( $id );

			// No cache, or one the hourly job has not refreshed in an hour: nothing to compare. Two nights in a row is told once.
			if ( null === $rows ) {
				$gaps[ $id ] = (int) ( $gaps[ $id ] ?? 0 ) + 1;

				if ( 2 === $gaps[ $id ] ) {
					Alerts::quietly(
						static fn() => Alerts::notify(
							/* translators: %s: cluster id. */
							sprintf( __( 'Cluster %s: the capacity check has no data', 'beltoft-services' ), $id ),
							array(
								/* translators: %s: cluster id. */
								sprintf( __( 'The nightly capacity check had no usable node data for cluster %s on two nights in a row, so it could not compare the committed memory with what the nodes report.', 'beltoft-services' ), $id ),
								__( 'Check that the hourly capacity job runs and that the cluster answers. Nothing is stopped; selling and placing go on.', 'beltoft-services' ),
							)
						)
					);
				}

				continue;
			}

			unset( $gaps[ $id ] );

			foreach ( $rows as $row ) {
				$name = (string) ( $row['node'] ?? '' );
				$max  = (int) ( $row['maxmem'] ?? 0 ) / 1048576;

				if ( 'online' !== (string) ( $row['status'] ?? '' ) || $max <= 0 || ! in_array( $name, array_map( 'strval', (array) $cluster['nodes'] ), true ) ) {
					continue;
				}

				$committed = (int) ( $row['mem'] ?? 0 ) / 1048576 - $reserve;
				/* translators: 1: node name, 2: cluster id. */
				$label = sprintf( __( 'Node %1$s of cluster %2$s', 'beltoft-services' ), $name, $id );

				if ( isset( $row['mem_used'] ) && self::report( 'pve:' . $id . ':' . $name, $label, 'memory', $max, $max - (int) $row['mem_used'] / 1048576, $committed, $max - $reserve ) ) {
					$sent[] = $id . ':' . $name . ':memory';
				}

				$disk = '' !== (string) ( $cluster['storage'] ?? '' ) ? DiskCapacity::reading( $id, $name, (string) $cluster['storage'] ) : null;

				if ( null !== $disk && self::report( 'pve:' . $id . ':' . $name, $label, 'disk', $disk['total'] / 1048576, $disk['avail'] / 1048576, DiskCapacity::committed_gb( $id, $name ) * 1024, $disk['total'] / 1048576 ) ) {
					$sent[] = $id . ':' . $name . ':disk';
				}
			}
		}

		update_option( self::GAPS_OPTION, array_intersect_key( $gaps, Clusters::all() ), false );

		return $sent;
	}
}

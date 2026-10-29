<?php
/**
 * Continuity of a high reading over several hourly rrddata windows, shared by the abuse check
 * and the customer usage alerts (each with its own key prefix).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

final class Usage {
	/** Transient prefix of the customer CPU alert. */
	public const CPU_PREFIX = 'beltoft_services_proxmox_usage_cpu_';

	/**
	 * Since when the VM has been above a threshold, over several hourly readings. It is reset by
	 * a reading that ends below the threshold, and when the readings do not join up.
	 *
	 * @param string                                                     $prefix      Transient prefix.
	 * @param int                                                        $resource_id Resource id.
	 * @param array{first: int, last: int, longest: int, trailing: ?int} $reading     From ProxmoxJobs::analyse().
	 * @param int                                                        $keep_hours  How long the state is kept.
	 * @return int|null Timestamp, or null when the newest sample is below the threshold.
	 */
	public static function since( string $prefix, int $resource_id, array $reading, int $keep_hours ): ?int {
		$key = $prefix . $resource_id;

		if ( null === $reading['trailing'] ) {
			delete_transient( $key );
			return null;
		}

		$since = $reading['trailing'];
		$prev  = get_transient( $key );

		// The run reaches back to the first sample, and the earlier reading ended where this one begins.
		if ( is_array( $prev ) && isset( $prev['since'], $prev['last'] ) && $reading['trailing'] === $reading['first'] && (int) $prev['last'] >= $reading['first'] - ProxmoxJobs::CONTINUITY_SLACK ) {
			$since = min( $since, (int) $prev['since'] );
		}

		set_transient( $key, array( 'since' => $since, 'last' => $reading['last'] ), $keep_hours * HOUR_IN_SECONDS );

		return $since;
	}
}

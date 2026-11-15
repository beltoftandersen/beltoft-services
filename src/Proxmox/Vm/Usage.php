<?php
/**
 * Continuity of a high reading over several hourly rrddata windows, shared by the abuse check
 * and the customer usage alerts (each with its own key prefix).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

defined( 'ABSPATH' ) || exit;

final class Usage {
	/** Transient prefix of the customer CPU alert. */
	public const CPU_PREFIX = \BeltoftServices\Monitoring\Samples::CPU_PREFIX;

	/**
	 * Since when the VM has been above a threshold, over several hourly readings. It is reset by
	 * a reading that ends below the threshold, and when the readings do not join up.
	 *
	 * @param string                                                     $prefix      Transient prefix.
	 * @param int                                                        $resource_id Resource id.
	 * @param array{first: int, last: int, longest: int, trailing: ?int} $reading     From AbuseJobs::analyse().
	 * @param int                                                        $keep_hours  How long the state is kept.
	 * @return int|null Timestamp, or null when the newest sample is below the threshold.
	 */
	public static function since( string $prefix, int $resource_id, array $reading, int $keep_hours ): ?int {
		return \BeltoftServices\Monitoring\Samples::since( $prefix, $resource_id, $reading, $keep_hours );
	}
}

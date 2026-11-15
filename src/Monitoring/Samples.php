<?php
/**
 * Reading the rrd samples of a guest: the runs above a CPU threshold, and how long one has lasted over several hourly windows.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Monitoring;

defined( 'ABSPATH' ) || exit;

final class Samples {

	/** Transient prefix of the customer CPU alert (the same key as before the move, so a run in progress is kept). */
	public const CPU_PREFIX = 'beltoft_services_proxmox_usage_cpu_';

	/** Seconds two hourly readings may be apart and still join up. */
	public const CONTINUITY_SLACK = 900;

	/**
	 * The runs above a threshold in a window of rrd samples. The CPU of a sample is the fraction PVE gives, so 0.9 is 90 percent.
	 * A sample without a numeric `cpu` and `time` is a gap: it ends a run, and an unfinished newest sample does not count.
	 * The interval between samples is not assumed.
	 *
	 * @param array<int, mixed> $samples   Rows of rrddata.
	 * @param float             $threshold CPU percent.
	 * @return array{first: int, last: int, longest: int, trailing: ?int}|null Seconds. `trailing` is where the run that reaches the newest sample began. Null: no usable sample.
	 */
	public static function analyse( array $samples, float $threshold ): ?array {
		$usable = array();

		foreach ( array_values( $samples ) as $sample ) {
			$ok = is_array( $sample ) && isset( $sample['cpu'], $sample['time'] ) && is_numeric( $sample['cpu'] ) && is_numeric( $sample['time'] );

			$usable[] = $ok ? array( (int) $sample['time'], (float) $sample['cpu'] * 100 ) : null;
		}

		while ( array() !== $usable && null === end( $usable ) ) {
			array_pop( $usable );
		}

		if ( array() === array_filter( $usable ) ) {
			return null;
		}

		$run     = null;
		$longest = 0;
		$first   = null;
		$last    = 0;

		foreach ( $usable as $point ) {
			if ( null === $point ) {
				$run = null;
				continue;
			}

			$first ??= $point[0];
			$last    = $point[0];

			if ( $point[1] >= $threshold ) {
				$run     ??= $point[0];
				$longest   = max( $longest, $point[0] - $run );
			} else {
				$run = null;
			}
		}

		return array(
			'first'    => (int) $first,
			'last'     => $last,
			'longest'  => $longest,
			'trailing' => $run,
		);
	}

	/**
	 * Since when a guest has been above a threshold, over several hourly readings. It is reset by a reading that ends
	 * below the threshold, and when the readings do not join up.
	 *
	 * @param string                                                     $prefix      Transient prefix.
	 * @param int                                                        $resource_id Resource id.
	 * @param array{first: int, last: int, longest: int, trailing: ?int} $reading     From analyse().
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
		if ( is_array( $prev ) && isset( $prev['since'], $prev['last'] ) && $reading['trailing'] === $reading['first'] && (int) $prev['last'] >= $reading['first'] - self::CONTINUITY_SLACK ) {
			$since = min( $since, (int) $prev['since'] );
		}

		set_transient( $key, array( 'since' => $since, 'last' => $reading['last'] ), $keep_hours * HOUR_IN_SECONDS );

		return $since;
	}
}

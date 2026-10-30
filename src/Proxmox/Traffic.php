<?php
/** Outgoing traffic volume alerts to the admin. @package BeltoftServices */
namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Two signals on the `netout` of the hourly rrddata window the usage checks already read (no extra
 * Proxmox call): a sustained rate, and a sudden jump against the server's own recent hours. Admin alert only: volume
 * alone is often legitimate (backups, downloads, a popular site), so nothing is sent to the customer and nothing
 * is restricted.
 *
 * The `hour` window covers about 70 minutes, so the 7-day baseline is kept here: one average per hourly run,
 * a week of them, in a transient per resource.
 */
final class Traffic {
	public const HISTORY_PREFIX = 'beltoft_services_proxmox_traffic_hist_';

	/** Minutes of the sustained signal, in seconds. */
	public const SUSTAINED = 1800;

	/** A signal that stayed away this long re-arms. */
	public const REARM = 6 * HOUR_IN_SECONDS;

	/** Hourly averages needed before a jump is judged (a day). */
	public const MIN_HISTORY = 24;

	/** Fewest usable samples in the 30 minute and the 60 minute window. */
	private const MIN_SAMPLES_SUSTAINED = 20;
	private const MIN_SAMPLES_HOUR      = 45;

	/**
	 * Judges one reading of a running VM.
	 *
	 * @param Resource          $r       Resource.
	 * @param array<int, mixed> $samples Rows of rrddata (`hour`, AVERAGE).
	 * @param int|null          $now     Timestamp.
	 */
	public static function check( Resource $r, array $samples, ?int $now = null ): void {
		$now = $now ?? time();

		if ( 'on' !== Settings::get( 'traffic_alerts' ) || true === $r->get( 'linked' ) ) {
			return;
		}

		$points = self::points( $samples );

		if ( array() === $points ) {
			return;
		}

		$last      = $points[ count( $points ) - 1 ][0];
		$sustained = self::average( $points, $last - self::SUSTAINED, self::MIN_SAMPLES_SUSTAINED );
		$hour      = self::average( $points, $last - HOUR_IN_SECONDS, self::MIN_SAMPLES_HOUR );

		$limit     = Operations::effective_mbit( $r );
		$threshold = null !== $limit && $limit > 0
			? $limit * (int) Settings::get( 'traffic_alert_percent' ) / 100
			: (float) (int) Settings::get( 'traffic_alert_mbit' );

		$jump   = null;
		$median = null;

		if ( null !== $hour ) {
			$median = self::remember( $r->id(), $last, $hour );
			$floor  = (float) (int) Settings::get( 'traffic_jump_floor_mbit' );
			$factor = (int) Settings::get( 'traffic_jump_factor' );

			if ( null !== $median ) {
				$jump = $hour >= $floor && $hour >= $factor * $median;
			}
		}

		$signals = array();

		if ( null !== $sustained ) {
			$signals['sustained'] = array(
				'present'  => $sustained > $threshold,
				'measured' => sprintf( 'Outgoing traffic averaged %.1f Mbit/s over the last 30 minutes, above the limit of %.1f Mbit/s.', $sustained, $threshold ),
			);
		}

		if ( null !== $jump ) {
			$signals['jump'] = array(
				'present'  => $jump,
				'measured' => sprintf( 'Outgoing traffic averaged %.1f Mbit/s over the last hour, %d times or more the usual hour (median %.1f Mbit/s over the last 7 days).', (float) $hour, (int) Settings::get( 'traffic_jump_factor' ), (float) ( $median ?? 0 ) ),
			);
		}

		if ( array() === $signals ) {
			return;
		}

		$r->locked(
			static function () use ( $r, $signals, $now ): bool {
				if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) ) {
					return false;
				}

				$meta = array();

				foreach ( $signals as $kind => $signal ) {
					$notified = (int) $r->get( 'traffic_' . $kind . '_notified' );
					$clear    = (int) $r->get( 'traffic_' . $kind . '_clear' );

					if ( $signal['present'] ) {
						if ( $clear > 0 ) {
							$meta[ 'traffic_' . $kind . '_clear' ] = null;
						}

						if ( 0 === $notified && Alerts::traffic( $r, $kind, $signal['measured'] ) ) {
							$meta[ 'traffic_' . $kind . '_notified' ] = $now;
							Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Traffic alert (' . $kind . '): ' . $signal['measured'] );
						}
					} elseif ( $notified > 0 ) {
						if ( 0 === $clear ) {
							$meta[ 'traffic_' . $kind . '_clear' ] = $now;
						} elseif ( $now - $clear >= self::REARM ) {
							$meta[ 'traffic_' . $kind . '_notified' ] = null;
							$meta[ 'traffic_' . $kind . '_clear' ]    = null;
						}
					}
				}

				return array() === $meta || $r->set( $meta );
			}
		);
	}

	/**
	 * The usable samples as [time, bytes per second], oldest first. A row without numeric `time` and `netout` is skipped.
	 *
	 * @param array<int, mixed> $samples Rows of rrddata.
	 * @return array<int, array{0:int,1:float}>
	 */
	private static function points( array $samples ): array {
		$points = array();

		foreach ( $samples as $row ) {
			if ( is_array( $row ) && isset( $row['time'], $row['netout'] ) && is_numeric( $row['time'] ) && is_numeric( $row['netout'] ) && is_finite( (float) $row['netout'] ) ) {
				$points[] = array( (int) $row['time'], max( 0.0, (float) $row['netout'] ) );
			}
		}

		usort( $points, static fn( $a, $b ) => $a[0] <=> $b[0] );

		return $points;
	}

	/**
	 * Mean rate in Mbit/s of the samples from a time on.
	 *
	 * @param array<int, array{0:int,1:float}> $points Samples.
	 * @param int                              $from   Earliest time.
	 * @param int                              $min    Fewest samples for a verdict.
	 * @return float|null Null when there are too few.
	 */
	private static function average( array $points, int $from, int $min ): ?float {
		$sum = 0.0;
		$n   = 0;

		foreach ( $points as $point ) {
			if ( $point[0] >= $from ) {
				$sum += $point[1];
				++$n;
			}
		}

		return $n >= $min ? $sum / $n * 8 / 1000000 : null;
	}

	/**
	 * Stores this hour's average and returns the median of the earlier hours of the last 7 days.
	 *
	 * @param int   $resource_id Resource id.
	 * @param int   $at          Time of the newest sample.
	 * @param float $mbit        Average Mbit/s of the last hour.
	 * @return float|null Null until a day of hours is known.
	 */
	private static function remember( int $resource_id, int $at, float $mbit ): ?float {
		$key  = self::HISTORY_PREFIX . $resource_id;
		$old  = get_transient( $key );
		$kept = array();

		foreach ( is_array( $old ) ? $old : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry[0], $entry[1] ) && $entry[0] >= $at - WEEK_IN_SECONDS && $entry[0] <= $at - HOUR_IN_SECONDS / 2 ) {
				$kept[] = array( (int) $entry[0], (float) $entry[1] );
			}
		}

		$values = array_column( $kept, 1 );
		$median = null;

		if ( count( $values ) >= self::MIN_HISTORY ) {
			sort( $values );
			$mid    = intdiv( count( $values ), 2 );
			$median = count( $values ) % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
		}

		$kept[] = array( $at, $mbit );
		set_transient( $key, $kept, 8 * DAY_IN_SECONDS );

		return $median;
	}
}

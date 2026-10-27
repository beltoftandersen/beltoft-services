<?php
/** Shape Proxmox usage samples for customer charts. @package BeltoftServices */
namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

final class Metrics {
	public const RANGES = array( 'hour' => 'hour', 'day' => 'day', 'month' => 'month' );
	private const FIELDS = array( 'cpu', 'mem', 'maxmem', 'netin', 'netout', 'diskread', 'diskwrite' );
	private const STEPS = array( 'hour' => 60, 'day' => 60, 'month' => 1800 );

	/** Missing values remain gaps; each bucket averages only the samples that have a value. */
	public static function shape( array $rows, string $range ): array {
		if ( ! isset( self::RANGES[ $range ] ) ) {
			throw new \InvalidArgumentException( 'Unknown metrics range.' );
		}
		$valid = array_values( array_filter( $rows, static fn( $row ) => is_array( $row ) && isset( $row['time'] ) && is_numeric( $row['time'] ) ) );
		usort( $valid, static fn( $a, $b ) => (float) $a['time'] <=> (float) $b['time'] );
		$count = count( $valid );
		$bucket_size = max( 1, (int) ceil( $count / 120 ) );
		$points = array();
		foreach ( array_chunk( $valid, $bucket_size ) as $bucket ) {
			$point = array( 't' => (int) $bucket[ count( $bucket ) - 1 ]['time'] );
			foreach ( self::FIELDS as $field ) {
				$numbers = array();
				foreach ( $bucket as $row ) {
					if ( isset( $row[ $field ] ) && is_numeric( $row[ $field ] ) && is_finite( (float) $row[ $field ] ) ) {
						$numbers[] = (float) $row[ $field ];
					}
				}
				$point[ $field ] = $numbers ? array_sum( $numbers ) / count( $numbers ) : null;
				if ( 'cpu' === $field && null !== $point[ $field ] ) {
					$point[ $field ] = round( $point[ $field ] * 100, 1 );
				}
			}
			$points[] = $point;
		}
		return array( 'range' => $range, 'step' => self::STEPS[ $range ] * $bucket_size, 'points' => $points );
	}
}

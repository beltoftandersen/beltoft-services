<?php
/**
 * How many of the lookups of one daily sweep failed: a resolver that is down must not make a service lose its records.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * One counter per feature, in an option: the sweep resets it, every daily look adds the lookups it made and how many of them failed.
 * The engine asks tripped() before it starts a loss.
 */
final class DnsWatchHealth {

	private static function key( DnsWatchFeature $f ): string {
		return 'beltoft_services_dns_watch_health_' . $f->id();
	}

	/**
	 * Starts a new count (the sweep begins).
	 *
	 * @param DnsWatchFeature $f Feature.
	 */
	public static function reset( DnsWatchFeature $f ): void {
		update_option( self::key( $f ), array( 'total' => 0, 'bad' => 0, 'at' => time() ), false );
	}

	/**
	 * Adds lookups to the count.
	 *
	 * @param DnsWatchFeature $f     Feature.
	 * @param int             $bad   Lookups that failed.
	 * @param int             $total Lookups made.
	 */
	public static function note( DnsWatchFeature $f, int $bad, int $total ): void {
		$now = (array) get_option( self::key( $f ), array() );

		update_option(
			self::key( $f ),
			array(
				'total' => (int) ( $now['total'] ?? 0 ) + $total,
				'bad'   => (int) ( $now['bad'] ?? 0 ) + $bad,
				'at'    => (int) ( $now['at'] ?? time() ),
			),
			false
		);
	}

	/**
	 * Did more than the setting's percent of this sweep's lookups fail?
	 *
	 * @param DnsWatchFeature $f Feature.
	 */
	public static function tripped( DnsWatchFeature $f ): bool {
		return (bool) ( self::counts( $f )[2] );
	}

	/**
	 * The count.
	 *
	 * @param DnsWatchFeature $f Feature.
	 * @return array{0:int,1:int,2:bool} Failed, made, tripped.
	 */
	public static function counts( DnsWatchFeature $f ): array {
		$now   = (array) get_option( self::key( $f ), array() );
		$bad   = (int) ( $now['bad'] ?? 0 );
		$total = (int) ( $now['total'] ?? 0 );

		return array( $bad, $total, $bad > 0 && $total > 0 && $bad * 100 > DnsWatchSettings::sweep_fail_percent( $f->id() ) * $total );
	}
}

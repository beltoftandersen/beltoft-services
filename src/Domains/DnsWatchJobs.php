<?php
/**
 * The scheduled jobs of the DNS watch: one set per registered feature.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Background;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs::schedule_once, Jobs::ensure_cron, Jobs::recurring and Background::declare are the owners; nothing new is invented. The hook
 * names come from the feature, because a queued Action Scheduler action holds its hook name and arguments (the resource id).
 */
final class DnsWatchJobs {

	/**
	 * Hooks the jobs of every feature: the look while waiting, the daily look with its sweep, the second read of a loss. The callbacks
	 * are static methods that find the feature by the hook that fired, so hooking twice (a second boot) never doubles a job.
	 */
	public static function register(): void {
		foreach ( DnsWatch::features() as $feature ) {
			$id = $feature->id();

			add_action( $feature->hook( 'look' ), array( __CLASS__, 'on_look' ) );
			Background::declare(
				$feature->hook( 'look' ),
				array(
					'due'      => static fn(): array => self::due( $id ),
					'resource' => true,
					'label'    => $feature->label() . ' DNS look',
				)
			);

			add_action( $feature->hook( 'recheck_all' ), array( __CLASS__, 'on_recheck_all' ) );
			add_action( $feature->hook( 'recheck' ), array( __CLASS__, 'on_recheck' ) );
			// One look a day per service, queued by the daily sweep: claimed by the runners and covered when it crashes. No watchdog.
			Background::declare( $feature->hook( 'recheck' ), array( 'resource' => true, 'label' => $feature->label() . ' DNS daily look' ) );

			add_action( $feature->hook( 'confirm' ), array( __CLASS__, 'on_confirm' ) );
			Background::declare( $feature->hook( 'confirm' ), array( 'resource' => true, 'label' => $feature->label() . ' DNS loss confirmation' ) );
		}

		Jobs::recurring( array( __CLASS__, 'schedule' ) );
	}

	/**
	 * The recurring part: the sweep of every feature exists as a cron job (the times of day follow the setting).
	 */
	public static function schedule(): void {
		foreach ( DnsWatch::features() as $feature ) {
			self::schedule_recheck( $feature );
		}
	}

	/**
	 * The feature whose hook for a job is the one that fired.
	 *
	 * @param string $job look, recheck_all, recheck or confirm.
	 */
	private static function fired( string $job ): ?DnsWatchFeature {
		$hook = current_action();

		foreach ( DnsWatch::features() as $feature ) {
			if ( $feature->hook( $job ) === $hook ) {
				return $feature;
			}
		}

		return null;
	}

	/** @param mixed $resource_id Resource id. */
	public static function on_look( $resource_id ): void {
		$f = self::fired( 'look' );

		if ( $f ) {
			DnsWatch::run( $f, (int) $resource_id );
		}
	}

	/** The daily sweep. */
	public static function on_recheck_all(): void {
		$f = self::fired( 'recheck_all' );

		if ( $f ) {
			DnsWatch::recheck_all( $f );
		}
	}

	/** @param mixed $resource_id Resource id. */
	public static function on_recheck( $resource_id ): void {
		$f = self::fired( 'recheck' );

		if ( $f ) {
			DnsWatch::recheck( $f, (int) $resource_id );
		}
	}

	/** @param mixed $resource_id Resource id. */
	public static function on_confirm( $resource_id ): void {
		$f = self::fired( 'confirm' );

		if ( $f ) {
			DnsWatch::confirm( $f, (int) $resource_id );
		}
	}

	/**
	 * Schedules the sweep of one feature once (the times of day follow the setting).
	 *
	 * @param DnsWatchFeature $feature Feature.
	 */
	public static function schedule_recheck( DnsWatchFeature $feature ): void {
		Jobs::ensure_cron( $feature->hook( 'recheck_all' ), DnsWatchSettings::recheck_cron( $feature->id() ), array(), HOUR_IN_SECONDS );
	}

	/**
	 * Queues one read for a resource, unless one is pending.
	 *
	 * @param DnsWatchFeature $feature Feature.
	 * @param int             $id      Resource id.
	 * @param int             $delay   Seconds from now.
	 */
	public static function look( DnsWatchFeature $feature, int $id, int $delay ): void {
		Jobs::schedule_once( time() + $delay, $feature->hook( 'look' ), array( $id ) );
	}

	/**
	 * The second read of a lost record, after the confirm minutes (or in a minute when the lock was busy).
	 *
	 * @param DnsWatchFeature $feature Feature.
	 * @param int             $id      Resource id.
	 * @param int             $delay   Seconds from now.
	 */
	public static function confirm( DnsWatchFeature $feature, int $id, int $delay ): void {
		Jobs::schedule_once( time() + $delay, $feature->hook( 'confirm' ), array( $id ) );
	}

	/**
	 * The watchdog's list: every service still waiting for records should have a read queued.
	 *
	 * @param string $feature_id Feature id.
	 * @return array<int, array<int, int>>
	 */
	public static function due( string $feature_id ): array {
		$f = DnsWatch::feature( $feature_id );

		if ( ! $f ) {
			return array();
		}

		return array_map( static fn( $id ): array => array( (int) $id ), Resource::find_by_meta( $f->kind(), $f->prefix(), 'waiting' ) );
	}
}

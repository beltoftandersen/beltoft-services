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
	 * Hooks the jobs of every feature: the look while waiting, the daily look with its sweep, the second read of a loss.
	 */
	public static function register(): void {
		foreach ( DnsWatch::features() as $feature ) {
			$id = $feature->id();

			add_action(
				$feature->hook( 'look' ),
				static function ( $resource_id ) use ( $id ): void {
					$f = DnsWatch::feature( $id );

					if ( $f ) {
						DnsWatch::run( $f, (int) $resource_id );
					}
				}
			);
			Background::declare(
				$feature->hook( 'look' ),
				array(
					'due'      => static fn(): array => self::due( $id ),
					'resource' => true,
					'label'    => $feature->label() . ' DNS look',
				)
			);

			add_action(
				$feature->hook( 'recheck_all' ),
				static function () use ( $id ): void {
					$f = DnsWatch::feature( $id );

					if ( $f ) {
						DnsWatch::recheck_all( $f );
					}
				}
			);
			add_action(
				$feature->hook( 'recheck' ),
				static function ( $resource_id ) use ( $id ): void {
					$f = DnsWatch::feature( $id );

					if ( $f ) {
						DnsWatch::recheck( $f, (int) $resource_id );
					}
				}
			);
			// One look a day per service, queued by the daily sweep: claimed by the runners and covered when it crashes. No watchdog.
			Background::declare( $feature->hook( 'recheck' ), array( 'resource' => true, 'label' => $feature->label() . ' DNS daily look' ) );
			Jobs::recurring(
				static function () use ( $id ): void {
					$f = DnsWatch::feature( $id );

					if ( $f ) {
						self::schedule_recheck( $f );
					}
				}
			);

			add_action(
				$feature->hook( 'confirm' ),
				static function ( $resource_id ) use ( $id ): void {
					$f = DnsWatch::feature( $id );

					if ( $f ) {
						DnsWatch::confirm( $f, (int) $resource_id );
					}
				}
			);
			Background::declare( $feature->hook( 'confirm' ), array( 'resource' => true, 'label' => $feature->label() . ' DNS loss confirmation' ) );
		}
	}

	/**
	 * Schedules the sweep once (the times of day follow the setting).
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

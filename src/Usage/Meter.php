<?php
/**
 * The hourly meter: writes the ledger rows of pay-as-you-go resources.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftLicenses\Install;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Runs hourly (Action Scheduler, group beltoft-services). For every resource of a license with source
 * `usage` it writes, for each full UTC hour it existed in, one row per item (Rates::items()). A resource
 * exists from its activation to its close; one in state deleted that is not closed yet is left out. A
 * server counts in any state but deleted, running or stopped, from the hour it first was ready (a server that
 * failed to provision, or is still being set up, bills nothing). Writing is idempotent (unique key), and an
 * hour that was missed is written by the next run, up to BACKFILL_HOURS back.
 */
final class Meter {

	public const HOOK = 'beltoft_services_usage_meter';

	/**
	 * Five minutes past each hour, so the hour that ended is complete.
	 */
	public const CRON = '5 * * * *';

	/**
	 * Hours the meter looks back.
	 */
	public const BACKFILL_HOURS = 48;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
	}

	/**
	 * Resource meta: when a pay-as-you-go resource first reached ready (unix time). Nothing is billed before it.
	 */
	public const READY_AT = 'usage_ready_at';

	/**
	 * Records the first time a pay-as-you-go resource is ready.
	 *
	 * @param mixed $r   Resource.
	 * @param mixed $old Previous state.
	 * @param mixed $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( 'ready' === $new && $r instanceof Resource && null === $r->get( self::READY_AT ) && 'usage' === (string) ( $r->license()['source'] ?? '' ) ) {
			$r->set( array( self::READY_AT => time() ) );
		}
	}

	/**
	 * Schedules the hourly run, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::CRON, self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * Removes the schedule (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * Writes the missing rows.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return int Rows written.
	 */
	public static function run( $now = null ): int {
		global $wpdb;

		$now     = is_int( $now ) ? $now : time();
		$last    = Ledger::hour( $now ) - HOUR_IN_SECONDS; // The last full hour.
		$horizon = $last - ( self::BACKFILL_HOURS - 1 ) * HOUR_IN_SECONDS;
		$tables  = Install::tables();
		$written = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.activated_at, a.deactivated_at FROM %i a INNER JOIN %i l ON l.id = a.license_id WHERE a.kind <> 'site' AND l.source = 'usage' AND ( a.deactivated_at IS NULL OR a.deactivated_at >= %s ) ORDER BY a.id",
				$tables['activations'],
				$tables['licenses'],
				gmdate( 'Y-m-d H:i:s', $horizon )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$written += self::meter( (int) $row['id'], $row, $horizon, $last );
		}

		return $written;
	}

	/**
	 * Writes one resource's missing rows.
	 *
	 * @param int                  $id      Activation id.
	 * @param array<string, mixed> $row     Its activation columns.
	 * @param int                  $horizon First hour to look at.
	 * @param int                  $last    Last full hour.
	 * @return int Rows written.
	 */
	private static function meter( int $id, array $row, int $horizon, int $last ): int {
		$resource = Resource::find( $id );
		$closed   = null !== $row['deactivated_at'];

		if ( ! $resource || ! $resource->is_valid() || ( ! $closed && 'deleted' === $resource->get( 'state' ) ) ) {
			return 0;
		}

		$license = $resource->license();
		$user_id = (int) ( $license['user_id'] ?? 0 );
		$ready   = $resource->get( self::READY_AT );

		// Billing starts in the hour the resource first was ready. One that never got ready (failed or still being set up) bills nothing. One that was ready before the time was recorded (state ready, paused or deleting) starts at its activation.
		if ( null === $ready ) {
			if ( ! in_array( $resource->get( 'state' ), array( 'ready', 'paused', 'deleting', 'deleted' ), true ) ) {
				return 0;
			}

			$ready = (int) strtotime( $row['activated_at'] . ' UTC' );
		}

		$start   = max( $horizon, Ledger::hour( (int) strtotime( $row['activated_at'] . ' UTC' ) ), Ledger::hour( (int) $ready ) );
		$end     = $closed ? min( $last, Ledger::hour( (int) strtotime( $row['deactivated_at'] . ' UTC' ) ) ) : $last;

		if ( $user_id < 1 || $start > $end ) {
			return 0;
		}

		$items = Rates::items( $resource );

		if ( ! $items ) {
			return 0;
		}

		$have    = Ledger::written( $id, $start );
		$written = 0;

		for ( $hour = $start; $hour <= $end; $hour += HOUR_IN_SECONDS ) {
			foreach ( $items as $item ) {
				if ( isset( $have[ $item['item'] . '|' . $hour ] ) ) {
					continue;
				}

				if ( Ledger::record( $id, $user_id, $item['item'], $item['quantity'], $item['unit'], $item['rate'], $hour ) ) {
					++$written;
				}
			}
		}

		return $written;
	}
}

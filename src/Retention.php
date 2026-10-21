<?php
/**
 * Deletes paused services after the product's retention period.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Events;
use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * A daily Action Scheduler job. Only a service that is still meant to be paused, on a
 * license that is not active, is not protected and whose product keeps services for a
 * number of days (0 means forever) is considered. The setting "deletion_mode" decides:
 *
 * - off (the default): nothing happens;
 * - report: the history says once what would happen ("Would warn", "Would delete");
 * - on: warning_days before the end of the retention period the customer is warned once
 *   (action beltoft_services_deletion_warning, meta warned_at), and once the period is over
 *   the deletion is requested, with a fresh service to follow if the license is renewed
 *   while it runs.
 *
 * A service is never deleted with less notice than warning_days: one that was never warned
 * (deletion turned on late) is warned first and deleted warning_days later. A warning sent
 * during an earlier pause does not count.
 *
 * Resources are read in batches of 50 in id order, continuing after the last id seen, so
 * the run always ends (Medusa's warning job could select the same batch forever).
 */
final class Retention {

	public const HOOK         = 'beltoft_services_retention';
	public const CRON         = '30 3 * * *';
	public const BATCH        = 50;
	public const WARNING_HOOK = 'beltoft_services_deletion_warning';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily run, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::CRON, self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * Removes the daily run (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * The daily run.
	 */
	public static function run(): void {
		$mode = (string) Settings::get( 'deletion_mode' );

		if ( 'report' !== $mode && 'on' !== $mode ) {
			return;
		}

		foreach ( Registry::kinds() as $kind ) {
			$last = 0;

			do {
				$ids  = self::batch( $kind, $last );
				$full = self::BATCH === count( $ids );

				foreach ( $ids as $id ) {
					$last     = $id;
					$resource = Resource::find( $id );

					if ( $resource ) {
						self::check( $resource, $mode );
					}
				}
			} while ( $full );
		}
	}

	/**
	 * The next live resources of a kind after an id.
	 *
	 * @param string $kind  Activation kind.
	 * @param int    $after Last id seen.
	 * @return int[]
	 */
	private static function batch( string $kind, int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE kind = %s AND deactivated_at IS NULL AND id > %d ORDER BY id ASC LIMIT %d', Install::tables()['activations'], $kind, $after, self::BATCH ) );

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Warns, reports or requests the deletion of one resource, if it is due.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $mode report or on.
	 */
	private static function check( Resource $r, string $mode ): void {
		if ( ! $r->is_valid() || ! $r->is_live() || $r->get( 'protected' ) || 'paused' !== $r->get( 'desired' ) ) {
			return;
		}

		$paused_at = $r->get( 'paused_at' );

		// A desired state waiting for the lock (a renewal, say) is applied first.
		if ( null === $paused_at || null !== $r->pending_desire() ) {
			return;
		}

		$license = $r->license();

		if ( ! $license || Licenses::STATUS_ACTIVE === Licenses::effective_status( $license ) ) {
			return;
		}

		$days = Product::retention_days( (int) ( $license['product_id'] ?? 0 ) );

		if ( $days <= 0 ) {
			return;
		}

		$now     = time();
		$notice  = max( 0, (int) Settings::get( 'warning_days' ) ) * DAY_IN_SECONDS;
		$ends_at = $paused_at + $days * DAY_IN_SECONDS;
		$warned  = $r->get( 'warned_at' );

		// A warning from an earlier pause does not count.
		if ( null !== $warned && $warned < $paused_at ) {
			$warned = null;
		}

		if ( $notice > 0 && null === $warned ) {
			if ( $now >= $ends_at - $notice ) {
				self::warn( $r, $mode, $paused_at, max( $ends_at, $now + $notice ) );
			}
			return;
		}

		$deletes_at = null === $warned ? $ends_at : max( $ends_at, $warned + $notice );

		if ( $now < $deletes_at ) {
			return;
		}

		if ( 'report' === $mode ) {
			self::report( $r, $paused_at, 'delete', 'Would delete the service: its retention period is over (deletion mode is report only).' );
			return;
		}

		// Replace: a renewal while the deletion runs gets a fresh service.
		if ( $r->desire( 'deleted', 'Retention period over', true ) ) {
			Jobs::enqueue( $r->id() );
		}
		// False: queued for the lock, or not stored (the admin was alerted). The next run retries.
	}

	/**
	 * Warns the customer once (on), or reports that it would (report).
	 *
	 * @param Resource $r          Resource.
	 * @param string   $mode       report or on.
	 * @param int      $paused_at  The paused_at the decision was made on.
	 * @param int      $deletes_at When the service will be deleted.
	 */
	private static function warn( Resource $r, string $mode, int $paused_at, int $deletes_at ): void {
		$date = gmdate( 'Y-m-d H:i', $deletes_at );

		if ( 'report' === $mode ) {
			self::report( $r, $paused_at, 'warn', sprintf( 'Would warn the customer: the service would be deleted on %s UTC (deletion mode is report only).', $date ) );
			return;
		}

		// Stored first: a warning whose record could not be written is not sent, so it is
		// never sent twice.
		if ( ! $r->record_retention( array( 'warned_at' => time() ), $paused_at ) ) {
			return;
		}

		Events::add( (int) $r->license()['id'], 'service', sprintf( 'Deletion warning: the service will be deleted on %s UTC.', $date ) );

		try {
			/**
			 * Fires once when a paused service will be deleted after its retention period.
			 * Hook the customer's warning email here. The service is deleted at $deletes_at
			 * at the earliest, unless the license is active again before then.
			 *
			 * @param Resource $r          The resource.
			 * @param int      $deletes_at Unix time of the deletion.
			 */
			do_action( self::WARNING_HOOK, $r, $deletes_at );
		} catch ( \Throwable $e ) {
			// One broken listener must not stop the run for every other service.
			Events::add( (int) $r->license()['id'], 'service', 'A deletion warning listener failed.' );
		}
	}

	/**
	 * Writes a report line once per stage and pause.
	 *
	 * @param Resource $r         Resource.
	 * @param int      $paused_at The paused_at the decision was made on.
	 * @param string   $stage     warn or delete.
	 * @param string   $message   History text.
	 */
	private static function report( Resource $r, int $paused_at, string $stage, string $message ): void {
		$key = $stage . ':' . $paused_at;

		if ( (string) $r->get( 'reported' ) === $key ) {
			return;
		}

		if ( $r->record_retention( array( 'reported' => $key ), $paused_at ) ) {
			Events::add( (int) $r->license()['id'], 'service', $message );
		}
	}
}

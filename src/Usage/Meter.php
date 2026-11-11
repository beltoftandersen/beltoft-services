<?php
/**
 * The hourly meter: writes the ledger rows of pay-as-you-go resources.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Currency;
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
	 * Resource meta: the first hour a resource could not be metered (no rate in its account's currency, or the currency could not be
	 * put in force), as unix time. The next successful run starts there instead of BACKFILL_HOURS back, and clears it.
	 */
	public const UNMETERED_SINCE = 'usage_unmetered_since';

	/**
	 * The most hours a gap is caught up: 45 days, a month of billing and slack. A resource unmetered for longer than that has a
	 * configuration fault, not a late price; the hours beyond it are not written and the admin is told once.
	 */
	public const MAX_CATCHUP_HOURS = 1080;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
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
		Jobs::ensure_cron( self::HOOK, self::CRON );
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
		$now     = is_int( $now ) ? $now : time();
		$last    = Ledger::hour( $now ) - HOUR_IN_SECONDS; // The last full hour.
		$horizon = $last - ( self::BACKFILL_HOURS - 1 ) * HOUR_IN_SECONDS;
		$written = 0;

		self::$currencies = array();

		$rows = Licenses::resources_of_source( 'usage', gmdate( 'Y-m-d H:i:s', $horizon ) );

		$seen = array();

		foreach ( (array) $rows as $row ) {
			$seen[ (int) $row['id'] ] = true;
			$written                 += self::meter( (int) $row['id'], $row, $horizon, $last );
		}

		// A resource that carries a marker is owed hours even when it was closed long ago (it is not listed above any more).
		foreach ( Resource::ids_with_meta_value( self::UNMETERED_SINCE ) as $id ) {
			$id  = (int) $id;
			$row = isset( $seen[ $id ] ) ? null : Licenses::activation( $id );
			$own = $row ? Resource::find( $id ) : null;

			if ( $row && $own && 'usage' === (string) ( $own->license()['source'] ?? '' ) ) {
				$written += self::meter( $id, $row, $horizon, $last );
			}
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

		// Billing starts in the hour the resource first was ready, which is recorded when it gets there. One that never got
		// ready bills nothing: a failed build, or a placeholder that was paused or closed (marked deleted) before it was built.
		if ( null === $ready ) {
			return 0;
		}

		// A resource with a marker (it could not be metered for a while) starts at its first unmetered hour, within MAX_CATCHUP_HOURS,
		// also when it was closed long ago: the gap is still owed. Others look back BACKFILL_HOURS.
		$since = $resource->get( self::UNMETERED_SINCE );
		$floor = $last - ( self::MAX_CATCHUP_HOURS - 1 ) * HOUR_IN_SECONDS;
		$first = max( Ledger::hour( (int) strtotime( $row['activated_at'] . ' UTC' ) ), Ledger::hour( (int) $ready ) );
		$start = null !== $since ? max( $floor, $first, Ledger::hour( (int) $since ) ) : max( $horizon, $first );
		$end   = $closed ? min( $last, Ledger::hour( (int) strtotime( $row['deactivated_at'] . ' UTC' ) ) ) : $last;

		if ( $user_id < 1 || $start > $end ) {
			// Nothing of the gap is left to write (it ended before the marker): the gap is closed.
			if ( null !== $since && $user_id > 0 ) {
				$resource->set( array( self::UNMETERED_SINCE => null ) );
			}

			return 0;
		}

		// The rates are read in the currency of the customer's usage account, whatever the job runs in.
		$currency = self::currency_of( $license, $user_id );
		list( $ok, $items ) = Currency::with( $currency, static fn() => Rates::items( $resource ) );

		// Fail closed: no rate is read under a currency we did not get, and a currency with no price meters nothing. Either way the first
		// unmetered hour is kept, so the hours are written once metering works (a base currency with no items stays silent, as before).
		$reason = '';

		if ( ! $ok ) {
			$reason = 'The currency of the usage account could not be used for the rates.';
		} elseif ( ! $items && Currency::base() !== $currency ) {
			$reason = 'The product has no price in that currency, so nothing is metered.';
		}

		if ( '' !== $reason ) {
			if ( null === $resource->get( self::UNMETERED_SINCE ) ) {
				$last_row = Ledger::last_hour( $id );
				$first    = max( Ledger::hour( (int) strtotime( $row['activated_at'] . ' UTC' ) ), Ledger::hour( (int) $ready ) );

				$resource->set( array( self::UNMETERED_SINCE => null !== $last_row ? max( $first, $last_row + HOUR_IN_SECONDS ) : $first ) );
			}

			self::alert( $resource, $currency, $reason );

			return 0;
		}

		if ( ! $items ) {
			return 0;
		}

		// A gap is caught up from its first hour, not only 48 hours back, within MAX_CATCHUP_HOURS.
		if ( null !== $since ) {
			if ( (int) $since < $floor ) {
				Alerts::notify(
					/* translators: %d: resource id */
					sprintf( __( 'Usage hours not caught up for resource %d', 'beltoft-services' ), $id ),
					array( sprintf( 'Resource %1$d was unmetered since %2$s, longer than %3$d hours. Only the last %3$d hours are written; the older ones are not billed.', $id, gmdate( 'Y-m-d H:i', (int) $since ), self::MAX_CATCHUP_HOURS ) ),
					$resource,
					'beltoft_services_usage_gap_' . $id
				);
			}
		}

		$have    = Ledger::written( $id, $start );
		$written = 0;

		for ( $hour = $start; $hour <= $end; $hour += HOUR_IN_SECONDS ) {
			foreach ( $items as $item ) {
				if ( isset( $have[ $item['item'] . '|' . $hour ] ) ) {
					continue;
				}

				if ( Ledger::record( $id, $user_id, $item['item'], $item['quantity'], $item['unit'], $item['rate'], $hour, $currency ) ) {
					++$written;
				}
			}
		}

		// The gap is closed only when every hour of it is in the ledger: an insert that failed keeps the marker for the next run.
		if ( null !== $since ) {
			$have = Ledger::written( $id, $start );

			for ( $hour = $start; $hour <= $end; $hour += HOUR_IN_SECONDS ) {
				foreach ( $items as $item ) {
					if ( ! isset( $have[ $item['item'] . '|' . $hour ] ) ) {
						return $written;
					}
				}
			}

			$resource->set( array( self::UNMETERED_SINCE => null ) );
		}

		return $written;
	}

	/** @var array<int, string> Currency per usage account subscription id, for one run. */
	private static array $currencies = array();

	/**
	 * The currency a resource is metered in: that of the usage account its licence is on, else the customer's usage account, else the shop's.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param int                  $user_id Customer.
	 */
	private static function currency_of( array $license, int $user_id ): string {
		$sub_id = (int) ( $license['subscription_id'] ?? 0 );
		$key    = $sub_id > 0 ? $sub_id : -$user_id;

		if ( ! isset( self::$currencies[ $key ] ) ) {
			$account = $sub_id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : false;
			$account = $account instanceof \WC_Subscription ? $account : Account::for_user( $user_id );
			$code    = $account ? Currency::clean( $account->get_currency() ) : '';

			self::$currencies[ $key ] = '' !== $code ? $code : Currency::base();
		}

		return self::$currencies[ $key ];
	}

	/**
	 * Tells the admin once per resource and currency that a resource is not metered.
	 *
	 * @param Resource $resource Resource.
	 * @param string   $currency Currency code.
	 * @param string   $reason   Why.
	 */
	private static function alert( Resource $resource, string $currency, string $reason ): void {
		Alerts::notify(
			/* translators: %s: currency code */
			sprintf( __( 'Usage not metered in %s', 'beltoft-services' ), $currency ),
			array( sprintf( 'Resource %d is not metered in %s. %s', $resource->id(), $currency, $reason ) ),
			$resource,
			'beltoft_services_usage_currency_' . $resource->id() . '_' . strtolower( $currency )
		);
	}
}

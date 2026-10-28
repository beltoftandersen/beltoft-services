<?php
/**
 * Daily job that frees a domain name once the parent no longer lists our name servers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Jobs;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A DNS-only service that ended leaves its assignment in release_pending. The name stays locked
 * to that customer (no one else can host it) until two clear checks, at least 24 hours apart,
 * show that the parent zone does not delegate to us. For these rows reserved_until holds the time
 * of the first clear check. Nothing here touches a zone or a registrar.
 */
final class DnsReleaseJob {

	public const HOOK           = 'beltoft_services_dns_release';
	public const CRON           = '40 4 * * *';
	public const OPTION_ALERTED = 'beltoft_services_dns_release_alerted';
	public const OPTION_CURSOR  = 'beltoft_services_dns_release_cursor';
	public const OPTION_LOG     = 'beltoft_services_dns_release_log';
	public const MAX_PER_RUN    = 50;
	public const STALE_AFTER    = 30 * DAY_IN_SECONDS;
	public const CLEAR_GAP      = DAY_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily job, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::CRON, self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * Removes the daily job (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * One run: checks at most 50 rows, then reports the rows that wait too long.
	 */
	public static function run(): void {
		if ( ! DnsClaimsFeature::enabled() ) {
			return;
		}

		$rows = DnsAssignments::for_status( 'release_pending' );

		foreach ( self::batch( $rows ) as $row ) {
			self::check( $row );
		}

		self::report_stale( DnsAssignments::for_status( 'release_pending' ) );
	}

	/**
	 * Up to 50 rows, continuing after the last domain of the previous run so that no row starves.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows ordered by domain.
	 * @return array<int, array<string, mixed>>
	 */
	private static function batch( array $rows ): array {
		$cursor = (string) get_option( self::OPTION_CURSOR, '' );
		$after  = array_values( array_filter( $rows, static fn( $row ) => strcmp( (string) $row['domain'], $cursor ) > 0 ) );
		$before = array_values( array_filter( $rows, static fn( $row ) => strcmp( (string) $row['domain'], $cursor ) <= 0 ) );
		$batch  = array_slice( array_merge( $after, $before ), 0, self::MAX_PER_RUN );

		update_option( self::OPTION_CURSOR, $batch ? (string) end( $batch )['domain'] : '', false );

		return $batch;
	}

	/**
	 * One row.
	 *
	 * @param array<string, mixed> $row Assignment row.
	 */
	private static function check( array $row ): void {
		$domain = (string) $row['domain'];
		$result = self::delegation( $domain );
		$status = (string) ( $result['status'] ?? '' );
		$reason = (string) ( $result['reason'] ?? '' );
		$clear  = ( 'ok' === $status && empty( $result['ours'] ) ) || ( 'support' === $status && 'not_delegated' === $reason );

		if ( 'support' === $status && 'delegated_to_us' === $reason ) {
			if ( ! empty( $row['reserved_until'] ) ) {
				DnsAssignments::mark_clear( $domain, null );
			}
			return;
		}

		if ( ! $clear ) {
			return;
		}

		$first = empty( $row['reserved_until'] ) ? 0 : (int) strtotime( (string) $row['reserved_until'] . ' UTC' );

		if ( $first <= 0 ) {
			DnsAssignments::mark_clear( $domain, gmdate( 'Y-m-d H:i:s' ) );
			return;
		}

		if ( time() - $first < self::CLEAR_GAP ) {
			return;
		}

		// Re-read: only a row that is still being released is freed.
		$current = DnsAssignments::get( $domain );

		if ( null !== $current && 'release_pending' === $current['status'] && DnsAssignments::release( $domain ) ) {
			self::note( $domain, (int) $current['user_id'] );
		}
	}

	/**
	 * The delegation of a domain. Tests replace the result with the filter the claim jobs use.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>
	 */
	private static function delegation( string $domain ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- the same test-only filter as DnsClaimJobs.
		$result = apply_filters( 'beltoft_services_dns_proof_result', null, 'delegation', array( $domain ) );

		return is_array( $result ) ? $result : ProofResolver::delegation( $domain );
	}

	/**
	 * Leaves a note about a released name: an action for other code and a short log in an option.
	 *
	 * @param string $domain  Domain.
	 * @param int    $user_id Customer it was held for.
	 */
	private static function note( string $domain, int $user_id ): void {
		$log   = (array) get_option( self::OPTION_LOG, array() );
		$log[] = array(
			'time'    => time(),
			'domain'  => $domain,
			'user_id' => $user_id,
		);

		update_option( self::OPTION_LOG, array_slice( $log, -50 ), false );

		/**
		 * A domain name was released after the parent stopped listing our name servers.
		 *
		 * @param string $domain  Domain.
		 * @param int    $user_id Customer.
		 */
		do_action( 'beltoft_services_dns_released', $domain, $user_id );
	}

	/**
	 * One mail per row that has waited more than 30 days. The mail is remembered by domain and the
	 * row's updated_at, so a row that comes back later is reported again.
	 *
	 * @param array<int, array<string, mixed>> $rows release_pending rows.
	 */
	private static function report_stale( array $rows ): void {
		$alerted = (array) get_option( self::OPTION_ALERTED, array() );
		$keep    = array();

		foreach ( $rows as $row ) {
			$domain  = (string) $row['domain'];
			$updated = (string) $row['updated_at'];
			$since   = (int) strtotime( $updated . ' UTC' );

			if ( $since <= 0 || time() - $since <= self::STALE_AFTER ) {
				continue;
			}

			if ( ( $alerted[ $domain ] ?? '' ) === $updated ) {
				$keep[ $domain ] = $updated;
				continue;
			}

			if ( self::mail( $domain, (int) $row['user_id'] ) ) {
				$keep[ $domain ] = $updated;
			}
		}

		if ( $keep !== $alerted ) {
			update_option( self::OPTION_ALERTED, $keep, false );
		}
	}

	/**
	 * The admin mail: domain and customer id only.
	 *
	 * @param string $domain  Domain.
	 * @param int    $user_id Customer.
	 */
	private static function mail( string $domain, int $user_id ): bool {
		$to = (string) Settings::get( 'admin_email' );

		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		if ( ! is_email( $to ) ) {
			return false;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		return (bool) wp_mail(
			$to,
			sprintf( '[%s] DNS hosting name still locked after 30 days: %s', $site, $domain ),
			sprintf( "Domain: %s\nCustomer: %d\nThe name has waited more than 30 days for the parent zone to stop listing our name servers.\n", $domain, $user_id )
		);
	}
}

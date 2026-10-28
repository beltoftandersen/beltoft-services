<?php
/**
 * Background jobs of the DNS ownership claims: the check, the record scan and the daily cleanup.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Jobs;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every job does nothing while the feature is off. DnsClaims::set_status() does not check what it
 * moves a claim from, so every write here goes through transition(), which re-reads the claim.
 */
final class DnsClaimJobs {

	public const CHECK   = 'beltoft_services_dns_claim_check';
	public const SCAN    = 'beltoft_services_dns_claim_scan';
	public const CLEANUP = 'beltoft_services_dns_claims_cleanup';
	public const CRON    = '20 4 * * *';

	/**
	 * Name servers that did not answer this many checks in a row need a person.
	 */
	public const MAX_FAILURES = 3;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::CHECK, array( __CLASS__, 'check' ) );
		add_action( self::SCAN, array( __CLASS__, 'scan' ) );
		add_action( self::CLEANUP, array( __CLASS__, 'cleanup' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily cleanup, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::CLEANUP, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::CRON, self::CLEANUP, array(), Jobs::GROUP );
		}
	}

	/**
	 * Removes the daily cleanup (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CLEANUP, array(), Jobs::GROUP );
		}
	}

	/**
	 * Queues a job for a claim, unless one waits already.
	 *
	 * @param string $hook     CHECK or SCAN.
	 * @param int    $claim_id Claim id.
	 */
	public static function enqueue( string $hook, int $claim_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( $hook, array( $claim_id ), Jobs::GROUP ) ) {
			as_enqueue_async_action( $hook, array( $claim_id ), Jobs::GROUP );
		}
	}

	/**
	 * Moves a claim to a new state, only when it is in one of the states it may move from, and
	 * never to an active state once it is past its time.
	 *
	 * @param int                  $id     Claim id.
	 * @param string[]             $from   Allowed current statuses.
	 * @param string               $to     New status.
	 * @param array<string, mixed> $fields Other fields (see DnsClaims::set_status()).
	 */
	public static function transition( int $id, array $from, string $to, array $fields = array() ): bool {
		$claim = DnsClaims::get( $id );

		// Cancelled, expired and consumed claims are final.
		if ( ! $claim || ! in_array( $claim['status'], $from, true ) || in_array( $claim['status'], array( 'cancelled', 'expired', 'consumed' ), true ) ) {
			return false;
		}

		$active = ! in_array( $to, array( 'expired', 'cancelled', 'consumed' ), true );

		if ( $active && self::past( $claim ) ) {
			return false;
		}

		return DnsClaims::set_status( $id, $to, $fields );
	}

	/**
	 * Saves fields of a verified claim without changing its status or its expiry: only a new
	 * verification starts the 24 hour window again.
	 *
	 * @param int                  $id     Claim id.
	 * @param array<string, mixed> $fields Fields (see DnsClaims::set_status()).
	 */
	public static function update( int $id, array $fields ): bool {
		$claim = DnsClaims::get( $id );

		if ( ! $claim || 'verified' !== $claim['status'] || self::past( $claim ) ) {
			return false;
		}

		return DnsClaims::update_fields( $id, $fields );
	}

	/**
	 * Checks the TXT record of a claim that is being checked (a verified claim asked again is
	 * checked too and stays verified until the new result).
	 *
	 * @param int $claim_id Claim id.
	 */
	public static function check( $claim_id ): void {
		$claim_id = (int) $claim_id;
		$claim    = DnsClaimsFeature::enabled() ? DnsClaims::get( $claim_id ) : null;
		$states   = array( 'checking', 'verified' );

		if ( ! $claim || ! in_array( $claim['status'], $states, true ) ) {
			return;
		}

		if ( self::past( $claim ) ) {
			self::transition( $claim_id, $states, 'expired' );
			return;
		}

		$domain     = (string) $claim['domain'];
		$delegation = self::proof( 'delegation', array( $domain ) );
		$result     = 'ok' === ( $delegation['status'] ?? '' ) ? self::proof( 'check_txt', array( $domain, DnsClaims::txt_value( $claim ), $delegation ) ) : $delegation;
		$status     = (string) ( $result['status'] ?? 'unknown' );
		$reason     = (string) ( $result['reason'] ?? '' );
		$summary    = array(
			'status'  => $status,
			'reason'  => $reason,
			'servers' => (array) ( $result['servers'] ?? array() ),
		);
		$kept       = array_intersect_key( $claim['last_result'], array_flip( array( 'scan', 'zone_file' ) ) );
		$now        = gmdate( 'Y-m-d H:i:s' );

		if ( 'found' === $status ) {
			self::transition(
				$claim_id,
				$states,
				'verified',
				array(
					'parent_ns'      => (array) ( $delegation['ns'] ?? array() ),
					'ds_present'     => isset( $delegation['ds'] ) && null !== $delegation['ds'] ? (int) (bool) $delegation['ds'] : null,
					'last_result'    => array_merge( $kept, $summary ),
					'verified_at'    => $now,
					'checked_at'     => $now,
					'check_failures' => 0,
				)
			);
			return;
		}

		$fields = array(
			'last_result' => array_merge( $kept, $summary ),
			'checked_at'  => $now,
		);

		if ( 'support' === $status ) {
			if ( self::transition( $claim_id, $states, 'support', $fields ) ) {
				self::alert( $claim, $reason );
			}
			return;
		}

		if ( 'unknown' === $status ) {
			$failures                = (int) $claim['check_failures'] + 1;
			$fields['check_failures'] = $failures;

			if ( $failures >= self::MAX_FAILURES ) {
				$fields['last_result']['reason'] = 'servers_not_answering';
				$fields['last_result']['status'] = 'support';
				if ( self::transition( $claim_id, $states, 'support', $fields ) ) {
					self::alert( $claim, 'servers_not_answering' );
				}
				return;
			}
		}

		self::transition( $claim_id, $states, 'pending', $fields );
	}

	/**
	 * Reads the common records of a verified domain from its current name servers.
	 *
	 * @param int $claim_id Claim id.
	 */
	public static function scan( $claim_id ): void {
		$claim_id = (int) $claim_id;
		$claim    = DnsClaimsFeature::enabled() ? DnsClaims::get( $claim_id ) : null;

		if ( ! $claim || 'verified' !== $claim['status'] || self::past( $claim ) ) {
			return;
		}

		$domain     = (string) $claim['domain'];
		$delegation = self::proof( 'delegation', array( $domain ) );
		$found      = 'ok' === ( $delegation['status'] ?? '' ) ? self::proof( 'scan', array( $domain, $delegation ) ) : array();
		$last       = $claim['last_result'];

		$last['scan'] = array_values( (array) ( $found['records'] ?? array() ) );

		if ( 'ok' !== ( $delegation['status'] ?? '' ) ) {
			$last['scan_reason'] = (string) ( $delegation['reason'] ?? 'unknown' );
		} else {
			unset( $last['scan_reason'] );
		}

		// A claim that changed while the servers were asked (cancelled, expired) is left as it is.
		self::update( $claim_id, array( 'last_result' => $last ) );
	}

	/**
	 * Daily: ends old claims, deletes the very old, drops lapsed reservations.
	 */
	public static function cleanup(): void {
		if ( ! DnsClaimsFeature::enabled() ) {
			return;
		}

		DnsClaims::expire_old();
		DnsClaims::purge();
		DnsAssignments::expire_reservations();
	}

	/**
	 * True when the claim's time is over.
	 *
	 * @param array<string, mixed> $claim Claim.
	 */
	private static function past( array $claim ): bool {
		return strtotime( (string) $claim['expires_at'] . ' UTC' ) <= time();
	}

	/**
	 * One of the three DNS calls. Test-only filter: a test returns an array for a call so that no
	 * network is needed (the DNS filters of ProofResolver work too).
	 *
	 * @param string            $call Call: delegation, check_txt or scan.
	 * @param array<int, mixed> $args Its arguments.
	 * @return array<string, mixed>
	 */
	private static function proof( string $call, array $args ): array {
		/**
		 * Replaces a ProofResolver result (tests only).
		 *
		 * @param array|null $result Null to run the real call.
		 * @param string     $call   delegation, check_txt or scan.
		 * @param array      $args   Call arguments.
		 */
		$result = apply_filters( 'beltoft_services_dns_proof_result', null, $call, $args );

		if ( is_array( $result ) ) {
			return $result;
		}

		if ( 'check_txt' === $call ) {
			return ProofResolver::check_txt( (string) $args[0], (string) $args[1], (array) $args[2] );
		}

		if ( 'scan' === $call ) {
			return ProofResolver::scan( (string) $args[0], (array) $args[1] );
		}

		return ProofResolver::delegation( (string) $args[0] );
	}

	/**
	 * Tells the admin that a domain needs a manual check. Domain, customer and reason only: never the token.
	 *
	 * @param array<string, mixed> $claim  Claim.
	 * @param string               $reason Reason code.
	 */
	private static function alert( array $claim, string $reason ): void {
		$to = (string) Settings::get( 'admin_email' );

		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		if ( ! is_email( $to ) ) {
			return;
		}

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array(
			sprintf( 'Domain: %s', $claim['domain'] ),
			sprintf( 'Customer: %d', (int) $claim['user_id'] ),
			sprintf( 'Claim: %d', (int) $claim['id'] ),
			sprintf( 'Reason: %s', $reason ),
		);

		wp_mail( $to, sprintf( '[%s] DNS hosting claim needs a manual check: %s', $site, $claim['domain'] ), implode( "\n", $lines ) . "\n" );
	}
}

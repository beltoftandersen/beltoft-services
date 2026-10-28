<?php
/**
 * Domain ownership claims: a customer proves control of a domain with a TXT record.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Two customers may hold a pending claim for the same domain; only a verified claim can be
 * turned into an assignment (DnsAssignments), and one customer at a time holds that.
 */
final class DnsClaims {

	public const TOKEN_PREFIX = 'beltoft-verify=v1:';
	public const RECORD_NAME  = '_beltoft-verify';
	public const PENDING_TTL  = 2 * DAY_IN_SECONDS;
	public const VERIFIED_TTL = DAY_IN_SECONDS;
	public const PURGE_AFTER  = 90 * DAY_IN_SECONDS;

	private const JSON_FIELDS = array( 'parent_ns', 'last_result', 'draft_records' );
	private const OPEN        = array( 'pending', 'checking', 'verified' );

	/**
	 * The claims table.
	 */
	private static function table(): string {
		return DnsSchema::tables()['claims'];
	}

	/**
	 * Current time as a UTC DATETIME string, optionally shifted.
	 *
	 * @param int $offset Seconds to add.
	 */
	private static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * Starts a pending claim with a fresh token. An older open claim of the same customer for the
	 * same domain is cancelled (rotation).
	 *
	 * @param int    $user_id Customer.
	 * @param string $domain  Normalised ASCII domain.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( int $user_id, string $domain ) {
		global $wpdb;

		$limit = max( 1, (int) Settings::get( 'dns_claims_max_per_user' ) );

		if ( self::open_domains( $user_id, $domain ) + self::live_resources( $user_id ) >= $limit ) {
			return new \WP_Error( 'claims_limit', __( 'You have reached the number of domains you can add. Remove one or contact support.', 'beltoft-services' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, always fresh.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled' WHERE user_id = %d AND domain = %s AND status IN ( 'pending', 'checking', 'verified' )",
				self::table(),
				$user_id,
				$domain
			)
		);

		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a random token, not obfuscation.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'user_id'    => $user_id,
				'domain'     => $domain,
				'token'      => $token,
				'status'     => 'pending',
				'created_at' => self::now(),
				'expires_at' => self::now( self::PENDING_TTL ),
			)
		);

		if ( ! $ok ) {
			return new \WP_Error( 'claim_failed', __( 'The domain could not be saved. Try again.', 'beltoft-services' ) );
		}

		return (array) self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Distinct domains other than this one for which the customer has an open claim.
	 *
	 * @param int    $user_id Customer.
	 * @param string $domain  The domain being claimed.
	 */
	private static function open_domains( int $user_id, string $domain ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, always fresh.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT domain ) FROM %i WHERE user_id = %d AND domain <> %s AND status IN ( 'pending', 'checking', 'verified' )",
				self::table(),
				$user_id,
				$domain
			)
		);
	}

	/**
	 * The customer's live DNS hosting resources.
	 *
	 * @param int $user_id Customer.
	 */
	private static function live_resources( int $user_id ): int {
		$count = 0;

		foreach ( Resource::live_ids( 'dns' ) as $id ) {
			$resource = Resource::find( $id );

			if ( $resource && (int) ( $resource->license()['user_id'] ?? 0 ) === $user_id ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * One claim.
	 *
	 * @param int $id Claim id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, always fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * The customer's claims, newest first. Finished rows older than a week are left out.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, always fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE user_id = %d AND NOT ( status IN ( 'consumed', 'cancelled', 'expired' ) AND expires_at < %s ) ORDER BY id DESC",
				self::table(),
				$user_id,
				self::now( -7 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Decodes the JSON columns and casts the ids.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		foreach ( self::JSON_FIELDS as $field ) {
			$decoded       = isset( $row[ $field ] ) ? json_decode( (string) $row[ $field ], true ) : null;
			$row[ $field ] = is_array( $decoded ) ? $decoded : array();
		}

		$row['id']             = (int) $row['id'];
		$row['user_id']        = (int) $row['user_id'];
		$row['check_failures'] = (int) $row['check_failures'];
		$row['ds_present']     = null === $row['ds_present'] ? null : (int) $row['ds_present'];

		return $row;
	}

	/**
	 * The TXT record value the customer publishes.
	 *
	 * @param array<string, mixed> $claim Claim.
	 */
	public static function txt_value( array $claim ): string {
		return self::TOKEN_PREFIX . $claim['token'];
	}

	/**
	 * The TXT record name the customer publishes it under.
	 *
	 * @param array<string, mixed> $claim Claim.
	 */
	public static function txt_name( array $claim ): string {
		return self::RECORD_NAME . '.' . $claim['domain'];
	}

	/**
	 * Changes a claim's status and optionally other fields (arrays are stored as JSON).
	 *
	 * @param int                  $id     Claim id.
	 * @param string               $status New status.
	 * @param array<string, mixed> $fields parent_ns, ds_present, last_result, draft_records, verified_at, checked_at, check_failures.
	 */
	public static function set_status( int $id, string $status, array $fields = array() ): bool {
		global $wpdb;

		$allowed = array( 'parent_ns', 'ds_present', 'last_result', 'draft_records', 'verified_at', 'checked_at', 'check_failures' );
		$data    = array( 'status' => $status );

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			$data[ $key ] = in_array( $key, self::JSON_FIELDS, true ) ? wp_json_encode( $value ) : $value;
		}

		if ( 'verified' === $status ) {
			$data['expires_at'] = self::now( self::VERIFIED_TTL );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * May this claim be turned into an assignment now?
	 *
	 * @param array<string, mixed> $claim   Claim.
	 * @param int                  $user_id Customer asking.
	 */
	public static function usable( array $claim, int $user_id ): bool {
		return 'verified' === ( $claim['status'] ?? '' )
			&& (int) ( $claim['user_id'] ?? 0 ) === $user_id
			&& strtotime( (string) ( $claim['expires_at'] ?? '' ) . ' UTC' ) > time();
	}

	/**
	 * Open claims past their expiry become expired.
	 *
	 * @return int Rows changed.
	 */
	public static function expire_old(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'expired' WHERE status IN ( 'pending', 'checking', 'verified' ) AND expires_at < %s",
				self::table(),
				self::now()
			)
		);

		return (int) $changed;
	}

	/**
	 * Deletes claims that ended more than 90 days ago.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status IN ( 'expired', 'consumed', 'cancelled', 'support' ) AND expires_at < %s",
				self::table(),
				self::now( -self::PURGE_AFTER )
			)
		);

		return (int) $deleted;
	}
}

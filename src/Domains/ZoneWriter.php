<?php
/**
 * The one writer of RRsets into a zone of ours.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the zone lock, reads the zone just before the write, refuses a paused or missing zone, asks the caller's policy
 * what to send and sends only those RRsets. Every other name and type in the zone is left alone.
 */
final class ZoneWriter {

	/**
	 * Writes validated rows into a zone, as the caller's policy decides.
	 *
	 * The policy gets the zone as read inside the lock and the validated sets of the rows. It returns the RRsets to
	 * send (each with its changetype), true when the zone is already right, or a string: the reason it refuses.
	 *
	 * @param int|null                                                   $user_id       Customer; when given, the zone must be one of theirs on our name servers.
	 * @param string                                                     $zone          Zone (the domain).
	 * @param array<int, array<string, mixed>>                           $rows          Editor rows (the shape Records::validate takes).
	 * @param callable(array, array): (array<int, array<string, mixed>>|true|string) $policy        Decides what is written.
	 * @param bool                                                       $check_paused  Refuse a paused zone.
	 * @return array{status:string,reason:string,wrote:bool}|\WP_Error Refused or ok; a transient error when the zone could not be read or written; the lock error (zone_busy) as it is.
	 */
	public static function write( ?int $user_id, string $zone, array $rows, callable $policy, bool $check_paused = true ) {
		if ( null !== $user_id && ! in_array( $zone, ZoneAccess::zones( $user_id ), true ) ) {
			return self::refused( 'not_ours' );
		}

		$sets = Records::validate( $zone, $rows );

		if ( is_wp_error( $sets ) || count( $sets ) !== count( $rows ) ) {
			return self::refused( 'invalid' );
		}

		return Records::locked(
			$zone,
			static function () use ( $zone, $sets, $policy, $check_paused ) {
				// Read immediately before the write: what is in the zone now decides.
				$zone_data = Clients::dns()->zone( $zone );

				if ( is_wp_error( $zone_data ) ) {
					return new \WP_Error( 'transient', __( 'The DNS zone could not be read.', 'beltoft-services' ) );
				}

				if ( ! is_array( $zone_data ) ) {
					return self::refused( 'no_zone' );
				}

				if ( $check_paused && Records::paused( $zone, $zone_data ) ) {
					return self::refused( 'paused' );
				}

				$decision = $policy( $zone_data, $sets );

				if ( is_string( $decision ) ) {
					return self::refused( $decision );
				}

				if ( ! is_array( $decision ) || array() === $decision ) {
					return array(
						'status' => 'ok',
						'reason' => '',
						'wrote'  => false,
					);
				}

				$done = Clients::dns()->replace_rrsets( $zone, $decision );

				if ( is_wp_error( $done ) ) {
					return new \WP_Error( 'transient', __( 'The DNS record could not be saved.', 'beltoft-services' ) );
				}

				return array(
					'status' => 'ok',
					'reason' => '',
					'wrote'  => true,
				);
			}
		);
	}

	/**
	 * Deletes the RRsets a policy names from a zone, under the zone lock.
	 *
	 * @param int|null                                  $user_id Customer; when given, the zone must be one of theirs.
	 * @param string                                    $zone    Zone.
	 * @param callable(array): array<int, array<string, mixed>> $policy  Gets the zone, returns DELETE RRsets; an empty array is nothing to do.
	 * @return bool|\WP_Error True when done, nothing was to do or the zone is gone; a not_ours error when a given customer does not own the zone; a transient error on a failed read or write; the lock error as it is.
	 */
	public static function remove( ?int $user_id, string $zone, callable $policy ) {
		if ( null !== $user_id && ! in_array( $zone, ZoneAccess::zones( $user_id ), true ) ) {
			return new \WP_Error( 'not_ours', __( 'The domain is not one of your domains on our name servers.', 'beltoft-services' ) );
		}

		return Records::locked(
			$zone,
			static function () use ( $zone, $policy ) {
				$zone_data = Clients::dns()->zone( $zone );

				if ( is_wp_error( $zone_data ) ) {
					return new \WP_Error( 'transient', __( 'The DNS zone could not be read.', 'beltoft-services' ) );
				}

				if ( ! is_array( $zone_data ) ) {
					return true;
				}

				$sets = $policy( $zone_data );

				if ( array() === $sets ) {
					return true;
				}

				$done = Clients::dns()->replace_rrsets( $zone, $sets );

				return is_wp_error( $done ) ? new \WP_Error( 'transient', __( 'The DNS record could not be removed.', 'beltoft-services' ) ) : true;
			}
		);
	}

	/**
	 * What to do with a CNAME given what its name holds. The one rule for every CNAME we publish: a free name is written,
	 * a name that already holds exactly this one alias is left as it is, anything else at the name is somebody's and is
	 * never touched.
	 *
	 * @param string                              $value Wanted target.
	 * @param array<string, array<string, mixed>> $here  The raw RRsets at the name, by upper-case type (SOA and NS left out).
	 * @return string write, present or name_taken.
	 */
	public static function cname_verdict( string $value, array $here ): string {
		if ( array() === $here ) {
			return 'write';
		}

		$have = array();

		foreach ( (array) ( $here['CNAME']['records'] ?? array() ) as $record ) {
			if ( empty( $record['disabled'] ) ) {
				$have[] = strtolower( rtrim( trim( (string) ( $record['content'] ?? '' ) ), '.' ) );
			}
		}

		return isset( $here['CNAME'] ) && 1 === count( $here ) && array( strtolower( $value ) ) === $have ? 'present' : 'name_taken';
	}

	/**
	 * The write policy for one CNAME row: writes only the CNAME RRset at its name, and only when the name is free; an
	 * identical alias is already right; any other record at the name refuses with conflict:name_taken.
	 *
	 * @param string $value Wanted target.
	 * @return callable(array, array): (array<int, array<string, mixed>>|true|string)
	 */
	public static function cname_policy( string $value ): callable {
		return static function ( array $zone_data, array $sets ) use ( $value ) {
			$name = strtolower( rtrim( (string) ( $sets[0]['name'] ?? '' ), '.' ) );
			$here = array();

			foreach ( (array) ( $zone_data['rrsets'] ?? array() ) as $set ) {
				if ( is_array( $set ) && strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) === $name ) {
					$here[ strtoupper( (string) ( $set['type'] ?? '' ) ) ] = $set;
				}
			}

			unset( $here['SOA'], $here['NS'] );

			$verdict = self::cname_verdict( $value, $here );

			if ( 'write' === $verdict ) {
				return array( $sets[0] );
			}

			return 'present' === $verdict ? true : 'conflict:name_taken';
		};
	}

	/**
	 * A refusal.
	 *
	 * @param string $reason Reason code.
	 * @return array{status:string,reason:string,wrote:bool}
	 */
	private static function refused( string $reason ): array {
		return array(
			'status' => 'refused',
			'reason' => $reason,
			'wrote'  => false,
		);
	}
}

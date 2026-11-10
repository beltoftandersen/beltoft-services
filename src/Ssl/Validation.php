<?php
/** Domain validation of a certificate order: what the authority asks for, and who does it. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Domains\ZoneWriter;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Methods of the customer's choice: `auto` (a CNAME published in a zone of ours), `dns` (the customer adds the CNAME), `file`
 * (a file on the customer's site) and `email` (the authority mails an approver address). The values of a CNAME or a file always
 * come from the registrar order's `additional_data`; nothing is computed here. A published CNAME is left in place, so a later
 * automatic reissue validates without a person; it is removed by nothing here.
 */
final class Validation {
	public const METHODS = array( 'auto', 'dns', 'file', 'email' );

	/** TTL of the published record. */
	public const TTL = 300;

	/**
	 * The method name the registrar order is created with.
	 *
	 * @param Resource $r Resource.
	 */
	public static function registrar_method( Resource $r ): string {
		return self::method_of( (string) $r->get( 'ssl_validation' ) );
	}

	/**
	 * The registrar's method name for one of ours.
	 *
	 * @param string $validation auto, dns, file or email.
	 */
	public static function method_of( string $validation ): string {
		return array( 'auto' => 'dns', 'dns' => 'dns', 'file' => 'https', 'email' => 'email' )[ $validation ] ?? 'dns';
	}

	/**
	 * The `domain_validation_methods` of an order: one row per name.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{host_name: string, method: string}>
	 */
	public static function registrar_methods( Resource $r ): array {
		return array_map( static fn( $e ) => array( 'host_name' => $e['name'], 'method' => self::method_of( $e['validation'] ) ), SslNames::entries( $r ) );
	}

	/**
	 * The validation rows of an order that is in the authority's hands, handled by the chosen method.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $detail The registrar order.
	 */
	public static function start( Resource $r, array $detail ): StepResult {
		if ( SslNames::is_multi( $r ) ) {
			return self::start_multi( $r, $detail );
		}

		$kind = (string) $r->get( 'ssl_validation' );

		if ( 'email' === $kind ) {
			return self::told( $r, $kind, array( array( 'type' => 'email', 'value' => (string) $r->get( 'ssl_approver' ), 'status' => 'sent' ) ), 'email' );
		}

		$rows = self::rows( $kind, $detail );

		if ( array() === $rows ) {
			$until = (int) ( $r->get( 'ssl_requested_at' ) ?: time() ) + 2 * HOUR_IN_SECONDS;

			return time() > $until
				? StepResult::fail( 'The certificate authority gave no validation data.' )
				: StepResult::wait( 300, 'Waiting for the validation data.', $until );
		}

		if ( 'auto' === $kind ) {
			$published = self::publish( $r, $rows );

			if ( $published instanceof StepResult ) {
				return $published;
			}

			if ( null !== $published ) {
				return StepResult::done( array( 'ssl_validation_rows' => $published, 'ssl_validation_auto' => true ) );
			}

			// A record in the way: the customer adds it by hand, and is told why.
			foreach ( $rows as $i => $row ) {
				$rows[ $i ]['status'] = 'manual';
			}

			return self::told( $r, 'dns', $rows, 'dns', array( 'ssl_validation' => 'dns', 'ssl_validation_auto' => false ) + SslNames::with_first_validation( $r, 'dns' ) );
		}

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['status'] = 'manual';
		}

		return self::told( $r, $kind, $rows, 'file' === $kind ? 'file' : 'dns' );
	}

	/**
	 * The validation of a certificate for several names: every name has its own method, and the registrar's `additional_data` holds
	 * one entry per name (its `domain`). The records of names validated "auto" are published in the customer's zones; every other row
	 * is shown to the customer. A name whose record cannot be published (no zone of ours, a record in the way) falls back to a DNS
	 * record the customer adds.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $detail The registrar order.
	 */
	private static function start_multi( Resource $r, array $detail ): StepResult {
		$entries = SslNames::entries( $r );
		$items   = array_values( array_filter( (array) ( $detail['additional_data'] ?? array() ), 'is_array' ) );
		$known   = array_column( $entries, 'base' );
		$matched = false;

		foreach ( $items as $item ) {
			$matched = $matched || in_array( strtolower( ltrim( rtrim( (string) ( $item['domain'] ?? '' ), '.' ), '*.' ) ), $known, true );
		}

		$rows = array();

		foreach ( $entries as $i => $entry ) {
			// The registrar names the domain of each entry; when none of them is one of ours the entries are taken in order.
			$mine = $matched
				? array_values( array_filter( $items, static fn( $it ) => strtolower( ltrim( rtrim( (string) ( $it['domain'] ?? '' ), '.' ), '*.' ) ) === $entry['base'] ) )
				: ( isset( $items[ $i ] ) ? array( $items[ $i ] ) : array() );
			$got  = self::rows( 'file' === $entry['validation'] ? 'file' : 'dns', array( 'additional_data' => $mine ) );

			if ( array() === $got ) {
				$until = (int) ( $r->get( 'ssl_requested_at' ) ?: time() ) + 2 * HOUR_IN_SECONDS;

				return time() > $until
					? StepResult::fail( 'The certificate authority gave no validation data for ' . $entry['name'] . '.' )
					: StepResult::wait( 300, 'Waiting for the validation data.', $until );
			}

			foreach ( $got as $row ) {
				$rows[] = $row + array( 'domain' => $entry['name'], 'method' => $entry['validation'] );
			}
		}

		$auto = array_keys( array_filter( $rows, static fn( $row ) => 'auto' === $row['method'] ) );

		if ( array() !== $auto ) {
			$published = self::publish( $r, array_intersect_key( $rows, array_flip( $auto ) ) );

			if ( $published instanceof StepResult ) {
				return $published;
			}

			foreach ( $auto as $i ) {
				$rows[ $i ] = null !== $published ? $published[ $i ] + array( 'domain' => $rows[ $i ]['domain'], 'method' => 'auto' ) : array_merge( $rows[ $i ], array( 'status' => 'manual', 'method' => 'dns' ) );
			}
		}

		$state = array();

		foreach ( $rows as $i => $row ) {
			if ( empty( $row['status'] ) ) {
				$rows[ $i ]['status'] = 'manual';
			}

			$state[ $row['domain'] ] = $rows[ $i ]['method'];
		}

		$kept    = array_values( array_map( static fn( $e ) => array( 'name' => $e['name'], 'validation' => $state[ $e['name'] ] ?? $e['validation'] ), $entries ) );
		$manual  = array_filter( $rows, static fn( $row ) => 'manual' === $row['status'] );
		$extra   = array( 'ssl_names' => $kept, 'ssl_validation_auto' => array() === $manual );
		$reason  = array() === $manual ? '' : ( array() !== array_filter( $manual, static fn( $row ) => 'file' === $row['type'] ) ? 'file' : 'dns' );

		if ( '' === $reason ) {
			return StepResult::done( array( 'ssl_validation' => $kept[0]['validation'], 'ssl_validation_rows' => array_values( $rows ) ) + $extra );
		}

		return self::told( $r, $kept[0]['validation'], array_values( $rows ), $reason, $extra );
	}

	/**
	 * The rows the registrar order asks for.
	 *
	 * @param string               $kind   Method.
	 * @param array<string, mixed> $detail Registrar order.
	 * @return array<int, array<string, string>>
	 */
	public static function rows( string $kind, array $detail ): array {
		$rows = array();

		foreach ( (array) ( $detail['additional_data'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( 'file' === $kind && ! empty( $item['file_location'] ) ) {
				$rows[] = array( 'type' => 'file', 'name' => (string) $item['file_location'], 'value' => (string) ( $item['file_contents'] ?? '' ) );
			} elseif ( 'file' !== $kind && ! empty( $item['dns_record'] ) && ! empty( $item['dns_value'] ) ) {
				$rows[] = array( 'type' => 'CNAME', 'name' => strtolower( rtrim( (string) $item['dns_record'], '.' ) ), 'value' => strtolower( rtrim( (string) $item['dns_value'], '.' ) ) );
			}
		}

		return $rows;
	}

	/**
	 * Publishes the CNAME rows into the customer's zone. Null means a record is in the way (the customer adds it by hand).
	 *
	 * @param Resource                          $r    Resource.
	 * @param array<int, array<string, string>> $rows Rows.
	 * @return array<int, array<string, string>>|StepResult|null
	 */
	private static function publish( Resource $r, array $rows ) {
		$user = (int) ( $r->license()['user_id'] ?? 0 );
		// Records this service wrote earlier (kept in ssl_written, which a reset of the rows does not touch): they are still ours.
		$mine = self::written_list( $r );

		foreach ( $rows as $i => $row ) {
			// Each record goes into the zone it belongs to (the names of a certificate may be in different zones of the customer).
			$zone = 'CNAME' === $row['type'] ? SslNames::zone_for( $row['name'], $user ) : null;

			if ( null === $zone ) {
				self::clean_up( $r, $user, $rows );

				return null;
			}

			$relative = $row['name'] === $zone ? '@' : substr( $row['name'], 0, -strlen( $zone ) - 1 );
			// A record that is already exactly this one was not written by us, and is never removed by us.
			$was      = self::listed( $mine, $row );
			$before   = ! $was && self::holds( $zone, $row['name'], $row['value'] );
			$result   = ZoneWriter::write( $user, $zone, array( array( 'name' => $relative, 'type' => 'CNAME', 'ttl' => self::TTL, 'value' => $row['value'] ) ), ZoneWriter::cname_policy( (string) $row['value'] ) );

			if ( is_wp_error( $result ) ) {
				// The records written so far stay: the next run reads them as present and goes on, and they are still known as ours.
				$r->set( array( 'ssl_written' => $mine ) );

				return StepResult::retry( 300, 'The validation record could not be published yet.' );
			}

			if ( 'ok' !== $result['status'] ) {
				// The customer adds the records by hand, so none of ours is left half done in their zone.
				self::clean_up( $r, $user, $rows );

				return null;
			}

			$rows[ $i ]['status']  = 'published';
			$rows[ $i ]['written'] = ! $before;

			if ( ! $before && ! $was ) {
				$mine[] = array( 'name' => $row['name'], 'value' => $row['value'] );
			}
		}

		// Saved with the rows' own save by the caller; saved here too so a crash between the two cannot lose the knowledge.
		$r->set( array( 'ssl_written' => $mine ) );

		return $rows;
	}

	/**
	 * Does the zone hold exactly this CNAME at the name?
	 *
	 * @param string $zone  Zone.
	 * @param string $name  Full name.
	 * @param string $value Target.
	 */
	private static function holds( string $zone, string $name, string $value ): bool {
		$data = \BeltoftServices\Domains\Clients::dns()->zone( $zone );

		foreach ( is_array( $data ) ? (array) ( $data['rrsets'] ?? array() ) : array() as $set ) {
			if ( 'CNAME' === ( $set['type'] ?? '' ) && strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) === $name ) {
				foreach ( (array) ( $set['records'] ?? array() ) as $record ) {
					if ( strtolower( rtrim( (string) ( $record['content'] ?? '' ), '.' ) ) === $value ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Removes a CNAME this service wrote, only when the name still holds exactly that one record.
	 *
	 * @param int                  $user Customer.
	 * @param string               $zone Zone.
	 * @param array<string, mixed> $row  Row (name, value).
	 */
	public static function unpublish( int $user, string $zone, array $row ): bool {
		if ( ! in_array( $zone, ZoneAccess::zones( $user ), true ) ) {
			return false;
		}

		$ours = false;
		$done = ZoneWriter::remove(
			$user,
			$zone,
			static function ( array $data ) use ( $row, &$ours ) {
				foreach ( (array) ( $data['rrsets'] ?? array() ) as $set ) {
					if ( 'CNAME' !== ( $set['type'] ?? '' ) || strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) !== $row['name'] ) {
						continue;
					}

					$records = (array) ( $set['records'] ?? array() );

					// Exactly our one CNAME: anything else at the name is the customer's now.
					if ( 1 !== count( $records ) || strtolower( rtrim( (string) ( $records[0]['content'] ?? '' ), '.' ) ) !== $row['value'] ) {
						return array();
					}

					$ours = true;

					return array( array( 'name' => $set['name'], 'type' => 'CNAME', 'changetype' => 'DELETE' ) );
				}

				return array();
			}
		);

		// Only a record that was ours and is gone counts: a name that no longer holds it is not "removed".
		return true === $done && $ours;
	}

	/**
	 * Removes what a half finished publish wrote.
	 *
	 * @param int                               $user Customer.
	 * @param array<int, array<string, string>> $rows Rows so far.
	 */
	private static function clean_up( Resource $r, int $user, array $rows ): void {
		$mine = self::written_list( $r );

		foreach ( $rows as $row ) {
			if ( empty( $row['written'] ) || ! isset( $row['name'], $row['value'] ) ) {
				continue;
			}

			$zone = SslNames::zone_for( (string) $row['name'], $user );

			if ( null !== $zone && self::unpublish( $user, $zone, $row ) ) {
				$mine = array_values( array_filter( $mine, static fn( $m ) => ! ( $m['name'] === $row['name'] && $m['value'] === $row['value'] ) ) );
			}
		}

		$r->set( array( 'ssl_written' => $mine ) );
	}

	/**
	 * The records this service wrote and has not removed (name and value).
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function written_list( Resource $r ): array {
		$out = array();

		foreach ( array_merge( (array) $r->get( 'ssl_written' ), array_filter( (array) $r->get( 'ssl_validation_rows' ), static fn( $row ) => is_array( $row ) && ! empty( $row['written'] ) ) ) as $row ) {
			if ( is_array( $row ) && isset( $row['name'], $row['value'] ) && ! self::listed( $out, $row ) ) {
				$out[] = array( 'name' => (string) $row['name'], 'value' => (string) $row['value'] );
			}
		}

		return $out;
	}

	/**
	 * Is the record (name and value) in the list?
	 *
	 * @param array<int, array<string, string>> $list List.
	 * @param array<string, string>             $row  Row.
	 */
	private static function listed( array $list, array $row ): bool {
		foreach ( $list as $one ) {
			if ( $one['name'] === $row['name'] && $one['value'] === $row['value'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes the CNAMEs this service wrote when the service is deleted (best effort; a record someone changed is left).
	 *
	 * @param Resource $r Resource.
	 */
	public static function remove_published( Resource $r ): void {
		$user = (int) ( $r->license()['user_id'] ?? 0 );

		foreach ( self::written_list( $r ) as $row ) {
			$zone = SslNames::zone_for( $row['name'], $user );

			if ( null !== $zone ) {
				self::unpublish( $user, $zone, $row );
			}
		}
	}

	/**
	 * Stores the rows, and tells the customer once what to do.
	 *
	 * @param Resource                          $r      Resource.
	 * @param string                            $kind   Method now in use.
	 * @param array<int, array<string, string>> $rows   Rows.
	 * @param string                            $reason dns, file or email.
	 * @param array<string, mixed>              $extra  More meta.
	 */
	private static function told( Resource $r, string $kind, array $rows, string $reason, array $extra = array() ): StepResult {
		$meta = array_merge( array( 'ssl_validation' => $kind, 'ssl_validation_rows' => $rows ), $extra );

		if ( $reason !== (string) $r->get( 'ssl_action_sent' ) ) {
			$r->set( $meta + array( 'ssl_action_sent' => $reason ) );
			Emails::send( 'beltoft_services_ssl_action_needed', Resource::find( $r->id() ) ?? $r, array( 'ssl_reason' => $reason ) );
			$meta['ssl_action_sent'] = $reason;
		}

		return StepResult::done( $meta );
	}
}

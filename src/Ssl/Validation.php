<?php
/** Domain validation of a certificate order: what the authority asks for, and who does it. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Mail\MailDns;
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
		return array( 'auto' => 'dns', 'dns' => 'dns', 'file' => 'https', 'email' => 'email' )[ (string) $r->get( 'ssl_validation' ) ] ?? 'dns';
	}

	/**
	 * The validation rows of an order that is in the authority's hands, handled by the chosen method.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $detail The registrar order.
	 */
	public static function start( Resource $r, array $detail ): StepResult {
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

			return self::told( $r, 'dns', $rows, 'dns', array( 'ssl_validation' => 'dns', 'ssl_validation_auto' => false ) );
		}

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['status'] = 'manual';
		}

		return self::told( $r, $kind, $rows, 'file' === $kind ? 'file' : 'dns' );
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
		$zone = SslNames::zone_for( (string) $r->get( 'ssl_domain' ), $user );

		if ( null === $zone ) {
			return null;
		}

		foreach ( $rows as $i => $row ) {
			if ( 'CNAME' !== $row['type'] || ( $row['name'] !== $zone && ! str_ends_with( $row['name'], '.' . $zone ) ) ) {
				self::clean_up( $user, $zone, $rows );

				return null;
			}

			$relative = $row['name'] === $zone ? '@' : substr( $row['name'], 0, -strlen( $zone ) - 1 );
			// A record that is already exactly this one was not written by us, and is never removed by us.
			$before   = self::holds( $zone, $row['name'], $row['value'] );
			$result   = MailDns::publish( $user, $zone, array( 'name' => $relative, 'type' => 'CNAME', 'ttl' => self::TTL, 'value' => $row['value'] ) );

			if ( is_wp_error( $result ) ) {
				// The records written so far stay: the next run reads them as present and goes on.
				return StepResult::retry( 300, 'The validation record could not be published yet.' );
			}

			if ( 'ok' !== $result[0] ) {
				// The customer adds the records by hand, so none of ours is left half done in their zone.
				self::clean_up( $user, $zone, $rows );

				return null;
			}

			$rows[ $i ]['status']  = 'published';
			$rows[ $i ]['written'] = ! $before;
		}

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

		$done = Records::locked(
			$zone,
			static function () use ( $zone, $row ) {
				$data = \BeltoftServices\Domains\Clients::dns()->zone( $zone );

				foreach ( is_array( $data ) ? (array) ( $data['rrsets'] ?? array() ) : array() as $set ) {
					if ( 'CNAME' !== ( $set['type'] ?? '' ) || strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) !== $row['name'] ) {
						continue;
					}

					$records = (array) ( $set['records'] ?? array() );

					if ( 1 !== count( $records ) || strtolower( rtrim( (string) ( $records[0]['content'] ?? '' ), '.' ) ) !== $row['value'] ) {
						return false;
					}

					return ! is_wp_error( \BeltoftServices\Domains\Clients::dns()->replace_rrsets( $zone, array( array( 'name' => $set['name'], 'type' => 'CNAME', 'changetype' => 'DELETE' ) ) ) );
				}

				return false;
			}
		);

		return true === $done;
	}

	/**
	 * Removes what a half finished publish wrote.
	 *
	 * @param int                               $user Customer.
	 * @param string                            $zone Zone.
	 * @param array<int, array<string, string>> $rows Rows so far.
	 */
	private static function clean_up( int $user, string $zone, array $rows ): void {
		foreach ( $rows as $row ) {
			if ( ! empty( $row['written'] ) ) {
				self::unpublish( $user, $zone, $row );
			}
		}
	}

	/**
	 * Removes the CNAMEs this service wrote when the service is deleted (best effort; a record someone changed is left).
	 *
	 * @param Resource $r Resource.
	 */
	public static function remove_published( Resource $r ): void {
		$user = (int) ( $r->license()['user_id'] ?? 0 );
		$zone = SslNames::zone_for( (string) $r->get( 'ssl_domain' ), $user );

		if ( null === $zone ) {
			return;
		}

		foreach ( (array) $r->get( 'ssl_validation_rows' ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['written'] ) && isset( $row['name'], $row['value'] ) ) {
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

	/**
	 * Looks at public DNS for each CNAME row and records whether it is there. Read only; used to show the customer progress.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, string>> The rows with `seen` set to found or missing.
	 */
	public static function check_public( Resource $r ): array {
		$rows = (array) $r->get( 'ssl_validation_rows' );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || 'CNAME' !== ( $row['type'] ?? '' ) ) {
				continue;
			}

			$found = MailDns::lookup( (string) $row['name'], 'CNAME' );
			$seen  = ! is_wp_error( $found ) && in_array( strtolower( rtrim( (string) ( $found[0] ?? '' ), '.' ) ), array( (string) $row['value'] ), true );

			$rows[ $i ]['seen'] = $seen ? 'found' : 'missing';
		}

		return $rows;
	}
}

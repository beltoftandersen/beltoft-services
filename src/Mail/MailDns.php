<?php
/**
 * The DNS records an email domain needs: computed, published where we may, re-checked where we may not.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Background;
use BeltoftServices\Domains\PublicDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Domains\ZoneWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Records: MX to the mail host, SPF (include of the SPF name), DKIM (the key mailcow made), DMARC, and the autoconfig
 * and autodiscover names for mail programs.
 *
 * A domain whose zone our name servers serve for this customer gets each record written on its own, as one RRset,
 * under the zone lock and after reading the zone again (the path the app domains use): a name that holds records of
 * another kind, or other values, is reported as a conflict and left alone, an SPF record that exists is reported, not
 * replaced, and a TXT set that holds other records keeps them. Any other domain gets the table in its account area and
 * a job that reads public DNS every ten minutes for 14 days and marks each record ok or missing. Nothing is ever
 * written for a domain the customer does not hold with us.
 *
 * Meta: mail_records (rows with status ok|missing|conflict and a reason), mail_dns (waiting, ok, timeout),
 * mail_dns_since, mail_dns_checked.
 */
final class MailDns {

	public const HOOK = 'beltoft_services_mail_dns_check';

	/** Seconds between two reads of public DNS for one domain. */
	public const INTERVAL = 600;

	/** The least gap between two reads, whatever calls the job. */
	public const MIN_GAP = 300;

	/** Seconds after setup before the customer is reminded once of records still missing. */
	public const REMIND_AFTER = DAY_IN_SECONDS;

	/** Days the records are looked for. */
	public const WAIT_DAYS = 14;

	/** Seconds a record may be cached. */
	public const TTL = 3600;

	/** The daily sweep that queues a look at each domain whose records were found, and the look. */
	public const RECHECK_ALL  = 'beltoft_services_mail_dns_recheck_all';
	public const RECHECK      = 'beltoft_services_mail_dns_recheck';
	public const RECHECK_CRON = '40 3,15 * * *';

	/** The second read of a lost record, a few minutes after the first. */
	public const CONFIRM = 'beltoft_services_mail_dns_confirm';

	/** The DKIM selector mailcow is asked for. */
	public const SELECTOR = 'dkim';

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Background::declare(
			self::HOOK,
			array(
				'due'      => array( __CLASS__, 'due' ),
				'resource' => true,
				'label'    => 'Email DNS look',
			)
		);
		self::register_recheck();
		add_action( self::CONFIRM, array( __CLASS__, 'confirm' ) );
		Background::declare( self::CONFIRM, array( 'resource' => true, 'label' => 'Email DNS loss confirmation' ) );
	}

	// -- The records ---------------------------------------------------------------------------

	/**
	 * The records the domain needs. Empty when the mail host is not set.
	 *
	 * @param string $domain   Domain.
	 * @param string $dkim_txt The DKIM TXT value mailcow made ('' leaves the DKIM record out).
	 * @return array<int, array<string, mixed>> Rows: key, name (relative, @ for the apex), type, ttl, value, priority (MX).
	 */
	public static function records( string $domain, string $dkim_txt ): array {
		$host = MailSettings::host();

		if ( '' === $host ) {
			return array();
		}

		$rows   = array();
		$rows[] = array( 'key' => 'mx', 'name' => '@', 'type' => 'MX', 'ttl' => self::TTL, 'value' => $host, 'priority' => 10 );
		$rows[] = array( 'key' => 'spf', 'name' => '@', 'type' => 'TXT', 'ttl' => self::TTL, 'value' => 'v=spf1 include:' . MailSettings::spf_include() . ' ~all' );

		if ( '' !== $dkim_txt ) {
			$rows[] = array( 'key' => 'dkim', 'name' => self::SELECTOR . '._domainkey', 'type' => 'TXT', 'ttl' => self::TTL, 'value' => $dkim_txt );
		}

		$rua    = MailSettings::dmarc_rua();
		$rua    = '' !== $rua ? $rua : sanitize_email( (string) Settings::get( 'admin_email' ) );
		$rows[] = array( 'key' => 'dmarc', 'name' => '_dmarc', 'type' => 'TXT', 'ttl' => self::TTL, 'value' => 'v=DMARC1; p=quarantine' . ( '' !== $rua ? '; rua=mailto:' . $rua : '' ) );
		$rows[] = array( 'key' => 'autoconfig', 'name' => 'autoconfig', 'type' => 'CNAME', 'ttl' => self::TTL, 'value' => $host );
		$rows[] = array( 'key' => 'autodiscover', 'name' => 'autodiscover', 'type' => 'CNAME', 'ttl' => self::TTL, 'value' => $host );

		return $rows;
	}

	/**
	 * The rows with a status each. For a zone of ours every record is published (or reported); otherwise all are missing.
	 *
	 * @param Resource $r Resource.
	 * @return array<int, array<string, mixed>>|\WP_Error Rows with status and reason, or a transient error (try again).
	 */
	public static function apply( Resource $r ) {
		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$rows   = self::records( $domain, (string) $r->get( 'mail_dkim' ) );
		$user   = (int) ( $r->license()['user_id'] ?? 0 );
		$ours   = MailDomain::on_our_servers( $domain, $user );

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['status'] = 'missing';
			$rows[ $i ]['reason'] = '';

			if ( ! $ours ) {
				continue;
			}

			$result = self::publish( $user, $domain, $row );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$rows[ $i ] = self::with_result( $rows[ $i ], $result );
		}

		return $rows;
	}

	/**
	 * A row with the outcome of publish(): the status and reason, and the mark written_by_us when this call wrote the record (a
	 * record that was there before, or that the customer owns, never gets it: it is not ours to remove).
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param array<int, mixed>    $result publish() result.
	 * @return array<string, mixed>
	 */
	private static function with_result( array $row, array $result ): array {
		$row['status'] = $result[0];
		$row['reason'] = $result[1];

		if ( ! empty( $result[2] ) ) {
			$row['written_by_us'] = true;
		}

		return $row;
	}

	/**
	 * The records still to add, as the customer would type them: full name, type, value, priority. Nothing secret is
	 * in them (the DKIM value is the public key).
	 *
	 * @param Resource $r           Resource.
	 * @param bool     $needed_only Leave out the recommended records.
	 * @return array<int, array<string, mixed>>
	 */
	public static function missing_rows( Resource $r, bool $needed_only = false ): array {
		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$out    = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			if ( ! is_array( $row ) || 'ok' === ( $row['status'] ?? '' ) || ! isset( $row['name'], $row['type'], $row['value'] ) || ( $needed_only && ! self::required( (string) ( $row['key'] ?? '' ) ) ) ) {
				continue;
			}

			$out[] = array(
				'key'      => (string) ( $row['key'] ?? '' ),
				'name'     => '@' === $row['name'] ? $domain : $row['name'] . '.' . $domain,
				'type'     => (string) $row['type'],
				'value'    => (string) $row['value'],
				'priority' => (int) ( $row['priority'] ?? 0 ),
				'status'   => (string) $row['status'],
			);
		}

		return $out;
	}

	/**
	 * Are the needed records in place? MX, SPF and DKIM must each be there and ok; the recommended records (DMARC,
	 * autoconfig, autodiscover) never decide.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with status.
	 */
	public static function complete( array $rows ): bool {
		$ok = array();

		foreach ( $rows as $row ) {
			if ( 'ok' === ( $row['status'] ?? '' ) ) {
				$ok[] = (string) ( $row['key'] ?? '' );
			}
		}

		return array() === array_diff( self::NEEDED, $ok );
	}

	/** The records a domain needs: without MX nothing arrives, without SPF and DKIM sending is held. */
	public const NEEDED = array( 'mx', 'spf', 'dkim' );

	/**
	 * Does the record count as needed? `mail_dns` is ok when the needed ones are found (complete()); the daily look and
	 * the reminder email go on until then. The others are recommended: still looked for, stored and shown, never blocking.
	 *
	 * @param string $key Record key (mx, spf, dkim, dmarc, autoconfig, autodiscover).
	 */
	public static function required( string $key ): bool {
		return in_array( $key, self::NEEDED, true );
	}

	/**
	 * A few words on what a record does, for the guide.
	 *
	 * @param string $key Record key.
	 */
	public static function purpose( string $key ): string {
		switch ( $key ) {
			case 'mx':
				return __( 'Sends email for your domain to our mail server. Receiving waits for this record.', 'beltoft-services' );
			case 'spf':
				return __( 'Says our mail server may send email for your domain. Sending waits for this record.', 'beltoft-services' );
			case 'dkim':
				return __( 'Signs your outgoing email, so other servers know it is yours. Sending waits for this record.', 'beltoft-services' );
			case 'dmarc':
				return __( 'Tells other servers what to do with email that fails the checks.', 'beltoft-services' );
			case 'autoconfig':
				return __( 'Lets mail programs find your settings by themselves.', 'beltoft-services' );
			case 'autodiscover':
				return __( 'The same for Outlook.', 'beltoft-services' );
		}

		return '';
	}

	/**
	 * An existing SPF record with our include added: a domain may have only one SPF record, so the customer changes theirs.
	 *
	 * @param string $existing The SPF record the domain has.
	 * @return string The merged record, or '' when it cannot be worked out (not an SPF record, or it uses redirect=).
	 */
	public static function merge_spf( string $existing ): string {
		$existing = trim( $existing );
		$include  = 'include:' . MailSettings::spf_include();

		if ( 0 !== stripos( $existing, 'v=spf1' ) || false !== stripos( $existing, 'redirect=' ) ) {
			return '';
		}

		if ( false !== stripos( $existing, $include ) ) {
			return $existing;
		}

		if ( 1 === preg_match( '/^(.*?)\s+([~\-+?]?all)$/iD', $existing, $m ) ) {
			return $m[1] . ' ' . $include . ' ' . $m[2];
		}

		return $existing . ' ' . $include;
	}

	// -- Writing into a zone of ours -------------------------------------------------------------

	/**
	 * Makes one record in the customer's zone, as one RRset, without touching any other name or type.
	 *
	 * @param int                  $user_id Customer; the zone must be one of theirs on our name servers.
	 * @param string               $zone    Zone (the domain).
	 * @param array<string, mixed> $row     A row of records().
	 * @return array{0:string,1:string,2:bool}|\WP_Error [ status (ok or conflict), reason, whether this call wrote the record ], or a transient error.
	 */
	public static function publish( int $user_id, string $zone, array $row ) {
		$fqdn = '@' === $row['name'] ? $zone : $row['name'] . '.' . $zone;
		$rows = array( array_intersect_key( $row + array( 'name' => '@' ), array_flip( array( 'name', 'type', 'ttl', 'value', 'priority' ) ) ) );

		$result = ZoneWriter::write(
			$user_id,
			$zone,
			$rows,
			static function ( array $zone_data, array $sets ) use ( $zone, $fqdn, $row ) {
				$here = Records::sets_at( $zone, $fqdn, (array) ( $zone_data['rrsets'] ?? array() ) );
				$plan = self::plan( $row, $here, $zone, 'dkim' === $row['key'] ? self::written_before( $zone, 'dkim' ) : array() );

				if ( null === $plan['write'] ) {
					return $plan['status'] . ':' . $plan['reason'];
				}

				$write               = $sets[0];
				$write['changetype'] = 'REPLACE';
				$write['records']    = array_merge( $plan['keep'], $write['records'] );
				$write['ttl']        = $plan['ttl'] ?? $write['ttl'];

				return array( $write );
			}
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'transient', __( 'The DNS zone is being changed.', 'beltoft-services' ) );
		}

		if ( 'ok' === $result['status'] ) {
			if ( ! empty( $result['wrote'] ) ) {
				self::remember_written( $zone, (string) $row['key'], (string) $row['value'], true );
			}

			return array( 'ok', '', ! empty( $result['wrote'] ) );
		}

		if ( in_array( $result['reason'], array( 'not_ours', 'invalid', 'no_zone', 'paused' ), true ) ) {
			return array( 'conflict', $result['reason'], false );
		}

		return array_merge( explode( ':', $result['reason'], 2 ), array( false ) );
	}

	/**
	 * The customer-facing text of a stored record reason, in the current locale. Reasons are stored as stable codes so they
	 * do not freeze in the locale of the job that made them; a row stored before that holds plain text, shown as it is.
	 *
	 * @param string $stored The stored reason.
	 * @return string
	 */
	public static function reason_text( string $stored ): string {
		switch ( $stored ) {
			case 'not_ours':
				return __( 'The domain is not one of your domains on our name servers.', 'beltoft-services' );
			case 'invalid':
				return __( 'The record is not valid for this domain.', 'beltoft-services' );
			case 'no_zone':
				return __( 'The DNS zone does not exist.', 'beltoft-services' );
			case 'paused':
				return __( 'DNS hosting is paused for this domain.', 'beltoft-services' );
			case 'name_taken':
				return __( 'The name already has a DNS record.', 'beltoft-services' );
			case 'name_alias':
				return __( 'The name is an alias (CNAME) and cannot also hold this record.', 'beltoft-services' );
			case 'mx_other':
				return __( 'The domain already has other MX records.', 'beltoft-services' );
			case 'mx_second':
				return '';
			case 'spf_exists':
				/* translators: %s: SPF include to add */
				return sprintf( __( 'The domain already has an SPF record. It was not changed: add include:%s to it.', 'beltoft-services' ), MailSettings::spf_include() );
			case 'dkim_other':
				return __( 'The name already holds another DKIM key.', 'beltoft-services' );
			case 'dmarc_kept':
				return __( 'Your own DMARC record was kept.', 'beltoft-services' );
			case 'unplaced':
				return __( 'The record could not be placed.', 'beltoft-services' );
		}

		return $stored;
	}

	/**
	 * What to do for one record given what the name holds. Pure: no I/O.
	 *
	 * @param array<string, mixed>                 $row  The wanted record.
	 * @param array<string, array<string, mixed>> $here The raw RRsets at the name, by type.
	 * @param string                               $zone Zone.
	 * @param string[]                             $ours Values of this record we wrote earlier (a stale DKIM key): they may be replaced.
	 * @return array{write:?bool,status:string,reason:string,keep:array<int,mixed>,ttl:?int} write is true to write.
	 */
	public static function plan( array $row, array $here, string $zone, array $ours = array() ): array {
		$none = array( 'write' => null, 'status' => 'ok', 'reason' => '', 'keep' => array(), 'ttl' => null );
		$type = (string) $row['type'];

		if ( 'CNAME' === $type ) {
			$verdict = ZoneWriter::cname_verdict( (string) $row['value'], $here );

			if ( 'write' === $verdict ) {
				return array_merge( $none, array( 'write' => true ) );
			}

			return 'present' === $verdict ? $none : array_merge( $none, array( 'status' => 'conflict', 'reason' => 'name_taken' ) );
		}

		if ( isset( $here['CNAME'] ) ) {
			return array_merge( $none, array( 'status' => 'conflict', 'reason' => 'name_alias' ) );
		}

		$set  = $here[ $type ] ?? null;
		$have = self::values( $zone, $set );
		$ttl  = $set ? (int) ( $set['ttl'] ?? self::TTL ) : null;

		if ( 'MX' === $type ) {
			if ( null === $set ) {
				return array_merge( $none, array( 'write' => true ) );
			}

			return array( '10 ' . strtolower( (string) $row['value'] ) ) === $have
				? $none
				: array_merge( $none, array( 'status' => 'conflict', 'reason' => 'mx_other' ) );
		}

		// TXT from here.
		$keep = $set ? array_values( (array) ( $set['records'] ?? array() ) ) : array();

		switch ( $row['key'] ) {
			case 'spf':
				$spf = array_values( array_filter( $have, static fn( $v ) => 0 === stripos( $v, 'v=spf1' ) ) );

				if ( array() === $spf ) {
					return array( 'write' => true, 'status' => 'ok', 'reason' => '', 'keep' => $keep, 'ttl' => $ttl );
				}

				return 1 === count( $spf ) && false !== stripos( $spf[0], 'include:' . MailSettings::spf_include() )
					? $none
					: array_merge( $none, array( 'status' => 'conflict', 'reason' => 'spf_exists' ) );

			case 'dkim':
				if ( array() === $have ) {
					return array_merge( $none, array( 'write' => true ) );
				}

				$squash = static fn( $v ) => preg_replace( '/\s+/', '', (string) $v );

				if ( in_array( $squash( $row['value'] ), array_map( $squash, $have ), true ) ) {
					return $none;
				}

				// Every key at the name is one we wrote before: the mail server made a new key since, ours is replaced.
				if ( array() !== $ours && array() === array_diff( array_map( $squash, $have ), array_map( $squash, $ours ) ) ) {
					return array( 'write' => true, 'status' => 'ok', 'reason' => '', 'keep' => array(), 'ttl' => $ttl );
				}

				return array_merge( $none, array( 'status' => 'conflict', 'reason' => 'dkim_other' ) );

			case 'dmarc':
				if ( array() === $have ) {
					return array_merge( $none, array( 'write' => true ) );
				}

				// A policy the customer chose is theirs: kept.
				return array_merge( $none, array( 'reason' => 'dmarc_kept' ) );
		}

		return array_merge( $none, array( 'status' => 'conflict', 'reason' => 'unplaced' ) );
	}

	/**
	 * Comparable values of one raw RRset: TXT as the joined text, MX as "priority host", CNAME as the lower case host.
	 *
	 * @param string                    $zone Zone.
	 * @param array<string, mixed>|null $set  Raw RRset.
	 * @return string[]
	 */
	private static function values( string $zone, ?array $set ): array {
		if ( null === $set ) {
			return array();
		}

		$out = array();

		foreach ( Records::to_rows( $zone, array( $set ) ) as $row ) {
			if ( ! empty( $row['disabled'] ) ) {
				continue;
			}

			$value = strtolower( (string) $row['value'] );
			$out[] = 'MX' === $row['type'] ? (int) ( $row['priority'] ?? 0 ) . ' ' . $value : ( 'TXT' === $row['type'] ? (string) $row['value'] : $value );
		}

		return $out;
	}

	// -- What we wrote, and taking it away again ---------------------------------------------------

	/** The record values we wrote into zones of ours, by domain and key: what a deleted service leaves behind remembered. */
	public const WRITTEN_OPTION = 'beltoft_services_mail_written';

	/**
	 * The values of one record kind that we wrote into the zone of this domain, from this service or an earlier one. They tell a
	 * stale key of ours from a key the customer made. Kept in an option because a deleted service's row is closed and is not found
	 * by its meta any more.
	 *
	 * @param string $zone Zone (the domain).
	 * @param string $key  Record key.
	 * @return string[]
	 */
	private static function written_before( string $zone, string $key ): array {
		$all = get_option( self::WRITTEN_OPTION, array() );

		return array_values( array_map( 'strval', (array) ( is_array( $all ) ? ( $all[ $zone ][ $key ] ?? array() ) : array() ) ) );
	}

	/**
	 * Remembers a value we wrote (or forgets it after it was removed again).
	 *
	 * @param string $zone  Zone.
	 * @param string $key   Record key.
	 * @param string $value The value.
	 * @param bool   $add   True to remember, false to forget.
	 */
	private static function remember_written( string $zone, string $key, string $value, bool $add ): void {
		$all = get_option( self::WRITTEN_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$had = array_values( array_map( 'strval', (array) ( $all[ $zone ][ $key ] ?? array() ) ) );

		$had = $add ? array_slice( array_values( array_unique( array_merge( $had, array( $value ) ) ) ), -5 ) : array_values( array_diff( $had, array( $value ) ) );

		if ( array() === $had ) {
			unset( $all[ $zone ][ $key ] );
		} else {
			$all[ $zone ][ $key ] = $had;
		}

		if ( array() === ( $all[ $zone ] ?? array( 1 ) ) ) {
			unset( $all[ $zone ] );
		}

		update_option( self::WRITTEN_OPTION, $all, false );
	}

	/**
	 * Takes one record we wrote out of the customer's zone, only the records of its set that hold exactly the value we wrote:
	 * a set with other records keeps them, a record changed since is the customer's and stays. Always through the zone writer,
	 * under the zone lock, after reading the zone again.
	 *
	 * @param int                  $user_id Customer; the zone must be one of theirs on our name servers.
	 * @param string               $zone    Zone.
	 * @param array<string, mixed> $row     The stored row (key, name, type, value, priority).
	 * @return bool|\WP_Error True when done or when nothing of ours was there, an error when the zone could not be changed.
	 */
	public static function unpublish( int $user_id, string $zone, array $row ) {
		$fqdn = '@' === $row['name'] ? $zone : $row['name'] . '.' . $zone;
		$type = (string) $row['type'];

		$done = ZoneWriter::remove(
			$user_id,
			$zone,
			static function ( array $data ) use ( $zone, $fqdn, $type, $row ): array {
				$here = Records::sets_at( $zone, $fqdn, (array) ( $data['rrsets'] ?? array() ) );
				$set  = $here[ $type ] ?? null;

				if ( null === $set ) {
					return array();
				}

				$keep    = array();
				$removed = false;

				foreach ( (array) ( $set['records'] ?? array() ) as $record ) {
					$one = Records::to_rows( $zone, array( array_merge( $set, array( 'records' => array( $record ) ) ) ) );

					if ( 1 === count( $one ) && self::is_ours( $row, $one[0] ) ) {
						$removed = true;
						continue;
					}

					$keep[] = $record;
				}

				if ( ! $removed ) {
					return array();
				}

				if ( array() === $keep ) {
					return array( array( 'name' => $set['name'], 'type' => $type, 'changetype' => 'DELETE' ) );
				}

				return array( array( 'name' => $set['name'], 'type' => $type, 'ttl' => (int) ( $set['ttl'] ?? self::TTL ), 'changetype' => 'REPLACE', 'records' => $keep ) );
			}
		);

		if ( true === $done ) {
			self::remember_written( $zone, (string) $row['key'], (string) $row['value'], false );
		}

		return $done;
	}

	/**
	 * Does a record of the zone hold exactly what we wrote?
	 *
	 * @param array<string, mixed> $row    The stored row.
	 * @param array<string, mixed> $record An editor row of the zone.
	 */
	private static function is_ours( array $row, array $record ): bool {
		$strip = static fn( $v ) => strtolower( rtrim( trim( (string) $v ), '.' ) );

		switch ( $row['type'] ) {
			case 'MX':
				return $strip( $record['value'] ?? '' ) === $strip( $row['value'] ) && (int) ( $record['priority'] ?? 0 ) === (int) ( $row['priority'] ?? 10 );
			case 'CNAME':
				return $strip( $record['value'] ?? '' ) === $strip( $row['value'] );
		}

		// TXT: the same text, a long key may have been split into pieces by the server.
		return preg_replace( '/\s+/', '', (string) ( $record['value'] ?? '' ) ) === preg_replace( '/\s+/', '', (string) $row['value'] );
	}

	// -- Public DNS, for domains we do not serve -------------------------------------------------

	/**
	 * Does a stored row hold, given what public DNS answers at its name?
	 *
	 * @param array<string, mixed> $row   Row.
	 * @param string[]             $found Values found: MX as "priority host", TXT as joined text, CNAME as host.
	 */
	public static function row_holds( array $row, array $found ): bool {
		$found = array_map( static fn( $v ) => strtolower( rtrim( trim( (string) $v ), '.' ) ), $found );
		$want  = strtolower( rtrim( (string) $row['value'], '.' ) );

		switch ( $row['key'] ) {
			case 'mx':
				foreach ( $found as $value ) {
					if ( preg_match( '/^\d+\s+(\S+)$/D', $value, $m ) && rtrim( $m[1], '.' ) === $want ) {
						return true;
					}
				}

				return false;
			case 'spf':
				foreach ( $found as $value ) {
					if ( 0 === strpos( $value, 'v=spf1' ) && false !== strpos( $value, 'include:' . MailSettings::spf_include() ) ) {
						return true;
					}
				}

				return false;
			case 'dkim':
				$have = array_map( static fn( $v ) => preg_replace( '/\s+/', '', $v ), $found );

				return in_array( preg_replace( '/\s+/', '', $want ), $have, true );
			case 'dmarc':
				foreach ( $found as $value ) {
					if ( 0 === strpos( $value, 'v=dmarc1' ) ) {
						return true;
					}
				}

				return false;
			default:
				return in_array( $want, $found, true );
		}
	}

	/**
	 * The MX records of a domain against ours: ours is missing, alone, or has company. Company with the same or a better priority
	 * (a lower number) splits the mail between two servers; with a worse priority it is only worth a note. The setting
	 * mail_dns_mx_strict turns the first into a note too.
	 *
	 * @param array<string, mixed> $row   The stored MX row.
	 * @param string[]             $found Values found: "priority host".
	 * @return array{0:string,1:string[]} Verdict (missing, ok, ok_note, wrong) and the other records as "priority host".
	 */
	public static function mx_verdict( array $row, array $found ): array {
		$want   = strtolower( rtrim( (string) $row['value'], '.' ) );
		$ours   = array();
		$others = array();

		foreach ( $found as $value ) {
			if ( 1 !== preg_match( '/^(\d+)\s+(\S+)$/D', strtolower( rtrim( trim( (string) $value ), '.' ) ), $m ) ) {
				continue;
			}

			$host = rtrim( $m[2], '.' );

			if ( $host === $want ) {
				$ours[] = (int) $m[1];
			} else {
				$others[ (int) $m[1] . ' ' . $host ] = (int) $m[1];
			}
		}

		if ( array() === $ours ) {
			return array( 'missing', array() );
		}

		if ( array() === $others ) {
			return array( 'ok', array() );
		}

		asort( $others );
		$list = array_slice( array_keys( $others ), 0, 3 );

		return array( MailSettings::dns_mx_strict() && min( $others ) <= min( $ours ) ? 'wrong' : 'ok_note', $list );
	}

	/**
	 * What public DNS answers for a name (see PublicDns::lookup).
	 *
	 * @param string $fqdn Name.
	 * @param string $type MX, TXT or CNAME.
	 * @return string[]|\WP_Error
	 */
	public static function lookup( string $fqdn, string $type ) {
		return PublicDns::lookup( $fqdn, $type );
	}

	// -- The job ---------------------------------------------------------------------------------

	/**
	 * Is the resource waiting for records?
	 *
	 * @param Resource $r Resource.
	 */
	public static function waiting( Resource $r ): bool {
		return 'waiting' === (string) $r->get( 'mail_dns' );
	}

	/**
	 * The meta that records the outcome of the first pass: the rows, and whether a re-check follows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with status.
	 * @return array<string, mixed>
	 */
	public static function meta_for( array $rows ): array {
		return array(
			'mail_records'     => $rows,
			'mail_dns'         => self::complete( $rows ) ? 'ok' : 'waiting',
			'mail_dns_since'   => time(),
			'mail_dns_checked' => null,
		);
	}

	/**
	 * Queues one read for a resource, unless one is pending.
	 *
	 * @param int $id    Resource id.
	 * @param int $delay Seconds from now.
	 */
	public static function schedule( int $id, int $delay ): void {
		Jobs::schedule_once( time() + $delay, self::HOOK, array( $id ) );
	}

	/**
	 * Starts the look again (the customer asks, after the 14 days ran out).
	 *
	 * @param Resource $r Resource.
	 */
	public static function restart( Resource $r ): bool {
		$stored = $r->locked(
			static function () use ( $r ): bool {
				$fresh = Resource::find( $r->id() );

				return $fresh && $fresh->set( array( 'mail_dns' => 'waiting', 'mail_dns_since' => time(), 'mail_dns_checked' => null ) );
			}
		);

		if ( true === $stored ) {
			self::schedule( $r->id(), 0 );
		}

		return true === $stored;
	}

	/**
	 * The job of the shared action `mail_dns_check` (MailDriver::act): the customer asked for a look now. It starts the look again and
	 * makes it, which publishes records into the customer's zone and changes the sending hold, so it runs as a job and not in the
	 * request. Safe to run again: every record is read or published again and the rows are stored as they are found.
	 *
	 * @param Resource $r Resource.
	 */
	public static function run_check( Resource $r ): StepResult {
		if ( ! $r->is_live() ) {
			return StepResult::fail( 'The email service is not available.' );
		}

		if ( ! self::restart( $r ) ) {
			return StepResult::retry( 30, 'The look could not be started.' );
		}

		self::run( $r->id() );

		$fresh = Resource::find( $r->id() ) ?? $r;

		return StepResult::done( array( Jobs::TEXT => self::check_text( (array) $fresh->get( 'mail_records' ) ) ) );
	}

	/**
	 * What the customer reads when a look they asked for has ended.
	 *
	 * @param array<int, array<string, mixed>> $rows Stored rows with their status.
	 */
	public static function check_text( array $rows ): string {
		if ( self::complete( $rows ) ) {
			return __( 'Checked. All the needed DNS records were found.', 'beltoft-services' );
		}

		$missing = 0;

		foreach ( $rows as $row ) {
			if ( 'ok' !== ( $row['status'] ?? '' ) && self::required( (string) ( $row['key'] ?? '' ) ) ) {
				++$missing;
			}
		}

		return sprintf(
			/* translators: %d: number of needed DNS records that were not found. */
			_n( 'Checked. %d needed DNS record is not found yet. Add it, then check again.', 'Checked. %d needed DNS records are not found yet. Add them, then check again.', max( 1, $missing ), 'beltoft-services' ),
			max( 1, $missing )
		);
	}

	/**
	 * The watchdog's list (Background): every domain still waiting for records should have a read queued. A read that was
	 * lost (a worker stopped, an action removed) is queued again by the shared sweep.
	 *
	 * @return array<int, array<int, int>>
	 */
	public static function due(): array {
		return array_map( static fn( $id ): array => array( (int) $id ), Resource::find_by_meta( MailDriver::KIND, 'mail_dns', 'waiting' ) );
	}

	/**
	 * One bounded read of public DNS for one domain: each record that is not ok yet is looked up, the result is stored
	 * under the resource lock after the resource was read again, and the next read is queued.
	 *
	 * @param int $id Resource id.
	 */
	public static function run( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || ! self::waiting( $r ) ) {
			return;
		}

		$now     = time();
		$checked = (int) $r->get( 'mail_dns_checked' );

		if ( $checked > 0 && $now - $checked < self::MIN_GAP ) {
			self::schedule( $id, self::MIN_GAP - ( $now - $checked ) );
			return;
		}

		$since = (int) $r->get( 'mail_dns_since' );

		if ( $since > 0 && $now >= $since + self::WAIT_DAYS * DAY_IN_SECONDS ) {
			if ( self::store( $id, array( 'mail_dns' => 'timeout', 'mail_dns_checked' => $now, 'mail_dns_rechecked' => $now ), null ) ) {
				self::timeout_notice( $id );
			}

			return;
		}

		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$rows   = (array) $r->get( 'mail_records' );
		$user   = (int) ( $r->license()['user_id'] ?? 0 );
		$ours   = MailDomain::on_our_servers( $domain, $user );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || 'ok' === ( $row['status'] ?? '' ) ) {
				continue;
			}

			// A zone of ours: try to write the record again. publish() re-checks the owner, takes the zone lock and never
			// overwrites a record that conflicts, so a customer who removed the conflicting record gets ours written.
			if ( $ours ) {
				$result = self::publish( $user, $domain, $row );

				if ( ! is_wp_error( $result ) ) {
					$rows[ $i ]['status'] = $result[0];
					$rows[ $i ]['reason'] = $result[1];
					// Stored as each record is published, so a stopped run never loses what it already wrote into the zone.
					self::store( $id, array( 'mail_records' => $rows ), null );
				}

				continue;
			}

			$read = self::read_row( $row, $domain );

			if ( null !== $read ) {
				$rows[ $i ] = $read;
			}
		}

		$done = self::complete( $rows );
		$meta = array( 'mail_records' => $rows, 'mail_dns_checked' => $now, 'mail_mode' => $ours ? 'ours' : 'external' );

		if ( $done ) {
			$meta['mail_dns'] = 'ok';
		}

		if ( self::store( $id, $meta, $done ? null : self::INTERVAL ) ) {
			// Sending is held or lifted from the records just stored (the system mailbox of Email lite included).
			MailHold::sync( Resource::find( $id ) ?? $r );

			if ( $done ) {
				self::after_found( $id );
			} else {
				self::remind( $id );
				self::lost_reminders( $id );
			}

			return;
		}

		self::schedule( $id, 60 );
	}

	/**
	 * Reads public DNS for one record of a domain hosted elsewhere and returns the row with its status: ok when it holds,
	 * missing (or conflict, kept) with what is there now when it does not.
	 *
	 * @param array<string, mixed> $row    Stored row.
	 * @param string               $domain Domain.
	 * @return array<string, mixed>|null The row, or null when the lookup failed (nothing is learned).
	 */
	private static function read_row( array $row, string $domain ): ?array {
		$fqdn  = '@' === $row['name'] ? $domain : $row['name'] . '.' . $domain;
		$found = self::lookup( $fqdn, (string) $row['type'] );

		if ( is_wp_error( $found ) ) {
			return null;
		}

		if ( self::row_holds( $row, $found ) ) {
			$row['status'] = 'ok';
			$row['reason'] = '';
			unset( $row['seen'], $row['merged'] );

			// Ours is there: another mail server next to it is a problem or a note, by its priority.
			if ( 'mx' === $row['key'] ) {
				list( $verdict, $others ) = self::mx_verdict( $row, $found );

				if ( 'wrong' === $verdict ) {
					$row['status'] = 'conflict';
					$row['reason'] = 'mx_second';
					$row['seen']   = $others;
				} elseif ( 'ok_note' === $verdict ) {
					$row['seen'] = $others;
				}
			}

			return $row;
		}

		if ( 'conflict' !== $row['status'] ) {
			$row['status'] = 'missing';
		}

		// What is there now, for the guide: another MX, another SPF (and the SPF with our include added), another key.
		$seen = array_values( array_filter( array_map( static fn( $v ) => substr( trim( (string) $v ), 0, 400 ), 'spf' === $row['key'] ? array_filter( $found, static fn( $v ) => 0 === stripos( (string) $v, 'v=spf1' ) ) : $found ), static fn( $v ) => '' !== $v ) );

		// A name that holds an address where the alias should be (a proxy in front of it answers like that).
		if ( 'CNAME' === $row['type'] && array() === $seen ) {
			$addresses = self::lookup( $fqdn, 'A' );

			if ( ! is_wp_error( $addresses ) ) {
				$seen = array_values( array_filter( array_map( 'strval', $addresses ), static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_IP ) ) );
			}
		}

		$row['seen'] = array_slice( $seen, 0, 3 );

		if ( 'spf' === $row['key'] && array() !== $seen ) {
			$row['merged'] = self::merge_spf( $seen[0] );
		}

		return $row;
	}

	// -- The daily look after the records were found ---------------------------------------------

	/**
	 * Hooks the daily look: a sweep that queues one look per domain, and the look.
	 */
	public static function register_recheck(): void {
		add_action( self::RECHECK_ALL, array( __CLASS__, 'recheck_all' ) );
		add_action( self::RECHECK, array( __CLASS__, 'recheck' ) );
		// One look a day per domain, queued by the daily sweep: claimed by the runners and covered when it crashes. No watchdog.
		Background::declare( self::RECHECK, array( 'resource' => true, 'label' => 'Email DNS daily look' ) );
		Jobs::recurring( array( __CLASS__, 'schedule_recheck' ) );
	}

	/**
	 * Schedules the sweep once (the times of day follow the setting).
	 */
	public static function schedule_recheck(): void {
		Jobs::ensure_cron( self::RECHECK_ALL, MailSettings::dns_recheck_cron(), array(), HOUR_IN_SECONDS );
	}

	/**
	 * The sweep: one look per domain whose records were found, spread over the hours until the next sweep so the lookups of
	 * many domains never come at once, and one slow look per domain that stopped being looked at after 14 days, until the day the
	 * setting names. Domains still waiting are not in it (the ten minute look has them).
	 */
	public static function recheck_all(): void {
		$step   = 0;
		$spread = MailSettings::dns_recheck_spread();

		foreach ( Resource::find_by_meta( MailDriver::KIND, 'mail_dns', 'ok' ) as $id ) {
			Jobs::schedule_once( time() + min( $step * 4, $spread ), self::RECHECK, array( (int) $id ) );
			++$step;
		}

		foreach ( Resource::find_by_meta( MailDriver::KIND, 'mail_dns', 'timeout' ) as $id ) {
			$r = Resource::find( (int) $id );

			if ( $r && self::slow_look_due( $r ) ) {
				Jobs::schedule_once( time() + min( $step * 4, $spread ), self::RECHECK, array( (int) $id ) );
				++$step;
			}
		}
	}

	/**
	 * Is a domain that stopped being looked at due for its slow look? Not before the days between looks have passed since the
	 * last one, never after the day the setting names.
	 *
	 * @param Resource $r Resource.
	 */
	private static function slow_look_due( Resource $r ): bool {
		if ( ! $r->is_live() || 'ready' !== (string) $r->get( 'state' ) || 'timeout' !== (string) $r->get( 'mail_dns' ) ) {
			return false;
		}

		$since = (int) $r->get( 'mail_dns_since' );

		if ( $since > 0 && time() >= $since + MailSettings::dns_stop_days() * DAY_IN_SECONDS ) {
			return false;
		}

		return time() - (int) $r->get( 'mail_dns_rechecked' ) >= MailSettings::dns_weekly_days() * DAY_IN_SECONDS - 12 * HOUR_IN_SECONDS;
	}

	/**
	 * The look at one domain after its records were found (or, slowly, after we stopped looking): all six records are read once and
	 * stored, so the recommended ones show their real state. For a zone of ours each record is written again where it is missing
	 * and free (publish never overwrites a record that is somebody's). A needed record (MX, SPF or DKIM) that is no longer found is
	 * confirmed with a second read straight away; if it is still gone the domain goes back to waiting, sending is held when SPF or
	 * DKIM is the one that went, and a confirmation is queued a few minutes later: only then is the customer told, once.
	 *
	 * A lookup that fails changes nothing. At most one look per guard time for a domain (mail_dns_rechecked).
	 *
	 * @param int $id Resource id.
	 */
	public static function recheck( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || 'ready' !== (string) $r->get( 'state' ) ) {
			return;
		}

		$status = (string) $r->get( 'mail_dns' );

		if ( 'ok' !== $status && 'timeout' !== $status ) {
			return;
		}

		$timeout = 'timeout' === $status;
		$now     = time();

		if ( $timeout ? ! self::slow_look_due( $r ) : $now - (int) $r->get( 'mail_dns_rechecked' ) < MailSettings::dns_recheck_guard() ) {
			return;
		}

		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$rows   = (array) $r->get( 'mail_records' );
		$user   = (int) ( $r->license()['user_id'] ?? 0 );

		if ( '' === $domain || array() === $rows ) {
			return;
		}

		// A zone of ours is asked, once a week, whether the name servers still point to us (MailDomain::on_our_servers follows it).
		MailMoved::watch( $r );

		$mode   = MailHold::mode( $r );
		$ours   = 'ours' === $mode;
		$stored = (string) $r->get( 'mail_mode' );

		// A name that became ours since the last look (the customer moved their DNS to us): the records are written into the new zone.
		if ( $ours && 'ours' !== $stored && '' !== $stored ) {
			self::adopt_zone( $r );
			return;
		}

		$before = self::ok_keys( $rows );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'], $row['type'], $row['value'], $row['key'] ) ) {
				continue;
			}

			if ( $ours ) {
				$result = self::publish( $user, $domain, $row );

				if ( ! is_wp_error( $result ) ) {
					$rows[ $i ] = self::with_result( $rows[ $i ], $result );
				}

				continue;
			}

			$read = self::read_row( $row, $domain );

			// Not found: look once more before saying so, so a flaky answer never holds a customer's sending.
			if ( null !== $read && 'ok' !== $read['status'] && 'ok' === ( $row['status'] ?? '' ) ) {
				$read = self::read_row( $row, $domain );

				if ( null !== $read && 'ok' !== $read['status'] ) {
					$rows[ $i ] = $read;
				}

				continue;
			}

			if ( null !== $read ) {
				$rows[ $i ] = $read;
			}
		}

		$lost = ! self::complete( $rows );
		$meta = array( 'mail_records' => $rows, 'mail_dns_rechecked' => $now );
		$gone = array_values( array_intersect( self::NEEDED, $before, array_diff( self::NEEDED, self::ok_keys( $rows ) ) ) );

		// The domain was ours when it was set up and is not on our servers any more: the stored mode follows the live one.
		$left = ! $ours && 'external' !== $stored;

		if ( $left ) {
			$meta['mail_mode'] = 'external';
		}

		if ( $left && MailMoved::away( $domain, $user ) ) {
			$meta['mail_dns_moved'] = $now;
		}

		if ( $timeout ) {
			self::finish_slow_look( $r, $meta, ! $lost );
			return;
		}

		if ( $lost ) {
			// Waiting again, as if just set up; the reminder of a new domain is not sent, the confirmation below decides on mail.
			$meta += array(
				'mail_dns'              => 'waiting',
				'mail_dns_since'        => $now,
				'mail_dns_checked'      => $now,
				'mail_dns_reminded'     => $now,
				'mail_dns_lost_pending' => $now,
				'mail_dns_lost_keys'    => $gone,
				'mail_dns_lost_at'      => 0,
				'mail_dns_lost_mails'   => 0,
			);
		}

		$stored_ok = $r->locked(
			static function () use ( $id, $meta ): bool {
				$fresh = Resource::find( $id );

				return $fresh && 'ok' === (string) $fresh->get( 'mail_dns' ) && $fresh->set( $meta );
			}
		);

		if ( true !== $stored_ok ) {
			return;
		}

		$fresh = Resource::find( $id ) ?? $r;

		MailHold::sync( $fresh );

		if ( $left ) {
			\BeltoftServices\Alerts::mail_domain_left( $fresh, $domain, $lost );
		}

		if ( $lost ) {
			self::schedule( $id, self::INTERVAL );
			Jobs::schedule_once( time() + MailSettings::dns_confirm_seconds(), self::CONFIRM, array( $id ) );
		}
	}

	/**
	 * The end of a slow look at a domain we stopped looking at: the rows are stored; when all needed records are found the domain is
	 * ok again, else the admin hears once when it has been without email DNS for the days the setting names.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $meta  Meta to store.
	 * @param bool                 $found All needed records were found.
	 */
	private static function finish_slow_look( Resource $r, array $meta, bool $found ): void {
		$id = $r->id();

		if ( $found ) {
			$meta['mail_dns'] = 'ok';
		}

		$stored = $r->locked(
			static function () use ( $id, $meta ): bool {
				$fresh = Resource::find( $id );

				return $fresh && 'timeout' === (string) $fresh->get( 'mail_dns' ) && $fresh->set( $meta );
			}
		);

		if ( true !== $stored ) {
			return;
		}

		$fresh = Resource::find( $id ) ?? $r;

		MailHold::sync( $fresh );

		if ( $found ) {
			self::after_found( $id );
			return;
		}

		$since = (int) $fresh->get( 'mail_dns_since' );

		if ( $since > 0 && time() >= $since + MailSettings::dns_admin_days() * DAY_IN_SECONDS && ! (int) $fresh->get( 'mail_dns_admin_alerted' ) ) {
			$marked = $fresh->locked(
				static function () use ( $id ): bool {
					$again = Resource::find( $id );

					return $again && ! (int) $again->get( 'mail_dns_admin_alerted' ) && $again->set( array( 'mail_dns_admin_alerted' => time() ) );
				}
			);

			if ( true === $marked ) {
				\BeltoftServices\Alerts::mail_domain_no_dns( $fresh, (string) $fresh->get( MailDomain::META_DOMAIN ), MailSettings::dns_admin_days() );
			}
		}
	}

	/**
	 * The second read of a record that was found before and was not found by the daily look. Only if it is still gone is the
	 * loss real: the customer gets the first email and the staff are told. A record that is back is no event at all.
	 *
	 * @param int $id Resource id.
	 */
	public static function confirm( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || ! self::waiting( $r ) || (int) $r->get( 'mail_dns_lost_pending' ) <= 0 ) {
			return;
		}

		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$user   = (int) ( $r->license()['user_id'] ?? 0 );
		$ours   = MailDomain::on_our_servers( $domain, $user );
		$keys   = MailDnsState::lost_keys( $r );
		$rows   = (array) $r->get( 'mail_records' );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || ! in_array( (string) ( $row['key'] ?? '' ), $keys, true ) || 'ok' === ( $row['status'] ?? '' ) ) {
				continue;
			}

			if ( $ours ) {
				$result = self::publish( $user, $domain, $row );

				if ( ! is_wp_error( $result ) ) {
					$rows[ $i ] = self::with_result( $rows[ $i ], $result );
				}

				continue;
			}

			$read = self::read_row( $row, $domain );

			if ( null !== $read ) {
				$rows[ $i ] = $read;
			}
		}

		$still = array_values( array_intersect( $keys, array_diff( self::NEEDED, self::ok_keys( $rows ) ) ) );
		$clear = array( 'mail_dns_lost_pending' => 0 );

		if ( array() === $still || self::complete( $rows ) ) {
			// It is back: nobody was told, nobody needs to be.
			$meta = array_merge( $clear, array( 'mail_records' => $rows, 'mail_dns' => 'ok', 'mail_dns_lost_keys' => array(), 'mail_dns_checked' => time() ) );

			if ( self::store( $id, $meta, null ) ) {
				MailHold::sync( Resource::find( $id ) ?? $r );
			}

			return;
		}

		$meta = array_merge( $clear, array( 'mail_records' => $rows, 'mail_dns_lost_keys' => $still, 'mail_dns_lost_at' => time(), 'mail_dns_lost_mails' => 1 ) );

		if ( ! self::store( $id, $meta, null ) ) {
			self::schedule_confirm_again( $id );
			return;
		}

		$fresh = Resource::find( $id ) ?? $r;

		MailHold::sync( $fresh );
		\BeltoftServices\Emails\Emails::send( 'beltoft_services_mail_dns_lost', $fresh );
		\BeltoftServices\Alerts::mail_domain_lost( $fresh, $domain, $still, MailSettings::dns_admin_throttle() );
	}

	/**
	 * The resource lock was busy: the confirmation is tried again in a minute.
	 *
	 * @param int $id Resource id.
	 */
	private static function schedule_confirm_again( int $id ): void {
		Jobs::schedule_once( time() + 60, self::CONFIRM, array( $id ) );
	}

	/**
	 * The reminders of a loss that goes on: one email on each day the setting names, counted from the day the loss was confirmed,
	 * never more than the days named plus the first email. The count is stored under the resource lock before the mail goes out,
	 * so a retry never mails twice.
	 *
	 * @param int $id Resource id.
	 */
	public static function lost_reminders( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || ! self::waiting( $r ) ) {
			return;
		}

		$at    = (int) $r->get( 'mail_dns_lost_at' );
		$sent  = (int) $r->get( 'mail_dns_lost_mails' );
		$days  = MailSettings::dns_remind_days();
		$index = $sent - 1;

		if ( $at <= 0 || $sent < 1 || $index >= count( $days ) || time() < $at + $days[ $index ] * DAY_IN_SECONDS ) {
			return;
		}

		$marked = $r->locked(
			static function () use ( $id, $sent ): bool {
				$fresh = Resource::find( $id );

				return $fresh && self::waiting( $fresh ) && (int) $fresh->get( 'mail_dns_lost_mails' ) === $sent && $fresh->set( array( 'mail_dns_lost_mails' => $sent + 1 ) );
			}
		);

		if ( true === $marked ) {
			\BeltoftServices\Emails\Emails::send( 'beltoft_services_mail_dns_lost', Resource::find( $id ) ?? $r );
		}
	}

	/**
	 * The needed records are found again. When the customer was told that some were lost, they are told that it is over, once;
	 * every mark of the loss is cleared either way.
	 *
	 * @param int $id Resource id.
	 */
	public static function after_found( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || 'ok' !== (string) $r->get( 'mail_dns' ) ) {
			return;
		}

		$told  = (int) $r->get( 'mail_dns_lost_at' ) > 0 && (int) $r->get( 'mail_dns_lost_mails' ) > 0;
		$marks = array( 'mail_dns_lost_at', 'mail_dns_lost_keys', 'mail_dns_lost_mails', 'mail_dns_lost_pending', 'mail_dns_moved', 'mail_dns_timeout_mailed', 'mail_dns_admin_alerted' );
		$set   = false;

		foreach ( $marks as $mark ) {
			$value = $r->get( $mark );
			$set   = $set || ( is_array( $value ) ? array() !== $value : (int) $value > 0 );
		}

		if ( ! $set ) {
			return;
		}

		$cleared = $r->locked(
			static function () use ( $id, $marks ): bool {
				$fresh = Resource::find( $id );
				$meta  = array();

				foreach ( $marks as $mark ) {
					$meta[ $mark ] = 'mail_dns_lost_keys' === $mark ? array() : 0;
				}

				return $fresh && 'ok' === (string) $fresh->get( 'mail_dns' ) && $fresh->set( $meta );
			}
		);

		if ( true === $cleared && $told ) {
			\BeltoftServices\Emails\Emails::send( 'beltoft_services_mail_dns_back', Resource::find( $id ) ?? $r );
		}
	}

	/**
	 * The look stopped after 14 days: the customer is told once. The mark is stored under the resource lock before the mail goes.
	 *
	 * @param int $id Resource id.
	 */
	public static function timeout_notice( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || 'timeout' !== (string) $r->get( 'mail_dns' ) || (int) $r->get( 'mail_dns_timeout_mailed' ) > 0 ) {
			return;
		}

		$marked = $r->locked(
			static function () use ( $id ): bool {
				$fresh = Resource::find( $id );

				return $fresh && 'timeout' === (string) $fresh->get( 'mail_dns' ) && ! (int) $fresh->get( 'mail_dns_timeout_mailed' ) && $fresh->set( array( 'mail_dns_timeout_mailed' => time() ) );
			}
		);

		if ( true === $marked ) {
			\BeltoftServices\Emails\Emails::send( 'beltoft_services_mail_dns_timeout', Resource::find( $id ) ?? $r );
		}
	}

	/**
	 * The name is on our name servers now and the records were made for it elsewhere: every record is written into the new zone
	 * (or reported as a conflict), by the ten minute look.
	 *
	 * @param Resource $r Resource.
	 */
	public static function adopt_zone( Resource $r ): void {
		$id   = $r->id();
		$rows = array();

		foreach ( (array) $r->get( 'mail_records' ) as $row ) {
			if ( is_array( $row ) ) {
				$row['status'] = 'missing';
				$row['reason'] = '';
				unset( $row['seen'], $row['merged'] );
				$rows[] = $row;
			}
		}

		$done = $r->locked(
			static function () use ( $id, $rows ): bool {
				$fresh = Resource::find( $id );

				return $fresh && $fresh->set(
					array(
						'mail_mode'         => 'ours',
						'mail_records'      => $rows,
						'mail_dns'          => 'waiting',
						'mail_dns_since'    => time(),
						'mail_dns_checked'  => null,
						'mail_dns_reminded' => time(),
						'mail_dns_moved'    => 0,
					)
				);
			}
		);

		if ( true === $done ) {
			self::schedule( $id, 0 );
		}
	}

	/**
	 * The keys of the records that are ok.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return string[]
	 */
	private static function ok_keys( array $rows ): array {
		$ok = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && 'ok' === ( $row['status'] ?? '' ) ) {
				$ok[] = (string) ( $row['key'] ?? '' );
			}
		}

		return $ok;
	}

	/**
	 * One reminder, once, when records of a domain hosted elsewhere are still missing a day after the domain was set up.
	 * The mark is stored under the resource lock before the mail is sent, so a retry never mails twice.
	 *
	 * @param int $id Resource id.
	 */
	public static function remind( int $id ): void {
		$r = Resource::find( $id );

		if ( ! $r || ! $r->is_live() || ! self::waiting( $r ) || 'external' !== (string) $r->get( 'mail_mode' ) || (int) $r->get( 'mail_dns_reminded' ) > 0 ) {
			return;
		}

		$since = (int) $r->get( 'mail_dns_since' );

		if ( $since <= 0 || time() < $since + self::REMIND_AFTER || array() === self::missing_rows( $r, true ) ) {
			return;
		}

		$marked = $r->locked(
			static function () use ( $id ): bool {
				$fresh = Resource::find( $id );

				return $fresh && self::waiting( $fresh ) && ! (int) $fresh->get( 'mail_dns_reminded' ) && $fresh->set( array( 'mail_dns_reminded' => time() ) );
			}
		);

		if ( true === $marked ) {
			\BeltoftServices\Emails\Emails::send( 'beltoft_services_mail_dns_reminder', Resource::find( $id ) ?? $r );
		}
	}

	/**
	 * Stores a result under the resource lock, only while the resource is still waiting.
	 *
	 * @param int                  $id    Resource id.
	 * @param array<string, mixed> $meta  Meta.
	 * @param int|null             $again Seconds until the next read, null for none.
	 * @return bool Whether it was stored.
	 */
	private static function store( int $id, array $meta, ?int $again ): bool {
		$r = Resource::find( $id );

		if ( ! $r ) {
			return false;
		}

		$done = $r->locked(
			static function () use ( $id, $meta ): bool {
				$fresh = Resource::find( $id );

				return $fresh && self::waiting( $fresh ) && $fresh->set( $meta );
			}
		);

		if ( true === $done && null !== $again ) {
			self::schedule( $id, $again );
		}

		return true === $done;
	}
}

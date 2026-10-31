<?php
/**
 * The DNS records an email domain needs: computed, published where we may, re-checked where we may not.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\Records;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\AppDomains;
use BeltoftServices\Resource;

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

	/** Days the records are looked for. */
	public const WAIT_DAYS = 14;

	/** Seconds a record may be cached. */
	public const TTL = 3600;

	/** The DKIM selector mailcow is asked for. */
	public const SELECTOR = 'dkim';

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( Jobs::SWEEP, array( __CLASS__, 'sweep' ) );
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

			$rows[ $i ]['status'] = $result[0];
			$rows[ $i ]['reason'] = $result[1];
		}

		return $rows;
	}

	/**
	 * Is every record in place?
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with status.
	 */
	public static function complete( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( 'ok' !== ( $row['status'] ?? '' ) ) {
				return false;
			}
		}

		return array() !== $rows;
	}

	// -- Writing into a zone of ours -------------------------------------------------------------

	/**
	 * Makes one record in the customer's zone, as one RRset, without touching any other name or type.
	 *
	 * @param int                  $user_id Customer; the zone must be one of theirs on our name servers.
	 * @param string               $zone    Zone (the domain).
	 * @param array<string, mixed> $row     A row of records().
	 * @return array{0:string,1:string}|\WP_Error [ status (ok or conflict), reason ], or a transient error.
	 */
	public static function publish( int $user_id, string $zone, array $row ) {
		if ( ! in_array( $zone, AppDomains::zones( $user_id ), true ) ) {
			return array( 'conflict', 'The domain is not one of your domains on our name servers.' );
		}

		$fqdn = '@' === $row['name'] ? $zone : $row['name'] . '.' . $zone;
		$set  = Records::validate( $zone, array( array_intersect_key( $row + array( 'name' => '@' ), array_flip( array( 'name', 'type', 'ttl', 'value', 'priority' ) ) ) ) );

		if ( is_wp_error( $set ) || 1 !== count( $set ) ) {
			return array( 'conflict', 'The record is not valid for this domain.' );
		}

		$locked = Records::locked(
			$zone,
			static function () use ( $zone, $fqdn, $row, $set ) {
				// Read immediately before the write: what is at the name now decides.
				$zone_data = DnsClients::dns()->zone( $zone );

				if ( is_wp_error( $zone_data ) ) {
					return new \WP_Error( 'transient', 'The DNS zone could not be read.' );
				}

				if ( ! is_array( $zone_data ) ) {
					return array( 'conflict', 'The DNS zone does not exist.' );
				}

				if ( Records::paused( $zone, $zone_data ) ) {
					return array( 'conflict', 'DNS hosting is paused for this domain.' );
				}

				$here = self::sets_at( $zone, $fqdn, (array) ( $zone_data['rrsets'] ?? array() ) );
				$plan = self::plan( $row, $here, $zone );

				if ( null === $plan['write'] ) {
					return array( $plan['status'], $plan['reason'] );
				}

				$write                = $set[0];
				$write['changetype']  = 'REPLACE';
				$write['records']     = array_merge( $plan['keep'], $write['records'] );
				$write['ttl']         = $plan['ttl'] ?? $write['ttl'];
				$done                 = DnsClients::dns()->replace_rrsets( $zone, array( $write ) );

				return is_wp_error( $done ) ? new \WP_Error( 'transient', 'The DNS record could not be saved.' ) : array( 'ok', '' );
			}
		);

		return is_wp_error( $locked ) ? new \WP_Error( 'transient', 'The DNS zone is being changed.' ) : $locked;
	}

	/**
	 * What to do for one record given what the name holds. Pure: no I/O.
	 *
	 * @param array<string, mixed>                 $row  The wanted record.
	 * @param array<string, array<string, mixed>> $here The raw RRsets at the name, by type.
	 * @param string                               $zone Zone.
	 * @return array{write:?bool,status:string,reason:string,keep:array<int,mixed>,ttl:?int} write is true to write.
	 */
	public static function plan( array $row, array $here, string $zone ): array {
		$none = array( 'write' => null, 'status' => 'ok', 'reason' => '', 'keep' => array(), 'ttl' => null );
		$type = (string) $row['type'];

		if ( 'CNAME' === $type ) {
			if ( array() === $here ) {
				return array_merge( $none, array( 'write' => true ) );
			}

			$have = self::values( $zone, $here['CNAME'] ?? null );

			return isset( $here['CNAME'] ) && 1 === count( $here ) && array( strtolower( (string) $row['value'] ) ) === $have
				? $none
				: array_merge( $none, array( 'status' => 'conflict', 'reason' => 'The name already has a DNS record.' ) );
		}

		if ( isset( $here['CNAME'] ) ) {
			return array_merge( $none, array( 'status' => 'conflict', 'reason' => 'The name is an alias (CNAME) and cannot also hold this record.' ) );
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
				: array_merge( $none, array( 'status' => 'conflict', 'reason' => 'The domain already has other MX records.' ) );
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
					: array_merge( $none, array( 'status' => 'conflict', 'reason' => 'The domain already has an SPF record. It was not changed: add include:' . MailSettings::spf_include() . ' to it.' ) );

			case 'dkim':
				if ( array() === $have ) {
					return array_merge( $none, array( 'write' => true ) );
				}

				return in_array( preg_replace( '/\s+/', '', (string) $row['value'] ), array_map( static fn( $v ) => preg_replace( '/\s+/', '', $v ), $have ), true )
					? $none
					: array_merge( $none, array( 'status' => 'conflict', 'reason' => 'The name already holds another DKIM key.' ) );

			case 'dmarc':
				if ( array() === $have ) {
					return array_merge( $none, array( 'write' => true ) );
				}

				// A policy the customer chose is theirs: kept.
				return array_merge( $none, array( 'reason' => 'Your own DMARC record was kept.' ) );
		}

		return array_merge( $none, array( 'status' => 'conflict', 'reason' => 'The record could not be placed.' ) );
	}

	/**
	 * The raw RRsets at a name, by type.
	 *
	 * @param string                             $zone   Zone.
	 * @param string                             $fqdn   Name.
	 * @param array<int, array<string, mixed>> $rrsets Raw RRsets.
	 * @return array<string, array<string, mixed>>
	 */
	private static function sets_at( string $zone, string $fqdn, array $rrsets ): array {
		$out = array();

		foreach ( $rrsets as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}

			$name = strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) );

			if ( ( '' === $name || '@' === $name ? $zone : ( str_ends_with( $name, '.' . $zone ) || $name === $zone ? $name : $name . '.' . $zone ) ) === $fqdn ) {
				$out[ strtoupper( (string) ( $set['type'] ?? '' ) ) ] = $set;
			}
		}

		// SOA and NS are the zone's own and never ours to judge.
		unset( $out['SOA'], $out['NS'] );

		return $out;
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
	 * What public DNS answers for a name, as any visitor sees it. Tests answer through the filter
	 * beltoft_services_mail_dns_lookup; nothing in a test run reaches the network.
	 *
	 * @param string $fqdn Name.
	 * @param string $type MX, TXT or CNAME.
	 * @return string[]|\WP_Error Values (MX as "priority host"), empty when none; an error when no resolver answered.
	 */
	public static function lookup( string $fqdn, string $type ) {
		/**
		 * Replaces the public DNS read (tests only).
		 *
		 * @param array|\WP_Error|null $answer Null to read for real.
		 * @param string               $fqdn   Name.
		 * @param string               $type   MX, TXT or CNAME.
		 */
		$test = apply_filters( 'beltoft_services_mail_dns_lookup', null, $fqdn, $type );

		if ( null !== $test ) {
			return is_wp_error( $test ) ? $test : array_values( array_map( 'strval', (array) $test ) );
		}

		$flag    = array( 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME )[ $type ] ?? DNS_TXT;
		$records = @dns_get_record( rtrim( $fqdn, '.' ) . '.', $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is returned as an error below.

		if ( false === $records ) {
			return new \WP_Error( 'dns_transport', 'The name did not resolve.' );
		}

		$out = array();

		foreach ( $records as $record ) {
			if ( $type !== ( $record['type'] ?? '' ) ) {
				continue;
			}

			if ( 'MX' === $type ) {
				$out[] = (int) ( $record['pri'] ?? 0 ) . ' ' . (string) ( $record['target'] ?? '' );
			} elseif ( 'TXT' === $type ) {
				$out[] = isset( $record['entries'] ) ? implode( '', (array) $record['entries'] ) : (string) ( $record['txt'] ?? '' );
			} else {
				$out[] = (string) ( $record['target'] ?? '' );
			}
		}

		return $out;
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
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$pending = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );

		if ( ! $pending ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), Jobs::GROUP );
		}
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
	 * Recovers reads that were lost (a worker stopped, an action removed).
	 */
	public static function sweep(): void {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( '"mail_dns":"waiting"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table sweep, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE kind = %s AND deactivated_at IS NULL AND meta LIKE %s', \BeltoftLicenses\Install::tables()['activations'], MailDriver::KIND, $like ) );

		foreach ( (array) $ids as $id ) {
			self::schedule( (int) $id, 0 );
		}
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
			self::store( $id, array( 'mail_dns' => 'timeout', 'mail_dns_checked' => $now ), null );
			return;
		}

		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$rows   = (array) $r->get( 'mail_records' );

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || 'ok' === ( $row['status'] ?? '' ) ) {
				continue;
			}

			$fqdn  = '@' === $row['name'] ? $domain : $row['name'] . '.' . $domain;
			$found = self::lookup( $fqdn, (string) $row['type'] );

			if ( is_wp_error( $found ) ) {
				continue;
			}

			if ( self::row_holds( $row, $found ) ) {
				$rows[ $i ]['status'] = 'ok';
				$rows[ $i ]['reason'] = '';
			} elseif ( 'conflict' !== $row['status'] ) {
				$rows[ $i ]['status'] = 'missing';
			}
		}

		$done = self::complete( $rows );
		$meta = array( 'mail_records' => $rows, 'mail_dns_checked' => $now );

		if ( $done ) {
			$meta['mail_dns'] = 'ok';
		}

		if ( self::store( $id, $meta, $done ? null : self::INTERVAL ) ) {
			return;
		}

		self::schedule( $id, 60 );
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

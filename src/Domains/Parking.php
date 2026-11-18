<?php
/**
 * Domain parking: a domain on our name servers that has no address of its own points at a parking page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The parking records are A and AAAA sets at the apex and at www, written through ZoneWriter, short lived (TTL 300) and
 * marked as ours by a stored copy of what was written (option per zone, never autoloaded). A set counts as ours only while
 * its content is still exactly what we wrote.
 *
 * One owner of the removal: ZoneWriter::write() and Records::change() / Records::replace() show their policy the zone
 * without our sets (`split()`), and when what they write is an address (A, AAAA, CNAME, ALIAS) at the apex or at www they
 * delete our sets in the same write (`merge()`). Nothing else needs to know that parking exists. The record editor never
 * lists our sets (`Records::to_rows()`), and a customer's own record is never touched, because only a set equal to the
 * stored copy is ours.
 */
final class Parking {

	public const TTL = 300;

	private const PREFIX = 'beltoft_services_parked_';

	/** Types that want the apex or www for themselves. */
	private const ADDRESS_TYPES = array( 'A', 'AAAA', 'CNAME', 'ALIAS' );

	/**
	 * The parking addresses from the settings, valid ones only.
	 *
	 * @return array<string, string> Type to address (A, AAAA); empty when parking is off.
	 */
	public static function addresses(): array {
		$out  = array();
		$ipv4 = trim( (string) Settings::get( 'parking_ipv4' ) );
		$ipv6 = trim( (string) Settings::get( 'parking_ipv6' ) );

		if ( '' !== $ipv4 && false !== filter_var( $ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$out['A'] = $ipv4;
		}

		if ( '' !== $ipv6 && false !== filter_var( $ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$out['AAAA'] = (string) inet_ntop( (string) inet_pton( $ipv6 ) );
		}

		return $out;
	}

	/** Is parking on? */
	public static function enabled(): bool {
		return array() !== self::addresses();
	}

	/**
	 * What we wrote into a zone, or null.
	 *
	 * @return array{zone:string,sets:array<string,array<int,string>>,at:int}|null
	 */
	public static function state( string $zone ): ?array {
		$value = get_option( self::option( $zone ), null );

		return is_array( $value ) && isset( $value['sets'] ) ? $value : null;
	}

	/** Forgets what we wrote into a zone. */
	public static function forget( string $zone ): void {
		$zone = strtolower( rtrim( $zone, '.' ) );

		if ( delete_option( self::option( $zone ) ) ) {
			/**
			 * Fires when the parking records of a zone were removed.
			 *
			 * @param string $zone Zone.
			 */
			do_action( 'beltoft_services_unparked', $zone );
		}
	}

	/**
	 * Every parked zone.
	 *
	 * @return array<int, array{zone:string,sets:array<string,array<int,string>>,at:int}>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the options of this feature, listed fresh for staff.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( self::PREFIX ) . '%' ) );
		$out   = array();

		foreach ( (array) $names as $name ) {
			$value = get_option( (string) $name, null );

			if ( is_array( $value ) && isset( $value['zone'], $value['sets'] ) ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	/**
	 * Is this raw RRset one we wrote and nobody changed since?
	 *
	 * @param array<string, mixed> $set Raw RRset.
	 */
	public static function is_ours( string $zone, array $set ): bool {
		$state = self::state( $zone );

		if ( null === $state ) {
			return false;
		}

		$key = self::key( (string) ( $set['name'] ?? '' ), (string) ( $set['type'] ?? '' ) );

		return isset( $state['sets'][ $key ] ) && self::contents( $set ) === $state['sets'][ $key ];
	}

	/**
	 * The zone without our sets, and the deletes for them.
	 *
	 * @param array<string, mixed> $zone_data Zone as the DNS server returned it.
	 * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
	 */
	public static function split( array $zone_data ): array {
		$zone = rtrim( strtolower( (string) ( $zone_data['name'] ?? '' ) ), '.' );

		if ( '' === $zone || null === self::state( $zone ) ) {
			return array( $zone_data, array() );
		}

		$keep    = array();
		$deletes = array();

		foreach ( (array) ( $zone_data['rrsets'] ?? array() ) as $set ) {
			if ( is_array( $set ) && self::is_ours( $zone, $set ) ) {
				$deletes[] = array(
					'name'       => (string) $set['name'],
					'type'       => (string) $set['type'],
					'changetype' => 'DELETE',
				);
			} else {
				$keep[] = $set;
			}
		}

		$zone_data['rrsets'] = $keep;

		return array( $zone_data, $deletes );
	}

	/**
	 * Adds the deletes of our sets to a write, when the write wants the apex or www for an address.
	 *
	 * @param string                           $zone     Zone.
	 * @param array<int, array<string, mixed>> $deletes From split().
	 * @param array<int, array<string, mixed>> $decision The RRsets about to be sent.
	 * @return array{0: array<int, array<string, mixed>>, 1: bool} The RRsets to send, and whether parking is being removed.
	 */
	public static function merge( string $zone, array $deletes, array $decision ): array {
		if ( null === self::state( $zone ) || ! self::wants_address( $zone, $decision ) ) {
			return array( $decision, false );
		}

		$replaced = array();

		foreach ( $decision as $set ) {
			$replaced[ self::key( (string) ( $set['name'] ?? '' ), (string) ( $set['type'] ?? '' ) ) ] = true;
		}

		$extra = array();

		foreach ( $deletes as $delete ) {
			if ( ! isset( $replaced[ self::key( (string) $delete['name'], (string) $delete['type'] ) ] ) ) {
				$extra[] = $delete;
			}
		}

		return array( array_merge( $extra, $decision ), true );
	}

	/**
	 * Does a write put an address, alias or CNAME at the apex or at www?
	 *
	 * @param array<int, array<string, mixed>> $sets RRsets about to be sent.
	 */
	private static function wants_address( string $zone, array $sets ): bool {
		foreach ( $sets as $set ) {
			$name = strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) );
			$type = strtoupper( (string) ( $set['type'] ?? '' ) );

			if ( 'DELETE' !== ( $set['changetype'] ?? 'REPLACE' ) && in_array( $type, self::ADDRESS_TYPES, true ) && ( $zone === $name || 'www.' . $zone === $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parks a zone when it qualifies. Writes through ZoneWriter, only A and AAAA at the apex and at www, only when
	 * neither name holds an address, alias or CNAME.
	 *
	 * @param string $zone       Zone (the domain).
	 * @param bool   $only_empty Park only a zone with no record of any kind besides SOA and NS (a new zone).
	 * @return string parked, already, off, no_zone, not_served, paused, has_address or error:<code>.
	 */
	public static function park( string $zone, bool $only_empty = false ): string {
		$zone  = strtolower( rtrim( $zone, '.' ) );
		$wants = self::addresses();

		if ( array() === $wants ) {
			return 'off';
		}

		$rows = array();

		foreach ( array( '@', 'www' ) as $name ) {
			foreach ( $wants as $type => $address ) {
				$rows[] = array(
					'name'  => $name,
					'type'  => $type,
					'ttl'   => self::TTL,
					'value' => $address,
				);
			}
		}

		$verdict = 'parked';
		$policy  = static function ( array $zone_data, array $sets, string $zone ) use ( &$verdict, $only_empty ) {
			if ( $only_empty && self::has_records( $zone_data ) ) {
				$verdict = 'has_records';

				return true;
			}

			if ( self::holds_address( $zone, $zone_data ) ) {
				$verdict = 'has_address';

				return true;
			}

			return $sets;
		};

		$locked = Records::locked(
			$zone,
			static function () use ( $zone, $rows, $policy, &$verdict ) {
				$state = self::state( $zone );
				$data  = Clients::dns()->zone( $zone );

				if ( is_array( $data ) && null !== $state ) {
					list( $view, $ours ) = self::split( $data );

					if ( array() !== $ours && ! self::holds_address( $zone, $view ) && count( $ours ) === count( $state['sets'] ) ) {
						return 'already';
					}
				}

				$result = ZoneWriter::write( null, $zone, $rows, $policy, true );

				if ( is_wp_error( $result ) ) {
					return 'error:' . $result->get_error_code();
				}

				if ( 'refused' === $result['status'] ) {
					return 'invalid' === $result['reason'] ? 'error:invalid' : $result['reason'];
				}

				if ( 'has_address' === $verdict || 'has_records' === $verdict ) {
					return $verdict;
				}

				self::remember( $zone, $rows );

				return 'parked';
			}
		);

		return is_wp_error( $locked ) ? 'error:' . $locked->get_error_code() : (string) $locked;
	}

	/**
	 * Called when a zone of ours is ready: parks it only when the domain is new to DNS and the zone is empty (a registration,
	 * or DNS hosting with nothing to import). A zone adopted from another DNS, a transfer and a zone with any record are never
	 * parked here, so a live site cannot flash the parking page. Never fails the caller.
	 *
	 * @param Resource $r Domain or DNS service.
	 */
	public static function offer( Resource $r ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$mode = (string) $r->get( 'mode' );

		if ( 'dns' === $mode ) {
			$claim = DnsClaims::get( (int) $r->get( 'claim_id' ) );

			if ( is_array( $claim ) && array() !== (array) ( $claim['draft_records'] ?? array() ) ) {
				return;
			}
		} elseif ( 'register' !== $mode ) {
			return;
		}

		self::park( (string) $r->get( 'domain' ), true );
	}

	/**
	 * Does the zone hold anything besides SOA and NS?
	 *
	 * @param array<string, mixed> $zone_data Zone.
	 */
	private static function has_records( array $zone_data ): bool {
		foreach ( (array) ( $zone_data['rrsets'] ?? array() ) as $set ) {
			if ( is_array( $set ) && ! in_array( strtoupper( (string) ( $set['type'] ?? '' ) ), array( 'SOA', 'NS' ), true ) && array() !== (array) ( $set['records'] ?? array() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parks every zone of ours that qualifies (or reports which would be).
	 *
	 * @param bool $apply Write; false is a dry run.
	 * @return array<int, array{zone:string,result:string}> One row per zone with a live holder.
	 */
	public static function sweep( bool $apply ): array {
		$zones = Clients::dns()->zones();
		$out   = array();

		if ( is_wp_error( $zones ) || ! self::enabled() ) {
			return $out;
		}

		foreach ( (array) $zones as $summary ) {
			$zone = strtolower( rtrim( (string) ( $summary['name'] ?? '' ), '.' ) );

			if ( '' === $zone || ! self::served( $zone ) ) {
				continue;
			}

			if ( $apply ) {
				$out[] = array( 'zone' => $zone, 'result' => self::park( $zone ) );
				continue;
			}

			$data = Clients::dns()->zone( $zone );

			if ( ! is_array( $data ) ) {
				$out[] = array( 'zone' => $zone, 'result' => 'no_zone' );
				continue;
			}

			list( $view, $ours ) = self::split( $data );
			$result              = array() !== $ours ? 'already' : ( self::holds_address( $zone, $view ) ? 'has_address' : ( Records::paused( $zone, $data ) ? 'paused' : 'would_park' ) );
			$out[]               = array( 'zone' => $zone, 'result' => $result );
		}

		return $out;
	}

	/**
	 * Does a live domain or DNS service of ours hold this zone on our name servers? A zone with no holder (our own
	 * infrastructure zones, a zone left behind) is never parked.
	 */
	private static function served( string $zone ): bool {
		foreach ( Zone::holders( $zone ) as list( $kind, $r ) ) {
			if ( 'dns' === $kind || ( 'custom' !== $r->get( 'ns_mode' ) && ! $r->get( 'dns_external' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does the apex or www hold an address, alias or CNAME (ours left out by the caller)?
	 *
	 * @param array<string, mixed> $zone_data Zone.
	 */
	private static function holds_address( string $zone, array $zone_data ): bool {
		foreach ( array( $zone, 'www.' . $zone ) as $fqdn ) {
			foreach ( Records::sets_at( $zone, $fqdn, (array) ( $zone_data['rrsets'] ?? array() ) ) as $type => $set ) {
				if ( in_array( $type, self::ADDRESS_TYPES, true ) && array() !== (array) ( $set['records'] ?? array() ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Stores what was written.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows given to ZoneWriter.
	 */
	private static function remember( string $zone, array $rows ): void {
		$sets = array();

		foreach ( $rows as $row ) {
			$fqdn = Records::fqdn_of_set( $zone, '@' === $row['name'] ? '' : (string) $row['name'] );
			$key  = self::key( $fqdn . '.', (string) $row['type'] );

			$sets[ $key ] = array( self::norm( (string) $row['type'], (string) $row['value'] ) );
		}

		if ( false === get_option( self::option( $zone ), false ) ) {
			add_option( self::option( $zone ), array( 'zone' => $zone, 'sets' => $sets, 'at' => time() ), '', false );
		} else {
			update_option( self::option( $zone ), array( 'zone' => $zone, 'sets' => $sets, 'at' => time() ), false );
		}

		/**
		 * Fires when the parking records of a zone were written (apex and www).
		 *
		 * @param string $zone Zone.
		 */
		do_action( 'beltoft_services_parked', $zone );
	}

	/** Option name of a zone. */
	private static function option( string $zone ): string {
		return self::PREFIX . sha1( strtolower( rtrim( $zone, '.' ) ) );
	}

	/** Key of a set: lower case name without the trailing dot, and the type. */
	private static function key( string $name, string $type ): string {
		return strtolower( rtrim( $name, '.' ) ) . '|' . strtoupper( $type );
	}

	/**
	 * The sorted comparable contents of a raw RRset.
	 *
	 * @param array<string, mixed> $set Raw RRset.
	 * @return array<int, string>
	 */
	private static function contents( array $set ): array {
		$list = array();

		foreach ( (array) ( $set['records'] ?? array() ) as $entry ) {
			$list[] = self::norm( (string) ( $set['type'] ?? '' ), (string) ( $entry['content'] ?? '' ) );
		}

		sort( $list );

		return $list;
	}

	/** One spelling of an address. */
	private static function norm( string $type, string $content ): string {
		$content = trim( $content );
		$packed  = 'AAAA' === strtoupper( $type ) ? @inet_pton( $content ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bad input stays as text.

		return false === $packed ? $content : (string) inet_ntop( $packed );
	}
}

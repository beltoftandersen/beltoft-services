<?php
/** Validated customer DNS record replacement. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class Records {
	private const TYPES = array( 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA' );

	/** @return array|\WP_Error PowerDNS RRsets, without SOA or NS. */
	/**
	 * Customer records as editor rows (the shape validate() takes), from the zone's rrsets.
	 * NS and SOA are ours and stay out of the editor.
	 */
	public static function to_rows( string $zone, array $rrsets ): array {
		$rows = array();
		foreach ( $rrsets as $set ) {
			$type = strtoupper( (string) ( $set['type'] ?? '' ) );
			if ( ! in_array( $type, self::TYPES, true ) ) { continue; }
			$fqdn = strtolower( (string) ( $set['name'] ?? '' ) );
			$relative = rtrim( substr( $fqdn, 0, max( 0, strlen( $fqdn ) - strlen( $zone ) - 1 ) ), '.' );
			$name = rtrim( $fqdn, '.' ) === $zone ? '@' : ( substr_count( $relative, '.' ) > 1 ? $fqdn : $relative );
			foreach ( (array) ( $set['records'] ?? array() ) as $entry ) {
				$row = array( 'name' => $name, 'type' => $type, 'ttl' => (int) ( $set['ttl'] ?? 3600 ), 'value' => '', 'disabled' => ! empty( $entry['disabled'] ) );
				$content = trim( (string) ( $entry['content'] ?? '' ) );
				if ( 'TXT' === $type ) {
					preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $content, $m );
					$row['value'] = stripcslashes( implode( '', $m[1] ) );
				} elseif ( 'MX' === $type && preg_match( '/^(\d+)\s+(\S+)$/', $content, $m ) ) {
					$row['priority'] = (int) $m[1];
					$row['value'] = rtrim( $m[2], '.' );
				} elseif ( 'SRV' === $type && preg_match( '/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)$/', $content, $m ) ) {
					$row['priority'] = (int) $m[1];
					$row['weight'] = (int) $m[2];
					$row['port'] = (int) $m[3];
					$row['value'] = rtrim( $m[4], '.' );
				} elseif ( 'CAA' === $type && preg_match( '/^(\d+)\s+(\S+)\s+"((?:[^"\\\\]|\\\\.)*)"$/', $content, $m ) ) {
					$row['flag'] = (int) $m[1];
					$row['tag'] = strtolower( $m[2] );
					$row['value'] = stripcslashes( $m[3] );
				} else {
					$row['value'] = 'CNAME' === $type ? rtrim( $content, '.' ) : $content;
				}
				$rows[] = $row;
			}
		}
		return $rows;
	}

	public static function validate( string $zone, array $records ): array|\WP_Error {
		$zone = Search::normalise( $zone );
		if ( is_wp_error( $zone ) ) { return $zone; }
		if ( count( $records ) > 200 ) { return new \WP_Error( 'too_many_records', 'A zone can have at most 200 customer records.' ); }
		$groups = array();
		$types = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) { return new \WP_Error( 'bad_record', 'A DNS record is invalid.' ); }
			$type = strtoupper( (string) ( $record['type'] ?? '' ) );
			$name = self::name( $zone, (string) ( $record['name'] ?? '' ), $type );
			$ttl = isset( $record['ttl'] ) ? (int) $record['ttl'] : 3600;
			if ( is_wp_error( $name ) ) { return $name; }
			if ( ! in_array( $type, self::TYPES, true ) || $ttl < 60 || $ttl > 86400 ) { return new \WP_Error( 'bad_record', 'A DNS record type or TTL is invalid.' ); }
			if ( 'CNAME' === $type && $name === $zone . '.' ) { return new \WP_Error( 'cname_apex', 'A CNAME cannot be placed at the domain root.' ); }
			$content = self::content( $type, $record );
			if ( is_wp_error( $content ) ) { return $content; }
			$key = $name . '|' . $type;
			if ( isset( $groups[ $key ] ) && $groups[ $key ]['ttl'] !== $ttl ) { return new \WP_Error( 'mixed_ttl', 'Records in one set must use one TTL.' ); }
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array( 'name' => $name, 'type' => $type, 'ttl' => $ttl, 'changetype' => 'REPLACE', 'records' => array() ); }
			$groups[ $key ]['records'][] = array( 'content' => $content, 'disabled' => false );
			$types[ $name ][ $type ] = true;
		}
		foreach ( $types as $by_type ) {
			if ( isset( $by_type['CNAME'] ) && count( $by_type ) > 1 ) { return new \WP_Error( 'cname_conflict', 'A CNAME cannot share its name with another record type.' ); }
		}
		return array_values( $groups );
	}

	/** Record types that may sit at a wildcard name (*.example.com). */
	private const WILDCARD_TYPES = array( 'A', 'AAAA', 'CNAME', 'TXT', 'MX' );

	private static function name( string $zone, string $raw, string $type = '' ): string|\WP_Error {
		$raw = strtolower( trim( $raw ) );
		if ( '@' === $raw || '' === $raw ) { return $zone . '.'; }
		if ( str_ends_with( $raw, '.' ) ) {
			$name = rtrim( $raw, '.' );
			if ( $name !== $zone && ! str_ends_with( $name, '.' . $zone ) ) { return new \WP_Error( 'outside_zone', 'A record name must stay inside the domain.' ); }
		} elseif ( $raw === $zone || str_ends_with( $raw, '.' . $zone ) ) {
			$name = $raw;
		} else {
			if ( substr_count( $raw, '.' ) > 1 ) { return new \WP_Error( 'outside_zone', 'A record name must stay inside the domain.' ); }
			$name = $raw . '.' . $zone;
		}
		if ( strlen( $name ) > 253 ) { return new \WP_Error( 'bad_name', 'A record name is invalid.' ); }
		$labels = explode( '.', $name );
		if ( '*' === $labels[0] && count( $labels ) > 1 ) {
			// A wildcard is the whole leftmost label, and only some types may use it.
			if ( ! in_array( $type, self::WILDCARD_TYPES, true ) ) { return new \WP_Error( 'bad_name', 'This record type cannot use a wildcard name.' ); }
			array_shift( $labels );
		}
		foreach ( $labels as $label ) {
			if ( ! preg_match( '/^_?[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?$/', $label ) ) { return new \WP_Error( 'bad_name', 'A record name is invalid.' ); }
		}
		return $name . '.';
	}

	private static function content( string $type, array $record ): string|\WP_Error {
		$value = trim( (string) ( $record['value'] ?? '' ) );
		if ( in_array( $type, array( 'TXT', 'CAA' ), true ) && preg_match( '/[\x00-\x1F\x7F]/', $value ) ) { return new \WP_Error( 'bad_txt', 'A record value cannot contain control characters.' ); }
		if ( 'A' === $type || 'AAAA' === $type ) {
			$family = 'A' === $type ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
			return filter_var( $value, FILTER_VALIDATE_IP, $family ) ? $value : new \WP_Error( 'bad_ip', 'Enter a valid IP address.' );
		}
		if ( 'CNAME' === $type ) { return self::target( $value ); }
		if ( 'TXT' === $type ) {
			if ( '' === $value || strlen( $value ) > 2048 ) { return new \WP_Error( 'bad_txt', 'A TXT value must contain 1 to 2048 bytes.' ); }
			// A string is at most 255 bytes in DNS: a longer value is written as several strings.
			$parts = array();
			foreach ( self::chunks( $value ) as $part ) { $parts[] = '"' . addcslashes( $part, '"\\' ) . '"'; }
			return implode( ' ', $parts );
		}
		if ( 'MX' === $type ) {
			$priority = self::number( $record['priority'] ?? null, 0, 65535 );
			$target = self::target( $value );
			return is_wp_error( $priority ) ? $priority : ( is_wp_error( $target ) ? $target : $priority . ' ' . $target );
		}
		if ( 'SRV' === $type ) {
			$numbers = array();
			foreach ( array( 'priority', 'weight', 'port' ) as $key ) {
				$number = self::number( $record[ $key ] ?? null, 0, 65535 );
				if ( is_wp_error( $number ) ) { return $number; }
				$numbers[] = $number;
			}
			$target = self::target( $value );
			return is_wp_error( $target ) ? $target : implode( ' ', $numbers ) . ' ' . $target;
		}
		if ( 'CAA' === $type ) {
			$flag = self::number( $record['flag'] ?? null, 0, 255 );
			$tag = strtolower( (string) ( $record['tag'] ?? '' ) );
			if ( is_wp_error( $flag ) || ! in_array( $tag, array( 'issue', 'issuewild', 'iodef' ), true ) || '' === $value || strlen( $value ) > 255 ) { return new \WP_Error( 'bad_caa', 'A CAA record is invalid.' ); }
			return $flag . ' ' . $tag . ' "' . addcslashes( $value, '"\\' ) . '"';
		}
		return new \WP_Error( 'bad_record', 'A DNS record is invalid.' );
	}

	/** Pieces of at most 255 bytes that never cut a UTF-8 character. */
	private static function chunks( string $value ): array {
		$chunks = array();
		while ( '' !== $value ) {
			$length = min( 255, strlen( $value ) );
			if ( strlen( $value ) > $length ) {
				while ( $length > 1 && ( ord( $value[ $length ] ) & 0xC0 ) === 0x80 ) { --$length; }
			}
			$chunks[] = substr( $value, 0, $length );
			$value = (string) substr( $value, $length );
		}
		return $chunks;
	}

	private static function target( string $value ): string|\WP_Error {
		$target = Search::normalise( $value );
		return is_wp_error( $target ) ? new \WP_Error( 'bad_target', 'Enter a valid target host name.' ) : $target . '.';
	}

	private static function number( $value, int $min, int $max ): int|\WP_Error {
		if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) ) { return new \WP_Error( 'bad_number', 'A DNS record number is invalid.' ); }
		$number = (int) $value;
		return $number >= $min && $number <= $max ? $number : new \WP_Error( 'bad_number', 'A DNS record number is invalid.' );
	}

	/**
	 * Runs a callback holding the zone lock. A busy zone gives the error zone_busy and the callback does not run.
	 * The lock is re-entrant on one connection, so a locked method may call another.
	 *
	 * @return mixed|\WP_Error
	 */
	public static function locked( string $zone, callable $fn ) {
		list( $taken, $result ) = \BeltoftServices\Locks::with( \BeltoftServices\Locks::zone( $zone ), \BeltoftServices\Locks::zone_wait(), $fn );
		return $taken ? $result : new \WP_Error( 'zone_busy', 'Your DNS is being changed. Try again in a moment.' );
	}

	/** The zone as the DNS server has it right now: array, or a WP_Error (zone_missing when there is none). */
	private static function read( string $zone ): array|\WP_Error {
		$current = Clients::dns()->zone( $zone );
		if ( is_wp_error( $current ) ) { return $current; }
		if ( null === $current ) { return new \WP_Error( 'zone_missing', 'The DNS zone does not exist.' ); }
		return $current;
	}

	/** The customer RRsets of a zone (types the editor knows), keyed by lowercase name and type. */
	private static function customer_sets( array $zone_data ): array {
		$have = array();
		foreach ( (array) ( $zone_data['rrsets'] ?? array() ) as $set ) {
			$type = strtoupper( (string) ( $set['type'] ?? '' ) );
			if ( in_array( $type, self::TYPES, true ) ) { $have[ strtolower( (string) ( $set['name'] ?? '' ) ) . '|' . $type ] = $set; }
		}
		return $have;
	}

	/** True when the zone has customer records and every one is switched off (a paused DNS service). */
	public static function zone_paused( array $zone_data ): bool {
		$any = false;
		foreach ( self::customer_sets( $zone_data ) as $set ) {
			foreach ( (array) ( $set['records'] ?? array() ) as $entry ) {
				$any = true;
				if ( empty( $entry['disabled'] ) ) { return false; }
			}
		}
		return $any;
	}

	/**
	 * Is the zone paused? The zone holder's `records_off` meta is the source of truth (read fresh, so an empty zone
	 * or one whose records are all off for another reason is judged right). A zone with no holder has only its own
	 * records to go by, so there the second guard applies: every customer record is switched off.
	 */
	public static function paused( string $zone, array $zone_data ): bool {
		$off = self::holder_off( $zone );
		return null === $off ? self::zone_paused( $zone_data ) : $off;
	}

	/** The holder's records_off flag, or null when the zone has no holding service. */
	private static function holder_off( string $zone ): ?bool {
		if ( ! DnsSchema::ready() ) { return null; }
		$row = DnsAssignments::get( $zone );
		if ( null === $row || (int) ( $row['resource_id'] ?? 0 ) <= 0 ) { return null; }
		$holder = \BeltoftServices\Resource::find( (int) $row['resource_id'] );
		return $holder ? (bool) $holder->get( 'records_off' ) : null;
	}

	/** One comparable form of a record's content, so spelling that DNS treats as the same does not read as a change. */
	private static function norm( string $type, string $content ): string {
		$content = trim( $content );
		switch ( $type ) {
			case 'A':
				return $content;
			case 'AAAA':
				$packed = @inet_pton( $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bad input falls back to the text.
				return false === $packed ? strtolower( $content ) : (string) inet_ntop( $packed );
			case 'CNAME':
				return strtolower( rtrim( $content, '.' ) );
			case 'MX':
			case 'SRV':
				$parts = preg_split( '/\s+/', $content );
				$last = count( $parts ) - 1;
				$parts[ $last ] = strtolower( rtrim( $parts[ $last ], '.' ) );
				return implode( ' ', $parts );
			case 'TXT':
				// PowerDNS returns the quoted strings; the value is their concatenation, however it was split.
				if ( preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $content, $m ) ) { return stripcslashes( implode( '', $m[1] ) ); }
				return $content;
			case 'CAA':
				if ( preg_match( '/^(\d+)\s+(\S+)\s+"((?:[^"\\\\]|\\\\.)*)"$/', $content, $m ) ) { return (int) $m[1] . ' ' . strtolower( $m[2] ) . ' ' . stripcslashes( $m[3] ); }
				return $content;
		}
		return $content;
	}

	/** Records of a set as sorted [content, disabled] pairs, for comparing two sets. */
	private static function signature( string $type, array $records, int $ttl ): string {
		$list = array();
		foreach ( $records as $entry ) { $list[] = self::norm( $type, (string) ( $entry['content'] ?? '' ) ) . '|' . ( empty( $entry['disabled'] ) ? '0' : '1' ); }
		sort( $list );
		return $ttl . '#' . implode( "\n", $list );
	}

	/** Gives each record of a new set the disabled flag of the same content already in the zone, else the zone's state. */
	private static function with_flags( array $set, ?array $old, bool $paused ): array {
		$flags = array();
		foreach ( (array) ( $old['records'] ?? array() ) as $entry ) { $flags[ self::norm( (string) $set['type'], (string) ( $entry['content'] ?? '' ) ) ] = ! empty( $entry['disabled'] ); }
		foreach ( $set['records'] as $i => $entry ) {
			$set['records'][ $i ]['disabled'] = $flags[ self::norm( (string) $set['type'], $entry['content'] ) ] ?? $paused;
		}
		return $set;
	}

	/**
	 * Replaces the RRset at one name and type, or deletes it when the list is empty. Only that RRset is written;
	 * every other record of the zone, and every record's disabled flag, is left as it is.
	 *
	 * @param array      $records Rows (value, priority, weight, port, flag, tag, ttl) of this one name and type.
	 * @param array|null $expect  The rows the customer saw for this name and type; a different RRset now gives `stale`.
	 */
	public static function change( string $zone, string $name, string $type, array $records, ?array $expect = null ): true|\WP_Error {
		$zone = Search::normalise( $zone );
		if ( is_wp_error( $zone ) ) { return $zone; }
		$type = strtoupper( $type );
		if ( ! in_array( $type, self::TYPES, true ) ) { return new \WP_Error( 'bad_record', 'A DNS record type or TTL is invalid.' ); }
		$fqdn = self::name( $zone, $name, $type );
		if ( is_wp_error( $fqdn ) ) { return $fqdn; }
		$rows = array();
		foreach ( $records as $row ) {
			if ( ! is_array( $row ) ) { return new \WP_Error( 'bad_record', 'A DNS record is invalid.' ); }
			$rows[] = array_merge( $row, array( 'name' => $fqdn, 'type' => $type ) );
		}
		$set = array();
		if ( $rows ) {
			$validated = self::validate( $zone, $rows );
			if ( is_wp_error( $validated ) ) { return $validated; }
			$set = $validated[0];
		}
		return self::locked( $zone, static function () use ( $zone, $fqdn, $type, $set, $expect ) {
			$data = self::read( $zone );
			if ( is_wp_error( $data ) ) { return $data; }
			if ( self::paused( $zone, $data ) ) { return new \WP_Error( 'records_off', 'DNS hosting is paused for this domain.' ); }
			$have = self::customer_sets( $data );
			$key = strtolower( $fqdn ) . '|' . $type;
			$old = $have[ $key ] ?? null;
			if ( null !== $expect && self::shown( $zone, $old ) !== self::shown( $zone, null, $expect ) ) {
				return new \WP_Error( 'stale', 'This record changed. Reload the list.' );
			}
			if ( ! $set ) {
				return $old ? Clients::dns()->replace_rrsets( $zone, array( array( 'name' => $old['name'], 'type' => $type, 'changetype' => 'DELETE' ) ) ) : true;
			}
			// What else lives at this name in the live zone, of any type, decides the CNAME rule.
			foreach ( (array) ( $data['rrsets'] ?? array() ) as $other ) {
				$other_type = strtoupper( (string) ( $other['type'] ?? '' ) );
				if ( strtolower( (string) ( $other['name'] ?? '' ) ) !== strtolower( $fqdn ) || $other_type === $type || empty( $other['records'] ) ) { continue; }
				if ( 'CNAME' === $type || 'CNAME' === $other_type ) { return new \WP_Error( 'cname_conflict', 'A CNAME cannot share its name with another record type.' ); }
			}
			$total = count( (array) ( $set['records'] ) );
			foreach ( $have as $k => $existing ) {
				if ( $k !== $key ) { $total += count( (array) ( $existing['records'] ?? array() ) ); }
			}
			if ( $total > 200 ) { return new \WP_Error( 'too_many_records', 'A zone can have at most 200 customer records.' ); }
			$set = self::with_flags( $set, $old, false );
			if ( $old && self::signature( (string) $set['type'], $set['records'], $set['ttl'] ) === self::signature( (string) $set['type'], (array) ( $old['records'] ?? array() ), (int) ( $old['ttl'] ?? 3600 ) ) ) { return true; }
			return Clients::dns()->replace_rrsets( $zone, array( $set ) );
		} );
	}

	/** Deletes the RRset at one name and type. */
	public static function delete_set( string $zone, string $name, string $type, ?array $expect = null ): true|\WP_Error {
		return self::change( $zone, $name, $type, array(), $expect );
	}

	/** Comparable form of an RRset as the editor shows it (from a zone set, or from rows the customer sent). */
	private static function shown( string $zone, ?array $set, ?array $rows = null ): array {
		if ( null === $rows ) {
			$rows = $set ? self::to_rows( $zone, array( $set ) ) : array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$keep = array( 'ttl' => (int) ( $row['ttl'] ?? 3600 ) );
			foreach ( array( 'value', 'priority', 'weight', 'port', 'flag', 'tag' ) as $key ) {
				if ( isset( $row[ $key ] ) ) { $keep[ $key ] = is_numeric( $row[ $key ] ) ? (int) $row[ $key ] : (string) $row[ $key ]; }
			}
			ksort( $keep );
			$out[] = wp_json_encode( $keep );
		}
		sort( $out );
		return $out;
	}

	/**
	 * Replaces the customer records of a zone with a list, writing only the RRsets that differ from the zone as it is
	 * read under the lock. Each record keeps its disabled flag; types the editor does not know are never touched.
	 */
	public static function replace( string $zone, array $records ): true|\WP_Error {
		$rrsets = self::validate( $zone, $records );
		if ( is_wp_error( $rrsets ) ) { return $rrsets; }
		return self::locked( $zone, static function () use ( $zone, $rrsets ) {
			$data = self::read( $zone );
			if ( is_wp_error( $data ) ) { return $data; }
			// A paused service takes no customer writes; a zone with no holder is judged by its own records.
			$off = self::holder_off( $zone );
			if ( true === $off ) { return new \WP_Error( 'records_off', 'DNS hosting is paused for this domain.' ); }
			$have = self::customer_sets( $data );
			$paused = null === $off && self::zone_paused( $data );
			$sets = array();
			$wanted = array();
			foreach ( $rrsets as $set ) {
				$key = strtolower( $set['name'] ) . '|' . $set['type'];
				$wanted[ $key ] = true;
				$old = $have[ $key ] ?? null;
				$set = self::with_flags( $set, $old, $paused );
				if ( $old && self::signature( (string) $set['type'], $set['records'], $set['ttl'] ) === self::signature( (string) $set['type'], (array) ( $old['records'] ?? array() ), (int) ( $old['ttl'] ?? 3600 ) ) ) { continue; }
				$sets[] = $set;
			}
			foreach ( $have as $key => $old ) {
				if ( ! isset( $wanted[ $key ] ) ) { $sets[] = array( 'name' => $old['name'], 'type' => $old['type'], 'changetype' => 'DELETE' ); }
			}
			return $sets ? Clients::dns()->replace_rrsets( $zone, $sets ) : true;
		} );
	}

	/**
	 * Switches every customer record of a zone off or on, keeping the records. NS and SOA stay as they are.
	 * A zone that does not exist gives the error zone_missing; a zone being changed right now gives zone_busy.
	 */
	public static function set_disabled( string $zone, bool $disabled ): true|\WP_Error {
		return self::locked( $zone, static function () use ( $zone, $disabled ) {
			$current = self::read( $zone );
			if ( is_wp_error( $current ) ) { return $current; }
			$sets = array();
			foreach ( self::customer_sets( $current ) as $set ) {
				$records = array();
				foreach ( (array) ( $set['records'] ?? array() ) as $entry ) { $records[] = array( 'content' => (string) ( $entry['content'] ?? '' ), 'disabled' => $disabled ); }
				$sets[] = array( 'name' => $set['name'], 'type' => $set['type'], 'ttl' => (int) ( $set['ttl'] ?? 3600 ), 'changetype' => 'REPLACE', 'records' => $records );
			}
			return $sets ? Clients::dns()->replace_rrsets( $zone, $sets ) : true;
		} );
	}
}

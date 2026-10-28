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
				$row = array( 'name' => $name, 'type' => $type, 'ttl' => (int) ( $set['ttl'] ?? 3600 ), 'value' => '' );
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
			$name = self::name( $zone, (string) ( $record['name'] ?? '' ) );
			$type = strtoupper( (string) ( $record['type'] ?? '' ) );
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

	private static function name( string $zone, string $raw ): string|\WP_Error {
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
		foreach ( explode( '.', $name ) as $label ) {
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

	/** Replace customer RRsets while preserving server-managed NS and SOA. */
	public static function replace( string $zone, array $records ): true|\WP_Error {
		$rrsets = self::validate( $zone, $records );
		if ( is_wp_error( $rrsets ) ) { return $rrsets; }
		$dns = Clients::dns();
		$current = $dns->zone( $zone );
		if ( is_wp_error( $current ) ) { return $current; }
		if ( null === $current ) { return new \WP_Error( 'zone_missing', 'The DNS zone does not exist.' ); }
		$new = array();
		foreach ( $rrsets as $set ) { $new[ $set['name'] . '|' . $set['type'] ] = true; }
		foreach ( (array) ( $current['rrsets'] ?? array() ) as $old ) {
			$key = (string) ( $old['name'] ?? '' ) . '|' . (string) ( $old['type'] ?? '' );
			if ( in_array( $old['type'] ?? '', self::TYPES, true ) && ! isset( $new[ $key ] ) ) { $rrsets[] = array( 'name' => $old['name'], 'type' => $old['type'], 'changetype' => 'DELETE' ); }
		}
		return $dns->replace_rrsets( $zone, $rrsets );
	}
}

<?php
/** Validated customer DNS record replacement. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class Records {
	private const TYPES = array( 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA' );

	/** @return array|\WP_Error PowerDNS RRsets, without SOA or NS. */
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
		foreach ( explode( '.', $name ) as $label ) {
			if ( ! preg_match( '/^_?[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?$/', $label ) ) { return new \WP_Error( 'bad_name', 'A record name is invalid.' ); }
		}
		return $name . '.';
	}

	private static function content( string $type, array $record ): string|\WP_Error {
		$value = trim( (string) ( $record['value'] ?? '' ) );
		if ( 'A' === $type || 'AAAA' === $type ) {
			$family = 'A' === $type ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
			return filter_var( $value, FILTER_VALIDATE_IP, $family ) ? $value : new \WP_Error( 'bad_ip', 'Enter a valid IP address.' );
		}
		if ( 'CNAME' === $type ) { return self::target( $value ); }
		if ( 'TXT' === $type ) {
			if ( '' === $value || strlen( $value ) > 255 ) { return new \WP_Error( 'bad_txt', 'A TXT string must contain 1 to 255 bytes.' ); }
			return '"' . addcslashes( $value, '"\\' ) . '"';
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

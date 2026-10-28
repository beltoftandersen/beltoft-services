<?php
/** DNS message building and parsing (RFC 1035, EDNS0 RFC 6891). @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class DnsWire {
	public const A     = 1;
	public const NS    = 2;
	public const CNAME = 5;
	public const SOA   = 6;
	public const MX    = 15;
	public const TXT   = 16;
	public const AAAA  = 28;
	public const SRV   = 33;
	public const OPT   = 41;
	public const DS    = 43;
	public const CAA   = 257;

	private const MAX_JUMPS = 20;

	/** A question with RD off, class IN, and an EDNS0 OPT record (UDP size 1232, DO off). */
	public static function query( string $name, int $type, int $id ): string {
		$wire = pack( 'nnnnnn', $id & 0xffff, 0, 1, 0, 0, 1 );
		foreach ( explode( '.', rtrim( $name, '.' ) ) as $label ) {
			if ( '' === $label ) { continue; }
			$wire .= chr( min( 63, strlen( $label ) ) ) . substr( $label, 0, 63 );
		}
		$wire .= "\0" . pack( 'nn', $type, 1 );
		return $wire . "\0" . pack( 'nnNn', self::OPT, 1232, 0, 0 );
	}

	/**
	 * Parse a response. Any structural problem is dns_malformed; a wrong id or a question that does not
	 * echo what was asked is dns_mismatch.
	 *
	 * @return array{rcode: int, aa: bool, tc: bool, answer: array, authority: array, additional: array}|\WP_Error
	 */
	public static function parse( string $packet, int $expected_id, string $expected_name, int $expected_type ): array|\WP_Error {
		$bad = new \WP_Error( 'dns_malformed', 'Malformed DNS message.' );
		if ( strlen( $packet ) < 12 ) { return $bad; }
		$head = unpack( 'nid/nflags/nqd/nan/nns/nar', $packet );
		if ( ( $head['flags'] & 0x8000 ) === 0 ) { return $bad; }
		if ( (int) $head['id'] !== ( $expected_id & 0xffff ) || 1 !== (int) $head['qd'] ) { return new \WP_Error( 'dns_mismatch', 'DNS response does not match the question.' ); }
		$offset = 12;
		$qname  = self::name( $packet, $offset );
		if ( null === $qname || $offset + 4 > strlen( $packet ) ) { return $bad; }
		$question = unpack( 'ntype/nclass', substr( $packet, $offset, 4 ) );
		$offset  += 4;
		if ( $qname !== strtolower( rtrim( $expected_name, '.' ) ) || (int) $question['type'] !== $expected_type ) { return new \WP_Error( 'dns_mismatch', 'DNS response does not match the question.' ); }
		$out = array( 'rcode' => $head['flags'] & 15, 'aa' => ( $head['flags'] & 0x0400 ) !== 0, 'tc' => ( $head['flags'] & 0x0200 ) !== 0, 'answer' => array(), 'authority' => array(), 'additional' => array() );
		foreach ( array( 'answer' => 'an', 'authority' => 'ns', 'additional' => 'ar' ) as $section => $key ) {
			for ( $i = 0; $i < $head[ $key ]; ++$i ) {
				$rr = self::record( $packet, $offset );
				if ( null === $rr ) { return $bad; }
				if ( false !== $rr ) { $out[ $section ][] = $rr; }
			}
		}
		return $out;
	}

	/** One resource record: array, false for a skipped one (OPT, other class, unknown type), null when malformed. */
	private static function record( string $packet, int &$offset ): array|false|null {
		$name = self::name( $packet, $offset );
		if ( null === $name || $offset + 10 > strlen( $packet ) ) { return null; }
		$fields = unpack( 'ntype/nclass/Nttl/nlength', substr( $packet, $offset, 10 ) );
		$offset += 10;
		$end = $offset + $fields['length'];
		if ( $end > strlen( $packet ) ) { return null; }
		$start = $offset;
		$offset = $end;
		$type = (int) $fields['type'];
		if ( 1 !== (int) $fields['class'] ) { return false; }
		$pos  = $start;
		$data = null;
		switch ( $type ) {
			case self::A:
				$data = 4 === $fields['length'] ? inet_ntop( substr( $packet, $start, 4 ) ) : null;
				break;
			case self::AAAA:
				$data = 16 === $fields['length'] ? inet_ntop( substr( $packet, $start, 16 ) ) : null;
				break;
			case self::NS:
			case self::CNAME:
				$data = self::name( $packet, $pos );
				if ( $pos > $end ) { $data = null; }
				break;
			case self::MX:
				if ( $fields['length'] < 3 ) { break; }
				$priority = unpack( 'n', substr( $packet, $start, 2 ) )[1];
				$pos      = $start + 2;
				$target   = self::name( $packet, $pos );
				$data     = null !== $target && $pos <= $end ? array( 'priority' => $priority, 'target' => $target ) : null;
				break;
			case self::SRV:
				if ( $fields['length'] < 7 ) { break; }
				$nums   = unpack( 'npriority/nweight/nport', substr( $packet, $start, 6 ) );
				$pos    = $start + 6;
				$target = self::name( $packet, $pos );
				$data   = null !== $target && $pos <= $end ? $nums + array( 'target' => $target ) : null;
				break;
			case self::TXT:
				$strings = array();
				while ( $pos < $end ) {
					$size = ord( $packet[ $pos++ ] );
					if ( $pos + $size > $end ) { $strings = null; break; }
					$strings[] = substr( $packet, $pos, $size );
					$pos      += $size;
				}
				$data = $strings;
				break;
			case self::CAA:
				if ( $fields['length'] < 2 ) { break; }
				$tag_length = ord( $packet[ $start + 1 ] );
				if ( 2 + $tag_length > $fields['length'] ) { break; }
				$data = array( 'flag' => ord( $packet[ $start ] ), 'tag' => strtolower( substr( $packet, $start + 2, $tag_length ) ), 'value' => substr( $packet, $start + 2 + $tag_length, $fields['length'] - 2 - $tag_length ) );
				break;
			case self::DS:
				if ( $fields['length'] < 4 ) { break; }
				$ds   = unpack( 'nkey_tag/Calgorithm/Cdigest_type', substr( $packet, $start, 4 ) );
				$data = $ds + array( 'digest' => bin2hex( substr( $packet, $start + 4, $fields['length'] - 4 ) ) );
				break;
			case self::SOA:
				$mname = self::name( $packet, $pos );
				$rname = null === $mname ? null : self::name( $packet, $pos );
				if ( null === $rname || $pos + 20 > $end ) { break; }
				$nums = unpack( 'Nserial/Nrefresh/Nretry/Nexpire/Nminimum', substr( $packet, $pos, 20 ) );
				$data = array( 'mname' => $mname, 'rname' => $rname, 'serial' => $nums['serial'], 'minimum' => $nums['minimum'] );
				break;
			default:
				return false; // OPT and unknown types: skipped, the offset already moved past them.
		}
		if ( null === $data || false === $data ) { return null; }
		return array( 'name' => $name, 'type' => $type, 'ttl' => (int) $fields['ttl'], 'data' => $data );
	}

	/** A possibly compressed name at $offset, lowercase without the trailing dot; null when malformed. Moves $offset past it. */
	private static function name( string $packet, int &$offset ): ?string {
		$length = strlen( $packet );
		$pos    = $offset;
		$next   = null;
		$jumps  = 0;
		$labels = array();
		$total  = 0;
		while ( true ) {
			if ( $pos >= $length ) { return null; }
			$size = ord( $packet[ $pos ] );
			if ( 0 === $size ) { ++$pos; break; }
			if ( 0xc0 === ( $size & 0xc0 ) ) {
				if ( $pos + 1 >= $length || ++$jumps > self::MAX_JUMPS ) { return null; }
				$target = ( ( $size & 0x3f ) << 8 ) | ord( $packet[ $pos + 1 ] );
				if ( $target >= $pos ) { return null; } // Pointers only go backwards, which also rules out loops.
				if ( null === $next ) { $next = $pos + 2; }
				$pos = $target;
				continue;
			}
			if ( $size > 63 || ( $size & 0xc0 ) !== 0 || $pos + 1 + $size > $length ) { return null; }
			$total += $size + 1;
			if ( $total > 254 ) { return null; }
			$labels[] = strtolower( substr( $packet, $pos + 1, $size ) );
			$pos     += 1 + $size;
		}
		$offset = $next ?? $pos;
		return implode( '.', $labels );
	}
}

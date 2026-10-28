<?php
/**
 * Builds DNS response packets for tests, so no test touches the network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Domains\DnsWire;

class DnsPackets {
	/**
	 * A response. Options: id, rcode, aa, tc, answer, authority, additional (lists of rr arrays with the
	 * parser's shape: name, type, ttl, data), compress (point owner names at the question).
	 */
	public static function response( string $name, int $type, array $options = array() ): string {
		$opts  = $options + array( 'id' => 1, 'rcode' => 0, 'aa' => false, 'tc' => false, 'answer' => array(), 'authority' => array(), 'additional' => array(), 'compress' => false );
		$flags = 0x8000 | ( $opts['aa'] ? 0x0400 : 0 ) | ( $opts['tc'] ? 0x0200 : 0 ) | $opts['rcode'];
		$wire  = pack( 'nnnnnn', $opts['id'], $flags, 1, count( $opts['answer'] ), count( $opts['authority'] ), count( $opts['additional'] ) );
		$wire .= self::name( $name ) . pack( 'nn', $type, 1 );
		foreach ( array( 'answer', 'authority', 'additional' ) as $section ) {
			foreach ( $opts[ $section ] as $rr ) {
				$owner = $opts['compress'] && strtolower( rtrim( $rr['name'], '.' ) ) === strtolower( rtrim( $name, '.' ) ) ? "\xc0\x0c" : self::name( $rr['name'] );
				$data  = self::rdata( $rr['type'], $rr['data'] );
				$wire .= $owner . pack( 'nnNn', $rr['type'], $rr['class'] ?? 1, $rr['ttl'] ?? 300, strlen( $data ) ) . $data;
			}
		}
		return $wire;
	}

	public static function name( string $name ): string {
		$wire = '';
		foreach ( explode( '.', rtrim( $name, '.' ) ) as $label ) {
			if ( '' !== $label ) { $wire .= chr( strlen( $label ) ) . $label; }
		}
		return $wire . "\0";
	}

	public static function rdata( int $type, mixed $data ): string {
		switch ( $type ) {
			case DnsWire::A:
			case DnsWire::AAAA:
				return inet_pton( $data );
			case DnsWire::NS:
			case DnsWire::CNAME:
				return self::name( $data );
			case DnsWire::MX:
				return pack( 'n', $data['priority'] ) . self::name( $data['target'] );
			case DnsWire::SRV:
				return pack( 'nnn', $data['priority'], $data['weight'], $data['port'] ) . self::name( $data['target'] );
			case DnsWire::TXT:
				return implode( '', array_map( static fn( $s ) => chr( strlen( $s ) ) . $s, (array) $data ) );
			case DnsWire::CAA:
				return chr( $data['flag'] ) . chr( strlen( $data['tag'] ) ) . $data['tag'] . $data['value'];
			case DnsWire::DS:
				return pack( 'nCC', $data['key_tag'], $data['algorithm'], $data['digest_type'] ) . hex2bin( $data['digest'] );
			case DnsWire::SOA:
				return self::name( $data['mname'] ) . self::name( $data['rname'] ) . pack( 'NNNNN', $data['serial'], 3600, 600, 86400, $data['minimum'] );
			default:
				return (string) $data;
		}
	}
}

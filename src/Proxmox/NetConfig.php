<?php
/** Shared parser for Proxmox NIC property strings. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

final class NetConfig {
	/** @return array<string,?string> */
	public static function parse( string $value ): array {
		$props = array();
		foreach ( '' === $value ? array() : explode( ',', $value ) as $token ) {
			$pair = explode( '=', $token, 2 );
			$props[ $pair[0] ] = $pair[1] ?? null;
		}
		return $props;
	}

	/**
	 * Proxmox `rate=` (MB/s) for a speed in Mbit/s; null for no limit (0 or below).
	 *
	 * @param float|int $mbit Plan speed in Mbit/s.
	 */
	public static function rate_for( $mbit ): ?string {
		$mbit = (float) $mbit;

		return $mbit > 0 ? rtrim( rtrim( number_format( $mbit / 8, 3, '.', '' ), '0' ), '.' ) : null;
	}

	/**
	 * A NIC string with `rate=` set to the plan's speed, or removed for no limit. Every other option stays, in order.
	 *
	 * @param string    $nic  NIC property string.
	 * @param float|int $mbit Plan speed in Mbit/s (0: none).
	 */
	public static function with_rate( string $nic, $mbit ): string {
		$props = self::parse( $nic );
		$rate  = self::rate_for( $mbit );

		if ( null === $rate ) {
			unset( $props['rate'] );
		} else {
			$props['rate'] = $rate;
		}

		return self::format( $props );
	}

	/**
	 * Is the NIC's `rate=` the one the speed asks for (numerically: 12.5 and 12.50 are the same)?
	 *
	 * @param string    $nic  NIC property string.
	 * @param float|int $mbit Plan speed in Mbit/s (0: none).
	 */
	public static function has_rate( string $nic, $mbit ): bool {
		$have = self::parse( $nic )['rate'] ?? null;
		$want = self::rate_for( $mbit );

		return null === $have || null === $want ? $have === $want : abs( (float) $have - (float) $want ) < 0.0005;
	}

	/** @param array<string,?string> $props */
	public static function format( array $props ): string {
		$out = array();
		foreach ( $props as $key => $value ) {
			$out[] = null === $value ? $key : $key . '=' . $value;
		}
		return implode( ',', $out );
	}
}

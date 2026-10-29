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

	/** @param array<string,?string> $props */
	public static function format( array $props ): string {
		$out = array();
		foreach ( $props as $key => $value ) {
			$out[] = null === $value ? $key : $key . '=' . $value;
		}
		return implode( ',', $out );
	}
}

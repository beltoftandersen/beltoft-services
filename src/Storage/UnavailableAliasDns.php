<?php
/**
 * The alias DNS writer used when live is chosen but cannot be reached.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Every call fails, so the step retries. Never the fake: that would record a host name that is not in DNS.
 */
final class UnavailableAliasDns implements AliasDns {

	private function no(): \WP_Error {
		return new \WP_Error( 'alias_dns_unavailable', 'The DNS provider connection is not usable: the token or zone id is missing, or live DNS writes are not allowed.' );
	}

	public function find( string $name ) {
		return $this->no();
	}

	public function create( string $name, string $target ) {
		return $this->no();
	}

	public function update( string $id, string $name, string $target ) {
		return $this->no();
	}

	public function delete( string $id ) {
		return $this->no();
	}
}

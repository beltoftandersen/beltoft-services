<?php
/** Public name server delegation status. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Delegation {
	public static function check( string $domain ): string {
		$expected = self::normalise( Zone::nameservers() );
		if ( count( $expected ) < 2 ) { return 'unknown'; }
		$answer = apply_filters( 'beltoft_services_domains_ns_answer', null, $domain );
		if ( null === $answer ) {
			$answer = dns_get_record( $domain, DNS_NS );
			if ( false === $answer || array() === $answer ) { return 'unknown'; }
			$answer = array_column( $answer, 'target' );
		}
		if ( ! is_array( $answer ) || array() === $answer ) { return 'unknown'; }
		return $expected === self::normalise( $answer ) ? 'ours' : 'other';
	}

	private static function normalise( array $hosts ): array {
		$hosts = array_values( array_unique( array_filter( array_map( static fn( $host ) => strtolower( rtrim( trim( (string) $host ), '.' ) ), $hosts ) ) ) );
		sort( $hosts, SORT_STRING );
		return $hosts;
	}

	public static function refresh( Resource $resource, bool $rate_limit = false ): string|\WP_Error {
		$checked = (int) $resource->get( 'delegation_checked' );
		if ( $rate_limit && $checked > time() - MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'rate_limited', 'Check again in a minute.' );
		}
		if ( 'dns' === $resource->get( 'driver' ) ) {
			$result = self::parent_result( (string) $resource->get( 'domain' ) );
			$resource->set( array( 'delegation' => $result['status'], 'parent_ds' => $result['ds'], 'delegation_checked' => time() ) );
			return $result['status'];
		}
		$status = self::check( (string) $resource->get( 'domain' ) );
		$resource->set( array( 'delegation' => $status, 'delegation_checked' => time() ) );
		return $status;
	}

	/**
	 * The parent zone's delegation of a domain, with the test hook the claim jobs use.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>
	 */
	public static function lookup( string $domain ): array {
		$found = apply_filters( 'beltoft_services_dns_proof_result', null, 'delegation', array( $domain ) );
		return is_array( $found ) ? $found : ProofResolver::delegation( $domain );
	}

	/**
	 * The delegation of a DNS-only domain as its parent zone publishes it.
	 *
	 * @param string $domain Domain.
	 * @return array{status: string, ds: ?bool}
	 */
	private static function parent_result( string $domain ): array {
		$found = self::lookup( $domain );
		$state = (string) ( $found['status'] ?? '' );
		$ours  = ! empty( $found['ours'] ) || ( 'support' === $state && 'delegated_to_us' === ( $found['reason'] ?? '' ) );
		$ds    = $found['ds'] ?? null;
		if ( $ours && in_array( $state, array( 'ok', 'support' ), true ) ) {
			$status = 'ours';
		} elseif ( 'ok' === $state ) {
			$status = 'other';
		} else {
			$status = 'unknown';
		}
		return array( 'status' => $status, 'ds' => is_bool( $ds ) ? $ds : null );
	}
}

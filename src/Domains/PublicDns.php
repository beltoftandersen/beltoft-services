<?php
/**
 * What public DNS answers for a name.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * A read of public DNS, as any visitor sees it. Used by the checks that wait for a customer's records (mail, certificate
 * validation) so none of them needs to know about another feature.
 */
final class PublicDns {

	/**
	 * What public DNS answers for a name. Tests answer through the filter beltoft_services_mail_dns_lookup; nothing in a
	 * test run reaches the network.
	 *
	 * @param string $fqdn Name.
	 * @param string $type MX, TXT or CNAME.
	 * @return string[]|\WP_Error Values (MX as "priority host"), empty when none; an error when no resolver answered.
	 */
	public static function lookup( string $fqdn, string $type ) {
		/**
		 * Replaces the public DNS read (tests only).
		 *
		 * @param array|\WP_Error|null $answer Null to read for real.
		 * @param string               $fqdn   Name.
		 * @param string               $type   MX, TXT or CNAME.
		 */
		$test = apply_filters( 'beltoft_services_mail_dns_lookup', null, $fqdn, $type );

		if ( null !== $test ) {
			return is_wp_error( $test ) ? $test : array_values( array_map( 'strval', (array) $test ) );
		}

		$flag    = array( 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME )[ $type ] ?? DNS_TXT;
		$records = @dns_get_record( rtrim( $fqdn, '.' ) . '.', $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is returned as an error below.

		if ( false === $records ) {
			return new \WP_Error( 'dns_transport', __( 'The name did not resolve.', 'beltoft-services' ) );
		}

		$out = array();

		foreach ( $records as $record ) {
			if ( $type !== ( $record['type'] ?? '' ) ) {
				continue;
			}

			if ( 'MX' === $type ) {
				$out[] = (int) ( $record['pri'] ?? 0 ) . ' ' . (string) ( $record['target'] ?? '' );
			} elseif ( 'TXT' === $type ) {
				$out[] = isset( $record['entries'] ) ? implode( '', (array) $record['entries'] ) : (string) ( $record['txt'] ?? '' );
			} else {
				$out[] = (string) ( $record['target'] ?? '' );
			}
		}

		return $out;
	}
}

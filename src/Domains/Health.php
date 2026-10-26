<?php
/** Registrar funds and DNS health checks. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Health {
	public const LOW_ALERT = 'beltoft_services_domains_low_balance_alert';
	public const DNS_ORPHAN_ALERT = 'beltoft_services_domains_orphan_alert';

	public static function balance(): float|\WP_Error {
		$reply = Clients::registrar()->request( 'GET', '/resellers' );
		if ( is_wp_error( $reply ) ) { return $reply; }
		if ( ! is_numeric( $reply['balance'] ?? null ) || 'EUR' !== strtoupper( (string) ( $reply['currency'] ?? 'EUR' ) ) ) { return new \WP_Error( 'bad_balance', 'Registrar did not return a EUR balance.' ); }
		$balance = (float) $reply['balance'];
		if ( $balance < (float) Settings::get( 'domain_low_balance', 50 ) ) {
			$today = gmdate( 'Y-m-d' );
			if ( $today !== get_option( self::LOW_ALERT, '' ) ) {
				$to = (string) Settings::get( 'admin_email' );
				if ( is_email( $to ) ) {
					wp_mail( $to, __( 'Domain registrar balance is low', 'beltoft-services' ), sprintf( 'Registrar balance: %.2f EUR', $balance ) );
					update_option( self::LOW_ALERT, $today, false );
				}
			}
		}
		return $balance;
	}

	/** @return string[]|\WP_Error DNS problems found in the primary and configured secondaries. */
	public static function dns_check(): array|\WP_Error {
		$nameservers = Zone::nameservers();
		if ( count( $nameservers ) < 2 ) { return new \WP_Error( 'no_nameservers', 'Configure at least two name servers.' ); }
		$dns = Clients::dns();
		$listed = $dns->zones();
		if ( is_wp_error( $listed ) ) { return $listed; }
		$known = array();
		$resources = array();
		foreach ( array( 'domain', 'dns' ) as $kind ) {
			foreach ( \BeltoftServices\Resource::live_ids( $kind ) as $id ) {
				$r = \BeltoftServices\Resource::find( $id );
				if ( ! $r || ! $r->is_live() || 'deleted' === $r->get( 'state' ) ) { continue; }
				$name = strtolower( rtrim( (string) $r->get( 'domain' ), '.' ) );
				if ( '' === $name ) { continue; }
				$known[ $name ] = true;
				if ( 'custom' !== $r->get( 'ns_mode' ) && ( $r->get( 'zone_ready' ) || $r->get( 'zone_claimed' ) ) ) { $resources[ $name ] = $r; }
			}
		}
		$issues = array();
		foreach ( $listed as $zone ) {
			$name = strtolower( rtrim( (string) ( $zone['name'] ?? '' ), '.' ) );
			if ( '' !== $name && ! isset( $known[ $name ] ) ) { $issues[] = $name . ': orphan zone'; }
		}
		foreach ( $resources as $name => $r ) {
			$zone = $dns->zone( $name );
			if ( is_wp_error( $zone ) || null === $zone ) { $issues[] = $name . ': missing primary zone'; continue; }
			$serial = DnsQuery::zone_serial( $zone );
			if ( null === $serial ) { $issues[] = $name . ': primary SOA serial unavailable'; continue; }
			$mismatch = false;
			foreach ( array_slice( $nameservers, 1 ) as $secondary ) {
				$other = DnsQuery::read( $secondary, $name, 6 );
				if ( is_wp_error( $other ) ) { $issues[] = $name . ': missing SOA at ' . $secondary; continue; }
				if ( $other !== $serial ) { $mismatch = true; }
			}
			if ( $mismatch ) {
				$since = (int) $r->get( 'dns_mismatch_since' );
				if ( ! $since ) { $r->set( array( 'dns_mismatch_since' => time() ) ); }
				elseif ( time() - $since >= 15 * MINUTE_IN_SECONDS ) { $issues[] = $name . ': SOA serial mismatch for over 15 minutes'; }
			} elseif ( $r->get( 'dns_mismatch_since' ) ) { $r->set( array( 'dns_mismatch_since' => null ) ); }
			if ( 'domain' === $r->get( 'driver' ) && ! empty( $r->get( 'dnssec_keys' ) ) ) {
				foreach ( $nameservers as $server ) {
					$expiry = DnsQuery::read( $server, $name, 46 );
					if ( is_wp_error( $expiry ) ) { $issues[] = $name . ': RRSIG unavailable at ' . $server; }
					elseif ( $expiry < time() + 7 * DAY_IN_SECONDS ) { $issues[] = $name . ': RRSIG expires within seven days at ' . $server; }
				}
			}
		}
		if ( $issues ) {
			$today = gmdate( 'Y-m-d' );
			$hash = md5( implode( "\n", $issues ) . $today );
			if ( $hash !== get_option( self::DNS_ORPHAN_ALERT, '' ) ) {
				$to = (string) Settings::get( 'admin_email' );
				if ( is_email( $to ) ) { wp_mail( $to, __( 'Domain DNS health needs attention', 'beltoft-services' ), implode( "\n", $issues ) ); update_option( self::DNS_ORPHAN_ALERT, $hash, false ); }
			}
		}
		return $issues;
	}

}

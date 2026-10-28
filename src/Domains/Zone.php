<?php
/** Authoritative zone and registry DNSSEC coordination. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Alerts;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Zone {
	/** The other live license claiming this name, relative to this resource's customer. */
	public static function owner( Resource $r ): string {
		$name = (string) $r->get( 'domain' );
		// The assignments table is the lock on a name: another customer's row (any status) is enough, even with no live resource.
		if ( DnsSchema::ready() ) {
			$row = DnsAssignments::get( $name );
			if ( null !== $row && (int) $row['user_id'] !== (int) ( $r->license()['user_id'] ?? 0 ) ) { return 'other'; }
		}
		foreach ( array( 'domain', 'dns' ) as $kind ) {
			foreach ( Resource::live_ids( $kind ) as $id ) {
				$other = Resource::find( $id );
				if ( ! $other || $other->id() === $r->id() || 'deleted' === $other->get( 'state' ) || $other->get( 'domain' ) !== $name ) { continue; }
				if ( (int) ( $other->license()['user_id'] ?? 0 ) !== (int) ( $r->license()['user_id'] ?? 0 ) ) { return 'other'; }
				return $kind;
			}
		}
		return 'none';
	}

	/** Make the configured servers authoritative before a registration request. Some registries require this. */
	public static function prepare( Resource $r ): StepResult {
		$name = (string) $r->get( 'domain' );
		$nameservers = self::nameservers();
		if ( count( $nameservers ) < 2 ) { return StepResult::retry( 3600, 'Configure at least two domain name servers.' ); }
		$owner = self::owner( $r );
		if ( 'other' === $owner ) {
			if ( ! $r->get( 'zone_conflict_alerted' ) ) { Alerts::admin( $r, 'Domain zone belongs to a different customer.' ); $r->set( array( 'zone_conflict_alerted' => true ) ); }
			return StepResult::retry( 3600, 'A domain zone is assigned to another customer.' );
		}
		if ( 'domain' === $owner ) { return StepResult::fail( 'This domain is already registered here.' ); }
		$dns = Clients::dns();
		$existing = $dns->zone( $name );
		if ( is_wp_error( $existing ) ) { return StepResult::retry( 3600, 'DNS zone could not be checked.' ); }
		if ( null === $existing ) {
			if ( ! $r->set( array( 'zone_claimed' => true ) ) ) { return StepResult::retry( 3600, 'DNS zone claim could not be saved.' ); }
			$created = $dns->create_zone( $name, $nameservers );
			if ( is_wp_error( $created ) ) {
				if ( 'zone_exists' === $created->get_error_code() || 'http_409' === $created->get_error_code() ) { $r->set( array( 'zone_claimed' => false ) ); }
				return StepResult::retry( 3600, 'DNS zone could not be created.' );
			}
		} elseif ( 'dns' !== $owner && ! $r->get( 'zone_claimed' ) ) {
			return StepResult::retry( 3600, 'An existing zone needs staff review.' );
		}
		$current = $dns->zone( $name );
		$serial = is_array( $current ) ? DnsQuery::zone_serial( $current ) : null;
		if ( null === $serial ) { return StepResult::retry( 300, 'The authoritative zone is not ready yet.' ); }
		foreach ( $nameservers as $server ) {
			$answer = DnsQuery::read( $server, $name, 6 );
			if ( is_wp_error( $answer ) || $answer !== $serial ) { return StepResult::retry( 300, 'The name servers have not received the zone yet.' ); }
		}
		return StepResult::done( array( 'zone_prepared' => true ) );
	}

	public static function nameservers(): array {
		$raw = Settings::get( 'domain_nameservers' );
		$raw = is_string( $raw ) ? $raw : '';
		return array_values( array_filter( array_map( static fn( $host ) => strtolower( trim( $host, " .\t\r\n" ) ), explode( ',', $raw ) ) ) );
	}

	public static function ensure( Resource $r, bool $switching_to_ours = false, bool $publish_ds = true ): StepResult {
		$name = (string) $r->get( 'domain' );
		$nameservers = self::nameservers();
		if ( ! $nameservers || ( ! $switching_to_ours && 'custom' === $r->get( 'ns_mode' ) ) ) { return StepResult::done(); }
		$dns = Clients::dns();
		$existing = $dns->zone( $name );
		if ( is_wp_error( $existing ) ) { return StepResult::retry( 3600, 'DNS zone could not be checked.' ); }
		if ( null === $existing ) {
			$created = $dns->create_zone( $name, $nameservers );
			if ( is_wp_error( $created ) ) { return StepResult::retry( 3600, 'DNS zone could not be created.' ); }
		}
		if ( 'dns' === $r->get( 'driver' ) ) { return StepResult::done( array( 'zone_ready' => true ) ); }
		// Sandbox registration has only a fake zone; its fake DNSKEY must never be sent to a registry.
		if ( 'sandbox' === Clients::mode() && $dns instanceof FakeDns ) { return StepResult::done( array( 'zone_ready' => true, 'dnssec_keys' => array() ) ); }
		$keys = $dns->secure( $name );
		if ( is_wp_error( $keys ) ) { return StepResult::retry( 3600, 'DNSSEC signing could not be enabled.' ); }
		if ( ! $publish_ds ) { return StepResult::done( array( 'zone_ready' => true, 'dnssec_keys' => $keys ) ); }
		$remote_id = (string) $r->get( 'remote_id' );
		if ( '' === $remote_id ) { return StepResult::retry( 3600, 'Registrar id is not stored yet.' ); }
		$reply = Clients::registrar()->request( 'PUT', '/domains/' . rawurlencode( $remote_id ), array( 'dnssec_keys' => $keys, 'is_dnssec_enabled' => true ) );
		if ( is_wp_error( $reply ) ) { return StepResult::retry( 3600, 'DNSSEC could not be published at the registry.' ); }
		return StepResult::done( array( 'zone_ready' => true, 'dnssec_keys' => $keys ) );
	}

	public static function remove( Resource $r ): StepResult {
		$name = (string) $r->get( 'domain' );
		if ( ! $r->get( 'zone_claimed' ) && ! $r->get( 'zone_ready' ) ) { return StepResult::done(); }
		$owner = self::owner( $r );
		if ( 'other' === $owner ) { return StepResult::retry( 3600, 'DNS zone ownership needs staff review.' ); }
		$remote_id = (string) $r->get( 'remote_id' );
		if ( '' !== $remote_id && ! empty( $r->get( 'dnssec_keys' ) ) ) {
			$reply = Clients::registrar()->request( 'PUT', '/domains/' . rawurlencode( $remote_id ), array( 'dnssec_keys' => array(), 'is_dnssec_enabled' => false ) );
			if ( is_wp_error( $reply ) ) { return StepResult::retry( 3600, 'DNSSEC could not be removed at the registry.' ); }
		}
		if ( 'domain' === $owner || 'dns' === $owner ) { return StepResult::done( array( 'zone_ready' => false, 'dnssec_keys' => array() ) ); }
		$deleted = Clients::dns()->delete_zone( $name );
		if ( is_wp_error( $deleted ) && 'http_404' !== $deleted->get_error_code() ) { return StepResult::retry( 3600, 'DNS zone could not be removed.' ); }
		// Autosecondary does not replicate deletion. Keep the resource open until staff clears any secondary copy.
		foreach ( array_slice( self::nameservers(), 1 ) as $secondary ) {
			$answer = DnsQuery::read( $secondary, $name, 6 );
			if ( ! is_wp_error( $answer ) || ! in_array( $answer->get_error_code(), array( 'dns_missing', 'dns_not_authoritative' ), true ) ) { return StepResult::retry( 3600, 'The DNS zone is still served by a secondary name server, or its status could not be checked.' ); }
		}
		return StepResult::done( array( 'zone_ready' => false, 'dnssec_keys' => array() ) );
	}
}

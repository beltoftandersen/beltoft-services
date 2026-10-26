<?php
/** Guarded client factory. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Clients {
	public static function live_allowed(): bool {
		return defined( 'BELTOFT_SERVICES_DOMAINS_LIVE' ) && true === constant( 'BELTOFT_SERVICES_DOMAINS_LIVE' );
	}

	public static function mode(): string {
		$requested = (string) Settings::get( 'domain_api_mode', 'fake' );
		if ( 'sandbox' === $requested ) {
			return 'sandbox';
		}
		return 'live' === $requested && self::live_allowed() ? 'live' : 'fake';
	}

	public static function base_url( string $mode ): string {
		return 'sandbox' === $mode ? 'https://api.sandbox.openprovider.nl/v1beta' : 'https://api.openprovider.eu/v1';
	}

	public static function registrar(): Client {
		$mode = self::mode();
		$account = Account::get();
		$client = 'fake' === $mode ? new FakeClient( true ) : new LiveClient( $mode, $account['username'], $account['password'] );
		$filtered = apply_filters( 'beltoft_services_domains_client', $client );
		if ( $filtered instanceof FakeClient || ( 'fake' !== $mode && $filtered instanceof LiveClient ) ) {
			return $filtered;
		}
		return $client;
	}

	public static function dns(): Dns {
		$account = Account::get();
		$client = self::live_allowed()
			? new LiveDns( (string) Settings::get( 'domain_dns_url', 'http://127.0.0.1:8081' ), $account['dns_key'], true )
			: new FakeDns( true );
		$filtered = apply_filters( 'beltoft_services_domains_dns', $client );
		if ( $filtered instanceof FakeDns || ( self::live_allowed() && $filtered instanceof LiveDns ) ) {
			return $filtered;
		}
		return $client;
	}
}

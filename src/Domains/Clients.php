<?php
/** Guarded client factory. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Clients {
	public static function live_allowed(): bool {
		return \BeltoftServices\Drivers\LiveGate::constant_true( 'BELTOFT_SERVICES_DOMAINS_LIVE' );
	}

	public static function mode(): string {
		$requested = (string) Settings::get( 'domain_api_mode', 'fake' );
		if ( 'sandbox' === $requested ) {
			return 'sandbox';
		}
		return 'live' === $requested && self::live_allowed() ? 'live' : 'fake';
	}

	public static function base_url( string $mode ): string {
		return 'sandbox' === $mode ? 'https://api.sandbox.openprovider.nl/v1' : 'https://api.openprovider.eu/v1';
	}

	/**
	 * Does the setting say live while wp-config.php does not allow it (a missing or mistyped constant)? Then no fake may
	 * answer for the registrar: orders would be sold and "fulfilled" with nothing registered.
	 */
	public static function live_missing(): bool {
		return 'live' === (string) Settings::get( 'domain_api_mode', 'fake' ) && ! self::live_allowed();
	}

	public static function registrar(): Client {
		if ( self::live_missing() ) {
			\BeltoftServices\Alerts::domains_not_live();

			return new class() implements Client {
				public function request( string $method, string $path, array $body = array(), array $query = array() ): array|\WP_Error {
					return new \WP_Error( 'registrar_not_live', 'The registrar is set to live but BELTOFT_SERVICES_DOMAINS_LIVE is not true in wp-config.php.' );
				}
			};
		}

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
		$live = self::live_allowed() && 'live' === self::mode();
		$client = $live
			? new LiveDns( (string) Settings::get( 'domain_dns_url', 'http://127.0.0.1:8081' ), $account['dns_key'], true )
			: new FakeDns( true );
		$filtered = apply_filters( 'beltoft_services_domains_dns', $client );
		if ( $filtered instanceof FakeDns || ( $live && $filtered instanceof LiveDns ) ) {
			return $filtered;
		}
		return $client;
	}
}

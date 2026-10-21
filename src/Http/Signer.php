<?php
/**
 * Request signatures and sign-in tokens of the HTTP driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Signs requests to a connection's service and makes the short-lived token of the "Open"
 * button. Plugins that talk to the same service (a customer app, say) use it too, so every
 * request is signed the same way.
 *
 * HMAC signature: hex HMAC-SHA256, keyed with the connection secret, over
 *
 *     METHOD "\n" path?query "\n" timestamp "\n" tenant "\n" sha256_hex(body)
 *
 * sent in the connection's timestamp, tenant and signature headers (by default
 * X-Beltoft-Timestamp, X-Beltoft-Tenant and X-Beltoft-Signature). The timestamp is in unix
 * seconds; the tenant is the resource id.
 */
final class Signer {

	/**
	 * Seconds an open token is valid.
	 */
	public const OPEN_TTL = 300;

	/**
	 * The authentication headers of one request.
	 *
	 * With `hmac` auth: the timestamp, tenant and signature headers. With `header` auth: the
	 * secret in the header named by `header_static`, and the tenant header.
	 *
	 * @param array<string, mixed> $connection     Connection, secret decrypted (Connections::get()).
	 * @param string               $method         HTTP method.
	 * @param string               $path_and_query The URL's path, plus "?" and the query when it has one.
	 * @param string               $body           Request body exactly as sent ('' for none).
	 * @param string               $tenant         Tenant (resource) id.
	 * @param int|null             $now            Unix time; now when null.
	 * @return array<string, string> Header name => value.
	 */
	public static function headers( array $connection, string $method, string $path_and_query, string $body, string $tenant, ?int $now = null ): array {
		$secret        = (string) ( $connection['secret'] ?? '' );
		$tenant_header = self::header_name( $connection, 'header_tenant', 'X-Beltoft-Tenant' );

		if ( 'header' === ( $connection['auth'] ?? 'hmac' ) ) {
			return array(
				(string) ( $connection['header_static'] ?? '' ) => $secret,
				$tenant_header => $tenant,
			);
		}

		$timestamp = (string) ( null === $now ? time() : $now );

		return array(
			self::header_name( $connection, 'header_timestamp', 'X-Beltoft-Timestamp' ) => $timestamp,
			$tenant_header => $tenant,
			self::header_name( $connection, 'header_signature', 'X-Beltoft-Signature' ) => self::signature( $secret, $method, $path_and_query, $timestamp, $tenant, $body ),
		);
	}

	/**
	 * The hex HMAC-SHA256 signature over the canonical string.
	 *
	 * @param string $secret         Shared secret.
	 * @param string $method         HTTP method.
	 * @param string $path_and_query Path and query.
	 * @param string $timestamp      Unix seconds.
	 * @param string $tenant         Tenant id.
	 * @param string $body           Body as sent.
	 */
	public static function signature( string $secret, string $method, string $path_and_query, string $timestamp, string $tenant, string $body ): string {
		$canonical = strtoupper( $method ) . "\n" . $path_and_query . "\n" . $timestamp . "\n" . $tenant . "\n" . hash( 'sha256', $body );

		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * A token for the "Open" button: base64url of the JSON payload
	 * `{tenant, email, license_key, exp}` (exp = now + 300 s), a dot, and the hex
	 * HMAC-SHA256 of that JSON payload with the connection secret.
	 *
	 * @param array<string, mixed> $connection  Connection, secret decrypted.
	 * @param string               $tenant      Tenant (resource) id.
	 * @param string               $email       Customer email.
	 * @param string               $license_key License key.
	 * @param int|null             $now         Unix time; now when null.
	 */
	public static function open_token( array $connection, string $tenant, string $email, string $license_key, ?int $now = null ): string {
		$payload = (string) wp_json_encode(
			array(
				'tenant'      => $tenant,
				'email'       => $email,
				'license_key' => $license_key,
				'exp'         => ( null === $now ? time() : $now ) + self::OPEN_TTL,
			),
			JSON_UNESCAPED_SLASHES
		);

		return self::base64url( $payload ) . '.' . hash_hmac( 'sha256', $payload, (string) ( $connection['secret'] ?? '' ) );
	}

	/**
	 * Checks an open token: the payload when the signature matches and it has not expired,
	 * otherwise null. This is what a service does on its side.
	 *
	 * @param array<string, mixed> $connection Connection, secret decrypted.
	 * @param string               $token      Token.
	 * @param int|null             $now        Unix time; now when null.
	 * @return array{tenant: string, email: string, license_key: string, exp: int}|null
	 */
	public static function verify_open_token( array $connection, string $token, ?int $now = null ): ?array {
		$secret = (string) ( $connection['secret'] ?? '' );
		$parts  = explode( '.', $token );

		if ( '' === $secret || 2 !== count( $parts ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $parts[0] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the token's payload.
		$payload = base64_decode( strtr( $parts[0], '-_', '+/' ), true );

		if ( false === $payload || ! hash_equals( hash_hmac( 'sha256', $payload, $secret ), $parts[1] ) ) {
			return null;
		}

		$data = json_decode( $payload, true );

		if ( ! is_array( $data ) || ! isset( $data['tenant'], $data['email'], $data['license_key'], $data['exp'] ) || ! is_int( $data['exp'] ) ) {
			return null;
		}

		if ( $data['exp'] < ( null === $now ? time() : $now ) ) {
			return null;
		}

		return array(
			'tenant'      => (string) $data['tenant'],
			'email'       => (string) $data['email'],
			'license_key' => (string) $data['license_key'],
			'exp'         => $data['exp'],
		);
	}

	/**
	 * A header name from the connection, or its default.
	 *
	 * @param array<string, mixed> $connection Connection.
	 * @param string               $key        Field.
	 * @param string               $fallback   Default name.
	 */
	private static function header_name( array $connection, string $key, string $fallback ): string {
		$name = (string) ( $connection[ $key ] ?? '' );

		return '' === $name ? $fallback : $name;
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $data Data.
	 */
	private static function base64url( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding the token's payload.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}

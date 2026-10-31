<?php
/**
 * Registered OAuth clients (Dynamic Client Registration, RFC 7591), public clients only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Oauth;

use BeltoftServices\Mcp\Access;

defined( 'ABSPATH' ) || exit;

/**
 * A client is an app (Claude) with a name and the redirect addresses it may use. Registration is open to anyone, so it
 * is checked hard: every redirect address must be on the owner's allowlist (exact match), there is no client secret,
 * and the table is capped; clients that never got a grant are removed after a day.
 */
final class Clients {

	/**
	 * Most clients kept.
	 */
	public const MAX = 500;

	/**
	 * Registers a client.
	 *
	 * @param array<string, mixed> $meta Client metadata as posted.
	 * @return array<string, mixed>|\WP_Error The registration response, or an error with an OAuth code.
	 */
	public static function register( array $meta ) {
		global $wpdb;

		$uris = isset( $meta['redirect_uris'] ) && is_array( $meta['redirect_uris'] ) ? array_values( $meta['redirect_uris'] ) : array();

		if ( ! $uris || count( $uris ) > 5 ) {
			return new \WP_Error( 'invalid_redirect_uri', 'Send between one and five redirect_uris.' );
		}

		$allowed = Access::redirect_allowlist();

		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! in_array( $uri, $allowed, true ) ) {
				return new \WP_Error( 'invalid_redirect_uri', 'A redirect_uri is not allowed on this site.' );
			}
		}

		$method = isset( $meta['token_endpoint_auth_method'] ) ? (string) $meta['token_endpoint_auth_method'] : 'none';

		if ( 'none' !== $method ) {
			return new \WP_Error( 'invalid_client_metadata', 'Only public clients (token_endpoint_auth_method none) are supported.' );
		}

		foreach ( array( 'grant_types' => array( 'authorization_code', 'refresh_token' ), 'response_types' => array( 'code' ) ) as $key => $ok ) {
			if ( isset( $meta[ $key ] ) && ( ! is_array( $meta[ $key ] ) || array_diff( $meta[ $key ], $ok ) ) ) {
				return new \WP_Error( 'invalid_client_metadata', 'Unsupported ' . $key . '.' );
			}
		}

		self::prune();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::tables()['clients'] ) ) >= self::MAX ) {
			return new \WP_Error( 'temporarily_unavailable', 'Too many registrations. Try again later.' );
		}

		$name = isset( $meta['client_name'] ) && is_string( $meta['client_name'] ) ? trim( sanitize_text_field( $meta['client_name'] ) ) : '';
		$name = '' === $name ? 'App' : substr( $name, 0, 100 );
		$id   = 'bsc_' . bin2hex( random_bytes( 16 ) );
		$now  = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->insert(
			Schema::tables()['clients'],
			array(
				'client_id'     => $id,
				'name'          => $name,
				'redirect_uris' => (string) wp_json_encode( $uris ),
				'created_at'    => $now,
			)
		);

		return array(
			'client_id'                  => $id,
			'client_id_issued_at'        => $now,
			'client_name'                => $name,
			'redirect_uris'              => $uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * A client.
	 *
	 * @param string $client_id Client id.
	 * @return array{client_id: string, name: string, redirect_uris: string[]}|null
	 */
	public static function find( string $client_id ): ?array {
		global $wpdb;

		if ( 1 !== preg_match( '/^bsc_[0-9a-f]{32}$/D', $client_id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, name, redirect_uris FROM %i WHERE client_id = %s', Schema::tables()['clients'], $client_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$uris = json_decode( (string) $row['redirect_uris'], true );

		return array(
			'client_id'     => (string) $row['client_id'],
			'name'          => (string) $row['name'],
			'redirect_uris' => is_array( $uris ) ? array_values( array_filter( $uris, 'is_string' ) ) : array(),
		);
	}

	/**
	 * Does this client own this redirect address, now that the owner's allowlist also allows it?
	 *
	 * @param array{redirect_uris: string[]} $client Client.
	 * @param string                         $uri    Address.
	 */
	public static function allows_redirect( array $client, string $uri ): bool {
		return in_array( $uri, $client['redirect_uris'], true ) && in_array( $uri, Access::redirect_allowlist(), true );
	}

	/**
	 * Removes clients that were registered over a day ago and never connected.
	 */
	public static function prune(): void {
		global $wpdb;

		$t = Schema::tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d AND client_id NOT IN (SELECT client_id FROM %i)', $t['clients'], time() - DAY_IN_SECONDS, $t['grants'] ) );
	}
}

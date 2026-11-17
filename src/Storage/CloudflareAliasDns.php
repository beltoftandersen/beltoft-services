<?php
/**
 * The live Cloudflare DNS writer for storage host name aliases.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare API v4, one zone by id, with a token scoped to Zone.DNS edit on that one zone. Built only by AliasDnsClients. A
 * record is always a CNAME with proxied false (SSH, rsync and borg cannot pass the proxy) and TTL 300. The token goes in the
 * Authorization header only and is scrubbed from every error. It touches only the records it is asked about by id or name;
 * the decision that a name is ours is HostAlias's.
 */
final class CloudflareAliasDns implements AliasDns {

	public const BASE    = 'https://api.cloudflare.com/client/v4';
	public const TIMEOUT = 20;
	public const TTL     = 300;

	/** @var string */
	private $zone;

	/** @var string */
	private $token;

	/** @var string */
	private $base;

	/**
	 * @param string $zone_id Zone id (32 hex characters).
	 * @param string $token   API token.
	 * @param string $base    API base, https only (a test may point elsewhere; default the real one).
	 * @throws \InvalidArgumentException When the zone id or the token is not usable.
	 */
	public function __construct( string $zone_id, string $token, string $base = self::BASE ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $zone_id ) || '' === $token || 0 !== strpos( $base, 'https://' ) ) {
			throw new \InvalidArgumentException( 'The zone id must be 32 hex characters and the token must be set.' );
		}

		$this->zone  = $zone_id;
		$this->token = $token;
		$this->base  = rtrim( $base, '/' );
	}

	/**
	 * One request: the decoded `result` or a WP_Error.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path after the zone.
	 * @param array<string, mixed> $body   JSON body.
	 * @return mixed|\WP_Error
	 */
	private function request( string $method, string $path, array $body = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array( 'Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json' ),
		);

		if ( array() !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$reply = wp_remote_request( $this->base . '/zones/' . $this->zone . '/dns_records' . $path, $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions -- the address is a constant of this class.

		if ( is_wp_error( $reply ) ) {
			return new \WP_Error( 'alias_dns_transport', 'The DNS provider could not be reached.' );
		}

		$status  = (int) wp_remote_retrieve_response_code( $reply );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $reply ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $status >= 200 && $status < 300 && ! empty( $decoded['success'] ) ) {
			return $decoded['result'] ?? array();
		}

		$why = preg_replace( '/[^a-z0-9_ ,.:-]/i', '', (string) ( $decoded['errors'][0]['message'] ?? '' ) );

		return new \WP_Error( 'alias_dns_http_' . $status, sprintf( 'The DNS provider answered %d%s.', $status, '' !== $why ? ': ' . substr( str_replace( $this->token, '', (string) $why ), 0, 200 ) : '' ), array( 'status' => $status ) );
	}

	/**
	 * A record in our shape.
	 *
	 * @param array<string, mixed> $row Provider record.
	 * @return array<string, mixed>
	 */
	private function record( array $row ): array {
		return array(
			'id'      => (string) ( $row['id'] ?? '' ),
			'name'    => strtolower( rtrim( (string) ( $row['name'] ?? '' ), '.' ) ),
			'type'    => (string) ( $row['type'] ?? '' ),
			'content' => strtolower( rtrim( (string) ( $row['content'] ?? '' ), '.' ) ),
			'proxied' => ! empty( $row['proxied'] ),
		);
	}

	/**
	 * Payload of a record: a CNAME, never proxied.
	 *
	 * @param string $name   Name.
	 * @param string $target Target.
	 * @return array<string, mixed>
	 */
	private static function payload( string $name, string $target ): array {
		return array( 'type' => 'CNAME', 'name' => strtolower( $name ), 'content' => strtolower( rtrim( $target, '.' ) ), 'ttl' => self::TTL, 'proxied' => false );
	}

	public function find( string $name ) {
		$rows = $this->request( 'GET', '?per_page=50&name=' . rawurlencode( strtolower( $name ) ) );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$rec = is_array( $row ) ? $this->record( $row ) : array();

			// The provider's own filter is not trusted: the name is compared here.
			if ( array() !== $rec && $rec['name'] === strtolower( $name ) ) {
				$out[] = $rec;
			}
		}

		return $out;
	}

	public function create( string $name, string $target ) {
		$row = $this->request( 'POST', '', self::payload( $name, $target ) );

		return is_wp_error( $row ) ? $row : $this->record( (array) $row );
	}

	public function update( string $id, string $name, string $target ) {
		$row = $this->request( 'PUT', '/' . self::id( $id ), self::payload( $name, $target ) );

		return is_wp_error( $row ) ? $row : true;
	}

	public function delete( string $id ) {
		$row = $this->request( 'DELETE', '/' . self::id( $id ) );

		return is_wp_error( $row ) ? $row : true;
	}

	/**
	 * A record id as a path part: hex only.
	 *
	 * @param string $id Id.
	 */
	private static function id( string $id ): string {
		return (string) preg_replace( '/[^a-f0-9]/', '', $id );
	}
}

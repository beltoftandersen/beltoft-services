<?php
/**
 * The live storage provider client.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the storage provider's HTTPS API (a Hetzner style Storage Box API: bearer token, JSON, /storage_boxes). It SPENDS
 * REAL MONEY (create and change_type) and is built only by StorageClients::build(). Written from the provider's published
 * API as read on 2026-11-17 and NOT yet tried on a real box: the field names are checked against the provider's own
 * description and every one is an owner step to confirm on a test box. The token goes in the Authorization header only
 * and is scrubbed from every error. A 429 or a 5xx is a retryable error (data retry true), never a success.
 */
final class LiveStorageClient implements StorageClient {

	public const TIMEOUT = 20;

	/** @var string */
	private $base;

	/** @var string */
	private $token;

	/**
	 * @param string $base  API base address, https only.
	 * @param string $token API token.
	 * @throws \InvalidArgumentException When the address is not https or the token is empty.
	 */
	public function __construct( string $base, string $token ) {
		$parts = wp_parse_url( trim( $base ) );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || '' === $token ) {
			throw new \InvalidArgumentException( 'The storage API address must be an https address and the token must be set.' );
		}

		$this->base  = 'https://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$this->token = $token;
	}

	/**
	 * One request. Returns the decoded body (an array, empty for no content) or a WP_Error.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Path after the base.
	 * @param array<string, mixed> $body   JSON body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, array $body = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
			),
		);

		if ( array() !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		// The address is the admin's own setting, not user input.
		$reply = wp_remote_request( $this->base . $path, $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions -- see above.

		if ( is_wp_error( $reply ) ) {
			return new \WP_Error( 'storage_transport', 'The storage provider could not be reached.', array( 'retry' => true ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $reply );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $reply ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $status >= 200 && $status < 300 ) {
			return $decoded;
		}

		$code = (string) ( $decoded['error']['code'] ?? '' );
		$why  = preg_replace( '/[^a-z0-9_ ,.:-]/i', '', (string) ( $decoded['error']['message'] ?? '' ) );
		$why  = str_replace( $this->token, '', (string) $why );

		return new \WP_Error(
			'storage_' . ( '' !== $code ? preg_replace( '/[^a-z0-9_]/i', '', $code ) : 'http_' . $status ),
			sprintf( 'The storage provider answered %d%s.', $status, '' !== $why ? ': ' . substr( $why, 0, 200 ) : '' ),
			array( 'status' => $status, 'retry' => 429 === $status || $status >= 500 )
		);
	}

	/**
	 * A box in our shape from the provider's object.
	 *
	 * @param array<string, mixed> $row Provider object.
	 * @return array<string, mixed>
	 */
	private function box( array $row ): array {
		$access = (array) ( $row['access_settings'] ?? array() );
		$plan   = $row['snapshot_plan'] ?? null;
		$type   = (array) ( $row['storage_box_type'] ?? array() );
		$stats  = (array) ( $row['stats'] ?? array() );

		return array(
			'id'             => (string) ( $row['id'] ?? '' ),
			'name'           => (string) ( $row['name'] ?? '' ),
			'status'         => (string) ( $row['status'] ?? '' ),
			'username'       => (string) ( $row['username'] ?? '' ),
			'host'           => (string) ( $row['server'] ?? '' ),
			'type'           => (string) ( $type['name'] ?? '' ),
			'location'       => (string) ( ( (array) ( $row['location'] ?? array() ) )['name'] ?? '' ),
			'size_bytes'     => (int) ( $type['size'] ?? 0 ),
			'used_bytes'     => (int) ( $stats['size_data'] ?? 0 ),
			'snapshot_bytes' => (int) ( $stats['size_snapshots'] ?? 0 ),
			'access'         => array(
				'reachable_externally' => ! empty( $access['reachable_externally'] ),
				'ssh_enabled'          => ! empty( $access['ssh_enabled'] ),
				'samba_enabled'        => ! empty( $access['samba_enabled'] ),
				'webdav_enabled'       => ! empty( $access['webdav_enabled'] ),
				'zfs_enabled'          => ! empty( $access['zfs_enabled'] ),
			),
			'protected'      => ! empty( ( (array) ( $row['protection'] ?? array() ) )['delete'] ),
			'snapshot_plan'  => is_array( $plan ) && ! empty( $plan ) ? array(
				'every'  => isset( $plan['day_of_month'] ) ? 'month' : ( isset( $plan['day_of_week'] ) ? 'week' : 'day' ),
				'hour'   => (int) ( $plan['hour'] ?? 0 ),
				'minute' => (int) ( $plan['minute'] ?? 0 ),
				'max'    => (int) ( $plan['max_snapshots'] ?? 0 ),
			) : null,
		);
	}

	/**
	 * Path part for an id: digits only, so nothing a caller passes becomes a path.
	 *
	 * @param string $id Id.
	 */
	private static function id( string $id ): string {
		return (string) preg_replace( '/[^0-9]/', '', $id );
	}

	public function types() {
		$reply = $this->request( 'GET', '/storage_box_types' );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$out = array();

		foreach ( (array) ( $reply['storage_box_types'] ?? array() ) as $row ) {
			if ( is_array( $row ) && '' !== (string) ( $row['name'] ?? '' ) ) {
				$out[ (string) $row['name'] ] = array( 'size_bytes' => (int) ( $row['size'] ?? 0 ), 'snapshots' => (int) ( $row['snapshot_limit'] ?? 0 ) );
			}
		}

		return $out;
	}

	public function find_box( string $name ) {
		$reply = $this->request( 'GET', '/storage_boxes?name=' . rawurlencode( $name ) );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		foreach ( (array) ( $reply['storage_boxes'] ?? array() ) as $row ) {
			// The provider's own filter is not trusted: the name is compared here.
			if ( is_array( $row ) && (string) ( $row['name'] ?? '' ) === $name ) {
				return $this->box( $row );
			}
		}

		return null;
	}

	public function get_box( string $id ) {
		$reply = $this->request( 'GET', '/storage_boxes/' . self::id( $id ) );

		return is_wp_error( $reply ) ? $reply : $this->box( (array) ( $reply['storage_box'] ?? array() ) );
	}

	public function create_box( string $name, string $type, string $location, string $password, array $keys, array $access ) {
		$reply = $this->request(
			'POST',
			'/storage_boxes',
			array(
				'name'             => $name,
				'password'         => $password,
				'location'         => $location,
				'storage_box_type' => $type,
				'ssh_keys'         => array_values( $keys ),
				'access_settings'  => $access,
				'labels'           => array( 'managed-by' => 'beltoft-services' ),
			)
		);

		return is_wp_error( $reply ) ? $reply : $this->box( (array) ( $reply['storage_box'] ?? array() ) );
	}

	public function change_type( string $id, string $type ) {
		$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/actions/change_type', array( 'storage_box_type' => $type ) );

		return is_wp_error( $reply ) ? $reply : true;
	}

	public function reset_password( string $id, string $password ) {
		$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/actions/reset_password', array( 'password' => $password ) );

		return is_wp_error( $reply ) ? $reply : true;
	}

	public function set_access( string $id, array $access ) {
		$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/actions/update_access_settings', $access );

		return is_wp_error( $reply ) ? $reply : true;
	}

	public function set_protection( string $id, bool $protected ) {
		$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/actions/change_protection', array( 'delete' => $protected ) );

		return is_wp_error( $reply ) ? $reply : true;
	}

	public function delete_box( string $id ) {
		$reply = $this->request( 'DELETE', '/storage_boxes/' . self::id( $id ) );

		return is_wp_error( $reply ) ? $reply : true;
	}

	public function snapshots( string $id ) {
		$reply = $this->request( 'GET', '/storage_boxes/' . self::id( $id ) . '/snapshots' );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$out = array();

		foreach ( (array) ( $reply['snapshots'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->snapshot( $row );
			}
		}

		usort( $out, static fn( $a, $b ) => $b['created'] <=> $a['created'] );

		return $out;
	}

	/**
	 * A snapshot in our shape.
	 *
	 * @param array<string, mixed> $row Provider object.
	 * @return array<string, mixed>
	 */
	private function snapshot( array $row ): array {
		$stats = (array) ( $row['stats'] ?? array() );

		return array(
			'id'         => (string) ( $row['id'] ?? '' ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'created'    => (int) strtotime( (string) ( $row['created'] ?? '' ) ),
			'size_bytes' => (int) ( $stats['size'] ?? 0 ),
			'automatic'  => ! empty( $row['is_automatic'] ),
		);
	}

	public function create_snapshot( string $id, string $name ) {
		$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/snapshots', array( 'description' => $name ) );

		return is_wp_error( $reply ) ? $reply : $this->snapshot( (array) ( $reply['snapshot'] ?? array() ) );
	}

	public function delete_snapshot( string $id, string $snapshot ) {
		$reply = $this->request( 'DELETE', '/storage_boxes/' . self::id( $id ) . '/snapshots/' . self::id( $snapshot ) );

		return is_wp_error( $reply ) ? $reply : true;
	}

	public function rollback_snapshot( string $id, string $snapshot ) {
		$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/actions/rollback_snapshot', array( 'snapshot' => self::id( $snapshot ) ) );

		return is_wp_error( $reply ) ? $reply : true;
	}

	public function set_snapshot_plan( string $id, ?array $plan ) {
		if ( null === $plan ) {
			$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/actions/disable_snapshot_plan' );

			return is_wp_error( $reply ) ? $reply : true;
		}

		$body = array(
			'max_snapshots' => (int) ( $plan['max'] ?? 7 ),
			'hour'          => (int) ( $plan['hour'] ?? 3 ),
			'minute'        => (int) ( $plan['minute'] ?? 0 ),
		);

		if ( 'week' === ( $plan['every'] ?? '' ) ) {
			$body['day_of_week'] = 1;
		} elseif ( 'month' === ( $plan['every'] ?? '' ) ) {
			$body['day_of_month'] = 1;
		}

		$reply = $this->request( 'POST', '/storage_boxes/' . self::id( $id ) . '/actions/enable_snapshot_plan', $body );

		return is_wp_error( $reply ) ? $reply : true;
	}
}

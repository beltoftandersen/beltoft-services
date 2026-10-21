<?php
/**
 * HTTP connections.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Http;

use BeltoftServices\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * The stored connections of the HTTP driver: where a service lives, how requests are signed and
 * what each operation calls. They are a list with add, edit and delete, so they have their own
 * option rather than a settings field. Secrets are encrypted with Crypto.
 */
final class Connections {

	public const OPTION = 'beltoft_services_connections';

	/**
	 * Operations that can have an endpoint.
	 *
	 * @var string[]
	 */
	public const ENDPOINTS = array( 'provision', 'pause', 'resume', 'update_plan', 'delete', 'status', 'usage' );

	/**
	 * Placeholders allowed in endpoint paths.
	 *
	 * @var string[]
	 */
	public const PATH_PLACEHOLDERS = array( 'resource_id', 'remote_id', 'license_key', 'customer_id', 'email' );

	/**
	 * Placeholders allowed in the body template.
	 *
	 * @var string[]
	 */
	public const BODY_PLACEHOLDERS = array( 'resource_id', 'remote_id', 'license_key', 'customer_id', 'email', 'limit', 'plan' );

	private const METHODS = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Hooks the filter other plugins read a connection through.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_connection', array( __CLASS__, 'filter_connection' ), 10, 2 );
	}

	/**
	 * Filter callback: the stored connection, secret decrypted.
	 *
	 * Documented where it is applied by consumers: `beltoft_services_connection( ?array $connection, string $id )`.
	 *
	 * @param mixed  $connection Value so far.
	 * @param string $id         Connection id.
	 * @return array<string, mixed>|null
	 */
	public static function filter_connection( $connection, $id = '' ) {
		$found = self::get( (string) $id );

		return null !== $found ? $found : ( is_array( $connection ) ? $connection : null );
	}

	/**
	 * Defaults for a new connection.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$endpoints = array();

		foreach ( self::ENDPOINTS as $name ) {
			$endpoints[ $name ] = array(
				'method' => 'POST',
				'path'   => '',
			);
		}

		return array(
			'label'             => '',
			'base_url'          => '',
			'auth'              => 'hmac',
			'secret'            => '',
			'header_timestamp'  => 'X-Beltoft-Timestamp',
			'header_tenant'     => 'X-Beltoft-Tenant',
			'header_signature'  => 'X-Beltoft-Signature',
			'header_static'     => '',
			'timeout'           => 10,
			'endpoints'         => $endpoints,
			'body_template'     => '',
			'remote_id_field'   => 'id',
			'usage_used_field'  => '',
			'usage_limit_field' => '',
			'open_url'          => '',
			'open_label'        => '',
		);
	}

	/**
	 * Every connection by id, secrets still encrypted.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$list   = array();

		if ( ! is_array( $stored ) ) {
			return $list;
		}

		foreach ( $stored as $id => $connection ) {
			if ( is_array( $connection ) ) {
				$list[ (string) $id ] = self::merge_defaults( $connection );
			}
		}

		return $list;
	}

	/**
	 * One connection with its secret decrypted. The secret is an empty string when none is
	 * saved or it cannot be decrypted (key missing or changed).
	 *
	 * @param string $id Connection id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		if ( ! isset( $all[ $id ] ) ) {
			return null;
		}

		$connection           = $all[ $id ];
		$connection['secret'] = '' === (string) $connection['secret'] ? '' : (string) Crypto::decrypt( (string) $connection['secret'] );

		return $connection;
	}

	/**
	 * Whether a secret is saved for the connection.
	 *
	 * @param string $id Connection id.
	 */
	public static function has_secret( string $id ): bool {
		$all = self::all();

		return isset( $all[ $id ] ) && '' !== (string) $all[ $id ]['secret'];
	}

	/**
	 * Validates and saves a connection. An empty `secret` keeps the saved one; `remove_secret`
	 * clears it.
	 *
	 * @param string               $id    Connection id (letters, numbers, dashes and underscores).
	 * @param array<string, mixed> $input Raw values, as posted.
	 * @return true|\WP_Error
	 */
	public static function save( string $id, array $input ) {
		$id = sanitize_key( $id );

		if ( '' === $id ) {
			return new \WP_Error( 'beltoft_services_connection_id', __( 'A connection needs an id.', 'beltoft-services' ) );
		}

		$all      = self::all();
		$existing = $all[ $id ] ?? array();
		$clean    = self::merge_defaults( $existing );

		$clean['label'] = sanitize_text_field( (string) ( $input['label'] ?? '' ) );

		if ( '' === $clean['label'] ) {
			return new \WP_Error( 'beltoft_services_connection_label', __( 'A connection needs a label.', 'beltoft-services' ) );
		}

		$url = self::clean_base_url( (string) ( $input['base_url'] ?? '' ) );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$clean['base_url'] = $url;

		$auth = (string) ( $input['auth'] ?? 'hmac' );

		if ( ! in_array( $auth, array( 'hmac', 'header' ), true ) ) {
			return new \WP_Error( 'beltoft_services_connection_auth', __( 'Authentication must be HMAC or a static header.', 'beltoft-services' ) );
		}

		$clean['auth'] = $auth;

		$defaults = self::defaults();

		foreach ( array( 'header_timestamp', 'header_tenant', 'header_signature' ) as $key ) {
			$name = trim( (string) ( $input[ $key ] ?? '' ) );
			$name = '' === $name ? $defaults[ $key ] : $name;

			if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
				return new \WP_Error( 'beltoft_services_connection_header', __( 'Header names may contain letters, numbers and dashes only.', 'beltoft-services' ) );
			}

			$clean[ $key ] = $name;
		}

		$static = trim( (string) ( $input['header_static'] ?? '' ) );

		if ( '' !== $static && ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $static ) ) {
			return new \WP_Error( 'beltoft_services_connection_header', __( 'The header name may contain letters, numbers and dashes only.', 'beltoft-services' ) );
		}

		if ( 'header' === $clean['auth'] && '' === $static ) {
			return new \WP_Error( 'beltoft_services_connection_header', __( 'Header authentication needs a header name.', 'beltoft-services' ) );
		}

		$clean['header_static'] = $static;

		$timeout = isset( $input['timeout'] ) && '' !== $input['timeout'] ? (int) $input['timeout'] : 10;

		if ( $timeout < 1 || $timeout > 120 ) {
			return new \WP_Error( 'beltoft_services_connection_timeout', __( 'The timeout must be between 1 and 120 seconds.', 'beltoft-services' ) );
		}

		$clean['timeout'] = $timeout;

		$endpoints = self::clean_endpoints( is_array( $input['endpoints'] ?? null ) ? $input['endpoints'] : array() );

		if ( is_wp_error( $endpoints ) ) {
			return $endpoints;
		}

		$clean['endpoints'] = $endpoints;

		$body = trim( (string) ( $input['body_template'] ?? '' ) );

		if ( '' !== $body ) {
			$check = self::check_placeholders( $body, self::BODY_PLACEHOLDERS );

			if ( null !== $check ) {
				/* translators: %s: placeholder */
				return new \WP_Error( 'beltoft_services_connection_body', sprintf( __( 'Unknown placeholder {%s} in the body template.', 'beltoft-services' ), $check ) );
			}

			$probe = preg_replace( '/\{[a-z_]+\}/', '0', $body );

			if ( null === json_decode( (string) $probe ) && JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error( 'beltoft_services_connection_body', __( 'The body template is not valid JSON.', 'beltoft-services' ) );
			}
		}

		$clean['body_template'] = $body;

		foreach ( array( 'remote_id_field', 'usage_used_field', 'usage_limit_field' ) as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );

			if ( 'remote_id_field' === $key && '' === $value ) {
				$value = 'id';
			}

			if ( '' !== $value && ! preg_match( '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $value ) ) {
				return new \WP_Error( 'beltoft_services_connection_field', __( 'Response fields are dot paths such as data.id.', 'beltoft-services' ) );
			}

			$clean[ $key ] = $value;
		}

		$open_url = trim( (string) ( $input['open_url'] ?? '' ) );

		if ( '' !== $open_url ) {
			if ( ! self::looks_like_url( str_replace( '{token}', 'x', $open_url ) ) || ! self::scheme_allowed( $open_url ) ) {
				return new \WP_Error( 'beltoft_services_connection_open_url', __( 'The open URL must be a valid https address, with {token} where the token goes.', 'beltoft-services' ) );
			}
		}

		$clean['open_url']   = $open_url;
		$clean['open_label'] = sanitize_text_field( (string) ( $input['open_label'] ?? '' ) );

		$secret = self::clean_secret( $input, (string) ( $existing['secret'] ?? '' ) );

		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		if ( '' === $secret ) {
			return new \WP_Error( 'beltoft_services_connection_secret', __( 'A connection needs a secret: the HMAC key, or the value sent in the header.', 'beltoft-services' ) );
		}

		$clean['secret'] = $secret;

		$all[ $id ] = $clean;
		update_option( self::OPTION, $all, false );

		return true;
	}

	/**
	 * Deletes a connection.
	 *
	 * @param string $id Connection id.
	 */
	public static function delete( string $id ): bool {
		$all = self::all();

		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		return true;
	}

	/**
	 * Whether a URL (which may hold {token}) uses https, or http to a private or loopback IP literal.
	 *
	 * @param string $url URL.
	 */
	public static function scheme_allowed( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( str_replace( array( '{', '}' ), '', $url ), PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( str_replace( array( '{', '}' ), '', $url ), PHP_URL_HOST );

		// Plain http only to an IP literal: a name such as localhost could resolve anywhere.
		$bare = trim( $host, '[]' );

		return 'https' === $scheme || ( 'http' === $scheme && false !== filter_var( $bare, FILTER_VALIDATE_IP ) && self::is_private_host( $bare ) );
	}

	/**
	 * Whether a text is an http or https URL with a plain host name or IP (and no spaces or credentials).
	 *
	 * @param string $url URL.
	 */
	private static function looks_like_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& ! isset( $parts['user'] )
			&& 1 === preg_match( '/^[A-Za-z0-9.:\[\]-]+$/', $parts['host'] )
			&& 1 !== preg_match( '/[\s\\\\]/', $url );
	}

	/**
	 * Whether a host is `localhost` or a private or loopback IP literal: 10/8, 172.16/12,
	 * 192.168/16, 127/8, ::1 or fc00::/7. No DNS lookup is made.
	 *
	 * @param string $host Host name or IP literal.
	 */
	public static function is_private_host( string $host ): bool {
		$host = strtolower( trim( $host, "[] \t" ) );

		if ( 'localhost' === $host ) {
			return true;
		}

		$packed = false === filter_var( $host, FILTER_VALIDATE_IP ) ? false : inet_pton( $host );

		if ( false === $packed ) {
			return false;
		}

		if ( 4 === strlen( $packed ) ) {
			$a = ord( $packed[0] );
			$b = ord( $packed[1] );

			return 10 === $a || 127 === $a || ( 172 === $a && $b >= 16 && $b <= 31 ) || ( 192 === $a && 168 === $b );
		}

		// IPv4-mapped (::ffff:a.b.c.d): judge the embedded address.
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return self::is_private_host( (string) inet_ntop( substr( $packed, 12 ) ) );
		}

		return $packed === str_repeat( "\0", 15 ) . "\1" || 0xfc === ( ord( $packed[0] ) & 0xfe );
	}

	/**
	 * Fills in what a stored connection lacks.
	 *
	 * @param array<string, mixed> $connection Stored values.
	 * @return array<string, mixed>
	 */
	private static function merge_defaults( array $connection ): array {
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, array_intersect_key( $connection, $defaults ) );
		$stored   = is_array( $connection['endpoints'] ?? null ) ? $connection['endpoints'] : array();

		foreach ( self::ENDPOINTS as $name ) {
			$merged['endpoints'][ $name ] = array_merge( $defaults['endpoints'][ $name ], is_array( $stored[ $name ] ?? null ) ? array_intersect_key( $stored[ $name ], array( 'method' => 1, 'path' => 1 ) ) : array() );
		}

		$merged['endpoints'] = array_intersect_key( $merged['endpoints'], $defaults['endpoints'] );

		return $merged;
	}

	/**
	 * Validates the base URL.
	 *
	 * @param string $url Raw URL.
	 * @return string|\WP_Error
	 */
	private static function clean_base_url( string $url ) {
		$url = untrailingslashit( trim( $url ) );

		if ( ! self::looks_like_url( $url ) || false !== strpbrk( $url, '#?' ) ) {
			return new \WP_Error( 'beltoft_services_connection_url', __( 'Enter a valid base URL.', 'beltoft-services' ) );
		}

		if ( ! self::scheme_allowed( $url ) ) {
			return new \WP_Error( 'beltoft_services_connection_https', __( 'The base URL must use https, unless the host is a private or loopback IP address.', 'beltoft-services' ) );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Validates the endpoints. An endpoint without a path is not used.
	 *
	 * @param array<string, mixed> $input Posted endpoints.
	 * @return array<string, array{method:string,path:string}>|\WP_Error
	 */
	private static function clean_endpoints( array $input ) {
		$clean = array();

		foreach ( self::ENDPOINTS as $name ) {
			$row    = is_array( $input[ $name ] ?? null ) ? $input[ $name ] : array();
			$method = strtoupper( trim( (string) ( $row['method'] ?? 'POST' ) ) );
			$path   = trim( (string) ( $row['path'] ?? '' ) );

			if ( '' !== $path ) {
				if ( ! in_array( $method, self::METHODS, true ) ) {
					/* translators: %s: endpoint name */
					return new \WP_Error( 'beltoft_services_connection_endpoint', sprintf( __( 'The %s endpoint needs a method: GET, POST, PUT, PATCH or DELETE.', 'beltoft-services' ), $name ) );
				}

				if ( '/' !== $path[0] || preg_match( '/[\s\\\\]|\.\.|\/\//', $path ) ) {
					/* translators: %s: endpoint name */
					return new \WP_Error( 'beltoft_services_connection_endpoint', sprintf( __( 'The %s path must start with a slash and hold no spaces or "..".', 'beltoft-services' ), $name ) );
				}

				$unknown = self::check_placeholders( $path, self::PATH_PLACEHOLDERS );

				if ( null !== $unknown ) {
					/* translators: 1: endpoint name, 2: placeholder */
					return new \WP_Error( 'beltoft_services_connection_endpoint', sprintf( __( 'Unknown placeholder {%2$s} in the %1$s path.', 'beltoft-services' ), $name, $unknown ) );
				}
			} elseif ( ! in_array( $method, self::METHODS, true ) ) {
				$method = 'POST';
			}

			$clean[ $name ] = array(
				'method' => $method,
				'path'   => $path,
			);
		}

		return $clean;
	}

	/**
	 * The first placeholder in a text that is not allowed, or null.
	 *
	 * @param string   $text    Path or template.
	 * @param string[] $allowed Allowed names.
	 */
	private static function check_placeholders( string $text, array $allowed ): ?string {
		if ( preg_match_all( '/\{([a-z_]+)\}/', $text, $found ) ) {
			foreach ( $found[1] as $name ) {
				if ( ! in_array( $name, $allowed, true ) ) {
					return $name;
				}
			}
		}

		return null;
	}

	/**
	 * The secret to store: a new one encrypted, the saved one, or none.
	 *
	 * @param array<string, mixed> $input Posted values.
	 * @param string               $saved Saved (encrypted) value.
	 * @return string|\WP_Error
	 */
	private static function clean_secret( array $input, string $saved ) {
		$new = trim( (string) ( $input['secret'] ?? '' ) );

		if ( '' !== $new ) {
			if ( ! Crypto::available() ) {
				return new \WP_Error( 'beltoft_services_no_key', __( 'Add BELTOFT_SERVICES_KEY to wp-config.php before saving secrets.', 'beltoft-services' ) );
			}

			return Crypto::encrypt( $new );
		}

		return empty( $input['remove_secret'] ) ? $saved : '';
	}
}

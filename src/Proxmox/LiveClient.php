<?php
/**
 * The live Proxmox client. Guarded: it refuses to exist unless wp-config.php allows it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to a real Proxmox VE API (PRODUCTION). Built only by Clients::for(), which passes the
 * allowance read from the constant BELTOFT_SERVICES_PROXMOX_LIVE. Nothing in the plugin sets it.
 *
 * Cluster keys: api_url (https only), token_id (USER@REALM!TOKENID), token_secret (plain text
 * here: the caller decrypts it), ca_file (optional), pin_sha256 (optional base64 SHA-256 of the
 * server's public key).
 *
 * wp_remote_request() is used, not the safe variant: the safe one refuses port 8006 and private
 * hosts, which is where a Proxmox lives. The host is the admin's own setting, never user input.
 */
class LiveClient implements Client {

	use Tasks;

	const TIMEOUT = 30;

	/**
	 * Base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * The Authorization header value.
	 *
	 * @var string
	 */
	private $authorization;

	/**
	 * The token secret, for scrubbing.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * CA file, or ''.
	 *
	 * @var string
	 */
	private $ca_file;

	/**
	 * Base64 public key pin, or ''.
	 *
	 * @var string
	 */
	private $pin;

	/**
	 * Host of the API, for the pin hook.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Parameters whose values never appear in errors.
	 */
	const SENSITIVE = array( 'cipassword', 'password', 'sshkeys' );

	/**
	 * @param array<string, mixed> $cluster      Cluster settings.
	 * @param bool                 $live_allowed Whether wp-config.php allows live calls.
	 *
	 * @throws \RuntimeException         Not allowed, or a pin the site cannot enforce.
	 * @throws \InvalidArgumentException Bad settings.
	 */
	public function __construct( array $cluster, bool $live_allowed ) {
		if ( ! $live_allowed ) {
			throw new \RuntimeException( 'Live Proxmox client disabled: define BELTOFT_SERVICES_PROXMOX_LIVE in wp-config.php' );
		}

		$url = isset( $cluster['api_url'] ) ? trim( (string) $cluster['api_url'] ) : '';

		if ( 0 !== stripos( $url, 'https://' ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			throw new \InvalidArgumentException( 'The Proxmox API URL must use https.' );
		}

		$token_id = isset( $cluster['token_id'] ) ? (string) $cluster['token_id'] : '';

		if ( 1 !== preg_match( '/^[^@!\s=]+@[^@!\s=]+![^@!\s=]+$/', $token_id ) ) {
			throw new \InvalidArgumentException( 'The Proxmox token id must look like USER@REALM!TOKENID.' );
		}

		$secret = isset( $cluster['token_secret'] ) ? (string) $cluster['token_secret'] : '';

		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'The Proxmox token secret is empty.' );
		}

		$ca = isset( $cluster['ca_file'] ) ? (string) $cluster['ca_file'] : '';

		if ( '' !== $ca && ! is_readable( $ca ) ) {
			throw new \InvalidArgumentException( 'The Proxmox CA file cannot be read.' );
		}

		$pin = isset( $cluster['pin_sha256'] ) ? (string) $cluster['pin_sha256'] : '';

		if ( '' !== $pin ) {
			$raw = base64_decode( $pin, true );

			if ( false === $raw || 32 !== strlen( $raw ) ) {
				throw new \InvalidArgumentException( 'The Proxmox pin must be the base64 of a SHA-256 hash.' );
			}

			if ( ! $this->curl_pin_supported() ) {
				throw new \RuntimeException( 'A Proxmox public key pin needs PHP curl with CURLOPT_PINNEDPUBLICKEY, which this server does not have.' );
			}
		}

		$this->base          = untrailingslashit( $url );
		$this->host          = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$this->authorization = 'PVEAPIToken=' . $token_id . '=' . $secret;
		$this->secret        = $secret;
		$this->ca_file       = $ca;
		$this->pin           = $pin;
	}

	/**
	 * One API call.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path relative to /api2/json.
	 * @param array<string, mixed> $params Parameters.
	 * @return array|string|int|float|bool|null|\WP_Error
	 */
	public function request( string $method, string $path, array $params = array() ) {
		$method = strtoupper( $method );

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) {
			return new \WP_Error( 'bad_method', 'Unsupported Proxmox request method.' );
		}

		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '?' ) ) {
			return new \WP_Error( 'bad_path', 'A Proxmox path starts with a slash and carries no query.' );
		}

		$clean = array();
		$scrub = array( $this->secret );

		foreach ( $params as $name => $value ) {
			if ( null === $value ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				return new \WP_Error( 'bad_params', 'Proxmox parameters must be scalar values.' );
			}

			$value = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;

			if ( in_array( $name, self::SENSITIVE, true ) ) {
				$scrub[] = $value;
				$scrub[] = rawurlencode( $value );
				$scrub[] = urlencode( $value );
			}

			// The API wants sshkeys url-encoded as a value (API viewer, config: format urlencoded).
			if ( 'sshkeys' === $name ) {
				$value   = rawurlencode( $value );
				$scrub[] = $value;
				$scrub[] = rawurlencode( $value );
			}

			$clean[ (string) $name ] = $value;
		}

		$encoded = http_build_query( $clean, '', '&', PHP_QUERY_RFC3986 );
		$url     = $this->base . '/api2/json' . $path;
		$args    = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'        => 'application/json',
				'Authorization' => $this->authorization,
			),
		);

		if ( 'GET' === $method || 'DELETE' === $method ) {
			$url .= '' === $encoded ? '' : '?' . $encoded;
		} elseif ( '' !== $encoded ) {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = $encoded;
		}

		if ( '' !== $this->ca_file ) {
			$args['sslcertificates'] = $this->ca_file;
		}

		$pinned = '' !== $this->pin;

		if ( $pinned ) {
			add_action( 'http_api_curl', array( $this, 'pin_key' ), 10, 3 );
		}

		try {
			$response = wp_remote_request( $url, $args );
		} finally {
			if ( $pinned ) {
				remove_action( 'http_api_curl', array( $this, 'pin_key' ), 10 );
			}
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'transport', 'Proxmox could not be reached: ' . $this->clean( $response->get_error_message(), $scrub ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$reason = (string) wp_remote_retrieve_response_message( $response );

			if ( '' === $reason ) {
				$decoded = json_decode( $body, true );
				$reason  = is_array( $decoded ) && isset( $decoded['message'] ) && is_scalar( $decoded['message'] ) ? (string) $decoded['message'] : '';
			}

			return new \WP_Error(
				'http_' . $code,
				'Proxmox answered HTTP ' . $code . ( '' === $reason ? '' : ': ' . $this->clean( $reason, $scrub ) ),
				array( 'status' => $code )
			);
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! array_key_exists( 'data', $decoded ) ) {
			return new \WP_Error( 'bad_response', 'Proxmox sent an answer that is not JSON with a data member.' );
		}

		return $decoded['data'];
	}

	/**
	 * Sets the public key pin on the curl handle (action http_api_curl, fired by
	 * WP_Http_Curl and Requests; present only while this client's request runs).
	 *
	 * @param resource|\CurlHandle $handle Curl handle.
	 * @param mixed                $args   Request arguments.
	 * @param mixed                $url    Request URL.
	 */
	public function pin_key( $handle, $args = null, $url = '' ): bool {
		if ( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ) !== $this->host ) {
			return false;
		}

		return curl_setopt( $handle, CURLOPT_PINNEDPUBLICKEY, 'sha256//' . $this->pin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress has no request argument for a public key pin.
	}

	/**
	 * Whether this PHP can enforce a public key pin. A seam for tests.
	 */
	protected function curl_pin_supported(): bool {
		return defined( 'CURLOPT_PINNEDPUBLICKEY' ) && function_exists( 'curl_setopt' );
	}

	/**
	 * Sleeps between two task polls.
	 *
	 * @param int $seconds Seconds.
	 */
	protected function pause( int $seconds ): void {
		sleep( $seconds );
	}

	/**
	 * Cuts a text and replaces the secrets in it.
	 *
	 * @param string   $text   Text.
	 * @param string[] $scrub  Values to hide.
	 */
	private function clean( string $text, array $scrub ): string {
		$scrub = array_filter( $scrub, static fn( $value ) => strlen( (string) $value ) >= 4 );
		usort( $scrub, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$text = str_replace( $scrub, '***', wp_check_invalid_utf8( $text, true ) );

		return mb_substr( $text, 0, 200 );
	}
}

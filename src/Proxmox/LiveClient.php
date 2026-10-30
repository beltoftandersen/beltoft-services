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
	private int $request_timeout = self::TIMEOUT;

	/** A bounded read for guest-agent calls that must not hold a job for 30 seconds. */
	public function request_with_timeout( string $method, string $path, array $params, int $seconds ) {
		$old = $this->request_timeout;
		$this->request_timeout = max( 1, min( self::TIMEOUT, $seconds ) );
		try { return $this->request( $method, $path, $params ); }
		finally { $this->request_timeout = $old; }
	}

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
	 * Whether the pin was applied to the handle of the current request.
	 *
	 * @var bool
	 */
	private $pin_applied = false;

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

		if ( 1 !== preg_match( '/^[^@!\s=]+@[^@!\s=]+![^@!\s=]+$/D', $token_id ) ) {
			throw new \InvalidArgumentException( 'The Proxmox token id must look like USER@REALM!TOKENID.' );
		}

		$secret = isset( $cluster['token_secret'] ) ? (string) $cluster['token_secret'] : '';

		if ( '' === trim( $secret ) ) {
			throw new \InvalidArgumentException( 'The Proxmox token secret is empty (was the secrets key changed?).' );
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
			'timeout'     => $this->request_timeout,
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

		$this->pin_applied = false;

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

		// Fail closed: a transport that never applied the pin (not curl) is not trusted.
		if ( $pinned && ! $this->pin_applied ) {
			return new \WP_Error( 'pin_unverified', 'The Proxmox public key pin could not be applied to this connection, so the answer was discarded.' );
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
	 * With a CA file the pin comes on top of the full chain and host name verification. With a
	 * pin and no CA file the pin replaces the chain check, for this request only: a Proxmox
	 * certificate is usually self-signed and would never pass it. The pin is set first; the
	 * checks are switched off only after curl accepted it, and the request counts as pinned only
	 * when every option was accepted (otherwise the answer is discarded, see request()).
	 *
	 * Sources: the hook runs from Requests' curl.before_send (wp-includes/class-wp-http-requests-hooks.php:58,
	 * dispatched at wp-includes/Requests/src/Transport/Curl.php:174), before the transport applies its own
	 * verify options (Curl.php:192-203), which only change anything for sslverify=false and the CA path;
	 * we pass sslverify=true and, here, no CA file, so they leave these two options alone. PHP curl_setopt():
	 * CURLOPT_SSL_VERIFYPEER (bool), CURLOPT_SSL_VERIFYHOST (0 or 2) and CURLOPT_PINNEDPUBLICKEY
	 * (https://www.php.net/manual/en/function.curl-setopt.php). libcurl checks the pin after the TLS
	 * handshake whatever the verify options say (a wrong pin ends with CURLE_SSL_PINNEDPUBKEYNOTMATCH, 90).
	 *
	 * @param resource|\CurlHandle $handle Curl handle.
	 * @param mixed                $args   Request arguments.
	 * @param mixed                $url    Request URL.
	 */
	public function pin_key( $handle, $args = null, $url = '' ): bool {
		if ( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ) !== $this->host ) {
			return false;
		}

		$this->pin_applied = $this->set_curl_option( $handle, CURLOPT_PINNEDPUBLICKEY, 'sha256//' . $this->pin );

		if ( $this->pin_applied && '' === $this->ca_file ) {
			$this->pin_applied = $this->set_curl_option( $handle, CURLOPT_SSL_VERIFYPEER, false )
				&& $this->set_curl_option( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
		}

		return $this->pin_applied;
	}

	/**
	 * One curl option on a handle. A seam for tests.
	 *
	 * @param resource|\CurlHandle $handle Curl handle.
	 * @param int                  $option CURLOPT_* constant.
	 * @param mixed                $value  Value.
	 */
	protected function set_curl_option( $handle, int $option, $value ): bool {
		return (bool) curl_setopt( $handle, $option, $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress has no request argument for a public key pin.
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
		$scrub = array_filter( $scrub, static fn( $value ) => '' !== (string) $value );
		usort( $scrub, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$text = str_replace( $scrub, '***', wp_check_invalid_utf8( $text, true ) );

		return mb_substr( $text, 0, 200 );
	}
}

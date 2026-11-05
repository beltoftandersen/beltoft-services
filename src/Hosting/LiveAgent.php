<?php
/**
 * The live node agent client.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Http\Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the node agent (the hosting-agent service) of a hosting node (PRODUCTION). Built only by
 * Clients::build_agent(), which passes the allowance read from the constant BELTOFT_SERVICES_HOSTING_LIVE and
 * checks the explicit site tag. Nothing in the plugin sets the constant and no test builds it with the allowance
 * outside a seam that never touches the network.
 *
 * The wire format is the agent's own (its README, "Protocol"):
 *
 * - `POST <agent_url>/jobs` with the JSON body `{"id", "op", "args"}`; the answer is 202 and the job
 *   `{"id", "status": queued|running|done|failed, "result"?, "error"?}`. The same id twice returns the first job.
 * - `GET <agent_url>/jobs/<id>` returns the job (200) or 404 `http_404`.
 * - Errors are `{"code", "message"}`: 400 `invalid` or `unknown_op`, 401 `unauthorized`, 403, 404, 405, 409
 *   `replay`, 413 `too_large`, 500.
 * - Mutual TLS (the plugin's client certificate and key, the CA that signed the agent's certificate, and an
 *   optional pin of the agent's public key), the Signer's headers (HMAC-SHA256 of `METHOD, path?query, timestamp,
 *   tenant, sha256_hex(body)`, with the agent's key) and a tenant header.
 *
 * The client certificate, its key and the CA come from the node record as text and reach curl as blobs: nothing is
 * written to disk. A client that cannot enforce all of it (curl without blob options or without the pin option)
 * refuses to exist: no half-secured connection. Errors never contain the key, the signature or the body.
 */
class LiveAgent implements Agent {

	public const TIMEOUT = 20;

	/**
	 * Base address, no trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * The signing key (the agent's HMAC key).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Tenant header value.
	 *
	 * @var string
	 */
	private $tenant;

	/**
	 * Client certificate, key and CA as PEM text.
	 *
	 * @var string
	 */
	private $cert;
	private $client_key;
	private $ca;

	/**
	 * Base64 public key pin of the agent, or ''.
	 *
	 * @var string
	 */
	private $pin;

	/**
	 * Host of the agent, for the curl hook.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Whether every TLS option was applied to the handle of the current request.
	 *
	 * @var bool
	 */
	private $tls_applied = false;

	/**
	 * @param array<string, mixed> $node         Node settings, secrets decrypted.
	 * @param bool                 $live_allowed Whether wp-config.php allows live calls.
	 *
	 * @throws \RuntimeException Not allowed, bad settings, or a curl that cannot enforce the TLS settings.
	 */
	public function __construct( array $node, bool $live_allowed ) {
		if ( ! $live_allowed ) {
			throw new \RuntimeException( 'Live hosting agent client disabled: define BELTOFT_SERVICES_HOSTING_LIVE in wp-config.php' );
		}

		$url = trim( (string) ( $node['agent_url'] ?? '' ) );

		if ( 0 !== stripos( $url, 'https://' ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			throw new \RuntimeException( 'The agent address must use https.' );
		}

		$this->key        = (string) ( $node['agent_key'] ?? '' );
		$this->tenant     = (string) ( $node['agent_tenant'] ?? '' );
		$this->cert       = trim( (string) ( $node['agent_client_cert'] ?? '' ) );
		$this->client_key = trim( (string) ( $node['agent_client_key'] ?? '' ) );
		$this->ca         = trim( (string) ( $node['agent_ca'] ?? '' ) );
		$this->pin        = (string) ( $node['agent_pin'] ?? '' );

		if ( strlen( $this->key ) < 16 ) {
			throw new \RuntimeException( 'The agent key is not set (was the secrets key changed?).' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,120}$/D', $this->tenant ) ) {
			throw new \RuntimeException( 'The agent tenant is not set.' );
		}

		if ( 0 !== strpos( $this->cert, '-----BEGIN CERTIFICATE-----' ) || 0 !== strpos( $this->ca, '-----BEGIN CERTIFICATE-----' ) || false === strpos( $this->client_key, '-----BEGIN' ) ) {
			throw new \RuntimeException( 'The agent client certificate, its key and the CA are needed for mutual TLS.' );
		}

		if ( '' !== $this->pin ) {
			$raw = base64_decode( $this->pin, true );

			if ( false === $raw || 32 !== strlen( $raw ) ) {
				throw new \RuntimeException( 'The agent pin must be the base64 of a SHA-256 hash.' );
			}
		}

		if ( ! $this->curl_supported() ) {
			throw new \RuntimeException( 'Mutual TLS from memory needs PHP curl with the certificate blob options and the public key pin option, which this server does not have.' );
		}

		$this->base = untrailingslashit( $url );
		$this->host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $op     Operation.
	 * @param array<string, mixed> $args   Arguments.
	 * @param string               $job_id Job id.
	 */
	public function submit( string $op, array $args, string $job_id ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,120}$/D', $job_id ) || 1 !== preg_match( '/^[a-z]+\.[a-z_]+$/D', $op ) ) {
			return new \WP_Error( 'invalid', 'The job id or the operation is not valid.', array( 'status' => 400 ) );
		}

		$body = wp_json_encode(
			array(
				'id'   => $job_id,
				'op'   => $op,
				'args' => (object) $args,
			),
			JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $body ) ) {
			return new \WP_Error( 'invalid', 'The job arguments cannot be encoded.', array( 'status' => 400 ) );
		}

		$sent = $this->call( 'POST', '/jobs', $body, array( $this->key ) );

		// The same request twice in one second has the same signature, so the agent answers 409 "already received": the
		// job is there (a polling caller sends the same submit again), so read it instead of failing the step.
		if ( is_wp_error( $sent ) && 'transport' === $sent->get_error_code() && false !== strpos( $sent->get_error_message(), 'HTTP 409' ) ) {
			$read = $this->job( $job_id );

			return is_wp_error( $read ) ? $sent : $read;
		}

		return $sent;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $job_id Job id.
	 */
	public function job( string $job_id ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,120}$/D', $job_id ) ) {
			return new \WP_Error( 'invalid', 'The job id is not valid.', array( 'status' => 400 ) );
		}

		return $this->call( 'GET', '/jobs/' . $job_id, '', array( $this->key ) );
	}

	/**
	 * The request as it is sent: method, URL, headers and body. A seam for the tests, which check the exact wire
	 * format without a network.
	 *
	 * @param string $method Method.
	 * @param string $path   Path, with no query.
	 * @param string $body   Body as sent.
	 * @param int    $now    Unix time (for the signature).
	 * @return array{method: string, url: string, headers: array<string, string>, body: string}
	 */
	public function build( string $method, string $path, string $body, int $now = 0 ): array {
		$connection = array(
			'auth'   => 'hmac',
			'secret' => $this->key,
		);
		$headers    = Signer::headers( $connection, $method, $path, $body, $this->tenant, 0 === $now ? null : $now );

		$headers['Accept'] = 'application/json';

		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/json';
		}

		return array(
			'method'  => $method,
			'url'     => $this->base . $path,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * One request and its answer: the job array, or a WP_Error.
	 *
	 * @param string   $method  Method.
	 * @param string   $path    Path.
	 * @param string   $body    Body.
	 * @param string[] $scrub   Values that never appear in an error.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( string $method, string $path, string $body, array $scrub ) {
		$request = $this->build( $method, $path, $body );
		$scrub[] = (string) ( $request['headers'][ 'X-Beltoft-Signature' ] ?? '' );
		$answer  = $this->send( $request );

		if ( is_wp_error( $answer ) ) {
			return new \WP_Error( 'transport', 'The node agent could not be reached: ' . $this->clean( $answer->get_error_message(), $scrub ) );
		}

		$code    = (int) $answer['status'];
		$decoded = json_decode( (string) $answer['body'], true );

		if ( $code < 200 || $code >= 300 ) {
			$error = is_array( $decoded ) && isset( $decoded['code'] ) && is_scalar( $decoded['code'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $decoded['code'] ) ) : 'http_' . $code;
			$text  = is_array( $decoded ) && isset( $decoded['message'] ) && is_scalar( $decoded['message'] ) ? (string) $decoded['message'] : '';

			// A repeated request signature, a busy or failing agent: the caller tries again, it is no refusal.
			if ( 409 === $code || $code >= 500 || 408 === $code || 429 === $code ) {
				return new \WP_Error( 'transport', 'The node agent answered HTTP ' . $code . ( '' === $text ? '' : ': ' . $this->clean( $text, $scrub ) ) );
			}

			return new \WP_Error( '' === $error ? 'http_' . $code : $error, 'The node agent refused the request (HTTP ' . $code . ')' . ( '' === $text ? '' : ': ' . $this->clean( $text, $scrub ) ), array( 'status' => $code ) );
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['id'], $decoded['status'] ) || ! in_array( $decoded['status'], array( 'queued', 'running', 'done', 'failed' ), true ) ) {
			return new \WP_Error( 'transport', 'The node agent sent an answer that is not a job.' );
		}

		$job = array(
			'id'     => (string) $decoded['id'],
			'status' => (string) $decoded['status'],
		);

		if ( isset( $decoded['result'] ) && is_array( $decoded['result'] ) ) {
			$job['result'] = $decoded['result'];
		}

		if ( isset( $decoded['error'] ) && is_scalar( $decoded['error'] ) ) {
			$job['error'] = $this->clean( (string) $decoded['error'], $scrub );
		}

		return $job;
	}

	/**
	 * Sends the request over mutual TLS. A seam: tests override it.
	 *
	 * @param array{method: string, url: string, headers: array<string, string>, body: string} $request Request.
	 * @return array{status: int, body: string}|\WP_Error
	 */
	protected function send( array $request ) {
		$args = array(
			'method'      => $request['method'],
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $request['headers'],
		);

		if ( 'POST' === $request['method'] ) {
			$args['body'] = $request['body'];
		}

		$this->tls_applied = false;
		add_action( 'http_api_curl', array( $this, 'tls' ), 10, 3 );

		try {
			$response = wp_remote_request( $request['url'], $args );
		} finally {
			remove_action( 'http_api_curl', array( $this, 'tls' ), 10 );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Fail closed: a transport that never applied the TLS options (not curl) is not trusted.
		if ( ! $this->tls_applied ) {
			return new \WP_Error( 'tls_unverified', 'The mutual TLS settings could not be applied to this connection, so the answer was discarded.' );
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Sets the client certificate, its key, the CA and the pin on the curl handle (action http_api_curl). The
	 * request counts as secured only when every option was accepted.
	 *
	 * @param resource|\CurlHandle $handle Curl handle.
	 * @param mixed                $args   Request arguments.
	 * @param mixed                $url    Request URL.
	 */
	public function tls( $handle, $args = null, $url = '' ): bool {
		unset( $args );

		if ( strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ) !== $this->host ) {
			return false;
		}

		$ok = $this->set_curl_option( $handle, CURLOPT_SSLCERT_BLOB, $this->cert )
			&& $this->set_curl_option( $handle, CURLOPT_SSLKEY_BLOB, $this->client_key )
			&& $this->set_curl_option( $handle, CURLOPT_CAINFO_BLOB, $this->ca )
			&& $this->set_curl_option( $handle, CURLOPT_SSL_VERIFYPEER, true )
			&& $this->set_curl_option( $handle, CURLOPT_SSL_VERIFYHOST, 2 );

		if ( $ok && '' !== $this->pin ) {
			$ok = $this->set_curl_option( $handle, CURLOPT_PINNEDPUBLICKEY, 'sha256//' . $this->pin );
		}

		$this->tls_applied = $ok;

		return $ok;
	}

	/**
	 * One curl option on a handle. A seam for tests.
	 *
	 * @param resource|\CurlHandle $handle Curl handle.
	 * @param int                  $option CURLOPT_* constant.
	 * @param mixed                $value  Value.
	 */
	protected function set_curl_option( $handle, int $option, $value ): bool {
		return (bool) curl_setopt( $handle, $option, $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress has no request argument for a client certificate from memory or a public key pin.
	}

	/**
	 * Whether this PHP can enforce all the TLS settings. A seam for tests.
	 */
	protected function curl_supported(): bool {
		return function_exists( 'curl_setopt' ) && defined( 'CURLOPT_SSLCERT_BLOB' ) && defined( 'CURLOPT_SSLKEY_BLOB' ) && defined( 'CURLOPT_CAINFO_BLOB' ) && defined( 'CURLOPT_PINNEDPUBLICKEY' );
	}

	/**
	 * An agent text as one short line without secrets.
	 *
	 * @param string   $text  Text.
	 * @param string[] $scrub Values to remove.
	 */
	private function clean( string $text, array $scrub ): string {
		$scrub = array_filter( $scrub, static fn( $s ) => strlen( (string) $s ) >= 4 );
		usort( $scrub, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		return mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( $scrub, '***', wp_check_invalid_utf8( $text, true ) ) ) ) ), 0, 300 );
	}
}

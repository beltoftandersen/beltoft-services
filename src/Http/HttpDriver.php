<?php
/**
 * The HTTP API driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Http;

use BeltoftServices\Api\Routes;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\Opens;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a resource's lifecycle as signed calls to a service's HTTP API, configured as a
 * connection (Connections). The tenant of every call is the resource id (its uuid).
 *
 * Every operation sends `Idempotency-Key: <uuid>:<operation>:<seq>`. The sequence is stored
 * before the request goes out and only goes up when the operation starts afresh: retries,
 * and an admin retry after the retries ran out, send the same key, so a service that did
 * the work already can answer it again instead of doing it twice. A request that got a final
 * answer (success or a refusal) is finished, and the next run of that operation gets the next
 * number.
 *
 * Answers: 2xx is done (provision also needs the remote id, read from the connection's
 * `remote_id_field`); 409 on provision with a remote id is done (without one it fails and the
 * provision stays open); 404 on delete is done (already
 * gone); 408, 429, 5xx and transport
 * errors are retried (a Retry-After of up to a day is accepted; the Reconciler never waits less
 * than its backoff step nor more than 14400 seconds); anything else fails. Redirects are not followed.
 */
final class HttpDriver implements Driver, Opens {

	/**
	 * Error when a provision answer carries no remote id.
	 */
	public const ERROR_NO_ID = 'Provision answered without an id';

	/**
	 * Characters of a response body kept in an error.
	 */
	private const SNIPPET = 200;

	/**
	 * Longest Retry-After honoured, in seconds.
	 */
	private const MAX_RETRY_AFTER = DAY_IN_SECONDS;

	/**
	 * Seconds the last usage answer is kept for the account panel.
	 */
	private const USAGE_TTL = HOUR_IN_SECONDS;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'http';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'HTTP API';
	}

	/**
	 * {@inheritDoc}
	 */
	public function kind(): string {
		return 'http';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $used Used.
	 * @param int $max  Allowed.
	 */
	public function unit_label( int $used, int $max ): string {
		/* translators: 1: units used, 2: units allowed */
		$default = __( '%1$d of %2$d', 'beltoft-services' );

		/**
		 * Filters the usage line format of HTTP services, a sprintf() format that gets the
		 * used and the allowed count, for example "%1$d of %2$d dashboards".
		 *
		 * @param string $format Format.
		 * @param int    $used   Used.
		 * @param int    $max    Allowed.
		 */
		$format = (string) apply_filters( 'beltoft_services_http_unit_label', $default, $used, $max );

		return sprintf( $format, $used, $max );
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan_fields(): array {
		return array(
			'limit'      => array(
				'number',
				__( 'Limit', 'beltoft-services' ),
				__( 'How many units the service allows, sent as {limit}. Empty: the license site limit.', 'beltoft-services' ),
				'',
				true,
			),
			'attributes' => array(
				'textarea',
				__( 'Plan attributes', 'beltoft-services' ),
				__( 'One key=value per line, sent in {plan} together with the limit.', 'beltoft-services' ),
				'',
				true,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function provision( Resource $r ): StepResult {
		return $this->operate( $r, 'provision' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function pause( Resource $r ): StepResult {
		return $this->operate( $r, 'pause' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function resume( Resource $r ): StepResult {
		return $this->operate( $r, 'resume' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function update_plan( Resource $r ): StepResult {
		return $this->operate( $r, 'update_plan' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function delete( Resource $r ): StepResult {
		return $this->operate( $r, 'delete' );
	}

	/**
	 * Usage from the `usage` endpoint: `{used, limit}`, or null when there is no usage endpoint
	 * or no usable answer. The limit is read from `usage_limit_field`, or is the plan's limit
	 * when that field is not set. The last answer is kept for an hour for the account panel.
	 *
	 * @param Resource $r Resource.
	 * @return array{used: int, limit: int}|null
	 */
	public function status( Resource $r ): ?array {
		$connection = $this->connection( $r );

		if ( ! $connection || '' === (string) $connection['endpoints']['usage']['path'] || '' === (string) $connection['secret'] ) {
			return null;
		}

		$license = $r->license();
		$values  = $this->values( $r, $license );
		$method  = (string) $connection['endpoints']['usage']['method'];
		$url     = $this->url( $connection, (string) $connection['endpoints']['usage']['path'], $values );
		$sent    = $this->send( $connection, $method, $url, '', $r->get( 'uuid' ), '' );

		if ( is_wp_error( $sent['response'] ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $sent['response'] );
		$data = json_decode( (string) wp_remote_retrieve_body( $sent['response'] ), true );

		if ( $code < 200 || $code > 299 || ! is_array( $data ) ) {
			return null;
		}

		$used  = self::field( $data, (string) $connection['usage_used_field'] );
		$limit = '' === (string) $connection['usage_limit_field'] ? $values['limit'] : self::field( $data, (string) $connection['usage_limit_field'] );

		if ( ! is_numeric( $used ) || ! is_numeric( $limit ) ) {
			return null;
		}

		$usage = array(
			'used'  => (int) $used,
			'limit' => (int) $limit,
		);

		set_transient( self::usage_key( $r ), $usage, self::USAGE_TTL );

		return $usage;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function customer_actions( Resource $r ): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::fail( 'This service has no customer actions' );
	}

	/**
	 * The state, the limit, the last known usage and, when the connection has an open URL and
	 * the resource is ready, an "Open" button. The button links to the open route, which makes
	 * the five-minute sign-in token when it is clicked. No request is made while rendering.
	 *
	 * @param Resource $r Resource.
	 */
	public function render_account( Resource $r ): void {
		$state   = $r->get( 'state' );
		$license = $r->license();
		$limit   = $this->limit( $r, $license );
		$usage   = get_transient( self::usage_key( $r ) );

		echo '<div class="beltoft-services-http">';
		echo '<p class="beltoft-services-http-state"><strong>' . esc_html__( 'Status:', 'beltoft-services' ) . '</strong> ' . esc_html( self::state_label( $state ) ) . '</p>';

		echo '<p class="beltoft-services-http-limit"><strong>' . esc_html__( 'Limit:', 'beltoft-services' ) . '</strong> ' . esc_html( (string) $limit ) . '</p>';

		if ( is_array( $usage ) && isset( $usage['used'], $usage['limit'] ) ) {
			echo '<p class="beltoft-services-http-usage"><strong>' . esc_html__( 'Usage:', 'beltoft-services' ) . '</strong> ' . esc_html( $this->unit_label( (int) $usage['used'], (int) $usage['limit'] ) ) . '</p>';
		}

		$connection = $this->connection( $r );

		if ( 'ready' === $state && self::can_open( $connection ) ) {
			// The token is made when the button is clicked (Routes::open), never in the page.
			$url   = Routes::open_url( $r->id() );
			$label = '' !== (string) $connection['open_label'] ? (string) $connection['open_label'] : __( 'Open', 'beltoft-services' );

			echo '<p><a class="button woocommerce-button beltoft-services-http-open" href="' . esc_url( $url ) . '" data-beltoft-services-open target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * The connection's open URL with a five-minute sign-in token made now, or null when the
	 * connection has no open URL or secret. No request is made.
	 *
	 * @param Resource $r Resource.
	 */
	public function open_url( Resource $r ): ?string {
		$connection = $this->connection( $r );

		if ( ! self::can_open( $connection ) ) {
			return null;
		}

		$license = $r->license();
		$token   = Signer::open_token( $connection, $r->get( 'uuid' ), (string) ( $license['email'] ?? '' ), (string) ( $license['license_key'] ?? '' ) );

		return str_replace( '{token}', rawurlencode( $token ), (string) $connection['open_url'] );
	}

	/**
	 * The host of the connection's configured open URL, or null.
	 *
	 * @param Resource $r Resource.
	 */
	public function open_host( Resource $r ): ?string {
		$connection = $this->connection( $r );
		$host       = $connection ? wp_parse_url( (string) $connection['open_url'], PHP_URL_HOST ) : null;

		return is_string( $host ) && '' !== $host ? strtolower( $host ) : null;
	}

	/**
	 * Whether a connection can sign customers in: it has an open URL and a secret.
	 *
	 * @param array<string, mixed>|null $connection Connection, secret decrypted.
	 */
	private static function can_open( ?array $connection ): bool {
		return $connection && '' !== (string) $connection['open_url'] && '' !== (string) $connection['secret'];
	}

	/**
	 * The connection, remote id, last request and the status of its answer.
	 *
	 * @param Resource $r Resource.
	 */
	public function render_admin( Resource $r ): void {
		$status = $r->get( 'last_status' );
		$at     = (int) $r->get( 'last_request_at' );
		$rows   = array(
			__( 'Connection', 'beltoft-services' )   => $this->connection_id( $r ),
			__( 'Remote id', 'beltoft-services' )    => $r->get( 'remote_id' ),
			__( 'Last request', 'beltoft-services' ) => (string) $r->get( 'last_request' ) . ( $at ? ' (' . wp_date( 'Y-m-d H:i:s', $at ) . ')' : '' ),
			__( 'Last answer', 'beltoft-services' )  => null === $status ? '' : ( 0 === (int) $status ? __( 'No answer', 'beltoft-services' ) : 'HTTP ' . (int) $status ),
		);

		echo '<table class="widefat striped beltoft-services-http-admin"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === (string) $value ? '—' : (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Whether a request goes through wp_safe_remote_request(). The one exception is the
	 * connection's own base URL host when that host is a private or loopback IP literal (an
	 * internal service the admin configured, such as 10.10.10.2:8100), which the safe request
	 * would refuse: then wp_remote_request() is used, for that exact host only.
	 *
	 * @param array<string, mixed> $connection Connection.
	 * @param string               $url        Request URL.
	 */
	public static function uses_safe_request( array $connection, string $url ): bool {
		$base = strtolower( (string) wp_parse_url( (string) ( $connection['base_url'] ?? '' ), PHP_URL_HOST ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$bare = trim( $host, '[]' );

		if ( '' === $host || $host !== $base || false === filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		return ! Connections::is_private_host( $bare );
	}

	/**
	 * Runs one operation: builds, signs and sends its request and reads the answer.
	 *
	 * @param Resource $r         Resource.
	 * @param string   $operation provision, pause, resume, update_plan or delete.
	 */
	private function operate( Resource $r, string $operation ): StepResult {
		$connection_id = $this->connection_id( $r );
		$connection    = $this->connection( $r );

		if ( ! $connection ) {
			return StepResult::fail( '' === $connection_id ? 'No connection is set for this service' : sprintf( 'Connection "%s" not found', $connection_id ) );
		}

		if ( '' === (string) $connection['secret'] ) {
			return StepResult::fail( sprintf( 'Connection "%s" has no usable secret', $connection_id ) );
		}

		$endpoint = $connection['endpoints'][ $operation ];
		$path     = (string) $endpoint['path'];

		if ( '' === $path ) {
			// Only resuming can be assumed: a service with nothing to resume is running.
			// Creating, pausing, deleting or a plan change that is never sent would leave the
			// service out of step without anyone knowing.
			return 'resume' === $operation
				? StepResult::done( array( 'connection' => $connection_id ) )
				: StepResult::fail( sprintf( 'No %s endpoint is configured', $operation ) );
		}

		$idempotency = is_array( $r->get( 'idempotency' ) ) ? $r->get( 'idempotency' ) : array();

		if ( 'provision' !== $operation && '' === $r->get( 'remote_id' ) && false !== strpos( $path, '{remote_id}' ) ) {
			return $this->without_remote_id( $operation, $idempotency );
		}

		$license = $r->license();
		$values  = $this->values( $r, $license );
		$method  = (string) $endpoint['method'];
		$url     = $this->url( $connection, $path, $values );
		$body    = 'GET' === $method ? '' : $this->body( (string) $connection['body_template'], $values );

		if ( null === $body ) {
			return StepResult::fail( 'The body template does not give valid JSON' );
		}

		// Store the sequence before the request goes out, so a crash cannot change the key. A
		// retry whose body changed (a newer plan) is another request and gets a new key, or the
		// service would replay its answer to the old body; provision keeps its key whatever
		// changed, so the tenant is never created twice.
		$entry = is_array( $idempotency[ $operation ] ?? null ) ? $idempotency[ $operation ] : array();
		$seq   = (int) ( $entry['seq'] ?? 0 );
		$hash  = hash( 'sha256', $body );
		$fresh = empty( $entry['open'] ) || ( 'provision' !== $operation && ( $entry['body'] ?? '' ) !== $hash );

		if ( $fresh ) {
			++$seq;
			$idempotency[ $operation ] = array(
				'seq'  => $seq,
				'open' => true,
				'body' => $hash,
			);

			if ( ! $r->set(
				array(
					'idempotency' => $idempotency,
					'connection'  => $connection_id,
				)
			) ) {
				return StepResult::retry( 0, 'The request sequence could not be stored' );
			}
		}

		$key  = $r->get( 'uuid' ) . ':' . $operation . ':' . $seq;
		$sent = $this->send( $connection, $method, $url, $body, $r->get( 'uuid' ), $key );
		$line = $method . ' ' . $sent['path'];

		$closed               = $idempotency;
		$closed[ $operation ] = array(
			'seq'  => $seq,
			'open' => false,
			'body' => $hash,
		);
		$meta                 = array(
			'last_request'    => $line,
			'last_request_at' => time(),
			'connection'      => $connection_id,
		);
		$response             = $sent['response'];
		$scrub                = array( (string) $connection['secret'], $sent['signature'], $body );

		// The license key and the customer's email are in the body and the path, and a service
		// may echo them back in an error.
		foreach ( array( (string) $values['license_key'], (string) $values['email'] ) as $private ) {
			$scrub[] = $private;
			$scrub[] = rawurlencode( $private );
			$scrub[] = urlencode( $private );
		}


		if ( is_wp_error( $response ) ) {
			$meta['last_status'] = 0;

			return StepResult::retry( 0, $line . ': ' . self::scrub( $response->get_error_message(), $scrub ), $meta );
		}

		$code                = (int) wp_remote_retrieve_response_code( $response );
		$text                = (string) wp_remote_retrieve_body( $response );
		$meta['last_status'] = $code;
		$prefix              = $line . ': HTTP ' . $code . ': ';

		// Deleting what the service no longer has is done.
		if ( ( $code >= 200 && $code <= 299 ) || ( 409 === $code && 'provision' === $operation ) || ( 404 === $code && 'delete' === $operation ) ) {
			if ( 'provision' !== $operation ) {
				return StepResult::done( array_merge( $meta, array( 'idempotency' => $closed ) ) );
			}

			$data      = json_decode( $text, true, 512, JSON_BIGINT_AS_STRING );
			$remote_id = is_array( $data ) ? self::field( $data, (string) $connection['remote_id_field'] ) : null;

			if ( is_scalar( $remote_id ) && ! is_bool( $remote_id ) && '' !== (string) $remote_id ) {
				$steps = $r->get( 'steps' );

				if ( ! in_array( 'provision', $steps, true ) ) {
					$steps[] = 'provision';
				}

				return StepResult::done(
					array_merge(
						$meta,
						array(
							'idempotency' => $closed,
							'remote_id'   => (string) $remote_id,
							'steps'       => $steps,
						)
					)
				);
			}

			if ( 409 === $code ) {
				// The service holds the tenant but did not say its id: the provision stays open, so a
				// later pause or delete needs an admin instead of counting as done.
				return StepResult::fail( $prefix . self::snippet( $text, $scrub ), $meta );
			}

			// The service may have created it: retry with the same key to get the id.
			return StepResult::retry( 0, $prefix . self::ERROR_NO_ID . ( is_array( $data ) ? '' : ' (the answer is not JSON)' ), $meta );
		}

		if ( 408 === $code || 429 === $code || $code >= 500 ) {
			return StepResult::retry( self::retry_after( $response ), $prefix . self::snippet( $text, $scrub ), $meta );
		}

		return StepResult::fail( $prefix . self::snippet( $text, $scrub ), array_merge( $meta, array( 'idempotency' => $closed ) ) );
	}

	/**
	 * The answer for an operation whose path needs a remote id the resource does not have.
	 * Never provisioned, or the provision got a final refusal: there is nothing to pause or
	 * delete. A provision was sent but its answer never came: the service may hold something,
	 * so an admin decides.
	 *
	 * @param string               $operation   Operation.
	 * @param array<string, mixed> $idempotency Stored sequences.
	 */
	private function without_remote_id( string $operation, array $idempotency ): StepResult {
		$provision = is_array( $idempotency['provision'] ?? null ) ? $idempotency['provision'] : array();

		if ( in_array( $operation, array( 'pause', 'delete' ), true ) && empty( $provision['open'] ) ) {
			return StepResult::done();
		}

		return StepResult::fail( sprintf( 'Cannot %s: the resource has no remote id', str_replace( '_', ' ', $operation ) ) );
	}

	/**
	 * Signs and sends a request.
	 *
	 * @param array<string, mixed> $connection Connection.
	 * @param string               $method     Method.
	 * @param string               $url        URL.
	 * @param string               $body       Body ('' for none).
	 * @param string               $tenant     Tenant id.
	 * @param string               $key        Idempotency key ('' for none).
	 * @return array{response: array<string, mixed>|\WP_Error, path: string, signature: string}
	 */
	private function send( array $connection, string $method, string $url, string $body, string $tenant, string $key ): array {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$path .= '' === $query ? '' : '?' . $query;

		$auth    = Signer::headers( $connection, $method, $path, $body, $tenant );
		$headers = array_merge(
			array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			'' === $key ? array() : array( 'Idempotency-Key' => $key ),
			$auth
		);
		$args    = array(
			'method'      => $method,
			'timeout'     => (int) $connection['timeout'],
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
		);

		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = self::uses_safe_request( $connection, $url ) ? wp_safe_remote_request( $url, $args ) : wp_remote_request( $url, $args );

		return array(
			'response'  => $response,
			'path'      => $path,
			'signature' => (string) ( $auth[ (string) $connection['header_signature'] ] ?? '' ),
		);
	}

	/**
	 * The connection id: the one the resource was first run with, else its product's.
	 *
	 * @param Resource $r Resource.
	 */
	private function connection_id( Resource $r ): string {
		$stored = $r->get( 'connection' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$license = $r->license();

		return Product::connection( (int) ( $license['product_id'] ?? 0 ) );
	}

	/**
	 * The resource's connection, secret decrypted, or null.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	private function connection( Resource $r ): ?array {
		$id = $this->connection_id( $r );

		if ( '' === $id ) {
			return null;
		}

		/**
		 * Filters an HTTP connection by id. Connections hooks it to return the stored one with
		 * its secret decrypted; another plugin may supply connections of its own.
		 *
		 * @param array<string, mixed>|null $connection Connection, or null when unknown.
		 * @param string                    $id         Connection id.
		 */
		$connection = apply_filters( 'beltoft_services_connection', null, $id );

		if ( ! is_array( $connection ) ) {
			return null;
		}

		$connection = array_merge( Connections::defaults(), $connection );

		foreach ( Connections::ENDPOINTS as $name ) {
			$connection['endpoints'][ $name ] = array_merge(
				array(
					'method' => 'POST',
					'path'   => '',
				),
				is_array( $connection['endpoints'][ $name ] ?? null ) ? $connection['endpoints'][ $name ] : array()
			);
		}

		return $connection;
	}

	/**
	 * The placeholder values of a resource.
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $license License row.
	 * @return array<string, mixed>
	 */
	private function values( Resource $r, array $license ): array {
		$limit = $this->limit( $r, $license );

		return array(
			'resource_id' => $r->get( 'uuid' ),
			'remote_id'   => $r->get( 'remote_id' ),
			'license_key' => (string) ( $license['license_key'] ?? '' ),
			'customer_id' => (int) ( $license['user_id'] ?? 0 ),
			'email'       => (string) ( $license['email'] ?? '' ),
			'limit'       => $limit,
			'plan'        => array_merge( self::attributes( $r->get( 'plan' ) ), array( 'limit' => $limit ) ),
		);
	}

	/**
	 * The plan's limit, or the license's site limit when the plan has none.
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $license License row.
	 */
	private function limit( Resource $r, array $license ): int {
		$plan  = $r->get( 'plan' );
		$limit = isset( $plan['limit'] ) && is_numeric( $plan['limit'] ) ? (int) $plan['limit'] : 0;

		return $limit > 0 ? $limit : (int) ( $license['max_activations'] ?? 0 );
	}

	/**
	 * The plan's `key=value` attribute lines as an array. Lines without "=" are skipped.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, string>
	 */
	private static function attributes( array $plan ): array {
		$attributes = array();
		$lines      = preg_split( '/\r\n|\r|\n/', is_scalar( $plan['attributes'] ?? null ) ? (string) $plan['attributes'] : '' );

		foreach ( (array) $lines as $line ) {
			$pair = explode( '=', (string) $line, 2 );

			if ( 2 === count( $pair ) && '' !== trim( $pair[0] ) ) {
				$attributes[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		return $attributes;
	}

	/**
	 * The request URL: the base URL and the path with each placeholder URL-encoded.
	 *
	 * @param array<string, mixed> $connection Connection.
	 * @param string               $path       Path template.
	 * @param array<string, mixed> $values     Values.
	 */
	private function url( array $connection, string $path, array $values ): string {
		$filled = preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) use ( $values ) {
				return in_array( $m[1], Connections::PATH_PLACEHOLDERS, true ) ? rawurlencode( (string) $values[ $m[1] ] ) : $m[0];
			},
			$path
		);

		return untrailingslashit( (string) $connection['base_url'] ) . $filled;
	}

	/**
	 * The request body from the template, or every value as a JSON object when there is no
	 * template. Placeholders become JSON values, never pasted text: a placeholder that is a
	 * whole JSON string ("{plan}") or stands outside a string ({limit}) becomes the value in
	 * its own JSON type ({plan} an object, {limit} and {customer_id} numbers, the rest
	 * strings); one inside a longer string becomes escaped text. Null when the result is not
	 * valid JSON.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $values   Values.
	 */
	private function body( string $template, array $values ): ?string {
		if ( '' === trim( $template ) ) {
			return self::json( $values );
		}

		$out    = '';
		$length = strlen( $template );
		$i      = 0;

		while ( $i < $length ) {
			$char = $template[ $i ];

			if ( '"' === $char ) {
				$end = $i + 1;

				while ( $end < $length && '"' !== $template[ $end ] ) {
					$end += '\\' === $template[ $end ] ? 2 : 1;
				}

				$literal = substr( $template, $i, $end - $i + 1 );
				$inner   = substr( $template, $i + 1, $end - $i - 1 );

				if ( preg_match( '/^\{([a-z_]+)\}$/', $inner, $m ) && array_key_exists( $m[1], $values ) ) {
					$out .= self::json( $values[ $m[1] ] );
				} else {
					$out .= preg_replace_callback(
						'/\{([a-z_]+)\}/',
						static function ( $m ) use ( $values ) {
							if ( ! array_key_exists( $m[1], $values ) ) {
								return $m[0];
							}

							$text = is_array( $values[ $m[1] ] ) ? self::json( $values[ $m[1] ] ) : (string) $values[ $m[1] ];

							return substr( self::json( $text ), 1, -1 );
						},
						$literal
					);
				}

				$i = $end + 1;
				continue;
			}

			if ( '{' === $char && preg_match( '/\G\{([a-z_]+)\}/', $template, $m, 0, $i ) && array_key_exists( $m[1], $values ) ) {
				$out .= self::json( $values[ $m[1] ] );
				$i   += strlen( $m[0] );
				continue;
			}

			$out .= $char;
			++$i;
		}

		json_decode( $out );

		return JSON_ERROR_NONE === json_last_error() ? $out : null;
	}

	/**
	 * JSON for a value; arrays with string keys (and the plan) are objects.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		if ( is_array( $value ) && ( ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
			$value = (object) $value;
		}

		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * A value at a dot path such as data.id, or null.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @param string               $path Dot path.
	 * @return mixed
	 */
	private static function field( array $data, string $path ) {
		if ( '' === $path ) {
			return null;
		}

		$value = $data;

		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}

			$value = $value[ $part ];
		}

		return $value;
	}

	/**
	 * Seconds from a Retry-After header (seconds or an HTTP date), at most a day; 0 if none.
	 *
	 * @param array<string, mixed> $response Response.
	 */
	private static function retry_after( array $response ): int {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		$header = is_array( $header ) ? (string) reset( $header ) : trim( (string) $header );

		if ( '' === $header ) {
			return 0;
		}

		if ( ctype_digit( $header ) ) {
			$seconds = (int) $header;
		} else {
			$time    = strtotime( $header );
			$seconds = false === $time ? 0 : $time - time();
		}

		return min( self::MAX_RETRY_AFTER, max( 0, $seconds ) );
	}

	/**
	 * At most the first 200 characters of a response body, as one line of text, with the
	 * secret, the signature, the request body, the license key and the customer email removed first.
	 *
	 * @param string   $text  Response body.
	 * @param string[] $scrub Values to remove.
	 */
	private static function snippet( string $text, array $scrub ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::scrub( $text, $scrub ) ) ) );

		return '' === $text ? 'empty answer' : mb_substr( $text, 0, self::SNIPPET );
	}

	/**
	 * Replaces each value in a text (the longest first) with "***".
	 *
	 * @param string   $text  Text.
	 * @param string[] $scrub Values.
	 */
	private static function scrub( string $text, array $scrub ): string {
		$scrub = array_filter( $scrub, static fn( $value ) => strlen( (string) $value ) >= 4 );
		usort( $scrub, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		return str_replace( $scrub, '***', wp_check_invalid_utf8( $text, true ) );
	}

	/**
	 * Cache key of the last usage answer.
	 *
	 * @param Resource $r Resource.
	 */
	private static function usage_key( Resource $r ): string {
		return 'beltoft_services_http_usage_' . $r->id();
	}

	/**
	 * A state for customers.
	 *
	 * @param string $state State.
	 */
	private static function state_label( string $state ): string {
		$labels = array(
			'requested'    => __( 'Requested', 'beltoft-services' ),
			'provisioning' => __( 'Being set up', 'beltoft-services' ),
			'ready'        => __( 'Ready', 'beltoft-services' ),
			'paused'       => __( 'Paused', 'beltoft-services' ),
			'deleting'     => __( 'Being removed', 'beltoft-services' ),
			'deleted'      => __( 'Removed', 'beltoft-services' ),
			'failed'       => __( 'Needs attention', 'beltoft-services' ),
		);

		return $labels[ $state ] ?? $state;
	}
}

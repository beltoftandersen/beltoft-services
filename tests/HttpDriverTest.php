<?php
/**
 * The HTTP driver: signed, idempotent, retried calls.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Http\Connections;
use BeltoftServices\Http\HttpDriver;
use BeltoftServices\Http\Signer;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

/**
 * HttpDriver tests. No real request is ever made: the recorder answers every call.
 */
class HttpDriverTest extends TestCase {

	private const SECRET = 'S3cret-Value-Never-Shown';

	/**
	 * Mails caught by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Connections::OPTION );

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Connections::OPTION );

		parent::tear_down();
	}

	/**
	 * Records a mail instead of sending it.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $atts Mail arguments.
	 * @return bool
	 */
	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Saves the connection 'svc'.
	 *
	 * @param array<string, mixed> $over Overrides of the form input.
	 */
	private function connection( array $over = array() ): void {
		$input = array_merge(
			array(
				'label'             => 'Service',
				'base_url'          => 'https://api.example.com/v1',
				'auth'              => 'hmac',
				'secret'            => self::SECRET,
				'endpoints'         => array(
					'provision'   => array( 'method' => 'POST', 'path' => '/tenants' ),
					'pause'       => array( 'method' => 'POST', 'path' => '/tenants/{resource_id}/pause' ),
					'resume'      => array( 'method' => 'POST', 'path' => '/tenants/{resource_id}/resume' ),
					'update_plan' => array( 'method' => 'PUT', 'path' => '/tenants/{remote_id}/plan' ),
					'delete'      => array( 'method' => 'DELETE', 'path' => '/tenants/{remote_id}' ),
					'usage'       => array( 'method' => 'GET', 'path' => '/tenants/{resource_id}/usage' ),
				),
				'body_template'     => '{"tenant":"{resource_id}","email":"{email}","key":"{license_key}","customer":{customer_id},"limit":{limit},"plan":{plan},"note":"for {email}"}',
				'remote_id_field'   => 'data.id',
				'usage_used_field'  => 'used',
				'usage_limit_field' => 'limit',
				'open_url'          => 'https://app.example.com/open?t={token}',
				'open_label'        => 'Open app',
			),
			$over
		);

		$this->assertTrue( Connections::save( 'svc', $input ) );
	}

	/**
	 * A resource of an HTTP service product on connection 'svc'.
	 *
	 * @param array<string, mixed> $plan    Product plan values.
	 * @param array<string, mixed> $license License columns.
	 * @return Resource
	 */
	private function resource( array $plan = array( 'limit' => 5 ), array $license = array() ): Resource {
		$product = $this->service_product( 'http', $plan, array( 'connection' => 'svc', 'max' => 3 ) );
		$row     = $this->service_license( $product, array_merge( array( 'fire' => false ), $license ) );

		$resource = Resource::create( $row, $this->driver(), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	/**
	 * The registered HTTP driver.
	 */
	private function driver(): HttpDriver {
		$driver = Registry::get( 'http' );
		$this->assertInstanceOf( HttpDriver::class, $driver );

		return $driver;
	}

	/**
	 * Runs one reconcile step now (any retry wait skipped) and returns the fresh resource.
	 *
	 * @param Resource $r Resource.
	 */
	private function step( Resource $r ): Resource {
		$r->set( array( 'next_attempt_at' => null ) );
		Reconciler::step( Resource::find( $r->id() ) );

		return Resource::find( $r->id() );
	}

	/**
	 * An HTTP response for the recorder.
	 *
	 * @param int                  $code    Status.
	 * @param mixed                $body    Body (arrays are JSON-encoded).
	 * @param array<string, mixed> $headers Headers.
	 * @return array<string, mixed>
	 */
	private static function answer( int $code, $body = '', array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : (string) $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Answers each request with the next of $answers; the last one repeats.
	 *
	 * @param array<int, mixed> $answers Responses or WP_Errors.
	 */
	private function answers( array $answers ): void {
		$this->respond_with(
			static function () use ( &$answers ) {
				return count( $answers ) > 1 ? array_shift( $answers ) : $answers[0];
			}
		);
	}

	/**
	 * Asserts that a recorded request carries a valid signature for its tenant.
	 *
	 * @param array{url: string, args: array<string, mixed>} $call   Recorded request.
	 * @param string                                          $tenant Expected tenant.
	 */
	private function assert_signed( array $call, string $tenant ): void {
		$headers = $call['args']['headers'];
		$path    = (string) wp_parse_url( $call['url'], PHP_URL_PATH );
		$query   = (string) wp_parse_url( $call['url'], PHP_URL_QUERY );
		$path   .= '' === $query ? '' : '?' . $query;

		$this->assertSame( $tenant, $headers['X-Beltoft-Tenant'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', $headers['X-Beltoft-Timestamp'] );
		$this->assertLessThanOrEqual( 5, abs( time() - (int) $headers['X-Beltoft-Timestamp'] ) );
		$this->assertSame(
			Signer::signature( self::SECRET, $call['args']['method'], $path, $headers['X-Beltoft-Timestamp'], $tenant, (string) ( $call['args']['body'] ?? '' ) ),
			$headers['X-Beltoft-Signature']
		);
	}

	public function test_identity_unit_label_and_plan_fields(): void {
		$driver = $this->driver();

		$this->assertSame( 'http', $driver->id() );
		$this->assertSame( 'http', $driver->kind() );
		$this->assertSame( 'HTTP API', $driver->label() );
		$this->assertSame( '2 of 5', $driver->unit_label( 2, 5 ) );

		add_filter(
			'beltoft_services_http_unit_label',
			static function ( $format, $used, $max ) {
				return 5 === $max ? '%1$d / %2$d dashboards' : $format;
			},
			10,
			3
		);
		$this->assertSame( '2 / 5 dashboards', $driver->unit_label( 2, 5 ) );

		$fields = $driver->plan_fields();
		$this->assertSame( array( 'limit', 'attributes' ), array_keys( $fields ) );
		$this->assertSame( 'number', $fields['limit'][0] );
		$this->assertSame( '', $fields['limit'][3] );
		$this->assertTrue( $fields['limit'][4] );
		$this->assertSame( 'textarea', $fields['attributes'][0] );
		$this->assertTrue( $fields['attributes'][4] );

		$this->connection();
		$this->assertSame( array(), $driver->customer_actions( $this->resource() ) );
	}

	public function test_provision_stores_remote_id_and_sends_limit_and_plan(): void {
		$this->connection();
		$resource = $this->resource(
			array(
				'limit'      => 5,
				'attributes' => "region=eu\nbrand_sync = yes\n\nnot a pair",
			)
		);
		$license  = $resource->license();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'rem-42' ) ) ) ) );

		$fresh = $this->step( $resource );

		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'rem-42', $fresh->get( 'remote_id' ) );

		$calls = $this->http_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://api.example.com/v1/tenants', $calls[0]['url'] );
		$this->assertSame( 'POST', $calls[0]['args']['method'] );
		$this->assertSame( 10, $calls[0]['args']['timeout'] );
		$this->assertSame( 0, $calls[0]['args']['redirection'] );
		$this->assertSame( 'application/json', $calls[0]['args']['headers']['Content-Type'] );
		$this->assertSame( $resource->get( 'uuid' ) . ':provision:1', $calls[0]['args']['headers']['Idempotency-Key'] );
		$this->assert_signed( $calls[0], $resource->get( 'uuid' ) );

		$this->assertSame(
			array(
				'tenant'   => $resource->get( 'uuid' ),
				'email'    => 'customer@example.com',
				'key'      => $license['license_key'],
				'customer' => (int) $license['user_id'],
				'limit'    => 5,
				'plan'     => array(
					'region'     => 'eu',
					'brand_sync' => 'yes',
					'limit'      => 5,
				),
				'note'     => 'for customer@example.com',
			),
			json_decode( $calls[0]['args']['body'], true )
		);

		// An empty limit is the license's site limit.
		$other = $this->resource( array() );
		$this->step( $other );
		$body = json_decode( $this->http_calls()[1]['args']['body'], true );
		$this->assertSame( 3, $body['limit'] );
		$this->assertSame( array( 'limit' => 3 ), $body['plan'] );
	}

	public function test_update_plan_and_delete_use_the_remote_id(): void {
		$this->connection();
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'rem/1' ) ) ), self::answer( 204 ) ) );
		$this->step( $resource );

		$resource->set(
			array(
				'plan'       => array(
					'limit'      => 9,
					'attributes' => '',
				),
				'plan_dirty' => true,
			)
		);
		$fresh = $this->step( $resource );

		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertFalse( $fresh->get( 'plan_dirty' ) );
		$call = $this->http_calls()[1];
		$this->assertSame( 'https://api.example.com/v1/tenants/rem%2F1/plan', $call['url'] );
		$this->assertSame( 'PUT', $call['args']['method'] );
		$this->assertSame( 9, json_decode( $call['args']['body'], true )['limit'] );
		$this->assertSame( $resource->get( 'uuid' ) . ':update_plan:1', $call['args']['headers']['Idempotency-Key'] );

		$fresh->desire( 'deleted', 'Test' );
		$fresh = $this->step( $fresh );

		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$call = $this->http_calls()[2];
		$this->assertSame( 'DELETE', $call['args']['method'] );
		$this->assertSame( 'https://api.example.com/v1/tenants/rem%2F1', $call['url'] );
		$this->assertSame( $resource->get( 'uuid' ) . ':delete:1', $call['args']['headers']['Idempotency-Key'] );
	}

	public function test_placeholders_are_encoded_not_pasted(): void {
		global $wpdb;

		$this->connection(
			array(
				'endpoints'     => array(
					'provision' => array( 'method' => 'POST', 'path' => '/tenants/{email}/{license_key}' ),
				),
				'body_template' => '{"email":"{email}","note":"for {email} \\"quoted\\"","plan":"{plan}","limit":"{limit}","nested":[{"e":{email}}]}',
			)
		);
		$resource = $this->resource();
		$odd      = "a\"b/c\\d\ne@example.com";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test: a value no form would let through.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'email' => $odd ), array( 'id' => $resource->license()['id'] ) );
		$resource->set(
			array(
				'plan' => array(
					'limit'      => 2,
					'attributes' => "q=\"x\" / y\\z\nk={email}",
				),
			)
		);
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r' ) ) ) ) );

		$this->step( $resource );

		$call = $this->http_calls()[0];
		$this->assertSame( 'https://api.example.com/v1/tenants/a%22b%2Fc%5Cd%0Ae%40example.com/' . rawurlencode( $resource->license()['license_key'] ), $call['url'] );

		$body = json_decode( $call['args']['body'], true );
		$this->assertIsArray( $body, 'The body is valid JSON.' );
		$this->assertSame(
			array(
				'email'  => $odd,
				'note'   => 'for ' . $odd . ' "quoted"',
				'plan'   => array(
					'q'     => '"x" / y\\z',
					'k'     => '{email}',
					'limit' => 2,
				),
				'limit'  => 2,
				'nested' => array( array( 'e' => $odd ) ),
			),
			$body
		);
	}

	public function test_without_a_body_template_the_values_are_sent_as_json(): void {
		$this->connection( array( 'body_template' => '' ) );
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r' ) ) ) ) );

		$this->step( $resource );

		$body = json_decode( $this->http_calls()[0]['args']['body'], true );
		$this->assertSame( array( 'resource_id', 'remote_id', 'license_key', 'customer_id', 'email', 'limit', 'plan' ), array_keys( $body ) );
		$this->assertSame( $resource->get( 'uuid' ), $body['resource_id'] );
		$this->assertSame( 5, $body['limit'] );
	}

	public function test_medusa_provision_is_idempotent(): void {
		$this->connection();
		$resource = $this->resource();
		$uuid     = $resource->get( 'uuid' );
		$this->answers(
			array(
				new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
				self::answer( 503, 'busy' ),
				self::answer( 409, array( 'data' => array( 'id' => 'rem-7' ) ) ),
				self::answer( 204 ),
			)
		);

		$this->assertSame( 'provisioning', $this->step( $resource )->get( 'state' ) );
		$this->assertSame( 'provisioning', $this->step( $resource )->get( 'state' ) );
		$fresh = $this->step( $resource );

		// 409 with the id: the service already has this tenant.
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'rem-7', $fresh->get( 'remote_id' ) );

		$keys = array_map( static fn( $call ) => $call['args']['headers']['Idempotency-Key'], $this->http_calls() );
		$this->assertSame( array_fill( 0, 3, $uuid . ':provision:1' ), $keys, 'Every retry sends the same key.' );

		// New operations count on their own; a repeated operation gets the next number.
		$fresh->desire( 'paused', 'Test' );
		$fresh = $this->step( $fresh );
		$fresh->desire( 'ready', 'Test' );
		$fresh = $this->step( $fresh );
		$fresh->desire( 'paused', 'Test' );
		$fresh = $this->step( $fresh );

		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$keys = array_map( static fn( $call ) => $call['args']['headers']['Idempotency-Key'], array_slice( $this->http_calls(), 3 ) );
		$this->assertSame( array( $uuid . ':pause:1', $uuid . ':resume:1', $uuid . ':pause:2' ), $keys );

		// 409 without an id is a plain conflict: failed.
		$other = $this->resource();
		$this->answers( array( self::answer( 409, array( 'error' => 'conflict' ) ) ) );
		$this->assertSame( 'failed', $this->step( $other )->get( 'state' ) );
	}

	public function test_medusa_requests_are_signed_not_a_global_header_plus_customer_id(): void {
		$this->connection();
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'rem-1' ) ) ), self::answer( 200, '{}' ) ) );

		$fresh = $this->step( $resource );
		$fresh->desire( 'paused', 'Test' );
		$fresh = $this->step( $fresh );
		$fresh->desire( 'ready', 'Test' );
		$fresh = $this->step( $fresh );
		$this->driver()->status( $fresh );

		$calls = $this->http_calls();
		$this->assertCount( 4, $calls );

		$user_id = (string) $resource->license()['user_id'];

		foreach ( $calls as $call ) {
			$this->assert_signed( $call, $resource->get( 'uuid' ) );
			$this->assertTrue( $call['args']['reject_unsafe_urls'], 'A public host goes through wp_safe_remote_request.' );

			foreach ( $call['args']['headers'] as $name => $value ) {
				$this->assertStringNotContainsStringIgnoringCase( 'customer', $name );
				$this->assertStringNotContainsString( self::SECRET, (string) $value, 'The secret is never sent.' );
				$this->assertNotSame( $user_id, (string) $value, 'No customer id header.' );
			}

			$this->assertStringNotContainsString( self::SECRET, (string) ( $call['args']['body'] ?? '' ) );
		}

		// Usage is a read: signed, no body, no idempotency key.
		$this->assertSame( 'GET', $calls[3]['args']['method'] );
		$this->assertSame( '', (string) ( $calls[3]['args']['body'] ?? '' ) );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $calls[3]['args']['headers'] );
	}

	public function test_header_auth_sends_the_secret_in_its_header_only(): void {
		$this->connection(
			array(
				'auth'          => 'header',
				'header_static' => 'X-Api-Key',
			)
		);
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'rem-1' ) ) ) ) );

		$this->step( $resource );

		$headers = $this->http_calls()[0]['args']['headers'];
		$this->assertSame( self::SECRET, $headers['X-Api-Key'] );
		$this->assertSame( $resource->get( 'uuid' ), $headers['X-Beltoft-Tenant'] );
		$this->assertArrayNotHasKey( 'X-Beltoft-Signature', $headers );
	}

	public function test_medusa_timeouts_and_errors_retry_not_vanish(): void {
		$this->connection();
		$driver = $this->driver();

		$cases = array(
			'transport' => array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), 'Operation timed out', 0 ),
			'408'       => array( self::answer( 408, 'slow' ), 'HTTP 408', 0 ),
			'429'       => array( self::answer( 429, 'later', array( 'retry-after' => '120' ) ), 'HTTP 429', 120 ),
			'500'       => array( self::answer( 500, 'oops' ), 'HTTP 500: oops', 0 ),
			'503 date'  => array( self::answer( 503, 'down', array( 'retry-after' => gmdate( 'D, d M Y H:i:s', time() + 600 ) . ' GMT' ) ), 'HTTP 503', 600 ),
		);

		foreach ( $cases as $name => list( $response, $error, $delay ) ) {
			$resource = $this->resource();
			$this->answers( array( $response ) );

			$result = $driver->provision( $resource );

			$this->assertTrue( $result->is_retry(), $name );
			$this->assertStringStartsWith( 'POST /v1/tenants: ', $result->error(), $name );
			$this->assertStringContainsString( $error, $result->error(), $name );
			$this->assertEqualsWithDelta( $delay, $result->delay(), 2, $name );
		}

		// Through the reconciler: retried with backoff, then failed and alerted, never lost.
		$resource = $this->resource();
		$this->answers( array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ) );
		$before = count( $this->http_calls() );

		for ( $i = 0; $i < count( Reconciler::BACKOFF ); $i++ ) {
			$fresh = $this->step( $resource );
			$this->assertSame( 'provisioning', $fresh->get( 'state' ) );
			$this->assertSame( $i + 1, $fresh->get( 'attempts' ) );
			$this->assertNotEmpty( Jobs::pending( $resource->id() ), 'A retry is queued.' );
		}

		$fresh = $this->step( $resource );
		$this->assertSame( 'failed', $fresh->get( 'state' ) );
		$this->assertStringContainsString( 'Operation timed out', $fresh->get( 'last_error' ) );
		$this->assertSame( count( Reconciler::BACKOFF ) + 1, count( $this->http_calls() ) - $before );
		$this->assertCount( 1, $this->mails );

		$keys = array_unique( array_map( static fn( $call ) => $call['args']['headers']['Idempotency-Key'], array_slice( $this->http_calls(), $before ) ) );
		$this->assertSame( array( $resource->get( 'uuid' ) . ':provision:1' ), array_values( $keys ) );

		// An admin retry after retries ran out keeps the key: the service may have done it.
		$fresh->set( array( 'attempts' => 0 ) );
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'late' ) ) ) ) );
		$this->assertSame( 'ready', $this->step( $fresh )->get( 'state' ) );
		$this->assertSame( $resource->get( 'uuid' ) . ':provision:1', end( $this->http_calls )['args']['headers']['Idempotency-Key'] );
	}

	public function test_200_without_id_never_makes_ready(): void {
		$this->connection();
		$resource = $this->resource();
		$this->answers( array( self::answer( 200, array( 'data' => array( 'name' => 'x' ) ) ) ) );

		for ( $i = 0; $i < count( Reconciler::BACKOFF ); $i++ ) {
			$fresh = $this->step( $resource );
			$this->assertSame( 'provisioning', $fresh->get( 'state' ) );
			$this->assertSame( '', $fresh->get( 'remote_id' ) );
			$this->assertStringContainsString( 'Provision answered without an id', $fresh->get( 'last_error' ) );
		}

		$fresh = $this->step( $resource );
		$this->assertSame( 'failed', $fresh->get( 'state' ) );
		$this->assertSame( 'POST /v1/tenants: HTTP 200: Provision answered without an id', $fresh->get( 'last_error' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Provision answered without an id', $this->mails[0]['message'] );

		// An empty or non-scalar id is no id either.
		foreach ( array( array( 'data' => array( 'id' => '' ) ), array( 'data' => array( 'id' => array( 1 ) ) ), array( 'data' => 'x' ) ) as $body ) {
			$this->answers( array( self::answer( 201, $body ) ) );
			$this->assertTrue( $this->driver()->provision( $this->resource() )->is_retry() );
		}
	}

	public function test_non_json_answer_retries(): void {
		$this->connection();
		$resource = $this->resource();
		$this->answers( array( self::answer( 200, '<html><body>Bad gateway page</body></html>' ) ) );

		$fresh = $this->step( $resource );

		$this->assertSame( 'provisioning', $fresh->get( 'state' ) );
		$this->assertSame( 1, $fresh->get( 'attempts' ) );
		$this->assertSame( 'POST /v1/tenants: HTTP 200: Provision answered without an id (the answer is not JSON)', $fresh->get( 'last_error' ) );

		// Other operations need no answer body.
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r' ) ) ), self::answer( 204, '' ) ) );
		$fresh = $this->step( $fresh );
		$fresh->desire( 'paused', 'Test' );
		$this->assertSame( 'paused', $this->step( $fresh )->get( 'state' ) );
	}

	public function test_4xx_fails_with_status_and_no_secret_in_error(): void {
		$this->connection();
		$resource = $this->resource();

		$this->respond_with(
			static function ( $url, $args ) {
				$echo = 'Bad request. You sent ' . self::SECRET . ' signed ' . $args['headers']['X-Beltoft-Signature'] . ' with ' . $args['body'] . ' ' . str_repeat( 'x', 500 );

				return self::answer( 422, $echo );
			}
		);

		$fresh = $this->step( $resource );
		$error = $fresh->get( 'last_error' );

		$this->assertSame( 'failed', $fresh->get( 'state' ) );
		$this->assertSame( 1, count( $this->http_calls() ), 'No retry of a 4xx.' );
		$this->assertStringStartsWith( 'POST /v1/tenants: HTTP 422: Bad request. You sent', $error );
		$this->assertStringNotContainsString( self::SECRET, $error );
		$this->assertStringNotContainsString( $this->http_calls()[0]['args']['headers']['X-Beltoft-Signature'], $error );
		$this->assertStringNotContainsString( $this->http_calls()[0]['args']['body'], $error );
		$this->assertLessThanOrEqual( 200, strlen( substr( $error, strlen( 'POST /v1/tenants: HTTP 422: ' ) ) ) );

		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( self::SECRET, $this->mails[0]['message'] );

		ob_start();
		$this->driver()->render_admin( $fresh );
		$admin = (string) ob_get_clean();
		$this->assertStringNotContainsString( self::SECRET, $admin );
		$this->assertStringContainsString( 'POST /v1/tenants', $admin );
		$this->assertStringContainsString( '422', $admin );

		// A failed request is finished: an admin retry starts it afresh with the next key.
		$fresh->set( array( 'attempts' => 0 ) );
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r' ) ) ) ) );
		$this->assertSame( 'ready', $this->step( $fresh )->get( 'state' ) );
		$this->assertSame( $resource->get( 'uuid' ) . ':provision:2', end( $this->http_calls )['args']['headers']['Idempotency-Key'] );

		// A redirect is not followed and not success either.
		$other = $this->resource();
		$this->answers( array( self::answer( 302, '', array( 'location' => 'https://elsewhere.example.com/' ) ) ) );
		$this->assertSame( 'failed', $this->step( $other )->get( 'state' ) );
	}

	public function test_medusa_one_tenant_per_license(): void {
		$this->connection();
		$user = self::factory()->user->create();
		$one  = $this->resource( array( 'limit' => 5 ), array( 'user_id' => $user ) );
		$two  = $this->resource( array( 'limit' => 5 ), array( 'user_id' => $user ) );

		$this->assertNotSame( $one->license()['id'], $two->license()['id'] );
		$this->assertNotSame( $one->get( 'uuid' ), $two->get( 'uuid' ) );

		$this->respond_with(
			static function ( $url, $args ) {
				return self::answer( 201, array( 'data' => array( 'id' => 'rem-' . $args['headers']['X-Beltoft-Tenant'] ) ) );
			}
		);
		$one = $this->step( $one );
		$two = $this->step( $two );

		$this->assertSame( 'rem-' . $one->get( 'uuid' ), $one->get( 'remote_id' ) );
		$this->assertSame( 'rem-' . $two->get( 'uuid' ), $two->get( 'remote_id' ) );

		$before = count( $this->http_calls() );
		$one->desire( 'paused', 'Cancelled' );
		$one = $this->step( $one );

		$calls = array_slice( $this->http_calls(), $before );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://api.example.com/v1/tenants/' . $one->get( 'uuid' ) . '/pause', $calls[0]['url'] );
		$this->assertSame( $one->get( 'uuid' ), $calls[0]['args']['headers']['X-Beltoft-Tenant'] );
		$this->assertSame( 'paused', $one->get( 'state' ) );
		$this->assertSame( 'ready', Resource::find( $two->id() )->get( 'state' ) );
	}

	public function test_request_transport_is_safe_except_for_the_private_service_host(): void {
		$this->connection( array( 'base_url' => 'http://10.10.10.2:8100/api' ) );
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r' ) ) ) ) );

		$this->step( $resource );

		$call = $this->http_calls()[0];
		$this->assertSame( 'http://10.10.10.2:8100/api/tenants', $call['url'] );
		$this->assertFalse( $call['args']['reject_unsafe_urls'], 'The private service host goes through wp_remote_request.' );
		$this->assertSame( 0, $call['args']['redirection'] );

		$private = array( 'base_url' => 'http://10.10.10.2:8100/api' );
		$this->assertFalse( HttpDriver::uses_safe_request( $private, 'http://10.10.10.2:8100/api/tenants' ) );
		$this->assertFalse( HttpDriver::uses_safe_request( array( 'base_url' => 'http://[::1]:9000' ), 'http://[::1]:9000/x' ) );
		$this->assertTrue( HttpDriver::uses_safe_request( $private, 'http://10.10.10.3:8100/api/tenants' ), 'Only that exact host.' );
		$this->assertTrue( HttpDriver::uses_safe_request( array( 'base_url' => 'http://localhost:8100' ), 'http://localhost:8100/x' ), 'Not an IP literal.' );
		$this->assertTrue( HttpDriver::uses_safe_request( array( 'base_url' => 'https://8.8.8.8' ), 'https://8.8.8.8/x' ), 'Not private.' );
		$this->assertTrue( HttpDriver::uses_safe_request( array( 'base_url' => 'https://api.example.com' ), 'https://api.example.com/x' ) );
	}

	public function test_missing_connection_or_endpoint_fails_without_a_request(): void {
		$product  = $this->service_product( 'http', array(), array( 'connection' => 'nope' ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, $this->driver(), Product::plan( $product->get_id() ) );

		$result = $this->driver()->provision( $resource );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'Connection "nope" not found', $result->error() );
		$this->assertSame( array(), $this->http_calls() );

		$this->connection( array( 'endpoints' => array( 'provision' => array( 'method' => 'POST', 'path' => '/t' ) ) ) );
		$resource = $this->resource();
		$resource->set( array( 'remote_id' => 'r' ) );

		// Only resuming has nothing to tell the service; a pause or a plan change cannot be assumed.
		$this->assertTrue( $this->driver()->resume( $resource )->is_done() );
		$result = $this->driver()->pause( $resource );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'No pause endpoint is configured', $result->error() );
		$result = $this->driver()->update_plan( $resource );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'No update_plan endpoint is configured', $result->error() );
		// Deleting cannot be assumed.
		$result = $this->driver()->delete( $resource );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'No delete endpoint is configured', $result->error() );
		$this->assertSame( array(), $this->http_calls() );
	}

	public function test_missing_remote_id_in_a_path(): void {
		$this->connection();
		$resource = $this->resource();

		// Never provisioned: nothing to delete.
		$this->assertTrue( $this->driver()->delete( $resource )->is_done() );

		// Provision sent but its answer never came: the id is unknown, so the admin decides.
		$this->answers( array( self::answer( 500, 'x' ) ) );
		$this->driver()->provision( $resource );
		$result = $this->driver()->delete( Resource::find( $resource->id() ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'remote id', $result->error() );
		$this->assertTrue( $this->driver()->update_plan( Resource::find( $resource->id() ) )->is_failed() );
		$this->assertCount( 1, $this->http_calls() );

		// A provision the service refused for good created nothing: nothing to delete.
		$refused = $this->resource();
		$this->answers( array( self::answer( 422, 'no' ) ) );
		$this->assertSame( 'failed', $this->step( $refused )->get( 'state' ) );
		$this->assertTrue( $this->driver()->delete( Resource::find( $refused->id() ) )->is_done() );
		$this->assertCount( 2, $this->http_calls() );
	}

	public function test_large_remote_ids_are_kept_exactly(): void {
		$this->connection();
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, '{"data":{"id":123456789012345678901234567890}}' ) ) );

		$this->assertSame( '123456789012345678901234567890', $this->step( $resource )->get( 'remote_id' ) );
	}

	public function test_delete_answered_404_is_already_gone(): void {
		$this->connection();
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r' ) ) ), self::answer( 404, 'Not found' ) ) );
		$fresh = $this->step( $resource );

		$fresh->desire( 'deleted', 'Test' );
		$this->assertSame( 'deleted', $this->step( $fresh )->get( 'state' ) );

		// A 404 to anything else is still a failure.
		$other = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r2' ) ) ), self::answer( 404, 'Not found' ) ) );
		$other = $this->step( $other );
		$other->desire( 'paused', 'Test' );
		$this->assertSame( 'failed', $this->step( $other )->get( 'state' ) );
	}

	public function test_a_changed_body_gets_a_new_key(): void {
		$this->connection();
		$resource = $this->resource();
		$uuid     = $resource->get( 'uuid' );
		$timeout  = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'r' ) ) ) ) );
		$fresh = $this->step( $resource );

		$this->answers( array( $timeout ) );
		$fresh->set( array( 'plan' => array( 'limit' => 7, 'attributes' => '' ), 'plan_dirty' => true ) );
		$this->assertTrue( $this->driver()->update_plan( Resource::find( $fresh->id() ) )->is_retry() );
		$this->assertTrue( $this->driver()->update_plan( Resource::find( $fresh->id() ) )->is_retry() );

		// Plan B while plan A's request is still open: a new key, so B is never answered with A's reply.
		$fresh->set( array( 'plan' => array( 'limit' => 9, 'attributes' => '' ) ) );
		$this->assertTrue( $this->driver()->update_plan( Resource::find( $fresh->id() ) )->is_retry() );
		$this->assertTrue( $this->driver()->update_plan( Resource::find( $fresh->id() ) )->is_retry() );

		$keys = array_map( static fn( $call ) => $call['args']['headers']['Idempotency-Key'], array_slice( $this->http_calls(), 1 ) );
		$this->assertSame( array( $uuid . ':update_plan:1', $uuid . ':update_plan:1', $uuid . ':update_plan:2', $uuid . ':update_plan:2' ), $keys );
		$this->assertSame( 9, json_decode( end( $this->http_calls )['args']['body'], true )['limit'] );

		// Provision keeps its key even when the body changed: the tenant must not be made twice.
		$other = $this->resource();
		$this->answers( array( $timeout ) );
		$this->driver()->provision( $other );
		$other->set( array( 'plan' => array( 'limit' => 4, 'attributes' => 'x=y' ) ) );
		$this->driver()->provision( Resource::find( $other->id() ) );

		$keys = array_map( static fn( $call ) => $call['args']['headers']['Idempotency-Key'], array_slice( $this->http_calls(), -2 ) );
		$this->assertSame( array( $other->get( 'uuid' ) . ':provision:1', $other->get( 'uuid' ) . ':provision:1' ), $keys );
	}

	public function test_status_reads_usage(): void {
		$this->connection();
		$resource = $this->resource();
		$resource->set( array( 'state' => 'ready' ) );

		$this->answers( array( self::answer( 200, array( 'used' => 2, 'limit' => 7 ) ) ) );
		$this->assertSame( array( 'used' => 2, 'limit' => 7 ), $this->driver()->status( $resource ) );
		$call = $this->http_calls()[0];
		$this->assertSame( 'https://api.example.com/v1/tenants/' . $resource->get( 'uuid' ) . '/usage', $call['url'] );
		$this->assertSame( 'GET', $call['args']['method'] );

		// Without a limit field: the plan's limit.
		$this->connection( array( 'usage_limit_field' => '' ) );
		$this->answers( array( self::answer( 200, array( 'used' => '4' ) ) ) );
		$this->assertSame( array( 'used' => 4, 'limit' => 5 ), $this->driver()->status( $resource ) );

		foreach ( array( self::answer( 500, 'x' ), self::answer( 200, 'nope' ), self::answer( 200, array( 'used' => 'many' ) ), new \WP_Error( 'x', 'down' ) ) as $answer ) {
			$this->answers( array( $answer ) );
			$this->assertNull( $this->driver()->status( $resource ) );
		}

		// No usage endpoint: no request.
		$this->connection(
			array(
				'endpoints' => array( 'provision' => array( 'method' => 'POST', 'path' => '/t' ) ),
			)
		);
		$count = count( $this->http_calls() );
		$this->assertNull( $this->driver()->status( $resource ) );
		$this->assertCount( $count, $this->http_calls() );
	}

	public function test_account_shows_state_limit_usage_and_open_button(): void {
		$this->connection();
		$resource = $this->resource();
		$license  = $resource->license();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'rem-1' ) ) ), self::answer( 200, array( 'used' => 2, 'limit' => 5 ) ) ) );
		$fresh = $this->step( $resource );
		$this->driver()->status( $fresh );
		$count = count( $this->http_calls() );

		ob_start();
		$this->driver()->render_account( $fresh );
		$html = (string) ob_get_clean();

		$this->assertCount( $count, $this->http_calls(), 'Rendering makes no request.' );
		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringContainsString( '2 of 5', $html );
		$this->assertStringContainsString( 'Open app', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );

		$this->assertSame( 1, preg_match( '#href="https://app\.example\.com/open\?t=([^"]+)"#', $html, $m ) );
		$payload = Signer::verify_open_token( Connections::get( 'svc' ), html_entity_decode( $m[1] ) );
		$this->assertIsArray( $payload );
		$this->assertSame( $fresh->get( 'uuid' ), $payload['tenant'] );
		$this->assertSame( 'customer@example.com', $payload['email'] );
		$this->assertSame( $license['license_key'], $payload['license_key'] );
		$this->assertEqualsWithDelta( time() + 300, $payload['exp'], 5 );

		// Not ready: no button.
		$fresh->set( array( 'state' => 'paused' ) );
		ob_start();
		$this->driver()->render_account( $fresh );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Paused', $html );
		$this->assertStringNotContainsString( 'Open app', $html );

		// No open URL: no button.
		$this->connection( array( 'open_url' => '' ) );
		$fresh->set( array( 'state' => 'ready' ) );
		ob_start();
		$this->driver()->render_account( $fresh );
		$this->assertStringNotContainsString( 'open?t=', (string) ob_get_clean() );
	}

	public function test_admin_panel_shows_remote_id_and_last_request(): void {
		$this->connection();
		$resource = $this->resource();
		$this->answers( array( self::answer( 201, array( 'data' => array( 'id' => 'rem-<b>1</b>' ) ) ) ) );
		$fresh = $this->step( $resource );

		ob_start();
		$this->driver()->render_admin( $fresh );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'rem-&lt;b&gt;1&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'POST /v1/tenants', $html );
		$this->assertStringContainsString( '201', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
	}
}

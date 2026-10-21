<?php
/**
 * The customer REST routes and the jobs they queue.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Api\Routes;
use BeltoftServices\Drivers\Opens;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Http\Connections;
use BeltoftServices\Http\HttpDriver;
use BeltoftServices\Http\Signer;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Routes tests.
 */
final class RoutesTest extends TestCase {

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Mail catcher.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $atts Mail.
	 * @return bool
	 */
	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * A fake-driver resource owned by a new customer.
	 *
	 * @param array<string, mixed> $meta    Resource meta.
	 * @param array<string, mixed> $license License columns.
	 * @return array{0: Resource, 1: FakeDriver, 2: int}
	 */
	private function resource( array $meta = array( 'state' => 'ready' ), array $license = array() ): array {
		$driver = $this->fake_driver();

		$driver->customer_actions = array( 'restart' => 'Restart' );

		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$row      = $this->service_license( $product, array_merge( array( 'fire' => false ), $license ) );
		$resource = Resource::create( $row, $driver, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return array( $resource, $driver, (int) $row['user_id'] );
	}

	/**
	 * Dispatches a request as a user.
	 *
	 * @param string               $method  Method.
	 * @param string               $route   Route below the namespace.
	 * @param int                  $user_id User (0 for a visitor).
	 * @param array<string, mixed> $body    JSON body.
	 */
	private function request( string $method, string $route, int $user_id, array $body = array() ): \WP_REST_Response {
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( $method, '/beltoft-services/v1' . $route );

		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Pending actions of a hook.
	 *
	 * @param string $hook Hook.
	 * @return array<int, \ActionScheduler_Action>
	 */
	private function queued( string $hook ): array {
		return as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => Jobs::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/beltoft-services/v1/resources/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( '/beltoft-services/v1/resources/(?P<id>\d+)/actions/(?P<action>[a-z0-9_-]+)', $routes );
		$this->assertArrayHasKey( '/beltoft-services/v1/resources/(?P<id>\d+)/open', $routes );
	}

	public function test_status_route_for_owner(): void {
		list( $resource, $driver, $user ) = $this->resource( array( 'state' => 'provisioning' ) );

		$response = $this->request( 'GET', '/resources/' . $resource->id(), $user );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'provisioning', $data['state'] );
		$this->assertSame( 'ready', $data['desired'] );
		$this->assertSame( 'Getting ready', $data['label'] );
		$this->assertNotSame( '', $data['message'] );
		$this->assertSame( array( 'state', 'desired', 'label', 'message' ), array_keys( $data ) );
		$this->assertSame( array(), $driver->calls(), 'A REST request never calls the driver.' );
	}

	public function test_visitors_get_401(): void {
		list( $resource ) = $this->resource();

		$this->assertSame( 401, $this->request( 'GET', '/resources/' . $resource->id(), 0 )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', 0 )->get_status() );
		$this->assertSame( array(), $this->queued( Jobs::ACT ) );
	}

	public function test_other_users_get_403_and_nothing_is_queued(): void {
		list( $resource, $driver ) = $this->resource();
		$other                     = self::factory()->user->create();

		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id(), $other )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', $other )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id() . '/open', $other )->get_status() );

		// A guest license with the same email is not enough: users only.
		list( $guest ) = $this->resource( array( 'state' => 'ready' ), array( 'user_id' => null ) );
		$same_email    = self::factory()->user->create( array( 'user_email' => 'customer@example.com' ) );
		$this->assertSame( 403, $this->request( 'POST', '/resources/' . $guest->id() . '/actions/restart', $same_email )->get_status() );

		// An id that is no resource at all.
		$this->assertSame( 403, $this->request( 'GET', '/resources/999999', $other )->get_status() );

		$this->assertSame( array(), $this->queued( Jobs::ACT ) );
		$this->assertSame( array(), $this->queued( Jobs::USAGE ) );
		$this->assertSame( array(), $driver->calls() );
	}

	public function test_paused_resource_refuses_actions(): void {
		list( $resource, $driver, $user ) = $this->resource( array( 'state' => 'paused', 'desired' => 'paused' ) );

		$this->assertSame( 403, $this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', $user )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id(), $user )->get_status() );

		// Ready, but on its way to paused.
		$resource->set( array( 'state' => 'ready' ) );
		$this->assertSame( 403, $this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', $user )->get_status() );

		// Not ready yet.
		$resource->set( array( 'state' => 'provisioning', 'desired' => 'ready' ) );
		$this->assertSame( 403, $this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', $user )->get_status() );

		// Ready on a license that is no longer active.
		$resource->set( array( 'state' => 'ready' ) );
		Licenses::update( (int) $resource->license()['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		$this->assertSame( 403, $this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', $user )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id(), $user )->get_status() );

		$this->assertSame( array(), $this->queued( Jobs::ACT ) );
		$this->assertSame( array(), $driver->calls() );
	}

	public function test_action_is_queued_and_run_by_the_job(): void {
		list( $resource, $driver, $user ) = $this->resource();

		$response = $this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', $user, array( 'args' => array( 'mode' => 'soft' ) ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array(), $driver->calls(), 'The request only queues.' );

		$queued = array_values( $this->queued( Jobs::ACT ) );
		$this->assertCount( 1, $queued );
		$this->assertSame( array( $resource->id(), 'restart', array( 'mode' => 'soft' ) ), $queued[0]->get_args() );

		// The same request again while it waits is not queued twice.
		$this->request( 'POST', '/resources/' . $resource->id() . '/actions/restart', $user, array( 'args' => array( 'mode' => 'soft' ) ) );
		$this->assertCount( 1, $this->queued( Jobs::ACT ) );

		$this->run_jobs();

		$calls = $driver->calls( 'act' );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'restart', array( 'mode' => 'soft' ) ), $calls[0]['args'] );
	}

	public function test_unknown_action_is_refused(): void {
		list( $resource, $driver, $user ) = $this->resource();

		$this->assertSame( 403, $this->request( 'POST', '/resources/' . $resource->id() . '/actions/reboot', $user )->get_status() );
		$this->assertSame( array(), $this->queued( Jobs::ACT ) );
		$this->assertSame( array(), $driver->calls() );
	}

	public function test_rate_limit(): void {
		list( $resource, , $user ) = $this->resource();
		$route                     = '/resources/' . $resource->id();

		// The status route allows 20 a minute: polling every 10 s never reaches it.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 200, $this->request( 'GET', $route, $user )->get_status(), 'Request ' . ( $i + 1 ) );
		}

		$over = $this->request( 'GET', $route, $user );
		$this->assertSame( 429, $over->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $over->get_headers() );

		// Another route of the same user has its own count; the action route allows 10.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 202, $this->request( 'POST', $route . '/actions/restart', $user )->get_status(), 'Action ' . ( $i + 1 ) );
		}
		$this->assertSame( 429, $this->request( 'POST', $route . '/actions/restart', $user )->get_status() );

		// The same route for another user has its own count.
		list( $second, , $other ) = $this->resource();
		$this->assertSame( 200, $this->request( 'GET', '/resources/' . $second->id(), $other )->get_status() );
	}

	public function test_routes_share_their_counter_with_the_public_limiter(): void {
		list( $resource, , $user ) = $this->resource();
		$route                     = '/beltoft-services/v1/resources/' . $resource->id();

		// The status route allows 20 a minute; use them up through the public API.
		for ( $i = 0; $i < Routes::STATUS_RATE_LIMIT; $i++ ) {
			$this->assertFalse( \BeltoftServices\RateLimit::exceeded( $user, $route, Routes::STATUS_RATE_LIMIT, Routes::WINDOW ) );
		}

		$this->assertSame( 429, $this->request( 'GET', '/resources/' . $resource->id(), $user )->get_status() );
	}

	public function test_rate_limit_is_filterable(): void {
		list( $resource, , $user ) = $this->resource();

		add_filter(
			'beltoft_services_rest_rate_limit',
			static function () {
				return 2;
			}
		);

		$this->request( 'GET', '/resources/' . $resource->id(), $user );
		$this->request( 'GET', '/resources/' . $resource->id(), $user );
		$this->assertSame( 429, $this->request( 'GET', '/resources/' . $resource->id(), $user )->get_status() );
	}

	public function test_message_never_contains_last_error(): void {
		list( $resource, , $user ) = $this->resource(
			array(
				'state'      => 'failed',
				'last_error' => 'HTTP 500 from https://internal.example/tenants: secret-detail',
				'failed_op'  => 'provision',
			)
		);

		$response = $this->request( 'GET', '/resources/' . $resource->id(), $user );
		$this->assertSame( 200, $response->get_status() );

		$json = (string) wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( 'secret-detail', $json );
		$this->assertStringNotContainsString( 'internal.example', $json );
		$this->assertSame( 'Failed: contact us', $response->get_data()['label'] );

		// Nor the panel.
		ob_start();
		do_action( 'beltoft_licenses_account_license', $resource->license(), \BeltoftLicenses\Account\View::license( $resource->license() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Failed: contact us', $html );
		$this->assertStringNotContainsString( 'secret-detail', $html );
	}

	public function test_status_queues_one_usage_refresh_that_runs_outside_the_request(): void {
		list( $resource, $driver, $user ) = $this->resource();

		$this->request( 'GET', '/resources/' . $resource->id(), $user );
		$this->request( 'GET', '/resources/' . $resource->id(), $user );

		$this->assertSame( array(), $driver->calls( 'status' ), 'No driver call during the request.' );
		$queued = array_values( $this->queued( Jobs::USAGE ) );
		$this->assertCount( 1, $queued, 'Deduplicated.' );
		$this->assertSame( array( $resource->id() ), $queued[0]->get_args() );

		$this->run_jobs();
		$this->assertCount( 1, $driver->calls( 'status' ) );

		// Refreshed a moment ago: not queued again yet.
		$this->request( 'GET', '/resources/' . $resource->id(), $user );
		$this->assertSame( array(), $this->queued( Jobs::USAGE ) );
	}

	public function test_usage_refresh_waits_for_the_kill_switch_and_skips_resources_not_ready(): void {
		list( $resource, $driver ) = $this->resource();

		$this->settings( array( 'pause_all' => 1 ) );
		Jobs::refresh_usage( $resource->id() );
		$this->assertSame( array(), $driver->calls() );

		$this->settings( array( 'pause_all' => 0 ) );
		$resource->set( array( 'state' => 'paused' ) );
		Jobs::refresh_usage( $resource->id() );
		$this->assertSame( array(), $driver->calls() );

		$resource->set( array( 'state' => 'ready' ) );
		Jobs::refresh_usage( $resource->id() );
		$this->assertCount( 1, $driver->calls( 'status' ) );
	}

	public function test_act_stores_meta_and_writes_an_event(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'act', StepResult::done( array( 'restarted' => 3 ), 'Service restarted' ) );

		Jobs::act( $resource->id(), 'restart', array() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 3, $fresh->get( 'restarted' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		$events = wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $resource->license()['id'] ), 'message' );
		$this->assertContains( 'Service restarted', $events );
	}

	public function test_act_cannot_overwrite_reconciler_meta(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'act', StepResult::done( array( 'state' => 'deleted', 'desired' => 'deleted' ) ) );

		Jobs::act( $resource->id(), 'restart', array() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
	}

	public function test_failed_act_alerts_and_the_service_stays_ready(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'act', StepResult::fail( 'Restart refused' ) );

		Jobs::act( $resource->id(), 'restart', array() );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Restart refused', $this->mails[0]['message'] );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );

		$events = implode( "\n", wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $resource->license()['id'] ), 'message' ) );
		$this->assertStringContainsString( 'restart', $events );
	}

	public function test_act_retry_is_rescheduled_then_fails_after_the_backoff(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'act', StepResult::retry( 10, 'Busy' ) );

		$before = time();
		Jobs::act( $resource->id(), 'restart', array( 'a' => 'b' ) );

		$queued = array_values( $this->queued( Jobs::ACT ) );
		$this->assertCount( 1, $queued );
		$this->assertSame( array( $resource->id(), 'restart', array( 'a' => 'b' ) ), $queued[0]->get_args() );
		$this->assertGreaterThanOrEqual( $before + 60, $queued[0]->get_schedule()->get_date()->getTimestamp() );
		$this->assertSame( array(), $this->mails );

		as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );

		for ( $i = 1; $i <= 5; $i++ ) {
			Jobs::act( $resource->id(), 'restart', array( 'a' => 'b' ) );
			as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );
		}

		$this->assertCount( 6, $driver->calls( 'act' ) );
		$this->assertCount( 1, $this->mails, 'Failed after the last backoff step.' );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );
	}

	public function test_act_waits_while_the_kill_switch_is_on(): void {
		list( $resource, $driver ) = $this->resource();
		$this->settings( array( 'pause_all' => 1 ) );

		$before = time();
		Jobs::act( $resource->id(), 'restart', array() );

		$this->assertSame( array(), $driver->calls() );
		$queued = array_values( $this->queued( Jobs::ACT ) );
		$this->assertCount( 1, $queued );
		$this->assertEqualsWithDelta( $before + Jobs::PAUSED_DELAY, $queued[0]->get_schedule()->get_date()->getTimestamp(), 2 );
	}

	public function test_act_reschedules_when_the_lock_is_busy(): void {
		list( $resource, $driver ) = $this->resource();

		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		global $wpdb;
		$held = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		$before = time();
		Jobs::act( $resource->id(), 'restart', array() );
		$other->close();

		$this->assertSame( array(), $driver->calls() );
		$queued = array_values( $this->queued( Jobs::ACT ) );
		$this->assertCount( 1, $queued );
		$this->assertEqualsWithDelta( $before + Jobs::LOCKED_DELAY, $queued[0]->get_schedule()->get_date()->getTimestamp(), 2 );
	}

	public function test_act_is_dropped_when_the_resource_is_no_longer_ready(): void {
		list( $resource, $driver ) = $this->resource();
		$resource->set( array( 'desired' => 'paused' ) );

		Jobs::act( $resource->id(), 'restart', array() );

		$this->assertSame( array(), $driver->calls() );
		$this->assertSame( array(), $this->queued( Jobs::ACT ) );
	}

	/**
	 * Saves an HTTP connection 'svc' with an open URL.
	 */
	private function connection(): void {
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a test key.
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->assertTrue(
			Connections::save(
				'svc',
				array(
					'label'      => 'Service',
					'base_url'   => 'https://api.example.com/v1',
					'auth'       => 'hmac',
					'secret'     => 'open-secret-123456',
					'endpoints'  => array(
						'provision' => array(
							'method' => 'POST',
							'path'   => '/tenants',
						),
					),
					'open_url'   => 'https://app.example.com/open?t={token}',
					'open_label' => 'Open app',
				)
			)
		);
	}

	public function test_open_route_mints_the_token_at_click_time_and_redirects(): void {
		$this->connection();
		$product  = $this->service_product( 'http', array( 'limit' => 5 ), array( 'connection' => 'svc' ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, new HttpDriver(), Product::plan( $product->get_id() ), array( 'state' => 'ready' ) );
		$user     = (int) $license['user_id'];

		// The rendered page carries no token, only the route.
		wp_set_current_user( $user );
		ob_start();
		( new HttpDriver() )->render_account( $resource );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'app.example.com', $html );
		$this->assertStringContainsString( esc_url( Routes::open_url( $resource->id() ) ), $html );
		$this->assertStringContainsString( '_wpnonce=', Routes::open_url( $resource->id() ) );

		$response = $this->request( 'GET', '/resources/' . $resource->id() . '/open', $user );

		$this->assertSame( 302, $response->get_status() );
		$location = $response->get_headers()['Location'];
		$this->assertSame( 1, preg_match( '#^https://app\.example\.com/open\?t=(.+)$#', $location, $m ) );
		$payload = Signer::verify_open_token( Connections::get( 'svc' ), rawurldecode( $m[1] ) );
		$this->assertIsArray( $payload );
		$this->assertSame( $resource->get( 'uuid' ), $payload['tenant'] );
		$this->assertSame( array(), $this->http_calls(), 'No request to the service.' );

		// Not ready: refused.
		$resource->set( array( 'state' => 'provisioning' ) );
		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id() . '/open', $user )->get_status() );
	}

	public function test_open_route_refuses_a_driver_without_an_open_url(): void {
		list( $resource, , $user ) = $this->resource();

		$this->assertSame( 404, $this->request( 'GET', '/resources/' . $resource->id() . '/open', $user )->get_status() );
	}

	public function test_act_retry_alerts_when_its_bookkeeping_cannot_be_stored(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'act', StepResult::retry( 10, 'Busy' ) );

		$table = \BeltoftLicenses\Install::tables()['activations'];
		$break = static function ( $query ) use ( $table ) {
			return 0 === strpos( ltrim( $query ), 'UPDATE `' . $table . '`' ) ? '' : $query;
		};

		add_filter( 'query', $break );
		Jobs::act( $resource->id(), 'restart', array() );
		remove_filter( 'query', $break );

		$this->assertCount( 1, $driver->calls( 'act' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Resource could not be updated', $this->mails[0]['message'] );
		$this->assertNotFalse( get_transient( 'beltoft_services_not_stored_' . $resource->id() ) );
		$this->assertSame( array(), $this->queued( Jobs::ACT ), 'A write that fails never loops.' );
	}

	/**
	 * Registers a fake driver that can be opened, answering the given URL and host.
	 *
	 * @param string      $url  What open_url() answers.
	 * @param string|null $host What open_host() answers.
	 * @return FakeDriver
	 */
	private function opener( string $url, ?string $host ): FakeDriver {
		$driver = new class( 'fake' ) extends FakeDriver implements Opens {
			/**
			 * Answer of open_url().
			 *
			 * @var string
			 */
			public $url = '';

			/**
			 * Answer of open_host().
			 *
			 * @var string|null
			 */
			public $host = null;

			public function open_url( Resource $r ): ?string {
				return $this->url;
			}

			public function open_host( Resource $r ): ?string {
				return $this->host;
			}
		};

		$driver->url  = $url;
		$driver->host = $host;

		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) use ( $driver ) {
				$drivers['fake'] = $driver;
				return $drivers;
			},
			20
		);

		return $driver;
	}

	public function test_open_route_only_redirects_to_the_drivers_configured_host(): void {
		list( $resource, , $user ) = $this->resource();

		// Another host than the configured one.
		$this->opener( 'https://evil.example.net/steal?t=abc', 'app.example.com' );
		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id() . '/open', $user )->get_status() );
	}

	public function test_open_route_refuses_unsafe_urls(): void {
		list( $resource, , $user ) = $this->resource();

		$this->opener( 'javascript:alert(1)', 'app.example.com' );
		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id() . '/open', $user )->get_status() );
	}

	public function test_open_route_refuses_when_the_driver_names_no_host(): void {
		list( $resource, , $user ) = $this->resource();

		$this->opener( 'https://app.example.com/open?t=abc', null );
		$this->assertSame( 403, $this->request( 'GET', '/resources/' . $resource->id() . '/open', $user )->get_status() );
	}

	public function test_open_route_allows_the_configured_host_for_that_request_only(): void {
		list( $resource, , $user ) = $this->resource();

		$this->opener( 'https://app.example.com/open?t=abc', 'app.example.com' );
		$response = $this->request( 'GET', '/resources/' . $resource->id() . '/open', $user );

		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( 'https://app.example.com/open?t=abc', $response->get_headers()['Location'] );
		$this->assertSame( '', wp_validate_redirect( 'https://app.example.com/x', '' ), 'The host is not allowed outside the request.' );
	}
}

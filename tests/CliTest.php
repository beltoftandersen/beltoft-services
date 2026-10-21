<?php
/**
 * WP-CLI commands.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Cli\Commands;
use BeltoftServices\Http\Connections;
use BeltoftServices\Jobs;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

require_once __DIR__ . '/Support/WpCliStub.php';

/**
 * Link and status commands, called directly.
 */
class CliTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		\WP_CLI::reset();
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		$this->connection();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Connections::OPTION );

		parent::tear_down();
	}

	/**
	 * A fresh licence on an HTTP product, without lifecycle hooks.
	 *
	 * @param string $driver Driver id.
	 * @return array<string, mixed>
	 */
	private function license( string $driver = 'http' ): array {
		return $this->service_license( $this->service_product( $driver, array(), array( 'slug' => 'cli-' . wp_generate_password( 5, false, false ), 'connection' => 'svc' ) ), array( 'fire' => false ) );
	}

	/**
	 * Runs a command and returns the error message, or '' when it did not fail.
	 *
	 * @param callable $call Command.
	 */
	private function failure( callable $call ): string {
		try {
			$call();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_link_http_records_ready_without_calling_provision(): void {
		$license = $this->license();

		( new Commands() )->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-42' ) );

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertNotNull( $resource );
		$this->assertSame( 'ready', $resource->get( 'state' ) );
		$this->assertSame( 'ready', $resource->get( 'desired' ) );
		$this->assertSame( 'tenant-42', $resource->get( 'remote_id' ) );
		$this->assertSame( 'http', $resource->get( 'driver' ) );
		$this->assertSame( array(), $this->http_calls(), 'Linking made no request.' );
	}

	public function test_linked_resource_is_protected(): void {
		$license = $this->license();

		( new Commands() )->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-42' ) );

		$this->assertTrue( Resource::for_license( (int) $license['id'] )->get( 'protected' ) );
	}

	public function test_link_queues_one_plan_push_not_a_provision(): void {
		$license = $this->license();

		( new Commands() )->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-42' ) );

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertTrue( $resource->get( 'plan_dirty' ) );
		$this->assertNotEmpty( Jobs::pending( $resource->id() ), 'A job is queued.' );
		$this->assertSame( 'update_plan', Reconciler::operation( $resource ) );
	}

	public function test_dry_run_writes_nothing(): void {
		$license = $this->license();

		( new Commands() )->link_http(
			array( (string) $license['id'] ),
			array(
				'remote-id' => 'tenant-42',
				'dry-run'   => true,
			)
		);

		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertSame( array(), $this->http_calls() );
		$this->assertStringContainsString( 'tenant-42', \WP_CLI::text() );
		$this->assertStringContainsString( 'update_plan', \WP_CLI::text() );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => Jobs::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );
	}

	public function test_link_refuses_a_license_that_already_has_a_resource(): void {
		$license = $this->license();
		$cli     = new Commands();
		$cli->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-1' ) );

		$message = $this->failure( fn() => $cli->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-2' ) ) );

		$this->assertStringContainsString( 'already', $message );
		$this->assertSame( 'tenant-1', Resource::for_license( (int) $license['id'] )->get( 'remote_id' ) );
	}

	public function test_link_refuses_a_non_http_product_an_empty_remote_id_and_unknown_license(): void {
		$this->fake_driver( 'fake' );
		$cli = new Commands();

		$fake = $this->license( 'fake' );
		$this->assertNotSame( '', $this->failure( fn() => $cli->link_http( array( (string) $fake['id'] ), array( 'remote-id' => 'x' ) ) ) );
		$this->assertNull( Resource::for_license( (int) $fake['id'] ) );

		$http = $this->license();
		foreach ( array( '', '   ' ) as $empty ) {
			$this->assertNotSame( '', $this->failure( fn() => $cli->link_http( array( (string) $http['id'] ), array( 'remote-id' => $empty ) ) ) );
		}
		$this->assertNotSame( '', $this->failure( fn() => $cli->link_http( array( (string) $http['id'] ), array() ) ) );
		$this->assertNull( Resource::for_license( (int) $http['id'] ) );

		$this->assertNotSame( '', $this->failure( fn() => $cli->link_http( array( '999999' ), array( 'remote-id' => 'x' ) ) ) );
	}

	public function test_status_prints_meta_without_secrets(): void {
		$license = $this->license();
		$cli     = new Commands();
		$cli->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-42' ) );
		Resource::for_license( (int) $license['id'] )->set(
			array(
				'connection_secret' => 'TOPSECRET',
				'reveal'            => array( 'password' => 'HUNTER2' ),
				'api_token'         => 'TOKEN123',
			)
		);
		\WP_CLI::reset();

		$cli->status( array( (string) $license['id'] ), array() );
		$table = \WP_CLI::text();
		\WP_CLI::reset();
		$cli->status( array( (string) $license['id'] ), array( 'format' => 'json' ) );
		$json = \WP_CLI::text();

		foreach ( array( $table, $json ) as $out ) {
			$this->assertStringContainsString( 'tenant-42', $out );
			$this->assertStringContainsString( 'ready', $out );
			foreach ( array( 'TOPSECRET', 'HUNTER2', 'TOKEN123' ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $out );
			}
		}
		$this->assertIsArray( json_decode( $json, true ) );
	}

	public function test_status_without_a_resource_is_an_error(): void {
		$license = $this->license();

		$this->assertNotSame( '', $this->failure( fn() => ( new Commands() )->status( array( (string) $license['id'] ), array() ) ) );
	}

	/**
	 * Saves an HTTP connection 'svc' with a pause and update_plan endpoint.
	 */
	private function connection(): void {
		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Connections::OPTION );

		$this->assertTrue(
			Connections::save(
				'svc',
				array(
					'label'         => 'Service',
					'base_url'      => 'https://api.example.com/v1',
					'auth'          => 'hmac',
					'secret'        => 'S3cret-Value-Never-Shown',
					'endpoints'     => array(
						'provision'   => array( 'method' => 'POST', 'path' => '/tenants' ),
						'pause'       => array( 'method' => 'POST', 'path' => '/tenants/{remote_id}/pause' ),
						'update_plan' => array( 'method' => 'PUT', 'path' => '/tenants/{remote_id}/plan' ),
						'delete'      => array( 'method' => 'DELETE', 'path' => '/tenants/{remote_id}' ),
					),
					'body_template' => '{"limit":{limit}}',
				)
			)
		);
	}

	public function test_link_http_refuses_a_product_whose_connection_does_not_exist(): void {
		$cli = new Commands();

		foreach ( array( '', 'nowhere' ) as $connection ) {
			$product = $this->service_product( 'http', array(), array( 'connection' => $connection, 'slug' => 'cli-noconn-' . $connection ) );
			$license = $this->service_license( $product, array( 'fire' => false ) );

			$message = $this->failure( fn() => $cli->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-1' ) ) );

			$this->assertStringContainsString( 'connection', $message );
			$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		}
	}

	public function test_link_http_refuses_a_remote_id_already_linked_to_another_license(): void {
		$cli   = new Commands();
		$first = $this->license();
		$cli->link_http( array( (string) $first['id'] ), array( 'remote-id' => 'tenant-dup' ) );

		$second  = $this->license();
		$message = $this->failure( fn() => $cli->link_http( array( (string) $second['id'] ), array( 'remote-id' => 'tenant-dup' ) ) );

		$this->assertStringContainsString( 'already linked', $message );
		$this->assertStringContainsString( (string) $first['id'], $message );
		$this->assertNull( Resource::for_license( (int) $second['id'] ) );

		// A dry run refuses too, and another id is fine.
		$this->assertStringContainsString( 'already linked', $this->failure( fn() => $cli->link_http( array( (string) $second['id'] ), array( 'remote-id' => 'tenant-dup', 'dry-run' => true ) ) ) );
		$cli->link_http( array( (string) $second['id'] ), array( 'remote-id' => 'tenant-other' ) );
		$this->assertNotNull( Resource::for_license( (int) $second['id'] ) );
	}

	public function test_inactive_license_is_refused_and_nothing_is_written(): void {
		$cli = new Commands();

		foreach ( array( 'revoked', 'suspended' ) as $status ) {
			$license = $this->license();
			\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'status' => $status ) );

			$message = $this->failure( fn() => $cli->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-9' ) ) );

			$this->assertStringContainsString( $status, $message );
			$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		}

		$expired = $this->license();
		\BeltoftLicenses\Licenses::update( (int) $expired['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->assertStringContainsString( 'expired', $this->failure( fn() => $cli->link_http( array( (string) $expired['id'] ), array( 'remote-id' => 'tenant-9' ) ) ) );
		$this->assertNull( Resource::for_license( (int) $expired['id'] ) );
	}

	public function test_pause_of_a_linked_resource_uses_the_remote_id(): void {
		$this->connection();
		$product = $this->service_product( 'http', array(), array( 'connection' => 'svc', 'slug' => 'cli-pause' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		( new Commands() )->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-9' ) );
		$this->run_jobs();

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertTrue( $resource->desire( 'paused', 'Test' ) );
		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$this->assertContains( 'https://api.example.com/v1/tenants/tenant-9/pause', array_column( $this->http_calls(), 'url' ) );
		$this->assertSame( 'paused', Resource::for_license( (int) $license['id'] )->get( 'state' ) );
	}

	public function test_plan_uses_the_licenses_variation(): void {
		$this->connection();
		$product = $this->service_product(
			'http',
			array( 'limit' => 2 ),
			array(
				'connection' => 'svc',
				'slug'       => 'cli-var',
				'variations' => array( array( 'limit' => 7 ), array( 'limit' => 11 ) ),
			)
		);
		$variation = $product->get_children()[1];
		$license   = $this->service_license( $product, array( 'fire' => false, 'variation_id' => $variation ) );

		( new Commands() )->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-v' ) );
		$this->assertSame( 11, Resource::for_license( (int) $license['id'] )->get( 'plan' )['limit'] );

		$this->run_jobs();

		$call = $this->http_calls()[0];
		$this->assertSame( 'https://api.example.com/v1/tenants/tenant-v/plan', $call['url'] );
		$this->assertSame( 11, json_decode( $call['args']['body'], true )['limit'] );
	}

	public function test_a_resource_made_in_between_is_not_reported_as_linked(): void {
		$license = $this->license();
		$driver  = \BeltoftServices\Product::driver_for( (int) $license['product_id'] );
		$done    = false;

		add_action(
			'beltoft_services_resource_locked',
			function () use ( $license, $driver, &$done ) {
				if ( $done ) {
					return;
				}
				$done = true;
				$this->assertInstanceOf( Resource::class, Resource::create( $license, $driver, array(), array( 'remote_id' => 'other' ) ) );
			}
		);

		$message = $this->failure( fn() => ( new Commands() )->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'mine' ) ) );

		$this->assertStringContainsString( 'meantime', $message );
		$this->assertSame( 'other', Resource::for_license( (int) $license['id'] )->get( 'remote_id' ) );
		$this->assertNotContains( 'resource_linked', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $license['id'] ), 'type' ) );
	}

	public function test_linked_tenant_sends_no_ready_mail_but_a_paused_mail_later(): void {
		$this->connection();
		$mails = array();
		add_filter(
			'pre_wp_mail',
			static function ( $pre, $atts ) use ( &$mails ) {
				$mails[] = $atts;
				return true;
			},
			10,
			2
		);
		$product = $this->service_product( 'http', array(), array( 'connection' => 'svc', 'slug' => 'cli-mail' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );

		( new Commands() )->link_http( array( (string) $license['id'] ), array( 'remote-id' => 'tenant-m' ) );
		$this->run_jobs();
		$this->assertSame( 'ready', Resource::for_license( (int) $license['id'] )->get( 'notified' ) );
		$this->assertSame( array(), $mails, 'Linking sends no ready email.' );

		\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'status' => 'suspended' ) );
		$this->run_jobs();

		$paused = array_filter( $mails, static fn( $m ) => false !== stripos( (string) $m['subject'], 'paused' ) && false !== strpos( implode( ',', (array) $m['to'] ), 'customer@example.com' ) );
		$this->assertCount( 1, $paused );
	}
}

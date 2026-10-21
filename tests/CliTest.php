<?php
/**
 * WP-CLI commands.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Cli\Commands;
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
	}

	/**
	 * A fresh licence on an HTTP product, without lifecycle hooks.
	 *
	 * @param string $driver Driver id.
	 * @return array<string, mixed>
	 */
	private function license( string $driver = 'http' ): array {
		return $this->service_license( $this->service_product( $driver, array(), array( 'slug' => 'cli-' . wp_generate_password( 5, false, false ) ) ), array( 'fire' => false ) );
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
}

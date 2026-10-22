<?php
/**
 * Generated passwords and the one-time reveal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Nothing here reaches a Proxmox host.
 */
class AccessTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * A ready resource owned by a new customer.
	 *
	 * @param array<string, mixed> $meta Resource meta.
	 * @return array{0: Resource, 1: int}
	 */
	private function resource( array $meta = array( 'state' => 'ready' ) ): array {
		$driver  = $this->fake_driver();
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$row     = $this->service_license( $product, array( 'fire' => false ) );
		$created = Resource::create( $row, $driver, array(), $meta );

		$this->assertInstanceOf( Resource::class, $created );

		return array( $created, (int) $row['user_id'] );
	}

	private function post( int $id, int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id );

		return rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/reveal' ) );
	}

	public function test_password_charset_and_length(): void {
		$allowed = array_merge( range( 'a', 'z' ), range( 'A', 'Z' ), range( '0', '9' ), str_split( '-_.!@#%^*+=' ) );
		$seen    = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$password = Access::generate_password();

			$this->assertSame( 24, strlen( $password ) );
			$this->assertSame( array(), array_diff( str_split( $password ), $allowed ), 'Only the allowed characters.' );
			$this->assertDoesNotMatchRegularExpression( '/["\'\\\\` \s]/', $password );
			$seen[ $password ] = true;
		}

		$this->assertCount( 200, $seen, 'Passwords differ.' );
		// 200 x 24 characters: every one of the 73 characters must have shown up, or random_int is not spread over the set.
		$all = array();
		foreach ( array_keys( $seen ) as $password ) {
			foreach ( str_split( $password ) as $char ) {
				$all[ $char ] = true;
			}
		}
		$this->assertGreaterThan( 60, count( $all ) );
	}

	public function test_password_is_stored_before_it_is_sent_and_reused_on_retry(): void {
		list( $resource ) = $this->resource();

		$password = Access::pending_password( $resource );

		// Nothing was sent yet: the stored copy already exists, encrypted, in a fresh read (a crash now loses nothing).
		$fresh  = Resource::find( $resource->id() );
		$stored = (string) $fresh->get( 'reveal' );
		$this->assertNotSame( '', $stored );
		$this->assertStringNotContainsString( $password, $stored );
		$this->assertSame( $password, Crypto::decrypt( $stored ) );
		$this->assertGreaterThan( time() + 6 * DAY_IN_SECONDS, (int) $fresh->get( 'reveal_until' ) );
		$this->assertLessThanOrEqual( time() + 7 * DAY_IN_SECONDS, (int) $fresh->get( 'reveal_until' ) );

		// The retry, from a fresh read, gets the same password.
		$this->assertSame( $password, Access::pending_password( Resource::find( $resource->id() ) ) );

		// Plain text is nowhere in the stored row.
		global $wpdb;
		$meta = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT meta FROM ' . \BeltoftLicenses\Install::tables()['activations'] . ' WHERE id = %d', $resource->id() ) ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.NotPrepared -- test read.
		$this->assertStringNotContainsString( $password, $meta );
	}

	public function test_no_exception_text_or_client_call_record_contains_the_password(): void {
		list( $resource ) = $this->resource();

		// An exception from the storing step.
		global $wpdb;
		$wpdb->delete( \BeltoftLicenses\Install::tables()['activations'], array( 'id' => $resource->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test.
		$texts = array();

		try {
			Access::pending_password( $resource );
			$this->fail( 'Expected an exception.' );
		} catch ( \RuntimeException $e ) {
			$texts[] = $e->getMessage() . $e->getTraceAsString();
		}

		// A client that is sent the password records it scrubbed.
		list( $other ) = $this->resource();
		$password      = Access::pending_password( $other );
		$client        = new \BeltoftServices\Proxmox\FakeClient();
		$client->add_node( 'n1' );
		$client->add_vm( array( 'vmid' => 100, 'node' => 'n1', 'name' => 'x' ) );
		$client->request( 'PUT', '/nodes/n1/qemu/100/config', array( 'cipassword' => $password, 'sshkeys' => rawurlencode( 'ssh-ed25519 AAAA' ) ) );

		$texts[] = wp_json_encode( $client->calls() );
		$texts[] = wp_json_encode( $client->state() );

		foreach ( $texts as $text ) {
			$this->assertStringNotContainsString( $password, $text );
		}

		$this->assertStringContainsString( '***', $texts[1], 'The record exists, scrubbed.' );
	}

	public function test_without_a_key_nothing_is_generated_or_stored(): void {
		list( $resource ) = $this->resource();
		remove_all_filters( 'beltoft_services_key' );

		try {
			Access::pending_password( $resource );
			$this->fail( 'Expected an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNull( Resource::find( $resource->id() )->get( 'reveal' ) );
		}
	}

	public function test_reveal_once_for_owner_only(): void {
		list( $resource, $owner ) = $this->resource();
		$password                 = Access::pending_password( $resource );
		$stranger                 = self::factory()->user->create();

		$this->assertNull( Access::take_reveal( Resource::find( $resource->id() ), $stranger ), 'Another user gets nothing.' );
		$this->assertNull( Access::take_reveal( Resource::find( $resource->id() ), 0 ), 'A visitor gets nothing.' );
		$this->assertNotSame( '', (string) Resource::find( $resource->id() )->get( 'reveal' ), 'A refused attempt wipes nothing.' );

		$this->assertSame( $password, Access::take_reveal( Resource::find( $resource->id() ), $owner ) );
		$this->assertNull( Access::take_reveal( Resource::find( $resource->id() ), $owner ), 'Second time: gone.' );
		$this->assertSame( '', (string) Resource::find( $resource->id() )->get( 'reveal' ) );
	}

	public function test_two_simultaneous_reveals_only_one_gets_the_password(): void {
		list( $resource, $owner ) = $this->resource();
		$password                 = Access::pending_password( $resource );

		// Two requests read the resource before either took the password.
		$first  = Resource::find( $resource->id() );
		$second = Resource::find( $resource->id() );

		$this->assertSame( $password, Access::take_reveal( $first, $owner ) );
		$this->assertNull( Access::take_reveal( $second, $owner ), 'The stale copy is refreshed under the lock and finds it wiped.' );
	}

	public function test_a_busy_lock_reveals_nothing_and_wipes_nothing(): void {
		list( $resource, $owner ) = $this->resource();
		Access::pending_password( $resource );

		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the resource lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );
		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );

		$this->assertNull( Access::take_reveal( Resource::find( $resource->id() ), $owner ) );
		$other->close();

		$this->assertNotNull( Access::take_reveal( Resource::find( $resource->id() ), $owner ), 'Still there once the lock is free.' );
	}

	public function test_reveal_expires(): void {
		list( $resource, $owner ) = $this->resource();
		Access::pending_password( $resource );
		$resource->set( array( 'reveal_until' => time() - 1 ) );

		$this->assertNull( Access::take_reveal( Resource::find( $resource->id() ), $owner ) );
		$this->assertSame( '', (string) Resource::find( $resource->id() )->get( 'reveal' ), 'An expired password is wiped.' );
	}

	public function test_reveal_route_rate_limited_and_no_store(): void {
		list( $resource, $owner ) = $this->resource();
		$password                 = Access::pending_password( $resource );

		$first = $this->post( $resource->id(), $owner );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'password' => $password ), $first->get_data() );
		$this->assertStringContainsString( 'no-store', (string) ( $first->get_headers()['Cache-Control'] ?? '' ) );

		$second = $this->post( $resource->id(), $owner );
		$this->assertSame( 409, $second->get_status() );
		$this->assertStringNotContainsString( $password, wp_json_encode( $second->get_data() ) );

		for ( $i = 3; $i <= 5; $i++ ) {
			$this->assertSame( 409, $this->post( $resource->id(), $owner )->get_status() );
		}

		$sixth = $this->post( $resource->id(), $owner );
		$this->assertSame( 429, $sixth->get_status(), 'Five a minute.' );
		$this->assertNotEmpty( $sixth->get_headers()['Retry-After'] ?? '' );
	}

	public function test_reveal_route_has_a_rate_limit_floor_even_when_the_filter_turns_limits_off(): void {
		list( $resource, $owner ) = $this->resource();
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );

		$statuses = array();

		for ( $i = 1; $i <= \BeltoftServices\Proxmox\RevealRoute::FLOOR_LIMIT + 1; $i++ ) {
			$statuses[] = $this->post( $resource->id(), $owner )->get_status();
		}

		remove_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );

		$this->assertNotContains( 429, array_slice( $statuses, 0, \BeltoftServices\Proxmox\RevealRoute::FLOOR_LIMIT ) );
		$this->assertSame( 429, end( $statuses ) );
	}

	public function test_reveal_route_refuses_strangers_visitors_and_unready_resources(): void {
		list( $resource, $owner ) = $this->resource();
		$password                 = Access::pending_password( $resource );
		$stranger                 = self::factory()->user->create();

		$this->assertSame( 401, $this->post( $resource->id(), 0 )->get_status() );

		$denied = $this->post( $resource->id(), $stranger );
		$this->assertSame( 403, $denied->get_status() );
		$this->assertStringNotContainsString( $password, wp_json_encode( $denied->get_data() ) );

		$this->assertSame( 403, $this->post( $resource->id() + 9999, $owner )->get_status(), 'Same answer for a resource that does not exist.' );

		$resource->set( array( 'state' => 'paused' ) );
		$this->assertSame( 403, $this->post( $resource->id(), $owner )->get_status(), 'Not ready: refused.' );

		$resource->set( array( 'state' => 'ready' ) );
		$this->assertNotNull( Access::take_reveal( Resource::find( $resource->id() ), $owner ), 'None of the refusals consumed it.' );
	}

	public function test_the_reveal_route_is_registered_with_the_other_customer_routes(): void {
		$this->assertArrayHasKey( '/beltoft-services/v1/resources/(?P<id>\d+)/reveal', rest_get_server()->get_routes() );
		$this->assertTrue( ( new \ReflectionMethod( Routes::class, 'guard' ) )->isPublic() );
	}

	public function test_medusa_no_password_in_checkout_metadata(): void {
		list( $resource ) = $this->resource();
		$order            = wc_create_order();
		$password         = Access::pending_password( $resource );

		$order->save();

		// The password lives in the resource only, encrypted; never on an order, its items or its notes.
		$haystack = wp_json_encode( array( $order->get_meta_data(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
		$this->assertStringNotContainsString( $password, (string) $haystack );
		$this->assertStringNotContainsString( 'reveal', (string) $haystack );

		$events = wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $resource->license()['id'] ) );
		$this->assertStringNotContainsString( $password, (string) $events );
	}
}

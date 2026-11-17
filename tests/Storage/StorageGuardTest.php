<?php
/**
 * The spend guard, the cost table and the settings of backup storage.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Crypto;
use BeltoftServices\Settings;
use BeltoftServices\Storage\FakeStorageClient;
use BeltoftServices\Storage\LiveStorageClient;
use BeltoftServices\Storage\StorageClients;
use BeltoftServices\Storage\StorageDriver;
use BeltoftServices\Storage\StorageSettings;
use BeltoftServices\Storage\StorageSpendGuard;
use BeltoftServices\Storage\UnavailableStorageClient;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageGuardTest extends StorageTestCase {

	/**
	 * A paid line.
	 *
	 * @param float  $total    Line total.
	 * @param string $currency Order currency.
	 */
	private function line( float $total, string $currency = 'EUR' ): \WC_Order_Item_Product {
		$order = wc_create_order();
		$order->set_currency( $currency );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Storage', 'quantity' => 1, 'subtotal' => $total, 'total' => $total ) );
		$order->add_item( $line );
		$order->save();

		return $line;
	}

	public function test_an_unknown_cost_refuses(): void {
		$this->assertStringContainsString( 'unknown', (string) StorageSpendGuard::problem( 'bx31', $this->line( 100.0 ) ) );
		$this->settings( array( 'storage_cost_table' => '' ) );
		$this->assertStringContainsString( 'unknown', (string) StorageSpendGuard::problem( 'bx11', $this->line( 100.0 ) ), 'The table is empty by default.' );
	}

	public function test_the_cost_table_is_empty_by_default(): void {
		$fields = Settings::fields();
		$this->assertSame( '', $fields['storage_cost_table']['default'] );
		$this->assertSame( 0, $fields['storage_max_boxes']['default'] );
		$this->assertSame( 0, $fields['storage_max_monthly_cost']['default'] );
		$this->assertSame( 0, $fields['storage_max_new_per_day']['default'] );
		$this->assertSame( 0, $fields['storage_deletes_enabled']['default'] );
		$this->assertSame( 0, $fields['storage_alias_enabled']['default'] );
		$this->assertSame( 'fake', $fields['storage_client']['default'] );
	}

	public function test_a_line_below_the_cost_refuses_and_an_equal_line_passes(): void {
		$this->assertSame( StorageSpendGuard::MESSAGE, StorageSpendGuard::problem( 'bx11', $this->line( 3.0 ) ) );
		$this->assertNull( StorageSpendGuard::problem( 'bx11', $this->line( 3.20 ) ) );
		$this->assertNull( StorageSpendGuard::problem( 'bx11', $this->line( 6.40 ) ) );
		$this->assertStringContainsString( 'unavailable', (string) StorageSpendGuard::problem( 'bx11', null ) );
	}

	public function test_account_credit_counts_as_paid(): void {
		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Storage', 'quantity' => 1, 'subtotal' => 3.2, 'total' => 1.2 ) );
		$order->add_item( $line );
		$order->save();
		$this->assertSame( StorageSpendGuard::MESSAGE, StorageSpendGuard::problem( 'bx11', $line ) );
		add_filter( 'beltoft_services_order_credit_cents', static fn() => 200 );
		$this->assertNull( StorageSpendGuard::problem( 'bx11', $line ), 'A paid cost through credit is paid.' );
		remove_all_filters( 'beltoft_services_order_credit_cents' );
	}

	public function test_another_currency_needs_a_guard_rate(): void {
		$this->assertStringContainsString( 'not in EUR', (string) StorageSpendGuard::problem( 'bx11', $this->line( 100.0, 'DKK' ) ) );
		$this->settings( array( 'currency_guard_rates' => 'DKK=7.60' ) );
		$this->assertNull( StorageSpendGuard::problem( 'bx11', $this->line( 100.0, 'DKK' ) ) );
		$this->assertSame( StorageSpendGuard::MESSAGE, StorageSpendGuard::problem( 'bx11', $this->line( 20.0, 'DKK' ) ) );
	}

	public function test_the_caps_only_bind_a_live_client_and_default_to_none_allowed(): void {
		$this->assertNull( StorageSpendGuard::budget_problem( 'bx11', null, false ), 'The fake spends nothing.' );
		$this->assertStringContainsString( 'cap on live storage boxes', (string) StorageSpendGuard::budget_problem( 'bx11', null, true ) );
		$this->settings( array( 'storage_max_boxes' => 5, 'storage_max_monthly_cost' => 3, 'storage_max_new_per_day' => 5 ) );
		$this->assertStringContainsString( 'monthly cost', (string) StorageSpendGuard::budget_problem( 'bx11', null, true ) );
		$this->settings( array( 'storage_max_monthly_cost' => 100, 'storage_max_new_per_day' => 1 ) );
		$this->assertNull( StorageSpendGuard::budget_problem( 'bx11', null, true ) );
		StorageSpendGuard::count_created();
		$this->assertStringContainsString( 'per day', (string) StorageSpendGuard::budget_problem( 'bx11', null, true ) );
	}

	public function test_a_guard_failure_buys_nothing_and_fails_the_build(): void {
		$this->settings( array( 'storage_cost_table' => '' ) );
		$r = $this->storage_resource();
		$result = ( new StorageDriver() )->provision( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 0, FakeStorageClient::state()['creates'] );
		$this->settings( array( 'storage_cost_table' => 'bx11=3.20' ) );
		$paid_below = $this->storage_resource( array( 'total' => 1.0 ) );
		$this->assertTrue( ( new StorageDriver() )->provision( $paid_below )->is_failed() );
		$this->assertSame( 0, FakeStorageClient::state()['creates'] );
	}

	public function test_the_client_is_the_fake_unless_constant_setting_and_token_agree(): void {
		remove_all_filters( 'beltoft_services_storage_client' );
		$this->assertInstanceOf( FakeStorageClient::class, StorageClients::build( false ) );
		$this->settings( array( 'storage_client' => 'live', 'storage_api_url' => 'https://api.example.test/v1' ) );
		$this->assertInstanceOf( FakeStorageClient::class, StorageClients::build( false ), 'The setting alone is not enough.' );
		$this->assertInstanceOf( UnavailableStorageClient::class, StorageClients::build( true ), 'No token: never the fake.' );
		$this->assertTrue( StorageClients::build( true )->get_box( '1' ) instanceof \WP_Error );
		$this->settings( array( 'storage_api_token' => Crypto::encrypt( 'TOKEN-123' ) ) );
		$this->assertInstanceOf( LiveStorageClient::class, StorageClients::build( true ) );
		$this->settings( array( 'storage_client' => 'fake' ) );
		$this->assertInstanceOf( FakeStorageClient::class, StorageClients::build( true ), 'The constant alone is not enough.' );
		$this->settings( array( 'storage_client' => 'live', 'storage_api_url' => 'http://api.example.test/v1' ) );
		$this->assertInstanceOf( UnavailableStorageClient::class, StorageClients::build( true ), 'http is refused.' );
		$this->assertFalse( StorageClients::live_allowed(), 'The constant is not defined in tests.' );
		$live = new LiveStorageClient( 'https://api.example.test/v1', 'T' );
		add_filter( 'beltoft_services_storage_client', static fn() => $live );
		$this->settings( array( 'storage_client' => 'fake' ) );
		$this->assertInstanceOf( FakeStorageClient::class, StorageClients::build( false ), 'No filter builds a live client.' );
	}

	public function test_the_token_is_saved_encrypted_and_not_printed(): void {
		$clean = Settings::sanitize( array( 'storage_api_token' => 'TOKEN-VALUE-123' ) );
		$this->assertStringStartsWith( 'bs1:', $clean['storage_api_token'] );
		$this->assertStringNotContainsString( 'TOKEN-VALUE-123', $clean['storage_api_token'] );
		update_option( Settings::OPTION, $clean );
		$this->assertSame( 'TOKEN-VALUE-123', StorageSettings::api_token() );
		ob_start();
		Settings::render_field( array( 'key' => 'storage_api_token' ) );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( $clean['storage_api_token'], $html );
		$this->assertStringNotContainsString( 'TOKEN-VALUE-123', $html );
	}

	public function test_the_fake_follows_the_provider_rules(): void {
		$c = $this->client;
		$this->assertTrue( $c->create_box( 'n1', 'bx11', 'fsn1', 'short', array(), array() ) instanceof \WP_Error );
		$this->assertTrue( $c->create_box( 'n1', 'bx11', 'fsn1', 'longpassword12', array(), array() ) instanceof \WP_Error, 'Needs a special character.' );
		$box = $c->create_box( 'n1', 'bx11', 'fsn1', 'long-password-12', array(), array() );
		$this->assertSame( 'initializing', $box['status'] );
		$this->assertTrue( $c->create_box( 'n1', 'bx11', 'fsn1', 'long-password-12', array(), array() ) instanceof \WP_Error, 'Names are unique.' );
		$this->assertSame( 'initializing', $c->get_box( $box['id'] )['status'] );
		$this->assertSame( 'active', $c->get_box( $box['id'] )['status'] );
		$limit = FakeStorageClient::catalogue()['bx11']['snapshots'];
		for ( $i = 0; $i < $limit; $i++ ) {
			$this->assertIsArray( $c->create_snapshot( $box['id'], 's' . $i ) );
		}
		$this->assertTrue( $c->create_snapshot( $box['id'], 'one too many' ) instanceof \WP_Error );
	}

	public function test_the_live_client_shapes_its_requests_and_keeps_the_token_out_of_errors(): void {
		$live = new LiveStorageClient( 'https://api.example.test/v1', 'SECRET-TOKEN' );
		$this->respond_with(
			static function ( string $url ) {
				if ( str_contains( $url, '/actions/' ) ) {
					return array( 'headers' => array(), 'body' => '{"error":{"code":"rate_limit_exceeded","message":"slow down SECRET-TOKEN"}}', 'response' => array( 'code' => 429, 'message' => 'x' ), 'cookies' => array(), 'filename' => null );
				}

				return array( 'headers' => array(), 'body' => '{"storage_box":{"id":42,"name":"bs-x","status":"active","username":"u42","server":"u42.host.test","storage_box_type":{"name":"bx11","size":1000000000000},"location":{"name":"fsn1"},"stats":{"size_data":5,"size_snapshots":2},"access_settings":{"ssh_enabled":true,"reachable_externally":true},"protection":{"delete":true}}}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
			}
		);
		$box = $live->create_box( 'bs-x', 'bx11', 'fsn1', 'long-password-12', array(), array( 'ssh_enabled' => true ) );
		$this->assertSame( 'u42', $box['username'] );
		$this->assertTrue( $box['protected'] );
		$this->assertSame( 2, $box['snapshot_bytes'] );
		$call = $this->http_calls()[0];
		$this->assertSame( 'https://api.example.test/v1/storage_boxes', $call['url'] );
		$this->assertSame( 'POST', $call['args']['method'] );
		$this->assertSame( 'Bearer SECRET-TOKEN', $call['args']['headers']['Authorization'] );
		$this->assertSame( 'bs-x', json_decode( $call['args']['body'], true )['name'] );
		$error = $live->set_access( '42', array( 'ssh_enabled' => false ) );
		$this->assertTrue( $error instanceof \WP_Error );
		$this->assertTrue( $error->get_error_data()['retry'], '429 is retryable.' );
		$this->assertStringNotContainsString( 'SECRET-TOKEN', $error->get_error_message() );
		$this->assertStringNotContainsString( 'long-password-12', wp_json_encode( $error ) );
		$this->assertSame( '/storage_boxes/42/actions/update_access_settings', substr( $this->http_calls()[1]['url'], strlen( 'https://api.example.test/v1' ) ) );
		$this->expectException( \InvalidArgumentException::class );
		new LiveStorageClient( 'http://api.example.test', 'T' );
	}
}

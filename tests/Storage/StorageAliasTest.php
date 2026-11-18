<?php
/**
 * The host name alias in a Cloudflare zone: fake writer, live request shape, gate, names that are ours.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Crypto;
use BeltoftServices\Settings;
use BeltoftServices\Storage\AliasDnsClients;
use BeltoftServices\Storage\Boxes;
use BeltoftServices\Storage\CloudflareAliasDns;
use BeltoftServices\Storage\FakeAliasDns;
use BeltoftServices\Storage\FakeStorageClient;
use BeltoftServices\Storage\HostAlias;
use BeltoftServices\Storage\StorageDriver;
use BeltoftServices\Storage\UnavailableAliasDns;
use BeltoftServices\Resource;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageAliasTest extends StorageTestCase {

	private const ZONE = '0123456789abcdef0123456789abcdef';

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'storage_alias_enabled' => 1, 'storage_alias_writer' => 'cloudflare', 'storage_alias_zone' => 'example.test', 'storage_alias_pattern' => 'backup{n}.{zone}' ) );
	}

	public function test_the_defaults_keep_the_alias_off_and_the_writer_cloudflare(): void {
		$fields = Settings::fields();
		$this->assertSame( 0, $fields['storage_alias_enabled']['default'] );
		$this->assertSame( 'cloudflare', $fields['storage_alias_writer']['default'] );
		$this->assertSame( 'fake', $fields['storage_alias_dns_client']['default'] );
		$this->assertSame( 'secret', $fields['storage_cf_token']['type'] );
	}

	public function test_a_record_is_created_unproxied_and_a_rerun_makes_no_second(): void {
		$r = $this->ready_storage();
		$name = 'backup' . HostAlias::number( $r ) . '.example.test';
		$this->assertSame( $name, (string) $r->get( 'storage_alias' ) );
		$rows = FakeAliasDns::records();
		$this->assertCount( 1, $rows );
		$row = array_values( $rows )[0];
		$this->assertSame( 'CNAME', $row['type'] );
		$this->assertFalse( $row['proxied'] );
		$this->assertSame( (string) $r->get( 'storage_host' ), $row['content'] );
		$this->assertSame( $name, HostAlias::publish( Resource::find( $r->id() ), (string) $r->get( 'storage_host' ) ) );
		$this->assertCount( 1, FakeAliasDns::records(), 'Idempotent by name.' );
		$this->assertSame( $name, Boxes::connection( $r )['host'] );
	}

	public function test_a_changed_upstream_host_updates_the_record(): void {
		$r = $this->ready_storage();
		HostAlias::publish( $r, 'new-host.example.net' );
		$rows = array_values( FakeAliasDns::records() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'new-host.example.net', $rows[0]['content'] );
	}

	public function test_a_foreign_record_at_the_name_is_left_alone(): void {
		$r = $this->storage_resource();
		FakeAliasDns::seed( 'backup' . HostAlias::number( $r ) . '.example.test', 'A', '192.0.2.1' );
		$this->assertSame( '', HostAlias::publish( $r, 'u1.host.test' ) );
		$rows = array_values( FakeAliasDns::records() );
		$this->assertCount( 1, $rows, 'Nothing was added or changed.' );
		$this->assertSame( 'A', $rows[0]['type'] );
		$this->assertSame( '192.0.2.1', $rows[0]['content'] );
	}

	public function test_delete_removes_only_our_own_record(): void {
		$r = $this->ready_storage();
		$other = $this->ready_storage();
		FakeAliasDns::seed( 'www.example.test', 'CNAME', 'elsewhere.example.net' );
		$this->settings( array( 'storage_deletes_enabled' => 1 ) );
		$this->assertTrue( ( new StorageDriver() )->delete( $r )->is_done() );
		$names = array_column( FakeAliasDns::records(), 'name' );
		$this->assertNotContains( 'backup' . HostAlias::number( $r ) . '.example.test', $names );
		$this->assertContains( 'backup' . HostAlias::number( $other ) . '.example.test', $names, 'The other box keeps its record.' );
		$this->assertContains( 'www.example.test', $names );
	}

	public function test_a_record_that_no_longer_points_at_our_host_is_not_deleted(): void {
		$r = $this->ready_storage();
		$rows = FakeAliasDns::records();
		$id = array_key_first( $rows );
		( new FakeAliasDns() )->update( (string) $id, 'backup' . HostAlias::number( $r ) . '.example.test', 'someone-else.example.net' );
		HostAlias::remove( $r );
		$this->assertCount( 1, FakeAliasDns::records() );
	}

	public function test_names_outside_the_pattern_are_refused(): void {
		$this->assertTrue( HostAlias::ours( 'backup12.example.test' ) );
		$this->assertFalse( HostAlias::ours( 'www.example.test' ) );
		$this->assertFalse( HostAlias::ours( 'backupx.example.test' ) );
		$this->assertFalse( HostAlias::ours( 'backup12.other.test' ) );
		$this->assertFalse( HostAlias::ours( 'backup12.example.test.evil.test' ) );
		$this->assertFalse( HostAlias::ours( 'sub.backup12.example.test' ) );
		$this->assertFalse( HostAlias::ours( 'backup12.example.testx' ) );
	}

	public function test_live_writes_need_the_constant_the_setting_and_the_token(): void {
		remove_all_filters( 'beltoft_services_storage_alias_dns' );
		$this->assertFalse( AliasDnsClients::live_allowed(), 'The constant is not defined in tests.' );
		$this->settings( array( 'storage_alias_dns_client' => 'live', 'storage_cf_zone_id' => self::ZONE, 'storage_cf_token' => Crypto::encrypt( 'CF-TOKEN' ) ) );
		$this->assertInstanceOf( FakeAliasDns::class, AliasDnsClients::build( false ), 'Setting and token alone: the fake.' );
		$this->assertInstanceOf( CloudflareAliasDns::class, AliasDnsClients::build( true ) );
		$this->settings( array( 'storage_alias_dns_client' => 'fake' ) );
		$this->assertInstanceOf( FakeAliasDns::class, AliasDnsClients::build( true ), 'The constant alone is not enough.' );
		$this->settings( array( 'storage_alias_dns_client' => 'live', 'storage_cf_token' => '' ) );
		$unavailable = AliasDnsClients::build( true );
		$this->assertInstanceOf( UnavailableAliasDns::class, $unavailable, 'No token: never the fake.' );
		$this->assertTrue( $unavailable->find( 'x' ) instanceof \WP_Error );
		$live = new CloudflareAliasDns( self::ZONE, 'T' );
		add_filter( 'beltoft_services_storage_alias_dns', static fn() => $live );
		$this->settings( array( 'storage_alias_dns_client' => 'fake' ) );
		$this->assertInstanceOf( FakeAliasDns::class, AliasDnsClients::build( false ), 'No filter builds a live writer.' );
	}

	public function test_an_unusable_live_writer_makes_the_step_retry_and_never_writes_the_fake(): void {
		remove_all_filters( 'beltoft_services_storage_alias_dns' );
		$unavailable = new UnavailableAliasDns();
		$this->assertTrue( $unavailable->create( 'a', 'b' ) instanceof \WP_Error );
		$this->assertSame( array(), FakeAliasDns::records() );
	}

	public function test_the_cloudflare_requests_are_unproxied_scoped_and_keep_the_token_out(): void {
		$dns = new CloudflareAliasDns( self::ZONE, 'CF-SECRET' );
		$this->respond_with(
			static function ( string $url, array $args ) {
				$body = 'GET' === $args['method']
					? '{"success":true,"result":[{"id":"aaaabbbbccccddddeeeeffff00001111","name":"backup1.example.test","type":"CNAME","content":"U1.host.test","proxied":false},{"id":"x","name":"other.example.test","type":"A","content":"1"}]}'
					: ( str_contains( $url, 'fail' ) ? '' : '{"success":true,"result":{"id":"aaaabbbbccccddddeeeeffff00001111","name":"backup1.example.test","type":"CNAME","content":"u1.host.test","proxied":false}}' );
				$code = 'DELETE' === $args['method'] && str_contains( $url, 'bad' ) ? 403 : 200;

				return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => 'x' ), 'cookies' => array(), 'filename' => null );
			}
		);
		$found = $dns->find( 'backup1.example.test' );
		$this->assertCount( 1, $found, 'Only the record of the asked name is kept.' );
		$this->assertSame( 'u1.host.test', $found[0]['content'] );
		$this->assertSame( 'GET', $this->http_calls()[0]['args']['method'] );
		$this->assertStringStartsWith( 'https://api.cloudflare.com/client/v4/zones/' . self::ZONE . '/dns_records?', $this->http_calls()[0]['url'] );
		$dns->create( 'Backup1.example.test', 'U1.host.test.' );
		$call = $this->http_calls()[1];
		$this->assertSame( 'POST', $call['args']['method'] );
		$body = json_decode( $call['args']['body'], true );
		$this->assertFalse( $body['proxied'], 'proxied is false: SSH cannot pass the proxy.' );
		$this->assertSame( 'CNAME', $body['type'] );
		$this->assertSame( 'backup1.example.test', $body['name'] );
		$this->assertSame( 'u1.host.test', $body['content'] );
		$this->assertSame( 300, $body['ttl'] );
		$this->assertSame( 'Bearer CF-SECRET', $call['args']['headers']['Authorization'] );
		$dns->update( 'aaaabbbbccccddddeeeeffff00001111', 'backup1.example.test', 'u2.host.test' );
		$this->assertSame( 'PUT', $this->http_calls()[2]['args']['method'] );
		$this->assertFalse( json_decode( $this->http_calls()[2]['args']['body'], true )['proxied'] );
		$this->assertStringEndsWith( '/dns_records/aaaabbbbccccddddeeeeffff00001111', $this->http_calls()[2]['url'] );
		$this->respond_with( static fn() => array( 'headers' => array(), 'body' => '{"success":false,"errors":[{"message":"bad CF-SECRET"}]}', 'response' => array( 'code' => 403, 'message' => 'x' ), 'cookies' => array(), 'filename' => null ) );
		$error = $dns->delete( 'aaaabbbbccccddddeeeeffff00001111' );
		$this->assertTrue( $error instanceof \WP_Error );
		$this->assertStringNotContainsString( 'CF-SECRET', $error->get_error_message() );
		$this->assertStringNotContainsString( 'CF-SECRET', (string) wp_json_encode( $error ) );
		$this->expectException( \InvalidArgumentException::class );
		new CloudflareAliasDns( 'not-a-zone', 'T' );
	}

	public function test_a_record_id_cannot_change_the_request_path(): void {
		$dns = new CloudflareAliasDns( self::ZONE, 'T' );
		$this->respond_with( static fn() => array( 'headers' => array(), 'body' => '{"success":true,"result":[]}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ) );
		$dns->delete( '../../zones/abc' );
		$this->assertStringNotContainsString( '..', $this->http_calls()[0]['url'] );
		$this->assertMatchesRegularExpression( '#/dns_records/[a-f0-9]*$#', $this->http_calls()[0]['url'] );
	}

	public function test_the_cloudflare_token_is_saved_encrypted_and_not_printed(): void {
		$clean = Settings::sanitize( array( 'storage_cf_token' => 'CF-TOKEN-VALUE' ) );
		$this->assertStringStartsWith( 'bs1:', $clean['storage_cf_token'] );
		update_option( Settings::OPTION, $clean );
		$this->assertSame( 'CF-TOKEN-VALUE', \BeltoftServices\Storage\StorageSettings::cf_token() );
		ob_start();
		Settings::render_field( array( 'key' => 'storage_cf_token' ) );
		$this->assertStringNotContainsString( 'CF-TOKEN-VALUE', (string) ob_get_clean() );
	}

	public function test_boxes_get_running_numbers_that_stay(): void {
		delete_option( HostAlias::NEXT_OPTION );
		$a = $this->storage_resource();
		$b = $this->storage_resource();

		$this->assertSame( 1, HostAlias::number( $a ) );
		$this->assertSame( 2, HostAlias::number( $b ) );
		$this->assertSame( 1, HostAlias::number( $a ), 'A box keeps its number.' );
		$this->assertSame( 1, (int) \BeltoftServices\Resource::find( $a->id() )->get( 'storage_alias_number' ) );
	}
}

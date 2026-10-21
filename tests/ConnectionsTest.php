<?php
/**
 * HTTP connections.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Crypto;
use BeltoftServices\Http\Connections;
use BeltoftServices\Http\ConnectionsScreen;
use BeltoftServices\Settings;

/**
 * Validation, secrets and the filter of the connections.
 */
class ConnectionsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Connections::OPTION );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Connections::OPTION );

		parent::tear_down();
	}

	/**
	 * A valid form submission.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function input( array $over = array() ): array {
		return array_merge(
			array(
				'label'     => 'Service',
				'base_url'  => 'https://api.example.com/',
				'auth'      => 'hmac',
				'secret'    => 'hunter2',
				'timeout'   => '15',
				'endpoints' => array(
					'provision' => array( 'method' => 'post', 'path' => '/tenants' ),
					'pause'     => array( 'method' => 'POST', 'path' => '/tenants/{remote_id}/pause' ),
				),
				'body_template' => '{"email":"{email}","limit":{limit},"plan":"{plan}"}',
			),
			$over
		);
	}

	public function test_save_validates_url_and_endpoints(): void {
		$this->assertTrue( Connections::save( 's', $this->input() ) );
		$saved = Connections::get( 's' );
		$this->assertSame( 'https://api.example.com', $saved['base_url'] );
		$this->assertSame( 15, $saved['timeout'] );
		$this->assertSame( 'POST', $saved['endpoints']['provision']['method'] );
		$this->assertSame( '/tenants/{remote_id}/pause', $saved['endpoints']['pause']['path'] );
		$this->assertSame( 'X-Beltoft-Timestamp', $saved['header_timestamp'] );
		$this->assertSame( 'id', $saved['remote_id_field'] );

		$bad = array(
			'http to a public host'  => array( 'base_url' => 'http://api.example.com' ),
			'not a url'              => array( 'base_url' => 'api.example.com' ),
			'ftp'                    => array( 'base_url' => 'ftp://api.example.com' ),
			'no label'               => array( 'label' => '' ),
			'bad auth'               => array( 'auth' => 'basic' ),
			'bad timeout'            => array( 'timeout' => '0' ),
			'bad method'             => array( 'endpoints' => array( 'provision' => array( 'method' => 'TRACE', 'path' => '/x' ) ) ),
			'path without slash'     => array( 'endpoints' => array( 'provision' => array( 'method' => 'POST', 'path' => 'x' ) ) ),
			'absolute path'          => array( 'endpoints' => array( 'provision' => array( 'method' => 'POST', 'path' => '//evil.test/x' ) ) ),
			'unknown path token'     => array( 'endpoints' => array( 'provision' => array( 'method' => 'POST', 'path' => '/x/{nope}' ) ) ),
			'unknown body token'     => array( 'body_template' => '{"a":"{nope}"}' ),
			'body not json'          => array( 'body_template' => '{"a":' ),
			'bad header name'        => array( 'header_tenant' => 'X Tenant' ),
			'bad remote id field'    => array( 'remote_id_field' => 'data..id' ),
			'userinfo https'         => array( 'base_url' => 'https://user:pw@api.example.com' ),
			'userinfo trick'         => array( 'base_url' => 'http://127.0.0.1@evil.com' ),
			'decimal ip over http'   => array( 'base_url' => 'http://2130706433' ),
			'octal ip over http'     => array( 'base_url' => 'http://0177.0.0.1' ),
			'fragment trick'         => array( 'base_url' => 'http://evil.com#@127.0.0.1' ),
			'fragment on loopback'   => array( 'base_url' => 'http://localhost#@evil.com' ),
			'backslash trick'        => array( 'base_url' => 'http://127.0.0.1\\@evil.com' ),
			'backslash trick 2'      => array( 'base_url' => 'http://evil.com\\@127.0.0.1' ),
			'open url without https' => array( 'open_url' => 'http://portal.example.com/?t={token}' ),
		);

		foreach ( $bad as $why => $over ) {
			$this->assertInstanceOf( \WP_Error::class, Connections::save( 'bad', $this->input( $over ) ), $why );
		}
		$this->assertNull( Connections::get( 'bad' ) );
	}

	public function test_http_is_allowed_for_private_and_loopback_hosts_only(): void {
		foreach ( array( 'http://127.0.0.1', 'http://10.1.2.3', 'http://172.16.0.9', 'http://172.31.255.1', 'http://192.168.1.5/api', 'http://[::1]:9000', 'http://[fd12:3456::1]', 'http://[::ffff:127.0.0.1]', 'https://localhost', 'https://localhost:8443' ) as $url ) {
			$this->assertTrue( Connections::save( 'p', $this->input( array( 'base_url' => $url ) ) ), $url );
		}
		foreach ( array( 'http://172.32.0.1', 'http://172.15.0.1', 'http://192.169.1.1', 'http://11.0.0.1', 'http://8.8.8.8', 'http://[2001:db8::1]', 'http://[::ffff:8.8.8.8]', 'http://localhost.example.com', 'http://localhost:8080', 'HTTP://LOCALHOST' ) as $url ) {
			$this->assertInstanceOf( \WP_Error::class, Connections::save( 'p', $this->input( array( 'base_url' => $url ) ) ), $url );
		}
	}

	public function test_secret_is_encrypted_at_rest_and_never_echoed(): void {
		$this->assertTrue( Connections::save( 's', $this->input() ) );

		$raw = (string) wp_json_encode( get_option( Connections::OPTION ) );
		$this->assertStringNotContainsString( 'hunter2', $raw );
		$this->assertStringStartsWith( 'bs1:', Connections::all()['s']['secret'] );
		$this->assertSame( 'hunter2', Crypto::decrypt( Connections::all()['s']['secret'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array( 'edit' => 's' );
		ob_start();
		ConnectionsScreen::render();
		$form = ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'name="secret"', $form );
		$this->assertStringContainsString( 'type="password"', $form );
		$this->assertStringNotContainsString( 'hunter2', $form );
		$this->assertStringNotContainsString( 'bs1:', $form );

		ob_start();
		ConnectionsScreen::render();
		$list = ob_get_clean();
		$this->assertStringContainsString( 'Service', $list );
		$this->assertStringNotContainsString( 'bs1:', $list );
	}

	public function test_empty_secret_keeps_the_saved_one(): void {
		$this->assertTrue( Connections::save( 's', $this->input() ) );
		$before = Connections::all()['s']['secret'];

		$this->assertTrue( Connections::save( 's', $this->input( array( 'secret' => '', 'label' => 'Renamed' ) ) ) );
		$this->assertSame( $before, Connections::all()['s']['secret'] );
		$this->assertSame( 'hunter2', Connections::get( 's' )['secret'] );
		$this->assertSame( 'Renamed', Connections::get( 's' )['label'] );

		$this->assertTrue( Connections::save( 's', $this->input( array( 'secret' => 'new-secret' ) ) ) );
		$this->assertSame( 'new-secret', Connections::get( 's' )['secret'] );
	}

	public function test_secret_is_required_and_an_empty_field_keeps_the_saved_one(): void {
		$this->assertTrue( Connections::save( 's', $this->input() ) );

		foreach ( array( 'hmac', 'header' ) as $auth ) {
			$over = array( 'secret' => '', 'auth' => $auth, 'header_static' => 'X-Api-Key' );
			$this->assertTrue( Connections::save( 's', $over + $this->input() ), $auth );
			$this->assertSame( 'hunter2', Connections::get( 's' )['secret'], 'An empty field keeps the saved secret.' );
		}

		// There is no way to clear a secret: a stray remove_secret field changes nothing.
		$this->assertTrue( Connections::save( 's', $this->input( array( 'secret' => '', 'remove_secret' => '1' ) ) ) );
		$this->assertSame( 'hunter2', Connections::get( 's' )['secret'] );

		$this->assertTrue( Connections::save( 's', $this->input( array( 'secret' => 'fresh' ) ) ) );
		$this->assertSame( 'fresh', Connections::get( 's' )['secret'] );

		$this->assertInstanceOf( \WP_Error::class, Connections::save( 'new', $this->input( array( 'secret' => '' ) ) ), 'no secret at all' );
	}

	public function test_header_auth_needs_a_header_name_and_a_secret(): void {
		$header = array( 'auth' => 'header', 'header_static' => 'X-Api-Key' );

		$this->assertTrue( Connections::save( 'h', $this->input( $header ) ) );
		$this->assertSame( 'X-Api-Key', Connections::get( 'h' )['header_static'] );
		$this->assertSame( 'hunter2', Connections::get( 'h' )['secret'] );

		$this->assertInstanceOf( \WP_Error::class, Connections::save( 'h2', $this->input( array( 'auth' => 'header' ) ) ), 'no header name' );
		$this->assertInstanceOf( \WP_Error::class, Connections::save( 'h2', $this->input( array( 'auth' => 'header', 'header_static' => 'X Key: v' ) ) ), 'bad name' );
		$this->assertInstanceOf( \WP_Error::class, Connections::save( 'h2', $this->input( $header + array( 'secret' => '' ) ) ), 'no secret' );
		$this->assertInstanceOf( \WP_Error::class, Connections::save( 'h2', $this->input( array( 'header_static' => str_repeat( 'a', 65 ) ) ) ), 'too long' );
	}

	public function test_secret_is_refused_without_a_key(): void {
		remove_all_filters( 'beltoft_services_key' );

		$result = Connections::save( 's', $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Add BELTOFT_SERVICES_KEY to wp-config.php before saving secrets.', $result->get_error_message() );
		$this->assertNull( Connections::get( 's' ) );
	}

	public function test_connection_filter_returns_decrypted_settings(): void {
		$this->assertTrue( Connections::save( 's', $this->input() ) );

		$found = apply_filters( 'beltoft_services_connection', null, 's' );
		$this->assertSame( 'hunter2', $found['secret'] );
		$this->assertSame( 'https://api.example.com', $found['base_url'] );
		$this->assertNull( apply_filters( 'beltoft_services_connection', null, 'missing' ) );
	}

	public function test_delete_removes_a_connection(): void {
		Connections::save( 's', $this->input() );
		$this->assertTrue( Connections::delete( 's' ) );
		$this->assertFalse( Connections::delete( 's' ) );
		$this->assertSame( array(), Connections::all() );
	}

	public function test_tab_is_listed_and_handlers_refuse_non_admins(): void {
		$this->assertArrayHasKey( 'connections', Settings::tabs() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array( 'label' => 'X' );

		$this->expectException( \WPDieException::class );
		ConnectionsScreen::handle_save();
	}

	public function test_handlers_need_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array( 'connection_id' => 's' );
		Connections::save( 's', $this->input() );

		try {
			ConnectionsScreen::handle_delete();
			$this->fail( 'A request without a nonce must die.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotNull( Connections::get( 's' ) );
		}
	}

	public function test_uninstall_deletes_connections_when_delete_data_is_on(): void {
		$this->assertStringContainsString( "delete_option( 'beltoft_services_connections' )", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'uninstall.php' ) );
	}

	/**
	 * Runs handle_save() as an administrator with a nonce; returns the redirect target.
	 *
	 * @param array<string, mixed> $post Posted values.
	 */
	private function post_save( array $post ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST    = wp_slash( $post + array( '_wpnonce' => wp_create_nonce( 'beltoft_services_save_connection' ) ) );
		$_REQUEST = $_POST;
		$target   = '';

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		);

		try {
			ConnectionsScreen::handle_save();
		} catch ( \RuntimeException $e ) {
			$target = $e->getMessage();
		}

		remove_all_filters( 'wp_redirect' );
		$_POST    = array();
		$_REQUEST = array();

		return $target;
	}

	public function test_save_handler_adds_with_an_id_from_the_label_and_refuses_duplicates(): void {
		$this->assertStringNotContainsString( 'action=new', $this->post_save( $this->input( array( 'label' => 'My Service' ) ) ) );
		$this->assertArrayHasKey( 'my-service', Connections::all() );

		$again = $this->post_save( $this->input( array( 'label' => 'Other', 'new_id' => 'my-service' ) ) );
		$this->assertStringContainsString( 'action=new', $again );
		$this->assertSame( 'My Service', Connections::get( 'my-service' )['label'] );
		$this->assertCount( 1, Connections::all() );
	}

	public function test_save_handler_never_makes_an_empty_id(): void {
		foreach ( array( '日本語', '!!!', 'Привет' ) as $label ) {
			$this->post_save( $this->input( array( 'label' => $label ) ) );
		}

		$ids = array_keys( Connections::all() );
		$this->assertCount( 3, $ids );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', (string) $id );
			$this->assertNotSame( '', (string) $id );
		}
	}

	public function test_save_handler_edit_keeps_the_id_and_refuses_unknown_ids(): void {
		Connections::save( 'keep', $this->input() );

		$this->post_save( $this->input( array( 'connection_id' => 'keep', 'label' => 'Edited', 'secret' => '' ) ) );
		$this->assertSame( array( 'keep' ), array_keys( Connections::all() ) );
		$this->assertSame( 'Edited', Connections::get( 'keep' )['label'] );

		$target = $this->post_save( $this->input( array( 'connection_id' => 'gone', 'label' => 'Ghost' ) ) );
		$this->assertNotSame( '', $target );
		$this->assertArrayNotHasKey( 'gone', Connections::all() );
		$this->assertArrayNotHasKey( 'ghost', Connections::all() );
		$this->assertSame( array( 'keep' ), array_keys( Connections::all() ) );
	}
}

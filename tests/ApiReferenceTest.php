<?php
/**
 * The public API reference page and the Security panel's link to it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Account\ApiTokens as Panel;
use BeltoftServices\Api\OpenApi;
use BeltoftServices\Api\Reference;
use BeltoftServices\Mcp\Tools;

/**
 * Tests.
 */
final class ApiReferenceTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_the_shortcode_is_registered_and_prints_the_reference(): void {
		$this->assertTrue( shortcode_exists( Reference::SHORTCODE ) );

		$html = do_shortcode( '[' . Reference::SHORTCODE . ']' );

		$this->assertStringContainsString( 'beltoft-services-apiref', $html );
		$this->assertStringContainsString( 'openapi.json', $html );
		$this->assertStringContainsString( 'HTTP Basic', $html );
		$this->assertTrue( wp_style_is( Reference::HANDLE, 'enqueued' ), 'The stylesheet loads with the shortcode.' );
	}

	public function test_every_openapi_path_and_method_is_listed(): void {
		$html = Reference::html();
		$doc  = json_decode( (string) wp_json_encode( OpenApi::document() ), true );
		$n    = 0;

		foreach ( $doc['paths'] as $path => $methods ) {
			foreach ( $methods as $method => $op ) {
				$this->assertStringContainsString( '<code>' . esc_html( $path ) . '</code>', $html, $path );
				$this->assertStringContainsString( esc_html( $op['summary'] ), $html, $path );
				$this->assertStringContainsString( '>' . strtoupper( $method ) . '<', $html );
				++$n;
			}
		}

		$this->assertGreaterThanOrEqual( 10, $n );
		$this->assertStringContainsString( '429', $html, 'Errors.' );
		$this->assertStringContainsString( 'Rate limit: 60 requests', $html );
	}

	public function test_the_parameters_and_reply_fields_are_listed(): void {
		$html = Reference::html();

		$this->assertStringContainsString( '<code>records</code>', $html, 'A body field.' );
		$this->assertStringContainsString( '<code>last_result</code>', $html, 'A reply field.' );
		$this->assertStringContainsString( '<code>id</code>', $html, 'A path parameter.' );
	}

	public function test_every_tool_is_listed_with_its_level(): void {
		$html = Reference::html();

		foreach ( Tools::definitions() as $name => $tool ) {
			$this->assertStringContainsString( '<code>' . esc_html( $name ) . '</code>', $html, $name );
		}

		$this->assertStringContainsString( esc_html( \BeltoftServices\Mcp\Endpoint::url() ), $html, 'The connector address.' );
		$this->assertStringContainsString( 'Add custom connector', $html );
		$this->assertStringContainsString( 'Domain transfers are never possible through Claude', $html );
		$this->assertStringContainsString( 'Use with Claude (MCP)', $html );
	}

	public function test_the_mcp_section_is_left_out_when_the_switch_is_off(): void {
		$this->settings( array( 'mcp_enabled' => 0 ) );

		$html = Reference::html();

		$this->assertStringNotContainsString( 'Add custom connector', $html );
		$this->assertStringContainsString( 'HTTP Basic', $html );
	}

	public function test_secret_routes_are_not_documented(): void {
		$html = Reference::html();

		foreach ( array( '/open', 'db-ca', 'auth-code', 'reveal', 'console', 'password' ) as $needle ) {
			$this->assertStringNotContainsString( '<code>/resources/{id}/' . $needle, $html, $needle );
		}

		$this->assertStringNotContainsString( 'BELTOFT_SERVICES_KEY', $html );
	}

	public function test_everything_is_escaped(): void {
		add_filter( 'bloginfo', static fn( $value, $show ) => 'name' === $show ? '<script>alert(1)</script>' : $value, 10, 2 );
		add_filter(
			'beltoft_services_token_rate_limit',
			static fn() => 5
		);

		$html = Reference::html();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Rate limit: 5 requests', $html );
	}

	public function test_no_em_dash_and_no_rounded_corners(): void {
		$this->assertStringNotContainsString( "\u{2014}", Reference::html() );
		$this->assertStringNotContainsString( 'border-radius', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/api-reference.css' ) );
		$this->assertStringNotContainsString( "\u{2014}", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/api-reference.php' ) );
	}

	public function test_the_setting_exists_and_keeps_only_a_url(): void {
		$this->assertArrayHasKey( 'api_docs_url', \BeltoftServices\Settings::fields() );

		$clean = \BeltoftServices\Settings::sanitize( array( 'api_docs_url' => 'javascript:alert(1)' ) );
		$this->assertStringNotContainsString( 'javascript', (string) $clean['api_docs_url'] );

		$clean = \BeltoftServices\Settings::sanitize( array( 'api_docs_url' => 'https://example.com/api/' ) );
		$this->assertSame( 'https://example.com/api/', $clean['api_docs_url'] );
	}

	private function panel(): string {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		ob_start();
		Panel::render();

		return (string) ob_get_clean();
	}

	public function test_the_security_panel_links_to_the_docs_when_the_setting_is_set(): void {
		$this->settings( array( 'api_docs_url' => 'https://example.com/api/' ) );

		$html = $this->panel();

		$this->assertStringContainsString( 'href="https://example.com/api/"', $html );
		$this->assertStringNotContainsString( 'curl -u', $html, 'The long block is gone.' );
		$this->assertStringNotContainsString( 'beltoft-services-api__scroll', $html );
		$this->assertStringContainsString( 'id="api-tokens"', $html, 'Token management stays.' );
		$this->assertStringContainsString( 'Create token', $html );
	}

	public function test_the_security_panel_keeps_the_guide_when_the_setting_is_empty(): void {
		$this->settings( array( 'api_docs_url' => '' ) );

		$html = $this->panel();

		$this->assertStringContainsString( 'curl -u', $html );
		$this->assertStringContainsString( 'How to use the API', $html );
		$this->assertStringNotContainsString( 'Read the API documentation', $html );
	}
}

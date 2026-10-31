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
		$this->assertTrue( wp_script_is( Reference::HANDLE, 'enqueued' ), 'The script loads with the shortcode.' );
		$this->assertStringNotContainsString( '<h1', $html, 'The page heading comes from the page.' );
	}

	public function test_endpoints_are_grouped_by_resource_with_anchors_and_navigation(): void {
		$html   = Reference::html();
		$groups = Reference::groups( Reference::operations() );

		$this->assertArrayHasKey( 'services', $groups );
		$this->assertArrayHasKey( 'dns', $groups );
		$this->assertArrayHasKey( 'domains', $groups );
		$this->assertArrayHasKey( 'usage', $groups );

		$count = 0;

		foreach ( $groups as $group ) {
			$this->assertNotSame( '', $group['title'] );
			$this->assertStringContainsString( 'id="' . $group['id'] . '"', $html );
			$this->assertStringContainsString( 'href="#' . $group['id'] . '"', $html, 'Navigation link.' );

			foreach ( $group['ops'] as $op ) {
				$this->assertStringContainsString( 'id="' . $op['id'] . '"', $html );
				$this->assertStringContainsString( 'href="#' . $op['id'] . '"', $html );
				++$count;
			}
		}

		$this->assertCount( count( Reference::operations() ), array_merge( ...array_column( $groups, 'ops' ) ) );
		$this->assertGreaterThanOrEqual( 10, $count );
		$this->assertStringContainsString( 'href="#api-authentication"', $html );
		$this->assertStringContainsString( 'href="#api-errors"', $html );
		$this->assertSame( 'dns', array_key_first( array_filter( $groups, static fn( $g ) => 'DNS' === $g['title'] ) ) );
	}

	public function test_each_endpoint_has_a_curl_example_a_reply_and_copy_buttons(): void {
		$html = Reference::html();

		$this->assertStringContainsString( 'data-apiref-copy', $html );
		$this->assertStringContainsString( 'aria-label="Copy the curl example for GET /resources"', $html );
		$this->assertStringContainsString( 'curl -u &quot;USERNAME:TOKEN&quot;', $html );
		$this->assertStringContainsString( esc_html( untrailingslashit( rest_url( 'beltoft-services/v1' ) ) . '/resources/123/dns-records/A/www' ), $html, 'Path parameters get example values.' );
		$this->assertStringContainsString( 'Example reply, 200', $html );

		foreach ( Reference::groups( Reference::operations() ) as $group ) {
			foreach ( $group['ops'] as $op ) {
				$this->assertStringStartsWith( 'curl', $op['curl'] );
				$this->assertStringNotContainsString( '{', str_replace( '-d \'{', '', (string) strstr( $op['curl'], 'http' ) ), $op['path'] );

				if ( $op['reply_schema'] ) {
					$this->assertIsArray( json_decode( $op['sample'], true ), $op['path'] );
				}
			}
		}
	}

	public function test_endpoints_name_the_claude_tool_that_does_the_same(): void {
		$tools = Reference::tools_for( 'GET', '/resources' );
		$this->assertSame( array( 'list_services' ), $tools );
		$this->assertContains( 'list_dns_records', Reference::tools_for( 'GET', '/resources/{id}/dns-records' ) );
		$this->assertContains( 'dns_upsert_record', Reference::tools_for( 'PUT', '/resources/{id}/dns-records/{type}/{name}' ) );
		$this->assertContains( 'reboot', Reference::tools_for( 'POST', '/resources/{id}/actions/{action}' ) );
		$this->assertSame( array(), Reference::tools_for( 'GET', '/openapi.json' ) );

		$this->assertStringContainsString( 'With Claude:', Reference::html() );
		$this->assertStringContainsString( '<span class="beltoft-services-apiref__tool">list_services</span>', Reference::html() );
	}

	public function test_curl_is_split_over_lines_and_empty_columns_are_left_out(): void {
		$groups = Reference::groups( Reference::operations() );
		$put    = null;

		foreach ( $groups['dns']['ops'] as $op ) {
			if ( 'PUT' === $op['method'] ) {
				$put = $op;
			}
		}

		$this->assertStringContainsString( " \\\n  -X PUT \\\n  -H ", $put['curl'] );
		$this->assertMatchesRegularExpression( "/ \\\\\n  -d '/", $put['curl'] );
		$this->assertMatchesRegularExpression( "/ \\\\\n  'http/", $put['curl'] );

		$html = Reference::html();
		$this->assertStringContainsString( 'title="GET /resources"', $html, 'Full path in the navigation.' );
		$this->assertStringContainsString( 'font-family: ui-monospace', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/api-reference.css' ) );
	}

	public function test_the_tools_are_grouped_by_level(): void {
		$html = Reference::html();

		$this->assertStringContainsString( '<caption>Read only</caption>', $html );
		$this->assertStringContainsString( '<caption>Everyday actions</caption>', $html );
	}

	public function test_every_openapi_path_and_method_is_listed(): void {
		$html = Reference::html();
		$doc  = json_decode( (string) wp_json_encode( OpenApi::document() ), true );
		$n    = 0;

		foreach ( $doc['paths'] as $path => $methods ) {
			foreach ( $methods as $method => $op ) {
				$this->assertStringContainsString( 'data-path="' . esc_attr( $path ) . '"', $html, $path );
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
		$this->assertStringContainsString( '<code class="beltoft-services-apiref__param">id</code>', $html, 'A path parameter.' );
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
		foreach ( array( 'templates/api-reference.php', 'assets/api-reference.js', 'assets/api-reference.css', 'src/Api/Reference.php' ) as $file ) {
			$this->assertStringNotContainsString( "\u{2014}", (string) file_get_contents( BELTOFT_SERVICES_PATH . $file ), $file );
		}

		foreach ( array( 'box-shadow', 'gradient', 'animation', 'transition' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/api-reference.css' ), $needle );
		}
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

<?php
/**
 * Low review findings of the scripts and the CLI (round 3, builder rf3l3). The scripts have no JS runner here, so these look at the source.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

/**
 * ASSETS-xx tests.
 */
final class ReviewLow3l3AssetsTest extends TestCase {

	private function js( string $name ): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/' . $name );
	}

	public function test_assets_11_domain_search_reads_an_error_page_without_a_parse_error(): void {
		$code = $this->js( 'domain-search.js' );

		$this->assertSame( 0, substr_count( $code, 'await response.json();' ), 'Every read of the answer survives a body that is not JSON.' );
		$this->assertSame( 2, substr_count( $code, 'response.json().catch(' ) );
	}

	public function test_assets_04_the_status_poll_is_taken_up_again_after_a_dropped_request_or_a_server_error(): void {
		$code = $this->js( 'account.js' );

		$this->assertStringContainsString( 'result.status >= 500', $code );
		$this->assertStringNotContainsString( '.catch( function () {} );', substr( $code, (int) strpos( $code, 'function poll(' ), (int) strpos( $code, 'function settle(' ) - (int) strpos( $code, 'function poll(' ) ), 'The poll does not swallow a rejection.' );
	}

	public function test_assets_03_the_network_tab_gives_the_service_back_after_a_failed_request(): void {
		$code = $this->js( 'network-tab.js' );

		$this->assertSame( 2, substr_count( $code, '.catch(' ), 'The post and the wait both have a rejection path.' );
		$this->assertStringContainsString( 'rounds > 150', $code );
	}

	public function test_assets_05_translated_labels_are_not_matched_against_english_text(): void {
		$this->assertStringNotContainsString( '/^Current: /', $this->js( 'app-reinstall.js' ) );
		$this->assertStringContainsString( 'data-current', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Apps/AppReinstall.php' ) );

		foreach ( array( 'api-reference.js', 'api-reference-full.js' ) as $name ) {
			$this->assertStringContainsString( "getAttribute( 'data-copied' )", $this->js( $name ) );
		}

		foreach ( array( 'api-reference.php', 'api-reference-full.php' ) as $name ) {
			$this->assertStringContainsString( 'data-copied=', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/' . $name ) );
		}
	}
}

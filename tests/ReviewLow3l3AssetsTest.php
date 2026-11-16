<?php
/**
 * Low review findings of the scripts and the CLI (round 3, builder rf3l3). The scripts have no JS runner here, so these look at the source.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

require_once __DIR__ . '/Support/WpCliStub.php';

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

	private function fails( callable $fn ): string {
		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected an error.' );
	}

	public function test_assets_01_ssl_order_retry_and_renew_retry_need_yes(): void {
		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = \BeltoftServices\Resource::create( $license, new \BeltoftServices\Ssl\SslDriver(), array(), array( 'ssl_name' => 'example.com', 'ssl_post_attempted' => time() ) );
		$this->assertInstanceOf( \BeltoftServices\Resource::class, $resource );
		$cmd = new \BeltoftServices\Cli\SslCommand();

		$message = $this->fails( fn() => $cmd->order_retry( array( (string) $resource->id() ), array() ) );
		$this->assertStringContainsString( '--yes', $message );
		$this->assertNotNull( \BeltoftServices\Resource::find( $resource->id() )->get( 'ssl_post_attempted' ), 'The marker stays.' );

		$message = $this->fails( fn() => $cmd->renew_retry( array( (string) $resource->id(), '123' ), array() ) );
		$this->assertStringContainsString( '--yes', $message );

		$cmd->order_retry( array( (string) $resource->id() ), array( 'yes' => true ) );
		$this->assertNull( \BeltoftServices\Resource::find( $resource->id() )->get( 'ssl_post_attempted' ) );
	}

	public function test_assets_10_quarantine_lift_needs_yes_and_names_the_server(): void {
		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = \BeltoftServices\Resource::create( $license, $this->fake_driver(), array(), array( 'server_name' => 'web-quarantined' ) );
		$this->assertInstanceOf( \BeltoftServices\Resource::class, $resource );

		$message = $this->fails( fn() => ( new \BeltoftServices\Cli\Commands() )->quarantine_lift( array( (string) $license['id'] ), array() ) );

		$this->assertStringContainsString( 'web-quarantined', $message );
		$this->assertStringContainsString( '--yes', $message );
	}
}

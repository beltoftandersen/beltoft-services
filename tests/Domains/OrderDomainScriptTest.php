<?php
/**
 * The checkout script of the domain tiles, run in node against small browser stubs (skipped where node is missing).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\OrderDomain;
use BeltoftServices\Tests\TestCase;

/**
 * assets/order-domain.js.
 */
class OrderDomainScriptTest extends TestCase {

	/** @return array<string, mixed> What the harness printed for a scenario. */
	private function run_script( string $scenario ): array {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed.' );
		}

		$dir = dirname( __DIR__, 2 );
		$out = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $dir . '/tests/fixtures/order-domain-harness.js' ) . ' ' . escapeshellarg( $dir . '/assets/order-domain.js' ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1' );
		$data = json_decode( (string) $out, true );
		$this->assertIsArray( $data, (string) $out );

		return $data;
	}

	public function test_a_missing_phone_row_reloads_the_page_once_per_cart_then_says_what_to_do(): void {
		$data = $this->run_script( 'phone' );
		$this->assertSame( 1, $data['reloads'], 'One reload however many refreshes follow.' );
		$this->assertSame( 1, $data['notes'], 'The note is shown once.' );
	}

	public function test_another_cart_may_reload_once_more(): void {
		$this->assertSame( 2, $this->run_script( 'phone-new-cart' )['reloads'] );
	}

	public function test_without_session_storage_a_url_marker_allows_one_reload(): void {
		$data = $this->run_script( 'storage-throws' );
		$this->assertStringContainsString( 'od_reload=1', $data['assigns'][0] );
		$data = $this->run_script( 'od-param' );
		$this->assertSame( array(), $data['assigns'], 'Back with the marker: no second reload.' );
		$this->assertSame( 1, $data['notes'] );
	}

	public function test_a_slow_checkout_reads_the_cart_once_more_before_an_error(): void {
		$data = $this->run_script( 'slow-third' );
		$this->assertSame( 'ok', $data['result'] );
		$this->assertSame( 2, $data['adds'] );
	}

	public function test_a_domain_that_shows_up_after_the_error_is_shown_as_added(): void {
		$data = $this->run_script( 'slow' );
		$this->assertSame( 'NOT IN ORDER', $data['result'] );
		$this->assertSame( 1, $data['late'] );
		$this->assertSame( 0, $this->run_script( 'slow-never' )['late'] );
	}

	public function test_the_phone_text_exists(): void {
		$this->assertSame( 'Add your phone number in the billing details.', OrderDomain::texts()['phoneMissing'] );
	}
}

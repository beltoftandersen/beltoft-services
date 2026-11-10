<?php
/**
 * Reading a classic checkout field.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Checkout;

use BeltoftServices\Checkout\ClassicPost;
use BeltoftServices\Tests\TestCase;

/**
 * The posted value is read only inside the checkout run.
 */
class ClassicPostTest extends TestCase {

	public function tear_down(): void {
		unset( $_POST['beltoft_test_field'] );
		parent::tear_down();
	}

	public function test_nothing_outside_the_checkout_run_and_the_fallback_inside_it(): void {
		$_POST['beltoft_test_field'] = '  posted ';

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( ! did_action( 'woocommerce_checkout_process' ) ) {
			$this->assertSame( '', ClassicPost::raw( 'beltoft_test_field', 'fallback' ) );
		}

		do_action( 'woocommerce_checkout_process' );

		$this->assertSame( '  posted ', ClassicPost::raw( 'beltoft_test_field', 'fallback' ), 'Not trimmed: callers do that.' );

		$_POST['beltoft_test_field'] = '';
		$this->assertSame( '', ClassicPost::raw( 'beltoft_test_field', 'fallback' ), 'An empty post wins.' );

		unset( $_POST['beltoft_test_field'] );
		$this->assertSame( 'fallback', ClassicPost::raw( 'beltoft_test_field', 'fallback' ) );

		$_POST['beltoft_test_field'] = array( 'x' );
		$this->assertSame( '', ClassicPost::raw( 'beltoft_test_field', 'fallback' ), 'A non-string is nothing.' );
	}
}

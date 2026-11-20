<?php
/** Priority support: one per customer, the flag follows the service, staff sees it. @package BeltoftServices */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Support\Priority;
use BeltoftServices\Support\PriorityDriver;
use BeltoftServices\Tests\TestCase;

class PriorityTest extends TestCase {

	/** A priority support service for a customer, run to the end of its build. */
	private function service( int $user_id = 0 ): Resource {
		$product = $this->service_product( PriorityDriver::ID, array(), array( 'slug' => 'prio-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false ) + ( $user_id > 0 ? array( 'user_id' => $user_id ) : array() ) );
		$r       = Resource::create( $license, Registry::get( PriorityDriver::ID ), Product::plan( $product->get_id() ), array() );
		$this->assertInstanceOf( Resource::class, $r );
		Jobs::run( $r->id() );

		return Resource::find( $r->id() );
	}

	public function test_the_driver_is_registered(): void {
		$this->assertSame( 'support', Registry::get( PriorityDriver::ID )->kind() );
	}

	public function test_a_ready_service_gives_the_customer_priority_and_the_flag(): void {
		$r    = $this->service();
		$user = (int) $r->license()['user_id'];

		$this->assertSame( 'ready', $r->get( 'state' ), (string) $r->get( 'last_error' ) );
		$this->assertTrue( Priority::has( $user ) );
		$this->assertTrue( apply_filters( 'beltoft_services_has_priority_support', false, $user ) );
		$this->assertFalse( Priority::has( $user + 9999 ) );

		Priority::sync( $user );
		$this->assertSame( '1', get_user_meta( $user, Priority::META, true ) );
	}

	public function test_the_flag_goes_away_when_the_service_ends(): void {
		$r    = $this->service();
		$user = (int) $r->license()['user_id'];
		Priority::sync( $user );
		$this->assertSame( '1', get_user_meta( $user, Priority::META, true ) );

		$r->set( array( 'state' => 'deleted' ) );
		Priority::changed( Resource::find( $r->id() ) );

		$this->assertFalse( Priority::has( $user ) );
		$this->assertSame( '', get_user_meta( $user, Priority::META, true ) );
	}

	public function test_a_second_service_is_refused_at_add_to_cart(): void {
		$r       = $this->service();
		$user    = (int) $r->license()['user_id'];
		$product = (int) $r->license()['product_id'];
		wp_set_current_user( $user );
		wc_clear_notices();

		$this->assertFalse( Priority::validate( true, $product, 1 ) );
		$this->assertStringContainsString( 'already', wp_json_encode( wc_get_notices() ) );

		wp_set_current_user( self::factory()->user->create() );
		wc_clear_notices();
		$this->assertTrue( Priority::validate( true, $product, 1 ) );

		$this->assertTrue( Priority::validate( true, 0, 1 ), 'Other products are not touched.' );
		wp_set_current_user( 0 );
	}

	public function test_the_promise_never_says_24_7_and_the_profile_shows_it_to_staff(): void {
		$this->assertStringNotContainsString( '24/7', Priority::promise() );
		$this->assertStringContainsString( '4 hours', Priority::promise() );

		$r    = $this->service();
		$user = new \WP_User( (int) $r->license()['user_id'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Priority::profile( $user );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( Priority::promise(), $html );
		wp_set_current_user( 0 );
	}
}

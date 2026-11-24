<?php
/**
 * The hosting section of the My Account overview (moved here from Account\OverviewTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Account\Overview;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * One section per area; hosting has its own.
 */
final class OverviewTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Overview::reset();
	}

	public function tear_down(): void {
		Overview::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function as_user( int $user_id ): void {
		Overview::reset();
		wp_set_current_user( $user_id );
	}

	private function hosting_account( int $user_id, string $domain ): Resource {
		$product  = $this->service_product( 'hosting', array(), array( 'slug' => 'hosting-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user_id ) );
		$resource = Registry::get( 'hosting' ) ? Resource::create( $license, Registry::get( 'hosting' ), array(), array( 'domain' => $domain ) ) : null;
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	public function test_a_customer_with_nothing_and_no_links_sees_the_empty_hosting_section(): void {
		$this->as_user( self::factory()->user->create() );

		$this->assertStringContainsString( 'ov__hosting is-empty', Overview::section( 'hosting' ) );
		$this->assertStringNotContainsString( 'list-btn', Overview::section( 'hosting' ), 'No product, no button.' );
	}

	public function test_a_hosting_account_has_its_own_overview_section(): void {
		$user = self::factory()->user->create();
		$this->as_user( $user );
		$this->hosting_account( $user, 'hosted-example.dk' );
		Overview::reset();

		$data = Overview::data();

		$this->assertCount( 1, $data['hosting'] );
		$this->assertNotSame( '', $data['hosting_url'] );
		$this->assertStringContainsString( 'ov__hosting', Overview::section( 'hosting' ) );
		$this->assertStringNotContainsString( 'is-empty', Overview::section( 'hosting' ) );
		$this->assertArrayHasKey( 'hosting', Overview::SECTIONS );
	}
}

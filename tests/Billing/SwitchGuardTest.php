<?php
/**
 * Which resources a subscription switch is about.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftLicenses\Licenses;
use BeltoftServices\Billing\SwitchGuard;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class SwitchGuardTest extends TestCase {

	private const SUBSCRIPTION = 7311;

	/**
	 * A licence of the subscription with a resource of the driver (or none).
	 *
	 * @param \WC_Product $product Product.
	 * @param string|null $driver  Driver id; null for no resource.
	 * @return int Licence id.
	 */
	private function licence_of( \WC_Product $product, ?string $driver ): int {
		$license = $this->service_license( $product, array( 'subscription_id' => self::SUBSCRIPTION, 'fire' => false ) );

		if ( null !== $driver ) {
			Resource::create( Licenses::find( (int) $license['id'] ), $this->fake_driver( $driver ), array(), array( 'state' => 'ready' ) );
		}

		return (int) $license['id'];
	}

	/**
	 * The licence ids of the resources.
	 *
	 * @param array<int, Resource> $resources Resources.
	 * @return array<int, int>
	 */
	private function ids( array $resources ): array {
		return array_map( static fn( $r ) => (int) $r->license()['id'], $resources );
	}

	private function item( int $product_id ): object {
		return new class( $product_id ) {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function get_product_id() {
				return $this->id;
			}
		};
	}

	public function test_only_the_switched_items_product_is_considered(): void {
		$a = $this->service_product( 'fake' );
		$b = $this->service_product( 'fake' );
		$one = $this->licence_of( $a, 'fake' );
		$this->licence_of( $b, 'fake' );

		$this->assertSame( array( $one ), $this->ids( SwitchGuard::resources( self::SUBSCRIPTION, $this->item( $a->get_id() ), 'fake' ) ) );
	}

	public function test_two_items_of_one_product_give_two_resources(): void {
		$a   = $this->service_product( 'fake' );
		$one = $this->licence_of( $a, 'fake' );
		$two = $this->licence_of( $a, 'fake' );

		$this->assertSame( array( $one, $two ), $this->ids( SwitchGuard::resources( self::SUBSCRIPTION, $this->item( $a->get_id() ), 'fake' ) ) );
	}

	public function test_another_drivers_resource_and_a_licence_without_a_resource_are_skipped(): void {
		$a   = $this->service_product( 'fake' );
		$one = $this->licence_of( $a, 'fake' );
		$this->licence_of( $a, 'other' );
		$this->licence_of( $a, null );

		$this->assertSame( array( $one ), $this->ids( SwitchGuard::resources( self::SUBSCRIPTION, null, 'fake' ) ) );
	}

	public function test_the_target_product_filter_narrows_further(): void {
		$a = $this->service_product( 'fake' );
		$b = $this->service_product( 'fake' );
		$this->licence_of( $a, 'fake' );
		$two = $this->licence_of( $b, 'fake' );

		$this->assertSame( array( $two ), $this->ids( SwitchGuard::resources( self::SUBSCRIPTION, null, 'fake', $b->get_id() ) ) );
		$this->assertSame( array(), $this->ids( SwitchGuard::resources( self::SUBSCRIPTION, $this->item( $a->get_id() ), 'fake', $b->get_id() ) ) );
	}

	public function test_an_item_without_a_product_method_means_every_licence(): void {
		$a   = $this->service_product( 'fake' );
		$b   = $this->service_product( 'fake' );
		$one = $this->licence_of( $a, 'fake' );
		$two = $this->licence_of( $b, 'fake' );

		$this->assertSame( 0, SwitchGuard::item_product( new \stdClass() ) );
		$this->assertSame( 0, SwitchGuard::item_product( null ) );
		$this->assertSame( array( $one, $two ), $this->ids( SwitchGuard::resources( self::SUBSCRIPTION, new \stdClass(), 'fake' ) ) );
	}
}

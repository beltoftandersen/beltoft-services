<?php
/**
 * Who holds a domain name (DOMAINS-07): one indexed lookup by name instead of a walk over every live domain resource.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Zone;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

final class ZoneHoldersTest extends TestCase {

	private function domain( string $name, array $meta = array(), ?int $user = null ): Resource {
		$product = $this->service_product( 'domain' );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ?? self::factory()->user->create() ) );

		return Resource::create( $license, new DomainDriver(), array(), $meta + array( 'domain' => $name, 'state' => 'ready' ) );
	}

	public function test_holders_finds_the_live_services_of_a_name_and_skips_failed_ones(): void {
		$mine  = $this->domain( 'held-one.example' );
		$gone  = $this->domain( 'held-one.example', array( 'state' => 'failed' ) );
		$other = $this->domain( 'held-two.example' );

		$found = Zone::holders( 'Held-One.example' );
		$ids   = array_map( static fn( $row ) => $row[1]->id(), $found );

		$this->assertSame( array( $mine->id() ), $ids );
		$this->assertSame( 'domain', $found[0][0] );
		$this->assertSame( array(), Zone::holders( 'nobody.example' ) );
		$this->assertNotContains( $gone->id(), $ids );
		$this->assertNotContains( $other->id(), $ids );
	}

	public function test_the_cart_and_the_zone_ask_for_the_holder_of_one_name_not_for_every_live_domain(): void {
		$user = self::factory()->user->create();
		$mine = $this->domain( 'cart-held.example', array(), $user );

		$this->assertSame( $user, Cart::domain_held( 'cart-held.example' ) );
		$this->assertNull( Cart::domain_held( 'cart-free.example' ) );
		$this->assertSame( 'none', Zone::owner( $this->domain( 'cart-free.example' ) ) );
		$this->assertSame( 'other', Zone::owner( $this->domain( 'cart-held.example', array(), self::factory()->user->create() ) ) );
		unset( $mine );

		$root = dirname( __DIR__, 2 ) . '/src/Domains/';
		foreach ( array( 'Cart.php', 'Zone.php', 'DnsClaimRoutes.php' ) as $file ) {
			$this->assertStringNotContainsString( "Resource::live_ids( 'domain' )", (string) file_get_contents( $root . $file ), $file );
			$this->assertStringNotContainsString( 'Resource::live_ids', (string) file_get_contents( $root . $file ), $file );
		}
	}
}

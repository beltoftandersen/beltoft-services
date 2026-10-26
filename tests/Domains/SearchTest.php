<?php
/** Public domain search tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\TestCase;

class SearchTest extends TestCase {
	public function test_check_normalises_idn_and_case(): void {
		$this->assertSame( 'xn--bcher-kva.dk', Search::normalise( 'Bücher.DK' ) );
	}

	public function test_check_refuses_invalid_labels(): void {
		foreach ( array( '-a.com', str_repeat( 'a', 64 ) . '.com', 'a..com' ) as $bad ) {
			$this->assertWPError( Search::normalise( $bad ) );
		}
	}

	public function test_premium_shown_as_premium_not_sellable(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => 'premium.com', 'status' => 'free', 'is_premium' => true ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = Search::find( 'premium.com' );
		$this->assertSame( 'premium', $result['results'][0]['status'] );
		$this->assertNull( $result['results'][0]['price'] );
	}
}

<?php
/** Price table tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Tests\TestCase;

class PricesTest extends TestCase {
	public function test_real_cost_examples_follow_rule(): void {
		foreach ( array( array( 8.15, 8.15, 13 ), array( 9.81, 9.81, 14 ), array( 7.04, 10.51, 15 ), array( 4.60, 4.60, 9 ), array( 3.68, 2.68, 8 ), array( 27.09, 27.09, 36 ), array( 46.90, 46.90, 61 ) ) as $case ) {
			$this->assertSame( $case[2], Prices::rule( $case[0], $case[1] ) );
		}
	}

	public function test_override_wins_and_restricted_is_hidden_except_dk(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ), 'dk' => array( 'price' => 13, 'restricted' => true ), 'bank' => array( 'price' => 40, 'restricted' => true ) ) );
		$this->settings( array( 'domain_price_overrides' => "com=15\n", 'domain_restricted_allowlist' => 'dk' ) );
		$this->assertSame( 15, Prices::price( 'com' ) );
		$this->assertTrue( Prices::sellable( 'dk' ) );
		$this->assertFalse( Prices::sellable( 'bank' ) );
		$this->assertNull( Prices::price( 'bank' ) );
	}

	public function test_sync_reads_page_and_keeps_old_table_on_error(): void {
		$fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/openprovider/tlds-page.json' ), true );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/tlds', $fixture['data'] );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 2, Prices::sync() );
		$this->assertSame( 16, Prices::price( '5g.in' ) );
		$this->assertSame( 16, Prices::price( '6g.in' ) );
		$old = get_option( Prices::OPTION );
		$this->assertWPError( Prices::sync() );
		$this->assertSame( $old, get_option( Prices::OPTION ) );
	}

	public function test_currency_must_be_eur(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/tlds', array( 'results' => array( array( 'name' => 'com', 'prices' => array( 'create_price' => array( 'reseller' => array( 'price' => 9, 'currency' => 'USD' ) ), 'renew_price' => array( 'reseller' => array( 'price' => 9, 'currency' => 'USD' ) ) ) ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertWPError( Prices::sync() );
		$this->assertSame( 14, Prices::price( 'com' ) );
	}
}

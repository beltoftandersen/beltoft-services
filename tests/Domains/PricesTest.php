<?php
/** Price table tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Tests\TestCase;

class PricesTest extends TestCase {
	public function test_real_cost_examples_follow_rule_and_the_price_with_vat_is_a_whole_euro_rounded_up(): void {
		// cost create, cost renew, price excluding VAT. max( cost x 1.30, cost + 4 ), x 1.25, rounded UP to a whole euro, / 1.25.
		foreach ( array( array( 8.15, 8.15, 12.8 ), array( 9.81, 9.81, 14.4 ), array( 7.04, 10.51, 15.2 ), array( 4.60, 4.60, 8.8 ), array( 3.68, 2.68, 8 ), array( 27.09, 27.09, 36 ), array( 46.90, 46.90, 61.6 ) ) as $case ) {
			$price = Prices::rule( $case[0], $case[1] );
			$this->assertEquals( $case[2], $price, 'cost ' . $case[0] . '/' . $case[1] );
			$this->assertEqualsWithDelta( round( $price * 1.25 ), $price * 1.25, 0.0001, 'whole euro with VAT' );
			$cost = max( $case[0], $case[1] );
			$this->assertGreaterThanOrEqual( max( $cost * 1.3, $cost + 4 ) - 0.0001, $price, 'never below the markup rule' );
		}
		$this->assertSame( 36, Prices::rule( 27.09, 27.09 ), 'A price that is already a whole euro with VAT is an int.' );
	}

	public function test_the_markup_and_the_minimum_margin_settings_still_decide_before_the_rounding(): void {
		$this->settings( array( 'domain_markup_percent' => 100, 'domain_min_margin' => 1 ) );
		$this->assertEquals( 16.0, Prices::rule( 8.0, 8.0 ) ); // 16 x 1.25 = 20 incl.
		$this->settings( array( 'domain_markup_percent' => 0, 'domain_min_margin' => 0 ) );
		$this->assertEquals( 8.0, Prices::rule( 8.0, 8.0 ) ); // 10 incl.
		$this->assertEquals( 8.8, Prices::rule( 8.1, 8.1 ), '8.1 x 1.25 = 10.125 incl., up to 11, / 1.25.' );
	}

	public function test_a_stored_price_with_decimals_is_returned_as_it_is(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12.8 ), 'net' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 14 ) ) );
		$this->assertSame( 12.8, Prices::price( 'com' ) );
		$this->assertSame( 14, Prices::price( 'net' ) );
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
		$this->assertSame( 7, Prices::sync() );
		// The recorded list answer carries the DNSSEC and WHOIS fields (read live 2026-11-19): max 0 means no maximum.
		$this->assertTrue( \BeltoftServices\Domains\TldFacts::dnssec_allowed( 'x.trade' ) );
		$this->assertTrue( \BeltoftServices\Domains\TldFacts::whois_allowed( 'x.de' ) );
		$this->assertFalse( \BeltoftServices\Domains\TldFacts::whois_allowed( 'x.dk' ) );
		$this->assertFalse( \BeltoftServices\Domains\TldFacts::whois_allowed( 'x.eu' ) );
		$this->assertSame( array( 8, 13, 14, 15, 16 ), \BeltoftServices\Domains\TldFacts::external_keys( 'x.dk' )['algs'] );
		$this->assertSame( \BeltoftServices\Domains\TldFacts::OWN_MAX_KEYS, \BeltoftServices\Domains\TldFacts::external_keys( 'x.com' )['max'] );
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

	public function test_active_tld_without_prices_is_skipped(): void {
		$eur = array( 'reseller' => array( 'price' => 9, 'currency' => 'EUR' ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/tlds', array( 'results' => array(
			array( 'name' => 'missing', 'status' => 'ACT', 'prices' => array( 'create_price' => null, 'renew_price' => $eur ) ),
			array( 'name' => 'com', 'status' => 'ACT', 'prices' => array( 'create_price' => $eur, 'renew_price' => $eur ) ),
		) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 1, Prices::sync() );
		$this->assertNull( Prices::price( 'missing' ) );
		$this->assertSame( 13.6, Prices::price( 'com' ), 'Cost 9: 13 excl., 16.25 incl., up to 17 incl.' );
	}

	public function test_an_override_below_the_registrar_cost_is_found_and_the_admin_is_told_after_a_refresh(): void {
		$this->settings( array( 'domain_price_overrides' => "com=15\nnet=30", 'admin_email' => 'christian.beltoft@gmail.com' ) );
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 21, 'create' => 12.0, 'renew' => 16.0, 'restricted' => false ), 'net' => array( 'price' => 30, 'create' => 12.0, 'renew' => 16.0, 'restricted' => false ) ) );

		$found = Prices::below_cost();
		$this->assertCount( 1, $found );
		$this->assertStringContainsString( '.com', $found[0] );

		Prices::alert_below_cost();
		Prices::alert_below_cost();
		$mails = array_filter( $GLOBALS['phpmailer']->mock_sent ?? array(), static fn( $m ) => false !== strpos( (string) $m['subject'], 'below the registrar cost' ) );
		$this->assertCount( 1, $mails, 'One mail for the same list.' );
		$this->assertStringContainsString( '.com', $mails[ array_key_first( $mails ) ]['body'] );
	}

	public function test_an_empty_list_from_the_registrar_keeps_the_saved_table(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/tlds', array( 'results' => array() ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );

		$this->assertWPError( Prices::sync() );
		$this->assertSame( 14, Prices::price( 'com' ), 'The prices are not wiped by an empty answer.' );
	}
}

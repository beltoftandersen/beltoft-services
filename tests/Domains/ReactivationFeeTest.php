<?php
/** The reactivation fee: the full stored restore cost times 1.30, always (owner decision 2026-11-27). @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\ReactivationFee;
use BeltoftServices\Tests\TestCase;

class ReactivationFeeTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		update_option(
			Prices::OPTION,
			array(
				'com' => array( 'restore' => 43.08, 'soft_restore' => 0.0, 'soft_quarantine_days' => 40, 'quarantine_days' => 0 ),
				'net' => array( 'restore' => 62.23, 'soft_restore' => 0.0, 'soft_quarantine_days' => 40, 'quarantine_days' => 0 ),
				'dk'  => array( 'restore' => 17.56, 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
				'eu'  => array( 'restore' => 15.0, 'soft_quarantine_days' => 0, 'quarantine_days' => 38 ),
				'de'  => array( 'restore' => 12.0, 'soft_quarantine_days' => 0, 'quarantine_days' => 29 ),
				'io'  => array( 'restore' => 67.01, 'soft_restore' => 0.0, 'soft_quarantine_days' => 44, 'quarantine_days' => 28 ),
				'xx'  => array( 'soft_quarantine_days' => 0, 'quarantine_days' => 10 ),
			)
		);
	}

	public function test_the_owners_examples(): void {
		// Plan section 9: cost x 1.30, rounded to whole cents.
		$this->assertSame( 56.0, ReactivationFee::of_cost( 43.08 ) );
		$this->assertSame( 22.83, ReactivationFee::of_cost( 17.56 ) );
		$this->assertSame( 19.5, ReactivationFee::of_cost( 15.0 ) );
		$this->assertSame( 15.6, ReactivationFee::of_cost( 12.0 ) );
		$this->assertSame( 0.0, ReactivationFee::of_cost( 0.0 ), 'No minimum.' );
	}

	public function test_the_fee_comes_from_the_stored_restore_price_not_a_fixed_figure(): void {
		$this->assertSame( 22.83, ReactivationFee::fee( 'example.dk' ) );
		$this->assertSame( 19.5, ReactivationFee::fee( 'example.eu' ) );
		$this->assertSame( 15.6, ReactivationFee::fee( 'example.de' ) );
		$table = get_option( Prices::OPTION );
		$table['dk']['restore'] = 20.0;
		update_option( Prices::OPTION, $table );
		$this->assertSame( 26.0, ReactivationFee::fee( 'example.dk' ), 'A refreshed price changes the fee.' );
	}

	public function test_a_gtld_pays_the_full_restore_cost_even_inside_its_soft_quarantine(): void {
		// Owner decision 2026-11-27: Openprovider's semantics are unknown, so the most expensive case is assumed. soft_restore is never used.
		$this->assertSame( 43.08, ReactivationFee::cost( 'example.com' ) );
		$this->assertSame( 56.0, ReactivationFee::fee( 'example.com' ) );
		$this->assertSame( 80.9, ReactivationFee::fee( 'example.net' ) );
		$this->assertSame( 87.11, ReactivationFee::fee( 'example.io' ) );
	}

	public function test_a_row_with_only_a_soft_restore_price_has_no_fee(): void {
		$table                = get_option( Prices::OPTION );
		$table['xx']['soft_restore'] = 0.0;
		update_option( Prices::OPTION, $table );
		$this->assertNull( ReactivationFee::fee( 'example.xx' ), 'The soft price is not a cost to build a fee on.' );
	}

	public function test_an_unknown_cost_gives_no_fee(): void {
		$this->assertNull( ReactivationFee::fee( 'example.xx' ) );
		$this->assertNull( ReactivationFee::fee( 'example.unknown' ) );
		$this->assertNull( ReactivationFee::cost( 'example.xx' ) );
	}

	public function test_the_markup_is_a_setting(): void {
		$this->assertSame( 30.0, ReactivationFee::markup_percent() );
		$this->settings( array( 'domain_reactivation_markup_percent' => 50 ) );
		$this->assertSame( 22.5, ReactivationFee::fee( 'example.eu' ) );
		$this->settings( array( 'domain_reactivation_markup_percent' => 0 ) );
		$this->assertSame( 15.0, ReactivationFee::fee( 'example.eu' ), 'Zero markup is the cost.' );
	}

	public function test_the_terms_of_an_extension(): void {
		$com = ReactivationFee::terms( 'example.com' );
		$this->assertSame( 40, $com['window'] );
		$this->assertSame( 56.0, $com['fee'] );
		$this->assertSame( 72, ReactivationFee::terms( 'example.io' )['window'] );
		$dk = ReactivationFee::terms( 'example.dk' );
		$this->assertSame( 30, $dk['window'] );
		$this->assertSame( 22.83, $dk['fee'] );
		$this->assertSame( 19.5, ReactivationFee::terms( 'example.eu' )['fee'] );
		$this->assertNull( ReactivationFee::terms( 'example.xx' )['fee'] );
	}

	public function test_the_amount_in_another_currency_needs_the_rate_or_a_listener(): void {
		$this->assertSame( 22.83, ReactivationFee::amount( 22.83, '', 'example.dk' ) );
		$this->assertNull( ReactivationFee::amount( 22.83, 'DKK', 'example.dk' ) );
		$this->settings( array( 'domain_fee_dkk_rate' => '7.46' ) );
		$this->assertSame( 171.0, ReactivationFee::amount( 22.83, 'DKK', 'example.dk' ) );
	}

	public function test_the_fee_is_shown_in_the_shop_currency_and_a_site_can_give_another_currency(): void {
		$this->assertStringContainsString( '22.83', ReactivationFee::text( 22.83, '', 'example.dk' ) );
		// No figure for DKK: the fee is shown in the shop currency, never a euro figure labelled as kroner.
		$this->assertStringNotContainsString( 'kr', ReactivationFee::text( 22.83, 'DKK', 'example.dk' ) );
		add_filter( 'beltoft_services_domain_reactivation_fee', static fn( $amount, $base, $currency ) => 'DKK' === $currency ? 170.0 : $amount, 10, 3 );
		$this->assertStringContainsString( '170', ReactivationFee::text( 22.83, 'DKK', 'example.dk' ) );
	}

	public function test_the_fixed_dkk_rate_rounds_up_to_whole_kroner(): void {
		$this->assertNull( ReactivationFee::dkk_rate() );
		$this->assertNull( ReactivationFee::dkk( 22.83 ), 'No rate, no kroner figure.' );
		$this->settings( array( 'domain_fee_dkk_rate' => '7.46' ) );
		$this->assertSame( 7.46, ReactivationFee::dkk_rate() );
		$this->assertSame( 171.0, ReactivationFee::dkk( 22.83 ), '170.3118 rounds up.' );
		$this->assertSame( 112.0, ReactivationFee::dkk( 15.0 ), '111.9 rounds up.' );
		$this->assertSame( 0.0, ReactivationFee::dkk( 0.0 ) );
		$this->settings( array( 'domain_fee_dkk_rate' => '10' ) );
		$this->assertSame( 150.0, ReactivationFee::dkk( 15.0 ), 'An exact figure is not pushed up.' );
	}

	public function test_dkk_text_uses_the_rate_when_set_and_euro_when_empty(): void {
		$this->assertStringContainsString( '22.83', ReactivationFee::text( 22.83, 'DKK', 'example.dk' ) );
		$this->settings( array( 'domain_fee_dkk_rate' => '7.46' ) );
		$text = ReactivationFee::text( 22.83, 'DKK', 'example.dk' );
		$this->assertStringContainsString( '171', $text );
		$this->assertStringNotContainsString( '22.83', $text );
		add_filter( 'beltoft_services_domain_reactivation_fee', static fn( $amount, $base, $currency ) => 200.0, 10, 3 );
		$this->assertStringContainsString( '200', ReactivationFee::text( 22.83, 'DKK', 'example.dk' ), 'The filter still has the last word.' );
	}
}

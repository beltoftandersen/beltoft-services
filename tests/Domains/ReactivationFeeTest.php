<?php
/** The reactivation fee: the stored restore cost times 1.30. @package BeltoftServices */
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
		// Plan section 9: cost x 1.30, rounded to whole cents. ".com after the grace" is cost 43.08 outside the soft quarantine.
		$this->assertSame( 56.0, ReactivationFee::of_cost( 43.08 ) );
		$this->assertSame( 22.83, ReactivationFee::of_cost( 17.56 ) );
		$this->assertSame( 19.5, ReactivationFee::of_cost( 15.0 ) );
		$this->assertSame( 15.6, ReactivationFee::of_cost( 12.0 ) );
		$this->assertSame( 0.0, ReactivationFee::of_cost( 0.0 ), 'No minimum.' );
	}

	public function test_the_fee_comes_from_the_stored_restore_price_not_a_fixed_figure(): void {
		$this->assertSame( 22.83, ReactivationFee::fee( 'example.dk', 1 ) );
		$this->assertSame( 19.5, ReactivationFee::fee( 'example.eu', 1 ) );
		$this->assertSame( 15.6, ReactivationFee::fee( 'example.de', 10 ) );
		$table = get_option( Prices::OPTION );
		$table['dk']['restore'] = 20.0;
		update_option( Prices::OPTION, $table );
		$this->assertSame( 26.0, ReactivationFee::fee( 'example.dk', 1 ), 'A refreshed price changes the fee.' );
	}

	public function test_a_gtld_inside_its_free_soft_quarantine_has_no_fee(): void {
		$this->assertSame( 0.0, ReactivationFee::fee( 'example.com', 1 ) );
		$this->assertSame( 0.0, ReactivationFee::fee( 'example.com', 40 ), 'The last day of the soft quarantine.' );
		$this->assertSame( 0.0, ReactivationFee::fee( 'example.net', 0 ) );
	}

	public function test_after_the_soft_quarantine_the_restore_price_applies(): void {
		$this->assertSame( 56.0, ReactivationFee::fee( 'example.com', 41 ) );
		$this->assertSame( 80.9, ReactivationFee::fee( 'example.net', 41 ) );
		$this->assertSame( 87.11, ReactivationFee::fee( 'example.io', 45 ), '.io: soft quarantine 44 days, then the quarantine.' );
		$this->assertSame( 56.0, ReactivationFee::fee( 'example.com', null ), 'Without a day count it is the paid price.' );
	}

	public function test_an_unknown_cost_gives_no_fee(): void {
		$this->assertNull( ReactivationFee::fee( 'example.xx', 1 ) );
		$this->assertNull( ReactivationFee::fee( 'example.unknown', 1 ) );
		$this->assertNull( ReactivationFee::cost( 'example.xx' ) );
	}

	public function test_the_markup_is_a_setting(): void {
		$this->assertSame( 30.0, ReactivationFee::markup_percent() );
		$this->settings( array( 'domain_reactivation_markup_percent' => 50 ) );
		$this->assertSame( 22.5, ReactivationFee::fee( 'example.eu', 1 ) );
		$this->settings( array( 'domain_reactivation_markup_percent' => 0 ) );
		$this->assertSame( 15.0, ReactivationFee::fee( 'example.eu', 1 ), 'Zero markup is the cost.' );
	}

	public function test_the_terms_of_an_extension(): void {
		$com = ReactivationFee::terms( 'example.com' );
		$this->assertSame( 40, $com['window'] );
		$this->assertSame( 40, $com['free_days'] );
		$this->assertSame( 0.0, $com['free_fee'] );
		$this->assertNull( $com['paid_fee'], 'A gTLD with only a free soft quarantine has no paid restore.' );
		$io = ReactivationFee::terms( 'example.io' );
		$this->assertSame( 72, $io['window'] );
		$this->assertSame( 87.11, $io['paid_fee'] );
		$dk = ReactivationFee::terms( 'example.dk' );
		$this->assertSame( 30, $dk['window'] );
		$this->assertNull( $dk['free_fee'] );
		$this->assertSame( 22.83, $dk['paid_fee'] );
		$eu = ReactivationFee::terms( 'example.eu' );
		$this->assertSame( 19.5, $eu['paid_fee'] );
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

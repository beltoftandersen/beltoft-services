<?php
/**
 * The subscription list and the page of one subscription.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\SubscriptionPages;
use BeltoftServices\Account\UsageView;
use BeltoftServices\Billing\SubscriptionNames;
use BeltoftServices\Tests\Usage\UsageTestCase;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

/**
 * Ordering, the fold, the pay-as-you-go card and where the usage block is shown.
 */
final class SubscriptionPagesTest extends UsageTestCase {

	public function tear_down(): void {
		global $wp;
		unset( $wp->query_vars['subscriptions'], $wp->query_vars['view-subscription'] );
		UsageView::reset();
		SubscriptionPages::reset();
		Estimate::reset();
		parent::tear_down();
	}

	/**
	 * A subscription of $user with one product line.
	 */
	private function sub( int $user, string $name, string $status = 'active', string $created = '' ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'pending' ) );
		$p   = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( '5' );
		$p->save();
		$sub->add_product( $p, 1 );
		if ( '' !== $created ) {
			$sub->set_date_created( $created );
		}
		$sub->save();
		$sub->update_status( 'active' );
		if ( 'active' !== $status ) {
			$sub->update_status( $status );
		}

		return wcs_get_subscription( $sub->get_id() );
	}

	private function endpoint( string $key ): void {
		global $wp;
		$wp->query_vars[ $key ] = '1';
	}

	public function test_live_first_newest_first_ended_folded_and_trash_hidden(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$old   = $this->sub( $user, 'Old live', 'active', '2026-01-01 10:00:00' );
		$new   = $this->sub( $user, 'New live', 'on-hold', '2026-05-01 10:00:00' );
		$gone  = $this->sub( $user, 'Gone', 'cancelled', '2026-06-01 10:00:00' );
		$trash = $this->sub( $user, 'Trashed', 'active', '2026-07-01 10:00:00' );
		$trash->update_status( 'trash' );

		$groups = SubscriptionPages::groups( $user );

		$this->assertSame( array( $new->get_id(), $old->get_id() ), array_map( static fn( $s ) => $s->get_id(), $groups['live'] ) );
		$this->assertSame( array( $gone->get_id() ), array_map( static fn( $s ) => $s->get_id(), $groups['ended'] ) );

		ob_start();
		wc_get_template( 'myaccount/my-subscriptions.php', array(), '', '/nonexistent/' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h2 class="bs-subscriptions__title">Subscriptions</h2>', $html );
		$this->assertStringContainsString( '<summary>Ended subscriptions (1)</summary>', $html );
		$this->assertStringNotContainsString( 'Trashed', $html );
		$this->assertLessThan( strpos( $html, 'Old live' ), strpos( $html, 'New live' ) );
		$this->assertLessThan( strpos( $html, '<details' ), strpos( $html, 'Old live' ) );
		$this->assertGreaterThan( strpos( $html, '<details' ), strpos( $html, 'Gone' ) );
		$this->assertStringContainsString( '#' . $new->get_id(), $html );
		$this->assertStringContainsString( 'class="beltoft-services-state is-bad">On hold', $html );
		$this->assertStringContainsString( 'class="beltoft-services-state is-off">Cancelled', $html );
		$this->assertStringContainsString( 'aria-label="New live, On hold"', $html );
		$this->assertStringNotContainsString( '<table', $html );
		$this->assertStringContainsString( 'beltoft-services-srv__figure', $html );
	}

	public function test_a_row_says_next_payment_and_end_date(): void {
		$user = $this->new_user();
		$sub  = $this->sub( $user, 'Backup', 'active' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ) );
		$row = SubscriptionPages::row( wcs_get_subscription( $sub->get_id() ) );

		$this->assertSame( 'ok', $row['tone'] );
		$this->assertSame( 'Next payment ' . wp_date( (string) get_option( 'date_format' ), time() + 20 * DAY_IN_SECONDS ), $row['note'] );

		$gone = SubscriptionPages::row( $this->sub( $user, 'Old', 'cancelled' ) );
		$this->assertSame( 'off', $gone['tone'] );
		$this->assertSame( '1', $gone['ended'] );
	}

	public function test_the_pay_as_you_go_card_replaces_its_row(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$account = $this->usage_account( $user );
		$other   = $this->sub( $user, 'Backup', 'active' );

		$ids = array_map( static fn( $s ) => $s->get_id(), SubscriptionPages::groups( $user )['live'] );
		$this->assertSame( array( $other->get_id() ), $ids );

		Estimate::reset();
		$card = UsageView::card();
		$this->assertStringContainsString( 'Pay as you go', $card );
		$this->assertStringContainsString( esc_url( $account->get_view_order_url() ), $card );
		$this->assertStringContainsString( 'View account', $card );
		$this->assertStringContainsString( 'aria-label="Pay as you go, Active"', $card );
		$this->assertStringNotContainsString( 'Usage this month', $card );
	}

	public function test_no_card_without_pay_as_you_go(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->sub( $user, 'Backup', 'active' );

		$this->assertSame( '', UsageView::card() );
	}

	public function test_the_usage_block_is_on_the_list_only(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->usage_account( $user );

		$this->endpoint( 'subscriptions' );
		ob_start();
		UsageView::in_content();
		$this->assertStringContainsString( 'Pay as you go', (string) ob_get_clean() );

		UsageView::reset();
		global $wp;
		unset( $wp->query_vars['subscriptions'] );
		$this->endpoint( 'view-subscription' );
		ob_start();
		UsageView::in_content();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_a_normal_subscription_page_has_a_heading_and_no_usage(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->usage_account( $user );
		$sub = $this->sub( $user, 'Backup - 1 TB', 'active' );

		ob_start();
		SubscriptionPages::head( $sub->get_id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h2 class="bs-sub-head__title">Backup - 1 TB</h2>', $html );
		$this->assertStringContainsString( 'Subscription #' . $sub->get_order_number(), $html );
		$this->assertStringNotContainsString( 'beltoft-services-usage', $html );
		$this->assertStringContainsString( 'bs-sub-summary', $html );
		$this->assertStringContainsString( 'beltoft-services-srv__figure', $html );
		$this->assertStringNotContainsString( 'invoiced here once a month', $html );
	}

	public function test_the_pay_as_you_go_page_shows_the_sentence_and_hides_totals(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$account = $this->usage_account( $user );
		$v       = $this->usage_product();
		$r     = $this->usage_vm( $v['u-None'], $user, 5, $account->get_id() );
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;
		Ledger::record( $r->id(), $user, 'server', 1.0, 'hour', 0.02, $end );
		Estimate::reset();
		UsageView::reset();

		ob_start();
		SubscriptionPages::head( $account->get_id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Servers and other services billed by the hour are invoiced here once a month. The amount depends on use.', $html );
		$this->assertStringContainsString( 'beltoft-services-usage', $html );
		$this->assertStringContainsString( 'bs-sub-summary', $html );
		$this->assertFalse( has_action( 'woocommerce_subscription_totals_table', array( 'WCS_Template_Loader', 'get_subscription_totals_template' ) ) );
	}

	public function test_an_ended_subscription_page_says_so(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$sub = $this->sub( $user, 'Gone', 'cancelled' );

		ob_start();
		SubscriptionPages::head( $sub->get_id() );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/This subscription (ended on .+|has ended)\./', $html );
	}

	public function test_dates_on_the_page_are_absolute(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$sub = $this->sub( $user, 'Soon', 'active' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ) );
		$sub = wcs_get_subscription( $sub->get_id() );

		$this->assertStringContainsString( 'in 2 days', $sub->get_date_to_display( 'next_payment' ) );

		ob_start();
		SubscriptionPages::head( $sub->get_id() );
		ob_get_clean();

		$this->assertSame( wp_date( (string) get_option( 'date_format' ), (int) $sub->get_time( 'next_payment', 'gmt' ) ), $sub->get_date_to_display( 'next_payment' ) );
	}

	public function test_empty_variation_values_are_dropped_from_names(): void {
		$this->assertSame( 'VPS hosting - Starter', SubscriptionNames::without_none( 'VPS hosting - Starter, None' ) );
		$this->assertSame( 'VPS hosting - Starter', SubscriptionNames::without_none( 'VPS hosting - Starter, Ingen' ) );
		$this->assertSame( 'Extra disk - 100 GB', SubscriptionNames::without_none( 'Extra disk - 100 GB' ) );

		$user = $this->new_user();
		$this->assertSame( 'VPS hosting - Starter', SubscriptionNames::names( $this->sub( $user, 'VPS hosting - Starter, None' ) ) );
	}
}

<?php
/**
 * The billing service the dashboard, REST and the MCP tools share: who may cancel, what the words say, the cancelling.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\PaymentMethods;
use BeltoftServices\Billing\Subscriptions;
use BeltoftServices\Mcp\Notices;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Mcp\McpTestCase;

require_once dirname( __DIR__ ) . '/Mcp/McpTestCase.php';

class SubscriptionsTest extends McpTestCase {

	public function test_price_line_for_free_and_paid(): void {
		$user = self::factory()->user->create();

		$this->assertSame( 'It is free.', Subscriptions::price_line( $this->subscription( $user, '0.00', true ) ) );

		$line = Subscriptions::price_line( $this->subscription( $user, '12.00', true ) );
		$this->assertStringContainsString( 'It costs', $line );
		$this->assertStringContainsString( 'every year', $line );
		$this->assertMatchesRegularExpression( '/no VAT is charged|VAT included/', $line );
	}

	public function test_what_will_happen_text_has_end_date_nothing_charged_again_and_a_state_that_changes(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, '12.00', true );
		$out  = Subscriptions::cancel_text( $sub, array(), array() );

		$this->assertStringContainsString( 'Cancel the subscription of this subscription.', $out['text'] );
		$this->assertStringContainsString( 'It is paid until ' . Subscriptions::ends_on( $sub ), $out['text'] );
		$this->assertStringContainsString( 'nothing is charged again', $out['text'] );

		$before = $out['state'];
		$sub->update_status( 'on-hold' );
		$this->assertNotSame( $before, Subscriptions::cancel_text( wcs_get_subscription( $sub->get_id() ), array(), array() )['state'] );
	}

	public function test_a_domain_registration_and_somebody_elses_subscription_cannot_be_cancelled(): void {
		$user   = self::factory()->user->create();
		$sub    = $this->subscription( $user, '12.00', true );
		$domain = $this->domain_paid_by( $user, $sub );

		$this->assertStringContainsString( 'registered domain', Subscriptions::cancel_blocker( $sub, $user, array( $domain ) ) );
		$this->assertSame( 'beltoft_services_tool', Subscriptions::cancellable( $user, $sub->get_id() )->get_error_code() );
		$this->assertSame( 'beltoft_services_forbidden', Subscriptions::cancellable( self::factory()->user->create(), $sub->get_id() )->get_error_code() );
		$this->assertSame( 'beltoft_services_forbidden', Subscriptions::owned( 0, $sub->get_id() )->get_error_code() );
	}

	public function test_a_customer_without_the_capability_cannot_cancel_even_when_the_button_is_listed(): void {
		// The dashboard's DNS stop checked the capability, the MCP tool checked the button list: now both must hold.
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, '12.00', true );

		$this->assertTrue( Subscriptions::wcs_allows_cancel( $sub, $user ) );

		add_filter( 'map_meta_cap', static fn( $caps, $cap ) => 'edit_shop_subscription_status' === $cap ? array( 'do_not_allow' ) : $caps, 10, 2 );

		$this->assertFalse( Subscriptions::wcs_allows_cancel( $sub, $user ) );
		$this->assertStringContainsString( 'does not offer cancelling', Subscriptions::cancel_blocker( $sub, $user, array() ) );
	}

	public function test_an_ended_subscription_is_not_cancellable(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, '12.00', true );
		Subscriptions::cancel( $sub, array( Notices::class, 'capture' ) );

		$this->assertTrue( Subscriptions::cancellable( $user, $sub->get_id() ) instanceof \WP_Error );
	}

	public function test_cancel_runs_the_customer_status_change_and_writes_no_note_itself(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$sub  = $this->subscription( $user, '12.00', true );

		$done = Subscriptions::cancel( $sub, array( Notices::class, 'capture' ) );

		$this->assertTrue( $done['ok'] );
		$this->assertTrue( $done['pending'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $done['ends'] );
		$this->assertTrue( wcs_get_subscription( $sub->get_id() )->has_status( 'pending-cancel' ) );
		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) );
		$this->assertNotContains( 'Claude: subscription cancelled', $notes, 'The trail is the caller\'s.' );
	}

	public function test_an_add_on_is_a_disk_snapshot_slots_or_a_licence_not_a_server(): void {
		$this->assertNotSame( '', Subscriptions::addon_blocker( $this->server( self::factory()->user->create() ) ) );
	}

	public function test_a_card_must_be_the_customers_not_default_and_not_expired(): void {
		$user  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$make  = static function ( int $owner, int $year ): int {
			$t = new \WC_Payment_Token_CC();
			$t->set_token( wp_generate_password( 12, false ) );
			$t->set_gateway_id( 'bacs' );
			$t->set_card_type( 'visa' );
			$t->set_last4( '4242' );
			$t->set_expiry_month( '12' );
			$t->set_expiry_year( (string) $year );
			$t->set_user_id( $owner );
			$t->save();

			return $t->get_id();
		};

		$first   = $make( $user, (int) gmdate( 'Y' ) + 2 );
		$second  = $make( $user, (int) gmdate( 'Y' ) + 2 );
		$expired = $make( $user, (int) gmdate( 'Y' ) - 1 );
		$theirs  = $make( $other, (int) gmdate( 'Y' ) + 2 );
		\WC_Payment_Tokens::set_users_default( $user, $first );

		$this->assertSame( 'beltoft_services_forbidden', PaymentMethods::usable( $user, $theirs )->get_error_code() );
		$this->assertStringContainsString( 'default payment method already', PaymentMethods::usable( $user, $first )->get_error_message() );
		$this->assertStringContainsString( 'expired', PaymentMethods::usable( $user, $expired )->get_error_message() );

		$token = PaymentMethods::usable( $user, $second );
		$this->assertSame( 'Visa ending 4242', PaymentMethods::label( $token ) );
		PaymentMethods::make_default( $user, $second );
		$this->assertSame( $second, \WC_Payment_Tokens::get_customer_default_token( $user )->get_id() );
	}
}

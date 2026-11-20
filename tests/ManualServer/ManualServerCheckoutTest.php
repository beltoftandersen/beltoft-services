<?php
/**
 * The checkout of a dedicated server: the setup fee is charged up front, pay as you go only, the new-account limit.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\Mcp\DeleteService;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Cart;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/ManualServerTestCase.php';

/**
 * Cart rules.
 */
class ManualServerCheckoutTest extends ManualServerTestCase {

	private function add( \WC_Product_Variation $v ): string {
		$attributes = $v->get_attributes();
		$key        = WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), array( 'attribute_server' => $attributes['server'], 'attribute_billing' => $attributes['billing'] ) );
		$this->assertNotFalse( $key );
		WC()->cart->calculate_totals();

		return (string) $key;
	}

	public function test_the_setup_fee_is_charged_at_checkout_and_the_usage_account_line_comes_with_it(): void {
		$v = $this->server_product();
		wp_set_current_user( $this->new_user() );

		$this->add( $v['u'] );

		$this->assertEquals( 54, WC()->cart->get_subtotal(), 'The one-time setup fee is the usage line price.' );
		$this->assertEquals( 54, WC()->cart->get_total( 'edit' ) );
		$this->assertTrue( WC()->cart->needs_payment() );
		$this->assertSame( 1, Cart::usage_units() );

		$lines = array_filter( WC()->cart->get_cart(), static fn( $item ) => (int) $item['product_id'] === (int) get_option( Account::OPTION_PRODUCT ) );
		$this->assertCount( 1, $lines, 'The hidden usage account line holds the card for the hourly bills.' );
	}

	public function test_a_new_account_is_limited_like_a_vps(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$this->settings( array( 'usage_new_limit' => 1 ) );

		$this->assertNull( Cart::limit_problem( $user, null, 1 ) );

		$this->waiting_server( $v['u'], $user );

		$this->assertNotNull( Cart::limit_problem( $user, null, 1 ), 'A dedicated server counts as a service.' );
		$this->assertCount( 1, Account::services( $user ) );
	}

	public function test_an_account_on_hold_cannot_order_another_server(): void {
		$user = $this->new_user();
		$this->usage_account( $user, 'on-hold' );

		$problems = Cart::problems( $user, 'fakecard', 1 );

		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'unpaid invoice', $problems[0] );
	}

	public function test_the_price_follows_the_monthly_variation_and_the_product_premium(): void {
		$v = $this->server_product();

		$this->assertSame( 0.0, Rates::premium( $v['u']->get_parent_id() ) );
		$this->assertSame( 119.0, Rates::monthly( $v['u'] ) );
		$rates = Rates::for_usage( $v['u'] );
		$this->assertSame( Rates::hourly( 119.0, 0.0 ), $rates['hourly'] );
		$this->assertSame( 119.0, $rates['cap'], 'The cap is the monthly price.' );
	}

	public function test_an_assistant_cannot_cancel_a_dedicated_server(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->ready_server( $v['u'], $user );

		foreach ( array( 'delete_server', 'delete_database' ) as $tool ) {
			$answer = DeleteService::summary( $tool, array( 'id' => $r->id() ), $user );
			$this->assertWPError( $answer, $tool );
			$this->assertStringContainsString( 'dashboard', $answer->get_error_message() );
		}

		$this->assertWPError( DeleteService::run( 'delete_server', array( 'id' => $r->id() ), $user ) );
		$this->assertSame( 'ready', \BeltoftServices\Resource::find( $r->id() )->get( 'state' ) );
	}
}

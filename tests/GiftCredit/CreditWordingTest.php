<?php
/**
 * Credit wording on the site: the add-on's gettext swap follows gift credit, the gift card plugin's My Account page goes to the credit
 * page, and no customer text of the credit says gift card.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\GiftCredit;

use BeltoftServices\GiftCredit\GiftCredit;
use BeltoftServices\GiftCredit\Page;

require_once __DIR__ . '/GiftCreditTestCase.php';

class CreditWordingTest extends GiftCreditTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\Bgca\Wording' ) ) {
			$this->markTestSkipped( 'The add-on is older than 0.3.1.' );
		}
	}

	public function test_the_gift_card_plugin_texts_say_credit_while_gift_credit_is_on(): void {
		$this->assertTrue( GiftCredit::active() );
		$this->assertSame( 'Credit removed.', __( 'Gift card removed.', 'beltoft-gift-cards' ) );
		$this->assertSame( 'Credit (X1)', sprintf( __( 'Gift Card (%s)', 'beltoft-gift-cards' ), 'X1' ) );
	}

	public function test_they_are_the_plugins_own_while_gift_credit_is_off(): void {
		$this->settings( array( 'gift_credit' => 0 ) );

		$this->assertFalse( GiftCredit::active() );
		$this->assertSame( 'Gift card removed.', __( 'Gift card removed.', 'beltoft-gift-cards' ) );
	}

	public function test_the_plugins_my_account_page_goes_to_the_credit_page(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[woocommerce_my_account]' ) );
		update_option( 'woocommerce_myaccount_page_id', $page );

		$this->go_to( add_query_arg( 'gift-cards', '', get_permalink( $page ) ) );
		$this->assertSame( Page::url(), Page::plugin_page_target() );

		$this->go_to( add_query_arg( 'credit', '', get_permalink( $page ) ) );
		$this->assertNull( Page::plugin_page_target() );

		$this->settings( array( 'gift_credit' => 0 ) );
		$this->go_to( add_query_arg( 'gift-cards', '', get_permalink( $page ) ) );
		$this->assertNull( Page::plugin_page_target() );
	}

	public function test_the_query_var_on_any_other_page_is_left_alone(): void {
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Plain page' ) );

		$this->go_to( add_query_arg( 'gift-cards', '', get_permalink( $other ) ) );
		$this->assertNull( Page::plugin_page_target() );
	}

	public function test_the_usage_note_of_the_api_says_credit(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Mcp/Pricing.php' );

		$this->assertStringContainsString( 'Credit is applied first on usage invoices', $source );
		$this->assertStringNotContainsStringIgnoringCase( 'credit (gift cards)', $source );
	}
}

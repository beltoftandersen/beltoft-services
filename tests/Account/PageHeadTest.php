<?php
/** The state line under the title row of every account page. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\PageHead;
use BeltoftServices\Tests\TestCase;

class PageHeadTest extends TestCase {
	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['domains'], $GLOBALS['wp']->query_vars['subscriptions'], $GLOBALS['wp']->query_vars['security'] );
		parent::tear_down();
	}

	public function test_each_page_has_a_plain_one_line_description(): void {
		foreach ( array( 'domains' => 'Your domains and DNS', 'subscriptions' => 'Subscriptions, invoices and payment methods', 'edit-account' => 'Your name, email address and password' ) as $endpoint => $text ) {
			$GLOBALS['wp']->query_vars = array( $endpoint => '' );
			$this->assertSame( $text, PageHead::description(), $endpoint );
			$this->assertStringNotContainsString( '—', $text );
			$this->assertStringNotContainsString( '·', $text );
		}
	}

	public function test_the_line_is_printed_before_the_account_element_unless_the_page_has_its_own(): void {
		$GLOBALS['wp']->query_vars = array( 'domains' => '' );
		$element = (object) array( 'name' => 'woocommerce-account-page-v2' );

		$html = (string) PageHead::before_bricks_account( '<div class="brxe">content</div>', $element );
		$this->assertStringStartsWith( '<div class="bs-pagehead"><span class="bs-inline" data-bs-inline role="status" aria-live="polite"><span class="bs-inline__text">Your domains and DNS</span>', $html );

		$own = '<header class="beltoft-services-page__head"><span class="bs-inline" data-bs-inline></span></header>';
		$this->assertSame( $own, PageHead::before_bricks_account( $own, $element ), 'A head with its own line is not given a second one.' );
		$this->assertSame( 'x', PageHead::before_bricks_account( 'x', (object) array( 'name' => 'heading' ) ) );
	}

	public function test_the_description_can_be_changed_with_a_filter(): void {
		$GLOBALS['wp']->query_vars = array( 'domains' => '' );
		add_filter( 'beltoft_services_page_description', static fn() => 'Custom' );
		$this->assertSame( 'Custom', PageHead::description() );
		remove_all_filters( 'beltoft_services_page_description' );
	}
}

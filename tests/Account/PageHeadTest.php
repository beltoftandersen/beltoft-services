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

	public function test_a_page_repeats_nothing_its_title_says(): void {
		foreach ( array( 'domains', 'subscriptions', 'orders', 'edit-account', 'security', 'licenses', 'tickets', '' ) as $endpoint ) {
			$GLOBALS['wp']->query_vars = '' === $endpoint ? array() : array( $endpoint => '' );
			$this->assertSame( '', PageHead::description(), $endpoint );
		}
	}

	public function test_the_line_is_printed_before_the_account_element_unless_the_page_has_its_own(): void {
		$GLOBALS['wp']->query_vars = array( 'domains' => '' );
		$element = (object) array( 'name' => 'woocommerce-account-page-v2' );

		$html = (string) PageHead::before_bricks_account( '<div class="brxe">content</div>', $element );
		$this->assertStringStartsWith( '<div class="bs-pagehead is-empty"><span class="bs-inline" data-bs-inline role="status" aria-live="polite"><span class="bs-inline__text"></span>', $html );

		$own = '<header class="beltoft-services-page__head"><span class="bs-inline" data-bs-inline></span></header>';
		$this->assertSame( $own, PageHead::before_bricks_account( $own, $element ), 'A head with its own line is not given a second one.' );
		$this->assertSame( 'x', PageHead::before_bricks_account( 'x', (object) array( 'name' => 'heading' ) ) );
	}

	public function test_a_line_without_a_description_is_marked_empty_and_one_with_a_description_is_not(): void {
		$this->assertStringStartsWith( '<div class="bs-pagehead is-empty">', PageHead::line( '' ) );
		$this->assertStringStartsWith( '<div class="bs-pagehead">', PageHead::line( 'Create a new server.' ) );
		$this->assertStringContainsString( 'Create a new server.', PageHead::line( 'Create a new server.' ) );
	}

	public function test_the_description_can_be_changed_with_a_filter(): void {
		$GLOBALS['wp']->query_vars = array( 'domains' => '' );
		add_filter( 'beltoft_services_page_description', static fn() => 'Custom' );
		$this->assertSame( 'Custom', PageHead::description() );
		remove_all_filters( 'beltoft_services_page_description' );
	}
}

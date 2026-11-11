<?php
/** My Account: the WordPress hosting tab is there for every customer, with an empty state and an order button. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Hosting;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Tests\TestCase;

class HostingAreaTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create() );
		Overview::reset();
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['hosting'] );
		remove_all_filters( 'beltoft_services_hosting_add_url' );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	/** @return array<string, string> */
	private function menu(): array {
		return apply_filters( 'woocommerce_account_menu_items', array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
	}

	private function page(): string {
		$GLOBALS['wp']->query_vars['hosting'] = '';
		ob_start();
		ServiceArea::get( Hosting::class )->render( array() );

		return (string) ob_get_clean();
	}

	public function test_the_tab_is_in_the_menu_of_a_customer_with_no_hosting_and_not_for_a_visitor(): void {
		$menu = $this->menu();

		$this->assertSame( 'WordPress hosting', $menu['hosting'] );
		$keys = array_keys( $menu );
		$this->assertSame( 'customer-logout', end( $keys ), 'Log out stays last.' );

		wp_set_current_user( 0 );
		$this->assertArrayNotHasKey( 'hosting', $this->menu() );
	}

	public function test_an_empty_page_shows_the_empty_text_and_no_button_without_an_address(): void {
		$html = $this->page();

		$this->assertStringContainsString( 'You have no hosting yet.', $html );
		$this->assertStringNotContainsString( 'list-btn', $html );
	}

	public function test_the_order_page_setting_gives_the_button(): void {
		$this->settings( array( 'hosting_order_url' => '/wordpress-hosting/#plans' ) );
		$html = $this->page();

		$this->assertSame( 1, substr_count( $html, 'beltoft-services-list-btn' ) );
		$this->assertStringContainsString( '>Order hosting<', $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/wordpress-hosting/#plans' ) ) . '"', $html );
	}

	public function test_without_the_setting_the_first_hosting_product_is_used(): void {
		$product = $this->service_product( 'hosting', array(), array( 'slug' => 'hosting-plan' ) );

		$this->assertSame( (string) get_permalink( $product->get_id() ), ServiceArea::get( Hosting::class )->url_add() );
	}

	public function test_a_full_address_works_a_scheme_less_one_does_not_and_the_filter_has_the_last_word(): void {
		$area = ServiceArea::get( Hosting::class );

		$this->settings( array( 'hosting_order_url' => 'https://shop.example.org/hosting/' ) );
		$this->assertSame( 'https://shop.example.org/hosting/', $area->url_add() );

		$this->settings( array( 'hosting_order_url' => '//evil.example/' ) );
		$this->assertSame( '', $area->url_add() );

		add_filter( 'beltoft_services_hosting_add_url', static fn() => 'https://example.org/buy/' );
		$this->assertSame( 'https://example.org/buy/', $area->url_add() );
	}
}

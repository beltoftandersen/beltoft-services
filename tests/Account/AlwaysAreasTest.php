<?php
/** My Account: Servers, Databases, Domains and Email are there for every customer, with a create button. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Databases;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\Email;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\Servers;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Tests\TestCase;

class AlwaysAreasTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create() );
		Overview::reset();
	}

	public function tear_down(): void {
		foreach ( array( 'vps', 'databases', 'domains', 'email' ) as $slug ) {
			unset( $GLOBALS['wp']->query_vars[ $slug ] );
		}
		foreach ( array( 'servers', 'databases', 'domains', 'email' ) as $area ) {
			remove_all_filters( 'beltoft_services_' . $area . '_add_url' );
		}
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function product( string $driver ): string {
		$product = $this->service_product( $driver, array(), array( 'slug' => $driver . '-' . wp_generate_password( 5, false ) ) );

		return (string) get_permalink( $product->get_id() );
	}

	/** @return array<string, string> */
	private function menu(): array {
		return apply_filters(
			'woocommerce_account_menu_items',
			array(
				'dashboard'       => 'Dashboard',
				'orders'          => 'Orders',
				'customer-logout' => 'Log out',
			)
		);
	}

	private function page( string $area ): string {
		$GLOBALS['wp']->query_vars[ 'servers' === $area ? 'vps' : $area ] = '';
		ob_start();
		switch ( $area ) {
			case 'servers':
				Servers::render( array() );
				break;
			case 'domains':
				Domains::render( array() );
				break;
			case 'databases':
				ServiceArea::get( Databases::class )->render( array() );
				break;
			default:
				ServiceArea::get( Email::class )->render( array() );
		}

		return (string) ob_get_clean();
	}

	public function test_the_four_areas_are_in_the_menu_of_a_customer_with_no_licenses(): void {
		$menu = $this->menu();

		foreach ( array( 'vps', 'databases', 'domains', 'email' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $menu, $slug );
		}

		$this->assertArrayNotHasKey( 'apps', $menu, 'Apps stay for customers who have one.' );
		$keys = array_keys( $menu );
		$this->assertSame( 'customer-logout', end( $keys ), 'Log out stays last.' );
		$this->assertLessThan( array_search( 'domains', $keys, true ), array_search( 'vps', $keys, true ) );
		$this->assertSame( array_search( 'domains', $keys, true ) + 1, array_search( 'email', $keys, true ), 'Email follows Domains.' );
	}

	public function test_a_visitor_gets_no_service_items(): void {
		wp_set_current_user( 0 );
		$menu = $this->menu();

		foreach ( array( 'vps', 'databases', 'domains', 'email' ) as $slug ) {
			$this->assertArrayNotHasKey( $slug, $menu, $slug );
		}
	}

	public function test_an_empty_page_shows_the_empty_text_and_one_button_to_the_first_product(): void {
		$urls = array(
			'servers'   => array( 'proxmox', 'Create a server', 'You have no servers yet.', Servers::url_new() ),
			'databases' => array( 'proxmox-db', 'Create a database', 'You have no databases yet.', ServiceArea::get( Databases::class )->url_new() ),
			'email'     => array( 'mail', 'Create an email domain', 'You have no email domains yet.', null ),
			'domains'   => array( 'domain', 'Register a domain', 'You have no domains yet.', null ),
		);

		foreach ( $urls as $area => list( $driver, $label, $text, $expected ) ) {
			$this->assertStringNotContainsString( 'list-btn', $this->page( $area ), 'No product, no button: ' . $area );
			$this->assertStringContainsString( $text, $this->page( $area ), $area );

			$product = $this->product( $driver );
			$html    = $this->page( $area );

			$this->assertStringContainsString( $text, $html, $area );
			$this->assertStringContainsString( 'domains' === $area ? 'domains is-empty' : 'is-start', $html, $area );
			$this->assertSame( 1, substr_count( $html, 'beltoft-services-list-btn' ), $area );
			$this->assertStringContainsString( '>' . $label . '<', $html, $area );
			$this->assertStringContainsString( 'href="' . esc_url( $expected ?? $product ) . '"', $html, $area );
		}
	}

	public function test_the_button_address_can_be_changed_or_removed_with_a_filter(): void {
		foreach ( array( 'servers' => 'proxmox', 'databases' => 'proxmox-db', 'email' => 'mail', 'domains' => 'domain' ) as $area => $driver ) {
			$this->product( $driver );
			add_filter( 'beltoft_services_' . $area . '_add_url', static fn() => 'https://example.org/buy/' . $area );
			$this->assertStringContainsString( 'href="https://example.org/buy/' . $area . '"', $this->page( $area ), $area );

			add_filter( 'beltoft_services_' . $area . '_add_url', '__return_empty_string', 20 );
			$this->assertStringNotContainsString( 'list-btn', $this->page( $area ), $area );
		}
	}

	public function test_the_domain_search_page_setting_comes_before_the_product(): void {
		$this->product( 'domain' );
		$this->settings( array( 'domain_search_url' => '/domains/' ) );

		$this->assertSame( home_url( '/domains/' ), Domains::url_register() );
	}

	public function test_the_overview_shows_every_area_empty_with_its_button(): void {
		$this->product( 'proxmox' );
		$this->product( 'proxmox-db' );
		$this->product( 'mail' );
		$this->product( 'domain' );
		Overview::reset();

		foreach ( array( 'servers' => 'Create a server', 'databases' => 'Create a database', 'mail' => 'Create an email domain', 'domains' => 'Register a domain' ) as $section => $label ) {
			$html = Overview::section( $section );

			$this->assertStringContainsString( 'is-empty', $html, $section );
			$this->assertStringContainsString( '>' . $label . '<', $html, $section );
		}

		$all = Overview::all();

		$this->assertSame( 4, substr_count( $all, ' is-empty' ) );
		$this->assertSame( '', Overview::section( 'apps' ) );
	}

	public function test_the_overview_empty_sections_have_no_button_without_a_product(): void {
		foreach ( array( 'servers', 'databases', 'mail', 'domains' ) as $section ) {
			$html = Overview::section( $section );

			$this->assertStringContainsString( 'is-empty', $html, $section );
			$this->assertStringNotContainsString( 'list-btn', $html, $section );
		}
	}
}

<?php
/** The small helpers the account areas share: moving a menu item, and the setting-to-address rule. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Domains\Domains;
use BeltoftServices\Mail\Email;
use BeltoftServices\Hosting\Hosting;
use BeltoftServices\Licences\Licences;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Ssl\Ssl;
use BeltoftServices\Tests\TestCase;

class AreaHelpersTest extends TestCase {
	public function test_each_menu_item_goes_right_after_its_neighbour_and_is_left_alone_without_it(): void {
		$d = ServiceArea::get( Domains::class )->slug();
		$e = ServiceArea::get( Email::class )->slug();
		$s = ServiceArea::get( Ssl::class )->slug();
		$l = ServiceArea::get( Licences::class )->slug();

		$items = array( 'dashboard' => 'D', $l => 'L', $s => 'S', 'vps' => 'V', $e => 'E', $d => 'Dom', 'customer-logout' => 'Out' );

		$this->assertSame( array( 'dashboard', $l, $s, 'vps', $d, $e, 'customer-logout' ), array_keys( Email::menu( $items ) ) );
		$this->assertSame( array( 'dashboard', $l, 'vps', $e, $s, $d, 'customer-logout' ), array_keys( Ssl::menu( $items ) ) );
		$this->assertSame( array( 'dashboard', $s, $l, 'vps', $e, $d, 'customer-logout' ), array_keys( Licences::menu( $items ) ) );
		$this->assertSame( 'E', Email::menu( $items )[ $e ] );

		foreach ( array( Email::class, Ssl::class, Licences::class ) as $class ) {
			$this->assertSame( array( 'a' => 'A' ), $class::menu( array( 'a' => 'A' ) ) );
			$this->assertSame( 'x', $class::menu( 'x' ) );
		}
	}

	public function test_the_setting_rule_for_a_page_address_is_the_same_for_every_area(): void {
		foreach ( array( 'hosting_order_url', 'mail_order_url', 'domain_search_url' ) as $key ) {
			$this->settings( array( $key => '/some/path/' ) );
			$this->assertSame( home_url( '/some/path/' ), ServiceArea::setting_url( $key ), $key );
			$this->settings( array( $key => ' https://shop.example.org/x ' ) );
			$this->assertSame( 'https://shop.example.org/x', ServiceArea::setting_url( $key ), $key );
			$this->settings( array( $key => '//evil.example/' ) );
			$this->assertSame( '', ServiceArea::setting_url( $key ), $key );
			$this->settings( array( $key => '' ) );
			$this->assertSame( '', ServiceArea::setting_url( $key ), $key );
		}
	}

	public function test_the_email_order_page_needs_a_product_to_show_a_button(): void {
		$this->settings( array( 'mail_order_url' => '/email/' ) );
		$this->assertSame( '', ServiceArea::get( Email::class )->url_add() );

		$product = $this->service_product( 'mail', array(), array( 'slug' => 'mail-plan' ) );
		$this->assertSame( home_url( '/email/' ), ServiceArea::get( Email::class )->url_add() );

		$this->settings( array( 'mail_order_url' => '' ) );
		$this->assertSame( (string) get_permalink( $product->get_id() ), ServiceArea::get( Email::class )->url_add() );
	}

	public function test_the_product_url_filter_maps_a_product_to_its_landing_page(): void {
		$this->assertSame( '', ServiceArea::product_url( array( 'ssl' ) ), 'No product: no address, and the filter does not run.' );
		$product = $this->service_product( 'ssl', array(), array( 'slug' => 'ssl-landing' ) );
		$default = (string) get_permalink( $product->get_id() );
		$this->assertSame( $default, ServiceArea::product_url( array( 'ssl' ) ), 'Without a listener the address is the product page.' );

		$seen = array();
		add_filter(
			'beltoft_services_product_url',
			static function ( $url, $drivers, $id ) use ( &$seen ) {
				$seen = array( $url, $drivers, $id );

				return 'https://example.org/certificates/';
			},
			10,
			3
		);
		$this->assertSame( 'https://example.org/certificates/', ServiceArea::product_url( array( 'ssl' ) ) );
		$this->assertSame( array( $default, array( 'ssl' ), $product->get_id() ), $seen );
		$this->assertSame( 'https://example.org/certificates/', ServiceArea::get( Ssl::class )->url_add() );

		remove_all_filters( 'beltoft_services_product_url' );
		add_filter( 'beltoft_services_product_url', static fn() => '' );
		$this->assertSame( $default, ServiceArea::product_url( array( 'ssl' ) ), 'An empty answer keeps the default.' );
		add_filter( 'beltoft_services_product_url', static fn() => array( 'x' ), 20 );
		$this->assertSame( $default, ServiceArea::product_url( array( 'ssl' ) ), 'A non-string answer keeps the default.' );
		remove_all_filters( 'beltoft_services_product_url' );
	}
}

<?php
/** My Account, the overview and the order pages when the hosting area is not offered (Hosting not loaded; with Hosting loaded the test takes its area out of the list). @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class NoHostingAreaTest extends TestCase {
	private int $user = 0;

	/** @var callable */
	private $without_hosting;

	public function set_up(): void {
		parent::set_up();
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
		$this->fake_driver();
		// The offer is a closure, so the test takes the class out of the list after every feature added its own.
		$this->without_hosting = ( function ( array $skip ) {
			return static fn( $classes ) => array_values( array_diff( (array) $classes, $skip ) );
		} )( $this->optional( 'account_area_classes' ) );
		add_filter( 'beltoft_services_account_areas', $this->without_hosting, 99 );
	}

	public function tear_down(): void {
		remove_filter( 'beltoft_services_account_areas', $this->without_hosting, 99 );
		remove_all_filters( 'beltoft_services_welcome_cards' );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	public function test_the_area_is_gone_and_find_says_so(): void {
		$this->assertNull( ServiceArea::find( 'hosting' ) );
		$this->assertNotNull( ServiceArea::find( 'databases' ) );
		$this->expectException( \OutOfBoundsException::class );
		ServiceArea::by_key( 'hosting' );
	}

	public function test_the_menu_has_no_hosting_tab_and_does_not_throw(): void {
		$menu = apply_filters( 'woocommerce_account_menu_items', array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );

		$this->assertArrayNotHasKey( 'hosting', $menu );
		$this->assertArrayHasKey( 'databases', $menu );
		$this->assertArrayHasKey( 'domains', $menu );
	}

	public function test_the_overview_of_a_customer_with_nothing_has_no_hosting_section(): void {
		$html = Overview::section( 'hosting' );
		$this->assertSame( '', $html );

		$all = Overview::all();
		$this->assertStringNotContainsString( 'hosting', strtolower( $all ), 'No hosting heading, class or link.' );
		$this->assertStringContainsString( 'beltoft-services-ov', $all );
	}

	public function test_the_overview_of_a_customer_with_services_has_no_hosting_section(): void {
		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'nh-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $resource );
		Overview::reset();

		$data = Overview::data();
		$this->assertSame( array(), $data['hosting'] );
		$this->assertSame( '', $data['hosting_url'] );
		$this->assertSame( '', Overview::section( 'hosting' ) );
		$this->assertStringNotContainsString( 'ov__hosting', Overview::all() );
		$this->assertStringNotContainsString( 'class="beltoft-services-ov beltoft-services-ov--hosting', Overview::all() );
	}

	public function test_the_thank_you_page_of_a_hosting_order_prints_no_error(): void {
		$order = wc_create_order( array( 'customer_id' => $this->user ) );
		$order->set_status( 'completed' );
		$order->save();
		$product  = $this->service_product( 'fake', array(), array( 'slug' => 'nh2-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $this->user ) );
		$resource = Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
		$resource->set( array( 'driver' => 'hosting' ) );

		global $wp;
		$wp->query_vars['order-received'] = $order->get_id();
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
		ob_start();
		do_action( 'woocommerce_order_details_before_order_table', $order );
		do_action( 'woocommerce_thankyou', $order->get_id() );
		$html = (string) ob_get_clean();
		remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		unset( $wp->query_vars['order-received'] );

		$this->assertStringNotContainsString( 'Warning', $html );
		$this->assertStringNotContainsString( 'Notice', $html );
		$this->assertStringNotContainsString( 'Fatal', $html );
	}
}

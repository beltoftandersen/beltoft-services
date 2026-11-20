<?php
/**
 * The hand-off screen after payment: which orders get it, where they go, what the template says.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\OrderHandoff;
use BeltoftServices\Resource;

/**
 * Nothing here calls a server: orders, resource meta and the registered fake driver only.
 */
final class OrderHandoffTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$this->fake_driver();
		$this->settings( array( OrderHandoff::SETTING => 1 ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		unset( $_GET['wc_stripe_product_checkout'] );

		parent::tear_down();
	}

	/**
	 * A paid order of a customer who is logged in, with one service.
	 *
	 * @param string $status   Status.
	 * @param bool   $service  Whether the order has a service.
	 * @param bool   $login    Whether the customer is the visitor.
	 */
	private function order( string $status = 'processing', bool $service = true, bool $login = true ): \WC_Order {
		$user  = self::factory()->user->create();
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_status( $status );
		$order->save();

		if ( $service ) {
			$product = $this->service_product( 'fake', array(), array( 'slug' => 'ho-' . wp_generate_password( 6, false ) ) );
			$license = $this->service_license( $product, array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $user ) );
			Resource::create( $license, Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
		}

		wp_set_current_user( $login ? $user : 0 );

		return wc_get_order( $order->get_id() );
	}

	public function test_a_paid_order_with_a_service_gets_it_and_goes_to_the_overview_with_its_id(): void {
		foreach ( array( 'processing', 'completed' ) as $status ) {
			$order = $this->order( $status );

			$this->assertTrue( OrderHandoff::applies( $order ), $status );
			$this->assertSame( add_query_arg( 'order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) ), OrderHandoff::target( $order ) );
		}
	}

	public function test_a_paid_order_whose_services_are_not_made_yet_gets_it_from_its_service_line(): void {
		$user    = self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user, 'status' => 'processing' ) );
		$product = $this->service_product( 'fake', array(), array( 'slug' => 'ho-' . wp_generate_password( 6, false ) ) );
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->save();
		wp_set_current_user( $user );
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( array(), \BeltoftServices\ThankYou::resources( $order ), 'No licence or resource yet, as right after payment.' );
		$this->assertTrue( OrderHandoff::has_service_lines( $order ) );
		$this->assertTrue( OrderHandoff::applies( $order ) );
		$this->assertSame( add_query_arg( 'order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) ), OrderHandoff::target( $order ) );
	}

	public function test_the_setting_is_off_by_default_and_off_gives_the_normal_page(): void {
		$this->assertSame( 0, (int) \BeltoftServices\Settings::fields()[ OrderHandoff::SETTING ]['default'] );

		$order = $this->order();
		$this->settings( array( OrderHandoff::SETTING => 0 ) );

		$this->assertFalse( OrderHandoff::applies( $order ) );
	}

	/**
	 * @dataProvider normal_page_cases
	 *
	 * @param string $status  Status.
	 * @param bool   $service Whether the order has a service.
	 * @param bool   $login   Whether the customer is the visitor.
	 */
	public function test_these_orders_keep_the_normal_page( string $status, bool $service, bool $login ): void {
		$this->assertFalse( OrderHandoff::applies( $this->order( $status, $service, $login ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	public static function normal_page_cases(): array {
		return array(
			'pending'      => array( 'pending', true, true ),
			'on hold'      => array( 'on-hold', true, true ),
			'failed'       => array( 'failed', true, true ),
			'cancelled'    => array( 'cancelled', true, true ),
			'no services'  => array( 'processing', false, true ),
			'logged out'   => array( 'processing', true, false ),
		);
	}

	public function test_another_customers_order_keeps_the_normal_page(): void {
		$order = $this->order();
		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( OrderHandoff::applies( $order ) );
	}

	public function test_the_filter_can_keep_the_normal_page(): void {
		$order = $this->order();
		add_filter( 'beltoft_services_order_handoff', '__return_false' );

		$this->assertFalse( OrderHandoff::applies( $order ) );

		remove_filter( 'beltoft_services_order_handoff', '__return_false' );
	}

	public function test_the_express_checkout_return_keeps_the_normal_page(): void {
		$order = $this->order();
		$_GET['wc_stripe_product_checkout'] = '1';

		$this->assertFalse( OrderHandoff::applies( $order ) );
	}

	public function test_a_service_page_from_a_feature_is_the_target_even_without_new_services(): void {
		$order = $this->order( 'processing', false );
		$this->assertFalse( OrderHandoff::applies( $order ) );

		$page = static fn() => 'https://example.test/my-account/server/7/';
		add_filter( 'beltoft_services_order_handoff_target', $page );

		$this->assertTrue( OrderHandoff::applies( $order ) );
		$this->assertSame( 'https://example.test/my-account/server/7/', OrderHandoff::target( $order ) );

		remove_filter( 'beltoft_services_order_handoff_target', $page );
	}

	public function test_a_theme_that_draws_the_page_itself_gets_the_whole_hand_off_page(): void {
		$order = $this->order();
		set_query_var( 'order-received', $order->get_id() );
		$_GET['key'] = $order->get_order_key();

		$this->assertSame( $order->get_id(), OrderHandoff::page_order()->get_id() );
		$this->assertStringEndsWith( 'templates/order/handoff-page.php', OrderHandoff::page( 'theme-index.php' ) );

		$_GET['key'] = 'wc_order_wrong';
		$this->assertNull( OrderHandoff::page_order(), 'A wrong key is not the order.' );
		$this->assertSame( 'theme-index.php', OrderHandoff::page( 'theme-index.php' ) );

		$_GET['key'] = $order->get_order_key();
		$this->settings( array( OrderHandoff::SETTING => 0 ) );
		$this->assertSame( 'theme-index.php', OrderHandoff::page( 'theme-index.php' ), 'Off: the theme page stays.' );
		unset( $_GET['key'] );
		set_query_var( 'order-received', '' );
		$this->assertNull( OrderHandoff::page_order(), 'Any other page has no order.' );
	}

	public function test_only_the_thank_you_template_is_swapped_and_only_when_it_applies(): void {
		$order = $this->order();
		$mine  = BELTOFT_SERVICES_PATH . 'templates/order/handoff.php';

		$this->assertSame( $mine, OrderHandoff::swap( 'normal.php', 'checkout/thankyou.php', array( 'order' => $order ) ) );
		$this->assertSame( 'normal.php', OrderHandoff::swap( 'normal.php', 'checkout/form-pay.php', array( 'order' => $order ) ) );
		$this->assertSame( 'normal.php', OrderHandoff::swap( 'normal.php', 'checkout/thankyou.php', array( 'order' => false ) ) );

		$this->settings( array( OrderHandoff::SETTING => 0 ) );
		$this->assertSame( 'normal.php', OrderHandoff::swap( 'normal.php', 'checkout/thankyou.php', array( 'order' => $order ) ) );
	}

	public function test_the_swap_is_on_the_template_loader_hook(): void {
		$this->assertNotFalse( has_filter( 'wc_get_template', array( OrderHandoff::class, 'swap' ) ) );
		$this->assertFalse( has_filter( 'template_redirect', 'wp_redirect' ) );
	}

	public function test_the_template_has_the_target_no_order_key_and_the_normal_area_for_no_script(): void {
		$order = $this->order();

		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/order/handoff.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-bs-handoff', $html );
		$this->assertStringContainsString( 'Setting up your order', $html );
		$this->assertStringContainsString( 'Go to your dashboard now', $html );
		$this->assertStringContainsString( 'data-next="' . esc_url( OrderHandoff::target( $order ) ) . '"', $html );
		$this->assertStringNotContainsString( $order->get_order_key(), (string) preg_replace( '#<noscript>.*</noscript>#s', '', $html ) );
		$this->assertMatchesRegularExpression( '#<noscript>.*What happens next.*</noscript>#s', $html );
	}

	public function test_the_script_waits_for_the_callback_with_the_fixed_times_and_never_redirects_on_the_server(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/handoff.js' );

		$this->assertStringContainsString( 'eventCallback', $js );
		$this->assertStringContainsString( 'eventTimeout: EVENT_TIMEOUT', $js );
		$this->assertStringContainsString( 'EVENT_TIMEOUT = 2000', $js );
		$this->assertStringContainsString( 'MAX = 2200', $js );
		$this->assertStringContainsString( 'MIN = 600', $js );
		$this->assertStringContainsString( 'location.replace', $js );
		$this->assertStringNotContainsString( 'wp_redirect', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/OrderHandoff.php' ) );
	}
}

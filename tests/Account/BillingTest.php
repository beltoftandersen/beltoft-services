<?php
/** The Billing menu item and tab bar of My Account. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Billing;
use BeltoftServices\Settings;
use BeltoftServices\Tests\TestCase;

class BillingTest extends TestCase {
	private const MENU = array(
		'dashboard'       => 'Dashboard',
		'orders'          => 'Orders',
		'subscriptions'   => 'Subscriptions',
		'edit-address'    => 'Address',
		'payment-methods' => 'Payment methods',
		'edit-account'    => 'Account details',
		'licenses'        => 'Licenses',
		'vps'             => 'VPS',
		'domains'         => 'Domains',
		'tickets'         => 'Support',
		'customer-logout' => 'Log out',
	);

	public function set_up(): void {
		parent::set_up();
		Billing::reset();
		$user = self::factory()->user->create();
		wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		wp_set_current_user( $user );
		$this->billing( true );
	}

	public function tear_down(): void {
		$this->on( '' );
		remove_all_filters( 'beltoft_services_account_menu_order' );
		remove_all_filters( 'beltoft_services_account_menu_labels' );
		remove_all_filters( 'woocommerce_account_menu_items' );
		Billing::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function billing( bool $on ): void {
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), array( Billing::SETTING => $on ? 1 : 0 ) ) );
	}

	/** Pretends the customer is on this My Account endpoint ('' = the dashboard). */
	private function on( string $endpoint ): void {
		global $wp;
		foreach ( array_keys( WC()->query->get_query_vars() ) as $key ) {
			unset( $wp->query_vars[ $key ] );
		}
		if ( '' !== $endpoint ) {
			$wp->query_vars[ $endpoint ] = '';
		}
	}

	/** The menu as WooCommerce builds it, with the plugin's filter on top of the given list. */
	private function menu( array $raw = self::MENU ): array {
		add_filter( 'woocommerce_account_menu_items', static fn() => $raw, 98 );
		$items = wc_get_account_menu_items();
		remove_all_filters( 'woocommerce_account_menu_items' );
		Billing::register();

		return $items;
	}

	// -- Menu ----------------------------------------------------------------------------------

	public function test_the_four_billing_items_become_one_in_the_place_of_the_first(): void {
		$items = $this->menu();

		$this->assertSame( array( 'dashboard', 'subscriptions', 'edit-account', 'licenses', 'vps', 'domains', 'tickets', 'customer-logout' ), array_keys( $items ) );
		$this->assertSame( 'Billing', $items['subscriptions'] );
	}

	public function test_a_customer_without_subscriptions_keeps_the_orders_key_for_billing(): void {
		$raw = self::MENU;
		unset( $raw['subscriptions'] );
		$items = $this->menu( $raw );

		$this->assertSame( 'Billing', $items['orders'] );
		$this->assertArrayNotHasKey( 'subscriptions', $items );
		$this->assertArrayNotHasKey( 'payment-methods', $items );
		$this->assertArrayNotHasKey( 'edit-address', $items );
	}

	public function test_with_no_subscriptions_billing_points_at_orders_and_the_tab_is_hidden(): void {
		wp_set_current_user( self::factory()->user->create() );
		$items = $this->menu();

		// WooCommerce Subscriptions lists the item for everybody; the customer has no subscription.
		$this->assertSame( 'Billing', $items['orders'] );
		$this->assertArrayNotHasKey( 'subscriptions', $items );

		$this->on( 'orders' );
		$this->menu();
		$this->on( 'orders' );
		$html = Billing::html();

		$this->assertStringNotContainsString( '>Subscriptions</a>', $html );
		$this->assertStringContainsString( '>Orders and invoices</a>', $html );
		$this->assertContains( 'is-active', Billing::classes( array(), 'orders' ) );
	}

	public function test_off_by_default_and_when_the_setting_is_off_the_menu_is_untouched(): void {
		$this->billing( false );

		$this->assertSame( self::MENU, $this->menu() );
		$this->assertFalse( Billing::enabled() );
		delete_option( Settings::OPTION );
		$this->assertFalse( Billing::enabled() );
	}

	public function test_items_that_are_not_listed_stay_hidden(): void {
		$raw = self::MENU;
		unset( $raw['vps'], $raw['domains'], $raw['licenses'] );
		$items = $this->menu( $raw );

		$this->assertArrayNotHasKey( 'vps', $items );
		$this->assertArrayNotHasKey( 'domains', $items );
		$this->assertArrayNotHasKey( 'licenses', $items );
	}

	public function test_a_site_can_set_the_order_and_the_labels(): void {
		add_filter( 'beltoft_services_account_menu_order', static fn() => array( 'dashboard', 'vps', 'domains', 'licenses', 'subscriptions', 'tickets', 'edit-account', 'customer-logout' ) );
		add_filter( 'beltoft_services_account_menu_labels', static fn() => array( 'dashboard' => 'Overview', 'vps' => 'Servers', 'domains' => 'Domains & DNS', 'nothing' => 'x' ) );
		$items = $this->menu();

		$this->assertSame( array( 'dashboard', 'vps', 'domains', 'licenses', 'subscriptions', 'tickets', 'edit-account', 'customer-logout' ), array_keys( $items ) );
		$this->assertSame( 'Overview', $items['dashboard'] );
		$this->assertSame( 'Servers', $items['vps'] );
		$this->assertSame( 'Domains & DNS', $items['domains'] );
		$this->assertSame( 'Licenses', $items['licenses'] );
	}

	public function test_an_item_the_order_leaves_out_stays_and_log_out_stays_last(): void {
		add_filter( 'beltoft_services_account_menu_order', static fn() => array( 'dashboard', 'licenses' ) );
		$items = $this->menu();
		$keys  = array_keys( $items );

		$this->assertSame( array( 'dashboard', 'licenses' ), array_slice( $keys, 0, 2 ) );
		$this->assertSame( 'customer-logout', end( $keys ) );
		$this->assertContains( 'tickets', $keys );
		$this->assertContains( 'edit-account', $keys );
	}

	public function test_a_listed_item_that_does_not_exist_is_not_invented(): void {
		add_filter( 'beltoft_services_account_menu_order', static fn() => array( 'vps', 'dashboard' ) );
		$raw = self::MENU;
		unset( $raw['vps'] );

		$this->assertArrayNotHasKey( 'vps', $this->menu( $raw ) );
	}

	public function test_billing_is_marked_active_on_every_billing_page_and_nowhere_else(): void {
		$this->menu();
		$billing = array( 'orders', 'view-order', 'subscriptions', 'view-subscription', 'subscription-payment-method', 'payment-methods', 'add-payment-method', 'edit-address' );

		foreach ( $billing as $endpoint ) {
			$this->on( $endpoint );
			$this->menu();
			$this->on( $endpoint );
			$this->assertContains( 'is-active', Billing::classes( array( 'x' ), 'subscriptions' ), $endpoint );
		}

		foreach ( array( '', 'licenses', 'vps', 'edit-account' ) as $endpoint ) {
			$this->on( $endpoint );
			$this->menu();
			$this->on( $endpoint );
			$this->assertNotContains( 'is-active', Billing::classes( array( 'x' ), 'subscriptions' ), $endpoint );
		}
	}

	public function test_the_active_class_is_not_added_to_other_items_or_when_off(): void {
		$this->on( 'view-order' );
		$this->menu();
		$this->on( 'view-order' );
		$this->assertNotContains( 'is-active', Billing::classes( array(), 'licenses' ) );
		$this->billing( false );
		$this->assertNotContains( 'is-active', Billing::classes( array(), 'subscriptions' ) );
	}

	// -- Tab bar -------------------------------------------------------------------------------

	public function test_the_bar_lists_the_four_tabs_with_the_current_one_marked(): void {
		$this->menu();
		$this->on( 'payment-methods' );
		$this->menu();
		$this->on( 'payment-methods' );
		$html = Billing::html();

		foreach ( array( 'Subscriptions', 'Orders and invoices', 'Payment methods', 'Addresses' ) as $label ) {
			$this->assertStringContainsString( '>' . $label . '</a>', $html );
		}

		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertMatchesRegularExpression( '#aria-current="page" class="is-active">Payment methods</a>#', $html );
		$this->assertStringContainsString( 'aria-label="Billing"', $html );
		$this->assertStringContainsString( esc_url( wc_get_account_endpoint_url( 'orders' ) ), $html );
	}

	public function test_the_bar_shows_on_the_sub_pages_with_the_parent_tab_marked(): void {
		$cases = array(
			'view-subscription'           => 'Subscriptions',
			'subscription-payment-method' => 'Subscriptions',
			'view-order'                  => 'Orders and invoices',
			'add-payment-method'          => 'Payment methods',
			'edit-address'                => 'Addresses',
		);

		foreach ( $cases as $endpoint => $label ) {
			$this->on( $endpoint );
			$this->menu();
			$this->on( $endpoint );
			$html = Billing::html();

			$this->assertStringContainsString( 'aria-current="page" class="is-active">' . $label . '</a>', $html, $endpoint );
		}
	}

	public function test_the_bar_prints_nothing_outside_billing_for_visitors_or_when_off(): void {
		foreach ( array( '', 'licenses', 'vps', 'edit-account', 'tickets' ) as $endpoint ) {
			$this->on( $endpoint );
			$this->menu();
			$this->on( $endpoint );
			$this->assertSame( '', Billing::html(), $endpoint );
		}

		$this->on( 'orders' );
		$this->menu();
		$this->on( 'orders' );
		$this->assertNotSame( '', Billing::html() );

		$this->billing( false );
		$this->assertSame( '', Billing::html() );
		$this->billing( true );
		wp_set_current_user( 0 );
		$this->assertSame( '', Billing::html() );
	}

	public function test_a_tab_the_customer_does_not_have_is_left_out_unless_it_is_the_page_shown(): void {
		$raw = self::MENU;
		unset( $raw['subscriptions'] );
		add_filter( 'woocommerce_account_menu_items', static fn() => $raw, 98 );
		$this->on( 'orders' );
		wc_get_account_menu_items();
		$this->on( 'orders' );
		$html = Billing::html();
		remove_all_filters( 'woocommerce_account_menu_items' );
		Billing::register();

		$this->assertStringNotContainsString( '>Subscriptions</a>', $html );
		$this->assertStringContainsString( '>Orders and invoices</a>', $html );

		$this->on( 'view-subscription' );
		add_filter( 'woocommerce_account_menu_items', static fn() => $raw, 98 );
		wc_get_account_menu_items();
		remove_all_filters( 'woocommerce_account_menu_items' );
		Billing::register();
		$this->assertStringContainsString( 'aria-current="page" class="is-active">Subscriptions</a>', Billing::html() );
	}

	public function test_the_labels_can_be_changed_and_are_escaped(): void {
		add_filter( 'beltoft_services_billing_tab_labels', static fn( $l ) => array( 'orders' => 'Orders <b>x</b>' ) + $l );
		$this->on( 'orders' );
		$this->menu();
		$this->on( 'orders' );
		$html = Billing::html();
		remove_all_filters( 'beltoft_services_billing_tab_labels' );

		$this->assertStringContainsString( 'Orders &lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
	}

	public function test_it_prints_once_whichever_way_it_is_placed(): void {
		$this->on( 'orders' );
		$this->menu();
		$this->on( 'orders' );
		$bar = do_shortcode( '[beltoft_billing_tabs]' );
		$this->assertStringContainsString( 'beltoft-services-billing-tabs', $bar );
		$this->assertSame( '', do_shortcode( '[beltoft_billing_tabs]' ), 'a second call prints nothing' );

		$element = (object) array( 'name' => 'woocommerce-account-page-v2' );
		$this->assertSame( '<div>a</div>', Billing::before_bricks_account( '<div>a</div>', $element ), 'already printed' );

		Billing::reset();
		$this->on( 'orders' );
		$this->menu();
		$this->on( 'orders' );
		$out = Billing::before_bricks_account( '<div>a</div>', $element );
		$this->assertStringStartsWith( '<nav class="beltoft-services-billing-tabs"', $out );
		$this->assertStringEndsWith( '<div>a</div>', $out );
		$this->assertSame( '<p>x</p>', Billing::before_bricks_account( '<p>x</p>', (object) array( 'name' => 'heading' ) ) );
	}

	public function test_the_bar_can_be_left_to_the_site(): void {
		add_filter( 'beltoft_services_billing_tabs_auto', '__return_false' );
		$this->on( 'orders' );
		$this->menu();
		$this->on( 'orders' );
		$element = (object) array( 'name' => 'woocommerce-account-page-v2' );
		$this->assertSame( '<div>a</div>', Billing::before_bricks_account( '<div>a</div>', $element ) );
		remove_all_filters( 'beltoft_services_billing_tabs_auto' );
	}

	public function test_the_setting_is_a_checkbox_in_my_account_that_is_off_by_default(): void {
		$field = Settings::fields()[ Billing::SETTING ];

		$this->assertSame( 'checkbox', $field['type'] );
		$this->assertSame( 'account', $field['section'] );
		$this->assertSame( 0, $field['default'] );
	}
}

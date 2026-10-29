<?php
/** The Danger zone tab of a domain page: auto-renew and stopping DNS hosting, through WooCommerce Subscriptions. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\DomainDanger;
use BeltoftServices\Account\Domains;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DomainDangerTest extends TestCase {
	private int $user = 0;
	private FakeDns $dns;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 0 ) );
		// Other tests clear the listeners of the tab hooks.
		DomainDanger::register();
		update_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle', 'yes' );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		// The test site has no gateway that takes subscription payments: manual is what the subscription says it is.
		add_filter( 'woocommerce_subscription_is_manual', static fn( $manual, $sub ) => true === $sub->get_requires_manual_renewal(), 10, 2 );
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( 'woocommerce_subscription_is_manual' );
		unset( $GLOBALS['wp']->query_vars['domains'] );
		delete_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function subscription( int $user, string $price, bool $manual = false, string $status = 'active' ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => $status ) );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Domain' );
		$product->set_regular_price( $price );
		$product->save();
		$sub->add_product( $product, 1 );
		if ( ! $manual ) {
			$sub->set_payment_method( 'bacs' );
		}
		// After the payment method: setting a gateway that takes no subscription payments turns manual on.
		$sub->set_requires_manual_renewal( $manual );
		$sub->calculate_totals();
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ) ) );
		$sub->save();
		return $sub;
	}

	private function service( int $user, string $name, string $driver, ?\WC_Subscription $sub, array $meta = array() ): Resource {
		$product = $this->service_product( $driver, array(), array( 'slug' => $driver . '-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub ? $sub->get_id() : null ) );
		$r       = Resource::create( $license, 'domain' === $driver ? new DomainDriver() : new DnsDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'domain' === $driver ? 'register' : 'dns', 'zone_ready' => true ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		return Resource::find( $r->id() );
	}

	private function page( Resource $r, string $tab = 'danger' ): string {
		$GLOBALS['wp']->query_vars['domains'] = (string) $r->license()['id'] . '/' . $tab;
		ob_start();
		Domains::render( array( View::license( $r->license() ) ) );
		return (string) ob_get_clean();
	}

	private function post( Resource $r, string $action, array $more = array(), ?int $user = null ): array {
		return DomainDanger::process( $user ?? $this->user, array_merge( array( 'action' => $action, 'license' => (int) $r->license()['id'] ), $more ) );
	}

	public function test_a_registered_domain_with_auto_renew_offers_to_turn_it_off(): void {
		$sub = $this->subscription( $this->user, '12.00' );
		$r   = $this->service( $this->user, 'example.com', 'domain', $sub, array( 'expires_at' => '2027-03-01 00:00:00' ) );
		$html = $this->page( $r );

		$this->assertMatchesRegularExpression( '#data-tab-link="danger"#', $html );
		$this->assertStringContainsString( 'Turn off auto-renew', $html );
		$this->assertStringContainsString( 'If you turn it off, you get an invoice by email instead of an automatic charge. The domain stays registered until', $html );
		$this->assertStringContainsString( 'beltoft_domain_danger', $html );
		$this->assertStringNotContainsString( '<dialog', $html, 'A registered domain has no stop dialog.' );
	}

	public function test_turning_auto_renew_off_and_on_uses_the_subscription_flag_and_leaves_a_note(): void {
		$sub = $this->subscription( $this->user, '12.00' );
		$r   = $this->service( $this->user, 'example.com', 'domain', $sub );

		$off = $this->post( $r, 'auto_renew_off' );
		$this->assertTrue( $off['ok'] );
		$fresh = wcs_get_subscription( $sub->get_id() );
		$this->assertTrue( (bool) $fresh->is_manual() );
		$this->assertStringContainsString( 'Turn auto-renew back on', $this->page( $r ) );
		$notes = implode( ' ', array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) ) );
		$this->assertStringContainsString( 'turned off automatic renewals', $notes );

		$on = $this->post( $r, 'auto_renew_on' );
		$this->assertTrue( $on['ok'] );
		$this->assertFalse( (bool) wcs_get_subscription( $sub->get_id() )->is_manual() );
	}

	public function test_the_toggle_setting_off_hides_the_tab_and_refuses_the_post(): void {
		update_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle', 'no' );
		$sub = $this->subscription( $this->user, '12.00' );
		$r   = $this->service( $this->user, 'example.com', 'domain', $sub );

		$this->assertStringNotContainsString( 'data-tab-link="danger"', $this->page( $r ) );
		$this->assertFalse( $this->post( $r, 'auto_renew_off' )['ok'] );
		$this->assertFalse( (bool) wcs_get_subscription( $sub->get_id() )->is_manual() );
	}

	public function test_another_customer_cannot_post_for_a_domain(): void {
		$sub   = $this->subscription( $this->user, '12.00' );
		$r     = $this->service( $this->user, 'example.com', 'domain', $sub );
		$other = self::factory()->user->create();
		$result = $this->post( $r, 'auto_renew_off', array(), $other );

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( (bool) wcs_get_subscription( $sub->get_id() )->is_manual() );
	}

	public function test_an_action_of_the_other_kind_is_refused(): void {
		$sub = $this->subscription( $this->user, '12.00' );
		$r   = $this->service( $this->user, 'example.com', 'domain', $sub );
		$this->assertFalse( $this->post( $r, 'stop_hosting', array( 'confirm' => 'example.com' ) )['ok'] );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_the_posts_are_rate_limited(): void {
		$sub = $this->subscription( $this->user, '12.00' );
		$r   = $this->service( $this->user, 'example.com', 'domain', $sub );
		$last = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$last = $this->post( $r, 'auto_renew_off' );
		}
		$this->assertFalse( $last['ok'] );
		$this->assertStringContainsString( 'Too many', $last['message'] );
	}

	public function test_a_dns_only_service_offers_stop_hosting_with_a_dialog_and_no_renewal_text(): void {
		$sub = $this->subscription( $this->user, '0', true );
		$r   = $this->service( $this->user, 'hosted.example', 'dns', $sub );
		$html = $this->page( $r );

		$this->assertMatchesRegularExpression( '#data-tab-link="danger"#', $html );
		$this->assertStringContainsString( '<dialog', $html );
		$this->assertStringContainsString( 'data-danger-domain="hosted.example"', $html );
		$this->assertStringContainsString( 'stop answering at once', $html );
		$this->assertStringNotContainsString( 'Turn off auto-renew', $html );
	}

	public function test_stop_hosting_needs_the_typed_domain_and_then_cancels_through_wcs(): void {
		$sub = $this->subscription( $this->user, '0', true );
		$r   = $this->service( $this->user, 'hosted.example', 'dns', $sub );

		$wrong = $this->post( $r, 'stop_hosting', array( 'confirm' => 'other.example' ) );
		$this->assertFalse( $wrong['ok'] );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status(), 'A wrong name changes nothing.' );

		$none = $this->post( $r, 'stop_hosting' );
		$this->assertFalse( $none['ok'] );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );

		$done = $this->post( $r, 'stop_hosting', array( 'confirm' => '  Hosted.Example ' ) );
		$this->assertTrue( $done['ok'] );
		$this->assertContains( wcs_get_subscription( $sub->get_id() )->get_status(), array( 'cancelled', 'pending-cancel' ) );
		$notes = implode( ' ', array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) ) );
		$this->assertStringContainsString( 'cancelled by the subscriber from their account page', $notes );
		$this->assertSame( Domains::url_list(), $done['redirect'] );
	}

	public function test_a_paid_dns_service_has_no_stop_button(): void {
		$sub = $this->subscription( $this->user, '5.00', true );
		$r   = $this->service( $this->user, 'paid.example', 'dns', $sub );
		$this->assertStringNotContainsString( 'data-tab-link="danger"', $this->page( $r ) );
	}

	public function test_nothing_is_ever_asked_of_the_registrar_or_the_dns_server(): void {
		$sub = $this->subscription( $this->user, '0', true );
		$r   = $this->service( $this->user, 'hosted.example', 'dns', $sub );
		$before = count( $this->dns->calls() );
		$this->post( $r, 'stop_hosting', array( 'confirm' => 'hosted.example' ) );
		$deletes = array_filter( array_slice( $this->dns->calls(), $before ), static fn( $c ) => 'delete_zone' === $c[0] );
		$this->assertSame( array(), $deletes, 'The zone is removed later by the normal release job, not here.' );
	}

	public function test_url_for_order_is_the_domain_page_for_one_domain_and_the_list_for_more(): void {
		$sub  = $this->subscription( $this->user, '12.00' );
		$r    = $this->service( $this->user, 'one.example', 'domain', $sub );
		$lic  = \BeltoftLicenses\Licenses::find( (int) $r->license()['id'] );
		\BeltoftLicenses\Licenses::update( (int) $lic['id'], array( 'order_id' => 4242 ) );
		$this->assertSame( Domains::url( $r->license() ), Domains::url_for_order( 4242, array( 'one.example' ) ) );
		$this->assertSame( Domains::url_list(), Domains::url_for_order( 4242, array( 'one.example', 'two.example' ) ) );
		$this->assertSame( Domains::url_list(), Domains::url_for_order( 4242, array( 'nope.example' ) ) );
	}
}

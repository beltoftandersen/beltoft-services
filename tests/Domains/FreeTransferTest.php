<?php
/** Free transfers: extensions the registrar moves without a year. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Billing\RenewalSweep;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Meta;
use BeltoftServices\Domains\PriceTags;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Search;
use BeltoftServices\Domains\ThankYou;
use BeltoftServices\Domains\Renewals;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;
use BeltoftServices\Tests\TestCase;

class FreeTransferTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_transfer_registrar', '__return_false' );
		add_filter( 'beltoft_services_key', static fn() => base64_encode( str_repeat( 'f', 32 ) ) );
		update_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment', 'no' );
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		delete_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment' );
		parent::tear_down();
	}

	/** The registrar table: dk and se move for free (known), com costs money, xx has no transfer price in the answer, nr has no renewal cost. */
	private function table( array $extra = array() ): void {
		update_option( Prices::OPTION, $extra + array(
			'dk' => array( 'create' => 9.0, 'renew' => 9.0, 'transfer' => 0.0, 'transfer_known' => true, 'restricted' => false, 'price' => 20 ),
			'se' => array( 'create' => 9.0, 'renew' => 9.0, 'transfer' => 0.0, 'transfer_known' => true, 'restricted' => false, 'price' => 20 ),
			'com' => array( 'create' => 10.0, 'renew' => 10.0, 'transfer' => 10.0, 'transfer_known' => true, 'restricted' => false, 'price' => 20 ),
			'xx' => array( 'create' => 10.0, 'renew' => 10.0, 'transfer' => 0.0, 'restricted' => false, 'price' => 20 ),
			'nr' => array( 'create' => 10.0, 'renew' => 0.0, 'transfer' => 0.0, 'transfer_known' => true, 'restricted' => false, 'price' => 20 ),
		) );
	}

	private function on(): void {
		$this->settings( array( 'domain_free_transfers' => 1 ) );
	}

	/** A yearly subscription product of a service driver. */
	private function yearly( string $driver, float $price, string $slug, string $trial_paid = 'no' ): \WC_Product {
		$product = $this->service_product( $driver, array(), array( 'slug' => $slug, 'trial_requires_payment' => $trial_paid ) );
		wp_set_object_terms( $product->get_id(), 'subscription', 'product_type' );
		clean_post_cache( $product->get_id() );
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $product->get_id() );
		foreach ( array( '_subscription_price' => (string) $price, '_subscription_period' => 'year', '_subscription_period_interval' => '1', '_subscription_length' => '0', '_subscription_sign_up_fee' => '0', '_subscription_trial_length' => '0', '_subscription_trial_period' => 'day' ) as $key => $value ) {
			update_post_meta( $product->get_id(), $key, $value );
		}
		update_post_meta( $product->get_id(), '_price', (string) $price );
		wc_delete_product_transients( $product->get_id() );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( \WC_Product_Subscription::class, $fresh );
		return $fresh;
	}

	private function domain_product(): \WC_Product {
		static $product = null;
		if ( null === $product || ! wc_get_product( $product->get_id() ) ) { $product = $this->yearly( 'domain', 20.0, 'domain-ft' ); }
		return $product;
	}

	private function add( \WC_Product $product, string $domain, string $mode ): string {
		$data = Cart::line_data( $domain, $mode, 'transfer-code-1' );
		$this->assertIsArray( $data );
		$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $data );
		$this->assertNotFalse( $key );
		return (string) $key;
	}

	private function recurring_total(): float {
		$total = 0.0;
		foreach ( (array) WC()->cart->recurring_carts as $recurring ) { $total += (float) $recurring->total; }
		return $total;
	}

	private function totals(): void {
		WC()->cart->calculate_totals();
	}

	// ---- R1: an explicit zero is not a missing price ----

	public function test_the_registrar_refresh_marks_an_explicit_zero_transfer_price_as_known_and_a_missing_one_as_unknown(): void {
		$eur = static fn( $price ) => array( 'reseller' => array( 'price' => $price, 'currency' => 'EUR' ) );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/tlds', array( 'results' => array(
			array( 'name' => 'dk', 'status' => 'ACT', 'prices' => array( 'create_price' => $eur( 9 ), 'renew_price' => $eur( 9 ), 'transfer_price' => $eur( 0 ) ) ),
			array( 'name' => 'xx', 'status' => 'ACT', 'prices' => array( 'create_price' => $eur( 9 ), 'renew_price' => $eur( 9 ) ) ),
			array( 'name' => 'yy', 'status' => 'ACT', 'prices' => array( 'create_price' => $eur( 9 ), 'renew_price' => $eur( 9 ), 'transfer_price' => null ) ),
			array( 'name' => 'com', 'status' => 'ACT', 'prices' => array( 'create_price' => $eur( 9 ), 'renew_price' => $eur( 9 ), 'transfer_price' => $eur( 8.5 ) ) ),
		) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertSame( 4, Prices::sync() );
		$table = get_option( Prices::OPTION );
		$this->assertTrue( $table['dk']['transfer_known'] );
		$this->assertTrue( $table['com']['transfer_known'] );
		$this->assertFalse( $table['xx']['transfer_known'] );
		$this->assertFalse( $table['yy']['transfer_known'] );
		$this->assertSame( 0.0, $table['xx']['transfer'], 'The stored cost keeps its shape.' );
	}

	public function test_the_free_transfer_rule(): void {
		$this->table();
		$this->assertFalse( Prices::free_transfer( 'example.dk' ), 'The setting is off.' );
		$this->on();
		$this->assertTrue( Prices::free_transfer( 'example.dk' ) );
		$this->assertTrue( Prices::free_transfer( 'example.se' ) );
		$this->assertFalse( Prices::free_transfer( 'example.com' ), 'A transfer that costs money is paid.' );
		$this->assertFalse( Prices::free_transfer( 'example.xx' ), 'R1: a zero the registrar never stated is not free.' );
		$this->assertFalse( Prices::free_transfer( 'example.nr' ), 'No renewal cost above zero, nothing to charge later.' );
		$this->assertFalse( Prices::free_transfer( 'example.unknown' ), 'An extension that is not sold.' );
		$table = get_option( Prices::OPTION );
		unset( $table['dk']['transfer_known'] );
		update_option( Prices::OPTION, $table );
		$this->assertFalse( Prices::free_transfer( 'example.dk' ), 'An old row without the flag is not free until the next refresh.' );
	}

	public function test_a_site_can_veto_a_free_transfer_with_a_filter(): void {
		$this->table();
		$this->on();
		add_filter( 'beltoft_services_domain_free_transfer', static fn( $free, $domain ) => str_ends_with( $domain, '.se' ) ? false : $free, 10, 2 );
		$this->assertTrue( Prices::free_transfer( 'a.dk' ) );
		$this->assertFalse( Prices::free_transfer( 'a.se' ) );
	}

	public function test_the_setting_is_off_by_default(): void {
		$this->assertEmpty( \BeltoftServices\Settings::get( 'domain_free_transfers' ) );
	}

	// ---- cart: today 0, recurring the yearly price, a payment method ----

	public function test_a_free_transfer_costs_nothing_today_and_the_yearly_price_later_and_needs_a_payment_method(): void {
		$this->table();
		$this->on();
		$key = $this->add( $this->domain_product(), 'moveme.se', 'transfer' );
		$this->assertTrue( WC()->cart->cart_contents[ $key ]['beltoft_services_domain']['free_transfer'] );
		$this->totals();
		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		$this->assertEquals( 20, $this->recurring_total() );
		$this->assertTrue( WC()->cart->needs_payment(), 'The card is needed for the first yearly payment although today is 0.' );
		$product = WC()->cart->cart_contents[ $key ]['data'];
		$this->assertSame( 1, (int) \WC_Subscriptions_Product::get_trial_length( $product ) );
		$this->assertSame( 'year', \WC_Subscriptions_Product::get_trial_period( $product ) );
		$this->assertSame( 0, (int) \WC_Subscriptions_Product::get_trial_length( wc_get_product( $this->domain_product()->get_id() ) ), 'The stored product has no trial.' );
	}

	public function test_a_free_transfer_is_not_refused_as_a_second_trial_of_the_domain_product(): void {
		$this->table();
		$this->on();
		$product = $this->domain_product();
		$user = self::factory()->user->create();
		$this->service_license( $product, array( 'user_id' => $user, 'email' => 'owner@example.com' ) );
		wp_set_current_user( $user );
		$this->add( $product, 'second.se', 'transfer' );
		$this->totals();
		$errors = new \WP_Error();
		\BeltoftServices\Checkout::validate( array( 'billing_email' => 'owner@example.com' ), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'The customer already holds a domain: a free transfer is not a trial.' );
	}

	public function test_with_the_setting_off_a_zero_cost_transfer_is_one_paid_year_as_before(): void {
		$this->table();
		$key = $this->add( $this->domain_product(), 'moveme.se', 'transfer' );
		$this->assertArrayNotHasKey( 'free_transfer', WC()->cart->cart_contents[ $key ]['beltoft_services_domain'] );
		$this->totals();
		$this->assertEquals( 20, WC()->cart->get_total( 'edit' ) );
		$this->assertSame( 0, (int) \WC_Subscriptions_Product::get_trial_length( WC()->cart->cart_contents[ $key ]['data'] ) );
	}

	public function test_a_transfer_with_a_cost_and_a_registration_stay_paid_with_the_setting_on(): void {
		$this->table();
		$this->on();
		$one = $this->add( $this->domain_product(), 'paid.com', 'transfer' );
		$this->assertArrayNotHasKey( 'free_transfer', WC()->cart->cart_contents[ $one ]['beltoft_services_domain'] );
		$this->totals();
		$this->assertEquals( 20, WC()->cart->get_total( 'edit' ) );
		$this->assertSame( 0, (int) \WC_Subscriptions_Product::get_trial_length( WC()->cart->cart_contents[ $one ]['data'] ) );
	}

	public function test_an_unknown_zero_cost_gets_no_free_transfer(): void {
		$this->table();
		$this->on();
		$key = $this->add( $this->domain_product(), 'unknown.xx', 'transfer' );
		$this->assertArrayNotHasKey( 'free_transfer', WC()->cart->cart_contents[ $key ]['beltoft_services_domain'] );
		$this->totals();
		$this->assertEquals( 20, WC()->cart->get_total( 'edit' ) );
	}

	public function test_two_free_transfers_cost_nothing_and_get_separate_recurring_carts(): void {
		$this->table();
		$this->on();
		$this->add( $this->domain_product(), 'one.se', 'transfer' );
		$this->add( $this->domain_product(), 'two.se', 'transfer' );
		$this->totals();
		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		$this->assertEquals( 40, $this->recurring_total() );
		$this->assertCount( 2, WC()->cart->recurring_carts, 'Each domain keeps its own subscription.' );
		$this->assertTrue( WC()->cart->needs_payment() );
	}

	public function test_a_free_transfer_next_to_a_paid_registration_pays_only_the_registration_today(): void {
		$this->table();
		$this->on();
		$this->add( $this->domain_product(), 'moveme.se', 'transfer' );
		$this->add( $this->domain_product(), 'fresh.com', 'register' );
		$this->totals();
		$this->assertEquals( 20, WC()->cart->get_total( 'edit' ) );
		$this->assertEquals( 40, $this->recurring_total() );
	}

	public function test_a_free_transfer_next_to_hosting_pays_only_the_hosting_today(): void {
		$this->table();
		$this->on();
		$this->fake_driver();
		$hosting = $this->yearly( 'fake', 100.0, 'hosting-ft' );
		$this->add( $this->domain_product(), 'moveme.se', 'transfer' );
		WC()->cart->add_to_cart( $hosting->get_id() );
		$this->totals();
		$this->assertEquals( 100, WC()->cart->get_total( 'edit' ) );
		$this->assertEquals( 120, $this->recurring_total() );
	}

	public function test_a_stamped_line_whose_rule_no_longer_holds_becomes_a_paid_transfer_with_one_notice(): void {
		$this->table();
		$this->on();
		$key = $this->add( $this->domain_product(), 'moveme.se', 'transfer' );
		$this->totals();
		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		wc_clear_notices();
		$this->settings( array( 'domain_free_transfers' => 0 ) );
		$this->totals();
		$this->totals();
		$this->assertArrayNotHasKey( 'free_transfer', WC()->cart->cart_contents[ $key ]['beltoft_services_domain'] );
		$this->assertEquals( 20, WC()->cart->get_total( 'edit' ) );
		$this->assertSame( 0, (int) \WC_Subscriptions_Product::get_trial_length( WC()->cart->cart_contents[ $key ]['data'] ) );
		$this->assertCount( 1, wc_get_notices( 'notice' ) );
	}

	public function test_an_unstamped_line_never_gains_a_free_transfer(): void {
		$this->table();
		$key = $this->add( $this->domain_product(), 'moveme.se', 'transfer' );
		$this->on();
		$this->totals();
		$this->assertArrayNotHasKey( 'free_transfer', WC()->cart->cart_contents[ $key ]['beltoft_services_domain'] );
		$this->assertEquals( 20, WC()->cart->get_total( 'edit' ) );
	}

	public function test_a_stamp_on_a_registration_or_a_renewal_line_changes_nothing(): void {
		$this->table();
		$this->on();
		$product = $this->domain_product();
		$key = $this->add( $product, 'fresh.se', 'register' );
		WC()->cart->cart_contents[ $key ]['beltoft_services_domain']['free_transfer'] = true;
		$this->totals();
		$this->assertEquals( 20, WC()->cart->get_total( 'edit' ), 'Only a transfer line can be free.' );
		$this->assertArrayNotHasKey( 'free_transfer', WC()->cart->cart_contents[ $key ]['beltoft_services_domain'] );
	}

	// ---- order to resource ----

	public function test_the_stamp_reaches_the_resource_only_with_a_zero_line(): void {
		$product = $this->domain_product();
		foreach ( array( array( 0.0, true ), array( 20.0, false ) ) as list( $total, $expect ) ) {
			$order = wc_create_order();
			$item = new \WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( 1 );
			$item->set_subtotal( $total );
			$item->set_total( $total );
			$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => 'moveme-' . (int) $total . '.dk', 'mode' => 'transfer', 'free_transfer' => true ), true );
			$order->add_item( $item );
			$order->save();
			$meta = Meta::initial_meta( array(), array( 'id' => 900 + (int) $total, 'order_id' => $order->get_id(), 'product_id' => $product->get_id() ), new FakeDriver( 'domain' ) );
			$this->assertSame( $expect, ! empty( $meta['transfer_free'] ), 'Line total ' . $total );
		}
	}

	// ---- driver ----

	private function transfer_resource( array $meta = array() ): Resource {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-drv' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, '_beltoft_services_domain_handle', 'AA123-GB' );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		$order->update_meta_data( '_beltoft_services_dk_agreement', array( 'version' => 1 ) );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Domain', 'quantity' => 1, 'subtotal' => 0, 'total' => 0 ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$name = 'ft-' . strtolower( wp_generate_password( 6, false, false ) ) . '.dk';
		$r = Resource::create( $license, new DomainDriver(), array(), $meta + array( 'domain' => $name, 'mode' => 'transfer', 'auth' => Crypto::encrypt( 'transfer-secret' ), 'order_item_id' => (int) $line->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	private function fakes(): array {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains', array( 'results' => array() ) );
		$fake->reply( 'POST', '/domains/transfer', array( 'id' => 321, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/321', array( 'id' => 321, 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'GET', '/domains/321', array( 'expiration_date' => '2027-10-26 00:00:00' ) );
		$fake->reply( 'POST', '/domains/321/renew', array( 'status' => 'ACT' ) );
		$fake->reply( 'GET', '/domains/321', array( 'expiration_date' => '2028-10-26 00:00:00' ) );
		$fake->reply( 'PUT', '/domains/321', array( 'success' => true ) );
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_dns_proof_result', static fn( $pre, $call ) => 'delegation' === $call ? array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.old-host.example', 'ns2.old-host.example' ), 'ips' => array(), 'ds' => false, 'ours' => false ) : $pre, 10, 2 );
		return array( $fake, $dns );
	}

	private function posts( FakeClient $fake, string $path = '' ): int {
		return count( array_filter( $fake->calls(), static fn( $c ) => 'POST' === $c['method'] && ( '' === $path || $path === $c['path'] ) ) );
	}

	public function test_a_free_transfer_buys_no_prepaid_year_and_schedules_nothing(): void {
		$this->table();
		$r = $this->transfer_resource( array( 'transfer_free' => true, 'expires_at' => '2027-10-26 00:00:00', 'transfer_expiry_before' => '2027-10-26 00:00:00', 'remote_id' => '321' ) );
		$fake = new FakeClient();
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$result = Renewals::transfer_year( $r );
		$this->assertTrue( $result->is_done() );
		$this->assertSame( array(), $fake->calls(), 'No registrar call at all.' );
		$this->assertArrayNotHasKey( 'prepaid_expiry', $result->meta() );
		$this->assertArrayNotHasKey( 'transfer_renewed', $result->meta() );
		$this->assertFalse( as_next_scheduled_action( Renewals::TRANSFER_HOOK, array( $r->id() ), 'beltoft-services' ) );
		$this->assertSame( '', (string) $r->get( 'alerted' ), 'The spend guard was not asked.' );
	}

	public function test_a_free_transfer_through_the_driver_orders_the_move_and_never_renews(): void {
		$this->table();
		[ $fake ] = $this->fakes();
		$r = $this->transfer_resource( array( 'transfer_free' => true ) );
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$this->assertSame( 1, $this->posts( $fake, '/domains/transfer' ) );
		$this->assertSame( 0, $this->posts( $fake, '/domains/321/renew' ) );
		$saved = Resource::find( $r->id() );
		$this->assertEmpty( $saved->get( 'prepaid_expiry' ) );
		$this->assertEmpty( $saved->get( 'transfer_year_scheduled' ) );
		$this->assertSame( '2027-10-26 00:00:00', (string) $saved->get( 'expires_at' ) );
	}

	private function transfer_acts( int $id ): int {
		$n = 0;
		foreach ( as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Jobs::ACT, 'group' => \BeltoftServices\Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ) ) as $action ) {
			$args = $action->get_args();
			if ( (int) ( $args[0] ?? 0 ) === $id && 'transfer_renew' === ( $args[1] ?? '' ) ) { ++$n; }
		}
		return $n;
	}

	public function test_an_old_scheduled_prepaid_action_does_nothing_for_a_free_transfer(): void {
		$paid = $this->transfer_resource( array( 'state' => 'ready' ) );
		Renewals::on_transfer_due( $paid->id() );
		$this->assertSame( 1, $this->transfer_acts( $paid->id() ), 'Control: a paid transfer queues its prepaid renewal.' );
		$free = $this->transfer_resource( array( 'transfer_free' => true, 'state' => 'ready' ) );
		Renewals::on_transfer_due( $free->id() );
		$this->assertSame( 0, $this->transfer_acts( $free->id() ) );
	}

	public function test_with_the_setting_off_a_paid_dk_transfer_still_prepays_the_year(): void {
		$this->table();
		[ $fake ] = $this->fakes();
		$r = $this->transfer_resource();
		$result = ( new DomainDriver() )->provision( $r );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$saved = Resource::find( $r->id() );
		$this->assertSame( '2028-10-26 00:00:00', (string) $saved->get( 'prepaid_expiry' ), 'The prepaid year path is taken.' );
		$this->assertTrue( (bool) $saved->get( 'transfer_year_scheduled' ) );
		$this->assertNotFalse( as_next_scheduled_action( Renewals::TRANSFER_HOOK, array( $r->id() ), 'beltoft-services' ) );
		$this->assertSame( 0, $this->posts( $fake, '/domains/321/renew' ), 'Bought later, in the window.' );
	}

	// ---- dates ----

	/** @return \WC_Subscription A yearly subscription started now, in a one year trial as a free transfer cart makes it. */
	private function trial_subscription(): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => self::factory()->user->create(), 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$end = gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS );
		$sub->update_dates( array( 'trial_end' => $end, 'next_payment' => $end ) );
		return $sub;
	}

	private function dated( \WC_Subscription $sub, string $expiry ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-dt' ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'subscription_id' => $sub->get_id() ) );
		return Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'dates-' . wp_generate_password( 5, false, false ) . '.dk', 'mode' => 'transfer', 'transfer_free' => true, 'expires_at' => $expiry ) );
	}

	public function test_the_first_payment_of_a_free_transfer_falls_before_the_real_expiry_and_the_trial_ends_with_it(): void {
		$this->settings( array( 'domain_renew_days_before' => 30 ) );
		$sub = $this->trial_subscription();
		$expiry = gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS );
		$result = Renewals::set_dates( $this->dated( $sub, $expiry ) );
		$this->assertTrue( $result->is_done(), (string) $result->error() );
		$sub = wcs_get_subscription( $sub->get_id() );
		$this->assertEqualsWithDelta( time() + 170 * DAY_IN_SECONDS, $sub->get_time( 'next_payment' ), 5 );
		$this->assertLessThanOrEqual( $sub->get_time( 'next_payment' ), $sub->get_time( 'trial_end' ) );
	}

	public function test_an_expiry_nearer_than_the_days_before_charges_in_an_hour_never_in_the_past(): void {
		foreach ( array( time() + 10 * DAY_IN_SECONDS, time() - 5 * DAY_IN_SECONDS ) as $when ) {
			$sub = $this->trial_subscription();
			$result = Renewals::set_dates( $this->dated( $sub, gmdate( 'Y-m-d H:i:s', $when ) ) );
			$this->assertTrue( $result->is_done(), (string) $result->error() );
			$next = wcs_get_subscription( $sub->get_id() )->get_time( 'next_payment' );
			$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $next, 5 );
		}
	}

	public function test_an_unknown_expiry_retries_and_leaves_the_dates_alone(): void {
		$sub = $this->trial_subscription();
		$before = $sub->get_time( 'next_payment' );
		$result = Renewals::set_dates( $this->dated( $sub, '' ) );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( $before, wcs_get_subscription( $sub->get_id() )->get_time( 'next_payment' ) );
	}

	public function test_a_subscription_without_a_trial_keeps_its_dates_logic(): void {
		$sub = wcs_create_subscription( array( 'customer_id' => self::factory()->user->create(), 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS ) ) );
		$result = Renewals::set_dates( $this->dated( $sub, gmdate( 'Y-m-d H:i:s', time() + 100 * DAY_IN_SECONDS ) ) );
		$this->assertTrue( $result->is_done() );
		$sub = wcs_get_subscription( $sub->get_id() );
		$this->assertEqualsWithDelta( time() + 70 * DAY_IN_SECONDS, $sub->get_time( 'next_payment' ), 5 );
		$this->assertSame( 0, $sub->get_time( 'trial_end' ) );
	}

	public function test_a_trial_that_ends_before_the_next_payment_is_left_alone(): void {
		$sub = wcs_create_subscription( array( 'customer_id' => self::factory()->user->create(), 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$trial = time() + 3 * DAY_IN_SECONDS;
		$sub->update_dates( array( 'trial_end' => gmdate( 'Y-m-d H:i:s', $trial ), 'next_payment' => gmdate( 'Y-m-d H:i:s', $trial ) ) );
		$this->assertTrue( RenewalSweep::set_dates( $this->dated( $sub, gmdate( 'Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS ) ), gmdate( 'Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS ), 'x', 'y', 30 )->is_done() );
		$sub = wcs_get_subscription( $sub->get_id() );
		$this->assertEqualsWithDelta( $trial, $sub->get_time( 'trial_end' ), 5, 'A real trial that ends before the payment is not touched.' );
	}

	// ---- what the customer reads ----

	public function test_the_search_result_says_which_taken_domains_move_for_free(): void {
		$this->table();
		$this->settings( array( 'domain_featured_tlds' => 'se' ) );
		$fake = new FakeClient();
		foreach ( array( 'owned.se', 'owned.se', 'owned.com' ) as $name ) { $fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => $name, 'status' => 'taken' ) ) ) ); }
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$off = Search::find( 'owned.se' );
		$this->assertArrayNotHasKey( 'free_transfer', $off['results'][0], 'Off: the result keeps the shape it had.' );
		$this->on();
		$on = Search::find( 'owned.se' );
		$this->assertTrue( $on['results'][0]['free_transfer'] );
		$this->assertSame( 20, $on['results'][0]['price'], 'The yearly price is still the figure.' );
		$this->assertArrayNotHasKey( 'free_transfer', Search::find( 'owned.com' )['results'][0] );
	}

	public function test_the_transfer_price_tag_says_free_and_the_others_keep_their_figure(): void {
		$this->table();
		$text = static fn( string $tag ): string => (string) apply_filters( 'bricks/frontend/render_data', $tag, null );
		$this->assertNotSame( 'Free', $text( '{beltoft_domain_transfer:se}' ), 'Off: a figure.' );
		$this->on();
		PriceTags::forget();
		$this->assertSame( 'Free', $text( '{beltoft_domain_transfer:se}' ) );
		$this->assertNotSame( 'Free', $text( '{beltoft_domain_renew:se}' ) );
		$this->assertNotSame( 'Free', $text( '{beltoft_domain_register:se}' ) );
		$this->assertNotSame( 'Free', $text( '{beltoft_domain_transfer:com}' ), 'A transfer with a cost keeps its figure.' );
		$this->settings( array( 'domain_free_transfers' => 0 ) );
		$this->assertNotSame( 'Free', $text( '{beltoft_domain_transfer:se}' ), 'Turned off again in the same request.' );
	}

	public function test_the_cart_line_reads_as_a_transfer_not_a_trial_and_prints_no_first_renewal_date(): void {
		$this->table();
		$this->on();
		$key = $this->add( $this->domain_product(), 'moveme.se', 'transfer' );
		$this->totals();
		$item = WC()->cart->cart_contents[ $key ];
		$price = wp_strip_all_tags( (string) apply_filters( 'woocommerce_cart_item_price', '', $item, $key ) );
		$this->assertStringStartsWith( 'Free transfer, then', $price );
		$this->assertStringContainsString( '20', $price );
		$this->assertStringNotContainsStringIgnoringCase( 'trial', $price );
		$this->assertStringContainsString( '0.00', wp_strip_all_tags( (string) apply_filters( 'woocommerce_cart_item_subtotal', '', $item, $key ) ) );
		$recurring = array_values( (array) WC()->cart->recurring_carts )[0];
		$html = apply_filters( 'wcs_cart_totals_order_total_html', '<strong>20</strong><div class="first-payment-date"><small>First renewal: November 11, 2027</small></div>', $recurring );
		$this->assertStringNotContainsString( 'First renewal', $html );
		$this->assertStringContainsString( '<strong>20</strong>', $html );
		$paid = apply_filters( 'wcs_cart_totals_order_total_html', 'x<div class="first-payment-date">d</div>', new \WC_Cart() );
		$this->assertStringContainsString( 'first-payment-date', $paid, 'Other carts keep it.' );
	}

	public function test_the_thank_you_page_and_the_panel_say_when_the_first_payment_is_due(): void {
		$order = wc_create_order();
		foreach ( array( array( 'a.se', 0.0, true ), array( 'b.se', 20.0, true ), array( 'c.com', 20.0, false ) ) as list( $name, $total, $stamp ) ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product( $this->domain_product() );
			$item->set_total( $total );
			$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => $name, 'mode' => 'transfer' ) + ( $stamp ? array( 'free_transfer' => true ) : array() ), true );
			$order->add_item( $item );
		}
		$order->save();
		$this->assertSame( array( 'a.se' => true ), ThankYou::free_transfers( $order ) );
		$r = $this->transfer_resource( array( 'transfer_free' => true ) );
		ob_start();
		\BeltoftServices\Domains\Panels::admin( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Free transfer. The first yearly payment is due when the domain is up for renewal.', $html );
		$r->set( array( 'next_payment_at' => strtotime( '2027-09-26 00:00:00 UTC' ) ) );
		ob_start();
		\BeltoftServices\Domains\Panels::admin( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'is due on', (string) ob_get_clean() );
	}
}

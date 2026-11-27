<?php
/** Pay-to-restore from account credit: the credit pays first, the saved method pays the rest, and nothing is asked when it all covers. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Restore;
use BeltoftServices\Domains\Stage;
use BeltoftServices\Resource;
use BeltoftServices\Tests\GiftCredit\GiftCreditTestCase;

require_once dirname( __DIR__ ) . '/GiftCredit/GiftCreditTestCase.php';

class RestoreCreditTest extends GiftCreditTestCase {
	private int $customer = 0;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_wp_mail', static fn() => true );
		$this->settings( array( 'domain_restore_enabled' => 1, 'domain_unpaid_notices' => 1 ) );
		update_option(
			Prices::OPTION,
			array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 43.08, 'soft_quarantine_days' => 40, 'quarantine_days' => 0 ) )
		);
	}

	public function tear_down(): void {
		parent::tear_down();
		$this->settings( array( 'domain_restore_enabled' => 0, 'domain_unpaid_notices' => 0 ) );
	}

	private function expired(): Resource {
		$product        = $this->service_product( 'domain', array(), array( 'slug' => 'rc-' . wp_generate_password( 6, false ) ) );
		$this->customer = self::factory()->user->create( array( 'user_email' => 'christian.beltoft+' . wp_generate_password( 6, false ) . '@gmail.com' ) );
		$sub            = wcs_create_subscription( array( 'customer_id' => $this->customer, 'billing_period' => 'year', 'billing_interval' => 1, 'currency' => 'EUR' ) );
		$license        = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->customer, 'email' => 'christian.beltoft@gmail.com', 'subscription_id' => $sub->get_id() ) );
		$r              = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'credit-example.com', 'remote_id' => '777', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), 'state' => 'paused', 'mode' => 'register' ) );
		$sub->update_status( 'active' );
		$sub->update_status( 'on-hold' );
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );

		return Resource::find( $r->id() );
	}

	public function test_credit_that_covers_it_pays_the_order_and_nothing_is_asked(): void {
		$r    = $this->expired();
		$card = $this->card( $this->customer, 10000, \Bgcw\GiftCard\Source::ORDER );
		$out  = Restore::process( $this->customer, (int) $r->license()['id'] );
		$this->assertTrue( $out['ok'], $out['message'] );
		$this->assertTrue( $out['paid'] );
		$this->assertStringNotContainsString( 'pay_for_order', $out['redirect'] );
		$order = wc_get_order( (int) Resource::find( $r->id() )->get( 'restore_order' ) );
		$this->assertTrue( $order->is_paid(), 'Paid from credit.' );
		$this->assertSame( 6900, \Bgca\Pay::applied( $order ) );
		$this->assertSame( 10000 - 6900, $this->left( $card ), 'The credit was taken once.' );
		$this->assertCount( 2, $order->get_items( 'fee' ), 'The credit is not a line change: the two fee lines stay.' );
	}

	public function test_credit_that_covers_a_part_leaves_the_rest_to_the_payment_page_without_a_saved_method(): void {
		$r    = $this->expired();
		$card = $this->card( $this->customer, 2000, \Bgcw\GiftCard\Source::ORDER );
		$out  = Restore::process( $this->customer, (int) $r->license()['id'] );
		$this->assertFalse( $out['paid'] );
		$this->assertStringContainsString( 'pay_for_order', $out['redirect'] );
		$order = wc_get_order( (int) Resource::find( $r->id() )->get( 'restore_order' ) );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 4900, (int) round( (float) $order->get_total() * 100 ), 'The credit took off what it could.' );
		unset( $card );
	}

	public function test_credit_then_the_saved_method_pays_the_rest(): void {
		$r       = $this->expired();
		$charged = 0.0;
		$sub     = Stage::subscription( $r );
		$sub->set_payment_method( 'testgw' );
		$sub->set_requires_manual_renewal( false );
		$sub->save();
		add_action(
			'woocommerce_scheduled_subscription_payment_testgw',
			static function ( $amount, $order ) use ( &$charged ): void {
				$charged = (float) $amount;
				$order->payment_complete( 'test-charge' );
			},
			10,
			2
		);
		$this->card( $this->customer, 2000, \Bgcw\GiftCard\Source::ORDER );
		$out = Restore::process( $this->customer, (int) $r->license()['id'] );
		$this->assertTrue( $out['paid'] );
		$this->assertSame( 49.0, $charged, 'Only what the credit did not cover is charged.' );
	}
}

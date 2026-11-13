<?php
/**
 * Shared set-up of the gift card credit tests.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\GiftCredit;

use Bgca\Wallet;
use Bgcw\GiftCard\Repository;
use Bgcw\GiftCard\Source;
use BeltoftServices\GiftCredit\PromoFlag;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

/**
 * Base class: gift card credit on (the gift card plugin and the add-on must be loaded: BELTOFT_GIFT_CARDS_DIR and
 * BELTOFT_GIFT_CARDS_ACCOUNT_DIR), a card builder, products and subscriptions.
 */
abstract class GiftCreditTestCase extends UsageTestCase {

	public function set_up(): void {
		if ( ! class_exists( '\Bgca\Plugin' ) || ! \Bgca\Plugin::requirements_met() ) {
			$this->markTestSkipped( 'The gift card plugin and its add-on are not loaded (set BELTOFT_GIFT_CARDS_DIR and BELTOFT_GIFT_CARDS_ACCOUNT_DIR).' );
		}

		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		delete_option( 'bgca_options' );
		delete_option( \Bgca\TopUp::OPTION_PRODUCT );
		$this->vat( false );
		$this->settings( array( 'gift_credit' => 1, 'usage_minimum' => 0, 'admin_email' => self::MAIL ) );
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Usage\Ledger::table() );
		$this->vat( false );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A card for a customer: free by default, in EUR, created $age seconds ago.
	 */
	protected function card( int $user, int $cents, string $source = Source::PROMOTION, string $currency = 'EUR', int $age = 0 ): object {
		$id = Wallet::create( $user, $cents, $source, $currency, 'Seed' );
		$this->assertGreaterThan( 0, $id );

		if ( $age > 0 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$wpdb->update( Repository::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $age ) ), array( 'id' => $id ) );
		}

		return Repository::find( $id );
	}

	/**
	 * The balance of a card in cents, read fresh.
	 */
	protected function left( object $card ): int {
		return (int) round( (float) Repository::find( (int) $card->id )->balance * 100 );
	}

	/**
	 * A product priced $price; $promo ticks the promotional credit box.
	 */
	protected function product( bool $promo, string $price = '10' ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Thing ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		$product->update_meta_data( PromoFlag::META_PROMO, $promo ? 'yes' : 'no' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * An active subscription of products.
	 *
	 * @param \WC_Product[] $products Products.
	 */
	protected function subscription( int $user, array $products ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->set_billing_email( self::MAIL );
		$sub->set_billing_country( 'DK' );
		$sub->set_currency( 'EUR' );
		$sub->set_payment_method( 'fakecard' );
		$sub->set_requires_manual_renewal( false );

		foreach ( $products as $product ) {
			$sub->add_product( $product, 1 );
		}

		$sub->calculate_totals();
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$sub->save();

		return wcs_get_subscription( $sub->get_id() );
	}

	/**
	 * Runs the scheduled payment of a subscription and returns its new renewal order.
	 */
	protected function renew( \WC_Subscription $sub ): \WC_Order {
		do_action( 'woocommerce_scheduled_subscription_payment', $sub->get_id() );
		$order = wcs_get_subscription( $sub->get_id() )->get_last_order( 'all', 'renewal' );
		$this->assertInstanceOf( \WC_Order::class, $order );

		return $order;
	}

	/**
	 * The coupon lines of an order: code (upper case) => cents.
	 *
	 * @return array<string, int>
	 */
	protected function coupon_lines( \WC_Order $order ): array {
		$out = array();

		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$out[ strtoupper( $item->get_code() ) ] = (int) round( ( (float) $item->get_discount() + (float) $item->get_discount_tax() ) * 100 );
		}

		return $out;
	}

	/**
	 * Pay as you go hours of a resource, billed in the last month.
	 */
	protected function hours( int $resource, int $user, int $hours, float $rate = 0.02 ): void {
		$now   = \BeltoftServices\Usage\Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;
		for ( $i = 0; $i < $hours; $i++ ) {
			\BeltoftServices\Usage\Ledger::record( $resource, $user, 'server', 1.0, 'hour', $rate, $end - $i * HOUR_IN_SECONDS );
		}
	}
}

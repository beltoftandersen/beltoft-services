<?php
/**
 * Shared set-up of the pay-as-you-go tests: a product with a Billing attribute, usage servers and a usage account.
 * FakeClient only; every address is the owner's, and mail is captured.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\BackupTestCase;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Rates;

require_once dirname( __DIR__ ) . '/Proxmox/BackupTestCase.php';

/**
 * A gateway that can be charged later, and one that cannot.
 */
class UsageCardGateway extends \WC_Payment_Gateway {
	public function __construct() {
		$this->id       = 'fakecard';
		$this->enabled  = 'yes';
		$this->supports = array( 'products', 'subscriptions', 'subscription_cancellation', 'subscription_suspension', 'subscription_reactivation', 'subscription_amount_changes', 'subscription_date_changes', 'multiple_subscriptions' );
	}
}

/**
 * Base class.
 */
abstract class UsageTestCase extends BackupTestCase {

	protected const MAIL = 'christian.beltoft@gmail.com';

	public function set_up(): void {
		parent::set_up();

		$this->settings( array( 'admin_email' => self::MAIL ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Usage\Ledger::table() );
		delete_option( Account::OPTION_PRODUCT );
		$this->drop_orphan_order_items();
		WC()->cart->empty_cart();
		// The test site's address differs from the one WooCommerce Subscriptions stored: it would count every subscription as manual.
		add_filter( 'woocommerce_subscriptions_is_duplicate_site', '__return_false' );
		WC()->payment_gateways()->payment_gateways['fakecard'] = new UsageCardGateway();
		update_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment', 'no' );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( WC()->payment_gateways()->payment_gateways['fakecard'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Earlier tests that end in an implicit commit (table creation) leave order items behind for ids that this
	 * test's orders and subscriptions get again. Items of orders that do not exist yet are deleted.
	 */
	private function drop_orphan_order_items(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$top = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		$hpos = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' ) );
		if ( $hpos ) {
			$top = max( $top, (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}wc_orders" ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id > %d", $top ) );
		// phpcs:enable
	}

	/**
	 * A customer with the owner's address (plus-tagged, so each is unique).
	 */
	protected function new_user(): int {
		static $n = 0;
		++$n;

		return (int) self::factory()->user->create( array( 'user_email' => 'christian.beltoft+usage' . $n . '-' . wp_generate_password( 4, false ) . '@gmail.com' ) );
	}

	/**
	 * A VPS with a Billing attribute (Monthly, Pay as you go) and a Backups attribute (None, Daily).
	 * Monthly: 15 without and 18 with backups. Pay as you go: price 0, meta billing = usage.
	 *
	 * @param string $premium Premium percent on the product ('' for the default).
	 * @return array<string, \WC_Product_Variation> Keys: m-None, m-Daily, u-None, u-Daily.
	 */
	protected function usage_product( string $premium = '' ): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'VPS ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$billing = new \WC_Product_Attribute();
		$billing->set_name( 'Billing' );
		$billing->set_options( array( 'Monthly', 'Pay as you go' ) );
		$billing->set_visible( true );
		$billing->set_variation( true );

		$backups = new \WC_Product_Attribute();
		$backups->set_name( 'Backups' );
		$backups->set_options( array( 'None', 'Daily' ) );
		$backups->set_visible( true );
		$backups->set_variation( true );

		$product->set_attributes( array( $billing, $backups ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'vps-' . wp_generate_password( 5, false ) );

		if ( '' !== $premium ) {
			$product->update_meta_data( Rates::META_PREMIUM, $premium );
		}

		foreach ( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 'm' => 'Monthly', 'u' => 'Pay as you go' ) as $short => $billing_value ) {
			foreach ( array( 'None' => '15', 'Daily' => '18' ) as $b => $price ) {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'billing' => $billing_value, 'backups' => $b ) );
				$v->set_regular_price( 'm' === $short ? $price : '0' );
				$v->set_status( 'publish' );
				$v->update_meta_data( Product::META_PLAN . 'backup_days', 'Daily' === $b ? '7' : '0' );

				if ( 'u' === $short ) {
					$v->update_meta_data( Rates::META_BILLING, 'usage' );
				}

				$v->save();
				$out[ $short . '-' . $b ] = $v;
			}
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	/**
	 * A pay-as-you-go server: a usage license on the variation, a ready VM, activated $hours ago.
	 *
	 * @param \WC_Product_Variation $variation Usage variation.
	 * @param int                   $user      Customer.
	 * @param int                   $hours     Hours since it was created.
	 * @param int|null              $account   Subscription id to attach (null: none).
	 */
	protected function usage_vm( \WC_Product_Variation $variation, int $user, int $hours = 5, ?int $account = null ): Resource {
		$parent = wc_get_product( $variation->get_parent_id() );
		$data   = array(
			'product_id'   => $parent->get_id(),
			'product_slug' => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
			'variation_id' => $variation->get_id(),
			'user_id'      => $user,
			'email'        => self::MAIL,
			'source'       => 'usage',
			'fire'         => false,
		);

		if ( null !== $account ) {
			$data['subscription_id'] = $account;
		}

		$license  = $this->service_license( $parent, $data );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $parent->get_id(), $variation->get_id() ), array() );
		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );
		$this->activated_ago( $resource->id(), $hours );

		return Resource::find( $resource->id() );
	}

	/**
	 * Moves a resource's activation time back.
	 *
	 * @param int $id    Activation id.
	 * @param int $hours Hours.
	 */
	protected function activated_ago( int $id, int $hours ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( \BeltoftLicenses\Install::tables()['activations'], array( 'activated_at' => gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ), array( 'id' => $id ) );
	}

	/**
	 * A usage account of a customer: a flagged, active subscription with a saved payment method.
	 *
	 * @param int    $user   Customer.
	 * @param string $status Status.
	 */
	protected function usage_account( int $user, string $status = 'active' ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => $status ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->set_billing_email( self::MAIL );
		$sub->set_billing_country( 'DK' );
		$sub->set_payment_method( 'fakecard' );
		$sub->set_requires_manual_renewal( false );
		$sub->add_product( wc_get_product( Account::product_id() ), 1 );
		$sub->update_meta_data( Account::META, 'yes' );
		$sub->save();

		return wcs_get_subscription( $sub->get_id() );
	}
}

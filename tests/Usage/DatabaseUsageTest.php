<?php
/**
 * Managed databases sold pay as you go: rates, meter, cap, checkout swap, add-ons, picker, order lines.
 * FakeClient only; every address is the owner's.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\DbPicker;
use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DbDiskField;
use BeltoftServices\Proxmox\DbIpField;
use BeltoftServices\Resource;
use BeltoftServices\Usage\BillingField;
use BeltoftServices\Usage\Catalog;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * A database product the way the shop has it: Billing, Public IP and Extra storage attributes, premium 0.
 */
class DatabaseUsageTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->seed_private_nets();
	}

	/**
	 * Monthly prices: 25 base, +5 public IP, +8 for 50 GB extra storage.
	 *
	 * @return array<string, \WC_Product_Variation> Keys m-0-0, u-1-50 (billing, public IP, extra GB).
	 */
	private function db_product( string $premium = '0' ): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Managed ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$attrs = array();

		foreach ( array( 'Plan' => array( 'Small' ), 'Billing' => array( 'Monthly', 'Pay as you go' ), 'Public IP' => array( 'No', 'Yes' ), 'Extra storage' => array( 'None', '50 GB' ) ) as $name => $options ) {
			$a = new \WC_Product_Attribute();
			$a->set_name( $name );
			$a->set_options( $options );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}

		$product->set_attributes( $attrs );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );
		$product->update_meta_data( Rates::META_PREMIUM, $premium );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'major' => '18', 'cores' => '1', 'memory_mb' => '2048', 'disk_gb' => '20', 'backup_days' => '7' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 'm' => 'Monthly', 'u' => 'Pay as you go' ) as $short => $billing ) {
			foreach ( array( 0, 1 ) as $ip ) {
				foreach ( array( 0 => 'None', 50 => '50 GB' ) as $gb => $label ) {
					$v = new \WC_Product_Variation();
					$v->set_parent_id( $product->get_id() );
					$v->set_attributes( array( 'plan' => 'Small', 'billing' => $billing, 'public-ip' => $ip ? 'Yes' : 'No', 'extra-storage' => $label ) );
					$v->set_regular_price( 'm' === $short ? (string) ( 25 + 5 * $ip + ( $gb ? 8 : 0 ) ) : '0' );
					$v->set_status( 'publish' );
					$v->update_meta_data( Product::META_PLAN . 'public_ip', (string) $ip );
					$v->update_meta_data( Product::META_PLAN . 'extra_disk_gb', (string) $gb );

					if ( 'u' === $short ) {
						$v->update_meta_data( Rates::META_BILLING, 'usage' );
					}

					$v->save();
					$out[ $short . '-' . $ip . '-' . $gb ] = $v;
				}
			}
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	/**
	 * A ready database of a usage license (no machine: only what the meter and the rates read).
	 */
	private function usage_db( \WC_Product_Variation $variation, int $user, int $hours = 5 ): Resource {
		$parent  = wc_get_product( $variation->get_parent_id() );
		$license = $this->service_license(
			$parent,
			array(
				'product_id'   => $parent->get_id(),
				'product_slug' => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'variation_id' => $variation->get_id(),
				'user_id'      => $user,
				'email'        => self::MAIL,
				'source'       => 'usage',
				'fire'         => false,
			)
		);
		$r       = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $parent->get_id(), $variation->get_id() ), array( 'state' => 'ready', 'desired' => 'ready', \BeltoftServices\Usage\Meter::READY_AT => time() - $hours * HOUR_IN_SECONDS ) );
		$this->assertInstanceOf( Resource::class, $r );
		$this->activated_ago( $r->id(), $hours );

		return Resource::find( $r->id() );
	}

	private function add( \WC_Product_Variation $v ): string {
		$attrs = array();

		foreach ( $v->get_attributes() as $name => $value ) {
			$attrs[ 'attribute_' . $name ] = $value;
		}

		$key = WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), $attrs );
		$this->assertNotFalse( $key, 'Added: ' . wp_json_encode( wc_get_notices() ) );

		return (string) $key;
	}

	private function in_cart(): int {
		$cart = WC()->cart->get_cart();

		return (int) reset( $cart )['variation_id'];
	}

	// -- Rates --------------------------------------------------------------------------------------

	public function test_the_hourly_rate_is_the_monthly_variation_price_over_730_and_the_cap_is_that_price(): void {
		$v = $this->db_product();

		foreach ( array( '0-0' => 25.0, '1-0' => 30.0, '0-50' => 33.0, '1-50' => 38.0 ) as $key => $price ) {
			$rates = Rates::for_usage( $v[ 'u-' . $key ] );
			$this->assertSame( Rates::hourly( $price, 0.0 ), $rates['hourly'], $key );
			$this->assertSame( $price, $rates['cap'], $key );
			$this->assertEqualsWithDelta( $price / 730, $rates['hourly'], 0.00000001 );
		}
	}

	public function test_a_database_resource_is_billed_one_database_item_with_its_add_ons_in_the_price(): void {
		$v = $this->db_product();
		$r = $this->usage_db( $v['u-1-50'], $this->new_user() );

		$items = Rates::items( $r );

		$this->assertCount( 1, $items );
		$this->assertSame( 'database', $items[0]['item'] );
		$this->assertSame( 'hour', $items[0]['unit'] );
		$this->assertSame( Rates::hourly( 38.0, 0.0 ), $items[0]['rate'] );
	}

	public function test_the_meter_writes_database_rows_and_the_statement_caps_them_at_the_monthly_price(): void {
		$v    = $this->db_product();
		$user = $this->new_user();
		$this->usage_account( $user );
		$r    = $this->usage_db( $v['u-1-50'], $user, 5 );

		$this->assertGreaterThan( 0, Meter::run( time() ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ledger::table() . " WHERE resource_id = %d AND item <> 'database'", $r->id() ) ) );

		// A whole long month of hours: 744 x 38 / 730 = 38.72, capped at 38.
		$base = (int) strtotime( gmdate( 'Y-m-01 00:00:00', time() ) . ' UTC' );
		do {
			$base = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $base - DAY_IN_SECONDS ) . ' UTC' );
		} while ( 31 !== (int) gmdate( 't', $base ) );
		$days = 31;
		$rows = array();

		for ( $i = 0; $i < $days * 24; $i++ ) {
			$rows[] = array( 'id' => 0, 'resource_id' => $r->id(), 'item' => 'database', 'unit' => 'hour', 'quantity' => 1.0, 'rate' => Rates::hourly( 38.0, 0.0 ), 'hour_bucket' => gmdate( 'Y-m-d H:i:s', $base + $i * HOUR_IN_SECONDS ) );
		}

		$statement = Renewal::statement( $rows );

		$this->assertCount( 1, $statement['lines'] );
		$this->assertSame( 38.0, $statement['lines'][0]['cap'] );
		$this->assertSame( 38.0, $statement['total'] );
		$this->assertTrue( $statement['lines'][0]['capped'] );
		$this->assertSame( 'Database web-db', Renewal::line_subject( 'database', 'web-db' ) );
		$this->assertSame( 'database', Renewal::item_label( 'database' ) );
	}

	public function test_the_bricks_tags_price_a_database_plan_and_its_add_on_variations(): void {
		$v = $this->db_product();
		$this->assertTrue( \BeltoftServices\Proxmox\DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		\BeltoftServices\Proxmox\Stock::sync( 'lab' );

		$this->assertSame( Rates::money( 25 / 730, 4 ), Catalog::value( 'beltoft_usage_hourly', $v['m-0-0']->get_id() ) );
		$this->assertSame( Rates::cap_money( 25.0 ), Catalog::value( 'beltoft_usage_cap', $v['m-0-0']->get_id() ) );
		$this->assertSame( Rates::cap_money( 38.0 ), Catalog::value( 'beltoft_usage_cap', $v['m-1-50']->get_id() ) );
		$this->assertSame( '', Catalog::value( 'beltoft_usage_cap', $v['u-0-0']->get_id() ), 'A pay as you go variation is not a plan.' );
		$ids = Catalog::visible_children( wc_get_product( $v['m-0-0']->get_parent_id() )->get_children(), null, true );
		$this->assertCount( 4, $ids );
	}

	public function test_a_sold_out_plan_keeps_its_price_on_the_page(): void {
		$v = $this->db_product();
		$u = $v['u-0-0'];
		$u->set_stock_status( 'outofstock' );
		$u->save();

		$this->assertSame( Rates::cap_money( 25.0 ), Catalog::value( 'beltoft_usage_cap', $v['m-0-0']->get_id() ) );
		$this->assertSame( 25.0, Rates::for_plan( $v['m-0-0'], false )['cap'] );
	}

	// -- Checkout -----------------------------------------------------------------------------------

	public function test_monthly_not_offered_a_database_in_the_cart_becomes_pay_as_you_go_and_keeps_its_add_ons(): void {
		$this->settings( array( 'admin_email' => self::MAIL, 'offer_monthly' => 0 ) );
		wp_set_current_user( $this->new_user() );
		WC()->cart->empty_cart();
		$v = $this->db_product();

		$this->add( $v['m-1-0'] );
		BillingField::enforce();
		$this->assertSame( $v['u-1-0']->get_id(), $this->in_cart(), 'Monthly with the public IP becomes its pay as you go twin.' );

		// The public IP off and extra storage on keep the billing model (storage needs room on a node).
		$this->assertTrue( \BeltoftServices\Proxmox\DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		\BeltoftServices\Proxmox\Stock::sync( 'lab' );
		$this->assertTrue( DbIpField::swap( false )['ok'] );
		$this->assertSame( $v['u-0-0']->get_id(), $this->in_cart() );
		$this->assertTrue( DbDiskField::swap( 50 )['ok'] );
		$this->assertSame( $v['u-0-50']->get_id(), $this->in_cart() );
		$this->assertTrue( DbIpField::swap( true )['ok'] );
		$this->assertSame( $v['u-1-50']->get_id(), $this->in_cart() );

		WC()->cart->empty_cart();
	}

	public function test_the_checkout_labels_of_the_add_ons_on_a_usage_line_are_hourly_differences(): void {
		$v = $this->db_product();

		$this->assertSame( '+' . Rates::money( 8 / 730, 4 ) . ' / hour', \BeltoftServices\Proxmox\Backups::price_difference( $v['u-0-0'], $v['u-0-50'] ) );
		$this->assertSame( '+' . Rates::money( 5 / 730, 4 ) . ' / hour', \BeltoftServices\Proxmox\Backups::price_difference( $v['u-0-0'], $v['u-1-0'] ) );
	}

	// -- The New database page ----------------------------------------------------------------------

	private function picker_page(): string {
		$GLOBALS['wp']->query_vars['databases'] = 'new';
		ob_start();
		\BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Databases::class )->render( array() );

		return (string) ob_get_clean();
	}

	public function test_monthly_not_offered_the_plan_cards_show_the_cap_then_the_hourly_rate(): void {
		$this->settings( array( 'admin_email' => self::MAIL, 'offer_monthly' => 0 ) );
		wp_set_current_user( $this->new_user() );
		$v = $this->db_product();
		add_filter( 'beltoft_services_db_picker_products', fn() => array( $v['m-0-0']->get_parent_id() ) );

		$html = $this->picker_page();

		$this->assertStringContainsString( 'data-picker-payg-only', $html );
		$this->assertStringContainsString( '<span class="beltoft-services-plan__name">Small</span>', $html, 'The public IP answer is not part of the plan name.' );
		$this->assertStringContainsString( '<b>' . Rates::cap_money( 25.0 ) . '</b> <span>max per month, excl. VAT</span>', $html );
		$this->assertStringContainsString( 'beltoft-services-plan__hourly">' . Rates::per_hour( 25 / 730 ), str_replace( "\n", '', $html ) );
		$this->assertStringContainsString( 'data-card-cap="' . Rates::cap_money( 25.0 ) . '"', $html );
		$this->assertStringContainsString( 'pay as you go: ' . Rates::terms( Rates::hourly( 25.0, 0.0 ), 25.0 ), html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
		$this->assertStringNotContainsString( 'per month excluding VAT</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'name="' . ServerPicker::PLAN . '"' ), 'Each plan is listed once: add-ons are chosen at checkout.' );

		remove_all_filters( 'beltoft_services_db_picker_products' );
		unset( $GLOBALS['wp']->query_vars['databases'] );
	}

	public function test_monthly_offered_the_cards_keep_the_monthly_price(): void {
		wp_set_current_user( $this->new_user() );
		$v = $this->db_product();
		add_filter( 'beltoft_services_db_picker_products', fn() => array( $v['m-0-0']->get_parent_id() ) );

		$html = $this->picker_page();

		$this->assertStringNotContainsString( 'data-picker-payg-only', $html );
		$this->assertStringContainsString( 'per month excluding VAT', $html );

		remove_all_filters( 'beltoft_services_db_picker_products' );
		unset( $GLOBALS['wp']->query_vars['databases'] );
	}

	public function test_a_post_without_monthly_puts_the_pay_as_you_go_variation_in_the_cart(): void {
		$this->settings( array( 'admin_email' => self::MAIL, 'offer_monthly' => 0 ) );
		$user = $this->new_user();
		wp_set_current_user( $user );
		WC()->cart->empty_cart();
		$v = $this->db_product();
		add_filter( 'beltoft_services_db_picker_products', fn() => array( $v['m-0-0']->get_parent_id() ) );

		$result = DbPicker::process( array( DbPicker::FIELD => wp_create_nonce( DbPicker::NONCE ), ServerPicker::PLAN => (string) $v['m-0-0']->get_id() ), $user );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( $v['u-0-0']->get_id(), $this->in_cart() );

		WC()->cart->empty_cart();
		remove_all_filters( 'beltoft_services_db_picker_products' );
		remove_all_filters( 'beltoft_services_picker_rate_limit' );
	}

	// -- Order lines ----------------------------------------------------------------------------------

	private function line_meta( \WC_Product_Variation $v ): array {
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $v );
		$item->set_name( 'Managed database' ); // WooCommerce leaves out a value the line name already says.
		$item->set_order_id( $order->get_id() );

		$item->add_meta_data( 'Server name', 'db1' );
		$item->save();

		return array_map( static fn( $m ) => $m->display_key . ': ' . wp_strip_all_tags( (string) $m->display_value ), array_values( $item->get_formatted_meta_data() ) );
	}

	public function test_a_usage_line_hides_billing_and_add_ons_that_were_not_chosen(): void {
		$v    = $this->db_product();
		$rows = $this->line_meta( $v['u-0-0'] );

		$this->assertSame( array( 'Plan: Small', 'Server name: db1' ), $rows );
		$full = $this->line_meta( $v['u-1-50'] );
		$this->assertContains( 'Server name: db1', $full );
		$this->assertCount( 4, $full, 'The chosen public IP and extra storage stay.' );
		foreach ( $full as $row ) {
			$this->assertStringStartsNotWith( 'billing', strtolower( $row ) );
		}
	}

	public function test_a_monthly_line_keeps_every_row(): void {
		$v    = $this->db_product();
		$rows = $this->line_meta( $v['m-0-0'] );

		$this->assertCount( 5, $rows );
	}

	public function test_a_server_usage_line_loses_billing_and_backups_none_and_keeps_daily_backups(): void {
		$v = $this->usage_product();

		$none = $this->line_meta( $v['u-None'] );
		$this->assertSame( array( 'Server name: db1' ), $none );
		$this->assertContains( 'Backups: Daily', $this->line_meta( $v['u-Daily'] ));
	}
}

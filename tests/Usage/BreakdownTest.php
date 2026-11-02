<?php
/**
 * The monthly breakdown of a pay-as-you-go server or database in the summary, the cart and the add-on labels.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\BackupField;
use BeltoftServices\Proxmox\DbDiskField;
use BeltoftServices\Proxmox\DbIpField;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Display only: amounts, labels, and lines that are not pay as you go.
 */
class BreakdownTest extends UsageTestCase {

	/**
	 * A database plan "Standard": 45 a month, +5 with the public IP, +4 for 50 GB of extra storage, each as Monthly
	 * and as Pay as you go.
	 *
	 * @param string $premium Premium percent.
	 * @return array<string, \WC_Product_Variation> Keys such as m-0-0, u-50-1 (billing, extra GB, ip).
	 */
	private function db( string $premium = '0' ): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Managed SQL Server Express' );
		$product->set_status( 'publish' );

		$attrs = array();

		foreach ( array( 'Plan' => array( 'Standard' ), 'Billing' => array( 'Monthly', 'Pay as you go' ), 'Extra storage' => array( 'None', '50 GB' ), 'Public IP' => array( 'No', 'Yes' ) ) as $name => $options ) {
			$a = new \WC_Product_Attribute();
			$a->set_name( $name );
			$a->set_options( $options );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}

		$product->set_attributes( $attrs );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( Rates::META_PREMIUM, $premium );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'major' => '18', 'cores' => '2', 'memory_mb' => '4096', 'disk_gb' => '40' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 'm' => 'Monthly', 'u' => 'Pay as you go' ) as $short => $billing ) {
			foreach ( array( 0 => array( 'None', 45 ), 50 => array( '50 GB', 49 ) ) as $extra => $set ) {
				foreach ( array( 0, 1 ) as $with ) {
					$v = new \WC_Product_Variation();
					$v->set_parent_id( $product->get_id() );
					$v->set_attributes( array( 'plan' => 'Standard', 'billing' => $billing, 'extra-storage' => $set[0], 'public-ip' => $with ? 'Yes' : 'No' ) );
					$v->set_regular_price( 'm' === $short ? (string) ( $set[1] + ( $with ? 5 : 0 ) ) : '0' );
					$v->set_status( 'publish' );
					$v->update_meta_data( Product::META_PLAN . 'public_ip', (string) $with );
					$v->update_meta_data( Product::META_PLAN . 'extra_disk_gb', (string) $extra );

					if ( 'u' === $short ) {
						$v->update_meta_data( Rates::META_BILLING, 'usage' );
					}

					$v->save();
					$out[ $short . '-' . $extra . '-' . $with ] = $v;
				}
			}
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	public function set_up(): void {
		parent::set_up();

		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_currency_pos', 'left' );
	}

	public function test_a_database_with_nothing_added_is_the_plan_alone(): void {
		$v     = $this->db();
		$parts = Breakdown::parts( $v['u-0-0'] );

		$this->assertSame( 45.0, $parts['plan'] );
		$this->assertSame( 0.0, $parts['ip'] );
		$this->assertSame( 0.0, $parts['storage'] );
		$this->assertSame( 45.0, $parts['max'] );
		$this->assertSame( array( array( 'Standard plan', '€45 / month' ) ), Breakdown::rows( $parts, 'Standard' ) );
	}

	public function test_the_owners_case_is_54_a_month_and_0074_an_hour(): void {
		$v     = $this->db();
		$parts = Breakdown::parts( $v['u-50-1'] );

		$this->assertSame( 45.0, $parts['plan'] );
		$this->assertSame( 5.0, $parts['ip'] );
		$this->assertSame( 4.0, $parts['storage'] );
		$this->assertSame( 50, $parts['storage_gb'] );
		$this->assertSame( 54.0, $parts['max'] );
		$this->assertSame( '€0.0740', Rates::money( $parts['hourly'], 4 ) );
		$this->assertSame(
			array(
				array( 'Standard plan', '€45 / month' ),
				array( 'Public IPv4 address', '+€5 / month' ),
				array( 'Extra storage, 50 GB', '+€4 / month' ),
			),
			Breakdown::rows( $parts, 'Standard' )
		);
		$this->assertSame( $parts['plan'] + $parts['ip'] + $parts['storage'], $parts['max'] );
	}

	public function test_each_database_add_on_alone(): void {
		$v  = $this->db();
		$ip = Breakdown::parts( $v['u-0-1'] );
		$gb = Breakdown::parts( $v['u-50-0'] );

		$this->assertSame( array( 45.0, 5.0, 0.0, 50.0 ), array( $ip['plan'], $ip['ip'], $ip['storage'], $ip['max'] ) );
		$this->assertSame( array( 45.0, 0.0, 4.0, 49.0 ), array( $gb['plan'], $gb['ip'], $gb['storage'], $gb['max'] ) );
	}

	public function test_amounts_are_whole_euros_or_two_decimals(): void {
		$this->assertSame( '€45 / month', Rates::per_month_line( 45.0 ) );
		$this->assertSame( '€16.50 / month', Rates::per_month_line( 16.5 ) );
		$this->assertSame( '+€5 / month', Rates::plus_per_month_line( 5.0 ) );
		$this->assertSame( '+€3.30 / month', Rates::plus_per_month_line( 3.3 ) );
		$this->assertSame( '+€4 per month', Rates::plus_per_month( 4.0 ) );
	}

	public function test_the_premium_is_part_of_every_amount(): void {
		$v     = $this->db( '10' );
		$parts = Breakdown::parts( $v['u-50-1'] );

		$this->assertSame( 49.5, $parts['plan'] );
		$this->assertSame( 5.5, $parts['ip'] );
		$this->assertSame( 4.4, $parts['storage'] );
		$this->assertSame( 59.4, $parts['max'] );
	}

	public function test_a_server_with_daily_backups(): void {
		$v    = $this->usage_product( '0' );
		$with = Breakdown::parts( $v['u-Daily'] );
		$none = Breakdown::parts( $v['u-None'] );

		$this->assertSame( array( 15.0, 3.0, 18.0 ), array( $with['plan'], $with['backups'], $with['max'] ) );
		$this->assertSame( array( 15.0, 0.0, 15.0 ), array( $none['plan'], $none['backups'], $none['max'] ) );
		$this->assertSame( array( array( 'Plan', '€15 / month' ), array( 'Daily backups', '+€3 / month' ) ), Breakdown::rows( $with ) );
		$this->assertSame( array( array( 'Plan', '€15 / month' ) ), Breakdown::rows( $none ) );
	}

	public function test_the_name_cell_is_the_product_name_the_plan_and_the_amounts(): void {
		$v    = $this->db();
		$item = array( 'data' => $v['u-50-1'], 'quantity' => 1 );
		$html = (string) apply_filters( 'woocommerce_cart_item_name', 'Managed SQL Server Express, Standard, pay as you go, public IPv4, 50 GB extra storage', $item, 'k' );
		$text = wp_strip_all_tags( str_replace( '</span>', ' | </span>', $html ) );

		$this->assertStringContainsString( 'Managed SQL Server Express', $html );
		$this->assertStringContainsString( 'Standard, 2 vCPU, 4 GB memory, 40 GB data disk', $html );
		$this->assertStringContainsString( 'Standard plan', $html );
		$this->assertStringContainsString( '€45 / month', $html );
		$this->assertStringContainsString( '+€5 / month', $html );
		$this->assertStringContainsString( 'Extra storage, 50 GB', $html );
		$this->assertStringContainsString( '+€4 / month', $html );
		$this->assertStringContainsString( 'Max per month', $html );
		$this->assertStringContainsString( '€54', $html );
		$this->assertStringContainsString( 'That is €0.0740 per hour, billed for the hours used.', $html );
		$this->assertStringNotContainsString( 'pay as you go,', $html, 'The long variation suffix is gone.' );
		$this->assertStringNotContainsString( '/ hour', $text );
		$this->assertStringNotContainsString( '0.0068', $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
	}

	public function test_a_linked_name_keeps_its_link(): void {
		$v    = $this->db();
		$item = array( 'data' => $v['u-0-0'], 'quantity' => 1 );
		$html = (string) apply_filters( 'woocommerce_cart_item_name', '<a href="https://example.test/x">Long name, pay as you go</a>', $item, 'k' );

		$this->assertStringContainsString( '<a href="https://example.test/x">Managed SQL Server Express</a>', $html );
		$this->assertSame( 1, substr_count( $html, 'Managed SQL Server Express' ) );
	}

	public function test_the_price_cells_are_empty_and_the_row_has_its_class(): void {
		$v    = $this->db();
		$item = array( 'data' => $v['u-50-1'], 'quantity' => 1 );

		$this->assertSame( '', apply_filters( 'woocommerce_cart_item_subtotal', '€0.00', $item, 'k' ) );
		$this->assertSame( '', apply_filters( 'woocommerce_cart_item_price', '€0.00', $item, 'k' ) );
		$this->assertSame( '', apply_filters( 'woocommerce_checkout_cart_item_quantity', ' x 1', $item, 'k' ) );
		$this->assertSame( '', apply_filters( 'woocommerce_widget_cart_item_quantity', '1 x €0', $item, 'k' ) );
		$this->assertStringContainsString( Breakdown::CLASS_NAME, (string) apply_filters( 'woocommerce_cart_item_class', 'cart_item', $item, 'k' ) );
		$this->assertStringContainsString( Breakdown::CLASS_NAME, (string) apply_filters( 'woocommerce_mini_cart_item_class', 'mini_cart_item', $item, 'k' ) );
	}

	public function test_the_hourly_add_on_rows_are_dropped(): void {
		$v    = $this->db();
		$item = array( 'data' => $v['u-50-1'], 'quantity' => 1 );
		$rows = array(
			array( 'key' => 'Operating system', 'value' => 'Ubuntu 24.04 LTS' ),
			array( 'key' => 'Public IPv4 address', 'value' => '+€0.0068 / hour' ),
			array( 'key' => 'Extra storage', 'value' => '+50 GB (+€0.0055 / hour)' ),
			array( 'key' => 'Daily backups', 'value' => '+€0.0041 / hour' ),
		);

		$keys = array_column( (array) apply_filters( 'woocommerce_get_item_data', $rows, $item ), 'key' );

		$this->assertContains( 'Operating system', $keys );
		$this->assertSame( array(), array_intersect( $keys, array( 'Public IPv4 address', 'Extra storage', 'Daily backups' ) ) );
	}

	public function test_monthly_lines_and_other_lines_are_untouched(): void {
		$v    = $this->db();
		$item = array( 'data' => $v['m-50-1'], 'quantity' => 1 );

		$this->assertStringNotContainsString( 'beltoft-services-payg', (string) apply_filters( 'woocommerce_cart_item_name', 'Name', $item, 'k' ) );
		$this->assertSame( '€54.00', apply_filters( 'woocommerce_cart_item_subtotal', '€54.00', $item, 'k' ) );
		$this->assertSame( '€54.00', apply_filters( 'woocommerce_cart_item_price', '€54.00', $item, 'k' ) );
		$this->assertSame( ' x 1', apply_filters( 'woocommerce_checkout_cart_item_quantity', ' x 1', $item, 'k' ) );
		$this->assertSame( 'cart_item', apply_filters( 'woocommerce_cart_item_class', 'cart_item', $item, 'k' ) );

		$simple = new \WC_Product_Simple();
		$other  = array( 'data' => $simple, 'quantity' => 1 );

		$this->assertSame( 'Plugin', apply_filters( 'woocommerce_cart_item_name', 'Plugin', $other, 'k' ) );
		$this->assertSame( '€9.00', apply_filters( 'woocommerce_cart_item_subtotal', '€9.00', $other, 'k' ) );
		$this->assertSame( 'cart_item', apply_filters( 'woocommerce_cart_item_class', 'cart_item', $other, 'k' ) );
	}

	public function test_a_renewal_keeps_its_look(): void {
		$v    = $this->db();
		$item = array( 'data' => $v['u-0-0'], 'quantity' => 1, 'subscription_renewal' => array( 'renewal_order_id' => 1 ) );

		$this->assertStringNotContainsString( 'beltoft-services-payg', (string) apply_filters( 'woocommerce_cart_item_name', 'Name', $item, 'k' ) );
		$this->assertSame( '', (string) apply_filters( 'woocommerce_cart_item_class', '', $item, 'k' ) );
	}

	public function test_real_prices_and_totals_are_not_touched(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$key = WC()->cart->add_to_cart( $v['u-Daily']->get_parent_id(), 1, $v['u-Daily']->get_id(), array( 'attribute_billing' => 'Pay as you go', 'attribute_backups' => 'Daily' ) );
		$this->assertNotFalse( $key );
		WC()->cart->calculate_totals();
		$item = WC()->cart->get_cart()[ $key ];

		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		$this->assertEquals( 0, $item['line_subtotal'] );
		$this->assertStringContainsString( '€18', (string) apply_filters( 'woocommerce_cart_item_name', 'Name', $item, $key ) );
	}

	public function test_the_database_labels_show_the_monthly_maximum(): void {
		$v = $this->db();

		$this->assertSame( 'Public IPv4 address, +€5 per month', DbIpField::label( $v['u-0-0'], $v['u-0-1'], '+€0.0068 / hour' ) );
		$this->assertSame( '+50 GB, +€4 per month', DbDiskField::step_label( 50, $v['u-0-0'], $v['u-50-0'] ) );
		$this->assertSame( '+50 GB, +€4 per month', DbDiskField::step_label( 50, $v['u-0-1'], $v['u-50-1'] ) );
	}

	public function test_the_monthly_labels_keep_their_wording(): void {
		$v = $this->db();

		$this->assertSame( 'Public IPv4 address (+€5.00)', DbIpField::label( $v['m-0-0'], $v['m-0-1'], '+€5.00' ) );
		$this->assertSame( '+50 GB (+€4.00)', preg_replace( '/ \/ month|\/month/', '', DbDiskField::step_label( 50, $v['m-0-0'], $v['m-50-0'] ) ) );
	}

	public function test_the_server_label_shows_the_monthly_maximum(): void {
		$v = $this->usage_product( '0' );

		$this->assertSame( 'Daily backups, +€3 per month', BackupField::label( $v['u-None'], $v['u-Daily'], '+€0.0041 / hour' ) );
		$this->assertSame( 'Daily backups (+€3.00)', BackupField::label( $v['m-None'], $v['m-Daily'], '+€3.00' ) );
	}

	public function test_the_bricks_summary_row_becomes_the_stacked_block(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$key = WC()->cart->add_to_cart( $v['u-Daily']->get_parent_id(), 1, $v['u-Daily']->get_id(), array( 'attribute_billing' => 'Pay as you go', 'attribute_backups' => 'Daily' ) );
		$this->assertNotFalse( $key );
		WC()->cart->calculate_totals();
		$name = wc_get_product( $v['u-Daily']->get_parent_id() )->get_name();

		$html = '<div id="bricks-woo-checkout-order-summary" class="x"><div class="brxe-33b40c brxe-div"><div class="brxe-d4a5aa brxe-div"><div class="brxe-f8a552 brxe-div"><div class="brxe-4b1d33 brxe-text-basic">' . $name . ' pay as you go, daily backups</div><div class="brxe-807587 brxe-text-basic">€0.0247 / hour</div><small class="brxe-1b2247 brxe-text-basic">2 vCPU</small><div class="brxe-sumos1 brxe-text-basic checkout-summary-os">Operating system: Ubuntu 24.04 LTS</div></div><div class="brxe-781d12 brxe-div"><div class="brxe-57f1f8 brxe-text-basic">max €18 / month</div></div></div></div><div id="brxe-7e1d8f" class="brxe-divider horizontal"></div></div>';
		$out  = (string) apply_filters( 'bricks/frontend/render_data', $html );

		$this->assertStringContainsString( 'beltoft-services-payg-row', $out );
		$this->assertStringContainsString( 'Daily backups', $out );
		$this->assertStringContainsString( '+€3 / month', $out );
		$this->assertStringContainsString( 'Operating system: Ubuntu 24.04 LTS', $out );
		$this->assertStringContainsString( 'Max per month', $out );
		$this->assertStringNotContainsString( 'max €18 / month', $out );
		$this->assertStringNotContainsString( '/ hour', $out );
		$this->assertStringNotContainsString( 'pay as you go, daily', $out );
		$this->assertGreaterThan( strpos( $out, 'beltoft-services-payg__plan' ), strpos( $out, 'Operating system' ), 'The operating system follows the plan line.' );
		$this->assertGreaterThan( strpos( $out, 'Operating system' ), strpos( $out, 'Max per month' ), 'The amounts follow the operating system.' );
	}

	public function test_a_row_that_is_not_ours_is_left_alone(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		WC()->cart->add_to_cart( $v['u-None']->get_parent_id(), 1, $v['u-None']->get_id(), array( 'attribute_billing' => 'Pay as you go', 'attribute_backups' => 'None' ) );
		WC()->cart->calculate_totals();
		$html = '<div id="bricks-woo-checkout-order-summary" class="x"><div class="brxe-33b40c brxe-div"><div class="brxe-d4a5aa brxe-div"><div class="brxe-f8a552 brxe-div"><div class="brxe-4b1d33 brxe-text-basic">Something else</div><div class="brxe-807587 brxe-text-basic">€1</div><small class="brxe-1b2247 brxe-text-basic">x</small><div class="brxe-sumos1 brxe-text-basic checkout-summary-os"></div></div><div class="brxe-781d12 brxe-div"><div class="brxe-57f1f8 brxe-text-basic">€1</div></div></div></div></div>';

		$this->assertStringNotContainsString( 'beltoft-services-payg', (string) apply_filters( 'bricks/frontend/render_data', $html ) );
	}

	public function test_the_add_on_note_says_how_it_is_billed(): void {
		$this->assertSame( 'Billed by the hour, like the database.', Breakdown::addon_note_text( true ) );
		$this->assertSame( 'Billed by the hour, like the server.', Breakdown::addon_note_text( false ) );
	}
}

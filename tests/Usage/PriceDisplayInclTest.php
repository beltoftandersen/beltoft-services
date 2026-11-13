<?php
/**
 * Display including tax (25 percent), every converted catalogue site: the figure, the words next to it, the product's tax status,
 * a VAT-exempt customer, usage rates at the standard class, the cart display, kses and the Bricks context.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Account\DbPicker;
use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Apps\AppLicence;
use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Domains\PriceTags;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Proxmox\Vm\Panels;
use BeltoftServices\Usage\BillingField;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Catalog;
use BeltoftServices\Usage\CoreHooks;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Slice 5 review (I1 to I5).
 */
class PriceDisplayInclTest extends UsageTestCase {

	/** @var array<int, array{0: string, 1: callable, 2: int}> Filters a test added. */
	private array $added = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		$this->display( 'incl' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		foreach ( array( '' => '25.0000', 'reduced-rate' => '10.0000' ) as $class => $rate ) {
			\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => $rate, 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => $class ) );
		}
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	public function tear_down(): void {
		foreach ( $this->added as $f ) {
			remove_filter( $f[0], $f[1], $f[2] );
		}
		if ( function_exists( 'WC' ) && WC()->customer ) {
			WC()->customer->set_is_vat_exempt( false );
		}
		parent::tear_down();
	}

	private function display( string $mode ): void {
		update_option( 'woocommerce_tax_display_shop', $mode );
		update_option( 'woocommerce_tax_display_cart', $mode );
	}

	private function both(): void {
		$f = static fn( $html, $amount, $currency, $context, $render ) => $render( 'excl' ) . ' | ' . $render( 'incl' );
		add_filter( 'beltoft_services_price_html', $f, 10, 5 );
		$this->added[] = array( 'beltoft_services_price_html', $f, 10 );
	}

	private function untaxed( \WC_Product_Variation $v ): \WC_Product_Variation {
		$parent = wc_get_product( $v->get_parent_id() );
		$parent->set_tax_status( 'none' );
		$parent->save();

		return wc_get_product( $v->get_id() );
	}

	public function test_the_picker_card_price_and_its_words_flip_together(): void {
		$p    = $this->usage_product()['m-None'];
		$span = '<span class="beltoft-services-plan__price"><b>%s</b> <span>%s</span></span>';

		$this->assertSame( sprintf( $span, wp_kses_post( wc_price( 12.5 ) ), 'per month including VAT' ), ServerPicker::price_span( 10.0, 'month', $p, 'server-picker' ) );
		$this->display( 'excl' );
		$this->assertSame( sprintf( $span, wp_kses_post( wc_price( 10 ) ), 'per month excluding VAT' ), ServerPicker::price_span( 10.0, 'month', $p, 'server-picker' ) );

		$this->display( 'incl' );
		$this->both();
		$out = ServerPicker::price_span( 10.0, 'month', $p, 'server-picker' );
		$this->assertStringContainsString( 'per month excluding VAT', $out );
		$this->assertStringContainsString( 'per month including VAT', $out );
		$this->assertStringContainsString( wp_kses_post( wc_price( 10 ) ), $out );
		$this->assertStringContainsString( wp_kses_post( wc_price( 12.5 ) ), $out );
	}

	public function test_a_product_that_is_not_taxable_shows_its_figure_and_no_vat_words(): void {
		$v = $this->untaxed( $this->usage_product()['m-None'] );

		$this->assertSame( 'none', TaxNote::vat_mode( $v ) );
		$this->assertSame( '<span class="beltoft-services-plan__price"><b>' . wp_kses_post( wc_price( 10 ) ) . '</b> <span>per month</span></span>', ServerPicker::price_span( 10.0, 'month', $v, 'server-picker' ) );
		$this->assertSame( '€10.00 per month', ServerPicker::price_text( 10.0, 'month', $v ) );
		$this->assertSame( '€10.00', ServerPicker::price_text( 10.0, '', $v ) );
		$this->assertSame( '10 GB: €15.00', rtrim( DiskField::size_label( 10, $v, false ) ), 'The figure is unchanged and there are no VAT words.' );
		$this->assertSame( 'excl', TaxNote::vat_mode( null ), 'No product (API): as it always was.' );
	}

	public function test_a_vat_exempt_customer_sees_the_excluding_figure_and_words(): void {
		$p = $this->usage_product()['m-None'];
		WC()->customer = WC()->customer ?: new \WC_Customer( 0, true );
		WC()->customer->set_is_vat_exempt( true );

		$this->assertSame( 'excl', TaxNote::display() );
		$this->assertSame( 'excl', TaxNote::display( 'cart' ) );
		$this->assertSame( 15.0, TaxNote::figure( 15.0, $p ), 'wc_get_price_to_display says the same.' );
		$this->assertEquals( wc_get_price_to_display( $p, array( 'price' => 15 ) ), TaxNote::figure( 15.0, $p ) );
		$this->assertSame( '€10.00 per month excluding VAT', ServerPicker::price_text( 10.0, 'month', $p ) );
		$this->assertSame( '€16.50', Catalog::value( 'beltoft_usage_cap', $p->get_id() ) );
	}

	public function test_pay_as_you_go_cards_and_sentences_follow_the_display_at_the_standard_class(): void {
		$p = $this->usage_product()['m-None'];

		$spans = ServerPicker::payg_spans( 16.5, 0.02260274, $p, 'server-picker-payg' );
		$this->assertStringContainsString( '<b>€20.63</b> <span>max per month, incl. VAT</span>', $spans );
		$this->assertStringContainsString( '€0.0283 per hour', $spans );

		$this->both();
		$flip = ServerPicker::payg_spans( 16.5, 0.02260274, $p, 'server-picker-payg' );
		$this->assertStringContainsString( 'max per month, excl. VAT', $flip );
		$this->assertStringContainsString( 'max per month, incl. VAT', $flip );
	}

	public function test_a_usage_rate_is_shown_at_the_class_the_invoice_is_taxed_at_not_the_products(): void {
		$p = $this->usage_product()['m-None'];
		$parent = wc_get_product( $p->get_parent_id() );
		$parent->set_tax_class( 'reduced-rate' );
		$parent->save();
		$p = wc_get_product( $p->get_id() );

		$this->assertSame( '€20.63', Rates::cap_money( 16.5, $p ), 'The usage invoice is taxed at the standard class: 25 percent, not the product\'s 10.' );
		$this->assertSame( '€16.50 per month, paid in advance', Rates::tile_monthly( 15.0, $p ), 'A fixed price follows the product\'s class: 10 percent.' );
	}

	public function test_monthly_labels_tiles_and_the_pay_as_you_go_terms(): void {
		$v = $this->usage_product();

		$tiles = BillingField::tiles( $v['m-None'] );
		$this->assertSame( '€18.75 per month, paid in advance', $tiles['monthly'][0] );
		$this->assertSame( '€0.0283 per hour, max €20.63 per month', $tiles['usage'][0] );
		$this->assertSame( '€0.0283 per hour, max €20.63 per month including VAT', Rates::terms( 0.02260274, 16.5, $v['m-None'] ) );
	}

	public function test_the_cart_checkout_and_orders_say_what_the_cart_display_says(): void {
		$this->display( 'incl' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );

		$this->assertStringEndsWith( 'excl. VAT', CoreHooks::line_usage_note( '', 'cart' ) );

		update_option( 'woocommerce_tax_display_cart', 'incl' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		$this->assertStringEndsWith( 'incl. VAT', CoreHooks::line_usage_note( '', 'cart' ) );
		$this->assertStringEndsWith( 'incl. VAT', CoreHooks::line_usage_note( '', 'order' ), 'An order line says what its figures are: the display.' );
		$this->assertStringEndsWith( 'incl. VAT', \BeltoftServices\LineNames::usage_note() );
		$this->assertStringEndsWith( 'incl. VAT', \BeltoftServices\LineNames::usage_note( 'cart' ) );
	}

	public function test_an_order_line_shows_the_rate_and_cap_in_the_display(): void {
		$v     = $this->usage_product();
		$order = wc_create_order();
		$order->add_product( $v['u-None'], 1 );
		$order->save();
		$item = current( $order->get_items() );

		$this->assertStringContainsString( '0.0283', html_entity_decode( \BeltoftServices\Usage\Summary::order_subtotal( '', $item ) ), 'Including 25 percent VAT.' );

		$order->update_meta_data( 'is_vat_exempt', 'yes' );
		$order->save();
		$this->assertStringContainsString( '0.0226', html_entity_decode( \BeltoftServices\Usage\Summary::order_subtotal( '', current( wc_get_order( $order->get_id() )->get_items() ) ) ), 'A VAT-exempt order: excluding.' );

		$this->display( 'excl' );
		$this->assertStringContainsString( '0.0226', html_entity_decode( \BeltoftServices\Usage\Summary::order_subtotal( '', $item ) ) );
	}

	public function test_the_hosting_add_on_prices_follow_the_display(): void {
		$this->assertSame( '€10.00', \BeltoftServices\Hosting\Addons::shown( 8.0, 'EUR' ) );
		$this->display( 'excl' );
		$this->assertSame( '€8.00', \BeltoftServices\Hosting\Addons::shown( 8.0, 'EUR' ) );
	}

	public function test_a_cart_row_that_is_a_price_difference_uses_the_cart_display(): void {
		$v = $this->usage_product();
		update_option( 'woocommerce_tax_display_cart', 'excl' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );

		$this->assertSame( '+€3.00', TaxNote::in_cart( static fn() => Backups::price_difference( $v['m-None'], $v['m-Daily'] ) ) );
		$this->assertSame( '+€3.75', Backups::price_difference( $v['m-None'], $v['m-Daily'] ), 'The shop display is including.' );
	}

	public function test_the_hourly_backup_difference_is_a_usage_figure_in_the_display(): void {
		$v = $this->usage_product();

		$text = Backups::price_difference( $v['u-None'], $v['u-Daily'] );

		$this->assertStringContainsString( ' / hour', $text );
		$this->assertStringStartsWith( '+€0.', $text );
		$excl = ( function () use ( $v ) {
			$this->display( 'excl' );
			return Backups::price_difference( $v['u-None'], $v['u-Daily'] );
		} )();
		$this->assertNotSame( $excl, $text );
	}

	public function test_the_breakdown_blocks_add_up_in_the_including_display(): void {
		$v     = $this->usage_product();
		$parts = array( 'driver' => 'proxmox', 'plan' => 16.5, 'ip' => 0.0, 'storage' => 0.0, 'backups' => 3.3, 'max' => 19.8, 'hourly' => 0.0271 );

		$blocks = Breakdown::blocks( $v['u-None'], $parts, 'Server' );

		// 16.5 gives 20.625 (20.63) and 3.3 gives 4.125 (4.13): the maximum is their sum, 24.76, so the rows add up to it.
		$this->assertStringContainsString( '€20.63 / month', $blocks[1] );
		$this->assertStringContainsString( '+€4.13 / month', $blocks[1] );
		$this->assertStringContainsString( '€24.76', $blocks[1] );
		$this->assertStringContainsString( '€0.0339', $blocks[1] );
	}

	public function test_the_database_card_and_the_disk_sizes_and_the_snapshot_price(): void {
		$v = $this->usage_product();

		$this->assertStringContainsString( 'GB: €', DiskField::size_label( 10, $v['m-None'], false ) );
		$this->assertStringEndsWith( 'incl. VAT', DiskField::size_label( 10, $v['m-None'], false ) );
		$this->assertStringEndsWith( 'incl. VAT, billed by the hour', DiskField::size_label( 10, $v['u-None'], true ) );
		$this->assertSame( '€18.75', Panels::price_text( $v['m-None'] ) );

		$plan = array( 'name' => 'Small', 'price' => 20.0, 'period' => 'month', 'variation_id' => $v['m-None']->get_id(), 'in_stock' => true, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20, 'backup_days' => 0, 'engine' => '', 'usage' => array( 'cap' => 16.5, 'hourly' => 0.0226, 'variation_id' => $v['u-None']->get_id() ), 'backups' => null, 'note' => '' );
		$card = new \ReflectionMethod( DbPicker::class, 'card' );
		$card->setAccessible( true );
		ob_start();
		$card->invoke( null, $plan, false );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( wp_kses_post( wc_price( 25 ) ), $html );
		$this->assertStringContainsString( 'per month including VAT', $html );
	}

	public function test_the_app_licence_price_and_the_billing_words(): void {
		$p = $this->usage_product()['m-None'];
		$p->update_meta_data( '_subscription_period', 'month' );
		$p->update_meta_data( '_subscription_period_interval', '1' );
		$p->save();
		$p     = wc_get_product( $p->get_id() );
		$price = new \ReflectionMethod( AppLicence::class, 'price' );
		$price->setAccessible( true );

		$this->assertStringEndsWith( 'incl. VAT', $price->invoke( null, $p ) );
		$this->display( 'excl' );
		$this->assertStringEndsWith( 'excl. VAT', $price->invoke( null, $p ) );
	}

	public function test_a_usage_tag_in_text_is_filtered_and_kses_clean_and_elsewhere_it_is_plain(): void {
		$id = $this->usage_product()['m-None']->get_id();
		$f  = static fn( $html ) => $html . '<script>alert(1)</script><b onclick="x()">ok</b>';
		add_filter( 'beltoft_services_price_html', $f );
		$this->added[] = array( 'beltoft_services_price_html', $f, 10 );

		$text = apply_filters( 'bricks/dynamic_data/render_content', '{beltoft_usage_cap:' . $id . '}', null, 'text' );
		$this->assertStringNotContainsString( '<script', $text );
		$this->assertStringNotContainsString( 'onclick', $text );
		$this->assertStringContainsString( '<b>ok</b>', $text );

		$attr = apply_filters( 'bricks/dynamic_data/render_content', '{beltoft_usage_cap:' . $id . '}', null, 'link' );
		$this->assertSame( '€20.63', $attr, 'An attribute or a link gets the plain figure in the display.' );
	}

	public function test_a_domain_tag_in_an_attribute_is_the_plain_figure(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ) ) );
		$f = static fn( $html ) => '<i>' . $html . '</i>';
		add_filter( 'beltoft_services_price_html', $f );
		$this->added[] = array( 'beltoft_services_price_html', $f, 10 );

		$this->assertSame( '<i>€12</i>', apply_filters( 'bricks/dynamic_data/render_content', '{beltoft_domain_register:com}', null, 'text' ) );
		$this->assertSame( '€12', apply_filters( 'bricks/dynamic_data/render_content', '{beltoft_domain_register:com}', null, 'link' ) );
		$this->assertSame( '€12', PriceTags::value( 'com', false ) );
	}

	public function test_a_domain_price_with_no_domain_override_line_in_another_currency_is_not_sellable(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ) ) );

		$this->assertNull( Prices::price( 'com', 'DKK' ) );
		$this->assertFalse( Prices::sellable( 'com', 'DKK' ) );
		$this->assertSame( 12, Prices::price( 'com', 'EUR' ) );
		$this->assertNull( Prices::price( 'net', 'EUR' ), 'Not in the table.' );
	}
}

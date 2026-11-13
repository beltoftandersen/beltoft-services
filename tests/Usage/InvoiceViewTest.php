<?php
/**
 * The layout of a usage invoice in emails and order views.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Tests\GiftCredit\GiftCreditTestCase;
use Bgcw\GiftCard\Source;
use BeltoftServices\Usage\InvoiceView;
use BeltoftServices\Usage\Ledger;

require_once dirname( __DIR__ ) . '/GiftCredit/GiftCreditTestCase.php';

/**
 * Display only: fee lines become item rows, totals read in order, intros say usage invoice.
 */
class InvoiceViewTest extends GiftCreditTestCase {

	/**
	 * Usage invoice for October 2026: 100 hours at 0.02 = 2.00 net, 0.50 VAT.
	 *
	 * @param int $credit_cents Paid gift card on the account before the invoice (0: none).
	 */
	private function usage_invoice( int $credit_cents = 0 ): \WC_Order {
		$this->vat( true );
		$user  = $this->new_user();
		$sub   = $this->usage_account( $user );
		$start = (int) strtotime( '2026-10-01 00:00:00 UTC' );

		for ( $i = 0; $i < 100; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02, $start + $i * HOUR_IN_SECONDS );
		}

		if ( $credit_cents > 0 ) {
			$this->card( $user, $credit_cents, Source::ORDER );
		}

		$order = wcs_create_renewal_order( $sub );
		$this->assertSame( 'yes', $order->get_meta( '_beltoft_services_usage_lines' ) );
		$order->set_payment_method_title( 'Card' );

		return $order;
	}

	public function tear_down(): void {
		parent::tear_down();
		// wcs_create_renewal_order() committed the VAT settings of this test: switch them off for good, or later tests see VAT.
		$this->vat( false );
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reset.
	}

	private function labels( \WC_Order $order ): array {
		return array_values( array_map( static fn( $r ) => wp_strip_all_tags( $r['label'] ), $order->get_order_item_totals() ) );
	}

	private function email_html( string $id, \WC_Order $order, bool $plain = false ): string {
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email->id === $id ) {
				$email->object = $order;
				if ( ! $plain ) {
					return $email->get_content_html();
				}
				// WooCommerce's plain items template reads $purchase_note after a hidden item (the usage account line) skipped it.
				$previous = set_error_handler( static fn( $no, $str ) => false !== strpos( $str, '$purchase_note' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
				try {
					return $email->get_content_plain();
				} finally {
					set_error_handler( $previous );
				}
			}
		}
		$this->fail( 'No email ' . $id );
	}

	public function test_renewal_stores_name_and_detail_and_detection_uses_the_fee_meta(): void {
		$order = $this->usage_invoice();
		$fees  = InvoiceView::fees( $order );

		$this->assertCount( 1, $fees );
		$this->assertTrue( InvoiceView::is_usage( $order ) );
		$parts = InvoiceView::parts( $fees[0] );
		$this->assertStringStartsWith( 'Server ', $parts['name'] );
		$this->assertSame( 'October 2026: 100 hours at €0.0200 per hour', html_entity_decode( $parts['detail'] ) );
	}

	public function test_an_old_order_without_the_meta_is_taken_apart_from_the_name(): void {
		$order = $this->usage_invoice();
		$fee   = InvoiceView::fees( $order )[0];
		$fee->delete_meta_data( InvoiceView::META_NAME );
		$fee->delete_meta_data( InvoiceView::META_DETAIL );
		$fee->set_name( 'Server web1, September 2026: 200 hours at €0.0616 per hour (excl. VAT), capped at €10.00 a month' );

		$this->assertSame(
			array( 'name' => 'Server web1', 'detail' => 'September 2026: 200 hours at €0.0616 per hour, capped at €10.00' ),
			InvoiceView::parts( $fee )
		);
	}

	public function test_totals_of_a_card_paid_invoice(): void {
		$order = $this->usage_invoice();
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( array( 'Subtotal (excl. VAT):', 'VAT:', 'Charged to your card:', 'Payment method:' ), $this->labels( $order ) );
		$rows = $order->get_order_item_totals();
		$this->assertSame( '€2.00', trim( html_entity_decode( wp_strip_all_tags( $rows['cart_subtotal']['value'] ) ) ) );
		$this->assertSame( '€2.50', trim( html_entity_decode( wp_strip_all_tags( $rows['order_total']['value'] ) ) ) );
	}

	public function test_totals_with_credit_and_card(): void {
		$order = $this->usage_invoice( 100 );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( array( 'Subtotal (excl. VAT):', 'VAT:', 'Paid from account credit:', 'Charged to your card:', 'Payment method:' ), $this->labels( $order ) );
	}

	public function test_totals_when_credit_pays_it_all(): void {
		$order = $this->usage_invoice( 1000 );

		$this->assertSame( array( 'Subtotal (excl. VAT):', 'VAT:', 'Paid from account credit:' ), $this->labels( $order ) );
		$rows = $order->get_order_item_totals();
		$last = $rows[ array_key_last( array_diff_key( $rows, array( 'payment_method' => 1 ) ) ) ];
		$this->assertStringContainsString( '2.50', $last['value'] );
	}

	public function test_an_unpaid_or_failed_invoice_ends_with_total(): void {
		$order = $this->usage_invoice( 100 );
		$order->set_status( 'failed' );
		$order->save();

		$this->assertSame( array( 'Subtotal (excl. VAT):', 'VAT:', 'Paid from account credit:', 'Total:', 'Payment method:' ), $this->labels( $order ) );
	}

	public function test_the_fee_lines_are_item_rows_in_the_email_and_not_in_the_totals(): void {
		$order = $this->usage_invoice();
		$order->set_status( 'processing' );
		$order->save();
		$html = $this->email_html( 'customer_processing_renewal_order', $order );

		$this->assertStringContainsString( 'font-size:13px', $html );
		$this->assertStringContainsString( 'October 2026: 100 hours at', $html );
		$this->assertSame( 1, substr_count( $html, 'October 2026: 100 hours' ), 'Once, as an item row.' );
		$this->assertStringNotContainsString( 'excl. VAT)</th>', $html );
		$this->assertStringNotContainsString( 'order-totals-fee', $html );
		$this->assertStringContainsString( 'Charged to your card:', $html );
	}

	public function test_intros_of_the_customer_emails(): void {
		$order = $this->usage_invoice();
		$order->set_status( 'completed' );
		$order->save();

		foreach ( array( 'customer_completed_renewal_order', 'customer_processing_renewal_order' ) as $id ) {
			$html = $this->email_html( $id, $order );
			$this->assertStringContainsString( 'Here is your usage invoice for October 2026.', $html, $id );
			$this->assertStringNotContainsString( 'subscription renewal order', $html, $id );
		}

		$plain = $this->email_html( 'customer_completed_renewal_order', $order, true );
		$this->assertStringContainsString( 'Here is your usage invoice for October 2026.', $plain );
	}

	public function test_a_failed_invoice_keeps_the_pay_link(): void {
		$order = $this->usage_invoice();
		$order->set_status( 'failed' );
		$order->save();

		foreach ( array( false, true ) as $plain ) {
			$text = $this->email_html( 'customer_renewal_invoice', $order, $plain );
			$this->assertStringContainsString( 'Your usage invoice for October 2026 could not be charged. Pay it here:', $text );
			$this->assertStringContainsString( 'order-pay', $text );
		}
	}

	public function test_the_admin_intro_says_paid_created_or_failed(): void {
		$order = $this->usage_invoice();
		$n     = $order->get_order_number();
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Dbpayg' );

		$order->set_status( 'processing' );
		$order->save();
		$this->assertStringContainsString( "Usage invoice #$n for Test Dbpayg was paid.", $this->email_html( 'new_renewal_order', $order ) );
		$this->assertStringContainsString( "Usage invoice #$n for Test Dbpayg was paid.", $this->email_html( 'new_renewal_order', $order, true ) );

		$order->set_status( 'pending' );
		$order->save();
		$this->assertStringContainsString( "Usage invoice #$n for Test Dbpayg was created.", $this->email_html( 'new_renewal_order', $order ) );

		$order->set_status( 'failed' );
		$order->save();
		$this->assertStringContainsString( "Usage invoice #$n for Test Dbpayg could not be charged.", $this->email_html( 'new_renewal_order', $order ) );
	}

	public function test_plain_text_lists_the_usage_then_the_totals_in_order(): void {
		$order = $this->usage_invoice( 100 );
		$order->set_status( 'processing' );
		$order->save();
		$plain = html_entity_decode( $this->email_html( 'customer_processing_renewal_order', $order, true ) );

		$this->assertStringContainsString( 'October 2026: 100 hours at', $plain );
		$positions = array_map( static fn( $s ) => strpos( $plain, $s ), array( 'Subtotal (excl. VAT):', 'VAT', 'Paid from account credit:', 'Charged to your card:' ) );
		$this->assertNotContains( false, $positions );
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions );
	}

	public function test_screen_rows_in_the_woocommerce_template_and_in_a_bricks_hook(): void {
		$order = $this->usage_invoice();

		ob_start();
		do_action( 'woocommerce_before_template_part', 'order/order-details.php', '', '', array( 'order' => $order ) );
		do_action( 'woocommerce_order_details_after_order_table_items', $order );
		do_action( 'woocommerce_after_template_part', 'order/order-details.php', '', '', array( 'order' => $order ) );
		$table = ob_get_clean();
		$this->assertStringContainsString( '<tr class="woocommerce-table__line-item order_item">', $table );

		ob_start();
		do_action( 'woocommerce_order_details_after_order_table_items', $order );
		$bricks = ob_get_clean();
		$this->assertStringContainsString( 'beltoft-services-usage-row', $bricks );
		$this->assertStringNotContainsString( '<tr', $bricks );
	}

	public function test_a_normal_order_is_left_alone(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Thing' );
		$product->set_regular_price( '10' );
		$product->save();
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Setup' );
		$fee->set_total( '5' );
		$order->add_item( $fee );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();

		$this->assertFalse( InvoiceView::is_usage( $order ) );
		$rows = $order->get_order_item_totals();
		remove_filter( 'woocommerce_get_order_item_totals', array( InvoiceView::class, 'totals' ), 20 );
		$this->assertSame( $order->get_order_item_totals(), $rows );
		add_filter( 'woocommerce_get_order_item_totals', array( InvoiceView::class, 'totals' ), 20, 2 );
		$this->assertContains( 'Setup:', array_map( static fn( $r ) => wp_strip_all_tags( $r['label'] ), $rows ) );

		$html = $this->email_html( 'customer_processing_order', $order );
		$this->assertStringNotContainsString( 'font-size:13px;line-height:1.4;color:#757575', $html );
		$this->assertStringNotContainsString( 'usage invoice', strtolower( $html ) );

		ob_start();
		do_action( 'woocommerce_order_details_after_order_table_items', $order );
		$this->assertSame( '', ob_get_clean() );
	}
}

<?php
/**
 * Subject and heading of the renewal emails for a usage invoice.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Threshold;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Usage orders get usage words in each email; other renewals keep WooCommerce Subscriptions' words.
 */
class EmailsTest extends UsageTestCase {

	private function email( string $id, \WC_Order $order ): \WC_Email {
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email->id === $id ) {
				$email->object = $order;
				return $email;
			}
		}
		$this->fail( 'No email ' . $id );
	}

	/**
	 * A usage renewal for the month before this one (so the month words are fixed).
	 */
	private function usage_order( bool $paid ): \WC_Order {
		$user  = $this->new_user();
		$sub   = $this->usage_account( $user );
		$start = (int) strtotime( '2026-10-01 00:00:00 UTC' );

		for ( $i = 0; $i < 10; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02, $start + $i * HOUR_IN_SECONDS );
		}
		$order = wcs_create_renewal_order( $sub );
		$this->assertSame( 'yes', $order->get_meta( '_beltoft_services_usage_lines' ) );

		if ( $paid ) {
			$order->set_status( 'completed' );
			$order->save();
		}

		return $order;
	}

	public function test_a_paid_usage_invoice_has_the_usage_words_in_both_customer_emails(): void {
		$order = $this->usage_order( true );

		foreach ( array( 'customer_completed_renewal_order', 'customer_processing_renewal_order' ) as $id ) {
			$email = $this->email( $id, $order );
			$this->assertSame( 'Your usage invoice for October 2026', $email->get_subject(), $id );
			$this->assertSame( 'Usage invoice for October 2026', $email->get_heading(), $id );
		}
	}

	public function test_an_unpaid_usage_invoice_asks_for_payment(): void {
		$order = $this->usage_order( false );
		$email = $this->email( 'customer_renewal_invoice', $order );

		$this->assertSame( 'Your usage invoice for October 2026 needs payment', $email->get_subject() );
		$this->assertSame( 'Usage invoice needs payment', $email->get_heading() );
	}

	public function test_the_admin_email_names_the_invoice(): void {
		$order = $this->usage_order( true );
		$email = $this->email( 'new_renewal_order', $order );

		$this->assertSame( 'Usage invoice #' . $order->get_order_number() . ' for October 2026', $email->get_subject() );
		$this->assertSame( 'Usage invoice', $email->get_heading() );
	}

	public function test_an_early_charge_says_early_in_every_email(): void {
		$order = $this->usage_order( false );
		$order->update_meta_data( '_beltoft_services_usage_early', 'yes' );
		$order->save();

		$this->assertSame( 'Usage invoice (early) #' . $order->get_order_number() . ' for October 2026', $this->email( 'new_renewal_order', $order )->get_subject() );
		$this->assertSame( 'Usage invoice (early)', $this->email( 'new_renewal_order', $order )->get_heading() );
		$this->assertSame( 'Your usage invoice (early) for October 2026 needs payment', $this->email( 'customer_renewal_invoice', $order )->get_subject() );

		$order->set_status( 'processing' );
		$order->save();
		$this->assertSame( 'Your usage invoice (early) for October 2026', $this->email( 'customer_processing_renewal_order', $order )->get_subject() );
		$this->assertSame( 'Usage invoice (early) for October 2026', $this->email( 'customer_processing_renewal_order', $order )->get_heading() );
	}

	public function test_the_threshold_marks_its_order_as_early(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		for ( $i = 0; $i < 3000; $i++ ) {
			Ledger::record( 501, $user, 'server', 1.0, 'hour', 0.02, Ledger::hour( time() ) - ( $i + 1 ) * HOUR_IN_SECONDS );
		}

		$this->assertTrue( Threshold::check( $sub ) );
		$order = $sub->get_last_order( 'all', 'renewal' );
		$this->assertSame( 'yes', $order->get_meta( '_beltoft_services_usage_early' ) );
	}

	public function test_a_normal_renewal_keeps_the_subscriptions_words(): void {
		$user  = $this->new_user();
		$sub   = $this->usage_account( $user );
		$order = wcs_create_renewal_order( $sub );
		$this->assertSame( '', (string) $order->get_meta( '_beltoft_services_usage_lines' ), 'No usage rows: a plain renewal.' );

		foreach ( array( 'new_renewal_order', 'customer_processing_renewal_order', 'customer_completed_renewal_order', 'customer_renewal_invoice' ) as $id ) {
			$email = $this->email( $id, $order );
			$this->assertStringNotContainsString( 'Usage invoice', $email->get_heading(), $id );
			$this->assertStringNotContainsString( 'sage invoice', $email->get_subject(), $id );
		}
		$this->assertSame( 'New subscription renewal order', $this->email( 'new_renewal_order', $order )->get_heading() );
	}
}

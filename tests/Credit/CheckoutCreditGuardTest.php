<?php
/**
 * An order whose account credit was given back (released) can no longer be paid by the customer, and a payment that was started before the
 * release and completes afterwards is held for an administrator: the credit is back in the balance, so the order must not also be
 * fulfilled at the lowered amount.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftLicenses\Licenses;
use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\CheckoutCredit;

require_once __DIR__ . '/CheckoutCreditCase.php';

/**
 * Guard tests.
 */
class CheckoutCreditGuardTest extends CheckoutCreditCase {

	/**
	 * An unpaid credited order A that a changed cart then released (a second order B takes the credit).
	 *
	 * @param \WC_Product[] $products Products of A.
	 * @return array{0: int, 1: \WC_Order, 2: \WC_Order} The customer, A (released), B.
	 */
	private function released( array $products ): array {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$this->cart( $user, $products );
		$a = $this->place();
		$this->assertSame( 400, \BeltoftServices\Credit\Invoice::used( $a ) );
		$this->cart( $user, array( $this->product( false, '30' ) ) );
		$b = $this->place();
		$this->assertSame( 400, \BeltoftServices\Credit\Invoice::used( $b ) );

		return array( $user, wc_get_order( $a->get_id() ), $b );
	}

	/**
	 * The admin alerts about a late payment (the shop's own order mails are not counted).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function alerts(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) ( $m['subject'] ?? '' ), 'after its account credit was returned' ) ) );
	}

	private function licences( \WC_Order $order ): int {
		return count( Licenses::where( 'order_id', $order->get_id() ) );
	}

	public function test_a_released_order_cannot_be_paid_by_the_customer(): void {
		list( , $a ) = $this->released( array( $this->product( false ) ) );

		$this->assertNotSame( '', (string) $a->get_meta( CheckoutCredit::META_RELEASED ) );
		$this->assertFalse( $a->needs_payment() );
		$this->assertSame( array(), apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), $a ) );
	}

	public function test_an_unreleased_credited_order_and_an_ordinary_order_stay_payable(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$this->cart( $user, array( $this->product( false ) ) );
		$this->assertTrue( $this->place()->needs_payment() );

		$plain = wc_create_order( array( 'customer_id' => $user ) );
		$plain->add_product( $this->product( false ), 1 );
		$plain->calculate_totals( true );
		$plain->save();
		$this->assertTrue( $plain->needs_payment() );
		$plain->payment_complete();
		$this->assertContains( wc_get_order( $plain->get_id() )->get_status(), array( 'processing', 'completed' ) );
	}

	public function test_a_payment_that_completes_after_the_release_is_held_and_nothing_is_set_up(): void {
		list( $user, $a ) = $this->released( array( $this->product( false ) ) );

		$a->payment_complete( 'pi_late' );

		$a = wc_get_order( $a->get_id() );
		$this->assertSame( 'on-hold', $a->get_status() );
		$this->assertSame( 0, $this->licences( $a ), 'No licence, so nothing is provisioned.' );
		$this->assertSame( 'yes', $a->get_meta( CheckoutCredit::META_GUARDED ) );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $a->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'after its account credit (4.00 EUR) had been returned to the customer', $notes );
		$this->assertCount( 1, $this->alerts(), 'One alert.' );

		// The webhook arrives as well: still held, no second alert.
		$a->payment_complete( 'pi_late' );
		$this->assertSame( 'on-hold', wc_get_order( $a->get_id() )->get_status() );
		$this->assertSame( 0, $this->licences( $a ) );
		$this->assertCount( 1, $this->alerts() );

		// The credit stays in the balance: B took 400 of it, so what is left is not touched.
		$this->assertLedgerBalanced( $user );
	}

	public function test_a_gateway_that_sets_the_paid_status_itself_is_held_too(): void {
		foreach ( array( 'processing', 'completed' ) as $status ) {
			list( , $a ) = $this->released( array( $this->product( false ) ) );

			$a->update_status( $status );

			$a = wc_get_order( $a->get_id() );
			$this->assertSame( 'on-hold', $a->get_status(), $status );
			$this->assertSame( 0, $this->licences( $a ), $status );
			$this->assertNull( $a->get_date_paid(), $status );
		}
	}

	public function test_an_administrator_can_move_a_held_order_on(): void {
		list( , $a ) = $this->released( array( $this->product( false ) ) );
		$a->payment_complete( 'pi_late' );
		$this->assertSame( 'on-hold', wc_get_order( $a->get_id() )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$a = wc_get_order( $a->get_id() );
		$a->update_status( 'processing' );

		$a = wc_get_order( $a->get_id() );
		$this->assertSame( 'processing', $a->get_status() );
		$this->assertSame( 'yes', $a->get_meta( CheckoutCredit::META_CLEARED ) );
		$this->assertSame( 1, $this->licences( $a ), 'Set up once an administrator has decided.' );
	}

	public function test_a_released_subscription_order_that_is_paid_late_does_not_activate_the_subscription(): void {
		list( , $a ) = $this->released( array( $this->as_subscription( $this->product( false ) ) ) );
		$subs = wcs_get_subscriptions_for_order( $a );
		$this->assertCount( 1, $subs );
		$sub = array_values( $subs )[0];
		$this->assertNotSame( 'active', $sub->get_status() );

		$a->payment_complete( 'pi_late' );

		$a   = wc_get_order( $a->get_id() );
		$sub = wcs_get_subscription( $sub->get_id() );
		$this->assertSame( 'on-hold', $a->get_status() );
		$this->assertNotSame( 'active', $sub->get_status(), 'The subscription stays inactive until an administrator moves the order on.' );
		$this->assertCount( 0, Licenses::where( 'subscription_id', $sub->get_id() ), 'No licence for the subscription.' );
		$this->assertCount( 1, $this->alerts() );
	}

	public function test_a_paid_credited_order_is_never_released_or_held(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$this->cart( $user, array( $this->product( false ) ) );
		$a = $this->place();
		$a->payment_complete( 'pi_ok' );
		$this->cart( $user, array( $this->product( false, '30' ) ) );
		$this->place();

		$a = wc_get_order( $a->get_id() );
		$this->assertSame( '', (string) $a->get_meta( CheckoutCredit::META_RELEASED ) );
		$this->assertContains( $a->get_status(), array( 'processing', 'completed' ) );
		$this->assertSame( 1, $this->licences( $a ) );
		$this->assertCount( 0, $this->alerts() );
		$this->assertSame( 0, Balance::get( $user ) );
	}
}

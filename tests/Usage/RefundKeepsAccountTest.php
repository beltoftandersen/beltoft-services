<?php
/**
 * A refunded usage invoice does not end the pay as you go account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

/**
 * The answer to the beltoft-licenses filter beltoft_licenses_cancel_subscription_on_refund.
 */
class RefundKeepsAccountTest extends UsageTestCase {

	public function test_a_refund_never_cancels_the_usage_account(): void {
		$account = $this->usage_account( $this->new_user() );

		$this->assertFalse( apply_filters( 'beltoft_licenses_cancel_subscription_on_refund', true, $account, null ) );
	}

	public function test_another_subscription_keeps_the_answer_it_had(): void {
		$other = wcs_create_subscription(
			array(
				'customer_id'      => $this->new_user(),
				'billing_period'   => 'month',
				'billing_interval' => 1,
			)
		);

		$this->assertTrue( apply_filters( 'beltoft_licenses_cancel_subscription_on_refund', true, $other, null ) );
		$this->assertFalse( apply_filters( 'beltoft_licenses_cancel_subscription_on_refund', false, $other, null ) );
	}
}

<?php
/**
 * A trusted customer is not held by the new-account rules.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\Cart;
use BeltoftServices\Usage\Threshold;
use BeltoftServices\Usage\Trusted;

final class TrustedTest extends UsageTestCase {

	public function test_the_new_account_limit_and_threshold_are_lifted_for_a_trusted_customer(): void {
		$this->settings( array( 'usage_new_limit' => 1, 'usage_threshold_new' => 15, 'usage_threshold' => 250 ) );
		$user    = $this->new_user();
		$account = $this->usage_account( $user );

		$this->assertStringContainsString( 'Contact us', (string) Cart::limit_problem( $user, $account, 2 ) );
		$this->assertSame( 15.0, Threshold::for_account( $account ) );

		update_user_meta( $user, Trusted::META, 'yes' );
		$this->assertNull( Cart::limit_problem( $user, $account, 2 ) );
		$this->assertSame( 250.0, Threshold::for_account( $account ) );
		$this->assertNull( Cart::limit_problem( $user, null, 2 ), 'Also before the customer has a usage account.' );
	}

	public function test_only_a_shop_manager_can_set_the_mark(): void {
		$user = $this->new_user();
		$_POST['beltoft_services_usage_trusted_nonce'] = wp_create_nonce( 'beltoft_services_usage_trusted' );
		$_POST['beltoft_services_usage_trusted']       = 'yes';

		wp_set_current_user( $user );
		Trusted::save( $user );
		$this->assertFalse( Trusted::is( $user ), 'A customer cannot trust themselves.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$_POST['beltoft_services_usage_trusted_nonce'] = wp_create_nonce( 'beltoft_services_usage_trusted' );
		Trusted::save( $user );
		$this->assertTrue( Trusted::is( $user ) );

		unset( $_POST['beltoft_services_usage_trusted'] );
		Trusted::save( $user );
		$this->assertFalse( Trusted::is( $user ) );
		unset( $_POST['beltoft_services_usage_trusted_nonce'] );
	}
}

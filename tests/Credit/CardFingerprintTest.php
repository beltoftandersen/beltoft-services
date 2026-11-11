<?php
/**
 * The card fingerprint is read through the Stripe plugin's gateway client.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Card\Card;
use BeltoftServices\Tests\Usage\UsageCardGateway;

require_once __DIR__ . '/CreditTestCase.php';
require_once dirname( __DIR__ ) . '/fixtures/stripe-gateway-stub.php';

/**
 * Card::token_fingerprint without the site filter.
 */
class CardFingerprintTest extends CreditTestCase {

	public function set_up(): void {
		parent::set_up();
		WC()->payment_gateways()->payment_gateways['stripe_cc'] = new UsageCardGateway( 'stripe_cc' );
	}

	public function tear_down(): void {
		unset( WC()->payment_gateways()->payment_gateways['stripe_cc'] );
		parent::tear_down();
	}

	/**
	 * An account whose card is the saved token with this payment method id.
	 *
	 * @param string $id Payment method id.
	 */
	private function account( string $id ): \WC_Subscription {
		$user  = (int) self::factory()->user->create( array( 'user_email' => 'christian.beltoft+fp' . wp_generate_password( 6, false ) . '@gmail.com' ) );
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe_cc' );
		$token->set_token( $id );
		$token->set_user_id( $user );
		$token->set_card_type( 'visa' );
		$token->set_last4( '1111' );
		$token->set_expiry_month( '08' );
		$token->set_expiry_year( '2099' );
		$token->save();

		$sub = $this->usage_account( $user, 'active' );
		$sub->set_payment_method( 'stripe_cc' );
		$sub->update_meta_data( '_payment_method_token', $id );
		$sub->save();

		return $sub;
	}

	public function test_the_fingerprint_comes_from_the_gateway_client(): void {
		if ( ! property_exists( '\WC_Stripe_Gateway', 'cards' ) ) {
			$this->markTestSkipped( 'The real Stripe plugin is loaded.' );
		}

		\WC_Stripe_Gateway::$cards = array( 'pm_known' => 'fp_REAL' );

		$this->assertSame( 'fp_REAL', Card::token_fingerprint( $this->account( 'pm_known' ) ) );
		$this->assertSame( '', Card::token_fingerprint( $this->account( 'pm_gone' ) ), 'A gateway error gives no fingerprint.' );
	}
}

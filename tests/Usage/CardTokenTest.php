<?php
/**
 * Saved card through WooCommerce's own token flow (Stripe and any gateway that saves tokens), no Mollie.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Card\Card;
use BeltoftServices\Usage\Account;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * A gateway that saves cards as tokens.
 */
class UsageTokenGateway extends UsageCardGateway {
	public function __construct( string $id = 'stripe_cc' ) {
		parent::__construct( $id );
		$this->supports[] = 'add_payment_method';
		$this->supports[] = 'tokenization';
	}
}

/**
 * Card token tests.
 */
class CardTokenTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		WC()->payment_gateways()->payment_gateways['stripe_cc'] = new UsageTokenGateway( 'stripe_cc' );
	}

	public function tear_down(): void {
		unset( WC()->payment_gateways()->payment_gateways['stripe_cc'] );
		parent::tear_down();
	}

	/**
	 * A saved card token of a customer.
	 *
	 * @param int    $user  Customer.
	 * @param string $pm    Payment method id.
	 * @param string $year  Expiry year.
	 * @param string $brand Brand.
	 */
	private function token( int $user, string $pm = 'pm_1', string $year = '2099', string $brand = 'visa', string $gateway = 'stripe_cc' ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway );
		$token->set_token( $pm );
		$token->set_user_id( $user );
		$token->set_card_type( $brand );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '08' );
		$token->set_expiry_year( $year );
		$token->save();

		return $token;
	}

	/**
	 * Makes the token the default the way the gateway's Add payment method does.
	 *
	 * @param int               $user  Customer.
	 * @param \WC_Payment_Token $token Token.
	 */
	private function make_default( int $user, \WC_Payment_Token $token ): void {
		wp_set_current_user( $user );
		\WC_Payment_Tokens::set_users_default( $user, $token->get_id() );
	}

	public function test_the_flow_is_token_when_a_token_gateway_is_enabled_and_mollie_otherwise(): void {
		$this->assertSame( 'token', Card::flow() );

		unset( WC()->payment_gateways()->payment_gateways['stripe_cc'] );
		$this->assertSame( 'mollie', Card::flow() );
	}

	public function test_a_card_saved_creates_the_account_with_the_token_and_it_is_chargeable(): void {
		$user = $this->new_user();
		$this->assertNull( Account::for_user( $user ) );

		$this->make_default( $user, $this->token( $user ) );

		$account = Account::for_user( $user );
		$this->assertInstanceOf( \WC_Subscription::class, $account );
		$this->assertTrue( Account::is_account( $account ) );
		$this->assertTrue( $account->has_status( 'active' ) );
		$this->assertSame( 'stripe_cc', $account->get_payment_method() );
		$this->assertFalse( $account->is_manual() );
		$this->assertSame( 'pm_1', $account->get_meta( '_payment_method_token' ) );
		$this->assertSame( 'Visa ending 4242, expires 08/99', Card::describe( (array) Card::details( $account ) ) );
	}

	public function test_a_stripe_account_is_chargeable_with_a_token_and_customer_and_not_without(): void {
		$user    = $this->new_user();
		$token   = $this->token( $user );
		$account = $this->usage_account( $user );
		$account->set_payment_method( 'stripe_cc' );
		$account->save();

		$this->assertFalse( Account::chargeable( $account ), 'No token on the account.' );

		$account->update_meta_data( '_payment_method_token', 'pm_1' );
		$account->save();
		$this->assertFalse( Account::chargeable( $account ), 'No Stripe customer.' );

		$account->update_meta_data( '_wc_stripe_customer', 'cus_1' );
		$account->save();
		$this->assertTrue( Account::chargeable( $account ) );
		$this->assertTrue( Account::has_valid_mandate( $user ) );

		$token->delete();
		$this->assertFalse( Account::chargeable( wcs_get_subscription( $account->get_id() ) ), 'The customer deleted the card.' );
		$this->assertFalse( Account::has_valid_mandate( $user ) );
	}

	public function test_an_expired_token_is_not_chargeable(): void {
		$user    = $this->new_user();
		$this->token( $user, 'pm_old', '2020' );
		$account = $this->usage_account( $user );
		$account->set_payment_method( 'stripe_cc' );
		$account->update_meta_data( '_payment_method_token', 'pm_old' );
		$account->update_meta_data( '_wc_stripe_customer', 'cus_1' );
		$account->save();

		$this->assertTrue( Card::details( $account )['expired'] );
		$this->assertFalse( Account::chargeable( $account ) );
	}

	public function test_a_new_default_card_updates_the_existing_account(): void {
		$user = $this->new_user();
		$this->make_default( $user, $this->token( $user, 'pm_1' ) );
		$account = Account::for_user( $user );
		$id      = $account->get_id();

		$this->make_default( $user, $this->token( $user, 'pm_2', '2098', 'mastercard' ) );

		$account = Account::for_user( $user );
		$this->assertSame( $id, $account->get_id(), 'The same account, not a second one.' );
		$this->assertSame( 'pm_2', $account->get_meta( '_payment_method_token' ) );
		$this->assertSame( 'Mastercard ending 4242, expires 08/98', Card::describe( (array) Card::details( $account ) ) );
	}

	public function test_a_filter_can_change_what_is_written_on_the_account(): void {
		add_filter(
			'beltoft_services_card_token_meta',
			static function ( array $meta ): array {
				$meta['_extra'] = 'x';
				return $meta;
			}
		);
		$user = $this->new_user();
		$this->make_default( $user, $this->token( $user ) );

		$this->assertSame( 'x', Account::for_user( $user )->get_meta( '_extra' ) );
		remove_all_filters( 'beltoft_services_card_token_meta' );
	}

	public function test_a_mollie_token_is_ignored(): void {
		$user  = $this->new_user();
		$token = $this->token( $user, 'pm_1', '2099', 'visa', 'mollie_wc_gateway_creditcard' );
		$this->make_default( $user, $token );

		$this->assertNull( Account::for_user( $user ) );
	}

	public function test_a_card_saved_at_checkout_or_by_someone_else_makes_no_account(): void {
		$user = $this->new_user();
		$this->token( $user );
		$this->assertNull( Account::for_user( $user ), 'No current user: no account.' );

		$other = $this->new_user();
		wp_set_current_user( $other );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->make_default( $other, $this->token( $other, 'pm_9' ) );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->assertNull( Account::for_user( $other ), 'A checkout does not make an account.' );
	}

	public function test_the_page_links_to_the_add_payment_method_page_and_shows_the_saved_card(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$html = Card::html();
		$this->assertStringContainsString( 'add-payment-method', $html );
		$this->assertStringContainsString( 'Add a card', $html );
		$this->assertStringContainsString( 'payment provider&#039;s secure form', $html );
		$this->assertStringNotContainsString( 'Mollie', $html );

		$this->make_default( $user, $this->token( $user ) );
		$html = Card::html();
		$this->assertStringContainsString( 'Visa ending 4242, expires 08/99', $html );
		$this->assertStringContainsString( 'Change card', $html );
		$this->assertDoesNotMatchRegularExpression( '/\x{2014}|\x{00B7}/u', $html );
	}
}

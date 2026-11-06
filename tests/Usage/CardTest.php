<?php
/**
 * The saved card: the customer's default saved payment method (a WooCommerce token, Stripe's) is the usage account's card.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Card\Card;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Cart;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * A gateway that saves cards as tokens and charges subscriptions.
 */
class UsageTokenGateway extends UsageCardGateway {
	public function __construct( string $id = 'stripe_cc' ) {
		parent::__construct( $id );
		$this->supports[] = 'add_payment_method';
		$this->supports[] = 'tokenization';
	}
}

/**
 * Card tests.
 */
class CardTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		WC()->payment_gateways()->payment_gateways['stripe_cc']        = new UsageTokenGateway( 'stripe_cc' );
		WC()->payment_gateways()->payment_gateways['stripe_googlepay'] = new UsageTokenGateway( 'stripe_googlepay' );
	}

	public function tear_down(): void {
		unset( WC()->payment_gateways()->payment_gateways['stripe_cc'], WC()->payment_gateways()->payment_gateways['stripe_googlepay'] );
		remove_all_filters( 'beltoft_services_card_token_meta' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A saved card token of a customer.
	 *
	 * @param int    $user    Customer.
	 * @param string $pm      Payment method id.
	 * @param string $year    Expiry year.
	 * @param string $brand   Brand.
	 * @param string $gateway Gateway.
	 * @param string $last4   Last four digits.
	 */
	private function token( int $user, string $pm = 'pm_1', string $year = '2099', string $brand = 'visa', string $gateway = 'stripe_cc', string $last4 = '4242' ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway );
		$token->set_token( $pm );
		$token->set_user_id( $user );
		$token->set_card_type( $brand );
		$token->set_last4( $last4 );
		$token->set_expiry_month( '08' );
		$token->set_expiry_year( $year );
		$token->save();

		return $token;
	}

	/**
	 * Saves a card the way the gateway's Add payment method page does: the customer is logged in and the new card is the default.
	 *
	 * @param int    $user Customer.
	 * @param string $pm   Payment method id.
	 * @param string $year Expiry year.
	 */
	private function add_card( int $user, string $pm = 'pm_1', string $year = '2099', string $brand = 'visa', string $gateway = 'stripe_cc' ): \WC_Payment_Token_CC {
		wp_set_current_user( $user );
		$token = $this->token( $user, $pm, $year, $brand, $gateway );
		\WC_Payment_Tokens::set_users_default( $user, $token->get_id() );

		return $token;
	}

	/**
	 * A usage account carrying leftovers of the retired provider (as the owner's does).
	 *
	 * @param int $user Customer.
	 */
	private function legacy_account( int $user ): \WC_Subscription {
		$account = $this->usage_account( $user );
		$account->set_payment_method( 'mollie_wc_gateway_creditcard' );
		$account->update_meta_data( '_mollie_customer_id', 'cst_old' );
		$account->update_meta_data( '_mollie_mandate_id', 'mdt_old' );
		$account->save();

		return wcs_get_subscription( $account->get_id() );
	}

	private function fresh( \WC_Subscription $account ): \WC_Subscription {
		return wcs_get_subscription( $account->get_id() );
	}

	// ------------------------------------------------------------------ a token event links the account.

	public function test_a_card_added_creates_the_account_with_what_a_stripe_renewal_reads(): void {
		$user = $this->new_user();
		$this->assertNull( Account::for_user( $user ) );

		$token = $this->add_card( $user );

		$account = Account::for_user( $user );
		$this->assertInstanceOf( \WC_Subscription::class, $account );
		$this->assertTrue( Account::is_account( $account ) );
		$this->assertTrue( $account->has_status( 'active' ) );
		$this->assertSame( 'stripe_cc', $account->get_payment_method() );
		$this->assertFalse( $account->is_manual() );
		$this->assertSame( 'pm_1', $account->get_meta( '_payment_method_token' ) );
		$this->assertSame( 'Visa ending 4242, expires 08/99', Card::describe( (array) Card::details( $account ) ) );
		$this->assertTrue( Card::linked( $account, $token ) );
		$this->assertGreaterThan( time(), $account->get_time( 'next_payment' ), 'It renews next month.' );
		$items = $account->get_items();
		$this->assertSame( Account::product_id(), (int) reset( $items )->get_product_id() );
	}

	public function test_the_new_account_takes_the_billing_details_the_customer_has(): void {
		$user = $this->new_user();
		update_user_meta( $user, 'billing_first_name', 'Anna' );
		update_user_meta( $user, 'billing_country', 'DK' );
		$this->add_card( $user );

		$account = Account::for_user( $user );
		$this->assertSame( 'Anna', $account->get_billing_first_name() );
		$this->assertSame( 'DK', $account->get_billing_country() );
		$this->assertSame( get_userdata( $user )->user_email, $account->get_billing_email() );
	}

	public function test_a_new_default_card_relinks_the_existing_account(): void {
		$user = $this->new_user();
		$this->add_card( $user, 'pm_1' );
		$id = Account::for_user( $user )->get_id();

		$this->add_card( $user, 'pm_2', '2098', 'mastercard' );

		$account = Account::for_user( $user );
		$this->assertSame( $id, $account->get_id(), 'The same account, not a second one.' );
		$this->assertSame( 'pm_2', $account->get_meta( '_payment_method_token' ) );
		$this->assertSame( 'Mastercard ending 4242, expires 08/98', Card::describe( (array) Card::details( $account ) ) );
	}

	public function test_making_another_card_the_default_relinks_the_account(): void {
		$user = $this->new_user();
		$first = $this->add_card( $user, 'pm_1' );
		$this->add_card( $user, 'pm_2' );

		\WC_Payment_Tokens::set_users_default( $user, $first->get_id() );

		$this->assertSame( 'pm_1', Account::for_user( $user )->get_meta( '_payment_method_token' ) );
	}

	public function test_a_card_added_but_not_made_default_leaves_the_default_linked(): void {
		$user = $this->new_user();
		$this->add_card( $user, 'pm_1' );

		$this->token( $user, 'pm_2' );

		$this->assertSame( 'pm_1', Account::for_user( $user )->get_meta( '_payment_method_token' ) );
	}

	public function test_a_card_added_at_checkout_links_an_existing_account_but_makes_none(): void {
		$other = $this->new_user();
		wp_set_current_user( $other );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->token( $other, 'pm_9' );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->assertNull( Account::for_user( $other ), 'A checkout does not make an account.' );

		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		wp_set_current_user( $user );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->token( $user, 'pm_8' );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );

		$this->assertSame( 'pm_8', $this->fresh( $account )->get_meta( '_payment_method_token' ), 'An account is linked on any page.' );
		$this->assertSame( 'stripe_cc', $this->fresh( $account )->get_payment_method() );
	}

	public function test_a_card_saved_by_someone_else_or_by_cron_makes_no_account(): void {
		$user = $this->new_user();
		$this->token( $user );
		$this->assertNull( Account::for_user( $user ), 'No current user: no account.' );
	}

	public function test_a_google_pay_card_is_the_account_card_too(): void {
		$user = $this->new_user();
		$this->add_card( $user, 'pm_g', '2099', 'visa', 'stripe_googlepay' );

		$account = Account::for_user( $user );
		$this->assertSame( 'stripe_googlepay', $account->get_payment_method() );
		$this->assertSame( 'pm_g', $account->get_meta( '_payment_method_token' ) );
	}

	public function test_a_card_of_a_gateway_that_charges_no_subscriptions_is_ignored(): void {
		$user = $this->new_user();
		$this->add_card( $user, 'pm_x', '2099', 'visa', 'unknown_gateway' );

		$this->assertNull( Account::for_user( $user ) );
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
		$this->add_card( $user );

		$this->assertSame( 'x', Account::for_user( $user )->get_meta( '_extra' ) );
	}

	public function test_a_card_removes_the_old_provider_data_from_the_account_and_its_first_order(): void {
		$user    = $this->new_user();
		$account = $this->legacy_account( $user );
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->update_meta_data( '_mollie_customer_id', 'cst_old' );
		$order->update_meta_data( '_mollie_mandate_id', 'mdt_old' );
		$order->save();
		$account->set_parent_id( $order->get_id() );
		$account->save();

		$this->add_card( $user );

		$account = $this->fresh( $account );
		$this->assertSame( '', $account->get_meta( '_mollie_customer_id' ) );
		$this->assertSame( '', $account->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( 'stripe_cc', $account->get_payment_method() );
	}

	// ------------------------------------------------------------------ a deleted card unlinks it.

	public function test_deleting_the_default_card_makes_the_newest_left_the_default_and_relinks(): void {
		$user = $this->new_user();
		$this->add_card( $user, 'pm_1' );
		$this->token( $user, 'pm_2' );
		$default = \WC_Payment_Tokens::get_customer_default_token( $user );
		$this->assertSame( 'pm_1', $default->get_token() );

		$default->delete();

		$this->assertSame( 'pm_2', \WC_Payment_Tokens::get_customer_default_token( $user )->get_token() );
		$this->assertSame( 'pm_2', Account::for_user( $user )->get_meta( '_payment_method_token' ) );
	}

	public function test_deleting_the_last_card_leaves_the_account_without_a_card(): void {
		$user  = $this->new_user();
		$token = $this->add_card( $user );
		$id    = Account::for_user( $user )->get_id();
		$this->assertTrue( Account::has_valid_mandate( $user ) );

		$token->delete();

		$account = wcs_get_subscription( $id );
		$this->assertSame( '', (string) $account->get_meta( '_payment_method_token' ) );
		$this->assertSame( '', (string) $account->get_meta( '_wc_stripe_customer' ) );
		$this->assertSame( '', (string) $account->get_payment_method() );
		$this->assertTrue( $account->is_manual() );
		$this->assertFalse( Account::has_card( $account ) );
		$this->assertFalse( Account::chargeable( $account ) );
		$this->assertFalse( Account::has_valid_mandate( $user ) );
	}

	// ------------------------------------------------------------------ chargeable.

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
		$this->assertFalse( Account::chargeable( $this->fresh( $account ) ), 'The customer deleted the card.' );
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

	public function test_a_card_of_an_inactive_payment_method_is_not_shown_or_charged(): void {
		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$account->set_payment_method( 'retired_gateway' );
		$account->update_meta_data( '_payment_method_token', 'pm_1' );
		$account->save();
		add_filter( 'woocommerce_subscription_is_manual', '__return_false' );

		$this->assertFalse( Card::gateway_active( 'retired_gateway' ) );
		$this->assertNull( Card::details( $account ), 'No card is shown for it.' );
		$this->assertFalse( Account::chargeable( $account ) );
		$this->assertSame( array(), $this->http_calls() );
	}

	public function test_a_server_order_with_a_card_account_needs_no_payment(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$this->add_card( $user );
		wp_set_current_user( $user );

		WC()->cart->add_to_cart( $v['u-None']->get_parent_id(), 1, $v['u-None']->get_id(), array( 'attribute_billing' => 'Pay as you go', 'attribute_backups' => 'None' ) );
		WC()->cart->calculate_totals();

		$this->assertFalse( WC()->cart->needs_payment(), 'The card account makes it a 0 order.' );
		$this->assertSame( array(), Cart::problems( $user, '', Cart::usage_units() ) );
		$this->assertCount( 1, WC()->cart->get_cart(), 'No second account line.' );
	}

	// ------------------------------------------------------------------ the page.

	/**
	 * What the plugin prints on the page (WooCommerce Subscriptions adds a hidden notice stub of its own at the same hook).
	 */
	private function note_html(): string {
		ob_start();
		Card::note();

		return (string) ob_get_clean();
	}

	public function test_the_page_has_one_line_and_no_box_of_its_own(): void {
		$user = $this->new_user();
		$this->add_card( $user );
		$html = $this->note_html();

		$this->assertSame( '<p class="beltoft-services-card__note">Your default card is used for Pay as you go invoices.</p>', $html );
		$this->assertNotFalse( has_action( 'woocommerce_before_account_payment_methods', array( Card::class, 'note' ) ) );
		$this->assertFalse( has_action( 'woocommerce_account_payment-methods_endpoint', array( Card::class, 'render' ) ) );
		$this->assertFalse( has_filter( 'bricks/frontend/render_element', array( Card::class, 'bricks_methods' ) ) );
		$this->assertFalse( method_exists( Card::class, 'html' ) );
		$this->assertFalse( file_exists( BELTOFT_SERVICES_PATH . 'templates/account/card.php' ) );
		$this->assertDoesNotMatchRegularExpression( '/\x{2014}|\x{00B7}/u', $html );
	}

	public function test_the_line_without_a_card_but_with_an_account_says_to_add_one(): void {
		$user = $this->new_user();
		$this->usage_account( $user );
		wp_set_current_user( $user );

		$this->assertSame( '<p class="beltoft-services-card__note">Pay as you go services are invoiced to your default card. Add a card to use them.</p>', $this->note_html() );
	}

	public function test_no_line_for_a_customer_with_neither_a_card_nor_an_account(): void {
		wp_set_current_user( $this->new_user() );

		$this->assertSame( '', $this->note_html() );
	}

	public function test_the_payment_methods_menu_item_is_still_added(): void {
		wp_set_current_user( $this->new_user() );
		$items = Card::menu( array( 'orders' => 'Orders', 'customer-logout' => 'Log out' ) );

		$this->assertSame( 'Payment methods', $items['payment-methods'] );
		$this->assertSame( 'customer-logout', array_key_last( $items ) );
	}

	public function test_the_cli_command_is_gone(): void {
		$this->assertFalse( class_exists( 'BeltoftServices\\Cli\\CardCommand' ) );
		$this->assertFalse( method_exists( Card::class, 'relink' ) );
	}

	// ------------------------------------------------------------------ an account that still carries the retired provider.

	public function test_an_old_account_moves_to_the_card_when_a_card_is_added(): void {
		$user    = $this->new_user();
		add_filter( 'beltoft_services_card_token_meta', static fn( $meta ) => $meta + array( '_wc_stripe_customer' => 'cus_1' ) );
		$account = $this->legacy_account( $user );
		$this->assertFalse( Account::chargeable( $this->fresh( $account ) ) );

		$this->add_card( $user, 'pm_g', '2099', 'visa', 'stripe_googlepay' );

		$account = $this->fresh( $account );
		$this->assertSame( 'stripe_googlepay', $account->get_payment_method() );
		$this->assertSame( 'pm_g', $account->get_meta( '_payment_method_token' ) );
		$this->assertSame( '', $account->get_meta( '_mollie_customer_id' ) );
		$this->assertSame( '', $account->get_meta( '_mollie_mandate_id' ) );
		$this->assertFalse( $account->is_manual() );
		$this->assertTrue( Account::chargeable( $account ) );
	}

	public function test_an_old_account_moves_to_the_card_when_one_is_made_the_default(): void {
		add_filter( 'beltoft_services_card_token_meta', static fn( $meta ) => $meta + array( '_wc_stripe_customer' => 'cus_1' ) );
		$user = $this->new_user();
		wp_set_current_user( 0 );
		$first  = $this->token( $user, 'pm_1' );
		$second = $this->token( $user, 'pm_2', '2099', 'visa', 'stripe_googlepay', '1013' );
		$account = $this->legacy_account( $user );

		wp_set_current_user( $user );
		\WC_Payment_Tokens::set_users_default( $user, $second->get_id() );

		$account = $this->fresh( $account );
		$this->assertSame( 'pm_2', $account->get_meta( '_payment_method_token' ) );
		$this->assertSame( 'stripe_googlepay', $account->get_payment_method() );
		$this->assertSame( '', $account->get_meta( '_mollie_mandate_id' ) );
		$this->assertTrue( Account::chargeable( $account ) );
	}

	// ------------------------------------------------------------------ Subscriptions' own handling.

	public function test_a_new_default_card_is_followed_before_subscriptions_asks_about_the_others(): void {
		$this->assertLessThan( 10, has_action( 'woocommerce_payment_token_set_default', array( Card::class, 'token_event' ) ) );
		$this->assertGreaterThan( 11, has_action( 'woocommerce_payment_token_deleted', array( Card::class, 'token_deleted' ) ) );
	}

	public function test_a_card_only_the_usage_account_uses_can_be_deleted(): void {
		$user  = $this->new_user();
		$token = $this->add_card( $user );

		$this->assertTrue( Card::allow_deletion( false, $token ) );
		$this->assertTrue( apply_filters( 'wc_subscriptions_allow_subscription_token_deletion', false, $token ) );
	}

	public function test_a_card_another_subscription_uses_keeps_the_subscriptions_rule(): void {
		$user  = $this->new_user();
		$token = $this->add_card( $user );
		$other = wcs_create_subscription( array( 'customer_id' => $user, 'status' => 'pending', 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$other->set_payment_method( WC()->payment_gateways()->payment_gateways()['stripe_cc'] );
		$other->update_meta_data( '_payment_method_token', $token->get_token() );
		$other->set_requires_manual_renewal( false );
		$other->save();

		$this->assertFalse( Card::allow_deletion( false, $token ) );
	}
}

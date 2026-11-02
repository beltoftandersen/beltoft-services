<?php
/**
 * Saved card: add or change it on Mollie's card page (a faked Mollie, never the network).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Card\Card;
use BeltoftServices\Card\Mollie;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Cart;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Card tests.
 */
class CardTest extends UsageTestCase {

	public function tear_down(): void {
		unset( WC()->payment_gateways()->payment_gateways[ Card::GATEWAY ] );
		parent::tear_down();
	}

	/**
	 * What the fake Mollie holds.
	 *
	 * @var array<string, mixed>
	 */
	private $mollie = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'mollie-payments-for-woocommerce_test_mode_enabled', 'yes' );
		update_option( 'mollie-payments-for-woocommerce_test_api_key', 'test_' . str_repeat( 'a', 30 ) );
		update_option( 'woocommerce_currency', 'EUR' );
		WC()->payment_gateways()->payment_gateways[ Card::GATEWAY ] = new UsageCardGateway( Card::GATEWAY );
		foreach ( array( 'tr_new1', 'tr_new2', 'tr_other1', 'tr_plain1' ) as $id ) {
			delete_option( Card::CLAIM . md5( $id ) );
		}
		delete_transient( 'beltoft_services_card_' . substr( md5( 'cst_1|mdt_old' ), 0, 24 ) );
		delete_transient( 'beltoft_services_card_' . substr( md5( 'cst_1|mdt_new' ), 0, 24 ) );
		delete_transient( 'beltoft_services_card_' . substr( md5( 'cst_new1|mdt_new' ), 0, 24 ) );

		$this->mollie = array(
			'mandates' => array(
				'mdt_old' => array( 'id' => 'mdt_old', 'status' => 'valid', 'method' => 'creditcard', 'details' => array( 'cardLabel' => 'Visa', 'cardNumber' => '9996', 'cardExpiryDate' => '2099-08-31' ) ),
				'mdt_new' => array( 'id' => 'mdt_new', 'status' => 'valid', 'method' => 'creditcard', 'details' => array( 'cardLabel' => 'Mastercard', 'cardNumber' => '4444', 'cardExpiryDate' => '2099-12-31' ) ),
			),
			'payments'  => array(),
			'revoked'   => array(),
			'customers' => array( 'cst_1' ),
			'created'   => array(),
		);

		$this->respond_with( fn( string $url, array $args ) => $this->fake_mollie( $url, $args ) );
	}

	/**
	 * The fake Mollie API.
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function fake_mollie( string $url, array $args ): array {
		$path   = substr( $url, strlen( Mollie::API ) );
		$method = $args['method'] ?? 'GET';
		$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
		$out    = array();
		$code   = 200;

		if ( 'POST' === $method && '/payments' === $path ) {
			$id                             = 'tr_new' . ( count( $this->mollie['payments'] ) + 1 );
			$this->mollie['payments'][ $id ] = $body + array( 'id' => $id, 'status' => 'open', '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/test-mode?token=' . $id ) ) );
			$out                            = $this->mollie['payments'][ $id ];
			$code                           = 201;
		} elseif ( 'GET' === $method && preg_match( '#^/payments/(tr_\w+)$#', $path, $m ) ) {
			isset( $this->mollie['payments'][ $m[1] ] ) ? $out = $this->mollie['payments'][ $m[1] ] : $code = 404;
		} elseif ( 'POST' === $method && '/customers' === $path ) {
			$id                             = 'cst_new' . ( count( $this->mollie['created'] ) + 1 );
			$this->mollie['customers'][]    = $id;
			$this->mollie['created'][ $id ] = $body;
			$out                            = array( 'id' => $id ) + $body;
			$code                           = 201;
		} elseif ( 'GET' === $method && preg_match( '#^/customers/(cst_\w+)$#', $path, $m ) ) {
			in_array( $m[1], $this->mollie['customers'], true ) ? $out = array( 'id' => $m[1] ) : $code = 404;
		} elseif ( 'GET' === $method && preg_match( '#^/customers/cst_\w+/mandates/(mdt_\w+)$#', $path, $m ) ) {
			isset( $this->mollie['mandates'][ $m[1] ] ) ? $out = $this->mollie['mandates'][ $m[1] ] : $code = 404;
		} elseif ( 'GET' === $method && preg_match( '#^/customers/cst_\w+/mandates$#', $path ) ) {
			$out = array( '_embedded' => array( 'mandates' => array_values( $this->mollie['mandates'] ) ) );
		} elseif ( 'DELETE' === $method && preg_match( '#^/customers/cst_\w+/mandates/(mdt_\w+)$#', $path, $m ) ) {
			$this->mollie['revoked'][] = $m[1];
			$code                      = 204;
		} else {
			$code = 404;
		}

		return array(
			'headers'  => array(),
			'body'     => $out ? wp_json_encode( $out ) : '',
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * An active account with a Mollie customer and the old mandate.
	 *
	 * @param int    $user   Customer.
	 * @param string $status Status.
	 */
	private function card_account( int $user, string $status = 'active' ): \WC_Subscription {
		$sub = $this->usage_account( $user, $status );
		$sub->update_meta_data( '_mollie_customer_id', 'cst_1' );
		$sub->update_meta_data( '_mollie_mandate_id', 'mdt_old' );
		$sub->save();

		return $sub;
	}

	private function mollie_calls( string $method, string $needle = '' ): int {
		$n = 0;
		foreach ( $this->http_calls() as $call ) {
			if ( ( $call['args']['method'] ?? 'GET' ) === $method && str_contains( $call['url'], Mollie::API . $needle ) ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * Marks the payment Mollie says is paid, with the mandate.
	 *
	 * @param string $id        Payment id.
	 * @param string $mandate   Mandate id.
	 * @param string $status    Status.
	 */
	private function pay( string $id, string $mandate = 'mdt_new', string $status = 'paid' ): void {
		$this->mollie['payments'][ $id ]['status']    = $status;
		$this->mollie['payments'][ $id ]['mandateId'] = $mandate;
	}

	// ------------------------------------------------------------------ the saved card.

	public function test_the_card_reads_visa_ending_and_expiry_and_is_cached(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		$this->mollie['mandates']['mdt_old']['details'] = array( 'cardLabel' => 'Visa', 'cardNumber' => '4242', 'cardExpiryDate' => '2099-08-31' );

		$info = Card::details( $sub );
		$this->assertSame( 'Visa ending 4242, expires 08/99', Card::describe( $info ) );
		$this->assertTrue( Card::usable( $info ) );

		Card::details( $sub );
		$this->assertSame( 1, $this->mollie_calls( 'GET', '/customers/' ), 'The second read comes from the cache.' );
	}

	public function test_an_invalid_or_expired_mandate_is_not_usable_and_the_page_says_so(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		$this->mollie['mandates']['mdt_old']['status'] = 'invalid';
		$this->assertFalse( Card::usable( Card::details( $sub ) ) );

		Card::forget( 'cst_1', 'mdt_old' );
		$this->mollie['mandates']['mdt_old']['status']                    = 'valid';
		$this->mollie['mandates']['mdt_old']['details']['cardExpiryDate'] = '2020-01-31';
		$info                                                              = Card::details( $sub );
		$this->assertTrue( $info['expired'] );

		wp_set_current_user( $user );
		$html = Card::html();
		$this->assertStringContainsString( 'Your card has expired. Change it to keep Pay as you go services running.', $html );
		$this->assertStringContainsString( 'Change card', $html );
		$this->assertStringNotContainsString( 'Add a card', $html );
	}

	public function test_the_page_says_add_a_card_without_one_and_change_card_with_one(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$html = Card::html();
		$this->assertStringContainsString( 'Add a card', $html );
		$this->assertStringNotContainsString( 'Change card', $html );
		$this->assertStringContainsString( 'Nothing is charged. You enter your card on the payment provider&#039;s secure form.', $html );
		$this->assertStringNotContainsString( 'billing', $html );
		$this->assertDoesNotMatchRegularExpression( '/\x{2014}|\x{00B7}/u', $html, 'No em dash or middle dot.' );

		$this->card_account( $user );
		$html = Card::html();
		$this->assertStringContainsString( 'Visa ending 9996, expires 08/99', $html );
		$this->assertStringContainsString( 'Change card', $html );
		$this->assertStringNotContainsString( 'Add a card', $html );
	}

	public function test_without_a_mollie_key_there_is_no_button(): void {
		update_option( 'mollie-payments-for-woocommerce_test_api_key', '' );
		wp_set_current_user( $this->new_user() );
		$this->assertStringNotContainsString( '<button', Card::html() );
	}

	public function test_has_valid_mandate(): void {
		$user = $this->new_user();
		$this->assertFalse( Account::has_valid_mandate( $user ), 'No account.' );

		$this->card_account( $user );
		$this->assertTrue( Account::has_valid_mandate( $user ) );

		Card::forget( 'cst_1', 'mdt_old' );
		$this->mollie['mandates']['mdt_old']['status'] = 'invalid';
		$this->assertFalse( Account::has_valid_mandate( $user ) );

		$other = $this->new_user();
		$hold  = $this->card_account( $other, 'on-hold' );
		$this->assertSame( 'on-hold', $hold->get_status() );
		$this->assertFalse( Account::has_valid_mandate( $other ), 'An account on hold is not usable.' );
	}

	public function test_a_server_order_with_a_valid_account_needs_no_payment(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$this->card_account( $user );
		wp_set_current_user( $user );

		WC()->cart->add_to_cart( $v['u-None']->get_parent_id(), 1, $v['u-None']->get_id(), array( 'attribute_billing' => 'Pay as you go', 'attribute_backups' => 'None' ) );
		WC()->cart->calculate_totals();

		$this->assertFalse( WC()->cart->needs_payment(), 'No Mollie page: a 0 order.' );
		$this->assertSame( array(), Cart::problems( $user, '', Cart::usage_units() ) );
		$this->assertCount( 1, WC()->cart->get_cart(), 'No second account line.' );
	}

	public function test_a_card_never_goes_through_the_cart(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		Card::start( $user );
		$this->assertCount( 0, WC()->cart->get_cart() );
		$this->assertFalse( \BeltoftServices\BillingRequirements::cart_needs_billing() );
	}

	// ------------------------------------------------------------------ the button.

	public function test_the_button_makes_a_mollie_customer_and_a_zero_first_payment(): void {
		$user = $this->new_user();
		wp_update_user( array( 'ID' => $user, 'first_name' => 'Test', 'last_name' => 'Customer' ) );

		$to = Card::start( $user );

		$this->assertStringStartsWith( 'https://www.mollie.com/checkout/', $to );
		$this->assertSame( 1, $this->mollie_calls( 'POST', '/customers' ) );
		$this->assertSame( 'Test Customer', $this->mollie['created']['cst_new1']['name'] );
		$this->assertSame( get_userdata( $user )->user_email, $this->mollie['created']['cst_new1']['email'] );
		$this->assertSame( 'cst_new1', get_user_meta( $user, 'mollie_customer_id', true ), 'The key the Mollie plugin reuses.' );

		$payment = $this->mollie['payments']['tr_new1'];
		$this->assertSame( 'first', $payment['sequenceType'] );
		$this->assertSame( 'cst_new1', $payment['customerId'] );
		$this->assertSame( 'creditcard', $payment['method'] );
		$this->assertSame( '0.00', $payment['amount']['value'] );
		$this->assertSame( 'EUR', $payment['amount']['currency'] );
		$this->assertStringContainsString( Card::QUERY . '=return', $payment['redirectUrl'] );
		$this->assertStringStartsWith( Card::url(), $payment['redirectUrl'] );
		$this->assertStringContainsString( '/beltoft-services/v1/card/webhook', $payment['webhookUrl'] );
		$this->assertSame( $user, $payment['metadata']['beltoft_card_user'] );
		$this->assertSame( array( 'tr_new1' ), get_user_meta( $user, Card::META_PENDING, true ) );
	}

	public function test_an_existing_mollie_customer_is_reused(): void {
		$user = $this->new_user();
		update_user_meta( $user, 'mollie_customer_id', 'cst_1' );
		Card::start( $user );
		$this->assertSame( 0, $this->mollie_calls( 'POST', '/customers' ) );
		$this->assertSame( 'cst_1', $this->mollie['payments']['tr_new1']['customerId'] );

		// A customer the key does not know is replaced.
		$gone = $this->new_user();
		update_user_meta( $gone, 'mollie_customer_id', 'cst_gone' );
		Card::start( $gone );
		$this->assertSame( 'cst_new1', $this->mollie['payments']['tr_new2']['customerId'] );
		$this->assertSame( 'cst_new1', get_user_meta( $gone, 'mollie_customer_id', true ) );
	}

	public function test_changing_a_card_uses_the_customer_of_the_account(): void {
		$user = $this->new_user();
		$this->card_account( $user );

		$this->assertStringStartsWith( 'https://www.mollie.com/checkout/', Card::start( $user ) );
		$this->assertSame( 0, $this->mollie_calls( 'POST', '/customers' ) );
		$this->assertSame( 'cst_1', $this->mollie['payments']['tr_new1']['customerId'] );
	}

	public function test_a_failing_mollie_sends_the_customer_back_with_a_message(): void {
		$user = $this->new_user();
		$this->respond_with( fn() => new \WP_Error( 'down', 'down' ) );
		$this->assertStringContainsString( Card::QUERY . '=failed', Card::start( $user ) );
		$this->assertEmpty( get_user_meta( $user, Card::META_PENDING, true ) );

		update_option( 'mollie-payments-for-woocommerce_test_api_key', '' );
		$this->assertStringContainsString( Card::QUERY . '=failed', Card::start( $user ) );
	}

	public function test_the_button_is_rate_limited(): void {
		$user = $this->new_user();
		for ( $i = 0; $i < Card::START_LIMIT; $i++ ) {
			$this->assertStringStartsWith( 'https://www.mollie.com/', Card::start( $user ) );
		}
		$this->assertStringContainsString( Card::QUERY . '=limit', Card::start( $user ) );
		$this->assertSame( Card::START_LIMIT, $this->mollie_calls( 'POST', '/payments' ) );
	}

	public function test_only_a_logged_in_owner_with_the_nonce_can_start_anything(): void {
		$user  = $this->new_user();
		$calls = count( $this->http_calls() );

		$this->assertNull( Card::dispatch( $user, array() ), 'Not our post.' );
		$this->assertSame( wc_get_page_permalink( 'myaccount' ), Card::dispatch( 0, array( Card::ACTION => '1' ) ), 'A visitor is sent to log in.' );
		$this->assertSame( Card::url(), Card::dispatch( $user, array( Card::ACTION => '1', '_wpnonce' => 'bad' ) ) );
		$this->assertSame( Card::url(), Card::dispatch( $user, array( Card::ACTION => '1' ) ) );
		$this->assertCount( $calls, $this->http_calls(), 'No Mollie call without the nonce.' );

		wp_set_current_user( $user );
		$nonce = wp_create_nonce( Card::NONCE );
		$this->assertStringStartsWith( 'https://www.mollie.com/', Card::dispatch( $user, array( Card::ACTION => '1', '_wpnonce' => $nonce ) ) );
	}

	public function test_a_pending_mandate_left_by_an_abandoned_card_page_is_revoked(): void {
		$user = $this->new_user();
		$this->card_account( $user );
		$this->mollie['mandates']['mdt_stale'] = array( 'id' => 'mdt_stale', 'status' => 'pending', 'method' => 'creditcard' );

		Card::start( $user );

		$this->assertSame( array( 'mdt_stale' ), $this->mollie['revoked'], 'Only the pending one, never the card in use.' );
	}

	// ------------------------------------------------------------------ a card for a customer with an account.

	public function test_a_paid_payment_swaps_the_mandate_and_revokes_the_old_one_once(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		Card::start( $user );

		$this->assertSame( 'waiting', Card::apply_payment( 'tr_new1' ), 'Not paid yet: nothing changes.' );
		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( array(), $this->mollie['revoked'] );

		$this->pay( 'tr_new1' );

		$this->assertSame( 'saved', Card::apply_payment( 'tr_new1' ) );
		$fresh = wcs_get_subscription( $sub->get_id() );
		$this->assertSame( 'mdt_new', $fresh->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( 'cst_1', $fresh->get_meta( '_mollie_customer_id' ) );
		$this->assertSame( 'mollie_wc_gateway_creditcard', $fresh->get_payment_method() );
		$this->assertSame( array( 'mdt_old' ), $this->mollie['revoked'] );
		$this->assertSame( array(), get_user_meta( $user, Card::META_PENDING, true ) );
		$this->assertSame( 'saved', get_user_meta( $user, Card::META_LAST, true ) );

		// Again, as Mollie does when it repeats a webhook.
		$this->assertSame( 'done', Card::apply_payment( 'tr_new1' ) );
		$this->assertSame( array( 'mdt_old' ), $this->mollie['revoked'], 'Revoked once.' );
		$this->assertSame( 'Mastercard ending 4444, expires 12/99', Card::describe( Card::details( wcs_get_subscription( $sub->get_id() ) ) ), 'The page shows the new card.' );

		$notes = wc_get_order_notes( array( 'order_id' => $sub->get_id() ) );
		$this->assertStringContainsString( 'mdt_new replaces mdt_old', implode( ' ', wp_list_pluck( $notes, 'content' ) ), 'The replaced mandate id is kept in the notes.' );
	}

	public function test_an_authorized_payment_counts_too(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		Card::start( $user );
		$this->pay( 'tr_new1', 'mdt_new', 'authorized' );

		$this->assertSame( 'saved', Card::apply_payment( 'tr_new1' ) );
		$this->assertSame( 'mdt_new', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
	}

	public function test_a_stale_read_cannot_apply_a_payment_twice(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		Card::start( $user );
		$this->pay( 'tr_new1' );

		// Another request already applied it, and this one still sees the old state.
		add_option( Card::CLAIM . md5( 'tr_new1' ), time(), '', false );

		$this->assertSame( 'done', Card::apply_payment( 'tr_new1' ) );
		$this->assertSame( array(), $this->mollie['revoked'] );
		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
	}

	// ------------------------------------------------------------------ a card for a customer without one.

	public function test_a_card_without_an_account_makes_the_account_with_the_card_on_it(): void {
		$user = $this->new_user();
		$this->assertNull( Account::for_user( $user ) );
		Card::start( $user );
		$this->pay( 'tr_new1' );

		$this->assertSame( 'saved', Card::apply_payment( 'tr_new1' ) );

		$account = Account::for_user( $user, Account::ESTABLISHED );
		$this->assertInstanceOf( \WC_Subscription::class, $account );
		$this->assertTrue( Account::is_account( $account ) );
		$this->assertSame( 'active', $account->get_status() );
		$this->assertSame( 'cst_new1', $account->get_meta( '_mollie_customer_id' ) );
		$this->assertSame( 'mdt_new', $account->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( 'mollie_wc_gateway_creditcard', $account->get_payment_method() );
		$this->assertFalse( $account->is_manual(), 'Charged by itself.' );
		$this->assertTrue( Account::chargeable( $account ) );
		$this->assertTrue( Account::has_valid_mandate( $user ) );
		$this->assertGreaterThan( time(), $account->get_time( 'next_payment' ), 'It renews next month.' );
		$this->assertSame( get_userdata( $user )->user_email, $account->get_billing_email() );
		$items = $account->get_items();
		$this->assertSame( Account::product_id(), (int) reset( $items )->get_product_id() );
		$this->assertEquals( 0, $account->get_total() );
		$this->assertSame( array(), $this->mollie['revoked'] );
		$this->assertSame( 'cst_new1', get_user_meta( $user, 'mollie_customer_id', true ) );

		// A second application (webhook again, then the return) makes no second account.
		$this->assertSame( 'done', Card::apply_payment( 'tr_new1' ) );
		$this->assertCount( 1, wcs_get_subscriptions( array( 'customer_id' => $user, 'subscription_status' => 'any' ) ) );
	}

	public function test_the_new_account_takes_the_billing_details_the_customer_has(): void {
		$user = $this->new_user();
		update_user_meta( $user, 'billing_first_name', 'Anna' );
		update_user_meta( $user, 'billing_country', 'DK' );
		update_user_meta( $user, 'billing_phone', '+4511223344' );
		Card::start( $user );
		$this->pay( 'tr_new1' );
		Card::apply_payment( 'tr_new1' );

		$account = Account::for_user( $user, Account::ESTABLISHED );
		$this->assertSame( 'Anna', $account->get_billing_first_name() );
		$this->assertSame( 'DK', $account->get_billing_country() );
		$this->assertSame( '+4511223344', $account->get_billing_phone() );
	}

	public function test_a_server_order_after_a_card_has_an_account_to_attach_to(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		Card::start( $user );
		$this->pay( 'tr_new1' );
		Card::apply_payment( 'tr_new1' );
		wp_set_current_user( $user );

		WC()->cart->add_to_cart( $v['u-None']->get_parent_id(), 1, $v['u-None']->get_id(), array( 'attribute_billing' => 'Pay as you go', 'attribute_backups' => 'None' ) );
		Cart::sync_account_line();

		$this->assertCount( 1, WC()->cart->get_cart(), 'The account exists: no account line is added.' );
		$this->assertSame( array(), Cart::problems( $user, '', Cart::usage_units() ) );
	}

	// ------------------------------------------------------------------ what Mollie says.

	public function test_a_payment_that_is_not_ours_is_ignored(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );

		// A paid first payment of the right customer that this site never started.
		$this->mollie['payments']['tr_other1'] = array( 'id' => 'tr_other1', 'status' => 'paid', 'sequenceType' => 'first', 'customerId' => 'cst_1', 'mandateId' => 'mdt_new', 'metadata' => array( 'beltoft_card_user' => $user ) );
		$this->assertSame( 'ignored', Card::apply_payment( 'tr_other1' ) );

		// One of ours, but for another Mollie customer.
		Card::start( $user );
		$this->pay( 'tr_new1' );
		$this->mollie['payments']['tr_new1']['customerId'] = 'cst_other';
		$this->assertSame( 'ignored', Card::apply_payment( 'tr_new1' ) );

		// A payment with no user, or an unknown one.
		$this->mollie['payments']['tr_plain1'] = array( 'id' => 'tr_plain1', 'status' => 'paid', 'metadata' => array() );
		$this->assertSame( 'ignored', Card::apply_payment( 'tr_plain1' ) );
		$this->assertSame( 'ignored', Card::apply_payment( 'tr_missing1' ) );

		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( array(), $this->mollie['revoked'] );
	}

	public function test_another_customers_payment_cannot_put_a_card_on_this_account(): void {
		$mine  = $this->new_user();
		$other = $this->new_user();
		$sub   = $this->card_account( $mine );
		Card::start( $other );
		$this->pay( 'tr_new1', 'mdt_new' );

		// The payment is the other customer's: their (new) customer, their account.
		$this->assertSame( 'saved', Card::apply_payment( 'tr_new1' ) );
		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( 'mdt_new', Account::for_user( $other, Account::ESTABLISHED )->get_meta( '_mollie_mandate_id' ) );
	}

	public function test_a_cancelled_or_failed_payment_changes_nothing_and_leaves_the_pending_list(): void {
		foreach ( array( 'canceled' => 'cancelled', 'failed' => 'failed', 'expired' => 'failed' ) as $status => $result ) {
			$user = $this->new_user();
			$sub  = $this->card_account( $user );
			Card::start( $user );
			$id = array_key_last( $this->mollie['payments'] );
			$this->mollie['payments'][ $id ]['status'] = $status;

			$this->assertSame( $result, Card::apply_payment( $id ) );
			$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
			$this->assertSame( array(), get_user_meta( $user, Card::META_PENDING, true ) );
			$this->assertSame( $result, get_user_meta( $user, Card::META_LAST, true ) );
			$this->assertSame( array(), $this->mollie['revoked'] );
		}
	}

	public function test_a_paid_payment_without_a_mandate_waits(): void {
		$user = $this->new_user();
		Card::start( $user );
		$this->mollie['payments']['tr_new1']['status'] = 'paid';
		$this->assertSame( 'waiting', Card::apply_payment( 'tr_new1' ) );
		$this->assertNull( Account::for_user( $user ) );
	}

	public function test_the_webhook_fetches_the_payment_and_never_believes_the_body(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		Card::start( $user );
		do_action( 'rest_api_init' );

		// The body claims everything; Mollie says open.
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/card/webhook' );
		$request->set_body_params( array( 'id' => 'tr_new1', 'status' => 'paid', 'mandateId' => 'mdt_evil' ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );

		$this->pay( 'tr_new1' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'saved', $response->get_data()['result'] );
		$this->assertSame( 'mdt_new', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );

		$again = rest_do_request( $request );
		$this->assertSame( 'done', $again->get_data()['result'] );
		$this->assertSame( array( 'mdt_old' ), $this->mollie['revoked'] );

		$bad = new \WP_REST_Request( 'POST', '/beltoft-services/v1/card/webhook' );
		$bad->set_body_params( array( 'id' => '../../x' ) );
		$this->assertSame( 'ignored', rest_do_request( $bad )->get_data()['result'] );
	}

	public function test_a_mollie_outage_on_the_webhook_asks_for_a_retry(): void {
		do_action( 'rest_api_init' );
		$this->respond_with( fn() => new \WP_Error( 'down', 'down' ) );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/card/webhook' );
		$request->set_body_params( array( 'id' => 'tr_new1' ) );
		$this->assertSame( 503, rest_do_request( $request )->get_status() );
	}

	// ------------------------------------------------------------------ the way back.

	public function test_the_page_applies_a_paid_payment_when_the_webhook_is_late(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		Card::start( $user );
		$this->pay( 'tr_new1' );

		$_GET[ Card::QUERY ] = 'return';
		$html                = Card::html();
		unset( $_GET[ Card::QUERY ] );

		$this->assertStringContainsString( 'Card saved.', $html );
		$this->assertStringContainsString( 'Mastercard ending 4444', $html );
		$this->assertStringContainsString( 'Change card', $html );
		$this->assertSame( 'mdt_new', Account::for_user( $user, Account::ESTABLISHED )->get_meta( '_mollie_mandate_id' ) );
	}

	public function test_the_page_shows_saved_when_the_webhook_was_first(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		Card::start( $user );
		$this->pay( 'tr_new1' );
		Card::apply_payment( 'tr_new1' );

		$_GET[ Card::QUERY ] = 'return';
		$html                = Card::html();
		unset( $_GET[ Card::QUERY ] );

		$this->assertStringContainsString( 'Card saved.', $html );
	}

	public function test_the_page_says_cancelled_failed_and_waiting(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$_GET[ Card::QUERY ] = 'return';

		Card::start( $user );
		$this->mollie['payments']['tr_new1']['status'] = 'canceled';
		$html = Card::html();
		$this->assertStringContainsString( 'Card not saved. You cancelled on the card page. You can try again.', $html );
		$this->assertStringContainsString( 'Add a card', $html );

		Card::start( $user );
		$this->mollie['payments']['tr_new2']['status'] = 'failed';
		$this->assertStringContainsString( 'The card was not saved. The payment provider could not confirm it. You can try again.', Card::html() );

		Card::start( $user );
		$this->assertStringContainsString( 'We are waiting for the payment provider to confirm your card.', Card::html() );

		unset( $_GET[ Card::QUERY ] );
	}

	public function test_an_abandoned_card_page_does_not_hide_the_saved_card(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		Card::start( $user );
		Card::start( $user );
		$this->pay( 'tr_new2' );
		$_GET[ Card::QUERY ] = 'return';

		$this->assertStringContainsString( 'Card saved.', Card::html() );

		// The first one expires later: the notice stays saved, the card stays.
		$this->mollie['payments']['tr_new1']['status'] = 'expired';
		$this->assertStringContainsString( 'Card saved.', Card::html() );
		$this->assertSame( array(), get_user_meta( $user, Card::META_PENDING, true ) );
		unset( $_GET[ Card::QUERY ] );
	}

	public function test_a_result_written_by_the_webhook_meanwhile_is_not_hidden_by_the_meta_cache(): void {
		global $wpdb;
		$user = $this->new_user();
		wp_set_current_user( $user );
		Card::start( $user );
		get_user_meta( $user ); // This request has read the customer's meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Another request writes it.
		$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $user, 'meta_key' => Card::META_LAST, 'meta_value' => 'saved' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$this->mollie['payments']['tr_new1']['status'] = 'open';

		$_GET[ Card::QUERY ] = 'return';
		$html                = Card::html();
		unset( $_GET[ Card::QUERY ] );

		$this->assertStringContainsString( 'Card saved.', $html );
		$this->assertStringNotContainsString( 'waiting for Mollie', $html );
	}

	public function test_the_page_messages_for_a_limit_and_a_failed_start(): void {
		wp_set_current_user( $this->new_user() );
		$_GET[ Card::QUERY ] = 'limit';
		$this->assertStringContainsString( 'Too many attempts.', Card::html() );
		$_GET[ Card::QUERY ] = 'failed';
		$this->assertStringContainsString( 'The card page could not be opened.', Card::html() );
		unset( $_GET[ Card::QUERY ] );
		$this->assertStringNotContainsString( 'beltoft-services-card__notice', Card::html() );
	}

	// ------------------------------------------------------------------ renewals and old orders.

	public function test_the_mollie_plugin_renewal_reads_the_mandate_from_the_subscription(): void {
		$base = WP_PLUGIN_DIR . '/mollie-payments-for-woocommerce/src/';
		$base = is_dir( $base ) ? $base : '/var/www/wordpress/staging.beltoft.net/wp-content/plugins/mollie-payments-for-woocommerce/src/';
		$file = $base . 'Subscription/MollieSubscriptionGatewayHandler.php';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'The Mollie plugin is not installed here.' );
		}

		$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file.
		// The renewal takes the mandate from the subscription, and only uses it when Mollie says it is valid.
		$this->assertStringContainsString( "\$mandateId = \$subscription->get_meta('_mollie_mandate_id');", $code );
		$this->assertStringContainsString( 'usePreviousMandate(', $code );
		$this->assertStringContainsString( "\$mandate->status === 'valid'", $code );
		// The customer comes from the renewal order, which WooCommerce Subscriptions copies from the subscription.
		$this->assertStringContainsString( "\$order->get_meta('_mollie_customer_id', \\true)", $code );
		$this->assertStringContainsString( "wcs_get_subscriptions_for_renewal_order(\$renewal_order)", $code );
	}

	public function test_the_renewal_order_of_a_card_account_carries_the_customer(): void {
		$user = $this->new_user();
		Card::start( $user );
		$this->pay( 'tr_new1' );
		Card::apply_payment( 'tr_new1' );
		$account = Account::for_user( $user, Account::ESTABLISHED );

		$renewal = wcs_create_renewal_order( $account );
		$this->assertInstanceOf( \WC_Order::class, $renewal );
		$this->assertSame( 'cst_new1', $renewal->get_meta( '_mollie_customer_id' ), 'The Mollie plugin reads the customer from the renewal order.' );
		$this->assertSame( 'mollie_wc_gateway_creditcard', wcs_get_subscription( $account->get_id() )->get_payment_method(), 'WooCommerce Subscriptions sets it on the renewal when it is paid, from the subscription.' );
		$this->assertSame( 'mdt_new', wcs_get_subscription( $account->get_id() )->get_meta( '_mollie_mandate_id' ) );
	}

	public function test_cleanup_cancels_old_pending_accounts_of_the_old_card_checkout_only(): void {
		$user = $this->new_user();
		$mk   = function ( string $status, int $hours, bool $setup ) use ( $user ): \WC_Subscription {
			$sub = $this->usage_account( $user, $status );
			$sub->update_meta_data( Card::META_SETUP, $setup ? 'yes' : 'no' );
			$sub->set_date_created( time() - $hours * HOUR_IN_SECONDS );
			$sub->save();
			return $sub;
		};
		$old    = $mk( 'pending', 30, true );
		$fresh  = $mk( 'pending', 2, true );
		$server = $mk( 'pending', 30, false );
		$active = $mk( 'active', 30, true );

		$this->assertSame( 1, Card::cleanup() );
		$this->assertSame( 'cancelled', wcs_get_subscription( $old->get_id() )->get_status() );
		$this->assertSame( 'pending', wcs_get_subscription( $fresh->get_id() )->get_status() );
		$this->assertSame( 'pending', wcs_get_subscription( $server->get_id() )->get_status() );
		$this->assertSame( 'active', wcs_get_subscription( $active->get_id() )->get_status() );
	}
}

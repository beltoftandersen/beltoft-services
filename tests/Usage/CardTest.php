<?php
/**
 * Saved card: add one, show it, replace it (a faked Mollie, never the network).
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
		foreach ( array( 'tr_new1', 'tr_new2', 'tr_other1' ) as $id ) {
			delete_option( Card::CLAIM . md5( $id ) );
		}
		delete_transient( 'beltoft_services_card_' . substr( md5( 'cst_1|mdt_old' ), 0, 24 ) );
		delete_transient( 'beltoft_services_card_' . substr( md5( 'cst_1|mdt_new' ), 0, 24 ) );

		$this->mollie = array(
			'mandates' => array(
				'mdt_old' => array( 'id' => 'mdt_old', 'status' => 'valid', 'method' => 'creditcard', 'details' => array( 'cardLabel' => 'Visa', 'cardNumber' => '9996', 'cardExpiryDate' => '2099-08-31' ) ),
				'mdt_new' => array( 'id' => 'mdt_new', 'status' => 'valid', 'method' => 'creditcard', 'details' => array( 'cardLabel' => 'Mastercard', 'cardNumber' => '4444', 'cardExpiryDate' => '2099-12-31' ) ),
			),
			'payments' => array(),
			'revoked'  => array(),
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
		} elseif ( 'GET' === $method && preg_match( '#^/customers/cst_1/mandates/(mdt_\w+)$#', $path, $m ) ) {
			isset( $this->mollie['mandates'][ $m[1] ] ) ? $out = $this->mollie['mandates'][ $m[1] ] : $code = 404;
		} elseif ( 'GET' === $method && '/customers/cst_1/mandates' === $path ) {
			$out = array( '_embedded' => array( 'mandates' => array_values( $this->mollie['mandates'] ) ) );
		} elseif ( 'DELETE' === $method && preg_match( '#^/customers/cst_1/mandates/(mdt_\w+)$#', $path, $m ) ) {
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

	private function line_count(): int {
		return count( WC()->cart->get_cart() );
	}

	// ------------------------------------------------------------------ the account-only cart.

	public function test_add_a_card_puts_only_the_account_line_in_the_cart_and_it_stays(): void {
		wp_set_current_user( $this->new_user() );

		$to = Card::start_add( get_current_user_id() );

		$this->assertSame( wc_get_checkout_url(), $to );
		$this->assertSame( 1, $this->line_count() );
		$this->assertTrue( Card::cart_has_setup() );
		$this->assertTrue( WC()->cart->needs_payment(), 'A 0 cart with the account line still needs a payment method.' );

		Cart::sync_account_line();
		do_action( 'woocommerce_check_cart_items' );
		$this->assertSame( 1, $this->line_count(), 'The cart check does not drop an account line made to save a card.' );
		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		$this->assertSame( array(), Cart::problems( get_current_user_id(), 'fakecard', Cart::usage_units() ), 'No service, so no usage rules apply.' );
	}

	public function test_one_account_per_customer_holds(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->card_account( $user );

		$this->assertSame( Card::url(), Card::start_add( $user ), 'A customer with an account is not sent to checkout.' );
		$this->assertSame( 0, $this->line_count() );

		// A line that was in the cart before the account existed goes at the cart check.
		WC()->cart->add_to_cart( Account::product_id(), 1, 0, array(), array( Card::CART_KEY => 1 ) );
		Cart::sync_account_line();
		$this->assertSame( 0, $this->line_count() );
	}

	public function test_a_cart_with_other_items_is_emptied_and_the_card_line_added(): void {
		$v = $this->usage_product();
		wp_set_current_user( $this->new_user() );
		WC()->cart->add_to_cart( $v['m-None']->get_parent_id(), 1, $v['m-None']->get_id(), array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'None' ) );
		$this->assertTrue( Card::cart_busy(), 'The button says it empties the cart.' );

		$this->assertStringNotContainsString( Card::QUERY . '=cart', Card::start_add( get_current_user_id() ) );
		$this->assertSame( 1, $this->line_count() );
		$this->assertTrue( Card::cart_has_setup() );
		$this->assertFalse( Card::cart_busy() );
	}

	public function test_an_empty_cart_is_not_busy(): void {
		wp_set_current_user( $this->new_user() );
		$this->assertFalse( Card::cart_busy() );
	}

	public function test_the_card_line_has_a_plain_name_and_needs_a_card_method(): void {
		wp_set_current_user( $this->new_user() );
		Card::start_add( get_current_user_id() );
		$item = array_values( WC()->cart->get_cart() )[0];

		$this->assertSame( 'Card for Pay as you go', Card::cart_name( 'Usage account', $item ) );

		$errors = new \WP_Error();
		Card::validate( array( 'payment_method' => '' ), $errors );
		$this->assertNotEmpty( $errors->get_error_codes() );

		$errors = new \WP_Error();
		Card::validate( array( 'payment_method' => 'fakecard' ), $errors );
		$this->assertEmpty( $errors->get_error_codes() );

		$gateways = array( 'fakecard' => 1, 'mollie_wc_gateway_creditcard' => 2, 'mollie_wc_gateway_ideal' => 3 );
		$this->assertSame( array( 'mollie_wc_gateway_creditcard' => 2 ), Card::gateways( $gateways ) );
		$this->assertSame( array( 'fakecard' => 1 ), Card::gateways( array( 'fakecard' => 1 ) ), 'Without a card method nothing is hidden.' );
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
		$this->assertStringContainsString( 'Your card has expired. Replace it to keep Pay as you go services running.', $html );
		$this->assertStringContainsString( 'Replace card', $html );
		$this->assertStringNotContainsString( 'Add a card', $html );
	}

	public function test_the_page_offers_add_without_an_account_and_replace_with_one(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$html = Card::html();
		$this->assertStringContainsString( 'Add a card', $html );
		$this->assertStringNotContainsString( 'Replace card', $html );
		$this->assertDoesNotMatchRegularExpression( '/\x{2014}|\x{00B7}/u', $html, 'No em dash or middle dot.' );

		$this->card_account( $user );
		$html = Card::html();
		$this->assertStringContainsString( 'Visa ending 9996, expires 08/99', $html );
		$this->assertStringContainsString( 'Replace card', $html );
		$this->assertStringNotContainsString( 'Add a card', $html );
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

		$this->assertFalse( Card::cart_has_setup() );
		$this->assertFalse( WC()->cart->needs_payment(), 'No Mollie page: a 0 order.' );
		$this->assertSame( array(), Cart::problems( $user, '', Cart::usage_units() ) );
		$this->assertSame( 1, $this->line_count(), 'No second account line.' );
	}

	// ------------------------------------------------------------------ replace.

	public function test_replace_creates_a_first_payment_for_the_same_customer(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );

		$to = Card::start_replace( $user );

		$this->assertStringStartsWith( 'https://www.mollie.com/checkout/', $to );
		$this->assertSame( 1, $this->mollie_calls( 'POST', '/payments' ) );
		$payment = $this->mollie['payments']['tr_new1'];
		$this->assertSame( 'first', $payment['sequenceType'] );
		$this->assertSame( 'cst_1', $payment['customerId'] );
		$this->assertSame( 'creditcard', $payment['method'] );
		$this->assertSame( '0.00', $payment['amount']['value'] );
		$this->assertSame( 'EUR', $payment['amount']['currency'] );
		$this->assertStringContainsString( Card::QUERY . '=replaced', $payment['redirectUrl'] );
		$this->assertStringContainsString( '/beltoft-services/v1/card/webhook', $payment['webhookUrl'] );
		$this->assertSame( array( 'tr_new1' ), wcs_get_subscription( $sub->get_id() )->get_meta( Card::META_PENDING ) );
	}

	public function test_replace_is_rate_limited_and_needs_an_account(): void {
		$user = $this->new_user();
		$this->card_account( $user );
		for ( $i = 0; $i < Card::REPLACE_LIMIT; $i++ ) {
			$this->assertStringStartsWith( 'https://www.mollie.com/', Card::start_replace( $user ) );
		}
		$this->assertStringContainsString( Card::QUERY . '=limit', Card::start_replace( $user ) );
		$this->assertSame( Card::REPLACE_LIMIT, $this->mollie_calls( 'POST', '/payments' ) );

		$stranger = $this->new_user();
		$calls    = count( $this->http_calls() );
		$this->assertSame( Card::url(), Card::start_replace( $stranger ), 'No account, no payment.' );
		$this->assertCount( $calls, $this->http_calls() );
	}

	public function test_only_a_logged_in_owner_with_the_nonce_can_start_anything(): void {
		$user = $this->new_user();
		$this->card_account( $user );
		$calls = count( $this->http_calls() );

		$this->assertNull( Card::dispatch( $user, array() ), 'Not our post.' );
		$this->assertSame( wc_get_page_permalink( 'myaccount' ), Card::dispatch( 0, array( Card::ACTION_REPLACE => '1' ) ), 'A visitor is sent to log in.' );
		$this->assertSame( Card::url(), Card::dispatch( $user, array( Card::ACTION_REPLACE => '1', '_wpnonce' => 'bad' ) ) );
		$this->assertSame( Card::url(), Card::dispatch( $user, array( Card::ACTION_REPLACE => '1' ) ) );
		$this->assertCount( $calls, $this->http_calls(), 'No Mollie call without the nonce.' );

		wp_set_current_user( $user );
		$nonce = wp_create_nonce( Card::NONCE );
		$this->assertStringStartsWith( 'https://www.mollie.com/', Card::dispatch( $user, array( Card::ACTION_REPLACE => '1', '_wpnonce' => $nonce ) ) );
	}

	public function test_a_paid_payment_swaps_the_mandate_and_revokes_the_old_one_once(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		Card::start_replace( $user );

		$this->assertSame( 'waiting', Card::apply_payment( 'tr_new1' ), 'Not paid yet: nothing changes.' );
		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( array(), $this->mollie['revoked'] );

		$this->mollie['payments']['tr_new1']['status']    = 'paid';
		$this->mollie['payments']['tr_new1']['mandateId'] = 'mdt_new';

		$this->assertSame( 'saved', Card::apply_payment( 'tr_new1' ) );
		$fresh = wcs_get_subscription( $sub->get_id() );
		$this->assertSame( 'mdt_new', $fresh->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( 'cst_1', $fresh->get_meta( '_mollie_customer_id' ) );
		$this->assertSame( array( 'mdt_old' ), $this->mollie['revoked'] );
		$this->assertSame( array(), $fresh->get_meta( Card::META_PENDING ) );

		// Again, as Mollie does when it repeats a webhook.
		$this->assertSame( 'done', Card::apply_payment( 'tr_new1' ) );
		$this->assertSame( array( 'mdt_old' ), $this->mollie['revoked'], 'Revoked once.' );
		$this->assertSame( 'mdt_new', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );

		$this->assertSame( 'Mastercard ending 4444, expires 12/99', Card::describe( Card::details( wcs_get_subscription( $sub->get_id() ) ) ), 'The page shows the new card.' );
	}

	public function test_a_stale_read_cannot_apply_a_payment_twice(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		Card::start_replace( $user );
		$this->mollie['payments']['tr_new1']['status']    = 'paid';
		$this->mollie['payments']['tr_new1']['mandateId'] = 'mdt_new';

		// Another request already applied it, and this one still sees the old subscription data.
		add_option( Card::CLAIM . md5( 'tr_new1' ), time(), '', false );

		$this->assertSame( 'done', Card::apply_payment( 'tr_new1' ) );
		$this->assertSame( array(), $this->mollie['revoked'] );
		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
	}

	public function test_a_payment_that_is_not_ours_is_ignored(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );

		// A paid first payment of the right customer that this site never started.
		$this->mollie['payments']['tr_other1'] = array( 'id' => 'tr_other1', 'status' => 'paid', 'sequenceType' => 'first', 'customerId' => 'cst_1', 'mandateId' => 'mdt_new', 'metadata' => array( 'beltoft_card_subscription' => $sub->get_id() ) );
		$this->assertSame( 'ignored', Card::apply_payment( 'tr_other1' ) );

		// One of ours, but another customer's.
		Card::start_replace( $user );
		$this->mollie['payments']['tr_new1']['status']     = 'paid';
		$this->mollie['payments']['tr_new1']['mandateId']  = 'mdt_new';
		$this->mollie['payments']['tr_new1']['customerId'] = 'cst_other';
		$this->assertSame( 'ignored', Card::apply_payment( 'tr_new1' ) );

		// Not a usage account.
		$plain = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->mollie['payments']['tr_plain1'] = array( 'id' => 'tr_plain1', 'status' => 'paid', 'metadata' => array( 'beltoft_card_subscription' => $plain->get_id() ) );
		$this->assertSame( 'ignored', Card::apply_payment( 'tr_plain1' ) );

		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
		$this->assertSame( array(), $this->mollie['revoked'] );
	}

	public function test_the_webhook_fetches_the_payment_and_never_believes_the_body(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		Card::start_replace( $user );
		do_action( 'rest_api_init' );

		// The body claims everything; Mollie says open.
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/card/webhook' );
		$request->set_body_params( array( 'id' => 'tr_new1', 'status' => 'paid', 'mandateId' => 'mdt_evil' ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'mdt_old', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );

		$this->mollie['payments']['tr_new1']['status']    = 'paid';
		$this->mollie['payments']['tr_new1']['mandateId'] = 'mdt_new';
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

	public function test_the_page_applies_a_paid_payment_when_the_webhook_is_late(): void {
		$user = $this->new_user();
		$sub  = $this->card_account( $user );
		wp_set_current_user( $user );
		Card::start_replace( $user );
		$this->mollie['payments']['tr_new1']['status']    = 'paid';
		$this->mollie['payments']['tr_new1']['mandateId'] = 'mdt_new';

		$_GET[ Card::QUERY ] = 'replaced';
		$html                = Card::html();
		unset( $_GET[ Card::QUERY ] );

		$this->assertStringContainsString( 'Card replaced.', $html );
		$this->assertStringContainsString( 'Mastercard ending 4444', $html );
		$this->assertSame( 'mdt_new', wcs_get_subscription( $sub->get_id() )->get_meta( '_mollie_mandate_id' ) );
	}

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

	public function test_a_return_from_a_card_only_order_goes_to_the_payment_methods_page(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( wc_get_product( Account::product_id() ), 1 );
		$order->save();
		$this->assertTrue( Card::is_card_order( $order ) );

		$other = wc_create_order( array( 'customer_id' => $user ) );
		$other->add_product( wc_get_product( Account::product_id() ), 1 );
		$other->add_product( $this->usage_product()['m-None'], 1 );
		$other->save();
		$this->assertFalse( Card::is_card_order( $other ) );
	}

	public function test_the_card_setup_cart_needs_full_billing_details(): void {
		wp_set_current_user( $this->new_user() );
		$this->assertFalse( \BeltoftServices\BillingRequirements::cart_needs_billing() );
		Card::start_add( get_current_user_id() );
		$this->assertTrue( \BeltoftServices\BillingRequirements::cart_needs_billing() );
		$fields = \BeltoftServices\BillingRequirements::require_fields( array( 'billing' => array( 'billing_address_1' => array( 'required' => false ), 'billing_phone' => array( 'required' => false ) ) ) );
		$this->assertTrue( $fields['billing']['billing_address_1']['required'] );
		$this->assertTrue( $fields['billing']['billing_phone']['required'] );
	}

	public function test_cleanup_cancels_old_pending_card_setups_only(): void {
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

	public function test_replace_revokes_a_pending_mandate_left_by_an_abandoned_replace(): void {
		$user = $this->new_user();
		$this->card_account( $user );
		$this->mollie['mandates']['mdt_stale'] = array( 'id' => 'mdt_stale', 'status' => 'pending', 'method' => 'creditcard' );

		Card::start_replace( $user );

		$this->assertSame( array( 'mdt_stale' ), $this->mollie['revoked'], 'Only the pending one, never the card in use.' );
	}
}

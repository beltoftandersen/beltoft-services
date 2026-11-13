<?php
/**
 * Credit codes: the coupon type, the cart refusal and redeeming on the credit page (the card is a saved token, its fingerprint supplied by a filter).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Code;
use BeltoftServices\RateLimit;
use BeltoftServices\Tests\Usage\UsageCardGateway;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * Every rule of Code::redeem.
 */
class CodeTest extends CreditTestCase {

	/**
	 * Card fingerprint of each fake payment method id.
	 *
	 * @var array<string, string>
	 */
	private $fingerprints = array();

	/**
	 * Expiry year of the cards made by customer().
	 *
	 * @var string
	 */
	private $year = '2099';

	public function set_up(): void {
		parent::set_up();
		WC()->payment_gateways()->payment_gateways['stripe_cc'] = new UsageCardGateway( 'stripe_cc' );
		$this->year         = '2099';
		$this->fingerprints = array(
			'mdt_a'    => 'fp_AAA',
			'mdt_b'    => 'fp_BBB',
			'mdt_a2'   => 'fp_AAA',
			'mdt_nofp' => '',
		);
		add_filter(
			'beltoft_services_card_fingerprint',
			function ( $fingerprint, $token ) {
				return $token ? ( $this->fingerprints[ $token->get_token() ] ?? '' ) : '';
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_card_fingerprint' );
		unset( WC()->payment_gateways()->payment_gateways['stripe_cc'] );
		parent::tear_down();
	}

	/**
	 * A customer with an active usage account holding a saved card (a token named by $mandate, which picks its fingerprint).
	 *
	 * @param string $mandate Token id ('' for no card).
	 * @param string $email   Email.
	 */
	private function customer( string $mandate = 'mdt_a', string $email = '' ): int {
		$user = (int) self::factory()->user->create( array( 'user_email' => '' !== $email ? $email : 'christian.beltoft+code' . wp_generate_password( 6, false ) . '@gmail.com' ) );

		if ( '' !== $mandate ) {
			$token = new \WC_Payment_Token_CC();
			$token->set_gateway_id( 'stripe_cc' );
			$token->set_token( $mandate );
			$token->set_user_id( $user );
			$token->set_card_type( 'visa' );
			$token->set_last4( '1111' );
			$token->set_expiry_month( '08' );
			$token->set_expiry_year( $this->year );
			$token->save();

			$sub = $this->usage_account( $user, 'active' );
			$sub->set_payment_method( 'stripe_cc' );
			$sub->update_meta_data( '_payment_method_token', $mandate );
			$sub->update_meta_data( '_wc_stripe_customer', 'cus_1' );
			$sub->save();
		}

		return $user;
	}

	/**
	 * A credit coupon.
	 *
	 * @param array<string, mixed> $args Overrides.
	 */
	private function code( array $args = array() ): \WC_Coupon {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $args['code'] ?? 'TEST' . wp_generate_password( 6, false ) );
		$coupon->set_discount_type( $args['type'] ?? Code::TYPE );
		$coupon->set_amount( $args['amount'] ?? '10' );
		foreach ( array( 'usage_limit', 'usage_limit_per_user', 'date_expires', 'email_restrictions' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$coupon->{ 'set_' . $key }( $args[ $key ] );
			}
		}
		$coupon->save();

		return $coupon;
	}

	private function fresh( \WC_Coupon $c ): \WC_Coupon {
		return new \WC_Coupon( $c->get_id() );
	}

	public function test_the_first_redemption_fires_the_currency_action_and_the_second_does_not(): void {
		$fired = array();
		add_action(
			'beltoft_services_credit_currency_set',
			static function ( $user, $currency ) use ( &$fired ) {
				$fired[] = array( (int) $user, (string) $currency );
			},
			10,
			2
		);
		$user = $this->customer();
		$this->code( array( 'amount' => '10', 'code' => 'SETONE' ) );
		$this->code( array( 'amount' => '5', 'code' => 'SETTWO' ) );

		$this->assertTrue( Code::redeem( $user, 'SETONE' )['ok'] );
		$this->assertSame( array( array( $user, 'EUR' ) ), $fired );
		$this->assertTrue( Code::redeem( $user, 'SETTWO' )['ok'] );
		$this->assertCount( 1, $fired );
		remove_all_actions( 'beltoft_services_credit_currency_set' );
	}

	public function test_type_is_registered(): void {
		$this->assertArrayHasKey( Code::TYPE, wc_get_coupon_types() );
	}

	public function test_redeem_credits_the_balance_once_and_records_usage(): void {
		$user   = $this->customer();
		$coupon = $this->code( array( 'amount' => '10', 'code' => 'CREDITTEST10' ) );

		$r = Code::redeem( $user, 'credittest10' );

		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertStringContainsString( '10.00', $r['message'] );
		$this->assertSame( 1000, Balance::get( $user ) );
		$row = Balance::history( $user, 1 )[0];
		$this->assertSame( 'Coupon CREDITTEST10', $row['note'] );
		$this->assertSame( Balance::PROMO, $row['kind'] );
		$this->assertSame( 1, $this->fresh( $coupon )->get_usage_count() );
		$this->assertSame( 1, (int) $this->fresh( $coupon )->get_data_store()->get_usage_by_user_id( $coupon, (string) $user ) );
		$this->assertCount( 1, (array) $this->fresh( $coupon )->get_meta( Code::META ) );
	}

	public function test_fingerprint_is_stored_hashed_only(): void {
		$user   = $this->customer();
		$coupon = $this->code();
		Code::redeem( $user, $coupon->get_code() );

		$stored = (string) wp_json_encode( $this->fresh( $coupon )->get_meta( Code::META ) );
		$this->assertStringNotContainsString( 'fp_AAA', $stored );
		$this->assertStringContainsString( Code::card_hash( 'fp_AAA', $coupon->get_id() ), $stored );
	}

	public function test_same_customer_twice_is_refused(): void {
		$user   = $this->customer();
		$coupon = $this->code( array( 'usage_limit_per_user' => 1 ) );

		$this->assertTrue( Code::redeem( $user, $coupon->get_code() )['ok'] );
		$again = Code::redeem( $user, $coupon->get_code() );

		$this->assertFalse( $again['ok'] );
		$this->assertSame( 1000, Balance::get( $user ) );
	}

	public function test_same_card_on_another_customer_is_refused(): void {
		$first  = $this->customer( 'mdt_a' );
		$second = $this->customer( 'mdt_a2' );
		$coupon = $this->code();

		$this->assertTrue( Code::redeem( $first, $coupon->get_code() )['ok'] );
		$r = Code::redeem( $second, $coupon->get_code() );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'This code was already used with this card.', $r['message'] );
		$this->assertSame( 0, Balance::get( $second ) );
		$this->assertSame( 1, $this->fresh( $coupon )->get_usage_count() );
	}

	public function test_another_card_may_use_a_code_without_per_user_limit(): void {
		$first  = $this->customer( 'mdt_a' );
		$second = $this->customer( 'mdt_b' );
		$coupon = $this->code();

		$this->assertTrue( Code::redeem( $first, $coupon->get_code() )['ok'] );
		$this->assertTrue( Code::redeem( $second, $coupon->get_code() )['ok'] );
		$this->assertSame( 2, $this->fresh( $coupon )->get_usage_count() );
	}

	public function test_without_a_card_is_refused(): void {
		$user = $this->customer( '' );
		$r    = Code::redeem( $user, $this->code()->get_code() );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'Add a card first, then redeem the code.', $r['message'] );
		$this->assertSame( 0, Balance::get( $user ) );
	}

	public function test_missing_fingerprint_is_refused(): void {
		$user = $this->customer( 'mdt_nofp' );
		$r    = Code::redeem( $user, $this->code()->get_code() );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 0, Balance::get( $user ) );
	}

	public function test_expired_card_is_refused(): void {
		$this->year = '2020';
		$user       = $this->customer( 'mdt_a' );

		$this->assertFalse( Code::redeem( $user, $this->code()->get_code() )['ok'] );
	}

	public function test_unknown_code_and_other_coupon_types_are_refused(): void {
		$user  = $this->customer();
		$plain = $this->code( array( 'type' => 'fixed_cart' ) );

		$this->assertFalse( Code::redeem( $user, 'NOSUCHCODE' )['ok'] );
		$this->assertFalse( Code::redeem( $user, $plain->get_code() )['ok'] );
		$this->assertFalse( Code::redeem( $user, '' )['ok'] );
		$this->assertSame( 0, Balance::get( $user ) );
	}

	public function test_expired_code_is_refused(): void {
		$user = $this->customer();
		$r    = Code::redeem( $user, $this->code( array( 'date_expires' => time() - DAY_IN_SECONDS ) )->get_code() );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'That code has expired.', $r['message'] );
	}

	public function test_usage_limit_is_refused(): void {
		$first  = $this->customer( 'mdt_a' );
		$second = $this->customer( 'mdt_b' );
		$coupon = $this->code( array( 'usage_limit' => 1 ) );

		$this->assertTrue( Code::redeem( $first, $coupon->get_code() )['ok'] );
		$r = Code::redeem( $second, $coupon->get_code() );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'That code has been used up.', $r['message'] );
	}

	public function test_allowed_emails_are_enforced(): void {
		$coupon = $this->code( array( 'email_restrictions' => array( 'christian.beltoft+vip@gmail.com' ) ) );
		$out    = $this->customer( 'mdt_a' );
		$in     = $this->customer( 'mdt_b', 'christian.beltoft+vip@gmail.com' );

		$this->assertFalse( Code::redeem( $out, $coupon->get_code() )['ok'] );
		$this->assertTrue( Code::redeem( $in, $coupon->get_code() )['ok'] );
	}

	public function test_a_zero_amount_is_refused(): void {
		$user = $this->customer();

		$this->assertFalse( Code::redeem( $user, $this->code( array( 'amount' => '0' ) )->get_code() )['ok'] );
	}

	public function test_credit_switched_off_is_refused(): void {
		$user = $this->customer();
		$code = $this->code()->get_code();
		$this->settings( array( 'credit_enabled' => 0 ) );

		$this->assertFalse( Code::redeem( $user, $code )['ok'] );
	}

	public function test_logged_out_is_refused(): void {
		$this->assertFalse( Code::redeem( 0, $this->code()->get_code() )['ok'] );
	}

	public function test_rate_limit(): void {
		$user = $this->customer();
		add_filter( 'beltoft_services_credit_code_rate_limit', static fn() => 2 );

		Code::redeem( $user, 'NOPE1' );
		Code::redeem( $user, 'NOPE2' );
		$r = Code::redeem( $user, 'NOPE3' );

		$this->assertSame( 'Too many tries. Wait a while and try again.', $r['message'] );
	}

	public function test_ledger_reference_is_idempotent(): void {
		$user   = $this->customer();
		$coupon = $this->code();
		Code::redeem( $user, $coupon->get_code() );

		// The coupon's own record is lost: the ledger still refuses the same card.
		$c = $this->fresh( $coupon );
		$c->delete_meta_data( Code::META );
		$c->save();
		$r = Code::redeem( $user, $coupon->get_code() );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 1000, Balance::get( $user ) );
		$this->assertSame( 1, $this->fresh( $coupon )->get_usage_count() );
	}

	public function test_a_credit_code_never_applies_in_a_cart(): void {
		$coupon = $this->code();
		WC()->cart->empty_cart();
		WC()->cart->remove_coupons();
		wc_clear_notices();

		$applied = WC()->cart->apply_coupon( $coupon->get_code() );
		$errors  = wc_get_notices( 'error' );

		$this->assertFalse( $applied );
		$this->assertFalse( WC()->cart->has_discount( $coupon->get_code() ) );
		$this->assertSame( 'This coupon gives account credit. Redeem it on your Billing page.', wp_strip_all_tags( $errors[0]['notice'] ?? '' ) );
		wc_clear_notices();
	}

	public function test_an_expired_credit_code_in_a_cart_gives_the_same_message(): void {
		$coupon = $this->code( array( 'date_expires' => time() - DAY_IN_SECONDS ) );
		wc_clear_notices();

		WC()->cart->apply_coupon( $coupon->get_code() );
		$errors = wc_get_notices( 'error' );

		$this->assertSame( 'This coupon gives account credit. Redeem it on your Billing page.', wp_strip_all_tags( $errors[0]['notice'] ?? '' ) );
		wc_clear_notices();
	}

	public function test_redeeming_makes_no_network_call(): void {
		$user = $this->customer( 'mdt_a' );
		Code::redeem( $user, $this->code()->get_code() );

		$this->assertSame( array(), $this->http_calls() );
	}

	public function test_form_handler_redeems_with_a_nonce(): void {
		$user   = $this->customer();
		$coupon = $this->code();
		wp_set_current_user( $user );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			Code::ACTION          => '1',
			'_wpnonce'            => wp_create_nonce( Code::NONCE ),
			'beltoft_credit_code' => $coupon->get_code(),
		);
		$hook = static function ( $url ) {
			throw new \RuntimeException( (string) $url );
		};
		add_filter( 'wp_redirect', $hook );
		wc_clear_notices();

		try {
			Code::handle();
			$this->fail( 'No redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $hook );
			$_POST = array();
			unset( $_SERVER['REQUEST_METHOD'] );
		}

		$this->assertSame( 1000, Balance::get( $user ) );
		$this->assertCount( 1, wc_get_notices( 'success' ) );
		wc_clear_notices();
	}

	public function test_form_handler_refuses_a_bad_nonce(): void {
		$user   = $this->customer();
		$coupon = $this->code();
		wp_set_current_user( $user );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( Code::ACTION => '1', '_wpnonce' => 'bad', 'beltoft_credit_code' => $coupon->get_code() );
		$hook                      = static function ( $url ) {
			throw new \RuntimeException( (string) $url );
		};
		add_filter( 'wp_redirect', $hook );

		try {
			Code::handle();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $hook );
			$_POST = array();
			unset( $_SERVER['REQUEST_METHOD'] );
		}

		$this->assertSame( 0, Balance::get( $user ) );
		wc_clear_notices();
	}

	public function test_the_page_shows_the_redeem_form(): void {
		wp_set_current_user( $this->customer() );
		$html = \BeltoftServices\Credit\Page::html();

		$this->assertStringContainsString( 'Have a coupon?', $html );
		$this->assertStringContainsString( 'name="beltoft_credit_code"', $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
	}

	public function test_the_page_prints_a_queued_notice(): void {
		wp_set_current_user( $this->customer() );
		wc_clear_notices();
		wc_add_notice( 'Test notice text.', 'success' );

		$this->assertStringContainsString( 'Test notice text.', \BeltoftServices\Credit\Page::html() );
		wc_clear_notices();
	}

	public function test_notices_go_to_the_status_bar_and_a_refused_code_under_its_field(): void {
		wp_set_current_user( $this->customer() );
		wc_clear_notices();
		wc_add_notice( 'That code is not valid.', 'error', array( 'beltoft_field' => 'code' ) );
		$html = \BeltoftServices\Credit\Page::html();

		$this->assertStringContainsString( '<p hidden data-bs-flash="fail" data-bs-field="#beltoft-credit-code">That code is not valid.</p>', $html );
		$this->assertStringNotContainsString( 'woocommerce-error', $html );
		$this->assertSame( array(), wc_get_notices(), 'Printed once.' );
		$this->assertMatchesRegularExpression( '/<form class="beltoft-services-credit__code"[^>]*novalidate data-bs-working=/', $html );
		$this->assertTrue( wp_script_is( 'beltoft-services-credit', 'enqueued' ) );
	}
}

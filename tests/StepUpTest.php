<?php
/**
 * The second check: an emailed one-time code.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Account\ApiTokens;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Domains\AuthCodeRoute;
use BeltoftServices\StepUp;

/**
 * StepUp tests. Mail is captured with pre_wp_mail and never leaves the test.
 */
final class StepUpTest extends TestCase {

	/**
	 * Captured mails.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	private function customer(): int {
		// Gmail alias of the owner: unique per user, never a dummy address (and the mail is captured anyway).
		return self::factory()->user->create( array( 'first_name' => 'Dana', 'user_email' => 'christian.beltoft+su' . wp_generate_password( 8, false ) . '@gmail.com' ) );
	}

	/**
	 * The code of the last captured mail.
	 */
	private function code(): string {
		$mail = (string) end( $this->mails )['message'];
		$this->assertSame( 1, preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( $mail ), $m ), 'The mail has a six digit code.' );

		return $m[1];
	}

	private function wrong( string $code ): string {
		return '000000' === $code ? '111111' : '000000';
	}

	public function test_sending_emails_a_six_digit_code_for_the_purpose_to_the_account_address(): void {
		$user = $this->customer();

		$this->assertTrue( StepUp::send( $user, ApiTokens::PURPOSE ) );
		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$text = wp_strip_all_tags( (string) $mail['message'] );
		$this->assertStringStartsWith( 'christian.beltoft+su', is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $this->code() );
		$this->assertStringContainsString( 'making a Full access API token', $text );
		$this->assertStringContainsString( '10 minutes', $text );
		$this->assertStringContainsString( 'Nobody from', $text );
		$this->assertStringContainsString( 'will ever ask you for this code', $text );
		$this->assertStringNotContainsString( $this->code(), (string) $mail['subject'], 'The code is not in the subject.' );
		$this->assertStringNotContainsString( '—', (string) $mail['message'] );
		$this->assertStringNotContainsString( '·', (string) $mail['message'] );

		$this->assertTrue( StepUp::send( $user, AuthCodeRoute::PURPOSE ) );
		$this->assertStringContainsString( 'showing a domain transfer code', wp_strip_all_tags( (string) $this->mails[1]['message'] ) );
	}

	public function test_only_a_hash_is_stored(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$code = $this->code();

		foreach ( get_user_meta( $user ) as $values ) {
			$this->assertStringNotContainsString( $code, wp_json_encode( array_map( 'maybe_unserialize', $values ) ) );
		}
	}

	public function test_the_right_code_works_once_and_is_then_gone(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$code = $this->code();

		$this->assertTrue( StepUp::verify( $user, $code, ApiTokens::PURPOSE ) );
		$this->assertFalse( StepUp::verify( $user, $code, ApiTokens::PURPOSE ), 'Single use.' );
	}

	public function test_spaces_in_the_typed_code_are_ignored(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$code = $this->code();

		$this->assertTrue( StepUp::verify( $user, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), ApiTokens::PURPOSE ) );
	}

	public function test_a_code_for_one_purpose_does_not_work_for_the_other(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$code = $this->code();

		$this->assertFalse( StepUp::verify( $user, $code, AuthCodeRoute::PURPOSE ) );
		$this->assertTrue( StepUp::verify( $user, $code, ApiTokens::PURPOSE ), 'The wrong purpose does not use it up.' );

		StepUp::send( $user, AuthCodeRoute::PURPOSE );
		$this->assertFalse( StepUp::verify( $user, $this->code(), 'something_else' ) );
	}

	public function test_a_code_belongs_to_its_customer(): void {
		$one = $this->customer();
		$two = $this->customer();
		StepUp::send( $one, ApiTokens::PURPOSE );

		$this->assertFalse( StepUp::verify( $two, $this->code(), ApiTokens::PURPOSE ) );
	}

	public function test_a_code_expires_after_ten_minutes(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$code = $this->code();
		$meta = get_user_meta( $user, 'beltoft_services_stepup_api_token', true );
		$this->assertEqualsWithDelta( time() + 600, $meta['expires'], 5 );

		$meta['expires'] = time() - 1;
		update_user_meta( $user, 'beltoft_services_stepup_api_token', $meta );
		$this->assertFalse( StepUp::verify( $user, $code, ApiTokens::PURPOSE ) );
		$this->assertSame( '', get_user_meta( $user, 'beltoft_services_stepup_api_token', true ) );
	}

	public function test_five_wrong_tries_burn_the_code_even_for_the_right_one(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$code = $this->code();

		for ( $i = 0; $i < StepUp::MAX_TRIES; $i++ ) {
			$this->assertFalse( StepUp::verify( $user, $this->wrong( $code ), ApiTokens::PURPOSE ) );
		}

		$this->assertFalse( StepUp::verify( $user, $code, ApiTokens::PURPOSE ) );
	}

	public function test_four_wrong_tries_leave_the_code_working(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$code = $this->code();

		for ( $i = 0; $i < StepUp::MAX_TRIES - 1; $i++ ) {
			StepUp::verify( $user, $this->wrong( $code ), ApiTokens::PURPOSE );
		}

		$this->assertTrue( StepUp::verify( $user, $code, ApiTokens::PURPOSE ) );
	}

	public function test_a_new_code_replaces_the_old_one(): void {
		$user = $this->customer();
		StepUp::send( $user, ApiTokens::PURPOSE );
		$first = $this->code();

		do {
			StepUp::send( $user, ApiTokens::PURPOSE );
			$second = $this->code();
		} while ( $second === $first && count( $this->mails ) < 3 );

		if ( $second !== $first ) {
			$this->assertFalse( StepUp::verify( $user, $first, ApiTokens::PURPOSE ) );
		}
	}

	public function test_nothing_sent_means_nothing_to_verify(): void {
		$this->assertFalse( StepUp::verify( $this->customer(), '123456', ApiTokens::PURPOSE ) );
		$this->assertFalse( StepUp::verify( $this->customer(), '', ApiTokens::PURPOSE ) );
	}

	public function test_sending_is_rate_limited_per_customer_and_purpose(): void {
		$user = $this->customer();

		for ( $i = 0; $i < StepUp::SEND_LIMIT; $i++ ) {
			$this->assertTrue( StepUp::send( $user, ApiTokens::PURPOSE ) );
		}

		$over = StepUp::send( $user, ApiTokens::PURPOSE );
		$this->assertWPError( $over );
		$this->assertSame( 'rate_limited', $over->get_error_code() );
		$this->assertCount( StepUp::SEND_LIMIT, $this->mails, 'No mail went out over the limit.' );
		$this->assertTrue( StepUp::send( $user, AuthCodeRoute::PURPOSE ), 'The other purpose counts apart.' );
	}

	public function test_a_failed_mail_leaves_no_code_behind(): void {
		$user = $this->customer();
		remove_all_filters( 'pre_wp_mail' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->assertWPError( StepUp::send( $user, ApiTokens::PURPOSE ) );
		$this->assertSame( '', get_user_meta( $user, 'beltoft_services_stepup_api_token', true ) );
	}

	public function test_purposes_are_the_three_registered_ones_and_a_third_can_be_added(): void {
		$this->assertEqualsCanonicalizing( array( 'api_token', 'transfer_code', 'oauth_full' ), StepUp::purposes() );
		$this->assertSame( 'api_token', ApiTokens::PURPOSE );
		$this->assertSame( 'transfer_code', AuthCodeRoute::PURPOSE );

		$user = $this->customer();
		$this->assertWPError( StepUp::send( $user, 'third' ), 'Unknown until registered.' );

		$add = static fn( $p ) => array_merge( $p, array( 'third', 'api_token' ) );
		add_filter( 'beltoft_services_stepup_purposes', $add );
		$text = static fn( $t, $p ) => 'third' === $p ? 'doing the third thing' : $t;
		add_filter( 'beltoft_services_stepup_text', $text, 10, 2 );

		$this->assertCount( 4, StepUp::purposes(), 'No duplicates.' );
		$this->assertTrue( StepUp::send( $user, 'third' ) );
		$this->assertStringContainsString( 'doing the third thing', wp_strip_all_tags( (string) end( $this->mails )['message'] ) );
		$this->assertTrue( StepUp::verify( $user, $this->code(), 'third' ) );

		remove_filter( 'beltoft_services_stepup_purposes', $add );
		remove_filter( 'beltoft_services_stepup_text', $text, 10 );
		$this->assertWPError( StepUp::send( $user, 'third' ) );
	}

	public function test_an_unknown_purpose_or_user_cannot_send(): void {
		$this->assertWPError( StepUp::send( $this->customer(), 'anything' ) );
		$this->assertWPError( StepUp::send( 999999, ApiTokens::PURPOSE ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_ask_shows_a_masked_address_and_no_password(): void {
		$ask = StepUp::ask( $this->customer(), ApiTokens::PURPOSE );

		$this->assertSame( 'emailed', $ask['kind'] );
		$this->assertSame( 'c***@gmail.com', $ask['email'] );
		$this->assertSame( 'one-time-code', $ask['autocomplete'] );
		$this->assertStringNotContainsStringIgnoringCase( 'password', wp_json_encode( $ask ) );
	}

	public function test_a_site_can_plug_in_its_own_factor_and_nothing_is_sent_for_it(): void {
		$user = $this->customer();
		$ask  = static fn() => array( 'kind' => 'code', 'label' => 'Code from your app' );
		$ok   = static fn( $ok, $u, $secret, $kind, $purpose ) => '654321' === $secret && 'code' === $kind && AuthCodeRoute::PURPOSE === $purpose ? true : false;
		add_filter( 'beltoft_services_step_up_ask', $ask );

		$this->assertSame( 'code', StepUp::ask( $user, ApiTokens::PURPOSE )['kind'] );
		$this->assertSame( 'Code from your app', StepUp::ask( $user, ApiTokens::PURPOSE )['label'] );
		$this->assertWPError( StepUp::send( $user, ApiTokens::PURPOSE ) );
		$this->assertSame( array(), $this->mails );
		$this->assertFalse( StepUp::verify( $user, '654321', AuthCodeRoute::PURPOSE ), 'Without a verify filter nothing passes.' );

		add_filter( 'beltoft_services_step_up_verify', $ok, 10, 5 );
		$this->assertTrue( StepUp::verify( $user, '654321', AuthCodeRoute::PURPOSE ) );
		$this->assertFalse( StepUp::verify( $user, '654321', ApiTokens::PURPOSE ) );
		remove_filter( 'beltoft_services_step_up_verify', $ok, 10 );
		remove_filter( 'beltoft_services_step_up_ask', $ask );
	}

	public function test_the_send_route_needs_a_login_and_a_known_purpose(): void {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/step-up/send' );
		$request->set_param( 'purpose', 'transfer_code' );
		wp_set_current_user( 0 );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );

		$user = $this->customer();
		wp_set_current_user( $user );
		$bad = new \WP_REST_Request( 'POST', '/beltoft-services/v1/step-up/send' );
		$bad->set_param( 'purpose', 'other' );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );

		$ok = rest_do_request( $request );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'c***@gmail.com', $ok->get_data()['email'] );
		$this->assertStringNotContainsString( $this->code(), (string) wp_json_encode( $ok->get_data() ), 'The reply never holds the code.' );
		$this->assertCount( 1, $this->mails );
	}

	public function test_the_send_route_answers_429_over_the_limit(): void {
		wp_set_current_user( $this->customer() );
		$status = 200;

		for ( $i = 0; $i <= StepUp::SEND_LIMIT; $i++ ) {
			$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/step-up/send' );
			$request->set_param( 'purpose', 'transfer_code' );
			$status = rest_do_request( $request )->get_status();
		}

		$this->assertSame( 429, $status );
	}

	public function test_the_api_token_form_sends_a_code_and_a_full_token_needs_it(): void {
		$user  = $this->customer();
		wp_set_current_user( $user );
		$nonce = wp_create_nonce( ApiTokens::NONCE );

		$sent = ApiTokens::process( $user, array( 'action' => 'send_code', 'name' => 'Claude', 'scope' => 'full', 'nonce' => $nonce ) );
		$this->assertTrue( $sent['ok'] );
		$this->assertSame( 'We sent a code to c***@gmail.com.', $sent['message'] );
		$this->assertCount( 1, $this->mails );
		$code = $this->code();

		ob_start();
		ApiTokens::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'We sent a code to c***@gmail.com', $html );
		$this->assertStringContainsString( 'value="Claude"', $html );
		$this->assertStringContainsString( 'value="send_code"', $html );
		$this->assertStringNotContainsString( 'type="password"', $html );
		$this->assertStringNotContainsString( $code, $html );

		$wrong = ApiTokens::process( $user, array( 'action' => 'create', 'name' => 'Claude', 'scope' => 'full', 'secret' => $this->wrong( $code ), 'nonce' => $nonce ) );
		$this->assertFalse( $wrong['ok'] );
		$this->assertSame( 'That code is not right or has expired. Send a new code.', $wrong['message'] );
		$this->assertSame( array(), Tokens::all( $user ) );

		$this->assertTrue( StepUp::verify( $user, $code, AuthCodeRoute::PURPOSE ) === false, 'The api_token code does not open a transfer code.' );

		$made = ApiTokens::process( $user, array( 'action' => 'create', 'name' => 'Claude', 'scope' => 'full', 'secret' => $code, 'nonce' => $nonce ) );
		$this->assertTrue( $made['ok'], $made['message'] );
		$this->assertCount( 1, Tokens::all( $user ) );

		$again = ApiTokens::process( $user, array( 'action' => 'create', 'name' => 'Second', 'scope' => 'full', 'secret' => $code, 'nonce' => $nonce ) );
		$this->assertFalse( $again['ok'], 'The code works once.' );
	}

	public function test_a_code_for_the_transfer_code_does_not_make_a_token(): void {
		$user  = $this->customer();
		$nonce = wp_create_nonce( ApiTokens::NONCE );
		StepUp::send( $user, AuthCodeRoute::PURPOSE );

		$result = ApiTokens::process( $user, array( 'action' => 'create', 'name' => 'Claude', 'scope' => 'full', 'secret' => $this->code(), 'nonce' => $nonce ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), Tokens::all( $user ) );
	}

	public function test_read_and_actions_tokens_need_no_code(): void {
		$user = $this->customer();
		$made = ApiTokens::process( $user, array( 'action' => 'create', 'name' => 'Script', 'scope' => 'read', 'nonce' => wp_create_nonce( ApiTokens::NONCE ) ) );

		$this->assertTrue( $made['ok'], $made['message'] );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_send_button_needs_a_valid_nonce(): void {
		$user = $this->customer();
		$sent = ApiTokens::process( $user, array( 'action' => 'send_code', 'nonce' => 'bad' ) );

		$this->assertFalse( $sent['ok'] );
		$this->assertSame( array(), $this->mails );
	}
}

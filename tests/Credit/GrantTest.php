<?php
/**
 * Credit an administrator gives or corrects without a payment.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Grant;
use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Topup;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * The form, the command's core, the history words, and how this credit sits next to top-ups.
 */
class GrantTest extends CreditTestCase {

	private function admin(): int {
		$id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );

		return $id;
	}

	private function customer(): int {
		return (int) self::factory()->user->create( array( 'user_email' => 'christian.beltoft+grant' . wp_generate_password( 6, false ) . '@gmail.com' ) );
	}

	/**
	 * Runs the form handler with a redirect that throws instead of exiting.
	 *
	 * @param array<string, mixed> $post Fields.
	 */
	private function submit( array $post ): string {
		$_POST    = $post;
		$_REQUEST = $post;
		$location = '';
		$hook     = static function ( $url ) {
			throw new \RuntimeException( (string) $url );
		};
		add_filter( 'wp_redirect', $hook );

		try {
			Grant::handle();
		} catch ( \RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $hook );
			$_POST    = array();
			$_REQUEST = array();
		}

		return $location;
	}

	/**
	 * A valid form of an admin for a customer.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function form( int $user, array $over = array() ): array {
		return array_merge(
			array(
				'user_id'                      => $user,
				'request_id'                   => wp_generate_uuid4(),
				'beltoft_credit_admin_amount'  => '10',
				'beltoft_credit_admin_reason'  => 'Welcome credit',
				'_wpnonce'                     => wp_create_nonce( Grant::NONCE ),
			),
			$over
		);
	}

	public function test_amounts_are_parsed_to_cents(): void {
		$this->assertSame( 1000, Grant::parse( '10' ) );
		$this->assertSame( 250, Grant::parse( '2,50' ) );
		$this->assertSame( -250, Grant::parse( '-2.50' ) );
		foreach ( array( '', '0', '0.00', 'abc', '1.234', '--1', '1e3' ) as $bad ) {
			$this->assertNull( Grant::parse( $bad ), $bad );
		}
	}

	public function test_credit_is_written_as_promo_without_vat_and_shown_as_credit_added(): void {
		$this->vat( true );
		$u = $this->customer();

		$this->assertTrue( Grant::add( $u, 1000, 'Welcome credit', 7, 'req-1' ) );

		$this->assertSame( 1000, Balance::get( $u ), 'Exactly the amount, no VAT added or taken.' );
		$row = Balance::history( $u )[0];
		$this->assertSame( 'promo', $row['kind'] );
		$this->assertSame( 'admin', $row['ref_type'] );
		$this->assertSame( 'admin-7-req-1', $row['ref_id'] );
		$this->assertSame( 'Welcome credit', $row['note'] );
		$this->assertSame( 'Credit added: Welcome credit', Page::row_label( $row ) );

		wp_set_current_user( $u );
		$this->assertStringContainsString( 'Credit added: Welcome credit', Page::html() );
	}

	public function test_the_same_request_is_written_once(): void {
		$u = $this->customer();

		$this->assertTrue( Grant::add( $u, 500, 'Promo', 1, 'same' ) );
		$this->assertTrue( Grant::add( $u, 500, 'Promo', 1, 'same' ) );

		$this->assertSame( 500, Balance::get( $u ) );
		$this->assertCount( 1, Balance::history( $u ) );
	}

	public function test_a_reason_is_required_and_the_amount_cannot_be_zero(): void {
		$u = $this->customer();

		$this->assertIsString( Grant::add( $u, 500, '   ', 1, 'r1' ) );
		$this->assertIsString( Grant::add( $u, 0, 'x', 1, 'r2' ) );
		$this->assertIsString( Grant::add( 0, 500, 'x', 1, 'r3' ) );
		$this->assertIsString( Grant::add( $u, 500, 'x', 1, '' ) );
		$this->assertSame( 0, Balance::get( $u ) );
	}

	public function test_a_correction_below_zero_needs_the_explicit_permission(): void {
		$u = $this->customer();
		Grant::add( $u, 300, 'Start', 1, 'a' );

		$this->assertIsString( Grant::add( $u, -500, 'Fix', 1, 'b' ) );
		$this->assertSame( 300, Balance::get( $u ) );

		$this->assertTrue( Grant::add( $u, -200, 'Fix', 1, 'c' ) );
		$this->assertSame( 100, Balance::get( $u ) );
		$row = Balance::history( $u )[0];
		$this->assertSame( 'adjust', $row['kind'] );
		$this->assertSame( 'Credit correction: Fix', Page::row_label( $row ) );

		$this->assertTrue( Grant::add( $u, -500, 'Fix', 1, 'd', true ) );
		$this->assertSame( -400, Balance::get( $u ) );
	}

	public function test_nothing_is_written_when_credit_is_off(): void {
		$u = $this->customer();
		$this->settings( array( 'credit_enabled' => 0 ) );

		$this->assertIsString( Grant::add( $u, 500, 'x', 1, 'off' ) );
		$this->assertSame( 0, Balance::get( $u ) );
	}

	public function test_a_refund_of_a_topup_never_touches_promo_credit(): void {
		$u = $this->customer();
		Grant::add( $u, 1000, 'Promo', 1, 'p' );
		$order = $this->topup_order( $u, 2000, 'processing' );
		$this->assertSame( 3000, Balance::get( $u ) );

		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '20.00' ) );
		$this->assertNotWPError( $refund );

		$this->assertSame( 1000, Balance::get( $u ), 'The promo credit stays.' );
		$this->assertSame( 1000, (int) Balance::find( Balance::PROMO, 'admin', 'admin-1-p' )['amount_cents'] );
	}

	public function test_the_form_needs_the_capability(): void {
		$u = $this->customer();
		wp_set_current_user( $u );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit( $this->form( $u ) );
		} finally {
			$this->assertSame( 0, Balance::get( $u ) );
		}
	}

	public function test_the_form_needs_a_valid_nonce(): void {
		$this->admin();
		$u = $this->customer();

		$this->expectException( \WPDieException::class );
		try {
			$this->submit( $this->form( $u, array( '_wpnonce' => 'bad' ) ) );
		} finally {
			$this->assertSame( 0, Balance::get( $u ) );
		}
	}

	public function test_an_admin_saves_credit_and_a_double_submit_counts_once(): void {
		$admin = $this->admin();
		$u     = $this->customer();
		$post  = $this->form( $u );

		$to = $this->submit( $post );
		$this->assertStringContainsString( 'beltoft_credit=done', $to );
		$this->submit( $post );

		$this->assertSame( 1000, Balance::get( $u ) );
		$this->assertSame( 'admin-' . $admin . '-' . $post['request_id'], Balance::history( $u )[0]['ref_id'] );
	}

	public function test_the_form_reports_why_it_refused(): void {
		$this->admin();
		$u  = $this->customer();
		$to = $this->submit( $this->form( $u, array( 'beltoft_credit_admin_amount' => '-5', '_wpnonce' => wp_create_nonce( Grant::NONCE ) ) ) );

		$this->assertStringContainsString( 'beltoft_credit=', $to );
		$this->assertStringNotContainsString( 'beltoft_credit=done', $to );
		$this->assertSame( 0, Balance::get( $u ) );
	}

	public function test_the_profile_box_shows_balance_and_rows_only_to_shop_managers(): void {
		$u = $this->customer();
		Grant::add( $u, 1000, 'Welcome credit', 1, 'x' );
		$user = get_userdata( $u );

		wp_set_current_user( $u );
		ob_start();
		Grant::render( $user );
		$this->assertSame( '', ob_get_clean() );

		$this->admin();
		ob_start();
		Grant::render( $user );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Add account credit', $html );
		$this->assertStringContainsString( '10.00', $html );
		$this->assertStringContainsString( 'Credit added: Welcome credit', $html );
	}

	public function test_promo_credit_pays_a_usage_invoice_like_a_topup(): void {
		$u   = $this->customer();
		$sub = $this->usage_account( $u );
		Grant::add( $u, 1000, 'Promo', 1, 'inv' );
		$end = \BeltoftServices\Usage\Ledger::hour( time() ) - 3 * HOUR_IN_SECONDS - 30 * DAY_IN_SECONDS;
		for ( $i = 0; $i < 50; $i++ ) {
			\BeltoftServices\Usage\Ledger::record( 701, $u, 'server', 1.0, 'hour', 0.02, $end - $i * HOUR_IN_SECONDS );
		}

		$order = wcs_create_renewal_order( $sub );

		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 1000 - Balance::cents( 1.0 ), Balance::get( $u ) );
	}
}

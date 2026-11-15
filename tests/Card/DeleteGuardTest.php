<?php
/**
 * A saved card cannot be deleted while it is still needed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Card;

use BeltoftServices\Card\DeleteGuard;
use BeltoftServices\Tests\Usage\UsageCardGateway;
use BeltoftServices\Tests\Usage\UsageTestCase;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

/**
 * Tests.
 */
class DeleteGuardTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		$gateway           = new UsageCardGateway( 'stripe_cc' );
		$gateway->supports = array_merge( $gateway->supports, array( 'add_payment_method', 'tokenization' ) );
		WC()->payment_gateways()->payment_gateways['stripe_cc'] = $gateway;
		Estimate::reset();
	}

	public function tear_down(): void {
		unset( WC()->payment_gateways()->payment_gateways['stripe_cc'] );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'beltoft_services_card_delete_needed' );
		global $wp;
		unset( $wp->query_vars['delete-payment-method'] );
		wp_set_current_user( 0 );
		Estimate::reset();
		parent::tear_down();
	}

	/**
	 * A saved card of a customer (nobody is logged in, so no usage account is made by it).
	 *
	 * @param int    $user Customer.
	 * @param string $pm   Token.
	 * @param string $year Expiry year.
	 */
	private function card( int $user, string $pm = 'pm_1', string $year = '2099' ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( 'stripe_cc' );
		$token->set_token( $pm );
		$token->set_user_id( $user );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '08' );
		$token->set_expiry_year( $year );
		$token->save();

		return $token;
	}

	/**
	 * An order that waits for payment.
	 *
	 * @param int    $user   Customer.
	 * @param float  $total  Total.
	 * @param string $status Status.
	 */
	private function order( int $user, float $total, string $status = 'pending' ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item  = new \WC_Order_Item_Fee();
		$item->set_name( 'Fee' );
		$item->set_total( $total );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * An active subscription with a price that pays with a token.
	 *
	 * @param int                $user  Customer.
	 * @param \WC_Payment_Token  $token Token.
	 * @param float              $price Price.
	 */
	private function subscription( int $user, \WC_Payment_Token $token, float $price ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'status' => 'pending', 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$sub->set_payment_method( WC()->payment_gateways()->payment_gateways()['stripe_cc'] );
		$sub->update_meta_data( '_payment_method_token', $token->get_token() );
		$sub->set_requires_manual_renewal( false );
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Plan' );
		$fee->set_total( $price );
		$sub->add_item( $fee );
		$sub->calculate_totals( false );
		$sub->save();
		$sub->update_status( 'active' );

		return wcs_get_subscription( $sub->get_id() );
	}

	/**
	 * Unbilled usage rows of a customer that has a usage account, no running service.
	 *
	 * @param int $user  Customer.
	 * @param int $hours Hours.
	 */
	private function usage( int $user, int $hours ): void {
		$this->usage_account( $user );
		$now   = Ledger::hour( time() );
		$month = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' UTC' );
		$end   = ( $now - $month > 400 * HOUR_IN_SECONDS ? $now : $month - 15 * DAY_IN_SECONDS ) - HOUR_IN_SECONDS;

		for ( $i = 0; $i < $hours; $i++ ) {
			Ledger::record( 9001, $user, 'server', 1.0, 'hour', 0.05, $end - $i * HOUR_IN_SECONDS );
		}

		Estimate::reset();
	}

	// ------------------------------------------------------------------ each reason.

	public function test_an_unpaid_order_above_zero_needs_the_only_card(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->assertNull( DeleteGuard::needed( $user, $token ) );

		$this->order( $user, 12.5 );

		$this->assertStringContainsString( 'unpaid order', (string) DeleteGuard::needed( $user, $token ) );
	}

	public function test_a_free_or_paid_or_cancelled_order_does_not_need_it(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->order( $user, 0.0 );
		$this->order( $user, 10.0, 'completed' );
		$this->order( $user, 10.0, 'cancelled' );

		$this->assertNull( DeleteGuard::needed( $user, $token ) );
	}

	public function test_an_active_subscription_with_a_price_needs_its_card(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->subscription( $user, $token, 9.0 );

		$this->assertStringContainsString( 'active services', (string) DeleteGuard::needed( $user, $token ) );
	}

	public function test_an_on_hold_subscription_needs_its_card_and_a_pending_cancel_one_does_not(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$sub   = $this->subscription( $user, $token, 9.0 );

		$sub->update_status( 'on-hold' );
		$this->assertNotNull( DeleteGuard::needed( $user, $token ) );

		wcs_get_subscription( $sub->get_id() )->update_status( 'pending-cancel' );
		$this->assertNull( DeleteGuard::needed( $user, $token ) );
	}

	public function test_the_reason_id_is_printed_once_for_two_different_reasons(): void {
		$tokens = array();
		foreach ( array( 'The first reason.', 'The second reason.' ) as $i => $text ) {
			$tokens[] = array( $this->card( $this->new_user(), 'pm_x' . $i ), $text );
		}
		foreach ( $tokens as $pair ) {
			$text = $pair[1];
			add_filter( 'beltoft_services_card_delete_needed', static fn() => $text );
			DeleteGuard::list_item( array( 'actions' => array( 'delete' => array( 'url' => '#', 'name' => 'Delete' ) ) ), $pair[0] );
			remove_all_filters( 'beltoft_services_card_delete_needed' );
		}

		ob_start();
		DeleteGuard::print_reason();
		$html = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $html, '<p' ) );
		$this->assertSame( 1, substr_count( $html, 'id="' . DeleteGuard::ANCHOR . '"' ) );
		$this->assertStringStartsWith( '<p id=', $html );
	}

	public function test_a_free_subscription_or_one_on_another_card_does_not_need_it(): void {
		$user  = $this->new_user();
		$token = $this->card( $user, 'pm_1' );
		$this->subscription( $user, $token, 0.0 );
		$this->assertNull( DeleteGuard::needed( $user, $token ) );

		$other = $this->card( $user, 'pm_2' );
		$this->subscription( $user, $other, 9.0 );
		$this->assertNull( DeleteGuard::needed( $user, $token ), 'pm_1 pays nothing and there is another card.' );
		$this->assertNull( DeleteGuard::needed( $user, $other ) );
	}

	public function test_a_running_pay_as_you_go_service_needs_the_card(): void {
		$v     = $this->usage_product();
		$user  = $this->new_user();
		$token = $this->card( $user );
		$sub   = $this->usage_account( $user );
		$this->assertNull( DeleteGuard::needed( $user, $token ), 'The account alone needs nothing.' );

		$this->usage_vm( $v['u-None'], $user, 5, $sub->get_id() );

		$this->assertStringContainsString( 'pay as you go', (string) DeleteGuard::needed( $user, $token ) );
	}

	public function test_usage_under_the_minimum_does_not_need_the_card(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->usage( $user, 20 ); // 20 h at 0.05 = 1.00 net.

		$this->assertNull( DeleteGuard::needed( $user, $token ) );
	}

	public function test_usage_over_the_minimum_needs_the_card(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->usage( $user, 200 ); // 200 h at 0.05 = 10.00 net.

		$this->assertStringContainsString( 'pay as you go', (string) DeleteGuard::needed( $user, $token ) );
	}

	public function test_a_minimum_of_zero_counts_any_unbilled_usage(): void {
		$this->settings( array( 'usage_minimum' => 0 ) );
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->usage( $user, 3 );

		$this->assertNotNull( DeleteGuard::needed( $user, $token ) );
	}

	// ------------------------------------------------------------------ the second card.

	public function test_with_another_usable_card_every_reason_is_off(): void {
		$v     = $this->usage_product();
		$user  = $this->new_user();
		$token = $this->card( $user, 'pm_1' );
		$this->card( $user, 'pm_2' );
		$sub = $this->usage_account( $user );
		$this->usage_vm( $v['u-None'], $user, 5, $sub->get_id() );
		$this->order( $user, 12.5 );
		$this->subscription( $user, $token, 9.0 );

		$this->assertNull( DeleteGuard::needed( $user, $token ) );
	}

	public function test_an_expired_other_card_does_not_count(): void {
		$user  = $this->new_user();
		$token = $this->card( $user, 'pm_1' );
		$this->card( $user, 'pm_2', '2001' );
		$this->order( $user, 12.5 );

		$this->assertNotNull( DeleteGuard::needed( $user, $token ) );
	}

	public function test_a_card_of_another_customer_is_never_needed_by_this_one(): void {
		$user  = $this->new_user();
		$other = $this->new_user();
		$token = $this->card( $other );
		$this->order( $user, 12.5 );

		$this->assertNull( DeleteGuard::needed( $user, $token ) );
	}

	public function test_the_filter_can_allow_or_change_the_reason(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->order( $user, 12.5 );
		add_filter( 'beltoft_services_card_delete_needed', '__return_null' );

		$this->assertNull( DeleteGuard::needed( $user, $token ) );
	}

	// ------------------------------------------------------------------ the button.

	public function test_the_delete_button_is_replaced_by_in_use_and_the_reason_is_printed(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->order( $user, 12.5 );

		$list    = wc_get_customer_saved_methods_list( $user );
		$actions = $list['cc'][0]['actions'];

		$this->assertArrayNotHasKey( 'delete', $actions );
		$this->assertArrayNotHasKey( 'wcs_deletion_error', $actions );
		$this->assertSame( 'In use', $actions['beltoft_services_card_needed']['name'] );

		ob_start();
		DeleteGuard::print_reason();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'unpaid order', $html );
		$this->assertStringContainsString( DeleteGuard::ANCHOR, $html );
		$this->assertNotNull( $token );
	}

	public function test_the_delete_button_stays_for_a_card_that_is_not_needed(): void {
		$user = $this->new_user();
		$this->card( $user, 'pm_1' );
		$this->card( $user, 'pm_2' );
		$this->order( $user, 12.5 );

		foreach ( wc_get_customer_saved_methods_list( $user )['cc'] as $item ) {
			$this->assertArrayHasKey( 'delete', $item['actions'] );
			$this->assertArrayNotHasKey( 'beltoft_services_card_needed', $item['actions'] );
		}
	}

	public function test_the_subscriptions_question_says_no_for_a_needed_card(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->order( $user, 12.5 );

		$this->assertFalse( apply_filters( 'wc_subscriptions_allow_subscription_token_deletion', true, $token ) );
		$this->assertTrue( apply_filters( 'wc_subscriptions_allow_subscription_token_deletion', true, $this->card( $this->new_user() ) ) );
	}

	// ------------------------------------------------------------------ the request.

	/**
	 * Sends the delete request the delete link makes and returns whether the guard redirected (refused).
	 *
	 * @param int $token_id Token.
	 */
	private function request_delete( int $token_id ): bool {
		global $wp;
		$wp->query_vars['delete-payment-method'] = (string) $token_id;
		add_filter(
			'wp_redirect',
			static function () {
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			DeleteGuard::intercept();
		} catch ( \RuntimeException $e ) {
			return true;
		}

		return false;
	}

	public function test_a_crafted_delete_request_for_a_needed_card_is_refused_with_the_reason(): void {
		$user  = $this->new_user();
		$token = $this->card( $user );
		$this->order( $user, 12.5 );
		wp_set_current_user( $user );
		wc_clear_notices();

		$this->assertTrue( $this->request_delete( $token->get_id() ) );
		$this->assertNotNull( \WC_Payment_Tokens::get( $token->get_id() ), 'The card is still there.' );
		$notices = wc_get_notices( 'error' );
		$this->assertStringContainsString( 'unpaid order', $notices[0]['notice'] );
	}

	public function test_a_delete_request_for_a_card_that_is_not_needed_goes_on_to_woocommerce(): void {
		$user  = $this->new_user();
		$token = $this->card( $user, 'pm_1' );
		$this->card( $user, 'pm_2' );
		$this->order( $user, 12.5 );
		wp_set_current_user( $user );

		$this->assertFalse( $this->request_delete( $token->get_id() ) );
	}

	public function test_someone_elses_card_is_left_to_woocommerce_to_refuse(): void {
		$user  = $this->new_user();
		$other = $this->new_user();
		$token = $this->card( $other );
		$this->order( $other, 12.5 );
		wp_set_current_user( $user );

		$this->assertFalse( $this->request_delete( $token->get_id() ) );
	}

	public function test_the_guard_runs_before_woocommerces_delete_handler(): void {
		$this->assertSame( 10, has_action( 'wp', array( DeleteGuard::class, 'intercept' ) ) );
		$this->assertSame( 20, has_action( 'wp', array( \WC_Form_Handler::class, 'delete_payment_method_action' ) ) );
	}

	public function test_no_route_of_the_plugin_deletes_a_card(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src' ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || 'DeleteGuard.php' === $file->getFilename() ) {
				continue;
			}

			$this->assertDoesNotMatchRegularExpression( '/WC_Payment_Tokens::delete\(/', (string) file_get_contents( $file->getPathname() ), $file->getPathname() );
		}
	}
}

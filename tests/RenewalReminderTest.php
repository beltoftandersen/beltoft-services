<?php
/**
 * The renewal reminder: a product-set number of days before a long renewal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\RenewalReminder;
use BeltoftServices\Resource;

/**
 * RenewalReminder and its email.
 */
class RenewalReminderTest extends TestCase {

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;

				return true;
			},
			10,
			2
		);
		as_unschedule_all_actions( RenewalReminder::HOOK );
	}

	/**
	 * The renewal reminders among the mails sent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function reminders(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'renews on' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_now' );
		as_unschedule_all_actions( RenewalReminder::HOOK );
		parent::tear_down();
	}

	/**
	 * An active subscription with a service licence and a resource.
	 *
	 * @param string $period      year or month.
	 * @param int    $next_in     Seconds until the next payment.
	 * @param string $days_meta   The product's setting ('' for none).
	 * @return \WC_Subscription
	 */
	private function subscription( string $period, int $next_in, string $days_meta ) {
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );

		if ( '' !== $days_meta ) {
			$product->update_meta_data( RenewalReminder::META, $days_meta );
			$product->save();
		}

		$known = get_user_by( 'email', 'christian.beltoft@gmail.com' );
		$user  = $known ? $known->ID : self::factory()->user->create( array( 'user_email' => 'christian.beltoft@gmail.com', 'first_name' => 'Chris' ) );
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => $period, 'billing_interval' => 1, 'start_date' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
		$this->assertNotWPError( $sub );
		$sub->add_product( $product, 1 );
		$sub->calculate_totals();
		$sub->update_status( 'active' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + $next_in ) ) );

		$row = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'email' => 'christian.beltoft@gmail.com' ) );
		Licenses::update( (int) $row['id'], array( 'subscription_id' => $sub->get_id() ) );
		Resource::create( Licenses::find( (int) $row['id'] ), $this->fake_driver(), array(), array( 'state' => 'ready' ) );

		return wcs_get_subscription( $sub->get_id() );
	}

	public function test_it_is_off_unless_the_product_asks_for_it(): void {
		$sub = $this->subscription( 'year', 100 * DAY_IN_SECONDS, '' );

		$this->assertNull( RenewalReminder::schedule( $sub ) );
		$this->assertFalse( as_next_scheduled_action( RenewalReminder::HOOK, array( $sub->get_id() ) ) );
	}

	public function test_a_yearly_renewal_is_scheduled_the_set_days_before_and_only_once(): void {
		$sub  = $this->subscription( 'year', 100 * DAY_IN_SECONDS, '30' );
		$next = (int) $sub->get_time( 'next_payment' );

		$at = RenewalReminder::schedule( $sub );
		$this->assertSame( $next - 30 * DAY_IN_SECONDS, $at );
		$this->assertSame( $at, as_next_scheduled_action( RenewalReminder::HOOK, array( $sub->get_id() ) ) );

		// Scheduling again replaces, never doubles.
		RenewalReminder::schedule( $sub );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => RenewalReminder::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}

	public function test_a_date_that_moves_reschedules_and_a_cancelled_subscription_is_not_reminded(): void {
		$sub = $this->subscription( 'year', 100 * DAY_IN_SECONDS, '30' );
		RenewalReminder::schedule( $sub );

		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ) ) );
		$sub = wcs_get_subscription( $sub->get_id() );
		$at  = RenewalReminder::schedule( $sub );
		$this->assertSame( (int) $sub->get_time( 'next_payment' ) - 30 * DAY_IN_SECONDS, $at, 'The new date, not the old one.' );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => RenewalReminder::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		$sub->update_status( 'pending-cancel' );
		$this->assertNull( RenewalReminder::schedule( wcs_get_subscription( $sub->get_id() ) ) );
		$this->assertFalse( as_next_scheduled_action( RenewalReminder::HOOK, array( $sub->get_id() ) ), 'Pending cancellation: no reminder.' );
		$this->assertFalse( RenewalReminder::run( $sub->get_id() ) );
		$this->assertCount( 0, $this->reminders() );
	}

	public function test_inside_the_window_it_goes_soon_but_not_when_the_renewal_is_a_day_away(): void {
		$sub = $this->subscription( 'year', 20 * DAY_IN_SECONDS, '30' );
		$at  = RenewalReminder::schedule( $sub );
		$this->assertNotNull( $at );
		$this->assertLessThanOrEqual( time() + 120, $at );

		$late = $this->subscription( 'year', 12 * HOUR_IN_SECONDS, '30' );
		$this->assertNull( RenewalReminder::schedule( $late ), 'Too late to act on it.' );
	}

	public function test_a_monthly_renewal_is_never_reminded_with_a_30_day_offset(): void {
		$sub = $this->subscription( 'month', 25 * DAY_IN_SECONDS, '30' );

		$this->assertNull( RenewalReminder::schedule( $sub ) );
	}

	public function test_the_email_says_plan_date_price_and_how_to_cancel_and_is_sent_once_per_renewal(): void {
		$sub  = $this->subscription( 'year', 100 * DAY_IN_SECONDS, '30' );
		$next = (int) $sub->get_time( 'next_payment' );

		// Due now: the clock is at the reminder time.
		add_filter( 'beltoft_services_now', static fn() => $next - 30 * DAY_IN_SECONDS );
		$this->assertTrue( RenewalReminder::run( $sub->get_id() ) );

		$this->assertCount( 1, $this->reminders() );
		$mail = $this->reminders()[0];
		$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $mail['message'] ) );
		$this->assertSame( 'christian.beltoft@gmail.com', is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] );
		$this->assertStringContainsString( wp_date( 'j F Y', $next ), (string) $mail['subject'], 'The date is in the subject.' );
		$this->assertStringContainsString( 'renews on', (string) $mail['subject'] );
		$this->assertStringContainsString( wp_date( 'j F Y', $next ), $text );
		$this->assertStringContainsString( 'Renewal price:', $text );
		$this->assertStringContainsString( wp_strip_all_tags( html_entity_decode( $sub->get_formatted_order_total() ) ), html_entity_decode( $text ), 'The price as WooCommerce Subscriptions shows it.' );
		$this->assertStringContainsString( 'cancel in My Account', $text );
		$this->assertStringContainsString( $sub->get_view_order_url(), (string) $mail['message'] );
		$this->assertSame( (string) $next, (string) wcs_get_subscription( $sub->get_id() )->get_meta( RenewalReminder::SENT_META ) );

		// Once per renewal: the job again, the schedule again, nothing more.
		$this->assertFalse( RenewalReminder::run( $sub->get_id() ) );
		$this->assertNull( RenewalReminder::schedule( wcs_get_subscription( $sub->get_id() ) ) );
		$this->assertCount( 1, $this->reminders() );

		// The date moves (a switch, an admin): the new renewal gets its own reminder.
		$sub = wcs_get_subscription( $sub->get_id() );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next + 365 * DAY_IN_SECONDS ) ) );
		$this->assertNotNull( RenewalReminder::schedule( wcs_get_subscription( $sub->get_id() ) ) );
	}

	public function test_a_job_that_runs_before_the_moved_date_is_due_reschedules_itself(): void {
		$sub = $this->subscription( 'year', 100 * DAY_IN_SECONDS, '30' );

		$this->assertFalse( RenewalReminder::run( $sub->get_id() ), 'Not due yet.' );
		$this->assertSame( (int) $sub->get_time( 'next_payment' ) - 30 * DAY_IN_SECONDS, as_next_scheduled_action( RenewalReminder::HOOK, array( $sub->get_id() ) ) );
		$this->assertCount( 0, $this->reminders() );
	}

	public function test_the_setting_is_cleaned_and_the_email_is_in_the_list(): void {
		$this->assertSame( '30', RenewalReminder::clean_days( ' 30 ' ) );
		$this->assertSame( '', RenewalReminder::clean_days( '0' ) );
		$this->assertSame( '', RenewalReminder::clean_days( 'abc' ) );
		$this->assertSame( '365', RenewalReminder::clean_days( '9999' ) );
		$this->assertContains( 'beltoft_services_renewal_reminder', Emails::IDS );
		$this->assertNotNull( Emails::get( 'beltoft_services_renewal_reminder' ) );
		$this->assertSame( 0, RenewalReminder::days( $this->service_product()->get_id() ) );
	}
}

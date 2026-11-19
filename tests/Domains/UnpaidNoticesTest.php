<?php
/** The unpaid domain notices N1 to N7: timing, once each, the proof, and who gets none. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Notices;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Stage;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class UnpaidNoticesTest extends TestCase {
	/** @var array<int, array<string, mixed>> Mails that would have gone out. Nothing ever leaves the test. */
	private array $mails = array();

	/** @var bool Does the (fake) mailer accept the mail? */
	private bool $mailer_ok = true;

	public function set_up(): void {
		parent::set_up();
		$this->mails     = array();
		$this->mailer_ok = true;
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return $this->mailer_ok;
			},
			10,
			2
		);
		remove_action( 'woocommerce_subscription_renewal_payment_complete', array( \BeltoftServices\Domains\Renewals::class, 'on_paid' ), 20 );
		$this->settings( array( 'domain_unpaid_notices' => 1, 'admin_email' => 'christian.beltoft@gmail.com' ) );
		update_option(
			Prices::OPTION,
			array(
				'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 43.08, 'soft_restore' => 0.0, 'soft_quarantine_days' => 40, 'quarantine_days' => 0 ),
				'dk'  => array( 'create' => 8.0, 'renew' => 8.15, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 17.56, 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
				'no'  => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
			)
		);
	}

	public function tear_down(): void {
		$flag = new \ReflectionProperty( \BeltoftLicenses\Import::class, 'running' );
		$flag->setAccessible( true );
		$flag->setValue( null, false );
		parent::tear_down();
	}

	private const EXPIRY = '2027-10-26 00:00:00';

	private function when( string $offset ): int {
		return strtotime( self::EXPIRY . ' UTC ' . $offset );
	}

	/** A domain whose renewal was not paid: subscription on hold, licence suspended. */
	private function lapsed( string $domain = 'unpaid-example.com', array $meta = array(), bool $on_hold = true ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'unpaid-' . wp_generate_password( 6, false ) ) );
		$user    = self::factory()->user->create( array( 'first_name' => 'Dana', 'user_email' => 'christian.beltoft+' . wp_generate_password( 6, false ) . '@gmail.com' ) );
		$sub     = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'email' => 'christian.beltoft@gmail.com', 'subscription_id' => $sub->get_id() ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), $meta + array( 'domain' => $domain, 'remote_id' => '777', 'expires_at' => self::EXPIRY, 'state' => 'paused', 'mode' => 'register' ) );
		$this->assertInstanceOf( Resource::class, $r );

		$sub->update_status( 'active' );

		if ( $on_hold ) {
			$sub->update_status( 'on-hold' );
			Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		} else {
			$sub->update_status( 'cancelled' );
			Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		}

		return Resource::find( $r->id() );
	}

	/** @return string[] Subjects of the customer mails. */

	/** The text of the nth customer mail (staff alerts are not counted). */
	private function body( int $index = 0 ): string {
		return (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $this->customer_mails()[ $index ]['message'] ) );
	}

	private function customer_mails(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => ! str_starts_with( (string) $m['subject'], '[' ) ) );
	}

	public function test_n1_goes_once_when_the_renewal_is_unpaid_and_the_domain_still_works(): void {
		$r = $this->lapsed();
		$this->assertSame( 'N1', Notices::process( $r, $this->when( '-29 days' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-28 days' ) ), 'Not twice.' );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-20 days' ) ) );
		$mails = $this->customer_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'Your domain unpaid-example.com must be renewed by 26 October 2027', $mails[0]['subject'] );
		$body = $this->body( 0 );
		$this->assertStringContainsString( 'We could not take payment for the renewal of unpaid-example.com. The domain expires on 26 October 2027. Until then it works as normal.', $body );
		$this->assertStringContainsString( 'If it is not paid, the domain stops working on 26 October 2027.', $body );
		$this->assertStringContainsString( 'After that you can still restore it until 5 December 2027, at the price of the renewal', $body );
		$this->assertStringContainsString( 'with no reactivation fee', $body );
		$this->assertStringContainsString( 'After 5 December 2027 the domain is released and anyone can register it.', $body );
		$this->assertStringNotContainsString( 'paused', $body );
		$this->assertStringContainsString( 'Pay the renewal', (string) $mails[0]['message'] );
	}

	public function test_the_notice_says_the_domain_is_not_paused(): void {
		$r = $this->lapsed();
		Notices::process( $r, $this->when( '-29 days' ) );
		foreach ( $this->customer_mails() as $mail ) {
			$this->assertStringNotContainsStringIgnoringCase( 'paused', (string) $mail['subject'] );
		}
	}

	public function test_n2_a_week_before_the_expiry_and_not_earlier(): void {
		$r = $this->lapsed();
		Notices::process( $r, $this->when( '-29 days' ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-8 days' ) ), 'Eight days before is too early.' );
		$this->assertSame( 'N2', Notices::process( Resource::find( $r->id() ), $this->when( '-7 days' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-6 days' ) ) );
		$subjects = array_column( $this->customer_mails(), 'subject' );
		$this->assertSame( array( 'Your domain unpaid-example.com must be renewed by 26 October 2027', 'Your domain unpaid-example.com expires in 7 days' ), $subjects );
		$this->assertStringContainsString( 'The domain expires in 7 days, on 26 October 2027.', $this->body( 1 ) );
	}

	public function test_late_discovery_sends_only_the_latest_due_notice_and_skips_the_earlier(): void {
		$r = $this->lapsed();
		$this->assertSame( 'N2', Notices::process( $r, $this->when( '-3 days' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-2 days' ) ) );
		$this->assertCount( 1, $this->customer_mails() );
		$log = Notices::log( Resource::find( $r->id() ) );
		$this->assertSame( 'skipped', $log['2027-10-26|N1']['status'] );
		$this->assertSame( 'sent', $log['2027-10-26|N2']['status'] );
	}

	public function test_n3_the_day_after_the_expiry_with_the_price_and_the_last_day_to_restore(): void {
		$r = $this->lapsed();
		Notices::process( $r, $this->when( '-29 days' ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+0 days' ) ), 'On the expiry day itself nothing is due yet.' );
		$this->assertSame( 'N3', Notices::process( Resource::find( $r->id() ), $this->when( '+1 day' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+2 days' ) ) );
		$mails = $this->customer_mails();
		$this->assertSame( 'Your domain unpaid-example.com has expired', end( $mails )['subject'] );
		$body = $this->body( count( $mails ) - 1 );
		$this->assertStringContainsString( 'unpaid-example.com expired on 26 October 2027. The website and email on this domain do not work now.', $body );
		$this->assertStringContainsString( 'You can restore it until 5 December 2027.', $body );
		$this->assertStringContainsString( 'with no reactivation fee', $body, 'A .com inside its free soft quarantine costs the renewal only.' );
		$this->assertStringContainsString( 'excluding VAT', $body );
	}

	public function test_n3_for_a_domain_that_was_never_reminded_goes_without_n1_and_n2(): void {
		$r = $this->lapsed();
		$this->assertSame( 'N3', Notices::process( $r, $this->when( '+1 day' ) ) );
		$this->assertCount( 1, $this->customer_mails() );
	}

	public function test_n4_and_n5_before_the_restore_window_ends_and_n7_at_its_end(): void {
		$r = $this->lapsed();
		$this->assertSame( 'N3', Notices::process( $r, $this->when( '+1 day' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+29 days' ) ), 'Ten days before the end of 40 days is day 30.' );
		$this->assertSame( 'N4', Notices::process( Resource::find( $r->id() ), $this->when( '+30 days' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+37 days' ) ) );
		$this->assertSame( 'N5', Notices::process( Resource::find( $r->id() ), $this->when( '+38 days' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+39 days' ) ) );
		$this->assertSame( 'N7', Notices::process( Resource::find( $r->id() ), $this->when( '+40 days' ) ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+60 days' ) ), 'Nothing after the end.' );
		$subjects = array_column( $this->customer_mails(), 'subject' );
		$this->assertSame(
			array(
				'Your domain unpaid-example.com has expired',
				'unpaid-example.com can be restored for 10 more days',
				'unpaid-example.com can be restored for 2 more days',
				'Your domain unpaid-example.com is no longer registered by us',
			),
			$subjects
		);
		$this->assertStringContainsString( 'The time to restore unpaid-example.com ended on 5 December 2027.', $this->body( 3 ) );
		$this->assertStringContainsString( 'anyone can register it now', $this->body( 3 ) );
	}

	public function test_dk_has_a_thirty_day_window_and_its_own_fee(): void {
		$r = $this->lapsed( 'unpaid-example.dk' );
		$this->assertSame( 'N3', Notices::process( $r, $this->when( '+1 day' ) ) );
		$body = $this->body( 0 );
		$this->assertStringContainsString( 'You can restore it until 25 November 2027.', $body );
		$this->assertStringContainsString( '22.83', $body, '17.56 x 1.30.' );
		$this->assertStringContainsString( '13', $body );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+19 days' ) ) );
		$this->assertSame( 'N4', Notices::process( Resource::find( $r->id() ), $this->when( '+20 days' ) ) );
		$this->assertSame( 'N5', Notices::process( Resource::find( $r->id() ), $this->when( '+28 days' ) ) );
		$this->assertSame( 'N7', Notices::process( Resource::find( $r->id() ), $this->when( '+30 days' ) ) );
	}

	public function test_an_extension_without_a_known_window_gets_n3_without_dates_and_never_n4_n5_n7(): void {
		$r = $this->lapsed( 'unpaid-example.no' );
		$this->assertSame( 'N3', Notices::process( $r, $this->when( '+1 day' ) ) );
		$this->assertStringContainsString( 'Contact us to find out if it can be restored.', $this->body( 0 ) );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '+100 days' ) ) );
		$this->assertSame( 1, count( $this->customer_mails() ) );
	}

	public function test_n4_shows_the_fee_and_total_when_a_paid_restore_applies(): void {
		update_option( Prices::OPTION, array( 'eu' => array( 'create' => 8.0, 'renew' => 4.6, 'transfer' => 8.0, 'restricted' => false, 'price' => 13, 'restore' => 15.0, 'soft_quarantine_days' => 0, 'quarantine_days' => 38 ) ) );
		$r = $this->lapsed( 'unpaid-example.eu' );
		$this->assertSame( 'N3', Notices::process( $r, $this->when( '+1 day' ) ) );
		$body = $this->body( 0 );
		$this->assertStringContainsString( 'You can restore it until 3 December 2027.', $body );
		$this->assertStringContainsString( '19.50 reactivation fee', $body, '15 x 1.30.' );
		$this->assertStringContainsString( 'renewal and', $body );
		$this->assertStringContainsString( '32.50', $body, 'The renewal 13 plus the fee 19.50.' );
	}

	public function test_a_domain_that_is_paid_gets_nothing_even_after_the_expiry_date(): void {
		$r = $this->lapsed();
		Licenses::update( (int) $r->license()['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$r = Resource::find( $r->id() );
		foreach ( array( '-29 days', '-3 days', '+1 day', '+35 days', '+41 days' ) as $offset ) {
			$this->assertSame( '', Notices::process( $r, $this->when( $offset ) ), $offset );
		}
		$this->assertSame( array(), $this->customer_mails() );
		$this->assertSame( Stage::ACTIVE, Stage::of( $r, $this->when( '+41 days' ) )['stage'] );
	}

	public function test_nothing_goes_while_the_setting_is_off_but_staff_are_still_told(): void {
		$this->settings( array( 'domain_unpaid_notices' => 0 ) );
		$r = $this->lapsed();
		$this->assertSame( '', Notices::process( $r, $this->when( '+1 day' ) ) );
		$this->assertSame( array(), $this->customer_mails() );
		$staff = array_filter( $this->mails, static fn( $m ) => str_contains( (string) $m['subject'], 'Domain expired' ) );
		$this->assertCount( 1, $staff );
		$this->assertSame( array(), Notices::log( Resource::find( $r->id() ) ) );
	}

	public function test_a_cancelled_domain_gets_no_payment_reminders_but_the_expiry_notices(): void {
		$r = $this->lapsed( 'unpaid-example.com', array(), false );
		$this->assertSame( '', Notices::process( $r, $this->when( '-29 days' ) ), 'The customer cancelled: no "we could not take payment".' );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-3 days' ) ) );
		$this->assertSame( 'N3', Notices::process( Resource::find( $r->id() ), $this->when( '+1 day' ) ) );
	}

	public function test_each_notice_is_once_per_expiry_date(): void {
		$r = $this->lapsed();
		$this->assertSame( 'N1', Notices::process( $r, $this->when( '-29 days' ) ) );
		// The domain is renewed for a year and lapses again: a new expiry date, new notices.
		$r->set( array( 'expires_at' => '2028-10-26 00:00:00' ) );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'N1', Notices::process( $r, strtotime( '2028-09-27 UTC' ) ) );
		$log = Notices::log( Resource::find( $r->id() ) );
		$this->assertArrayHasKey( '2027-10-26|N1', $log );
		$this->assertArrayHasKey( '2028-10-26|N1', $log );
		$this->assertCount( 2, $this->customer_mails() );
	}

	public function test_the_proof_of_sending_is_kept_with_time_address_and_a_history_line(): void {
		$r   = $this->lapsed();
		$now = $this->when( '-29 days' );
		Notices::process( $r, $now );
		$entry = Notices::log( Resource::find( $r->id() ) )['2027-10-26|N1'];
		$this->assertSame( $now, $entry['time'] );
		$this->assertSame( 'christian.beltoft@gmail.com', $entry['to'] );
		$this->assertTrue( $entry['sent'] );
		$this->assertSame( 'sent', $entry['status'] );
		$this->assertSame( 1, $entry['tries'] );
		$events = \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] );
		$found  = array_filter( $events, static fn( $e ) => str_contains( (string) $e['message'], 'Domain notice N1 sent to christian.beltoft@gmail.com' ) );
		$this->assertNotEmpty( $found, 'The service history names the notice and the address.' );
	}

	public function test_a_mail_that_the_mailer_refuses_is_tried_again_up_to_three_times(): void {
		$this->mailer_ok = false;
		$r = $this->lapsed();
		$this->assertSame( '', Notices::process( $r, $this->when( '-29 days' ) ) );
		$this->assertFalse( Notices::log( Resource::find( $r->id() ) )['2027-10-26|N1']['sent'] );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-28 days' ) ) );
		$this->mailer_ok = true;
		$this->assertSame( 'N1', Notices::process( Resource::find( $r->id() ), $this->when( '-27 days' ) ), 'The third try works.' );
		$this->assertSame( 3, Notices::log( Resource::find( $r->id() ) )['2027-10-26|N1']['tries'] );
		$this->assertSame( '', Notices::process( Resource::find( $r->id() ), $this->when( '-26 days' ) ) );
	}

	public function test_it_stops_after_three_failed_tries(): void {
		$this->mailer_ok = false;
		$r = $this->lapsed();
		foreach ( array( -29, -28, -27, -26, -25 ) as $day ) {
			Notices::process( Resource::find( $r->id() ), $this->when( $day . ' days' ) );
		}
		$this->assertSame( 3, Notices::log( Resource::find( $r->id() ) )['2027-10-26|N1']['tries'] );
	}

	public function test_n6_goes_once_when_a_reminded_domain_is_renewed(): void {
		$r = $this->lapsed();
		Notices::process( $r, $this->when( '-29 days' ) );
		$this->mails = array();
		$r           = Resource::find( $r->id() );
		$r->set( array( 'expires_at' => '2028-10-26 00:00:00' ) );
		Notices::on_renewed( Resource::find( $r->id() ), '2028-10-26 00:00:00' );
		Notices::on_renewed( Resource::find( $r->id() ), '2028-10-26 00:00:00' );
		$mails = $this->customer_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'The renewal of unpaid-example.com is paid', $mails[0]['subject'] );
		$this->assertStringContainsString( 'The domain is registered until 26 October 2028.', $this->body( 0 ) );
		$this->assertSame( 'sent', Notices::log( Resource::find( $r->id() ) )['2027-10-26|N6']['status'] );
	}

	public function test_n6_is_not_sent_for_a_domain_that_was_never_reminded_or_when_the_setting_is_off(): void {
		$r = $this->lapsed();
		Notices::on_renewed( $r, '2028-10-26 00:00:00' );
		$this->assertSame( array(), $this->customer_mails() );
		Notices::process( $r, $this->when( '-29 days' ) );
		$this->mails = array();
		$this->settings( array( 'domain_unpaid_notices' => 0 ) );
		Notices::on_renewed( Resource::find( $r->id() ), '2028-10-26 00:00:00' );
		$this->assertSame( array(), $this->customer_mails() );
	}

	public function test_the_paused_email_is_replaced_by_the_factual_notice_for_an_unpaid_renewal(): void {
		$r = $this->lapsed();
		$r->set( array( 'notified' => 'ready', 'paused_reason' => 'License suspended' ) );
		// The pause comes when the charge fails, 30 days before the expiry: the notice is due as of now.
		$now_expiry = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$r->set( array( 'expires_at' => $now_expiry ) );
		Emails::on_state_changed( Resource::find( $r->id() ), 'ready', 'paused' );
		$mails = $this->customer_mails();
		$this->assertCount( 1, $mails );
		$this->assertStringStartsWith( 'Your domain unpaid-example.com must be renewed by', $mails[0]['subject'] );
		$this->assertStringNotContainsString( 'paused', strtolower( $this->body( 0 ) ) );
	}

	public function test_the_generic_paused_email_stays_when_the_setting_is_off_or_the_pause_has_another_reason(): void {
		$r = $this->lapsed();
		$r->set( array( 'notified' => 'ready', 'paused_reason' => 'License suspended', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		$this->settings( array( 'domain_unpaid_notices' => 0 ) );
		Emails::on_state_changed( Resource::find( $r->id() ), 'ready', 'paused' );
		$this->assertCount( 1, $this->customer_mails() );
		$this->assertStringContainsString( 'is paused', $this->customer_mails()[0]['subject'] );

		$this->mails = array();
		$this->settings( array( 'domain_unpaid_notices' => 1 ) );
		$other = $this->lapsed( 'refunded-example.com' );
		$other->set( array( 'notified' => 'ready', 'paused_reason' => 'License revoked', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		Emails::on_state_changed( Resource::find( $other->id() ), 'ready', 'paused' );
		$this->assertCount( 1, $this->customer_mails() );
		$this->assertStringContainsString( 'is paused', $this->customer_mails()[0]['subject'], 'A refund or an admin pause keeps its own email.' );
	}

	public function test_the_staff_alerts_go_once_per_event_and_expiry(): void {
		$r = $this->lapsed();
		Notices::process( $r, $this->when( '-29 days' ) );
		Notices::process( Resource::find( $r->id() ), $this->when( '-28 days' ) );
		Notices::process( Resource::find( $r->id() ), $this->when( '+1 day' ) );
		Notices::process( Resource::find( $r->id() ), $this->when( '+2 days' ) );
		Notices::process( Resource::find( $r->id() ), $this->when( '+31 days' ) );
		Notices::process( Resource::find( $r->id() ), $this->when( '+32 days' ) );
		Notices::process( Resource::find( $r->id() ), $this->when( '+41 days' ) );
		$staff = array_map( static fn( $m ) => (string) $m['subject'], array_filter( $this->mails, static fn( $m ) => str_starts_with( (string) $m['subject'], '[' ) ) );
		$count = static fn( string $needle ) => count( array_filter( $staff, static fn( $s ) => str_contains( $s, $needle ) ) );
		$this->assertSame( 1, $count( 'Domain renewal not paid' ) );
		$this->assertSame( 1, $count( 'Domain expired' ) );
		$this->assertSame( 1, $count( 'restore window ends soon' ) );
		$this->assertSame( 1, $count( 'restore window ended' ) );
	}

	public function test_a_window_nobody_knows_alerts_staff(): void {
		$r = $this->lapsed( 'unpaid-example.no' );
		Notices::process( $r, $this->when( '+1 day' ) );
		$this->assertCount( 1, array_filter( $this->mails, static fn( $m ) => str_contains( (string) $m['subject'], 'restore window unknown' ) ) );
	}

	public function test_the_daily_job_goes_through_the_live_domains(): void {
		$r = $this->lapsed( 'daily-example.com', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 1, Notices::run() );
		$this->assertSame( 'sent', Notices::log( Resource::find( $r->id() ) )[ gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) . '|N3' ]['status'] );
		$this->assertSame( 0, Notices::run(), 'A second run sends nothing.' );
		$this->assertTrue( has_action( Notices::HOOK ) !== false );
	}

	public function test_the_job_is_scheduled_with_the_other_recurring_jobs(): void {
		\BeltoftServices\Domains\Unpaid::schedule();
		$this->assertNotFalse( as_has_scheduled_action( Notices::HOOK, array(), 'beltoft-services' ) );
		as_unschedule_all_actions( Notices::HOOK, array(), 'beltoft-services' );
	}

	public function test_the_customer_texts_have_no_dash_or_middle_dot(): void {
		$r     = $this->lapsed();
		$stage = Stage::of( $r, $this->when( '+31 days' ) );
		foreach ( \BeltoftServices\Emails\DomainUnpaid::KINDS as $kind ) {
			$facts = Notices::facts( $r, $kind, $stage, $this->when( '+31 days' ) );
			$text  = implode( ' ', array_merge( \BeltoftServices\Emails\DomainUnpaid::titles( $facts, 'x.com' ), \BeltoftServices\Emails\DomainUnpaid::lines( $facts, 'x.com' ), \BeltoftServices\Emails\DomainUnpaid::link( $facts, 'https://example.org/a' ) ) );
			$this->assertDoesNotMatchRegularExpression( '/[\x{2014}\x{2013}\x{00B7}]/u', $text, $kind );
			$this->assertNotSame( '', trim( $text ), $kind );
		}
	}

	public function test_every_notice_renders_in_html_and_plain(): void {
		$r     = $this->lapsed();
		$email = Emails::get( 'beltoft_services_domain_unpaid' );
		foreach ( \BeltoftServices\Emails\DomainUnpaid::KINDS as $kind ) {
			$stage = Stage::of( $r, $this->when( '+31 days' ) );
			$email->trigger( $r, array( 'unpaid' => Notices::facts( $r, $kind, $stage, $this->when( '+31 days' ) ) ) );
			$this->assertStringContainsString( 'unpaid-example.com', (string) $email->get_content_html(), $kind );
			$this->assertStringContainsString( 'unpaid-example.com', (string) $email->get_content_plain(), $kind );
		}
	}
}

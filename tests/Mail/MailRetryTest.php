<?php
/**
 * A domain whose ownership proof was not found in time: Try again for 30 days without a new order, then a new order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailRetry;
use BeltoftServices\Mail\MailView;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';
require_once __DIR__ . '/DnsFixtures.php';

final class MailRetryTest extends MailTestCase {

	use DnsFixtures;

	private const BASE = '/beltoft-services/v1/resources/';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		parent::tear_down();
	}

	/**
	 * A domain at another provider whose 14 days for the proof ran out.
	 *
	 * @return array{0: Resource, 1: int}
	 */
	private function failed_proof( string $name ): array {
		$user = self::factory()->user->create();
		$this->proof['check_txt'] = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );
		$r = $this->mail_resource( $name, array( 'mail_proof_until' => time() - 60 ), array(), $user );
		Jobs::enqueue( $r->id() );
		$this->run_jobs( 3 );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'failed', $r->get( 'state' ) );
		$this->assertGreaterThan( 0, (int) $r->get( 'mail_proof_failed_at' ) );

		return array( $r, $user );
	}

	private function call( string $method, string $path, int $user ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, self::BASE . $path );

		return rest_get_server()->dispatch( $request );
	}

	public function test_a_failed_proof_offers_try_again_with_the_proof_record_for_30_days(): void {
		list( $r, $user ) = $this->failed_proof( 'retry.example' );

		$this->assertTrue( MailRetry::open( $r ) );
		$view = MailView::data( $r, array() );

		$this->assertSame( 'failed', $view['kind'] );
		$this->assertSame( 'open', $view['dns']['retry']['state'] );
		$this->assertSame( wp_date( get_option( 'date_format' ), (int) $r->get( 'mail_proof_failed_at' ) + 30 * DAY_IN_SECONDS ), $view['dns']['retry']['until'] );
		$this->assertSame( 'TXT', $view['dns']['proof']['type'] );
		$this->assertNotNull( $view['dns']['guide'], 'The proof record is shown again.' );
		$this->assertStringContainsString( 'data-mail-retry', \BeltoftServices\Domains\DnsRecordsGuide::html( $view['dns']['guide'] ) );
		$this->assertStringContainsString( 'Try again', \BeltoftServices\Domains\DnsRecordsGuide::html( $view['dns']['guide'] ) );
		$this->assertSame( $user, (int) $r->license()['user_id'] );
	}

	public function test_try_again_runs_the_setup_again_and_the_domain_is_ready_when_the_proof_is_found(): void {
		list( $r, $user ) = $this->failed_proof( 'again.example' );
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );

		$other = $this->call( 'POST', $r->id() . '/mail/retry', self::factory()->user->create() );
		$this->assertSame( 403, $other->get_status(), 'Only the owner.' );

		$reply = $this->call( 'POST', $r->id() . '/mail/retry', $user );
		$this->assertSame( 202, $reply->get_status() );
		$this->assertSame( 'provisioning', Resource::find( $r->id() )->get( 'state' ), 'Back in set up at once.' );

		$this->run_jobs( 5 );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'ready', $r->get( 'state' ) );
		$this->assertSame( 'external', $r->get( 'mail_mode' ) );
		$this->assertContains( 'proof', (array) $r->get( 'steps' ) );
	}

	public function test_try_again_while_the_proof_is_still_missing_waits_14_more_days_and_fails_again_after_them(): void {
		list( $r, $user ) = $this->failed_proof( 'still.example' );

		$this->assertSame( 202, $this->call( 'POST', $r->id() . '/mail/retry', $user )->get_status() );
		$this->run_jobs( 3 );
		$r = Resource::find( $r->id() );

		$this->assertNotSame( 'failed', $r->get( 'state' ), 'It waits for the proof again.' );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, (int) $r->get( 'mail_proof_until' ), 120 );
	}

	public function test_after_30_days_there_is_no_try_again_and_the_page_says_to_order_again(): void {
		list( $r, $user ) = $this->failed_proof( 'late.example' );
		$r->set( array( 'mail_proof_failed_at' => time() - 31 * DAY_IN_SECONDS ) );
		$r = Resource::find( $r->id() );

		$this->assertFalse( MailRetry::open( $r ) );
		$this->assertSame( 'expired', MailView::data( $r, array() )['dns']['retry']['state'] );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail/retry', $user )->get_status() );
		$this->assertSame( 'failed', Resource::find( $r->id() )->get( 'state' ) );
	}

	public function test_the_days_are_a_setting_and_zero_turns_it_off(): void {
		list( $r ) = $this->failed_proof( 'off.example' );
		$this->settings( array( 'mail_retry_days' => 0 ) );
		$this->assertFalse( MailRetry::open( Resource::find( $r->id() ) ) );

		$this->settings( array( 'mail_retry_days' => 60 ) );
		$r->set( array( 'mail_proof_failed_at' => time() - 45 * DAY_IN_SECONDS ) );
		$this->assertTrue( MailRetry::open( Resource::find( $r->id() ) ) );
	}

	public function test_a_failure_that_is_not_the_proof_has_no_try_again_and_keeps_the_support_text(): void {
		$user = self::factory()->user->create();
		$r    = $this->mail_resource( 'system.example', array(), array(), $user );
		$r->set( array( 'state' => 'failed', 'desired' => 'ready', 'failed_op' => 'provision', 'attempts' => 3 ) );
		$r = Resource::find( $r->id() );

		$this->assertFalse( MailRetry::open( $r ) );
		$this->assertSame( 'none', MailView::data( $r, array() )['dns']['retry']['state'] );
		$this->assertSame( 409, $this->call( 'POST', $r->id() . '/mail/retry', $user )->get_status() );
	}

	public function test_the_name_claim_is_not_weakened_a_name_proven_by_another_service_refuses_the_retry(): void {
		list( $r, $user ) = $this->failed_proof( 'claimed.example' );
		$other = $this->ready_mail( 'claimed.example' );
		$this->assertTrue( MailDomain::taken( 'claimed.example', $r->id() ) );

		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$this->call( 'POST', $r->id() . '/mail/retry', $user );
		$this->run_jobs( 5 );

		$this->assertNotSame( 'ready', Resource::find( $r->id() )->get( 'state' ), 'The first service that proved the name keeps it.' );
		$this->assertNotNull( $other );
	}
}

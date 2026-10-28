<?php
/** Daily release of DNS-only names. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsReleaseJob;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Tests\TestCase;

class DnsReleaseJobTest extends TestCase {
	private array $result = array();
	private array $calls = array();
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'admin_email' => 'admin@example.com' ) );
		$this->result = array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => false );
		$this->calls = array();
		$this->mails = array();
		delete_option( DnsReleaseJob::OPTION_ALERTED );
		delete_option( DnsReleaseJob::OPTION_CURSOR );
		add_filter(
			'beltoft_services_dns_proof_result',
			function ( $pre, $call, $args ) {
				if ( 'delegation' !== $call ) { return $pre; }
				$this->calls[] = $args[0];
				return $this->result;
			},
			10,
			3
		);
		add_filter( 'pre_wp_mail', function ( $pre, $atts ) { $this->mails[] = $atts; return true; }, 10, 2 );
	}

	private function pending( string $domain, int $user = 3 ): void {
		$this->assertTrue( DnsAssignments::reserve( $user, 1, $domain ) );
		$this->assertTrue( DnsAssignments::activate( $domain, $user, 1, 1 ) );
		$this->assertTrue( DnsAssignments::release_pending( $domain ) );
	}

	private function mark( string $domain, array $data ): void {
		global $wpdb;
		$wpdb->update( DnsSchema::tables()['assignments'], $data, array( 'domain' => $domain ) );
	}

	private function hours_ago( int $hours ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
	}

	public function test_two_clear_checks_a_day_apart_release_the_name(): void {
		$this->pending( 'free-example.com' );
		DnsReleaseJob::run();
		$row = DnsAssignments::get( 'free-example.com' );
		$this->assertSame( 'release_pending', $row['status'] );
		$this->assertNotEmpty( $row['reserved_until'] );
		DnsReleaseJob::run();
		$this->assertNotNull( DnsAssignments::get( 'free-example.com' ), 'a second check the same day does not release' );
		$this->mark( 'free-example.com', array( 'reserved_until' => $this->hours_ago( 25 ) ) );
		DnsReleaseJob::run();
		$this->assertNull( DnsAssignments::get( 'free-example.com' ) );
		$log = get_option( DnsReleaseJob::OPTION_LOG );
		$this->assertSame( 'free-example.com', $log[0]['domain'] );
	}

	public function test_not_delegated_counts_as_clear(): void {
		$this->result = array( 'status' => 'support', 'reason' => 'not_delegated', 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => false );
		$this->pending( 'nodeleg-example.com' );
		DnsReleaseJob::run();
		$this->assertNotEmpty( DnsAssignments::get( 'nodeleg-example.com' )['reserved_until'] );
	}

	public function test_delegated_to_us_resets_the_clear_time(): void {
		$this->pending( 'ours-example.com' );
		$this->mark( 'ours-example.com', array( 'reserved_until' => $this->hours_ago( 30 ) ) );
		$this->result = array( 'status' => 'support', 'reason' => 'delegated_to_us', 'ns' => array( 'ns1.example.net' ), 'ips' => array(), 'ds' => null, 'ours' => true );
		DnsReleaseJob::run();
		$row = DnsAssignments::get( 'ours-example.com' );
		$this->assertSame( 'release_pending', $row['status'] );
		$this->assertNull( $row['reserved_until'] );
	}

	public function test_unknown_and_other_results_keep_the_row_as_it_is(): void {
		$this->pending( 'unknown-example.com' );
		$stamp = $this->hours_ago( 30 );
		$this->mark( 'unknown-example.com', array( 'reserved_until' => $stamp ) );
		foreach ( array( array( 'unknown', 'parent_unreachable' ), array( 'support', 'too_many_ns' ), array( 'weird', 'x' ) ) as $case ) {
			$this->result = array( 'status' => $case[0], 'reason' => $case[1], 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => false );
			DnsReleaseJob::run();
			$row = DnsAssignments::get( 'unknown-example.com' );
			$this->assertSame( 'release_pending', $row['status'] );
			$this->assertSame( $stamp, $row['reserved_until'] );
		}
	}

	public function test_a_row_waiting_over_30_days_mails_once(): void {
		$this->result['status'] = 'unknown';
		$this->pending( 'stale-example.com', 42 );
		$this->mark( 'stale-example.com', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ) );
		DnsReleaseJob::run();
		DnsReleaseJob::run();
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'stale-example.com', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Customer: 42', $this->mails[0]['message'] );
	}

	public function test_a_young_row_sends_no_mail(): void {
		$this->result['status'] = 'unknown';
		$this->pending( 'young-example.com' );
		DnsReleaseJob::run();
		$this->assertSame( array(), $this->mails );
	}

	public function test_at_most_50_rows_per_run_and_the_next_run_continues(): void {
		for ( $i = 10; $i < 62; $i++ ) { $this->pending( 'bulk' . $i . '-example.com' ); }
		DnsReleaseJob::run();
		$this->assertCount( 50, $this->calls );
		$first = $this->calls;
		$this->calls = array();
		DnsReleaseJob::run();
		$this->assertCount( 50, $this->calls );
		$this->assertContains( 'bulk60-example.com', $this->calls );
		$this->assertContains( 'bulk61-example.com', $this->calls );
		$this->assertNotContains( 'bulk61-example.com', $first );
	}

	public function test_nothing_runs_while_the_feature_is_off(): void {
		$this->pending( 'off-example.com' );
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		DnsReleaseJob::run();
		$this->assertSame( array(), $this->calls );
	}

	public function test_schedule_and_unschedule(): void {
		DnsReleaseJob::schedule();
		$this->assertTrue( as_has_scheduled_action( DnsReleaseJob::HOOK, array(), \BeltoftServices\Jobs::GROUP ) );
		DnsReleaseJob::unschedule();
		$this->assertFalse( as_has_scheduled_action( DnsReleaseJob::HOOK, array(), \BeltoftServices\Jobs::GROUP ) );
	}
}

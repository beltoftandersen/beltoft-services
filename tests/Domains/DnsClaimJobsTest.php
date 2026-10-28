<?php
/** DNS claim jobs. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaimJobs;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Jobs;
use BeltoftServices\Tests\TestCase;

class DnsClaimJobsTest extends TestCase {
	private array $proof = array();
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 1 ) );
		$this->proof = array();
		$this->mails = array();
		add_filter(
			'beltoft_services_dns_proof_result',
			function ( $pre, $call ) {
				return $this->proof[ $call ] ?? $pre;
			},
			10,
			2
		);
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

	private function claim( string $status = 'checking', string $domain = 'example.com' ): array {
		$claim = DnsClaims::create( 7, $domain );
		if ( 'pending' !== $status ) {
			DnsClaims::set_status( $claim['id'], $status );
		}
		return (array) DnsClaims::get( $claim['id'] );
	}

	private function delegation( array $over = array() ): array {
		return array_merge( array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.host.example', 'ns2.host.example' ), 'ips' => array(), 'ds' => true, 'ours' => false ), $over );
	}

	private function expire( int $id ): void {
		global $wpdb;
		$wpdb->update( DnsSchema::tables()['claims'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $id ) );
	}

	public function test_found_verifies_with_the_delegation_fields(): void {
		$claim               = $this->claim();
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'found', 'servers' => array( 'ns1.host.example' => 'found', 'ns2.host.example' => 'found' ), 'reason' => 'found' );

		DnsClaimJobs::check( $claim['id'] );

		$after = DnsClaims::get( $claim['id'] );
		$this->assertSame( 'verified', $after['status'] );
		$this->assertSame( array( 'ns1.host.example', 'ns2.host.example' ), $after['parent_ns'] );
		$this->assertSame( 1, $after['ds_present'] );
		$this->assertSame( 0, $after['check_failures'] );
		$this->assertSame( 'found', $after['last_result']['reason'] );
		$this->assertNotEmpty( $after['verified_at'] );
		$this->assertNotEmpty( $after['checked_at'] );
		$this->assertSame( array(), $this->mails );
	}

	public function test_missing_goes_back_to_pending_with_the_server_states(): void {
		$claim = $this->claim();
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'missing', 'servers' => array( 'ns1.host.example' => 'found', 'ns2.host.example' => 'missing' ), 'reason' => 'not_found' );

		DnsClaimJobs::check( $claim['id'] );

		$after = DnsClaims::get( $claim['id'] );
		$this->assertSame( 'pending', $after['status'] );
		$this->assertSame( array( 'ns1.host.example' => 'found', 'ns2.host.example' => 'missing' ), $after['last_result']['servers'] );
		$this->assertNotEmpty( $after['checked_at'] );
		$this->assertSame( 0, $after['check_failures'] );
	}

	public function test_three_unknown_results_in_a_row_need_support_and_send_one_mail(): void {
		$claim = $this->claim();
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'unknown', 'servers' => array( 'ns1.host.example' => 'timeout' ), 'reason' => 'incomplete' );

		for ( $i = 1; $i <= 2; $i++ ) {
			DnsClaimJobs::check( $claim['id'] );
			$after = DnsClaims::get( $claim['id'] );
			$this->assertSame( 'pending', $after['status'] );
			$this->assertSame( $i, $after['check_failures'] );
			DnsClaims::set_status( $claim['id'], 'checking' );
		}

		$this->assertSame( array(), $this->mails );
		DnsClaimJobs::check( $claim['id'] );

		$after = DnsClaims::get( $claim['id'] );
		$this->assertSame( 'support', $after['status'] );
		$this->assertSame( 'servers_not_answering', $after['last_result']['reason'] );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'example.com', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'servers_not_answering', $this->mails[0]['message'] );
	}

	public function test_a_support_reason_sends_a_mail_without_the_token(): void {
		$claim = $this->claim();
		$this->proof['delegation'] = $this->delegation( array( 'status' => 'support', 'reason' => 'delegated_to_us', 'ours' => true ) );

		DnsClaimJobs::check( $claim['id'] );

		$this->assertSame( 'support', DnsClaims::get( $claim['id'] )['status'] );
		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0]['subject'] . $this->mails[0]['message'];
		$this->assertStringContainsString( 'delegated_to_us', $mail );
		$this->assertStringContainsString( 'example.com', $mail );
		$this->assertStringContainsString( 'Customer: 7', $mail );
		$this->assertStringNotContainsString( $claim['token'], $mail );
		$this->assertStringNotContainsString( 'beltoft-verify', $mail );
	}

	public function test_the_admin_email_setting_wins_over_the_site_address(): void {
		$this->settings( array( 'admin_email' => 'ops@example.org' ) );
		$claim = $this->claim();
		$this->proof['delegation'] = $this->delegation( array( 'status' => 'support', 'reason' => 'not_delegated' ) );

		DnsClaimJobs::check( $claim['id'] );

		$this->assertSame( 'ops@example.org', $this->mails[0]['to'] );
	}

	public function test_an_expired_claim_is_never_verified(): void {
		$claim = $this->claim();
		$this->expire( $claim['id'] );
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );

		DnsClaimJobs::check( $claim['id'] );

		$this->assertSame( 'expired', DnsClaims::get( $claim['id'] )['status'] );
	}

	public function test_a_cancelled_or_pending_claim_is_left_alone(): void {
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );

		foreach ( array( 'cancelled', 'pending', 'expired', 'support' ) as $status ) {
			$claim = $this->claim( $status, $status . '.example.com' );
			DnsClaimJobs::check( $claim['id'] );
			$this->assertSame( $status, DnsClaims::get( $claim['id'] )['status'], $status );
		}
	}

	public function test_a_claim_cancelled_while_the_servers_were_asked_stays_cancelled(): void {
		$claim = $this->claim();
		$this->proof['delegation'] = $this->delegation();
		add_filter(
			'beltoft_services_dns_proof_result',
			function ( $pre, $call ) use ( $claim ) {
				if ( 'check_txt' === $call ) {
					DnsClaims::set_status( $claim['id'], 'cancelled' );
					return array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
				}
				return $pre;
			},
			20,
			2
		);

		DnsClaimJobs::check( $claim['id'] );

		$this->assertSame( 'cancelled', DnsClaims::get( $claim['id'] )['status'] );
	}

	public function test_a_verified_claim_checked_again_stays_verified_on_found_and_keeps_its_data(): void {
		$claim = $this->claim( 'verified' );
		DnsClaims::set_status( $claim['id'], 'verified', array( 'last_result' => array( 'zone_file' => array( 'records' => array() ), 'scan' => array( array( 'name' => '@' ) ) ) ) );
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );

		DnsClaimJobs::check( $claim['id'] );

		$after = DnsClaims::get( $claim['id'] );
		$this->assertSame( 'verified', $after['status'] );
		$this->assertArrayHasKey( 'zone_file', $after['last_result'] );
		$this->assertArrayHasKey( 'scan', $after['last_result'] );
	}

	public function test_transition_refuses_to_revive_a_finished_claim(): void {
		foreach ( array( 'cancelled', 'expired' ) as $status ) {
			$claim = $this->claim( $status, $status . '.example.com' );
			$this->assertFalse( DnsClaimJobs::transition( $claim['id'], array( 'checking' ), 'verified' ) );
			$this->assertFalse( DnsClaimJobs::transition( $claim['id'], array( $status ), 'verified' ) );
			$this->assertSame( $status, DnsClaims::get( $claim['id'] )['status'] );
		}
		$this->assertFalse( DnsClaimJobs::transition( 999999, array( 'checking' ), 'verified' ) );
	}

	public function test_scan_stores_the_rows_apart_from_the_draft(): void {
		$claim = $this->claim( 'verified' );
		$rows  = array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.1' ) );
		$this->proof['delegation'] = $this->delegation();
		$this->proof['scan']       = array( 'records' => $rows, 'server' => 'ns1.host.example', 'queries' => 4 );

		DnsClaimJobs::scan( $claim['id'] );

		$after = DnsClaims::get( $claim['id'] );
		$this->assertSame( $rows, $after['last_result']['scan'] );
		$this->assertSame( array(), $after['draft_records'] );
		$this->assertSame( 'verified', $after['status'] );
	}

	public function test_scan_runs_only_for_a_verified_claim(): void {
		$this->proof['delegation'] = $this->delegation();
		$this->proof['scan']       = array( 'records' => array( array( 'name' => '@' ) ), 'server' => '', 'queries' => 1 );
		foreach ( array( 'pending', 'checking', 'cancelled' ) as $status ) {
			$claim = $this->claim( $status, $status . '.example.com' );
			DnsClaimJobs::scan( $claim['id'] );
			$this->assertArrayNotHasKey( 'scan', DnsClaims::get( $claim['id'] )['last_result'], $status );
		}
	}

	public function test_nothing_runs_while_the_feature_is_off(): void {
		$claim = $this->claim();
		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		$this->proof['delegation'] = $this->delegation();
		$this->proof['check_txt']  = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		DnsClaimJobs::check( $claim['id'] );
		$this->assertSame( 'checking', DnsClaims::get( $claim['id'] )['status'] );

		$old = $this->claim( 'pending', 'old.example.com' );
		$this->expire( $old['id'] );
		DnsClaimJobs::cleanup();
		$this->assertSame( 'pending', DnsClaims::get( $old['id'] )['status'] );
	}

	public function test_cleanup_expires_purges_and_never_touches_held_assignments(): void {
		global $wpdb;
		$old = $this->claim( 'pending', 'old.example.com' );
		$this->expire( $old['id'] );
		$gone = $this->claim( 'cancelled', 'gone.example.com' );
		$wpdb->update( DnsSchema::tables()['claims'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ), array( 'id' => $gone['id'] ) );

		$this->assertTrue( DnsAssignments::reserve( 7, $old['id'], 'lapsed.example.com' ) );
		$this->assertTrue( DnsAssignments::reserve( 7, $old['id'], 'held.example.com' ) );
		$this->assertTrue( DnsAssignments::reserve( 7, $old['id'], 'leaving.example.com' ) );
		$table = DnsSchema::tables()['assignments'];
		$past  = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$wpdb->update( $table, array( 'reserved_until' => $past ), array( 'domain' => 'lapsed.example.com' ) );
		$wpdb->update( $table, array( 'status' => 'active', 'reserved_until' => $past ), array( 'domain' => 'held.example.com' ) );
		$wpdb->update( $table, array( 'status' => 'release_pending', 'reserved_until' => $past ), array( 'domain' => 'leaving.example.com' ) );

		DnsClaimJobs::cleanup();

		$this->assertSame( 'expired', DnsClaims::get( $old['id'] )['status'] );
		$this->assertNull( DnsClaims::get( $gone['id'] ) );
		$this->assertNull( DnsAssignments::get( 'lapsed.example.com' ) );
		$this->assertSame( 'active', DnsAssignments::get( 'held.example.com' )['status'] );
		$this->assertSame( 'release_pending', DnsAssignments::get( 'leaving.example.com' )['status'] );
	}

	public function test_hooks_and_the_daily_schedule(): void {
		$this->assertNotFalse( has_action( DnsClaimJobs::CHECK, array( DnsClaimJobs::class, 'check' ) ) );
		$this->assertNotFalse( has_action( DnsClaimJobs::SCAN, array( DnsClaimJobs::class, 'scan' ) ) );
		$this->assertNotFalse( has_action( DnsClaimJobs::CLEANUP, array( DnsClaimJobs::class, 'cleanup' ) ) );
		as_unschedule_all_actions( DnsClaimJobs::CLEANUP, array(), Jobs::GROUP );
		DnsClaimJobs::schedule();
		DnsClaimJobs::schedule();
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => DnsClaimJobs::CLEANUP, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );
		DnsClaimJobs::unschedule();
		$this->assertCount( 0, as_get_scheduled_actions( array( 'hook' => DnsClaimJobs::CLEANUP, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );
	}
}

<?php
/**
 * The mail driver: provision, pause, resume, plan change and delete, against the fake mailcow and FakeDns.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailAccess;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Product;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailDriverTest extends MailTestCase {

	private function driver(): MailDriver {
		return Registry::get( 'mail' );
	}

	public function test_it_is_registered_with_the_four_plan_fields(): void {
		$driver = $this->driver();
		$this->assertInstanceOf( MailDriver::class, $driver );
		$this->assertSame( 'mail', $driver->id() );
		$this->assertSame( 'mail', $driver->kind() );
		$this->assertSame( array( 'mailboxes', 'quota_mb', 'aliases', 'domains' ), array_keys( $driver->plan_fields() ) );
		foreach ( $driver->plan_fields() as $field ) {
			$this->assertTrue( $field[4], 'A variation can set it.' );
		}

		$product = $this->service_product( 'mail', array( 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 20, 'domains' => 1 ), array( 'slug' => 'mail-fields', 'variations' => array( array(), array( 'mailboxes' => 10, 'quota_mb' => 20480, 'aliases' => 200, 'domains' => 10 ) ) ) );
		$ids     = $product->get_children();
		$this->assertSame( array( 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 20, 'domains' => 1 ), Product::plan( $product->get_id(), $ids[0] ) );
		$this->assertSame( array( 'mailboxes' => 10, 'quota_mb' => 20480, 'aliases' => 200, 'domains' => 10 ), Product::plan( $product->get_id(), $ids[1] ) );
		$this->assertSame( '1 of 3 email domains', $driver->unit_label( 1, 3 ) );
	}

	public function test_a_cancelled_domain_is_kept_30_days_unless_the_product_says_otherwise(): void {
		$product = $this->service_product( 'mail', self::PLAN, array( 'slug' => 'mail-ret' ) );
		$this->assertSame( 30, Product::retention_days( $product->get_id() ) );
		$other = $this->service_product( 'mail', self::PLAN, array( 'slug' => 'mail-ret0', 'retention' => '0' ) );
		$this->assertSame( 0, Product::retention_days( $other->get_id() ) );
	}

	// -- Names -------------------------------------------------------------------------------------

	public function test_unusable_reserved_and_taken_names_are_refused_before_anything_is_made(): void {
		foreach ( array( 'not a domain', 'reserved.example', 'mail.reserved.example', 'OWNER.example', 'mail.example.net' ) as $name ) {
			$r = $this->mail_resource( $name );
			$this->assertTrue( $this->driver()->provision( $r )->is_failed(), $name );
		}
		$this->assertSame( array(), $this->mailcow->calls(), 'The mail server was not called.' );

		$ready = $this->ready_mail( 'taken.example' );
		$dup   = $this->mail_resource( 'taken.example' );
		$res   = $this->driver()->provision( $dup );
		$this->assertTrue( $res->is_failed() );
		$this->assertSame( 'This domain already has email here.', $res->error() );
		$this->assertNotNull( $ready );
	}

	public function test_nothing_is_provisioned_until_the_mail_host_is_set(): void {
		$this->settings( array( 'mail_host' => '' ) );
		$res = $this->driver()->provision( $this->mail_resource( 'a.example' ) );
		$this->assertTrue( $res->is_retry() );
		$this->assertSame( array(), $this->mailcow->calls() );
	}

	// -- An external domain ------------------------------------------------------------------------

	public function test_an_external_domain_waits_for_the_proof_and_adds_nothing_before_it(): void {
		$r   = $this->mail_resource( 'customer.example' );
		$res = $this->driver()->provision( $r );

		$this->assertTrue( $res->is_retry() );
		$this->assertGreaterThan( time(), $res->wait_until() );
		$this->assertSame( array(), $this->mailcow->calls(), 'No mailcow call before the proof.' );

		$fresh = Resource::find( $r->id() );
		$token = $fresh->get( MailDomain::META_TOKEN );
		$this->assertStringStartsWith( 'beltoft-', $token );
		$this->assertSame( array( 'name' => '_beltoft-verify.customer.example', 'type' => 'TXT', 'value' => $token ), MailDomain::proof_record( 'customer.example', $token ) );

		$this->driver()->provision( $fresh );
		$this->assertSame( $token, Resource::find( $r->id() )->get( MailDomain::META_TOKEN ), 'The token stays the same.' );
	}

	public function test_a_domain_that_cannot_be_proven_fails_and_one_not_yet_delegated_waits(): void {
		$this->proof['delegation'] = array( 'status' => 'support', 'reason' => 'delegated_to_us', 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => true );
		$res                       = $this->driver()->provision( $this->mail_resource( 'ours-elsewhere.example' ) );
		$this->assertTrue( $res->is_failed() );

		$this->proof['delegation'] = array( 'status' => 'support', 'reason' => 'not_delegated', 'ns' => array(), 'ips' => array(), 'ds' => null, 'ours' => false );
		$this->assertTrue( $this->driver()->provision( $this->mail_resource( 'new.example' ) )->is_retry() );

		$this->proof['delegation'] = array( 'status' => 'unknown', 'reason' => 'parent_unreachable' );
		$this->assertTrue( $this->driver()->provision( $this->mail_resource( 'later.example' ) )->is_retry() );
		$this->assertSame( array(), $this->mailcow->calls() );
	}

	public function test_the_proof_runs_out_after_14_days(): void {
		$r = $this->mail_resource( 'late.example', array( 'mail_proof_until' => time() - 1 ) );
		$this->assertTrue( $this->driver()->provision( $r )->is_failed() );
	}

	public function test_with_the_proof_the_domain_is_added_with_the_plan_limits_and_a_dkim_key(): void {
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$r   = $this->mail_resource( 'customer.example' );
		$res = $this->driver()->provision( $r );

		$this->assertTrue( $res->is_done(), $res->error() );
		$domain = $this->mailcow->domain( 'customer.example' );
		$this->assertSame( '5', $domain['mailboxes'] );
		$this->assertSame( '50', $domain['aliases'] );
		$this->assertSame( '10240', $domain['maxquota'] );
		$this->assertSame( '10240', $domain['defquota'] );
		$this->assertSame( '51200', $domain['quota'], 'Room for every mailbox at full size.' );
		$this->assertSame( 'beltoft-services:' . $r->get( 'uuid' ), $domain['description'] );
		$this->assertCount( 1, $this->mailcow->calls_of( 'add_dkim' ) );

		$meta = $res->meta();
		$this->assertSame( 'external', Resource::find( $r->id() )->get( 'mail_mode' ) );
		$this->assertStringStartsWith( 'v=DKIM1;', Resource::find( $r->id() )->get( 'mail_dkim' ) );
		$this->assertSame( 'waiting', $meta['mail_dns'] );
		$this->assertSame( array( 'mx', 'spf', 'dkim', 'dmarc', 'autoconfig', 'autodiscover' ), array_column( $meta['mail_records'], 'key' ) );
		$this->assertSame( array( 'missing' ), array_values( array_unique( array_column( $meta['mail_records'], 'status' ) ) ) );
		$this->assertSame( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ), Resource::find( $r->id() )->get( 'mail_limits' ) );
		$this->assertSame( 1, $this->pending( $r->id() ), 'A re-check is queued.' );
	}

	public function test_provision_can_run_again_after_any_step_without_repeating_it(): void {
		$r = $this->ready_mail( 'again.example' );
		$before = count( $this->mailcow->calls_of( 'add_domain' ) );
		$this->assertTrue( $this->driver()->provision( Resource::find( $r->id() ) )->is_done() );
		$this->assertSame( $before, count( $this->mailcow->calls_of( 'add_domain' ) ) );

		// A run that died after add/domain and before it stored the step: the domain is there with our marker.
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$crashed = $this->mail_resource( 'crashed.example' );
		$this->mailcow->add_domain( array( 'domain' => 'crashed.example', 'description' => 'beltoft-services:' . $crashed->get( 'uuid' ) ) );
		$this->assertTrue( $this->driver()->provision( $crashed )->is_done() );
	}

	public function test_a_domain_that_is_already_on_the_mail_server_is_never_taken_over(): void {
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$this->mailcow->add_domain( array( 'domain' => 'foreign.example', 'description' => 'someone else' ) );
		$r   = $this->mail_resource( 'foreign.example' );
		$res = $this->driver()->provision( $r );

		$this->assertTrue( $res->is_failed() );
		$this->assertSame( array(), $this->mailcow->calls_of( 'edit_domain' ) );
		$this->assertSame( array(), $this->mailcow->calls_of( 'add_dkim' ) );
		$this->assertCount( 1, $this->mails, 'The admin is told.' );
		$this->assertSame( 'someone else', $this->mailcow->domain( 'foreign.example' )['description'] );
	}

	public function test_a_mail_server_that_does_not_answer_is_retried_and_a_refusal_is_final(): void {
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$this->mailcow->fail_next( 'add_domain', new \WP_Error( 'mailcow_transport', 'timeout' ) );
		$this->assertTrue( $this->driver()->provision( $this->mail_resource( 'slow.example' ) )->is_retry() );

		$this->mailcow->fail_next( 'add_domain', new \WP_Error( 'mailcow_error', 'refused', array( 'key' => 'domain_invalid' ) ) );
		$this->assertTrue( $this->driver()->provision( $this->mail_resource( 'refused.example' ) )->is_failed() );
	}

	// -- A zone of ours ----------------------------------------------------------------------------

	public function test_a_domain_in_the_customers_zone_needs_no_proof_and_gets_its_records(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'mine.example' );
		$r   = $this->mail_resource( 'mine.example', array(), array(), $user );
		$res = $this->driver()->provision( $r );

		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertSame( 'ours', Resource::find( $r->id() )->get( 'mail_mode' ) );
		$this->assertSame( 'ok', $res->meta()['mail_dns'] );
		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( $res->meta()['mail_records'], 'status' ) ) ) );

		$rows = array();
		foreach ( $this->zone_rows( 'mine.example' ) as $row ) {
			$rows[ $row['type'] . ' ' . $row['name'] ] = $row;
		}
		$this->assertSame( 'mail.example.net', $rows['MX @']['value'] );
		$this->assertSame( 10, $rows['MX @']['priority'] );
		$this->assertSame( 'v=spf1 include:_spf.mail.example.net ~all', $rows['TXT @']['value'] );
		$this->assertStringStartsWith( 'v=DKIM1;k=rsa;', $rows['TXT dkim._domainkey']['value'] );
		$this->assertSame( 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.net', $rows['TXT _dmarc']['value'] );
		$this->assertSame( 'mail.example.net', $rows['CNAME autoconfig']['value'] );
		$this->assertSame( 'mail.example.net', $rows['CNAME autodiscover']['value'] );
		$this->assertSame( 0, $this->pending( $r->id() ), 'Nothing to wait for.' );
	}

	public function test_a_zone_that_is_not_the_customers_is_never_written(): void {
		$me    = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->our_zone( $other, 'theirs.example' );
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$r = $this->mail_resource( 'theirs.example', array(), array(), $me );
		$this->assertTrue( $this->driver()->provision( $r )->is_done() );

		$this->assertSame( 'external', Resource::find( $r->id() )->get( 'mail_mode' ) );
		$this->assertSame( array(), DnsClients::dns()->zone( 'theirs.example' )['rrsets'] );
	}

	public function test_existing_records_are_reported_and_kept_and_other_txt_records_survive(): void {
		$user = self::factory()->user->create();
		$this->our_zone(
			$user,
			'busy.example',
			array(
				array( 'name' => '@', 'type' => 'MX', 'ttl' => 600, 'value' => 'mx.other.example', 'priority' => 5 ),
				array( 'name' => '@', 'type' => 'TXT', 'ttl' => 600, 'value' => 'v=spf1 include:_spf.google.com ~all' ),
				array( 'name' => '@', 'type' => 'TXT', 'ttl' => 600, 'value' => 'google-site-verification=abc' ),
				array( 'name' => '_dmarc', 'type' => 'TXT', 'ttl' => 600, 'value' => 'v=DMARC1; p=none' ),
				array( 'name' => 'autoconfig', 'type' => 'A', 'ttl' => 600, 'value' => '192.0.2.7' ),
			)
		);
		$before = $this->zone_rows( 'busy.example' );
		$r      = $this->mail_resource( 'busy.example', array(), array(), $user );
		$res    = $this->driver()->provision( $r );
		$this->assertTrue( $res->is_done(), $res->error() );

		$by = array();
		foreach ( $res->meta()['mail_records'] as $row ) {
			$by[ $row['key'] ] = $row;
		}
		$this->assertSame( 'conflict', $by['mx']['status'] );
		$this->assertSame( 'conflict', $by['spf']['status'] );
		$this->assertStringContainsString( 'include:_spf.mail.example.net', $by['spf']['reason'] );
		$this->assertSame( 'ok', $by['dkim']['status'], 'The free name got its record.' );
		$this->assertSame( 'ok', $by['dmarc']['status'] );
		$this->assertSame( 'Your own DMARC record was kept.', $by['dmarc']['reason'] );
		$this->assertSame( 'conflict', $by['autoconfig']['status'] );
		$this->assertSame( 'ok', $by['autodiscover']['status'] );
		$this->assertSame( 'waiting', $res->meta()['mail_dns'] );

		// Everything that was there is still there, unchanged; only the free names were added.
		$after = $this->zone_rows( 'busy.example' );
		foreach ( $before as $row ) {
			$this->assertContains( $row, $after );
		}
		$this->assertCount( count( $before ) + 2, $after );
	}

	public function test_an_spf_record_is_added_beside_other_txt_records_without_losing_them(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'plain.example', array( array( 'name' => '@', 'type' => 'TXT', 'ttl' => 900, 'value' => 'google-site-verification=abc' ) ) );
		$r = $this->mail_resource( 'plain.example', array(), array(), $user );
		$this->assertTrue( $this->driver()->provision( $r )->is_done() );

		$values = array();
		foreach ( $this->zone_rows( 'plain.example' ) as $row ) {
			if ( 'TXT' === $row['type'] && '@' === $row['name'] ) {
				$values[] = $row['value'];
			}
		}
		sort( $values );
		$this->assertSame( array( 'google-site-verification=abc', 'v=spf1 include:_spf.mail.example.net ~all' ), $values );
	}

	public function test_each_record_is_one_write_and_a_second_run_writes_nothing(): void {
		$dns = new \BeltoftServices\Domains\FakeDns( true );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'one.example' );
		$r = $this->mail_resource( 'one.example', array(), array(), $user );
		$this->assertTrue( $this->driver()->provision( $r )->is_done() );

		$writes = array_filter( $dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] && 'one.example' === $c[1] && count( $c[2] ) === 1 );
		$this->assertGreaterThanOrEqual( 6, count( $writes ), 'Each record is its own write.' );
		foreach ( $writes as $call ) {
			$this->assertSame( 'REPLACE', $call[2][0]['changetype'] );
		}

		// Run again: everything is in place, nothing more is written.
		$count = count( $dns->calls() );
		$again = \BeltoftServices\Mail\MailDns::apply( Resource::find( $r->id() ) );
		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( $again, 'status' ) ) ) );
		$this->assertSame( 0, count( array_filter( array_slice( $dns->calls(), $count ), static fn( $c ) => 'replace_rrsets' === $c[0] ) ) );
	}

	// -- Pause, resume ---------------------------------------------------------------------------

	public function test_pause_switches_the_domain_and_its_active_mailboxes_off_and_resume_only_those_on(): void {
		$r = $this->ready_mail( 'pause.example' );
		$this->mailcow->add_mailbox( array( 'local_part' => 'a', 'domain' => 'pause.example', 'quota' => '100' ) );
		$this->mailcow->add_mailbox( array( 'local_part' => 'b', 'domain' => 'pause.example', 'quota' => '100', 'active' => '0' ) );

		$res = $this->driver()->pause( $r );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertSame( '0', $this->mailcow->domain( 'pause.example' )['active'] );
		$this->assertSame( '0', $this->mailcow->mailbox( 'a@pause.example' )['active'] );
		$this->assertSame( array( 'a@pause.example' ), $res->meta()['mail_paused_boxes'] );

		$r->set( $res->meta() );
		$this->assertTrue( $this->driver()->pause( Resource::find( $r->id() ) )->is_done(), 'Pausing twice is fine.' );

		$back = $this->driver()->resume( Resource::find( $r->id() ) );
		$this->assertTrue( $back->is_done(), $back->error() );
		$this->assertSame( '1', $this->mailcow->domain( 'pause.example' )['active'] );
		$this->assertSame( '1', $this->mailcow->mailbox( 'a@pause.example' )['active'] );
		$this->assertSame( '0', $this->mailcow->mailbox( 'b@pause.example' )['active'], 'The one the customer switched off stays off.' );
		$this->assertSame( array(), $back->meta()['mail_paused_boxes'] );
	}

	public function test_a_pause_that_stops_half_way_remembers_what_it_switched_off(): void {
		$r = $this->ready_mail( 'half.example' );
		foreach ( array( 'a', 'b' ) as $local ) {
			$this->mailcow->add_mailbox( array( 'local_part' => $local, 'domain' => 'half.example', 'quota' => '100' ) );
		}

		// The first mailbox call works, the second fails.
		$this->mailcow->fail_next( 'edit_mailbox', new \WP_Error( 'mailcow_transport', 'x' ), 1 );
		$res = $this->driver()->pause( $r );
		$this->assertTrue( $res->is_retry() );
		$this->assertSame( array( 'a@half.example' ), Resource::find( $r->id() )->get( 'mail_paused_boxes' ) );

		// The retry switches off the other one and keeps the first in the list.
		$res = $this->driver()->pause( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertSame( array( 'a@half.example', 'b@half.example' ), $res->meta()['mail_paused_boxes'] );
	}

	public function test_pausing_something_that_was_never_made_does_nothing(): void {
		$r = $this->mail_resource( 'never.example' );
		$this->assertTrue( $this->driver()->pause( $r )->is_done() );
		$this->assertSame( array(), $this->mailcow->calls() );
	}

	// -- Plan change -------------------------------------------------------------------------------

	public function test_a_plan_change_raises_limits_and_mailboxes_of_the_old_size_and_never_lowers(): void {
		$r = $this->ready_mail( 'grow.example', array( 'mailboxes' => 2, 'quota_mb' => 5120, 'aliases' => 20 ) );
		$this->mailcow->add_mailbox( array( 'local_part' => 'a', 'domain' => 'grow.example', 'quota' => '5120' ) );
		$this->mailcow->add_mailbox( array( 'local_part' => 'custom', 'domain' => 'grow.example', 'quota' => '5120' ) );
		$this->mailcow->edit_mailbox( 'custom@grow.example', array( 'quota' => '1000' ) );

		$r->change_plan( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ), 'Upgrade' );
		$res = $this->driver()->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_done(), $res->error() );

		$domain = $this->mailcow->domain( 'grow.example' );
		$this->assertSame( array( '5', '50', '10240', '51200' ), array( $domain['mailboxes'], $domain['aliases'], $domain['maxquota'], $domain['quota'] ) );
		$this->assertSame( 10240 * 1048576, $this->mailcow->mailbox( 'a@grow.example' )['quota'] );
		$this->assertSame( 1000 * 1048576, $this->mailcow->mailbox( 'custom@grow.example' )['quota'], 'A size the customer chose is kept.' );
		$this->assertSame( 5, $res->meta()['mail_limits']['mailboxes'] );

		$r2 = Resource::find( $r->id() );
		$r2->set( $res->meta() );
		$r2->change_plan( array( 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 20, 'domains' => 1 ), 'Downgrade' );
		$calls = count( $this->mailcow->calls_of( 'edit_domain' ) );
		$down  = $this->driver()->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $down->is_done() );
		$this->assertSame( $calls, count( $this->mailcow->calls_of( 'edit_domain' ) ), 'Nothing is lowered.' );
		$this->assertSame( 5, $down->meta()['mail_limits']['mailboxes'] );
		$this->assertSame( 'Email plan change was lower: limits kept', $down->event() );
	}

	public function test_the_domain_gets_its_minute_limit_when_made_and_when_the_plan_changes(): void {
		$r = $this->ready_mail( 'rate.example' );
		$this->assertSame( array( 'value' => 30, 'frame' => 'm' ), $this->mailcow->domain( 'rate.example' )['rl'] );
		$this->assertContains( 'rl', (array) Resource::find( $r->id() )->get( 'steps' ) );

		$this->settings( array( 'mail_rl_minute' => 45 ) );
		$r->change_plan( array( 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ), 'Upgrade' );
		$this->assertTrue( $this->driver()->update_plan( Resource::find( $r->id() ) )->is_done() );
		$this->assertSame( array( 'value' => 45, 'frame' => 'm' ), $this->mailcow->domain( 'rate.example' )['rl'] );
	}

	public function test_no_domain_limit_is_sent_when_the_setting_is_zero_and_a_refusal_retries(): void {
		$this->settings( array( 'mail_rl_minute' => 0 ) );
		$this->ready_mail( 'norate.example' );
		$this->assertSame( array(), $this->mailcow->calls_of( 'set_domain_rate_limit' ) );

		$this->settings( array( 'mail_rl_minute' => 30 ) );
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$r = $this->mail_resource( 'retry.example' );
		$this->mailcow->fail_next( 'set_domain_rate_limit', new \WP_Error( 'mailcow_transport', 'x' ) );
		$this->assertTrue( $this->driver()->provision( $r )->is_retry() );
		$this->assertTrue( $this->driver()->provision( Resource::find( $r->id() ) )->is_done(), 'It goes on from the saved steps.' );
	}

	// -- Delete ------------------------------------------------------------------------------------

	public function test_delete_removes_mailboxes_aliases_and_the_domain_and_wipes_passwords(): void {
		$r = $this->ready_mail( 'bye.example' );
		$r->locked( static function () use ( $r ): bool {
			MailAccess::begin( $r, 'a@bye.example' );
			return true;
		} );
		$this->mailcow->add_mailbox( array( 'local_part' => 'a', 'domain' => 'bye.example', 'quota' => '100' ) );
		$this->mailcow->add_alias( 'x@bye.example', 'y@elsewhere.example' );

		$res = $this->driver()->delete( Resource::find( $r->id() ) );
		$this->assertTrue( $res->is_done(), $res->error() );
		$this->assertNull( $this->mailcow->domain( 'bye.example' ) );
		$this->assertNull( $this->mailcow->mailbox( 'a@bye.example' ) );
		$this->assertSame( array(), Resource::find( $r->id() )->get( MailAccess::META ) );
		$this->assertTrue( $this->driver()->delete( Resource::find( $r->id() ) )->is_done(), 'Deleting again is fine.' );
	}

	public function test_delete_does_nothing_remote_for_a_domain_that_was_never_made(): void {
		$r = $this->mail_resource( 'ghost.example' );
		$this->assertTrue( $this->driver()->delete( $r )->is_done() );
		$this->assertSame( array(), $this->mailcow->calls() );
	}

	public function test_deletion_is_off_by_default(): void {
		$this->assertSame( 'off', \BeltoftServices\Settings::get( 'deletion_mode' ) );
	}

	// -- Through the reconciler --------------------------------------------------------------------

	public function test_the_job_runner_provisions_a_domain_end_to_end(): void {
		$this->proof['check_txt'] = array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' );
		$r = $this->mail_resource( 'flow.example' );
		Jobs::enqueue( $r->id() );
		$this->run_jobs();

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertNotNull( $this->mailcow->domain( 'flow.example' ) );
		$this->assertSame( 'waiting', $fresh->get( 'mail_dns' ) );
	}

	private function pending( int $id ): int {
		return count( as_get_scheduled_actions( array( 'hook' => MailDns::HOOK, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' ) );
	}
}

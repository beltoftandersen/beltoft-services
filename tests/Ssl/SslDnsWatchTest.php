<?php
/** Certificates in the shared DNS watch: the validation CNAME while the order waits, and a standing look after the certificate is issued. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\DnsWatch;
use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Domains\DnsWatchState;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\Operations;
use BeltoftServices\Ssl\Panels;
use BeltoftServices\Ssl\SslDnsWatch;
use BeltoftServices\Ssl\SslDriver;
use BeltoftServices\Ssl\Validation;

require_once __DIR__ . '/SslTestCase.php';

/**
 * Documentation values only. The registrar is the fake model, DNS answers come from a filter or FakeDns, no mail leaves the test.
 */
final class SslDnsWatchTest extends SslTestCase {

	private function f(): SslDnsWatch {
		$f = DnsWatch::feature( SslDnsWatch::ID );
		$this->assertInstanceOf( SslDnsWatch::class, $f );

		return $f;
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	/** An order that waits for validation at the registrar, with its rows, the watch started. */
	private function waiting( array $opts = array() ): Resource {
		$r = $this->ssl_resource( $opts );
		( new SslDriver() )->provision( $r );
		$r = $this->fresh( $r );
		$this->assertSame( 'REQ', $r->get( 'ssl_status' ) );
		$this->assertNotSame( array(), (array) $r->get( 'ssl_validation_rows' ) );
		SslDnsWatch::sync( $r );

		return $this->fresh( $r );
	}

	/** @return array{name:string,value:string} */
	private function row( Resource $r ): array {
		$row = (array) $r->get( 'ssl_validation_rows' )[0];

		return array( 'name' => (string) $row['name'], 'value' => (string) $row['value'] );
	}

	private function present( Resource $r, bool $yes = true ): void {
		$row = $this->row( $r );
		$this->dns[ 'CNAME ' . $row['name'] ] = $yes ? array( $row['value'] ) : array();
	}

	/** Subjects of the mails to the customer (not the staff alerts, which start with a bracket). */
	private function customer_subjects(): array {
		return array_values( array_filter( array_map( static fn( $m ) => (string) ( $m['subject'] ?? '' ), $this->mails ), static fn( $s ) => false !== stripos( $s, 'DNS record' ) && 0 !== strpos( $s, '[' ) ) );
	}

	private function staff_subjects(): array {
		return array_values( array_filter( array_map( static fn( $m ) => (string) ( $m['subject'] ?? '' ), $this->mails ), static fn( $s ) => 0 === strpos( $s, '[' ) ) );
	}

	/** An order from before the watch: issued, with no mark of the watch yet. */
	private function before_the_watch( array $opts = array() ): Resource {
		$r = $this->built( $opts );
		$this->assertTrue( $r->set( array( 'ssl_dns' => null, 'ssl_dns_sig' => null, 'ssl_dns_since' => null, 'ssl_dns_rows' => null, 'ssl_dns_mode' => null ) ) );

		return $this->fresh( $r );
	}

	/** An issued order whose watch has its first daily look due. */
	private function issued( array $opts = array() ): Resource {
		$r = $this->before_the_watch( $opts );
		SslDnsWatch::sync( $r );
		$r = $this->fresh( $r );
		$this->assertSame( 'ok', DnsWatch::status( $this->f(), $r ) );
		$this->mails = array();

		return $r;
	}

	private function recheck( Resource $r ): void {
		$this->assertTrue( $this->fresh( $r )->set( array( 'ssl_dns_rechecked' => time() - 2 * DAY_IN_SECONDS ) ) );
		DnsWatch::recheck( $this->f(), $r->id() );
	}

	public function test_the_certificate_is_a_feature_of_the_watch_with_its_own_prefix_and_hooks(): void {
		$f = $this->f();
		$this->assertSame( 'ssl', $f->id() );
		$this->assertSame( 'ssl', $f->kind() );
		$this->assertSame( 'ssl_dns', $f->prefix() );
		$this->assertSame( 'ssl_dns_rows', $f->rows_key() );
		$this->assertSame( array( 'cname' ), $f->needed() );
		$this->assertSame( 'beltoft_services_dns_watch_look_ssl', $f->hook( 'look' ) );
		$this->assertSame( 'beltoft_services_dns_watch_recheck_ssl', $f->hook( 'recheck' ) );
		$this->assertNotFalse( has_action( $f->hook( 'look' ) ) );
	}

	public function test_the_wait_days_are_the_cancel_days_of_the_order_not_a_second_number(): void {
		$this->settings( array( 'ssl_wait_days' => 9, 'dns_watch_wait_days' => 21 ) );
		$this->assertSame( 9, DnsWatchSettings::wait_days( 'ssl' ) );
		$this->assertSame( 21, DnsWatchSettings::wait_days( 'mail' ) );
		$this->assertSame( 21, DnsWatchSettings::wait_days() );
	}

	// -- Which orders are watched ---------------------------------------------------------------------

	public function test_only_orders_with_a_cname_to_find_are_watched(): void {
		$dns  = $this->waiting();
		$file = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'file' ) ) );
		( new SslDriver() )->provision( $file );
		$file = $this->fresh( $file );
		SslDnsWatch::sync( $file );
		$mail = $this->ssl_resource( array( 'meta' => array( 'ssl_validation' => 'email', 'ssl_approver' => 'admin@example.com' ) ) );
		( new SslDriver() )->provision( $mail );

		$this->assertTrue( $this->f()->owns( $dns ) );
		$this->assertFalse( $this->f()->owns( $file ), 'A file is checked by the authority over HTTPS.' );
		$this->assertFalse( $this->f()->owns( $this->fresh( $mail ) ), 'The authority mails the approver.' );
		$this->assertNull( $this->fresh( $file )->get( 'ssl_dns' ) );
	}

	// -- The first wait ---------------------------------------------------------------------------------

	public function test_the_first_wait_looks_for_the_cname_and_sends_nothing_when_it_is_found(): void {
		$r = $this->waiting();
		$this->assertSame( 'waiting', $r->get( 'ssl_dns' ) );
		$this->assertTrue( $this->f()->shows_status( $r ) );
		$this->assertSame( DnsWatchState::SETTING_UP, DnsWatchState::of( $this->f(), $r ) );

		DnsWatch::run( $this->f(), $r->id() );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'waiting', $fresh->get( 'ssl_dns' ) );
		$this->assertSame( array( 'missing' ), array_column( (array) $fresh->get( 'ssl_dns_rows' ), 'status' ) );

		$this->present( $r );
		$this->assertTrue( $fresh->set( array( 'ssl_dns_checked' => time() - 900 ) ) );
		DnsWatch::run( $this->f(), $r->id() );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'ok', $fresh->get( 'ssl_dns' ) );
		$this->assertSame( array(), $this->customer_subjects(), 'Nobody is told that a record is found.' );
		$this->assertSame( 'REQ', $fresh->get( 'ssl_status' ), 'The watch changes nothing at the registrar.' );
		$this->assertSame( array(), $this->calls( 'POST', '#/(cancel|reissue|renew)#' ) );
	}

	public function test_a_reminder_goes_to_the_customer_after_the_first_hours(): void {
		$r = $this->waiting();
		$this->assertTrue( $r->set( array( 'ssl_dns_since' => time() - 2 * DAY_IN_SECONDS ) ) );
		DnsWatch::run( $this->f(), $r->id() );
		DnsWatch::run( $this->f(), $r->id() );

		$this->assertCount( 1, $this->customer_subjects(), 'One reminder, once.' );
		$this->assertStringContainsString( 'example.com', $this->customer_subjects()[0] );
	}

	public function test_the_end_of_the_wait_is_the_cancel_of_the_order_and_sends_no_timeout_mail(): void {
		$this->settings( array( 'ssl_wait_days' => 5 ) );
		$r = $this->waiting();
		$this->assertTrue( $r->set( array( 'ssl_dns_since' => time() - 6 * DAY_IN_SECONDS, 'ssl_dns_reminded' => time() ) ) );
		DnsWatch::run( $this->f(), $r->id() );

		$this->assertSame( 'timeout', $this->fresh( $r )->get( 'ssl_dns' ) );
		$this->assertSame( array(), $this->customer_subjects() );
		$this->assertSame( array(), $this->calls( 'POST', '#/cancel#' ), 'The watch never cancels: the order step does, with the refund.' );

		// Inside the days the wait goes on.
		$this->settings( array( 'ssl_wait_days' => 10 ) );
		$this->assertTrue( $this->fresh( $r )->set( array( 'ssl_dns' => 'waiting' ) ) );
		DnsWatch::run( $this->f(), $r->id() );
		$this->assertSame( 'waiting', $this->fresh( $r )->get( 'ssl_dns' ) );
	}

	public function test_a_cancelled_order_is_not_looked_at_again(): void {
		$r = $this->waiting();
		$this->assertTrue( $r->set( array( 'ssl_cancelled' => true ) ) );
		$this->assertFalse( $this->f()->active( $this->fresh( $r ) ) );
		DnsWatch::run( $this->f(), $r->id() );
		$this->assertNull( $this->fresh( $r )->get( 'ssl_dns_checked' ) );
	}

	public function test_new_rows_start_the_wait_again_and_the_same_rows_do_not(): void {
		$r   = $this->waiting();
		$sig = $this->fresh( $r )->get( 'ssl_dns_sig' );
		$this->assertNotEmpty( $sig );
		$this->present( $r );
		DnsWatch::run( $this->f(), $r->id() );
		$this->assertSame( 'ok', $this->fresh( $r )->get( 'ssl_dns' ) );

		SslDnsWatch::sync( $this->fresh( $r ) );
		$this->assertSame( 'ok', $this->fresh( $r )->get( 'ssl_dns' ), 'The same rows change nothing.' );

		$this->assertTrue( $this->fresh( $r )->set( array( 'ssl_validation_rows' => array( array( 'type' => 'CNAME', 'name' => '_new.example.com', 'value' => 'new.validation.example', 'status' => 'manual' ) ) ) ) );
		SslDnsWatch::sync( $this->fresh( $r ) );
		$fresh = $this->fresh( $r );
		$this->assertSame( 'waiting', $fresh->get( 'ssl_dns' ), 'A new CSR gives a new value to find.' );
		$this->assertNotSame( $sig, $fresh->get( 'ssl_dns_sig' ) );
	}

	public function test_new_rows_clear_every_mark_of_the_old_ones(): void {
		$r = $this->waiting();
		$marks = array( '_lost_at', '_lost_pending', '_lost_mails', '_moved', '_timeout_mailed', '_admin_alerted', '_unknown_tries', '_unknown_alerted', '_last_mail', '_back_at', '_reminded', '_rechecked' );
		$set   = array();
		foreach ( $marks as $mark ) {
			$set[ 'ssl_dns' . $mark ] = time() - 100;
		}
		$set['ssl_dns_lost_keys'] = array( 'cname' );
		$this->assertTrue( $this->fresh( $r )->set( $set ) );

		$this->assertTrue( $this->fresh( $r )->set( array( 'ssl_validation_rows' => array( array( 'type' => 'CNAME', 'name' => '_new.example.com', 'value' => 'new.validation.example', 'status' => 'manual' ) ) ) ) );
		SslDnsWatch::sync( $this->fresh( $r ) );
		$fresh = $this->fresh( $r );

		foreach ( $marks as $mark ) {
			$this->assertSame( 0, (int) $fresh->get( 'ssl_dns' . $mark ), $mark );
		}
		$this->assertSame( array(), $fresh->get( 'ssl_dns_lost_keys' ) );
	}

	public function test_the_staff_alert_of_a_loss_follows_the_mail_flag(): void {
		$r = $this->waiting();
		$this->assertStringContainsString( 'The customer was emailed.', implode( ' ', $this->f()->alert_lines( 'lost', $r, array( 'mailed' => true ) ) ) );
		$this->assertStringContainsString( 'The customer was emailed.', implode( ' ', $this->f()->alert_lines( 'lost', $r ) ), 'Without the flag as before.' );
		$quiet = implode( ' ', $this->f()->alert_lines( 'lost', $r, array( 'mailed' => false ) ) );
		$this->assertStringContainsString( 'The customer was not emailed again: inside the mail gap.', $quiet );
		$this->assertStringNotContainsString( 'The customer was emailed.', $quiet );
	}

	// -- The standing look after the certificate is issued -----------------------------------------------

	public function test_an_issued_order_with_no_record_of_ours_yet_starts_as_found(): void {
		$r = $this->before_the_watch();
		$this->assertNull( $r->get( 'ssl_dns' ) );
		SslDnsWatch::sync( $r );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'ok', $fresh->get( 'ssl_dns' ), 'The authority found the record or the certificate would not exist.' );
		$this->assertSame( array(), $this->customer_subjects(), 'Starting to watch mails nobody.' );
		$this->assertSame( DnsWatchState::OK, DnsWatchState::of( $this->f(), $fresh ) );
	}

	public function test_a_record_that_goes_missing_after_issue_is_read_twice_then_confirmed_and_told_once(): void {
		$r    = $this->issued();
		$cert = $r->get( 'ssl_cert' );
		$this->present( $r, false );
		$this->recheck( $r );

		$fresh = $this->fresh( $r );
		$this->assertSame( 'waiting', $fresh->get( 'ssl_dns' ) );
		$this->assertSame( array(), $this->customer_subjects(), 'Nothing is said before the confirmation.' );

		DnsWatch::confirm( $this->f(), $r->id() );
		$fresh = $this->fresh( $r );
		$this->assertSame( DnsWatchState::LOST, DnsWatchState::of( $this->f(), $fresh ) );
		$this->assertCount( 1, $this->customer_subjects() );
		$this->assertSame( $cert, $fresh->get( 'ssl_cert' ), 'The running certificate is never touched.' );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( array(), $this->calls( 'POST', '#/(cancel|reissue|renew)#' ) );

		$body = $this->mails[ array_key_last( array_filter( $this->mails, fn( $m ) => 0 !== strpos( (string) $m['subject'], '[' ) ) ) ]['message'];
		$this->assertStringContainsString( 'keeps working', $body );
		$this->assertStringContainsString( $this->row( $r )['name'], $body );
	}

	public function test_a_record_that_comes_back_in_time_mails_nobody(): void {
		$r = $this->issued();
		$this->present( $r, false );
		$this->recheck( $r );
		$this->present( $r );
		DnsWatch::confirm( $this->f(), $r->id() );

		$this->assertSame( 'ok', $this->fresh( $r )->get( 'ssl_dns' ) );
		$this->assertSame( array(), $this->customer_subjects() );
	}

	public function test_a_lookup_that_fails_learns_nothing(): void {
		$r   = $this->issued();
		$row = $this->row( $r );
		$this->dns[ 'CNAME ' . $row['name'] ] = new \WP_Error( 'dns_transport', 'down' );
		$this->recheck( $r );

		$this->assertSame( 'ok', $this->fresh( $r )->get( 'ssl_dns' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_foreign_value_at_the_name_is_a_conflict_with_what_is_seen(): void {
		$r   = $this->issued();
		$row = $this->row( $r );
		$this->dns[ 'CNAME ' . $row['name'] ] = array( 'other.example.org' );
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );

		$rows = (array) $this->fresh( $r )->get( 'ssl_dns_rows' );
		$this->assertSame( 'conflict', $rows[0]['status'] );
		$this->assertSame( array( 'other.example.org' ), $rows[0]['seen'] );
	}

	public function test_the_loss_mail_and_the_status_word_say_what_stops_and_what_does_not(): void {
		$r = $this->issued();
		$this->present( $r, false );
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );
		$word = DnsWatchState::word( $this->f(), $this->fresh( $r ) );

		$this->assertStringContainsString( 'DNS records missing', $word );
		$copy = $this->f()->copy( 'lost', $this->fresh( $r ) );
		$this->assertStringContainsString( 'renew', $copy['dns_effect'] );
		$this->assertNotSame( '', $copy['dns_link'] );
		$this->assertSame( array(), $this->f()->alert_lines( 'timeout', $this->fresh( $r ) ) );
		$this->assertFalse( $this->f()->mails( 'timeout', $this->fresh( $r ) ), 'No timeout mail for a certificate.' );
		$this->assertTrue( $this->f()->mails( 'lost', $this->fresh( $r ) ) );
	}

	public function test_the_daily_sweep_queues_an_issued_order(): void {
		$r = $this->issued();
		DnsWatch::recheck_all( $this->f() );
		$ids = as_get_scheduled_actions( array( 'hook' => $this->f()->hook( 'recheck' ), 'args' => array( $r->id() ), 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' );
		$this->assertCount( 1, $ids );
	}

	public function test_an_issue_while_the_watch_waits_means_the_authority_found_the_record(): void {
		$r = $this->waiting();
		$this->assertSame( 'waiting', $r->get( 'ssl_dns' ) );
		$built = $this->build( $r );
		$this->assertTrue( $built->is_done() );
		$this->assertTrue( $this->fresh( $r )->set( $built->meta() ) );
		SslDnsWatch::sync( $this->fresh( $r ) );

		$this->assertSame( 'ok', $this->fresh( $r )->get( 'ssl_dns' ) );
		$this->assertSame( array(), $this->customer_subjects() );
	}

	// -- A zone of ours --------------------------------------------------------------------------------

	public function test_our_own_missing_cname_is_written_back_quietly(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->built( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto' ) ) );
		SslDnsWatch::sync( $r );
		$r   = $this->fresh( $r );
		$row = (array) $r->get( 'ssl_validation_rows' )[0];
		$this->assertTrue( $this->f()->on_our_servers( $r ) );
		$this->mails = array();

		$this->assertTrue( Validation::unpublish( $user, 'example.com', $row ) );
		$has = fn() => in_array( $row['name'], array_map( static fn( $s ) => strtolower( rtrim( (string) $s['name'], '.' ) ), array_filter( (array) Clients::dns()->zone( 'example.com' )['rrsets'], static fn( $s ) => 'CNAME' === $s['type'] ) ), true );
		$this->assertFalse( $has() );

		$this->recheck( $r );
		$this->assertTrue( $has(), 'The record is back in the zone.' );
		$this->assertSame( array(), $this->customer_subjects(), 'Put back with no mail.' );
		$this->assertSame( 'ok', $this->fresh( $r )->get( 'ssl_dns' ) );
	}

	public function test_a_foreign_cname_in_our_zone_is_never_overwritten(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->built( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'auto' ) ) );
		SslDnsWatch::sync( $r );
		$r   = $this->fresh( $r );
		$row = (array) $r->get( 'ssl_validation_rows' )[0];
		$this->assertTrue( Validation::unpublish( $user, 'example.com', $row ) );
		$rel = substr( $row['name'], 0, -strlen( '.example.com' ) );
		$this->assertTrue( \BeltoftServices\Domains\Records::replace( 'example.com', array_merge( $this->zone_rows( 'example.com' ), array( array( 'name' => $rel, 'type' => 'CNAME', 'ttl' => 300, 'value' => 'other.example.org' ) ) ) ) );

		$this->recheck( $r );
		$sets = array_values( array_filter( (array) Clients::dns()->zone( 'example.com' )['rrsets'], static fn( $s ) => 'CNAME' === $s['type'] && strtolower( rtrim( (string) $s['name'], '.' ) ) === $row['name'] ) );
		$this->assertSame( 'other.example.org.', strtolower( (string) $sets[0]['records'][0]['content'] ) . ( '.' === substr( (string) $sets[0]['records'][0]['content'], -1 ) ? '' : '.' ) );
		$this->assertCount( 1, $sets[0]['records'] );
		$this->assertSame( 'conflict', ( (array) $this->fresh( $r )->get( 'ssl_dns_rows' ) )[0]['status'] );
	}

	public function test_a_dns_order_in_a_zone_of_ours_is_read_not_written(): void {
		$user = (int) self::factory()->user->create();
		$this->our_zone( $user, 'example.com' );
		$r = $this->built( array( 'user' => $user, 'meta' => array( 'ssl_validation' => 'dns' ) ) );
		SslDnsWatch::sync( $r );

		$this->assertFalse( $this->f()->on_our_servers( $this->fresh( $r ) ), 'The customer chose to add it; we do not write.' );
	}

	// -- The authority's own reissue --------------------------------------------------------------------

	public function test_a_reissue_by_the_authority_that_waits_for_validation_is_noticed_and_staff_are_told_once(): void {
		$r = $this->built();
		add_filter( 'beltoft_services_ssl_fake_reissue_waits', '__return_true' );
		$this->mails = array();
		// The authority reissues in place after the certificate's lifetime: the order is requested again.
		$this->now += ( \BeltoftServices\Ssl\FakeModel::LIFETIME_DAYS + 1 ) * DAY_IN_SECONDS;
		$this->apply( $r, Operations::refresh( $r ) );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'ACT', $fresh->get( 'ssl_status' ), 'The stored status is not changed.' );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'ssl_authority_reissue_at' ) );
		$this->assertCount( 1, $this->staff_subjects() );
		$this->assertStringContainsString( 'SSL renewal waits for validation: example.com', $this->staff_subjects()[0] );
		$this->assertStringNotContainsString( 'Service failed', $this->staff_subjects()[0] );
		$body = (string) $this->mails[ array_key_last( $this->mails ) ]['message'];
		$this->assertStringContainsString( 'Order id: ' . $fresh->get( 'remote_id' ), $body );
		$this->assertStringContainsString( 'Resource: ' . $r->id(), $body );
		$this->assertStringContainsString( 'validation CNAME', $body );
		$this->assertSame( array(), $this->customer_subjects() );

		$this->apply( $fresh, Operations::refresh( $fresh ) );
		$this->assertCount( 1, $this->staff_subjects(), 'Once.' );

		// The new certificate arrives: the marks go.
		$this->now += 120;
		$this->apply( $this->fresh( $r ), Operations::refresh( $this->fresh( $r ) ) );
		$done = $this->fresh( $r );
		$this->assertEmpty( $done->get( 'ssl_authority_reissue_at' ) );
		remove_all_filters( 'beltoft_services_ssl_fake_reissue_waits' );
	}

	public function test_a_certificate_for_several_names_is_found_only_when_every_record_is(): void {
		$r = $this->waiting();
		$this->assertTrue( $r->set( array( 'ssl_validation_rows' => array(
			array( 'type' => 'CNAME', 'name' => '_a.example.com', 'value' => 'a.validation.example', 'status' => 'manual' ),
			array( 'type' => 'CNAME', 'name' => '_b.example.org', 'value' => 'b.validation.example', 'status' => 'manual' ),
		) ) ) );
		SslDnsWatch::sync( $this->fresh( $r ) );
		$this->dns['CNAME _a.example.com'] = array( 'a.validation.example.' );
		DnsWatch::run( $this->f(), $r->id(), true );

		$fresh = $this->fresh( $r );
		$this->assertSame( 'waiting', $fresh->get( 'ssl_dns' ), 'One of two is not enough.' );
		$this->assertSame( array( '_b.example.org' ), array_column( $this->f()->missing_rows( $fresh, true ), 'name' ) );
		$this->assertSame( 'ok', SslDnsWatch::status_of( $fresh, '_a.example.com' ) );
		$this->assertSame( 'missing', SslDnsWatch::status_of( $fresh, '_b.example.org' ) );

		$this->dns['CNAME _b.example.org'] = array( 'b.validation.example' );
		DnsWatch::run( $this->f(), $r->id(), true );
		$this->assertSame( 'ok', $this->fresh( $r )->get( 'ssl_dns' ) );
	}

	// -- What the customer sees --------------------------------------------------------------------------

	public function test_the_validation_box_says_found_or_not_found_yet(): void {
		$r = $this->waiting();
		$this->present( $r );
		DnsWatch::run( $this->f(), $r->id() );
		$r    = $this->fresh( $r );
		$html = $this->draw( $r );
		$this->assertStringContainsString( 'The record is found.', $html );

		$other = $this->waiting( array( 'meta' => array( 'ssl_name' => 'other.example.com', 'ssl_domain' => 'example.com' ) ) );
		DnsWatch::run( $this->f(), $other->id() );
		$this->assertStringContainsString( 'Not found yet', $this->draw( $this->fresh( $other ) ) );
	}

	private function draw( Resource $r ): string {
		$method = new \ReflectionMethod( Panels::class, 'validation' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( null, $r, (array) $r->get( 'ssl_validation_rows' ) );

		return (string) ob_get_clean();
	}
}

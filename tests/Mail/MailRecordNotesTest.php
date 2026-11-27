<?php
/**
 * The DNS records editor warns before a record an email domain uses is deleted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Domains\DomainPage;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailRecordNotesTest extends MailTestCase {

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

	public function test_a_zone_with_email_names_the_records_the_email_uses(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'notes.example' );
		$this->ready_mail( 'notes.example', array(), $user );

		$notes = apply_filters( 'beltoft_services_dns_record_notes', array(), 'notes.example', $user );
		$by    = array();

		foreach ( $notes as $note ) {
			$by[ $note['name'] . '|' . $note['type'] . '|' . $note['match'] ] = $note['text'];
		}

		$this->assertSame( 'This record is used by your email. Mail stops if you delete it.', $by['@|MX|mail.example.net'] );
		$this->assertArrayHasKey( '@|TXT|v=spf1', $by );
		$this->assertArrayHasKey( 'dkim._domainkey|TXT|v=DKIM1', $by );
		$this->assertCount( 3, $notes, 'Only the records mail stops without.' );
	}

	public function test_another_customer_or_another_zone_gets_no_note(): void {
		$user = self::factory()->user->create();
		$this->our_zone( $user, 'mine.example' );
		$this->ready_mail( 'mine.example', array(), $user );

		$this->assertSame( array(), apply_filters( 'beltoft_services_dns_record_notes', array(), 'mine.example', self::factory()->user->create() ) );
		$this->assertSame( array(), apply_filters( 'beltoft_services_dns_record_notes', array(), 'other.example', $user ) );
	}

	public function test_a_domain_at_another_provider_gets_no_note(): void {
		$user = self::factory()->user->create();
		$this->ready_mail( 'elsewhere.example', array(), $user );

		$this->assertSame( array(), apply_filters( 'beltoft_services_dns_record_notes', array(), 'elsewhere.example', $user ) );
	}

	public function test_the_records_route_carries_the_notes(): void {
		$user = self::factory()->user->create();
		$zone = $this->our_zone( $user, 'route.example' );
		$this->ready_mail( 'route.example', array(), $user );

		wp_set_current_user( $user );
		$reply = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', self::BASE . $zone->id() . '/dns-records' ) );

		$this->assertSame( 200, $reply->get_status() );
		$this->assertCount( 3, $reply->get_data()['notes'] );
	}
}

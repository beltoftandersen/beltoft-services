<?php
/** The zone file import as a shared action: the sets are stored, a job writes them one at a time, the report is the outcome. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Account\Overview;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\RecordImport;
use BeltoftServices\Domains\Records;
use BeltoftServices\Jobs;
use BeltoftServices\Progress;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/** A DNS server that fails the write of one chosen set a number of times, then works. */
class FlakyDns extends FakeDns {
	/** @var array<string, int> Failures left by "name|type". */
	public array $fail = array();

	public function replace_rrsets( string $zone, array $rrsets ): bool|\WP_Error {
		foreach ( $rrsets as $set ) {
			$key = ( $set['name'] ?? '' ) . '|' . ( $set['type'] ?? '' );

			if ( ! empty( $this->fail[ $key ] ) ) {
				--$this->fail[ $key ];

				return new \WP_Error( 'dns_down', 'The DNS server is not answering.' );
			}
		}

		return parent::replace_rrsets( $zone, $rrsets );
	}
}

class RecordImportJobTest extends TestCase {
	private FlakyDns $dns;
	private int $user = 0;

	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		$this->dns = new FlakyDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		add_filter( 'pre_wp_mail', function ( $pre, $atts ) { $this->mails[] = $atts; return true; }, 10, 2 );
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function domain(): Resource {
		$name    = 'imp-' . strtolower( wp_generate_password( 6, false, false ) ) . '.com';
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true, 'zone_claimed' => true, 'remote_id' => '55' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->assertTrue( Records::replace( $name, array( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );

		return Resource::find( $r->id() );
	}

	private function text( string $name ): string {
		return '$ORIGIN ' . $name . ".\n@ 300 IN A 192.0.2.99\nwww 300 IN A 192.0.2.50\n@ 300 IN MX 10 mail.example.org.\nmail 300 IN A 192.0.2.60\n";
	}

	private function post( Resource $r, bool $apply ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/dns-import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'text' => $this->text( (string) $r->get( 'domain' ) ), 'apply' => $apply ) ) );

		return rest_get_server()->dispatch( $request );
	}

	private function zone_json( Resource $r ): string {
		return (string) wp_json_encode( $this->dns->zone( (string) $r->get( 'domain' ) ) );
	}

	public function test_apply_queues_and_writes_nothing_in_the_request(): void {
		$r   = $this->domain();
		$out = $this->post( $r, true );

		$this->assertSame( 202, $out->get_status() );
		$this->assertTrue( $out->get_data()['queued'] );
		$this->assertSame( 'dns_import', $out->get_data()['action'] );
		$this->assertStringNotContainsString( '192.0.2.99', $this->zone_json( $r ), 'The request wrote nothing.' );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 4, count( $fresh->get( RecordImport::JOB )['sets'] ) );
		$this->assertSame( 'dns_import', Progress::pending( $fresh )['action'] );
		$this->assertTrue( Jobs::acting( $r->id() ) );
		$this->assertSame( array( array( $r->id(), 'dns_import', array() ) ), array_map( static fn( $a ) => $a->get_args(), array_values( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) ) ), 'The records are not in the job arguments.' );

		$this->run_jobs();
		$this->assertStringContainsString( '192.0.2.99', $this->zone_json( $r ) );
		$last = Resource::find( $r->id() )->get( 'last_result' );
		$this->assertTrue( $last['ok'] );
		$this->assertSame( 'The records were imported: 3 added, 1 replaced.', $last['text'] );
		$this->assertNull( Progress::pending( Resource::find( $r->id() ) ) );
	}

	public function test_a_dns_server_that_fails_for_a_moment_keeps_the_place_and_the_replay_writes_the_rest(): void {
		$r = $this->domain();
		$this->dns->fail[ 'www.' . $r->get( 'domain' ) . '.|A' ] = 1;
		$this->assertSame( 202, $this->post( $r, true )->get_status() );
		$this->run_jobs();

		$mid = Resource::find( $r->id() );
		$this->assertStringContainsString( '192.0.2.99', $this->zone_json( $r ), 'The sets before it are written.' );
		$this->assertStringNotContainsString( '192.0.2.50', $this->zone_json( $r ) );
		$this->assertSame( 1, $mid->get( RecordImport::JOB )['next'], 'It stopped at the second set.' );
		$this->assertNull( $mid->get( 'last_result' ), 'Not over: no outcome yet, the page says it is working.' );
		$this->assertNotNull( Progress::pending( $mid ) );

		Jobs::act( $r->id(), 'dns_import', array() );
		$zone = $this->zone_json( $r );
		$this->assertStringContainsString( '192.0.2.50', $zone );
		$this->assertStringContainsString( 'mail.example.org', $zone );
		$this->assertStringContainsString( '192.0.2.60', $zone );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( $fresh->get( 'last_result' )['ok'] );
		$this->assertNull( $fresh->get( RecordImport::JOB ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'dns_imported_at' ) );

		// Every set was written once: the set before the failure was not written again.
		$writes = array_filter( $this->dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] && str_contains( (string) wp_json_encode( $c ), '192.0.2.99' ) );
		$this->assertCount( 1, $writes );
	}

	public function test_a_replay_after_it_ended_does_nothing(): void {
		$r = $this->domain();
		$this->post( $r, true );
		$this->run_jobs();
		$count = count( $this->dns->calls() );

		$again = RecordImport::run_job( Resource::find( $r->id() ) );
		$this->assertTrue( $again->is_done() );
		$this->assertCount( $count, $this->dns->calls() );
	}

	public function test_a_set_that_can_never_be_written_is_reported_and_the_others_still_go_in(): void {
		$r = $this->domain();
		// A CNAME where an A record is: a rule of the zone, never a passing error. Another set is added first.
		$text = '$ORIGIN ' . $r->get( 'domain' ) . ".\nwww 300 IN A 192.0.2.50\n@ 300 IN MX 10 mail.example.org.\n";
		$plan = RecordImport::plan( (string) $r->get( 'domain' ), $text, Records::to_rows( (string) $r->get( 'domain' ), (array) $this->dns->zone( (string) $r->get( 'domain' ) )['rrsets'] ) );
		$this->assertIsArray( $plan );
		// A set that the zone refuses at write time: it is paused between the preview and the job.
		$sets = $plan['sets'];
		$sets[0]['type'] = 'SPF';
		$this->assertTrue( RecordImport::queue( $r, $sets ) );
		$this->run_jobs();

		$last = Resource::find( $r->id() )->get( 'last_result' );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'dns_import', $last['action'] );
		$this->assertStringContainsString( 'Not added: www SPF', $last['text'] );
		$this->assertStringContainsString( 'mail.example.org', $this->zone_json( $r ), 'The other set went in.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'dns_imported_at' ) );
		$this->assertSame( array(), $this->mails, 'A refusal the customer reads is no alert for staff.' );
	}

	public function test_a_second_import_while_one_is_pending_is_refused_and_the_stored_sets_stay(): void {
		$r = $this->domain();
		$this->assertSame( 202, $this->post( $r, true )->get_status() );
		$before = Resource::find( $r->id() )->get( RecordImport::JOB );

		$second = $this->post( $r, true );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'Wait until adding DNS records is done.', $second->get_data()['message'] );
		$this->assertSame( $before, Resource::find( $r->id() )->get( RecordImport::JOB ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );
	}

	public function test_the_preview_stays_in_the_request_and_queues_nothing(): void {
		$r   = $this->domain();
		$out = $this->post( $r, false );

		$this->assertSame( 200, $out->get_status() );
		$this->assertNull( $out->get_data()['result'] );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );
		$this->assertNull( Resource::find( $r->id() )->get( RecordImport::JOB ) );
	}

	public function test_the_dns_only_service_can_import_too(): void {
		$driver = new \BeltoftServices\Domains\DnsDriver();
		$this->assertSame( array( 'dns_import' ), $driver->internal_actions() );
		$this->assertTrue( apply_filters( 'beltoft_services_action_resumable', false, 'dns', 'dns_import' ) );
		$this->assertTrue( apply_filters( 'beltoft_services_action_resumable', false, 'domain', 'adopt_switch' ) );
		$this->assertFalse( apply_filters( 'beltoft_services_action_resumable', false, 'domain', 'lock' ) );
	}
}

<?php
/** DNS claims CLI and DNS-only emails. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Cli\DnsClaimsCommand;
use BeltoftServices\Domains\DnsAssignments;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

class DnsClaimsCommandTest extends TestCase {
	/** @var array<int, array<string, mixed>> */
	private array $mails = array();
	/** @var array<int, array<int, mixed>> */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails  = array();
		$this->events = array();
		add_filter( 'pre_wp_mail', function ( $pre, $atts ) { $this->mails[] = $atts; return true; }, 10, 2 );
		add_action( 'beltoft_services_dns_event', function ( ...$args ) { $this->events[] = $args; }, 10, 3 );
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		\WP_CLI::reset();
	}

	private function cmd(): DnsClaimsCommand { return new DnsClaimsCommand(); }

	private function dns_resource( string $domain, int $user, string $driver = 'dns' ): Resource {
		$license = $this->service_license( $this->service_product( $driver ), array( 'fire' => false, 'user_id' => $user ) );
		$r = Resource::create( $license, 'dns' === $driver ? new DnsDriver() : new DomainDriver(), array(), array( 'domain' => $domain, 'mode' => $driver ) );
		$this->assertInstanceOf( Resource::class, $r );
		return $r;
	}

	private function fails( callable $fn ): string {
		try { $fn(); } catch ( \RuntimeException $e ) { return $e->getMessage(); }
		$this->fail( 'Expected an error.' );
	}

	public function test_list_and_show_never_print_a_token(): void {
		$user = self::factory()->user->create();
		$claim = DnsClaims::create( $user, 'list.example.com' );
		DnsClaims::set_status( $claim['id'], 'verified' );
		DnsAssignments::reserve( $user, $claim['id'], 'list.example.com' );
		$this->cmd()->list_( array(), array() );
		$this->assertStringContainsString( 'list.example.com', \WP_CLI::text() );
		\WP_CLI::reset();
		$this->cmd()->list_( array(), array( 'status' => 'active', 'format' => 'json' ) );
		$this->assertStringNotContainsString( 'list.example.com', \WP_CLI::text() );
		\WP_CLI::reset();
		add_filter( 'beltoft_services_dns_proof_result', static fn() => array( 'status' => 'ok', 'reason' => 'ok', 'ns' => array( 'ns1.other.net' ), 'ips' => array(), 'ds' => false, 'ours' => false ) );
		$this->cmd()->show( array( 'list.example.com' ), array() );
		$text = \WP_CLI::text();
		$this->assertStringContainsString( 'reserved', $text );
		$this->assertStringContainsString( 'Claim ' . $claim['id'], $text );
		$this->assertStringContainsString( 'ns1.other.net', $text );
		$this->assertStringNotContainsString( $claim['token'], $text );
	}

	public function test_assign_needs_yes_and_replaces_the_row(): void {
		$old = self::factory()->user->create();
		$new = self::factory()->user->create();
		$claim = DnsClaims::create( $old, 'assign.example.com' );
		DnsAssignments::reserve( $old, $claim['id'], 'assign.example.com' );
		$this->fails( fn() => $this->cmd()->assign( array( 'assign.example.com' ), array( 'user' => $new ) ) );
		$this->assertSame( $old, (int) DnsAssignments::get( 'assign.example.com' )['user_id'] );
		$this->assertStringContainsString( 'assign.example.com', \WP_CLI::text(), 'The current row is printed first.' );
		$this->cmd()->assign( array( 'assign.example.com' ), array( 'user' => $new, 'yes' => true ) );
		$row = DnsAssignments::get( 'assign.example.com' );
		$this->assertSame( $new, (int) $row['user_id'] );
		$this->assertSame( 'reserved', $row['status'] );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s', time() + 6 * DAY_IN_SECONDS ), $row['reserved_until'] );
		$this->assertSame( array( array( 'assign', 'assign.example.com', $new ) ), $this->events );
	}

	public function test_release_refused_while_a_live_resource_has_the_domain(): void {
		$user = self::factory()->user->create();
		DnsAssignments::assign_reserved( 'rel.example.com', $user );
		$r = $this->dns_resource( 'rel.example.com', $user );
		$message = $this->fails( fn() => $this->cmd()->release( array( 'rel.example.com' ), array( 'yes' => true ) ) );
		$this->assertStringContainsString( (string) $r->id(), $message );
		$this->assertNotNull( DnsAssignments::get( 'rel.example.com' ) );

		DnsAssignments::assign_reserved( 'free.example.com', $user );
		$this->fails( fn() => $this->cmd()->release( array( 'free.example.com' ), array() ) );
		$this->assertNotNull( DnsAssignments::get( 'free.example.com' ) );
		$this->cmd()->release( array( 'free.example.com' ), array( 'yes' => true ) );
		$this->assertNull( DnsAssignments::get( 'free.example.com' ) );
		$this->assertSame( array( array( 'release', 'free.example.com', $user ) ), $this->events );
	}

	public function test_approve_zone(): void {
		$dns = new FakeDns();
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$user = self::factory()->user->create();
		$other = self::factory()->user->create();
		$dns->create_zone( 'zone.example.com', array( 'ns1.example.net', 'ns2.example.net' ) );
		DnsAssignments::assign_reserved( 'zone.example.com', $user );
		$r = $this->dns_resource( 'zone.example.com', $user );
		$license = (int) $r->license()['id'];

		$this->fails( fn() => $this->cmd()->approve_zone( array( (string) $license ), array() ) );
		$this->assertStringContainsString( 'existing zone', \WP_CLI::text() );
		$this->assertNotTrue( Resource::find( $r->id() )->get( 'zone_claimed' ) );

		DnsAssignments::assign_reserved( 'zone.example.com', $other );
		$this->fails( fn() => $this->cmd()->approve_zone( array( (string) $license ), array( 'yes' => true ) ) );
		$this->assertNotTrue( Resource::find( $r->id() )->get( 'zone_claimed' ) );

		DnsAssignments::assign_reserved( 'zone.example.com', $user );
		$r->set( array( 'op' => 'provision', 'attempts' => 4 ) );
		as_unschedule_all_actions( '', array(), Jobs::GROUP );
		$this->cmd()->approve_zone( array( (string) $license ), array( 'yes' => true ) );
		$fresh = Resource::find( $r->id() );
		$this->assertTrue( $fresh->get( 'zone_claimed' ) );
		$this->assertSame( 0, (int) $fresh->get( 'attempts' ) );
		$this->assertNotEmpty( as_get_scheduled_actions( array( 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		$this->assertContains( array( 'approve_zone', 'zone.example.com', $user ), $this->events );

		$domain = $this->dns_resource( 'reg.example.com', $user, 'domain' );
		$this->fails( fn() => $this->cmd()->approve_zone( array( (string) $domain->license()['id'] ), array( 'yes' => true ) ) );
	}

	private function send( Resource $r, string $id, string $type ): string {
		$email = Emails::get( $id );
		$email->email_type = $type;
		$this->mails = array();
		$email->trigger( $r );
		return $this->mails ? trim( (string) preg_replace( '/\s+/', ' ', (string) $this->mails[0]['message'] ) ) : '';
	}

	public function test_ready_and_paused_emails_add_dns_text_for_dns_only(): void {
		$user = self::factory()->user->create();
		$dns = $this->dns_resource( 'mail.example.com', $user );
		$reg = $this->dns_resource( 'mail2.example.com', $user, 'domain' );
		foreach ( array( 'html', 'plain' ) as $type ) {
			$ready = $this->send( $dns, 'beltoft_services_ready', $type );
			$this->assertStringContainsString( 'ns1.example.net, ns2.example.net', $ready );
			$this->assertStringContainsString( 'Change the name servers at your registrar to these. If your registrar shows a DS record (DNSSEC), remove it first and wait a day.', $ready );
			$this->assertStringNotContainsString( 'Change the name servers at your registrar to these', $this->send( $reg, 'beltoft_services_ready', $type ) );
			$this->assertStringContainsString( 'Your DNS records are kept but no longer answered.', $this->send( $dns, 'beltoft_services_paused', $type ) );
			$this->assertStringNotContainsString( 'no longer answered', $this->send( $reg, 'beltoft_services_paused', $type ) );
		}
	}
}

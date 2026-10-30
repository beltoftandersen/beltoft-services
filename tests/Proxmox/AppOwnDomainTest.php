<?php
/** An app on a domain registered elsewhere: the wait for DNS, the install and the customer's view. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Servers;
use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\AppInstall;
use BeltoftServices\Proxmox\AppOwnDomain;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\AppStatus;
use BeltoftServices\Proxmox\AppTab;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

/**
 * Documentation values only. DNS answers come from a filter, Proxmox is FakeClient, no mail leaves the test.
 */
final class AppOwnDomainTest extends MonitoringTestCase {

	private const NAME = 'shop.elsewhere.test';
	private const IP   = '192.0.2.10';

	/** @var array<string, array<int, string>|\WP_Error> Answers by type. */
	private array $answers = array();

	/** @var array<int, string> Lookups made, "A name" form. */
	private array $lookups = array();

	/** @var array<int, array<string, mixed>> Mails sent. */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( FakeDns::OPTION );
		$this->answers = array( 'A' => array(), 'AAAA' => array() );
		$this->lookups = array();
		$this->mails   = array();
		add_filter(
			'beltoft_services_dns_resolve',
			function ( $pre, $name, $type ) {
				$this->lookups[] = $type . ' ' . $name;
				return $this->answers[ $type ];
			},
			20,
			3
		);
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;
				return true;
			},
			10,
			2
		);
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->clear_ips();
		$this->assertTrue( \BeltoftServices\Proxmox\Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04",
			'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress', 'coolify' ),
			'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) ) );
	}

	public function tear_down(): void {
		$this->clear_ips();
		delete_option( FakeDns::OPTION );
		unset( $GLOBALS['wp']->query_vars['vps'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function clear_ips(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * A ready server with the wordpress app waiting for an own domain.
	 *
	 * @param array<string, mixed> $meta Meta over the defaults.
	 */
	private function waiting( array $meta = array(), int $vmid = 5000 ): Resource {
		$r  = $this->ready_vm( $vmid, 'christian.beltoft@gmail.com' );
		$ip = '192.0.2.' . ( 10 + $vmid - 5000 );
		$this->assertTrue( \BeltoftServices\Proxmox\VmRecords::move( $r->id(), 'node-a' ) );
		$this->assertTrue( $r->set( array_merge(
			array(
				'ip'          => $ip,
				'app_id'      => 'wordpress',
				'app_version' => '7.1.4',
				'app_state'   => 'pending',
				OsField::META => 9001,
				'plan'        => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ),
			),
			array_filter( AppOwnDomain::fresh_wait( self::NAME ), static fn( $v ) => null !== $v ),
			$meta
		) ) );
		IpPool::add( $ip );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$this->assertTrue( IpPool::assign( $r->id() ) );

		return Resource::find( $r->id() );
	}

	private function pending( int $id, string $hook ): int {
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' ) );
	}

	private function backdate( Resource $r, string $key, int $seconds ): void {
		$this->assertTrue( $r->set( array( $key => time() - $seconds ) ) );
	}

	// -- The name ------------------------------------------------------------------------------

	public function test_names_are_normalised_and_unusable_ones_refused(): void {
		$this->assertSame( 'shop.example.com', AppOwnDomain::normalise( '  Shop.Example.COM. ' ) );
		$this->assertSame( 'a.xn--bcher-kva.example', AppOwnDomain::normalise( 'a.xn--bcher-kva.example' ) );
		foreach ( array( '', 'example', 'a..b.com', '-a.example.com', 'a.example.com/x', 'a b.example.com', 'bücher.example', '*.example.com', 'a.example.123', 'host.localhost', str_repeat( 'a', 64 ) . '.example.com', str_repeat( 'a.', 130 ) . 'com' ) as $bad ) {
			$this->assertNull( AppOwnDomain::normalise( $bad ), $bad );
		}
		$this->assertNull( AppOwnDomain::normalise( array( 'x.example.com' ) ) );
	}

	public function test_the_verdict_needs_this_server_alone(): void {
		$this->assertSame( 'none', AppOwnDomain::verdict( self::IP, array(), array() ) );
		$this->assertSame( 'other', AppOwnDomain::verdict( self::IP, array( '198.51.100.1' ), array() ) );
		$this->assertSame( 'other', AppOwnDomain::verdict( self::IP, array( self::IP, '198.51.100.1' ), array() ) );
		$this->assertSame( 'aaaa', AppOwnDomain::verdict( self::IP, array( self::IP ), array( '2001:db8::1' ) ) );
		$this->assertSame( 'live', AppOwnDomain::verdict( self::IP, array( self::IP, self::IP ), array() ) );
	}

	public function test_public_dns_answers_come_from_the_filter_and_a_failure_is_an_error(): void {
		$this->answers['A'] = array( '203.0.113.9' );
		$this->assertSame( array( '203.0.113.9' ), DnsQuery::addresses( self::NAME, 'A' ) );
		$this->answers['AAAA'] = new \WP_Error( 'dns_transport', 'down' );
		$this->assertInstanceOf( \WP_Error::class, DnsQuery::addresses( self::NAME, 'AAAA' ) );
	}

	// -- The install waits -----------------------------------------------------------------------

	public function test_the_install_waits_and_starts_the_clock_and_a_check(): void {
		$r = $this->waiting();
		AppInstall::run( $r->id() );

		$this->assertNull( $this->fake->guest_file( 5000, Apps::JOB ), 'No install job is written while the name does not point at the server.' );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'pending', $fresh->get( 'app_state' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'app_domain_dns_since' ) );
		$this->assertSame( 1, $this->pending( $r->id(), AppOwnDomain::HOOK ) );
		$this->assertSame( array(), $this->lookups, 'The install itself reads no DNS.' );
	}

	public function test_a_name_that_is_not_there_yet_is_checked_again_later_with_a_plain_reason(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() ) );

		AppOwnDomain::run( $r->id() );
		$this->assertSame( 'none', Resource::find( $r->id() )->get( 'app_domain_dns_reason' ) );
		$this->assertSame( 1, $this->pending( $r->id(), AppOwnDomain::HOOK ), 'The next look is queued.' );

		$this->backdate( Resource::find( $r->id() ), 'app_domain_dns_checked', 700 );
		$this->answers['A'] = array( '198.51.100.7' );
		AppOwnDomain::run( $r->id() );
		$this->assertSame( 'other', Resource::find( $r->id() )->get( 'app_domain_dns_reason' ) );

		$this->backdate( Resource::find( $r->id() ), 'app_domain_dns_checked', 700 );
		$this->answers = array( 'A' => array( self::IP ), 'AAAA' => array( '2001:db8::7' ) );
		AppOwnDomain::run( $r->id() );
		$this->assertSame( 'aaaa', Resource::find( $r->id() )->get( 'app_domain_dns_reason' ) );

		$this->backdate( Resource::find( $r->id() ), 'app_domain_dns_checked', 700 );
		$this->answers = array( 'A' => new \WP_Error( 'dns_transport', 'down' ), 'AAAA' => array() );
		AppOwnDomain::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'unreachable', $fresh->get( 'app_domain_dns_reason' ) );
		$this->assertSame( 'waiting', $fresh->get( 'app_domain_dns' ) );
		$this->assertNull( $fresh->get( 'app_domain' ) );
	}

	public function test_a_read_is_rate_limited_per_server(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() ) );
		AppOwnDomain::run( $r->id() );
		$made = count( $this->lookups );
		$this->assertSame( 2, $made, 'One A and one AAAA read.' );

		AppOwnDomain::run( $r->id() );
		AppOwnDomain::run( $r->id() );
		$this->assertCount( $made, $this->lookups, 'Called again at once, it reads nothing.' );
	}

	public function test_a_name_that_points_at_the_server_goes_to_the_install_exactly_like_one_of_our_zones(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() ) );
		$dns_before = get_option( FakeDns::OPTION );
		$this->answers['A'] = array( self::IP );

		AppOwnDomain::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'live', $fresh->get( 'app_domain_dns' ) );
		$this->assertSame( self::NAME, $fresh->get( 'app_domain' ) );
		$this->assertFalse( $fresh->get( 'app_domain_made' ), 'No record of ours exists, so none is ever removed.' );
		$this->assertSame( 1, $this->pending( $r->id(), AppInstall::HOOK ), 'The install is queued.' );
		$this->assertFalse( AppOwnDomain::waiting( $fresh ) );

		$result = AppInstall::queue( $fresh, $this->fake );
		$this->assertTrue( $result->is_done(), $result->error() );
		$job = json_decode( $this->fake->guest_file( 5000, Apps::JOB ), true );
		$this->assertSame( self::NAME, $job['domain'] );
		$this->assertSame( self::IP, $job['ip'] );
		$this->assertSame( $dns_before, get_option( FakeDns::OPTION ), 'No DNS was written anywhere.' );
	}

	public function test_a_job_that_runs_twice_or_after_the_wait_changes_nothing(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time(), 'app_domain_dns' => 'live', 'app_domain' => self::NAME, 'app_domain_made' => false ) );
		$this->answers['A'] = array( '198.51.100.7' );
		AppOwnDomain::run( $r->id() );
		$this->assertCount( 0, $this->lookups, 'A name that is live is not read again.' );
		$this->assertSame( self::NAME, Resource::find( $r->id() )->get( 'app_domain' ) );
	}

	public function test_a_name_that_never_points_at_the_server_ends_after_fourteen_days_with_the_install_on_the_address(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() - 14 * DAY_IN_SECONDS - 60 ) );
		AppOwnDomain::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'timeout', $fresh->get( 'app_domain_dns' ) );
		$this->assertNull( $fresh->get( 'app_domain' ) );
		$this->assertCount( 0, $this->lookups );
		$this->assertSame( 1, $this->pending( $r->id(), AppInstall::HOOK ) );

		$this->assertTrue( AppInstall::queue( $fresh, $this->fake )->is_done() );
		$job = json_decode( $this->fake->guest_file( 5000, Apps::JOB ), true );
		$this->assertSame( '', $job['domain'], 'The app is installed on the server address.' );

		$pane = $this->pane( $fresh );
		$this->assertStringContainsString( 'data-app-own-domain-timeout', $pane );
		$this->assertStringContainsString( 'did not point to this server within 14 days', $pane );
	}

	public function test_the_sweep_finds_a_lost_check(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() ) );
		$this->assertSame( 0, $this->pending( $r->id(), AppOwnDomain::HOOK ) );
		AppOwnDomain::sweep();
		$this->assertSame( 1, $this->pending( $r->id(), AppOwnDomain::HOOK ) );

		$done = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain' => self::NAME ), 5001 );
		AppOwnDomain::sweep();
		$this->assertSame( 0, $this->pending( $done->id(), AppOwnDomain::HOOK ), 'A live name is left alone.' );
	}

	public function test_a_deleted_server_is_not_read(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() ) );
		$r->set( array( 'state' => 'deleted' ) );
		AppOwnDomain::run( $r->id() );
		$this->assertCount( 0, $this->lookups );
	}

	// -- The customer is told ---------------------------------------------------------------------

	public function test_one_email_says_the_app_is_live_on_the_own_domain(): void {
		$r = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain' => self::NAME, 'app_domain_made' => false, 'app_state' => 'ready' ) );

		AppOwnDomain::installed( $r );
		AppOwnDomain::installed( Resource::find( $r->id() ) );

		$this->assertCount( 1, $this->mails, 'Sent once.' );
		$this->assertSame( array( 'christian.beltoft@gmail.com' ), (array) $this->mails[0]['to'] );
		$this->assertSame( 'Your app is live on your domain', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'https://' . self::NAME, wp_strip_all_tags( $this->mails[0]['message'] ) );
		$this->assertStringNotContainsString( "\u{2014}", $this->mails[0]['message'] );
		$this->assertContains( 'beltoft_services_app_domain_live', Emails::IDS );
	}

	public function test_no_email_for_a_server_without_an_own_domain_or_one_that_never_went_live(): void {
		AppOwnDomain::installed( $this->waiting( array(), 5000 ) );
		$plain = $this->waiting( array( 'app_domain_own' => null, 'app_domain_dns' => null ), 5001 );
		AppOwnDomain::installed( $plain );
		$this->assertCount( 0, $this->mails );
	}

	public function test_the_app_status_tells_the_customer_when_the_install_reports_ready(): void {
		$r = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain' => self::NAME, 'app_domain_made' => false, 'app_state' => 'installing', 'app_request' => 'req-1', 'app_started_at' => time(), 'app_id' => 'docker', 'app_version' => '29' ) );
		$this->fake->set_guest_file( 5000, Apps::STATUS, wp_json_encode( array( 'v' => 1, 'request' => 'req-1', 'state' => 'ready', 'step' => 'complete', 'updated' => gmdate( 'c' ) ) ) );
		$record = \BeltoftServices\Proxmox\VmRecords::for_resource( $r->id() );
		AppStatus::check( Resource::find( $r->id() ), $this->fake, $record['node'], (int) $record['vmid'] );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'app_state' ) );
		$this->assertCount( 1, $this->mails );
	}

	// -- What the App tab says ----------------------------------------------------------------------

	private function pane( Resource $r ): string {
		wp_set_current_user( (int) $r->license()['user_id'] );
		$view = View::license( $r->license() );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		ob_start();
		Servers::render( array( $view ) );
		$html = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '#<section[^>]*data-tab="app"[^>]*>(.*?)</section>#s', $html, $m ) );

		return $m[1];
	}

	public function test_the_tab_says_which_record_to_make_and_what_it_found(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time(), 'app_domain_dns_reason' => 'other' ) );
		$view = AppTab::view( $r );
		$this->assertSame( 'dns', $view['kind'] );

		$pane = $this->pane( $r );
		$this->assertStringContainsString( 'data-app-state="dns"', $pane );
		$this->assertStringContainsString( 'Point an A record for <strong>' . self::NAME . '</strong> to <strong>' . self::IP . '</strong>.', $pane );
		$this->assertStringContainsString( 'does not point to this server only', $pane );
		$this->assertStringContainsString( 'installed on the server address instead', $pane );
		$this->assertStringNotContainsString( "\u{2014}", $pane );
	}

	public function test_the_tab_before_the_server_is_ready_names_the_address_when_it_has_one(): void {
		$r = $this->waiting();
		$r->set( array( 'state' => 'provisioning' ) );
		$pane = $this->pane( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'data-app-own-domain-before', $pane );
		$this->assertStringContainsString( 'Point an A record for ' . self::NAME . ' to ' . self::IP, $pane );
	}

	public function test_a_live_name_is_the_address_of_the_app(): void {
		$r = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain' => self::NAME, 'app_domain_made' => false, 'app_state' => 'ready', 'app_installed_at' => time() ) );
		$view = AppTab::view( $r );
		$this->assertSame( 'ready', $view['kind'] );
		$this->assertTrue( $view['secure'] );
		$this->assertSame( 'https://' . self::NAME . '/wp-admin/', $view['url'] );
		$this->assertStringNotContainsString( 'data-app-own-domain', $this->pane( $r ) );
	}

	// -- Reinstall ------------------------------------------------------------------------------------

	public function test_reinstall_offers_the_own_domain_and_validates_it(): void {
		$r = $this->waiting( array( 'app_id' => 'wordpress', 'app_domain_own' => null, 'app_domain_dns' => null ) );
		ob_start();
		AppReinstall::fields( $r->id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="app_domain_mode" value="own"', $html );
		$this->assertStringContainsString( 'name="app_own"', $html );

		$args = array( 'app_version' => 'keep', 'app_domain_mode' => 'own', 'app_own' => 'Blog.Elsewhere.Test' );
		$this->assertNull( AppReinstall::check( null, $r, $args ) );
		$this->assertStringContainsString( 'not a domain name we can use', (string) AppReinstall::check( null, $r, array_merge( $args, array( 'app_own' => 'http://x' ) ) ) );
		$this->assertSame( 'Enter the domain name you want to use.', AppReinstall::check( null, $r, array_merge( $args, array( 'app_own' => '' ) ) ) );

		$meta = AppReinstall::meta( array(), $r, $args );
		$this->assertSame( 'blog.elsewhere.test', $meta['app_domain_own'] );
		$this->assertSame( 'waiting', $meta['app_domain_dns'] );
		$this->assertNull( $meta['app_domain_dns_since'], 'The wait starts again when the server is ready.' );
		$this->assertNull( $meta['app_domain_want'] );
		$this->assertArrayNotHasKey( 'app_domain_old', $meta, 'No record of ours to remove.' );
	}

	public function test_reinstall_with_no_domain_forgets_a_live_own_name_without_a_note_about_a_record(): void {
		$r = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain' => self::NAME, 'app_domain_made' => false, 'app_state' => 'ready' ) );
		$meta = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$this->assertNull( $meta['app_domain'] );
		$this->assertNull( $meta['app_domain_own'] );
		$this->assertNull( $meta['app_domain_dns'] );
		$this->assertNull( $meta['app_domain_left'], 'We never made a record, so we do not say one was left.' );
		$this->assertArrayNotHasKey( 'app_domain_old', $meta );
	}

	public function test_reinstall_keeping_a_live_own_name_installs_with_it_and_a_name_not_yet_live_waits_again(): void {
		$live = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain' => self::NAME, 'app_domain_made' => false, 'app_state' => 'ready' ) );
		$meta = AppReinstall::meta( array(), $live, array( 'app_version' => 'keep' ) );
		$this->assertArrayNotHasKey( 'app_domain', $meta, 'Kept as it is.' );
		$this->assertArrayNotHasKey( 'app_domain_dns', $meta );

		$timed = $this->waiting( array( 'app_domain_dns' => 'timeout', 'app_domain_dns_since' => time() - 20 * DAY_IN_SECONDS ), 5001 );
		$again = AppReinstall::meta( array(), $timed, array( 'app_version' => 'keep', 'app_domain_mode' => 'keep' ) );
		$this->assertSame( 'waiting', $again['app_domain_dns'] );
		$this->assertNull( $again['app_domain_dns_since'] );
	}

	public function test_a_coolify_or_docker_server_has_no_own_domain(): void {
		$r = $this->waiting( array( 'app_id' => 'docker', 'app_version' => '29', 'app_domain_own' => null, 'app_domain_dns' => null ) );
		$this->assertSame( 'This app does not use a domain at install. You can add one later.', AppReinstall::check( null, $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'own', 'app_own' => self::NAME ) ) );
	}
}

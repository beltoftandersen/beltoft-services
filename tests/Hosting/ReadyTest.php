<?php
/**
 * The HostingReady email, the thank-you and progress entries, the checklist card and the account script.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingEmail;
use BeltoftServices\Progress;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\ThankYou;

/**
 * Email, thank-you, progress, checklist and script.
 */
class ReadyTest extends HostingCase {

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;

				return true;
			},
			10,
			2
		);
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * A ready account.
	 *
	 * @param array<string, mixed> $meta Meta.
	 */
	private function account( array $meta = array() ): Resource {
		$r = $this->resource( array( 'ssh' => true ), $meta );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	public function test_the_email_is_registered_and_chosen_for_a_hosting_account(): void {
		$this->assertContains( 'beltoft_services_hosting_ready', Emails::ids() );
		$this->assertInstanceOf( \BeltoftServices\Emails\HostingReady::class, Emails::get( 'beltoft_services_hosting_ready' ) );

		$r = $this->account();
		$r->set( array( 'notified' => '' ) );
		Reconciler::notify( Resource::find( $r->id() ), 'provisioning', 'ready' );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'is ready', (string) $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Your website', (string) $this->mails[0]['message'] );

		Reconciler::notify( Resource::find( $r->id() ), 'provisioning', 'ready' );
		$this->assertCount( 1, $this->mails, 'Told once.' );
	}

	public function test_the_email_has_the_address_admin_link_and_sftp_details_and_no_password(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();

		foreach ( array( 'html', 'plain' ) as $type ) {
			$this->mails        = array();
			$email              = Emails::get( 'beltoft_services_hosting_ready' );
			$email->email_type  = $type;
			$this->assertTrue( $email->trigger( $r ) );

			$body = (string) $this->mails[0]['message'];
			$this->assertStringContainsString( 'https://' . $slug . '.preview.example.test/', $body, $type );
			$this->assertStringContainsString( 'https://' . $slug . '.preview.example.test/wp-admin/', $body, $type );
			$this->assertStringContainsString( 'Host 198.51.100.7, port 22001, user web', $body, $type );
			$this->assertStringContainsString( 'shown once in My Account', $body, $type );
			$this->assertStringNotContainsString( "\u{2014}", $body, $type );
			$this->assertStringNotContainsString( "\u{00B7}", $body, $type );
			$this->assertStringNotContainsString( \BeltoftServices\Crypto::decrypt( (string) $r->get( 'db_secret' ) ), $body, $type );
		}
	}

	public function test_an_external_domain_adds_its_dns_records_and_an_account_with_email_drops_the_hint(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'www.elsewhere.example' );

		$facts = HostingEmail::values( Resource::find( $r->id() ) );
		$this->assertSame(
			array(
				array( 'name' => 'www.elsewhere.example', 'type' => 'A', 'value' => '198.51.100.7' ),
				array( 'name' => 'www.elsewhere.example', 'type' => 'AAAA', 'value' => '2001:db8::7' ),
				array( 'name' => '_beltoft-verify.www.elsewhere.example', 'type' => 'TXT', 'value' => \BeltoftServices\Hosting\SiteDns::proof_token( $r->id(), 'www.elsewhere.example' ) ),
			),
			$facts['dns_records']
		);
		$this->assertFalse( $facts['has_email'] );

		$email = Emails::get( 'beltoft_services_hosting_ready' );
		$email->trigger( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'Connect your domain', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'www.elsewhere.example', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'so your websites can send mail', (string) $this->mails[0]['message'] );
	}

	public function test_a_resource_that_is_not_hosting_gets_no_hosting_facts(): void {
		$fake = $this->fake_driver();
		$p    = $this->service_product( 'fake', array(), array( 'slug' => 'f-' . wp_generate_password( 4, false ) ) );
		$l    = $this->service_license( $p, array( 'fire' => false ) );
		$r    = Resource::create( $l, $fake, array() );

		$this->assertSame( '', HostingEmail::values( Resource::find( $r->id() ) )['site_url'] );
	}

	public function test_progress_lists_the_hosting_steps_in_words(): void {
		$r = $this->resource();
		$this->agent->pending( 'ct.prepare', 3 );
		$this->provision( $r );

		$progress = Progress::build( Resource::find( $r->id() ) );
		$labels   = array_column( $progress['steps'], 'label', 'key' );

		$this->assertSame( 'build', $progress['kind'] );
		$this->assertSame( 'Reserving resources', $labels['place'] );
		$this->assertSame( 'Creating your container', $labels['clone'] );
		$this->assertSame( 'Installing WordPress', $labels['site'] );
		$this->assertSame( 'Publishing the address', $labels['dns'] );
		$this->assertSame( 'Final checks', $labels['ready'] );
		$this->assertSame( 14, count( $labels ) );
		$this->assertSame( 'prepare', $progress['current'], 'The agent job still runs.' );
	}

	public function test_the_thank_you_row_has_the_open_label_and_the_site_addresses_once_ready(): void {
		$this->assertSame( 'Open your hosting', ThankYou::open_label( 'hosting' ) );

		$r   = $this->account();
		$row = ThankYou::row( $r );

		$this->assertSame( 'hosting', $row['kind'] );
		$this->assertSame( 'https://w' . $r->id() . '.preview.example.test/', $row['hosting']['site_url'] );
		$this->assertStringEndsWith( '/wp-admin/', $row['hosting']['admin_url'] );

		$r->set( array( 'state' => 'provisioning' ) );
		$this->assertArrayNotHasKey( 'hosting', ThankYou::row( Resource::find( $r->id() ) ), 'Nothing to show before it is ready.' );
	}

	// -- The checklist ----------------------------------------------------------------------------

	public function test_the_checklist_reads_domain_and_email_and_takes_two_ticks(): void {
		$r = $this->account();

		$this->assertSame( array( 'domain' => false, 'email' => false, 'build' => false, 'backups' => false ), HostingAccount::checklist( $r ) );
		$this->assertTrue( HostingAccount::checklist_visible( $r ) );

		$a = new HostingActions( $r );
		$a->add_domain( 'w' . $r->id(), 'www.elsewhere.example' );
		$this->connect_domain( $r, 'elsewhere.example' );
		$a->enable_mail( 'www.elsewhere.example' );
		$this->assertSame( array( 'done' => 'build' ), $a->checklist( 'build' ) );
		$a->checklist( 'build' );
		$this->assertSame( array( 'domain' => true, 'email' => true, 'build' => true, 'backups' => false ), HostingAccount::checklist( Resource::find( $r->id() ) ) );
		$this->assertSame( array( 'build' ), Resource::find( $r->id() )->get( 'checklist' ), 'A tick is kept once.' );

		$this->assertSame( 'invalid', $a->checklist( 'domain' )->get_error_code(), 'Only the two manual items.' );

		$a->checklist( 'backups' );
		$this->assertFalse( HostingAccount::checklist_visible( Resource::find( $r->id() ) ), 'Hidden when everything is done.' );
	}

	public function test_the_checklist_can_be_dismissed_and_is_shown_in_the_panel_until_then(): void {
		$r = $this->account();

		ob_start();
		HostingAccount::render( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Getting started', $html );
		$this->assertStringContainsString( 'To do:</span><span class="beltoft-hosting-check-label">Connect your domain', $html );
		$this->assertStringContainsString( 'data-hosting-action="check_item" data-item="build"', $html );
		$this->assertStringContainsString( 'data-hosting-resource="' . $r->id() . '"', $html );

		$this->assertSame( array( 'dismissed' => true ), ( new HostingActions( $r ) )->checklist( '', true ) );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'Getting started', (string) ob_get_clean() );
	}

	public function test_the_checklist_route_belongs_to_the_owner(): void {
		$r    = $this->account();
		$base = '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/checklist';

		wp_set_current_user( (int) $r->license()['user_id'] );
		$ok = new \WP_REST_Request( 'POST', $base );
		$ok->set_header( 'content-type', 'application/json' );
		$ok->set_body( (string) wp_json_encode( array( 'item' => 'backups' ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $ok )->get_status() );

		wp_set_current_user( self::factory()->user->create() );
		$no = new \WP_REST_Request( 'POST', $base );
		$no->set_header( 'content-type', 'application/json' );
		$no->set_body( (string) wp_json_encode( array( 'dismiss' => true ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $no )->get_status() );
		$this->assertNull( Resource::find( $r->id() )->get( 'checklist_dismissed' ) );
	}

	public function test_the_panel_has_action_buttons_only_while_ready_and_the_script_is_enqueued(): void {
		$r = $this->account();
		$this->connect_domain( $r, 'ready.example' );

		ob_start();
		HostingAccount::render( $r );
		$html = (string) ob_get_clean();
		foreach ( array( 'login', 'cache', 'remove_site', 'add_site', 'add_domain', 'enable_mail' ) as $action ) {
			$this->assertStringContainsString( 'data-hosting-action="' . $action . '"', $html, $action );
		}

		$r->set( array( 'desired' => 'paused' ) );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$paused = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-hosting-action', $paused );

		$this->assertTrue( wp_script_is( HostingAccount::HANDLE, 'enqueued' ), 'Printing a hosting panel enqueues the script.' );
		$this->assertContains( \BeltoftServices\Account\Panel::HANDLE, wp_scripts()->registered[ HostingAccount::HANDLE ]->deps );
		wp_dequeue_script( HostingAccount::HANDLE );
		wp_deregister_script( HostingAccount::HANDLE );
	}

	public function test_the_script_exists_calls_only_the_hosting_routes_and_has_no_dash_or_middle_dot(): void {
		$path = BELTOFT_SERVICES_PATH . 'assets/hosting.js';
		$js   = (string) file_get_contents( $path );

		$this->assertNotSame( '', $js );
		$this->assertStringNotContainsString( "\u{2014}", $js );
		$this->assertStringNotContainsString( "\u{00B7}", $js );
		$this->assertStringContainsString( 'beltoftServicesAccount', $js );
		$this->assertStringContainsString( "id + '/hosting'", $js );

		foreach ( array( '/sites', '/checklist', '/mail', '/login', '/cache', '/domains' ) as $route ) {
			$this->assertStringContainsString( $route, $js, $route );
		}

		$this->assertStringNotContainsString( 'eval(', $js );
		$this->assertStringNotContainsString( 'innerHTML', $js, 'Nothing from a reply is put in the page as markup.' );
	}

	public function test_a_plan_switch_that_changes_ssh_sends_ct_prepare_again_and_otherwise_does_not(): void {
		$r = $this->resource( array( 'ssh' => false ) );
		$this->provision_fully( $r );
		$r = Resource::find( $r->id() );
		$this->assertFalse( $r->get( 'prepared_ssh' ) );

		$same = $this->driver->update_plan( $r );
		$this->assertTrue( $same->is_done(), $same->error() );
		$this->assertCount( 1, $this->agent->calls_of( 'ct.prepare' ), 'Nothing changed: nothing is sent.' );

		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'ssh' => true ) ) ) );
		$up = $this->driver->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $up->is_done(), $up->error() );
		$prepare = $this->agent->calls_of( 'ct.prepare' );
		$this->assertCount( 2, $prepare );
		$this->assertTrue( $prepare[1]['args']['ssh'] );
		$this->assertNotSame( $prepare[0]['job_id'], $prepare[1]['job_id'] );
		$this->assertTrue( $this->agent->state()['cts'][1000]['ssh'] );

		$r2 = Resource::find( $r->id() );
		$r2->set( array( 'prepared_ssh' => true ) );
		$this->driver->update_plan( Resource::find( $r->id() ) );
		$this->assertCount( 2, $this->agent->calls_of( 'ct.prepare' ) );
	}

	public function test_the_agent_gets_ssh_and_woocommerce_by_their_contract_names(): void {
		$r = $this->resource( array( 'ssh' => false ), array( 'site' => array( 'slug' => 'shopsite', 'install_kind' => 'woocommerce', 'install' => true ) ) );
		$this->provision_fully( $r );

		$this->assertFalse( $this->agent->calls_of( 'ct.prepare' )[0]['args']['ssh'], 'SFTP only on a plan without SSH.' );
		$this->assertTrue( $this->agent->calls_of( 'site.wp_install' )[0]['args']['woocommerce'] );

		$plain = $this->resource( array( 'ssh' => true ) );
		$this->provision_fully( $plain );
		$this->assertTrue( $this->agent->calls_of( 'ct.prepare' )[1]['args']['ssh'] );
		$this->assertFalse( $this->agent->calls_of( 'site.wp_install' )[1]['args']['woocommerce'] );
	}
}

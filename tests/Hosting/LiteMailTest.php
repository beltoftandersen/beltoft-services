<?php
/**
 * Email lite as a child mail resource, the Children cascade and the per-resource mail limits.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Children;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\LiteMail;
use BeltoftServices\Mail\FakeMailcowClient;
use BeltoftServices\Mail\MailDomain;
use BeltoftServices\Mail\MailSettings;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

/**
 * LiteMail, Children and MailSettings::override.
 */
class LiteMailTest extends HostingCase {

	/**
	 * The fake mail server.
	 *
	 * @var FakeMailcowClient
	 */
	private $mailcow;

	public function set_up(): void {
		parent::set_up();

		delete_option( FakeMailcowClient::OPTION );
		$this->mailcow = new FakeMailcowClient();
		add_filter( 'beltoft_services_mail_client', fn() => $this->mailcow );
		$this->settings(
			array(
				'domain_nameservers' => 'ns1.example.net,ns2.example.net',
				'mail_host'          => 'mail.example.net',
				'mail_spf_include'   => '_spf.mail.example.net',
				'mail_rl_minute'     => 30,
				'mail_rl_day'        => 500,
			)
		);
		// The customer holds the domain: the proof is found. Public lookups find nothing.
		$proof = array(
			'delegation' => array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.host.example' ), 'ips' => array(), 'ds' => null, 'ours' => false ),
			'check_txt'  => array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' ),
		);
		add_filter( 'beltoft_services_dns_proof_result', fn( $pre, $call ) => $proof[ $call ] ?? $pre, 10, 2 );
		add_filter( 'beltoft_services_mail_dns_lookup', fn() => array(), 10, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_mail_client' );
		remove_all_filters( 'beltoft_services_dns_proof_result' );
		remove_all_filters( 'beltoft_services_mail_dns_lookup' );
		remove_all_filters( 'pre_wp_mail' );
		delete_option( FakeMailcowClient::OPTION );

		parent::tear_down();
	}

	/**
	 * A provisioned hosting account.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function account( array $plan = array() ): Resource {
		$r = $this->resource( array_merge( array( 'mail_mailboxes' => 5, 'mail_quota_mb' => 1024, 'mail_aliases' => 3, 'mail_per_minute' => 60 ), $plan ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Provisions a child with the mail driver and marks it ready, as the reconciler would.
	 *
	 * @param Resource $child Child.
	 */
	private function make_ready( Resource $child ): Resource {
		$result = Registry::get( 'mail' )->provision( Resource::find( $child->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$child->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) );

		return Resource::find( $child->id() );
	}

	public function test_enable_makes_a_linked_mail_child_with_the_plan_limits(): void {
		$parent = $this->account();

		$child = LiteMail::enable( $parent, 'Customer.Example' );

		$this->assertInstanceOf( Resource::class, $child );
		$this->assertSame( 'mail', $child->get( 'driver' ) );
		$this->assertSame( 'customer.example', $child->get( MailDomain::META_DOMAIN ) );
		$this->assertSame( array( 'mailboxes' => 5, 'quota_mb' => 1024, 'aliases' => 3, 'domains' => 1 ), $child->get( 'plan' ) );
		$this->assertSame( $parent->id(), (int) $child->get( Children::PARENT ) );
		$this->assertSame( array( $child->id() ), Children::ids( Resource::find( $parent->id() ) ) );
		$this->assertSame( $parent->id(), Children::parent_of( $child )->id() );
		$this->assertSame( 'child', $child->license()['source'] );
		$this->assertSame( (int) $parent->license()['user_id'], (int) $child->license()['user_id'] );
		$this->assertNotSame( (int) $parent->license()['id'], (int) $child->license()['id'], 'A resource needs a license of its own.' );

		$ready = $this->make_ready( $child );
		$this->assertSame( 'ready', $ready->get( 'state' ) );
		$domain = $this->mailcow->domain( 'customer.example' );
		$this->assertSame( '5', (string) $domain['mailboxes'] );
		$this->assertSame( '1024', (string) $domain['maxquota'] );
		$this->assertSame( array( 'value' => 60, 'frame' => 'm' ), $domain['rl'], 'The plan limit, not the global 30.' );
	}

	public function test_an_email_lite_child_is_not_an_email_plan_member_and_not_in_the_email_list(): void {
		$parent = $this->account();
		$child  = $this->make_ready( LiteMail::enable( $parent, 'lite.example' ) );
		$area   = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Email::class );

		$this->assertFalse( $area->is_member( $child->license() ) );

		wp_set_current_user( (int) $child->license()['user_id'] );
		$GLOBALS['wp']->query_vars[ $area->slug() ] = '';
		ob_start();
		$area->render( array( \BeltoftLicenses\Account\View::license( $child->license() ) ) );
		$html = (string) ob_get_clean();
		unset( $GLOBALS['wp']->query_vars[ $area->slug() ] );
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'lite.example', $html );
	}

	public function test_the_hosting_account_is_ready_without_email_and_enable_has_rules(): void {
		$parent = $this->account();
		$this->assertSame( array(), Children::of( $parent ) );

		$this->assertSame( 'bad_domain', LiteMail::enable( $parent, 'not a domain' )->get_error_code() );
		$this->assertSame( 'no_mail', LiteMail::enable( $this->account( array( 'mail_mailboxes' => 0 ) ), 'a.example' )->get_error_code() );

		$unbuilt = $this->resource();
		$this->assertSame( 'not_hosting', LiteMail::enable( $unbuilt, 'b.example' )->get_error_code(), 'No container yet.' );

		$this->assertInstanceOf( Resource::class, LiteMail::enable( $parent, 'one.example' ) );
		$this->assertSame( 'exists', LiteMail::enable( $parent, 'two.example' )->get_error_code(), 'One Email lite domain per account.' );
	}

	public function test_the_child_follows_the_parent_through_pause_resume_and_delete(): void {
		$parent = $this->account();
		$child  = $this->make_ready( LiteMail::enable( $parent, 'follow.example' ) );

		Reconciler::notify( Resource::find( $parent->id() ), 'ready', 'paused' );
		$paused = Resource::find( $child->id() );
		$this->assertSame( 'paused', $paused->get( 'desired' ) );
		$this->assertSame( 1, (int) $paused->get( Children::MARKER ) );

		Reconciler::notify( Resource::find( $parent->id() ), 'paused', 'ready' );
		$back = Resource::find( $child->id() );
		$this->assertSame( 'ready', $back->get( 'desired' ) );
		$this->assertNull( $back->get( Children::MARKER ) );

		Reconciler::notify( Resource::find( $parent->id() ), 'deleting', 'deleted' );
		$this->assertSame( 'deleted', Resource::find( $child->id() )->get( 'desired' ) );
	}

	public function test_a_child_paused_for_its_own_reason_is_not_resumed_by_the_parent(): void {
		$parent = $this->account();
		$child  = $this->make_ready( LiteMail::enable( $parent, 'own.example' ) );
		$child->desire( 'paused', 'The customer paused it' );

		Reconciler::notify( Resource::find( $parent->id() ), 'paused', 'ready' );

		$this->assertSame( 'paused', Resource::find( $child->id() )->get( 'desired' ), 'Only a pause the cascade made is undone.' );
	}

	public function test_the_cascade_ignores_resources_without_children_and_stray_events(): void {
		$parent = $this->account();
		Reconciler::notify( $parent, 'ready', 'paused' );
		$this->assertSame( 'ready', Resource::find( $parent->id() )->get( 'desired' ), 'The parent itself is not changed by its own event.' );

		Children::follow( 'not a resource', 'a', 'b' );
		$this->assertSame( array(), Children::of( $parent ) );

		$child = LiteMail::enable( $parent, 'stray.example' );
		$child->set( array( Children::PARENT => 999999 ) );
		$this->assertSame( array(), Children::of( Resource::find( $parent->id() ) ), 'A child that points elsewhere is not followed.' );
	}

	public function test_a_deleted_parent_runs_the_child_delete_through_the_mail_driver(): void {
		$parent = $this->account();
		$child  = $this->make_ready( LiteMail::enable( $parent, 'bye.example' ) );
		$this->assertNotNull( $this->mailcow->domain( 'bye.example' ) );

		Reconciler::notify( Resource::find( $parent->id() ), 'deleting', 'deleted' );
		$fresh = Resource::find( $child->id() );
		$fresh->set( array( 'next_attempt_at' => null ) );
		Reconciler::step( $fresh );

		$this->assertNull( $this->mailcow->domain( 'bye.example' ), 'The domain is removed from the mail server.' );
	}

	public function test_a_per_resource_limit_wins_for_that_resource_only(): void {
		$this->assertSame( array( 30, 'm' ), MailSettings::domain_rate_limit() );
		$this->assertSame( array( 500, 'd' ), MailSettings::rate_limit() );

		$parent = $this->account();
		$child  = LiteMail::enable( $parent, 'limits.example' );

		$this->assertSame( array( 60, 'm' ), MailSettings::domain_rate_limit( $child ) );
		$this->assertSame( array( 500, 'd' ), MailSettings::rate_limit( $child ), 'A key that is not set leaves the global value.' );

		$child->set( array( 'mail_rl' => array( 'minute' => 0, 'day' => 1000 ) ) );
		$child = Resource::find( $child->id() );
		$this->assertNull( MailSettings::domain_rate_limit( $child ), '0 turns the limit off for this domain.' );
		$this->assertSame( array( 1000, 'd' ), MailSettings::rate_limit( $child ) );

		$other = $this->account();
		$this->assertSame( array( 30, 'm' ), MailSettings::domain_rate_limit( $other ), 'Another resource keeps the global value.' );
		$this->assertSame( 77, MailSettings::override( null, 'day', 77 ) );
		$child->set( array( 'mail_rl' => array( 'day' => 'x' ) ) );
		$this->assertSame( 5, MailSettings::override( Resource::find( $child->id() ), 'day', 5 ), 'A value that is not a number is ignored.' );
	}

	public function test_a_mailbox_of_the_child_gets_the_child_day_limit(): void {
		$parent = $this->account();
		$child  = LiteMail::enable( $parent, 'boxes.example' );
		$child->set( array( 'mail_rl' => array( 'minute' => 60, 'day' => 250 ) ) );
		$child = $this->make_ready( Resource::find( $child->id() ) );

		$made = Mailboxes::create( $child, 'info', 'Info', 0 );
		$this->assertFalse( is_wp_error( $made ), is_wp_error( $made ) ? $made->get_error_message() : '' );

		$this->assertSame( array( 'value' => 250, 'frame' => 'd' ), $this->mailcow->mailbox( 'info@boxes.example' )['rl'], 'The child day limit, not the global 500.' );
	}
}

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

		$child = $this->lite( $parent, 'Customer.Example' );

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

	public function test_an_email_lite_child_is_listed_in_the_email_area_with_its_own_page_and_label(): void {
		$parent = $this->account();
		$child  = $this->make_ready( $this->lite( $parent, 'lite.example' ) );
		$area   = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Email::class );

		$this->assertTrue( $area->is_member( $child->license() ), 'The licence is grouped under Email, so the Licenses tab does not list it as a separate key.' );

		$matching = array_values( array_filter( \BeltoftServices\Account\Tabs::groups( array() ), static fn( $g ) => isset( $g['match'] ) && $g['match']( $child->license() ) ) );
		$this->assertCount( 1, $matching, 'One tab claims it, so the generic Licenses tab leaves it out.' );
		$this->assertSame( $area->slug(), $matching[0]['slug'] );

		wp_set_current_user( (int) $child->license()['user_id'] );
		$view = \BeltoftLicenses\Account\View::license( $child->license() );

		$GLOBALS['wp']->query_vars[ $area->slug() ] = '';
		ob_start();
		$area->render( array( $view ) );
		$list = (string) ob_get_clean();
		$this->assertStringContainsString( 'lite.example', $list );
		$this->assertStringContainsString( 'Included with', $list );

		// Its own page: the normal mailbox page, labelled as included, with no plan or domain actions.
		$GLOBALS['wp']->query_vars[ $area->slug() ] = (string) $child->license()['id'];
		ob_start();
		$area->render( array( $view ) );
		$page = (string) ob_get_clean();
		unset( $GLOBALS['wp']->query_vars[ $area->slug() ] );
		wp_set_current_user( 0 );

		$this->assertStringNotContainsString( 'was not found', $page );
		$this->assertStringContainsString( 'lite.example', $page );
		$this->assertStringContainsString( 'Included with', $page );
		$this->assertStringContainsString( 'data-mail-card', $page );
		$this->assertStringNotContainsString( 'Domains in this plan', $page );
	}

	public function test_the_included_label_comes_from_the_hosting_filter(): void {
		$parent = $this->account();
		$child  = $this->make_ready( $this->lite( $parent, 'label.example' ) );

		$this->assertStringStartsWith( 'Included with ', \BeltoftServices\Mail\MailView::data( $child, array() )['included'] );

		remove_filter( 'beltoft_services_mail_included_label', array( \BeltoftServices\Hosting\HostingAccount::class, 'included_label' ), 10 );
		$this->assertSame( '', \BeltoftServices\Mail\MailView::data( $child, array() )['included'] );
		add_filter( 'beltoft_services_mail_included_label', array( \BeltoftServices\Hosting\HostingAccount::class, 'included_label' ), 10, 2 );
	}

	public function test_email_lite_follows_the_accounts_own_domain_never_the_preview_address(): void {
		$parent = $this->account();
		$slug   = 'w' . $parent->id();

		// Only the preview address: no own domain, so nothing is offered or made.
		$this->assertNull( LiteMail::own_domain( $parent ) );
		$preview = $slug . '.preview.example.test';
		$this->assertSame( 'no_domain', LiteMail::enable( $parent, $preview )->get_error_code() );
		$this->assertSame( 'no_domain', LiteMail::enable( $parent, '' )->get_error_code() );

		// The main domain is the oldest non-preview name, whatever its state: a waiting outside name is not connected yet,
		// and a younger active name does not take its place.
		\BeltoftServices\Hosting\SiteDomains::add( $parent->id(), 0, 'waiting.example', 'external' );
		\BeltoftServices\Hosting\SiteDomains::add( $parent->id(), 0, $preview, 'preview' );
		\BeltoftServices\Hosting\SiteDomains::set_state( $preview, 'active' );
		\BeltoftServices\Hosting\SiteDomains::add( $parent->id(), 0, 'main.example', 'ours' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'main.example', 'active' );
		$this->assertSame( 'waiting.example', LiteMail::own_domain( $parent ) );
		$this->assertNull( LiteMail::ready_domain( $parent ) );
		$this->assertSame( 'no_domain', LiteMail::enable( $parent, 'main.example' )->get_error_code(), 'Not offered until the main domain is active.' );

		// Once the oldest name is gone, the next oldest is the main domain; the preview address and other names are refused.
		\BeltoftServices\Hosting\SiteDomains::remove( 'waiting.example' );
		$this->assertSame( 'main.example', LiteMail::own_domain( $parent ) );
		$this->assertSame( 'main.example', LiteMail::ready_domain( $parent ) );
		$this->assertSame( 'not_own_domain', LiteMail::enable( $parent, $preview )->get_error_code() );
		$this->assertSame( 'not_own_domain', LiteMail::enable( $parent, 'other.example' )->get_error_code() );

		$child = LiteMail::enable( $parent, '' );
		$this->assertInstanceOf( Resource::class, $child, 'Empty means the account\'s own domain.' );
		$this->assertSame( 'main.example', $child->get( MailDomain::META_DOMAIN ) );
		$this->assertSame( 1, (int) ( $child->get( 'plan' )['domains'] ?? 0 ), 'One mail domain, no multi-domain.' );

		// The main domain changes later: Email lite stays on its first domain, and the page says so.
		\BeltoftServices\Hosting\SiteDomains::remove( 'main.example' );
		\BeltoftServices\Hosting\SiteDomains::add( $parent->id(), 0, 'new.example', 'ours' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'new.example', 'active' );
		ob_start();
		\BeltoftServices\Hosting\HostingAccount::render( Resource::find( $parent->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Email lite stays on main.example. It does not move when the main domain changes.', $html );
	}

	public function test_the_account_page_offers_email_lite_only_with_a_connected_domain(): void {
		$parent = $this->account();
		ob_start();
		\BeltoftServices\Hosting\HostingAccount::render( $parent );
		$none = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-hosting-action="enable_mail"', $none );

		$this->connect_domain( $parent, 'offer.example' );
		ob_start();
		\BeltoftServices\Hosting\HostingAccount::render( Resource::find( $parent->id() ) );
		$with = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-hosting-action="enable_mail"', $with );
		$this->assertStringContainsString( 'Set up Email lite on offer.example', $with );
	}

	public function test_the_hosting_account_is_ready_without_email_and_enable_has_rules(): void {
		$parent = $this->account();
		$this->assertSame( array(), Children::of( $parent ) );

		$this->assertSame( 'no_domain', LiteMail::enable( $parent, 'a.example' )->get_error_code(), 'No own domain connected yet.' );
		$this->connect_domain( $parent, 'own.example' );
		$this->assertSame( 'bad_domain', LiteMail::enable( $parent, 'not a domain' )->get_error_code() );
		$this->assertSame( 'no_mail', $this->lite( $this->account( array( 'mail_mailboxes' => 0 ) ), 'a.example' )->get_error_code() );

		$unbuilt = $this->resource();
		$this->assertSame( 'not_hosting', $this->lite( $unbuilt, 'b.example' )->get_error_code(), 'No container yet.' );

		$this->assertInstanceOf( Resource::class, $this->lite( $parent, 'own.example' ) );
		$this->assertSame( 'exists', $this->lite( $parent, 'two.example' )->get_error_code(), 'One Email lite domain per account.' );
	}

	public function test_the_child_follows_the_parent_through_pause_resume_and_delete(): void {
		$parent = $this->account();
		$child  = $this->make_ready( $this->lite( $parent, 'follow.example' ) );

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
		$child  = $this->make_ready( $this->lite( $parent, 'own.example' ) );
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

		$child = $this->lite( $parent, 'stray.example' );
		$child->set( array( Children::PARENT => 999999 ) );
		$this->assertSame( array(), Children::of( Resource::find( $parent->id() ) ), 'A child that points elsewhere is not followed.' );
	}

	public function test_a_deleted_parent_runs_the_child_delete_through_the_mail_driver(): void {
		$parent = $this->account();
		$child  = $this->make_ready( $this->lite( $parent, 'bye.example' ) );
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
		$child  = $this->lite( $parent, 'limits.example' );

		$this->assertSame( array( 60, 'm' ), MailSettings::domain_rate_limit( $child ) );
		$this->assertSame( array( 500, 'd' ), MailSettings::rate_limit( $child ), 'A key that is not set leaves the global value.' );

		$child->set( array( \BeltoftServices\Mail\MailMeta::RATE_LIMIT => array( 'minute' => 0, 'day' => 1000 ) ) );
		$child = Resource::find( $child->id() );
		$this->assertNull( MailSettings::domain_rate_limit( $child ), '0 turns the limit off for this domain.' );
		$this->assertSame( array( 1000, 'd' ), MailSettings::rate_limit( $child ) );

		$other = $this->account();
		$this->assertSame( array( 30, 'm' ), MailSettings::domain_rate_limit( $other ), 'Another resource keeps the global value.' );
		$this->assertSame( 77, MailSettings::override( null, 'day', 77 ) );
		$child->set( array( \BeltoftServices\Mail\MailMeta::RATE_LIMIT => array( 'day' => 'x' ) ) );
		$this->assertSame( 5, MailSettings::override( Resource::find( $child->id() ), 'day', 5 ), 'A value that is not a number is ignored.' );
	}

	public function test_a_mailbox_of_the_child_gets_the_child_day_limit(): void {
		$parent = $this->account();
		$child  = $this->lite( $parent, 'boxes.example' );
		$child->set( array( \BeltoftServices\Mail\MailMeta::RATE_LIMIT => array( 'minute' => 60, 'day' => 250 ) ) );
		$child = $this->make_ready( Resource::find( $child->id() ) );

		$made = Mailboxes::create( $child, 'info', 'Info', 0 );
		$this->assertFalse( is_wp_error( $made ), is_wp_error( $made ) ? $made->get_error_message() : '' );

		$this->assertSame( array( 'value' => 250, 'frame' => 'd' ), $this->mailcow->mailbox( 'info@boxes.example' )['rl'], 'The child day limit, not the global 500.' );
	}

	public function test_the_email_lite_page_is_headed_email_lite_and_a_plan_domain_keeps_business_email(): void {
		$r     = $this->account();
		$child = $this->lite( $r, 'lite.customer.example' );
		$area  = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Email::class );
		$this->assertSame( 'Email lite', $area->label_of( Resource::find( $child->id() ) ) );

		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/mail.css' );
		$this->assertMatchesRegularExpression( '/@media \(max-width: 700px\).*mail__actions \{ width: auto; min-width: 0;/s', $css, 'No 430px minimum on a phone.' );
	}

	public function test_a_child_that_cannot_be_made_leaves_no_active_license(): void {
		$parent = $this->account();
		$this->connect_domain( $parent, 'customer.example' );
		$other = null;

		add_filter( 'beltoft_services_lock_timeout', '__return_zero' );
		// Another request holds the new license's lock, so the service cannot be created.
		add_action(
			'beltoft_licenses_license_created',
			function ( $license ) use ( &$other ) {
				$license = is_array( $license ) ? $license : array( 'id' => (int) $license );
				$other   = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
				$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( \BeltoftServices\Locks::name( 'license', (int) $license['id'] ) ) . "', 0)" );
			}
		);

		$res = LiteMail::enable( $parent, 'customer.example' );
		$this->assertWPError( $res );
		$this->assertNotNull( $other, 'the hook ran' );
		$other->close();

		$slug  = sanitize_title( (string) $parent->license()['product_slug'] ) . '-mail';
		$found = array_values( array_filter( \BeltoftLicenses\Licenses::where( 'user_id', (int) $parent->license()['user_id'] ), static fn( $l ) => $slug === $l['product_slug'] ) );
		$this->assertCount( 1, $found );
		$this->assertSame( \BeltoftLicenses\Licenses::STATUS_REVOKED, $found[0]['status'] );
		remove_all_actions( 'beltoft_licenses_license_created' );
		remove_filter( 'beltoft_services_lock_timeout', '__return_zero' );
	}

	/**
	 * The record is not there for the mail service, but the hosting account found it for the same domain.
	 *
	 * @param bool $by_record The hosting name's proof was the record itself.
	 */
	private function mail_on_hosting_name( bool $by_record ): array {
		remove_all_filters( 'beltoft_services_dns_proof_result' );
		$proof = array(
			'delegation' => array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.host.example' ), 'ips' => array(), 'ds' => null, 'ours' => false ),
			'check_txt'  => array( 'status' => 'missing', 'servers' => array(), 'reason' => 'missing' ),
		);
		add_filter( 'beltoft_services_dns_proof_result', fn( $pre, $call ) => $proof[ $call ] ?? $pre, 10, 2 );

		$parent = $this->account();
		$child  = $this->lite( $parent, 'proved.example' );
		$this->assertInstanceOf( Resource::class, $child );

		// The account's name is an outside name that passed the record (or was proven by something else).
		global $wpdb;
		$wpdb->update( \BeltoftServices\Hosting\Schema::tables()['hosting_domains'], array( 'kind' => 'external' ), array( 'host' => 'proved.example' ) );

		if ( $by_record ) {
			$parent->set( array( \BeltoftServices\Hosting\SiteDns::META_PROOFS => array( 'proved.example' => time() ) ) );
		}

		return array( $parent, $child );
	}

	public function test_the_mail_domain_of_a_hosting_account_needs_no_second_proof(): void {
		list( , $child ) = $this->mail_on_hosting_name( true );

		$result = Registry::get( 'mail' )->provision( Resource::find( $child->id() ) );
		$fresh  = Resource::find( $child->id() );
		$this->assertContains( 'proof', (array) $fresh->get( 'steps' ), 'The proof step is passed: ' . $result->error() );
		$this->assertSame( 'external', $fresh->get( 'mail_mode' ) );
		$this->assertSame( MailDomain::VIA_OTHER, $fresh->get( MailDomain::META_VIA ), 'It is marked as taken from another service.' );
	}

	public function test_a_hosting_name_that_was_not_proven_by_the_record_proves_nothing_for_mail(): void {
		list( , $child ) = $this->mail_on_hosting_name( false );

		Registry::get( 'mail' )->provision( Resource::find( $child->id() ) );
		$this->assertNotContains( 'proof', (array) Resource::find( $child->id() )->get( 'steps' ), 'A name active for another reason is not passed on.' );
	}

	public function test_another_customers_hosting_proof_does_not_count_for_mail(): void {
		list( , $child ) = $this->mail_on_hosting_name( true );

		$other = $this->account();
		$this->assertFalse( \BeltoftServices\Domains\Ownership::proven( (int) $other->license()['user_id'], 'proved.example' ) );
		$this->assertFalse( \BeltoftServices\Domains\Ownership::proven( (int) $other->license()['user_id'], 'www.proved.example' ) );
		$this->assertTrue( \BeltoftServices\Domains\Ownership::proven( (int) Resource::find( $child->id() )->license()['user_id'], 'www.proved.example' ), 'The same customer: a name below it is covered.' );
	}
}

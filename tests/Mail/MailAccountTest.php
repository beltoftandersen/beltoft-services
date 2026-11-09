<?php
/**
 * The Email area of My Account: tab, list, domain page and its four tabs, the Overview and the rules for the files.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Email;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Slugs;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Mail\MailAccess;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';

final class MailAccountTest extends MailTestCase {

	private int $user = 0;

	public function set_up(): void {
		parent::set_up();

		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['email'] );
		foreach ( wp_scripts()->queue as $handle ) {
			if ( 0 === strpos( $handle, 'beltoft-services-' ) ) {
				wp_dequeue_script( $handle );
			}
		}
		foreach ( wp_styles()->queue as $handle ) {
			if ( 0 === strpos( $handle, 'beltoft-services-' ) ) {
				wp_dequeue_style( $handle );
			}
		}
		Overview::reset();
		remove_all_filters( 'beltoft_services_email_slug' );
		parent::tear_down();
	}

	private function area(): Email {
		return ServiceArea::get( Email::class );
	}

	/** @return array<int, array<string, mixed>> */
	private function views( Resource ...$resources ): array {
		return array_map( static fn( Resource $r ): array => View::license( $r->license() ), $resources );
	}

	private function render( array $views, string $value = '' ): string {
		$GLOBALS['wp']->query_vars[ $this->area()->slug() ] = $value;
		ob_start();
		$this->area()->render( $views );

		return (string) ob_get_clean();
	}

	/** The page of a domain, by the id in the address. */
	private function page( Resource $r, string $tab = '' ): string {
		return $this->render( $this->views( $r ), $r->license()['id'] . ( '' !== $tab ? '/' . $tab : '' ) );
	}

	public function test_email_has_its_own_tab_and_leaves_the_licenses_tab(): void {
		$r     = $this->ready_mail( 'tab.example', array(), $this->user );
		$other = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) );
		$this->fake_driver();

		$groups = Endpoint::groups();
		$this->assertSame( 'Email', $groups['email']['label'] );
		$this->assertSame( 'You have no email domains yet.', $groups['email']['empty'] );

		$tabs = Endpoint::split( array( $r->license(), $other ) );
		$this->assertSame( array( $r->license()['id'] ), array_column( $tabs['email'], 'id' ) );
		$this->assertSame( array( $other['id'] ), array_column( $tabs[''], 'id' ) );
		$this->assertContains( 'email', Endpoint::slugs() );
		$this->assertSame( wc_get_account_endpoint_url( 'email' ), Endpoint::url_for_license( $r->license() ) );
	}

	public function test_the_row_names_the_domain_and_counts_mailboxes_of_the_plan(): void {
		$r = $this->ready_mail( 'rows.example', array(), $this->user );
		Mailboxes::create( $r, 'a' );
		Mailboxes::create( Resource::find( $r->id() ), 'b' );
		ob_start();
		$this->page( Resource::find( $r->id() ) );
		ob_end_clean();
		$r = Resource::find( $r->id() );

		$row = $this->area()->row( $this->views( $r )[0], $r );

		$this->assertSame( 'rows.example', $row['name'] );
		$this->assertSame( '2 of 5 mailboxes', $row['details'] );
		$this->assertSame( 'Ready', $row['state'] );
		$this->assertSame( 'ok', $row['tone'] );
		$this->assertSame( 'DNS records missing', $row['extra'] );
		$this->assertSame( $this->area()->url( $r->license() ), $row['url'] );

		$html = $this->render( $this->views( $r ) );
		$this->assertStringContainsString( 'rows.example', $html );
		$this->assertStringContainsString( '2 of 5 mailboxes', $html );
		$this->assertStringContainsString( 'beltoft-services-servers--area', $html );
	}

	public function test_an_empty_list_says_so_and_a_strangers_domain_is_never_shown(): void {
		$this->assertStringContainsString( 'You have no email domains yet.', $this->render( array() ) );

		$theirs = $this->ready_mail( 'theirs.example', array(), self::factory()->user->create() );
		$html   = $this->render( array(), (string) $theirs->license()['id'] );

		$this->assertStringContainsString( 'That email domain was not found', $html );
		$this->assertStringNotContainsString( 'theirs.example', $html );
	}

	public function test_the_page_has_four_tabs_with_the_mailboxes_first_and_the_domain_as_title(): void {
		$r  = $this->ready_mail( 'pages.example', array(), $this->user );
		$id = (int) $r->license()['id'];

		$this->assertSame( array( 'overview', 'aliases', 'dns', 'settings' ), array_keys( $this->area()->nav() ) );
		$this->assertSame( array( 'Mailboxes', 'Aliases', 'DNS', 'Connect' ), array_values( $this->area()->nav() ) );

		$html = $this->page( $r );

		$this->assertStringContainsString( 'All email domains', $html );
		$this->assertStringContainsString( '<h2 class="beltoft-services-page__name">pages.example</h2>', $html );
		$this->assertStringContainsString( 'Business email', $html );
		$this->assertStringContainsString( 'aria-current="page">Mailboxes', $html );
		foreach ( array( 'aliases', 'dns', 'settings' ) as $tab ) {
			$this->assertStringContainsString( 'href="' . esc_url( $this->area()->url( $r->license(), $tab ) ) . '"', $html );
		}
		$this->assertStringContainsString( 'You have no mailboxes yet', $html );
		$this->assertStringContainsString( 'data-mail-create-submit', $html );
		$this->assertStringContainsString( '0 of 5', $html );
		$this->assertStringContainsString( 'data-resource="' . $r->id() . '"', $html );
		$this->assertTrue( wp_script_is( 'beltoft-services-mail', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'beltoft-services-mail', 'enqueued' ) );
		$this->assertSame( $id, (int) $r->license()['id'] );
	}

	public function test_the_slug_is_the_domain_and_the_address_has_no_number(): void {
		$r = $this->ready_mail( 'slug.example', array(), $this->user );

		$this->assertSame( 'slug.example', Slugs::for_license( $r->license() ) );
		$url = $this->area()->url( $r->license() );
		$dns = $this->area()->url( $r->license(), 'dns' );
		$this->assertStringContainsString( 'slug.example', $url );
		$this->assertStringNotContainsString( (string) $r->license()['id'], $url );
		$this->assertStringContainsString( 'slug.example', $dns );
		$this->assertMatchesRegularExpression( '#slug\.example(/|%2F)dns#', $dns );
		$this->assertSame( 'email', Slugs::type_of( $r->license() ) );
	}

	public function test_mailboxes_show_storage_and_buttons_but_never_a_password(): void {
		$r = $this->ready_mail( 'boxes.example', array(), $this->user );
		Mailboxes::create( $r, 'info', 'Info desk' );
		$this->mailcow->set_usage( 'info@boxes.example', 2560 * 1048576 );
		$secret = (string) \BeltoftServices\Crypto::decrypt( (string) ( (array) Resource::find( $r->id() )->get( MailAccess::META ) )['info@boxes.example']['pw'] );

		$html = $this->page( Resource::find( $r->id() ) );

		$this->assertStringContainsString( 'info@boxes.example', $html );
		$this->assertStringContainsString( 'Info desk', $html );
		$this->assertStringContainsString( '2.5 GB of 10 GB (25%)', $html );
		$this->assertStringContainsString( 'data-mail-reveal="info@boxes.example"', $html );
		$this->assertStringContainsString( 'data-mail-reset="info@boxes.example"', $html );
		$this->assertStringContainsString( 'data-mail-delete="info@boxes.example"', $html );
		$this->assertStringContainsString( 'Type DELETE to confirm', $html );
		$this->assertStringNotContainsString( $secret, $html );

		// After the password was shown there is nothing to show.
		MailAccess::take( Resource::find( $r->id() ), 'info@boxes.example', $this->user );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'data-mail-reveal', $html );
		$this->assertStringContainsString( 'data-mail-reset', $html );
	}

	public function test_the_size_field_is_offered_when_the_plan_is_large_enough(): void {
		$r    = $this->ready_mail( 'sizes.example', array(), $this->user );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'data-mail-size', $html );
		$this->assertStringContainsString( 'max="10"', $html );

		$small = $this->ready_mail( 'tiny.example', array( 'quota_mb' => 1024 ), $this->user );
		$this->assertStringNotContainsString( 'data-mail-size', $this->page( $small ) );
	}

	public function test_a_full_plan_has_no_create_form(): void {
		$r = $this->ready_mail( 'full.example', array( 'mailboxes' => 1 ), $this->user );
		Mailboxes::create( $r, 'a' );

		$html = $this->page( Resource::find( $r->id() ) );

		$this->assertStringContainsString( 'Your plan has no room for more mailboxes. Delete a mailbox or change to a larger plan.', $html );
		$this->assertSame( 1, substr_count( $html, 'has no room for more mailboxes' ) );
		$this->assertStringNotContainsString( 'data-mail-create-submit', $html );
	}

	public function test_a_domain_that_is_not_ready_says_what_it_waits_for(): void {
		$this->proof['check_txt'] = array( 'status' => 'missing', 'servers' => array(), 'reason' => 'not_found' );
		$r = $this->mail_resource( 'wait.example', array(), array(), $this->user );
		$result = \BeltoftServices\Drivers\Registry::get( 'mail' )->provision( $r );
		$this->assertFalse( $result->is_done() || $result->is_failed(), 'It waits for the proof.' );
		$r->set( array_merge( $result->meta(), array( 'state' => 'provisioning', 'desired' => 'ready' ) ) );
		$r = Resource::find( $r->id() );

		$mailboxes = $this->page( $r );
		$this->assertStringContainsString( 'We are waiting for the proof that the domain is yours', $mailboxes );
		$this->assertStringNotContainsString( 'data-mail-create-submit', $mailboxes );

		$dns = $this->page( $r, 'dns' );
		$this->assertStringContainsString( '_beltoft-verify.wait.example', $dns );
		$this->assertStringContainsString( (string) $r->get( 'mail_verify_token' ), $dns );
		$this->assertStringNotContainsString( 'Check again', $dns );

		$r->set( array( 'state' => 'paused' ) );
		$this->assertStringContainsString( 'Your email service is paused', $this->page( Resource::find( $r->id() ) ) );
		$r->set( array( 'state' => 'failed' ) );
		$this->assertStringContainsString( 'could not be set up', $this->page( Resource::find( $r->id() ) ) );
	}

	public function test_the_aliases_tab_lists_adds_and_shows_the_limits(): void {
		$r = $this->ready_mail( 'al.example', array( 'aliases' => 2 ), $this->user );
		\BeltoftServices\Mail\Aliases::create( $r, 'sales', array( 'a@al.example', 'christian.beltoft@gmail.com' ) );

		$html = $this->page( Resource::find( $r->id() ), 'aliases' );

		$this->assertStringContainsString( 'aria-current="page">Aliases', $html );
		$this->assertStringContainsString( 'sales@al.example', $html );
		$this->assertStringContainsString( 'a@al.example, christian.beltoft@gmail.com', $html );
		$this->assertStringContainsString( 'data-mail-alias-delete="sales@al.example"', $html );
		$this->assertStringContainsString( '1 of 2 aliases used.', $html );
		$this->assertStringContainsString( 'data-mail-alias-submit', $html );
		$this->assertStringContainsString( 'in this domain or elsewhere', $html );
		$this->assertStringNotContainsString( 'Mailboxes</h4>', $html, 'Only the tab shown.' );

		$this->settings( array( 'mail_allow_external_forward' => 0 ) );
		$this->assertStringContainsString( 'to one or more other addresses in this domain.', $this->page( Resource::find( $r->id() ), 'aliases' ) );

		\BeltoftServices\Mail\Aliases::create( Resource::find( $r->id() ), 'two', array( 'a@al.example' ) );
		$this->assertStringContainsString( 'Your plan has no room for more aliases.', $this->page( Resource::find( $r->id() ), 'aliases' ) );
	}

	public function test_the_dns_tab_tells_a_zone_of_ours_from_one_elsewhere_and_marks_each_record(): void {
		$r = $this->ready_mail( 'elsewhere.example', array(), $this->user );
		$html = $this->page( $r, 'dns' );

		$this->assertStringContainsString( 'managed somewhere else', $html );
		$this->assertStringContainsString( 'every ten minutes for 14 days', $html );
		$this->assertStringContainsString( 'mail.example.net', $html );
		$this->assertStringContainsString( 'v=spf1 include:_spf.mail.example.net ~all', $html );
		$this->assertStringContainsString( 'dkim._domainkey.elsewhere.example', $html );
		$this->assertStringContainsString( 'Missing', $html );
		$this->assertStringContainsString( 'data-mail-dns-check', $html );
		$this->assertStringContainsString( 'DNS records are not in place yet', $this->page( $r ), 'The first tab says it too.' );

		$ours = $this->our_zone( $this->user, 'ours.example' );
		$mine = $this->ready_mail( 'ours.example', array(), $this->user );
		$html = $this->page( $mine, 'dns' );

		$this->assertStringContainsString( 'on our name servers, so we add these records for you', $html );
		$this->assertStringContainsString( 'OK', $html );
		$this->assertStringNotContainsString( 'data-mail-dns-check', $html, 'All in place: nothing to check.' );
		$this->assertStringContainsString( 'All records are in place.', $html );
		$this->assertNotNull( $ours );
	}

	public function test_a_conflict_shows_its_reason(): void {
		$this->our_zone( $this->user, 'conflict.example', array( array( 'name' => 'autoconfig', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.1' ) ) );
		$r = $this->ready_mail( 'conflict.example', array(), $this->user );

		$html = $this->page( $r, 'dns' );

		$this->assertStringContainsString( 'Conflict', $html );
		$this->assertStringContainsString( 'The name already has a DNS record.', $html );
		$this->assertStringContainsString( 'data-mail-dns-check', $html );
	}

	public function test_the_connect_tab_has_the_server_the_ports_the_webmail_and_the_limits(): void {
		$this->settings( array( 'mail_webmail_url' => 'https://webmail.example.net/' ) );
		$r = $this->ready_mail( 'connect.example', array(), $this->user );

		$html = $this->page( $r, 'settings' );

		$this->assertStringContainsString( 'aria-current="page">Connect', $html );
		foreach ( array( 'mail.example.net', '993', '995', '465', '587', 'SSL/TLS', 'STARTTLS' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringContainsString( 'href="https://webmail.example.net/"', $html );
		$this->assertStringContainsString( 'Each mailbox can send 500 messages a day.', $html );
		$this->assertStringContainsString( 'together can send 30 messages a minute.', $html );
		$this->assertStringContainsString( '10 GB', $html );

		$this->settings( array( 'mail_webmail_url' => '', 'mail_rl_day' => 0, 'mail_rl_minute' => 0 ) );
		$html = $this->page( Resource::find( $r->id() ), 'settings' );
		$this->assertStringNotContainsString( 'Open webmail', $html );
		$this->assertStringNotContainsString( 'Sending limits', $html );

		$this->settings( array( 'mail_webmail_url' => 'http://insecure.example/' ) );
		$this->assertStringNotContainsString( 'Open webmail', $this->page( Resource::find( $r->id() ), 'settings' ), 'Webmail is https only.' );
	}

	public function test_the_connect_tab_links_to_the_setup_guide_only_when_the_setting_is_set(): void {
		$r = $this->ready_mail( 'guide.example', array(), $this->user );

		$this->assertStringNotContainsString( 'Setup guide for your mail app', $this->page( $r, 'settings' ) );

		$this->settings( array( 'mail_guide_url' => 'https://example.net/mail-setup/' ) );
		$html = $this->page( Resource::find( $r->id() ), 'settings' );
		$this->assertStringContainsString( '<a href="https://example.net/mail-setup/">Setup guide for your mail app</a>', $html );

		$this->settings( array( 'mail_guide_url' => '' ) );
		$this->assertStringNotContainsString( 'Setup guide for your mail app', $this->page( Resource::find( $r->id() ), 'settings' ) );
	}

	public function test_the_connect_tab_shows_calendar_and_contacts_only_when_the_address_is_set(): void {
		$r = $this->ready_mail( 'dav.example', array(), $this->user );

		$this->assertStringNotContainsString( 'CalDAV', $this->page( $r, 'settings' ) );

		$this->settings( array( 'mail_dav_url' => 'https://mail.example.net/dav/' ) );
		$html = $this->page( Resource::find( $r->id() ), 'settings' );
		$this->assertStringContainsString( 'Calendar and contacts (CalDAV and CardDAV): https://mail.example.net/dav/', $html );
	}

	public function test_the_overview_lists_email_domains_like_other_services(): void {
		$r = $this->ready_mail( 'dash.example', array(), $this->user );
		$this->assertContains( 'mail', array_keys( Overview::SECTIONS ) );

		$html = Overview::section( 'mail' );

		$this->assertStringContainsString( 'beltoft-services-ov__mail', $html );
		$this->assertStringContainsString( '<h2>Email</h2>', $html );
		$this->assertStringContainsString( 'dash.example', $html );
		$this->assertStringContainsString( '0 of 5 mailboxes', $html );
		$this->assertStringContainsString( 'All email domains', $html );
		$this->assertStringContainsString( esc_url( $this->area()->url_list() ), $html );
		$this->assertStringContainsString( 'dash.example', Overview::all() );
		$this->assertNotNull( $r );

		wp_set_current_user( self::factory()->user->create() );
		Overview::reset();
		$this->assertStringNotContainsString( 'dash.example', Overview::section( 'mail' ) );
		$this->assertStringContainsString( 'You have no email domains yet.', Overview::section( 'mail' ) );
	}

	public function test_the_mail_notice_points_to_the_email_page(): void {
		$r = $this->ready_mail( 'notice.example', array(), $this->user );

		$this->assertSame( 'notice.example', \BeltoftServices\Proxmox\ServerName::email_label( $r, $r->license() ) );
		$this->assertStringContainsString( 'reveal_mailbox', implode( ' ', \BeltoftServices\Emails\Notice::KINDS ) );
		$this->assertSame( 'View your email in your account', \BeltoftServices\Emails\Notice::link_text( 'reveal_mailbox' ) );
		$this->assertContains( 'reveal_mailbox', \BeltoftServices\Emails\Notice::ALWAYS );
	}

	public function test_the_files_keep_the_design_rules(): void {
		$files = array( 'assets/mail.css', 'assets/mail.js', 'templates/account/mail.php', 'src/Account/Email.php', 'src/Mail/MailCard.php', 'src/Mail/MailView.php', 'src/Mail/MailRoutes.php' );

		foreach ( $files as $file ) {
			$text = (string) file_get_contents( BELTOFT_SERVICES_PATH . $file );
			$this->assertNotSame( '', $text, $file );
			$this->assertStringNotContainsString( 'border-radius', $text, $file . ' has square corners.' );
			$this->assertStringNotContainsString( "\u{2014}", $text, $file . ' has no em dash.' );
		}

		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/mail.css' );
		$this->assertStringContainsString( 'summary::after', $css, 'The delete disclosure has no plus sign.' );
		$this->assertStringContainsString( 'p.beltoft-services-panel__notice { margin: 16px 0; }', $css );

		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/mail.js' );
		$this->assertStringNotContainsString( 'localStorage', $js, 'A password is not stored in the browser.' );
		$this->assertStringNotContainsString( 'sessionStorage', $js );
	}

	public function test_the_assets_are_enqueued_by_the_panel_hook_on_the_email_endpoint_only(): void {
		do_action( 'beltoft_services_account_assets', 'beltoft-services-account', 'databases' );
		$this->assertFalse( wp_style_is( 'beltoft-services-mail', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'beltoft-services-mail', 'enqueued' ) );

		do_action( 'beltoft_services_account_assets', 'beltoft-services-account', $this->area()->slug() );
		$this->assertTrue( wp_style_is( 'beltoft-services-mail', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'beltoft-services-mail', 'enqueued' ) );
		$this->assertContains( 'beltoft-services-account', wp_scripts()->registered['beltoft-services-mail']->deps );
	}

	public function test_the_menu_item_sits_right_after_domains(): void {
		$items = array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'domains' => 'Domains & DNS', 'vps' => 'VPS', 'billing' => 'Billing', 'support' => 'Support', 'email' => 'Email', 'customer-logout' => 'Log out' );

		$this->assertSame( array( 'dashboard', 'orders', 'domains', 'email', 'vps', 'billing', 'support', 'customer-logout' ), array_keys( Email::menu( $items ) ) );

		unset( $items['domains'] );
		$this->assertSame( array_keys( $items ), array_keys( Email::menu( $items ) ), 'No Domains item: left alone.' );
		$this->assertSame( array( 'a' => 'A' ), Email::menu( array( 'a' => 'A' ) ) );
	}

	public function test_the_counts_follow_create_and_delete_not_only_the_routes(): void {
		$r = $this->ready_mail( 'count.example', array(), $this->user );

		Mailboxes::create( $r, 'a' );
		Mailboxes::create( Resource::find( $r->id() ), 'b' );
		Mailboxes::create( Resource::find( $r->id() ), 'c' );
		$this->assertSame( 3, (int) Resource::find( $r->id() )->get( 'mail_boxes' ) );
		$this->assertSame( '3 of 5 mailboxes', $this->area()->row( $this->views( Resource::find( $r->id() ) )[0], Resource::find( $r->id() ) )['details'] );

		Mailboxes::delete( Resource::find( $r->id() ), 'b@count.example' );
		$this->assertSame( 2, (int) Resource::find( $r->id() )->get( 'mail_boxes' ) );
	}

	public function test_the_add_button_goes_to_the_order_page_setting_instead_of_the_product_page(): void {
		$ref = new \ReflectionMethod( Email::class, 'buttons' );
		$ref->setAccessible( true );

		$this->settings( array( 'mail_order_url' => '/business-email/#pricing' ) );
		$this->assertSame( array(), $ref->invoke( $this->area() ), 'The setting alone makes no button: there is nothing to sell.' );

		$this->service_product( 'mail', self::PLAN, array( 'slug' => 'mail-order' ) );
		$this->assertSame( home_url( '/business-email/#pricing' ), $ref->invoke( $this->area() )[0]['url'] );

		$this->settings( array( 'mail_order_url' => 'https://shop.example.org/email/' ) );
		$this->assertSame( 'https://shop.example.org/email/', $ref->invoke( $this->area() )[0]['url'] );

		$this->settings( array( 'mail_order_url' => '//evil.example/' ) );
		$this->assertStringNotContainsString( 'evil.example', $ref->invoke( $this->area() )[0]['url'] );
	}

	public function test_the_add_button_shows_only_when_an_email_product_exists_and_the_address_can_be_changed(): void {
		$ref = new \ReflectionMethod( Email::class, 'buttons' );
		$ref->setAccessible( true );
		$this->assertSame( array(), $ref->invoke( $this->area() ), 'No product, no button.' );
		$this->assertStringNotContainsString( 'Create an email domain', $this->render( array() ) );

		$product = $this->service_product( 'mail', self::PLAN, array( 'slug' => 'mail-buy' ) );
		$url     = get_permalink( $product->get_id() );
		$buttons = $ref->invoke( $this->area() );
		$this->assertSame( 'Create an email domain', $buttons[0]['label'] );
		$this->assertSame( $url, $buttons[0]['url'] );
		$this->assertStringContainsString( 'Create an email domain', $this->render( array() ) );

		add_filter( 'beltoft_services_email_add_url', static fn() => 'https://example.org/buy/' );
		$this->assertSame( 'https://example.org/buy/', $ref->invoke( $this->area() )[0]['url'] );
		add_filter( 'beltoft_services_email_add_url', '__return_empty_string', 20 );
		$this->assertSame( array(), $ref->invoke( $this->area() ) );
		remove_all_filters( 'beltoft_services_email_add_url' );
	}

	/** A ready child domain of a root, jobs run. */
	private function child_of( Resource $root, string $name ): Resource {
		$child = \BeltoftServices\Mail\MailDomains::add( $root, $name );
		$this->assertInstanceOf( Resource::class, $child );
		$this->run_jobs();

		return Resource::find( $child->id() );
	}

	public function test_a_team_root_shows_its_domains_the_counter_and_the_add_form(): void {
		$this->settings( array( 'domain_search_url' => '/domains/' ) );
		$root = $this->ready_mail( 'team.example', array(), $this->user );
		$this->our_zone( $this->user, 'zone.example' );
		$this->child_of( $root, 'second.example' );

		$html = $this->page( Resource::find( $root->id() ) );

		$this->assertStringContainsString( 'Domains in this plan', $html );
		$this->assertStringContainsString( '2 of 3 domains', $html );
		$this->assertStringContainsString( 'second.example', $html );
		$this->assertStringContainsString( 'data-mail-domain-add', $html );
		$this->assertStringContainsString( 'One of your domains with us', $html );
		$this->assertStringContainsString( '<option value="zone.example">', $html );
		$this->assertStringContainsString( 'A domain at another provider', $html );
		$this->assertStringContainsString( 'You add a few DNS records at your provider. We show you which ones, starting with one that proves the domain is yours.', $html );
		$this->assertStringContainsString( 'Register a new domain', $html );
		$this->assertStringContainsString( 'Transfer a domain to us', $html );
		$this->assertStringContainsString( '/domains/?mail_for=' . $root->id() . '"', $html );
		$this->assertStringContainsString( '/domains/?mail_for=' . $root->id() . '#beltoft-transfer-domain"', $html, 'The transfer link keeps its anchor after the plan.' );
		$this->assertStringNotContainsString( 'data-mail-domain-remove', $html, 'The first domain cannot be removed.' );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
	}

	public function test_one_domain_of_one_has_one_domain_counter_and_a_full_plan_has_no_add_form(): void {
		$root = $this->ready_mail( 'solo.example', array( 'domains' => 1 ), $this->user );
		$html = $this->page( $root );
		$this->assertStringNotContainsString( 'data-mail-domain-add', $html );

		$full = $this->ready_mail( 'full3.example', array(), $this->user );
		$this->child_of( $full, 'b.example' );
		$this->child_of( $full, 'c.example' );
		$html = $this->page( Resource::find( $full->id() ) );
		$this->assertStringContainsString( '3 of 3 domains', $html );
		$this->assertStringContainsString( 'Your plan has no room for another domain.', $html );
		$this->assertStringNotContainsString( 'data-mail-domain-add', $html );
	}

	public function test_the_mailbox_counter_is_plan_wide_and_the_form_follows_the_plan_not_the_domain(): void {
		$root = $this->ready_mail( 'wide.example', array( 'mailboxes' => 3 ), $this->user );
		$kid  = $this->child_of( $root, 'wide-two.example' );
		Mailboxes::create( Resource::find( $root->id() ), 'a' );
		Mailboxes::create( Resource::find( $root->id() ), 'b' );
		Mailboxes::create( Resource::find( $kid->id() ), 'c' );

		$html = $this->page( Resource::find( $kid->id() ) );
		$this->assertStringContainsString( '3 of 3 mailboxes in your plan', $html );
		$this->assertStringContainsString( 'On this domain', $html );
		$this->assertStringNotContainsString( 'data-mail-create-submit', $html, 'One mailbox on this domain, but the plan is full.' );

		$solo = $this->ready_mail( 'solo-box.example', array( 'mailboxes' => 5, 'domains' => 1 ), $this->user );
		$html = $this->page( $solo );
		$this->assertStringContainsString( '0 of 5 mailboxes in your plan', $html );
		$this->assertStringNotContainsString( 'On this domain', $html );
	}

	public function test_a_domain_being_removed_is_gone_from_the_page_at_once(): void {
		$root = $this->ready_mail( 'gone.example', array(), $this->user );
		$this->child_of( $root, 'b-gone.example' );
		$third = $this->child_of( $root, 'c-gone.example' );
		$this->assertStringContainsString( '3 of 3 domains', $this->page( Resource::find( $root->id() ) ) );

		$this->assertTrue( \BeltoftServices\Mail\MailDomains::remove( $third ) );
		$html = $this->page( Resource::find( $root->id() ) );
		$this->assertStringContainsString( '2 of 3 domains', $html );
		$this->assertStringNotContainsString( 'c-gone.example', $html );
		$this->assertStringContainsString( 'data-mail-domain-add', $html, 'There is room again.' );
		$this->assertSame( 2, \BeltoftServices\Mail\MailPlan::used( Resource::find( $root->id() ) )['domains'] );
	}

	public function test_a_proof_wait_offers_check_now_and_the_files_use_the_route(): void {
		$r = $this->mail_resource( 'proofbtn.example', array(), array(), $this->user );
		\BeltoftServices\Jobs::enqueue( $r->id() );
		$this->run_jobs( 3 );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'We are waiting for the proof that the domain is yours.', $html );
		$this->assertStringContainsString( 'data-mail-proof-check', $html );
		$this->assertStringContainsString( '/mail/proof/check', (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/mail.js' ) );

		$ready = $this->ready_mail( 'noproofbtn.example', array(), $this->user );
		$this->assertStringNotContainsString( 'data-mail-proof-check', $this->page( $ready ) );
	}

	public function test_a_child_page_names_its_plan_and_offers_remove_only_without_mailboxes(): void {
		$root  = $this->ready_mail( 'team2.example', array(), $this->user );
		$child = $this->child_of( $root, 'kid.example' );

		$html = $this->page( $child );
		$this->assertStringContainsString( 'Part of the', $html );
		$this->assertMatchesRegularExpression( '#plan of <a href="[^"]+">team2\.example</a>#', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->area()->url( $root->license() ) ) . '"', $html );
		$this->assertStringContainsString( 'data-mail-domain-remove="kid.example"', $html );
		$this->assertStringNotContainsString( 'data-mail-domain-add', $html, 'The add form is on the first domain.' );

		Mailboxes::create( Resource::find( $child->id() ), 'info' );
		$html = $this->page( Resource::find( $child->id() ) );
		$this->assertStringNotContainsString( 'data-mail-domain-remove', $html );
		$this->assertStringContainsString( 'Delete the mailboxes on this domain first.', $html );
	}

	public function test_on_a_paused_plan_the_remove_button_is_disabled_with_the_reason(): void {
		$root  = $this->ready_mail( 'team3.example', array(), $this->user );
		$child = $this->child_of( $root, 'kid3.example' );
		Resource::find( $root->id() )->set( array( 'state' => 'paused' ) );

		$html = $this->page( Resource::find( $child->id() ) );
		$this->assertMatchesRegularExpression( '/<button[^>]*\sdisabled[^>]*data-mail-domain-remove="kid3.example"/', $html );
		$this->assertStringContainsString( 'You can remove a domain when the plan is active again.', $html );
	}

	public function test_the_list_button_adds_a_domain_when_a_plan_has_room_else_creates_an_email_domain(): void {
		$this->service_product( 'mail', self::PLAN, array( 'slug' => 'mail-buy' ) );

		$this->assertSame( 'Create an email domain', $this->area()->buttons()[0]['label'] );

		$root = $this->ready_mail( 'room.example', array(), $this->user );
		$b    = $this->area()->buttons();
		$this->assertSame( 'Add a domain', $b[0]['label'] );
		$this->assertStringContainsString( $this->area()->url( $root->license() ), $b[0]['url'] );
		$this->assertStringContainsString( '#', $b[0]['url'] );

		$this->child_of( $root, 'b.example' );
		$this->child_of( $root, 'c.example' );
		$b = $this->area()->buttons();
		$this->assertSame( 'Create an email domain', $b[0]['label'] );
		$this->assertSame( $this->area()->url_add(), $b[0]['url'] );
		$this->assertNotSame( '', $b[0]['url'] );
	}

	public function test_a_plan_of_one_alias_says_alias_and_the_copy_says_on_this_domain(): void {
		$r = $this->ready_mail( 'oa.example', array( 'aliases' => 1 ), $this->user );
		$this->assertStringContainsString( '0 of 1 alias used.', $this->page( Resource::find( $r->id() ), 'aliases' ) );
		$this->assertStringNotContainsString( 'of this domain first', (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Mail/MailDomains.php' ) );
	}

	public function test_finishing_or_purging_a_password_does_not_bring_back_one_that_was_just_revealed(): void {
		$r = $this->ready_mail( 'race.example', array(), $this->user );
		$r->locked(
			static function () use ( $r ): bool {
				MailAccess::begin( $r, 'a@race.example' );
				MailAccess::begin( $r, 'b@race.example' );

				return true;
			}
		);
		$r = Resource::find( $r->id() );
		MailAccess::finish( $r, 'b@race.example' );

		// Another request holds an older copy of the resource while b is revealed.
		$stale = Resource::find( $r->id() );
		$this->assertNotNull( MailAccess::take( Resource::find( $r->id() ), 'b@race.example', $this->user ) );

		MailAccess::finish( $stale, 'a@race.example' );
		$meta = (array) Resource::find( $r->id() )->get( MailAccess::META );
		$this->assertArrayNotHasKey( 'b@race.example', $meta, 'A revealed password stays wiped.' );
		$this->assertTrue( MailAccess::revealable( Resource::find( $r->id() ), 'a@race.example' ) );

		$stale = Resource::find( $r->id() );
		MailAccess::take( Resource::find( $r->id() ), 'a@race.example', $this->user );
		$r2 = Resource::find( $r->id() );
		$r2->locked( static fn() => MailAccess::begin( $r2, 'c@race.example' ) && true );
		MailAccess::purge( $stale, 'zz@race.example' );
		$this->assertArrayHasKey( 'c@race.example', (array) Resource::find( $r->id() )->get( MailAccess::META ), 'A purge works on the current map, not an old copy.' );
	}
}

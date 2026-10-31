<?php
/**
 * Customer email polish: headings, dates, names, greetings, plain footers, notices, registrations.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DbAccess;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\EmailDetails;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Resource;
use BeltoftServices\Retention;

/**
 * Mails are caught by pre_wp_mail; nothing is sent.
 */
final class EmailsPolishTest extends TestCase {

	/**
	 * Mails caught.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		delete_option( 'woocommerce_email_footer_text' );

		foreach ( WC()->mailer()->get_emails() as $email ) {
			$email->email_type = 'html';
		}

		parent::tear_down();
	}

	/**
	 * Records a mail instead of sending it.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $atts Mail arguments.
	 * @return bool
	 */
	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * A ready resource of a driver.
	 *
	 * @param string               $driver Driver id.
	 * @param array<string, mixed> $meta   Resource meta.
	 * @param array<string, mixed> $plan   Plan.
	 */
	private function resource( string $driver, array $meta = array(), array $plan = array() ): Resource {
		if ( 'fake' === $driver ) {
			$this->fake_driver();
		}

		$product  = $this->service_product( $driver );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( $driver ), $plan, array_merge( array( 'state' => 'ready' ), $meta ) );
		$this->assertInstanceOf( Resource::class, $resource );

		return Resource::find( $resource->id() );
	}

	/**
	 * The last caught mail as text.
	 */
	private function last(): array {
		$this->assertNotEmpty( $this->mails );
		$mail = end( $this->mails );

		return array(
			'subject' => (string) $mail['subject'],
			'body'    => (string) $mail['message'],
			'text'    => (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $mail['message'] ) ),
		);
	}

	public function test_a_database_alert_has_a_database_heading_and_a_server_alert_a_server_heading(): void {
		$r = $this->resource( 'fake' );

		foreach ( array( 'db_down' => 'Your database needs attention', 'db_disk' => 'Your database needs attention', 'down' => 'Your server needs attention' ) as $reason => $heading ) {
			$this->mails = array();
			Emails::get( 'beltoft_services_monitor_alert' )->trigger( $r, array( 'reason' => $reason, 'disk_percent' => 91 ) );
			$this->assertStringContainsString( $heading, $this->last()['body'], $reason );
			$this->assertStringContainsString( 'Hi', $this->last()['text'], 'Every email greets.' );
		}
	}

	public function test_quarantine_and_limit_emails_greet_the_customer(): void {
		$r = $this->resource( 'fake' );

		foreach ( array( 'beltoft_services_quarantine', 'beltoft_services_limit_exceeded' ) as $id ) {
			foreach ( array( 'html', 'plain' ) as $type ) {
				$this->mails        = array();
				$email              = Emails::get( $id );
				$email->email_type  = $type;
				$email->trigger( $r, array( 'used' => 6, 'limit' => 5 ) );
				$this->assertMatchesRegularExpression( '/Hi[ ,]/', $this->last()['text'], $id . ' ' . $type );
			}
		}

		$this->mails = array();
		Emails::get( 'beltoft_services_limit_exceeded' )->trigger( $r, array( 'used' => 6, 'limit' => 5 ) );
		$this->assertStringContainsString( 'over the limit of your plan', $this->last()['text'] );
		$this->assertStringContainsString( 'Usage: 6 of 5', $this->last()['text'] );
		$this->assertStringContainsString( 'change to a larger plan', $this->last()['text'] );
	}

	public function test_plain_text_footers_show_no_markup(): void {
		update_option( 'woocommerce_email_footer_text', 'Beltoft<br />Aarhus <a href="https://example.com">site</a>' );
		$r = $this->resource( 'fake' );

		foreach ( array( 'beltoft_services_paused', 'beltoft_services_quarantine', 'beltoft_services_monitor_alert', 'beltoft_services_notice' ) as $id ) {
			$this->mails       = array();
			$email             = Emails::get( $id );
			$email->email_type = 'plain';
			$email->trigger( $r, array( 'reason' => 'down' ) );
			$body = $this->last()['body'];

			$this->assertStringNotContainsString( '<br', $body, $id );
			$this->assertStringNotContainsString( '<a ', $body, $id );
			$this->assertStringContainsString( "Beltoft\nAarhus site", $body, $id );
		}
	}

	public function test_dates_are_spelled_out(): void {
		$r  = $this->resource( 'fake' );
		$at = gmmktime( 12, 0, 0, 11, 3, 2026 );
		update_option( 'date_format', 'd/m/Y' );

		Emails::get( 'beltoft_services_deletion_warning' )->trigger( $r, array( 'deletes_at' => $at ) );

		$this->assertStringContainsString( '3 November 2026', $this->last()['text'] );
		$this->assertStringNotContainsString( '03/11/2026', $this->last()['text'] );

		$this->mails = array();
		Emails::get( 'beltoft_services_quarantine' )->trigger( $r, array( 'quarantine_at' => $at ) );
		$this->assertStringContainsString( '3 November 2026, 12:00', $this->last()['text'] );
		update_option( 'date_format', 'F j, Y' );
	}

	public function test_the_notice_email_for_a_database_names_it_greets_and_links(): void {
		$r = $this->resource( DbDriver::ID, array( 'db_address' => '10.0.0.5' ), array( 'engine' => 'postgres', 'major' => 18 ) );

		DbAccess::notify( $r, 'add' );
		$mail = $this->last();

		$this->assertSame( 'The access list of your database changed', $mail['subject'] );
		$this->assertStringContainsString( 'Hi', $mail['text'] );
		$this->assertStringContainsString( 'PostgreSQL 18 database', $mail['text'] );
		$this->assertStringContainsString( 'If you did not do this, change your password', $mail['text'] );
		$this->assertStringContainsString( '<a href=', $mail['body'], 'A branded html email with a link.' );

		$this->mails = array();
		DbAccess::notify( $r, 'world_on' );
		$this->assertStringContainsString( 'open to everyone', strtolower( $this->last()['subject'] ) );
		$this->assertStringContainsString( 'Anyone on the internet', $this->last()['text'] );
	}

	public function test_email_labels_name_the_actual_service(): void {
		$domain = $this->resource( 'fake', array( 'domain' => 'example.com' ) );
		$domain->set( array( 'driver' => 'domain' ) );
		$license = $domain->license();

		$this->assertSame( 'example.com', ServerName::email_label( Resource::find( $domain->id() ), $license ) );

		$dns = $this->resource( 'fake', array( 'domain' => 'example.org' ) );
		$dns->set( array( 'driver' => 'dns' ) );
		$this->assertSame( 'DNS hosting for example.org', ServerName::email_label( Resource::find( $dns->id() ), $dns->license() ) );

		$db = $this->resource( DbDriver::ID, array(), array( 'engine' => 'postgres', 'major' => 18 ) );
		$this->assertSame( 'PostgreSQL 18 database', ServerName::email_label( $db, $db->license() ) );
	}

	public function test_a_domain_registration_gets_no_deletion_warning_and_no_deleted_email_and_is_never_checked(): void {
		$r = $this->resource( 'fake', array( 'domain' => 'example.com' ) );
		$r->set( array( 'driver' => 'domain' ) );
		$r = Resource::find( $r->id() );

		$this->assertTrue( Retention::is_registration( $r ) );

		Emails::on_deletion_warning( $r, time() + DAY_IN_SECONDS );
		$this->assertSame( array(), $this->mails, 'No deletion warning for a registration.' );

		$r->set( array( 'notified' => 'paused' ) );
		Emails::on_state_changed( Resource::find( $r->id() ), 'paused', 'deleted' );
		$this->assertSame( array(), $this->mails, 'No deleted email for a registration.' );

		// DNS hosting still gets both.
		$dns = $this->resource( 'fake', array( 'domain' => 'example.org' ) );
		$dns->set( array( 'driver' => 'dns' ) );
		Emails::on_deletion_warning( Resource::find( $dns->id() ), time() + DAY_IN_SECONDS );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'DNS hosting for example.org', $this->last()['text'] );
	}

	public function test_the_dns_ready_email_lists_the_name_servers_once(): void {
		$dns = $this->resource( 'fake', array( 'domain' => 'example.org' ) );
		$dns->set( array( 'driver' => 'dns' ) );
		$dns = Resource::find( $dns->id() );

		$lines = \BeltoftServices\Domains\EmailDetails::details( array(), 'beltoft_services_ready', $dns );

		$this->assertArrayHasKey( 'Domain', $lines );
		$this->assertArrayNotHasKey( 'Name servers', $lines, 'The email text lists them with what to do.' );
	}

	public function test_database_ready_details_have_the_connection_facts_and_never_a_password(): void {
		$db    = $this->resource( DbDriver::ID, array( 'db_address' => '10.0.0.5' ), array( 'engine' => 'postgres', 'major' => 18 ) );
		$lines = EmailDetails::details( array(), 'beltoft_services_ready', $db );

		$this->assertSame( 'PostgreSQL 18', $lines['Engine'] );
		$this->assertSame( '10.0.0.5', $lines['Host (private network)'] );
		$this->assertArrayNotHasKey( 'Host (public address)', $lines );
		$this->assertSame( '5432', $lines['Port'] );
		$this->assertArrayHasKey( 'Database name', $lines );
		$this->assertArrayHasKey( 'User name', $lines );
		$this->assertSame( 'Required', $lines['TLS'] );
		$this->assertStringContainsString( 'Not sent by email', $lines['Password'] );
		$this->assertStringContainsString( 'CA certificate', $lines['CA certificate'] );
	}

	public function test_the_billing_address_note_uses_customer_labels_and_greets(): void {
		$user = self::factory()->user->create( array( 'user_email' => 'customer@example.com', 'first_name' => 'Kim' ) );
		$call = new \ReflectionMethod( \BeltoftServices\Mcp\Acts::class, 'tell_customer' );
		$call->setAccessible( true );
		$call->invoke( null, $user, array( array( 'label' => 'Street address', 'old' => 'Old road 1', 'new' => 'New road 2' ) ) );

		$mail = $this->last();
		$this->assertStringContainsString( 'Hi Kim,', $mail['text'] );
		$this->assertStringContainsString( 'Street address: from "Old road 1" to "New road 2"', $mail['text'] );
		$this->assertStringNotContainsString( 'address_1', $mail['text'] );
		$this->assertStringNotContainsString( '(Claude)', $mail['text'], 'No hard-coded app name.' );
	}

	public function test_the_billing_note_names_the_connected_app_from_its_grant(): void {
		$client = \BeltoftServices\Oauth\Clients::register( array( 'client_name' => 'My Assistant', 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ) ) );
		$this->assertIsArray( $client );
		\BeltoftServices\Api\Tokens::use_grant( array( 'user' => 1, 'scope' => 'actions', 'family' => 'fam', 'client_id' => $client['client_id'] ) );

		$user = self::factory()->user->create( array( 'user_email' => 'customer@example.com' ) );
		$call = new \ReflectionMethod( \BeltoftServices\Mcp\Acts::class, 'tell_customer' );
		$call->setAccessible( true );
		$call->invoke( null, $user, array( array( 'label' => 'City', 'old' => '', 'new' => 'Aarhus' ) ) );

		$this->assertStringContainsString( 'An app you connected to your account (My Assistant) changed', $this->last()['text'] );
		$this->assertStringContainsString( 'City: from (empty) to "Aarhus"', $this->last()['text'] );
		\BeltoftServices\Api\Tokens::reset( null );
	}
}

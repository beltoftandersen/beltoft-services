<?php
/**
 * The manual (dedicated) server: waiting, delivery, billing, cancel, lapse and the customer's card.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftLicenses\Licenses;
use BeltoftServices\ManualServer\Dedicated;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Jobs;
use BeltoftServices\ManualServer\ManualServerDelivery;
use BeltoftServices\ManualServer\ManualServerDriver;
use BeltoftServices\ManualServer\ManualServerPanel;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Usage\CustomerDelete;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;

require_once __DIR__ . '/ManualServerTestCase.php';

/**
 * Lifecycle and billing of a manual server.
 */
class ManualServerTest extends ManualServerTestCase {

	private function rows( int $resource_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Ledger::table() . ' WHERE resource_id = %d ORDER BY hour_bucket', $resource_id ), ARRAY_A );
	}

	public function test_the_variation_describes_the_server_as_plan_fields(): void {
		$v    = $this->server_product();
		$plan = Product::plan( $v['u']->get_parent_id(), $v['u']->get_id() );

		$this->assertSame( 'AMD Ryzen 7 PRO 8700GE', $plan['server_cpu'] );
		$this->assertSame( '64 GB DDR5', $plan['server_memory'] );
		$this->assertSame( 'AX42-1', $plan['server_ref'] );
		$this->assertSame( '99.00', $plan['cost_monthly'] );
		$this->assertSame( '49.00', $plan['cost_setup'] );
		$this->assertSame( 'manual-server', Product::driver_for( $v['u']->get_parent_id() )->id() );
		$this->assertSame( 'dedicated', Product::driver_for( $v['u']->get_parent_id() )->kind() );
		$this->assertSame( 119.0, Rates::monthly( $v['u'] ), 'The figure the rates are taken from is typed on the variation.' );
	}

	public function test_a_new_server_waits_calls_nothing_and_mails_staff_once(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );

		Jobs::run( $r->id() );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'provisioning', $fresh->get( 'state' ) );
		$this->assertStringContainsString( 'dedicated-', (string) $fresh->get( 'server_name' ) );
		$this->assertNotSame( '', (string) $fresh->get( 'waiting' ), 'A wait, not an error.' );
		$this->assertSame( '', (string) $fresh->get( 'last_error' ) );

		$mails = $this->mails_with( 'Dedicated server to set up' );
		$this->assertCount( 1, $mails, 'Staff are mailed once, however often it is looked at.' );
		$this->assertSame( array( self::MAIL ), $mails[0]['to'] );
		$this->assertStringContainsString( 'AX42-1', $mails[0]['body'] );
		$this->assertStringContainsString( 'FSN1', $mails[0]['body'] );
		$this->assertStringContainsString( 'Cost per month, EUR without VAT: 99', $mails[0]['body'] );
		$this->assertStringContainsString( 'Nothing is ordered by the plugin', $mails[0]['body'] );
		$this->assertSame( 0, Meter::run(), 'A server that is not delivered bills nothing.' );
		$this->assertNull( $fresh->get( Meter::READY_AT ) );
	}

	public function test_mark_ready_delivers_starts_billing_and_tells_the_customer(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->ready_server( $v['u'], $user );

		$this->assertSame( array( '203.0.113.7', '2001:db8::7' ), ManualServerDelivery::ips( $r ) );
		$this->assertSame( 'S-1234', $r->get( ManualServerDelivery::PROVIDER_NO ) );
		$this->assertNotNull( $r->get( Meter::READY_AT ), 'Billing starts when the server is ready.' );

		$ready = $this->mails_with( 'is ready' );
		$this->assertNotEmpty( $ready, 'The customer gets the service ready email.' );
		$this->assertStringContainsString( '203.0.113.7', $ready[0]['body'] );
		$this->assertStringNotContainsString( 'S-1234', $ready[0]['body'], 'The supplier number is staff only.' );
		$this->assertStringNotContainsString( 'AX42', $ready[0]['body'] );

		$this->activated_ago( $r->id(), 5 );
		$this->assertGreaterThanOrEqual( 5, Meter::run() );

		$rows = $this->rows( $r->id() );
		$this->assertSame( 'server', $rows[0]['item'] );
		$this->assertEquals( Rates::hourly( 119.0, 0.0 ), (float) $rows[0]['rate'], 'The hourly rate is the monthly price over 730 hours.' );
		$this->assertSame( 119.0, Rates::cap( 119.0, 0.0 ) );
	}

	public function test_the_monthly_cap_is_the_monthly_price(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->ready_server( $v['u'], $user );
		$rate = Rates::hourly( 119.0, 0.0 );
		$hour = (int) strtotime( '2026-01-01 00:00:00 UTC' );

		// January has 744 hours: 744 x 119 / 730 is more than 119, so the line is capped at the monthly price.
		for ( $i = 0; $i < 744; $i++ ) {
			Ledger::record( $r->id(), $user, 'server', 1.0, 'hour', $rate, $hour + $i * HOUR_IN_SECONDS );
		}

		$statement = Renewal::statement( $this->rows( $r->id() ) );

		$this->assertTrue( $statement['lines'][0]['capped'] );
		$this->assertSame( 119.0, $statement['total'] );
	}

	public function test_mark_ready_checks_its_input_and_works_once(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );
		Jobs::run( $r->id() );
		$r = Resource::find( $r->id() );

		foreach ( array( '', 'not an ip', '203.0.113.7 999.1.1.1' ) as $bad ) {
			$this->assertWPError( ManualServerDelivery::save( $r, $bad, '', '', 'tester', true ), $bad );
		}

		$this->assertWPError( ManualServerDelivery::save( $r, '203.0.113.7', '', 'bad;number', 'tester', true ) );
		$this->assertWPError( ManualServerDelivery::save( $r, '203.0.113.7', '', '', 'tester', false ), 'Details of a server that is not delivered.' );
		$this->assertNull( Resource::find( $r->id() )->get( ManualServerDelivery::READY_AT ), 'Nothing was stored by a refusal.' );

		$this->assertTrue( ManualServerDelivery::save( $r, '203.0.113.7', 'Key login.', 'S-1', 'tester', true ) );
		$again = ManualServerDelivery::save( Resource::find( $r->id() ), '203.0.113.8', '', '', 'tester', true );
		$this->assertWPError( $again );
		$this->assertSame( 'delivered', $again->get_error_code() );

		// Once delivered the details can change, and the state stays.
		Jobs::run( $r->id() );
		$this->assertTrue( ManualServerDelivery::save( Resource::find( $r->id() ), '203.0.113.9', 'New note.', 'S-1', 'tester', false ) );
		$this->assertSame( array( '203.0.113.9' ), ManualServerDelivery::ips( Resource::find( $r->id() ) ) );
	}

	public function test_a_server_that_failed_while_waiting_can_still_be_delivered(): void {
		$v    = $this->server_product();
		$r    = $this->waiting_server( $v['u'], $this->new_user() );
		Jobs::run( $r->id() );
		$r = Resource::find( $r->id() );
		$r->set( array( 'state' => 'failed', 'failed_op' => 'provision', 'failed_desired' => 'ready', 'attempts' => 5, 'op' => 'provision' ) );

		$this->assertTrue( ManualServerDelivery::save( Resource::find( $r->id() ), '203.0.113.7', '', '', 'tester', true ) );
		Jobs::run( $r->id() );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
	}

	public function test_staff_are_mailed_once_when_the_promise_has_passed(): void {
		$v = $this->server_product();
		$r = $this->waiting_server( $v['u'], $this->new_user() );
		Jobs::run( $r->id() );
		$this->assertCount( 0, $this->mails_with( 'is late' ) );

		Resource::find( $r->id() )->set( array( ManualServerDelivery::REQUESTED_AT => time() - 11 * MINUTE_IN_SECONDS, 'next_attempt_at' => null ) );
		Jobs::run( $r->id() );
		Resource::find( $r->id() )->set( array( 'next_attempt_at' => null ) );
		Jobs::run( $r->id() );

		$this->assertCount( 1, $this->mails_with( 'is late' ) );
	}

	public function test_the_customer_cancels_with_the_typed_word_billing_stops_and_staff_are_told_once(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->ready_server( $v['u'], $user );
		$this->activated_ago( $r->id(), 5 );
		Meter::run();

		$this->assertSame( 'DELETE', CustomerDelete::word( $r ) );
		$this->assertSame( 'dedicated', CustomerDelete::kind( $r ) );
		$this->assertStringContainsString( 'deleted at once', CustomerDelete::text( 'dedicated' ) );

		$bad = CustomerDelete::run( $r, $user, 'delete me' );
		$this->assertWPError( $bad );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ), 'A wrong word cancels nothing.' );
		$this->assertCount( 0, $this->mails_with( 'Cancel a dedicated server' ) );

		$other = CustomerDelete::run( $r, $this->new_user(), 'DELETE' );
		$this->assertWPError( $other, 'Someone else cannot cancel it.' );

		$this->assertTrue( CustomerDelete::run( $r, $user, 'DELETE' ) );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertNotNull( Licenses::activation( $r->id() )['deactivated_at'], 'The activation is closed, which ends the meter.' );

		$mails = $this->mails_with( 'Cancel a dedicated server' );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'S-1234', $mails[0]['body'] );
		$this->assertStringContainsString( 'CANCEL the server at the supplier', $mails[0]['body'] );
		$this->assertCount( 0, $this->mails_with( 'on hold' ), 'No power off before the cancel.' );

		$last = Ledger::hour( time() );
		Meter::run( time() + 10 * HOUR_IN_SECONDS );
		$rows = $this->rows( $r->id() );
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d H:00:00', $last ), end( $rows )['hour_bucket'], 'Nothing is billed after the cancel.' );
	}

	public function test_a_server_still_being_set_up_is_not_cancelled_by_the_customer(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );
		Jobs::run( $r->id() );

		$why = CustomerDelete::blocker( Resource::find( $r->id() ) );
		$this->assertNotNull( $why );
		$this->assertStringContainsString( 'contact support', $why );
		$this->assertWPError( CustomerDelete::run( Resource::find( $r->id() ), $user, 'DELETE' ) );
		$this->assertSame( 'provisioning', Resource::find( $r->id() )->get( 'state' ) );
	}

	public function test_a_busy_server_says_server_not_database(): void {
		$v = $this->server_product();
		$r = $this->ready_server( $v['u'], $this->new_user() );
		$r->queue_desire( 'paused', 'Usage account on hold' );

		$why = CustomerDelete::blocker( Resource::find( $r->id() ) );
		$this->assertNotNull( $why );
		$this->assertStringContainsString( 'this server', $why );
		$this->assertStringNotContainsString( 'database', $why );
	}

	public function test_an_admin_cancel_tells_staff_and_closes_the_server(): void {
		$v = $this->server_product();
		$r = $this->ready_server( $v['u'], $this->new_user() );

		$this->assertTrue( $r->desire( 'deleted', 'Cancelled by an admin' ) );
		Jobs::run( $r->id() );

		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertCount( 1, $this->mails_with( 'Cancel a dedicated server' ) );
	}

	public function test_a_lapse_tells_staff_to_power_off_and_on_by_hand_and_keeps_billing(): void {
		$v = $this->server_product();
		$r = $this->ready_server( $v['u'], $this->new_user() );

		$this->assertTrue( $r->desire( 'paused', 'Usage account on hold' ) );
		Jobs::run( $r->id() );
		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertCount( 1, $this->mails_with( 'Dedicated server on hold' ) );
		$this->assertStringContainsString( 'Switch the server off', $this->mails_with( 'on hold' )[0]['body'] );

		$this->activated_ago( $r->id(), 3 );
		$this->assertGreaterThan( 0, Meter::run(), 'A stopped server is still billed, as the supplier bills it.' );

		$paused = Resource::find( $r->id() );
		$this->assertNull( CustomerDelete::blocker( $paused ), 'A lapsed customer can still cancel to stop the billing.' );

		$this->assertTrue( $paused->desire( 'ready', 'Paid' ) );
		Jobs::run( $r->id() );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertCount( 1, $this->mails_with( 'Dedicated server resumed' ) );
	}

	public function test_the_customers_card_shows_the_server_and_nothing_of_the_supplier(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->ready_server( $v['u'], $user );

		wp_set_current_user( $user );
		ob_start();
		ManualServerPanel::account( $r );
		$html = (string) ob_get_clean();

		foreach ( array( '203.0.113.7', '2001:db8::7', '64 GB DDR5', 'Germany', 'Log in as root with your SSH key.', 'Cancel server', 'data-delete-box' ) as $shown ) {
			$this->assertStringContainsString( $shown, $html );
		}

		foreach ( array( 'S-1234', 'AX42', 'FSN1', '99', 'Hetzner', 'Robot' ) as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html, $hidden );
		}

		wp_set_current_user( $this->new_user() );
		ob_start();
		ManualServerPanel::account( $r );
		$this->assertSame( '', (string) ob_get_clean(), 'Another customer sees nothing.' );

		ob_start();
		( new ManualServerDriver() )->render_admin( $r );
		$admin = (string) ob_get_clean();
		$this->assertStringContainsString( 'S-1234', $admin );
		$this->assertStringContainsString( 'AX42-1', $admin );
	}

	public function test_a_server_that_is_not_delivered_says_so_on_the_card(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );
		Jobs::run( $r->id() );

		wp_set_current_user( $user );
		ob_start();
		ManualServerPanel::account( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ready in up to 10 minutes', $html );
		$this->assertStringContainsString( 'contact support', $html );
	}

	public function test_the_account_area_lists_the_server(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->ready_server( $v['u'], $user );
		$area = ServiceArea::get( Dedicated::class );

		$this->assertContains( $area, ServiceArea::all() );
		$this->assertSame( 'dedicated-servers', $area->slug() );
		$this->assertTrue( $area->is_member( $r->license() ) );

		$row = $area->row( array( 'license' => $r->license(), 'product' => 'Dedicated server' ), $r );
		$this->assertSame( (string) $r->get( 'server_name' ), $row['name'] );
		$this->assertSame( '64 GB DDR5, Germany', $row['details'] );
	}

	public function test_the_ready_email_link_goes_to_the_dedicated_page(): void {
		$v    = $this->server_product();
		$r    = $this->ready_server( $v['u'], $this->new_user() );
		$vals = ManualServerPanel::email_values( array( 'account_url' => 'x' ), $r );

		$this->assertSame( ServiceArea::get( Dedicated::class )->url( $r->license() ), $vals['account_url'] );
		$this->assertSame( array( 'x' => 'y' ), ManualServerPanel::email_details( array( 'x' => 'y' ), 'beltoft_services_paused', $r ) );
	}

	public function test_the_usage_invoice_names_the_server(): void {
		$v    = $this->server_product();
		$r    = $this->ready_server( $v['u'], $this->new_user() );
		$name = (string) $r->get( 'server_name' );

		$this->assertSame( $name, Renewal::label( $r->id() ) );
		$this->assertStringContainsString( $name, Renewal::invoice_title( 'server', $r->id() ) );
		$this->assertStringContainsString( 'Dedicated server', Renewal::invoice_title( 'server', $r->id() ) );
	}
}

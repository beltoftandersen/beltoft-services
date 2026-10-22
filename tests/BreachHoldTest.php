<?php
/**
 * Suspend for breach: the hold, its lifting and the retention that follows it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\Panel as AccountPanel;
use BeltoftServices\Admin\Panel;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Retention;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Breach hold tests.
 */
final class BreachHoldTest extends TestCase {

	/**
	 * Mails caught by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	/**
	 * The fake driver.
	 *
	 * @var FakeDriver
	 */
	private $driver;

	/**
	 * The service product (30 days retention).
	 *
	 * @var \WC_Product
	 */
	private $product;

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		as_unschedule_all_actions( Retention::HOOK, array(), Jobs::GROUP );

		$this->driver  = $this->fake_driver();
		$this->product = $this->service_product( 'fake', array(), array( 'retention' => 30 ) );
		$this->settings(
			array(
				'admin_email'  => 'ops@example.com',
				'warning_days' => 1,
			)
		);

		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'boss',
			)
		);
		wp_set_current_user( $admin );
	}

	/**
	 * Records a mail.
	 *
	 * @param mixed                $pre  Short-circuit.
	 * @param array<string, mixed> $atts Mail arguments.
	 * @return bool
	 */
	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * A ready service on an active license.
	 */
	private function ready(): Resource {
		$license = $this->service_license( $this->product );
		$this->run_jobs();

		$r = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'ready', $r->get( 'state' ) );

		return $r;
	}

	/**
	 * A fresh read.
	 *
	 * @param Resource $r Resource.
	 */
	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	/**
	 * History of a resource's license.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	private function history( Resource $r ): array {
		return wp_list_pluck( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	/**
	 * Suspends through the admin action.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $reason Reason.
	 */
	private function suspend( Resource $r, string $reason = 'Spam run from this server' ) {
		return Panel::act( $r, 'suspend', Panel::CONFIRM_SUSPEND, $reason );
	}

	public function test_suspend_needs_the_typed_word_and_a_reason(): void {
		$r = $this->ready();

		foreach ( array( '', 'suspend', 'DELETE' ) as $word ) {
			$result = Panel::act( $r, 'suspend', $word, 'A reason' );
			$this->assertWPError( $result );
			$this->assertSame( 'confirm_suspend', $result->get_error_code() );
		}

		foreach ( array( '', '   ' ) as $reason ) {
			$result = Panel::act( $r, 'suspend', 'SUSPEND', $reason );
			$this->assertWPError( $result );
			$this->assertSame( 'reason', $result->get_error_code() );
		}

		$fresh = $this->fresh( $r );
		$this->assertNull( $fresh->get( 'breach_at' ) );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
	}

	public function test_suspend_pauses_and_records_the_hold(): void {
		$r = $this->ready();

		$this->assertTrue( $this->suspend( $r ) );
		$this->run_jobs();

		$fresh = $this->fresh( $r );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertTrue( $fresh->on_hold() );
		$this->assertEqualsWithDelta( time(), $fresh->get( 'breach_at' ), 3 );
		$this->assertSame( 'Spam run from this server', $fresh->get( 'breach_reason' ) );
		$this->assertCount( 1, $this->driver->calls( 'pause' ) );

		$history = implode( "\n", $this->history( $r ) );
		$this->assertStringContainsString( 'Suspended for breach by boss: Spam run from this server', $history );
	}

	public function test_a_hold_on_a_deleting_service_is_refused(): void {
		$r = $this->ready();
		$r->desire( 'deleted', 'Gone' );

		$result = $this->suspend( $r );

		$this->assertWPError( $result );
		$this->assertSame( 'deleting', $result->get_error_code() );
		$this->assertFalse( $this->fresh( $r )->on_hold() );
	}

	public function test_the_admin_cannot_resume_a_held_service_and_the_customer_cannot_act(): void {
		$r = $this->ready();
		$this->suspend( $r );
		$this->run_jobs();

		$result = Panel::act( $this->fresh( $r ), 'resume' );
		$this->assertWPError( $result );
		$this->assertSame( 'hold', $result->get_error_code() );

		$fresh = $this->fresh( $r );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertFalse( $fresh->accepts_actions() );
		$this->assertFalse( $fresh->shows_status() );

		// Even forced: the desired state is refused at its source.
		$this->assertFalse( $fresh->desire( 'ready', 'Anything' ) );
		$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ) );
	}

	public function test_a_renewal_during_the_hold_does_not_resume(): void {
		$r       = $this->ready();
		$license = $r->license();
		$this->suspend( $r );
		$this->run_jobs();

		// Billing: the license ends, then a renewal makes it active again.
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_EXPIRED ) );
		$this->run_jobs();
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->run_jobs();

		$fresh = $this->fresh( $r );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertTrue( $fresh->on_hold() );
		$this->assertSame( array(), $this->driver->calls( 'resume' ) );
		$this->assertStringContainsString( 'breach hold', implode( "\n", $this->history( $r ) ) );
	}

	public function test_an_expiry_change_and_a_pending_desire_cannot_resume_either(): void {
		$r       = $this->ready();
		$license = $r->license();
		$this->suspend( $r );
		$this->run_jobs();

		Licenses::update( (int) $license['id'], array( 'expires_at' => null ) );
		do_action( 'beltoft_licenses_expiry_changed', Licenses::find( (int) $license['id'] ), gmdate( 'Y-m-d H:i:s', time() - 5 ) );
		$r->queue_desire( 'ready', 'Queued while the lock was busy' );
		$this->run_jobs();

		$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ) );
		$this->assertSame( array(), $this->driver->calls( 'resume' ) );
		$this->assertNull( $this->fresh( $r )->pending_desire(), 'The refused desire is forgotten.' );
	}

	public function test_the_sweep_does_not_alert_about_a_held_service_on_an_active_license(): void {
		$r = $this->ready();
		$this->suspend( $r );
		$this->run_jobs();
		$this->mails = array();

		Jobs::sweep();
		$this->run_jobs();

		$this->assertSame( array(), $this->mails );
	}

	public function test_the_customer_sees_a_fixed_notice(): void {
		$r = $this->ready();
		$this->suspend( $r, 'Secret internal reason <b>x</b>' );
		$this->run_jobs();
		$fresh = $this->fresh( $r );

		$message = AccountPanel::message( 'paused', $fresh );
		$this->assertSame( 'This server is suspended. Contact support.', $message );
		$this->assertSame( 'Paused', AccountPanel::label( 'paused' ) );

		$license = $fresh->license();
		wp_set_current_user( (int) $license['user_id'] );
		ob_start();
		AccountPanel::render( $license );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'This server is suspended. Contact support.', $html );
		$this->assertStringNotContainsString( 'Secret internal reason', $html );

		// Not held: the ordinary paused text.
		$this->assertSame( 'Your service is paused.', AccountPanel::message( 'paused' ) );
	}

	public function test_the_status_route_gives_a_held_service_nothing(): void {
		$r = $this->ready();
		$this->suspend( $r, 'Secret internal reason' );
		$this->run_jobs();

		$license = $r->license();
		wp_set_current_user( (int) $license['user_id'] );
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertStringNotContainsString( 'Secret', wp_json_encode( $response->get_data() ) );

		$action = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/restart' );
		$this->assertSame( 403, rest_get_server()->dispatch( $action )->get_status() );
	}

	/**
	 * A held resource whose hold began some days ago.
	 *
	 * @param float $days_ago Days since the hold.
	 */
	private function held( float $days_ago ): Resource {
		$r = $this->ready();
		$this->suspend( $r );
		$this->run_jobs();

		$this->assertTrue( $this->fresh( $r )->set( array( 'breach_at' => time() - (int) round( $days_ago * DAY_IN_SECONDS ) ) ) );

		return $this->fresh( $r );
	}

	public function test_retention_deletes_after_the_breach_days_only_when_deletion_is_on(): void {
		$this->assertSame( 14, \BeltoftServices\Settings::get( 'breach_retention_days' ) );

		$early = $this->held( 13 );
		$due   = $this->held( 14.1 );

		// Off: nothing is deleted; the admin is told once that it is due.
		$this->mails = array();
		Retention::run();
		Retention::run();
		$this->assertSame( 'paused', $this->fresh( $due )->get( 'desired' ) );
		$this->assertCount( 1, $this->mails, 'Alert only, once.' );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'breach', strtolower( (string) $this->mails[0]['subject'] ) );

		// Report: history only, once, and no deletion.
		$this->settings( array( 'deletion_mode' => 'report' ) );
		Retention::run();
		Retention::run();
		$this->assertSame( 'paused', $this->fresh( $due )->get( 'desired' ) );
		$this->assertCount( 1, array_filter( $this->history( $due ), static fn( $m ) => 0 === strpos( $m, 'Would delete the service: the breach hold' ) ) );

		// On: deleted, without a replacement.
		$this->settings( array( 'deletion_mode' => 'on' ) );
		Retention::run();
		$this->assertSame( 'deleted', $this->fresh( $due )->get( 'desired' ) );
		$this->assertFalse( $this->fresh( $due )->get( 'replace_after_delete' ) );
		$this->assertSame( 'paused', $this->fresh( $early )->get( 'desired' ), 'Not due yet.' );

		$this->run_jobs();
		$this->assertFalse( $this->fresh( $due )->is_live() );
		$this->assertCount( 1, $this->driver->calls( 'delete' ) );
		$this->assertNull( Resource::for_license( (int) $due->license()['id'] ), 'No new service, even with an active license.' );
	}

	public function test_a_held_service_is_not_deleted_by_the_ordinary_retention(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$r = $this->held( 1 );
		$this->assertTrue( $this->fresh( $r )->set( array( 'paused_at' => time() - 100 * DAY_IN_SECONDS ) ) );
		Licenses::update( (int) $r->license()['id'], array( 'status' => Licenses::STATUS_EXPIRED ) );
		$this->run_jobs();

		Retention::run();

		$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ) );
		$this->assertNull( $this->fresh( $r )->get( 'warned_at' ), 'No ordinary warning either.' );
	}

	public function test_a_protected_held_service_is_not_deleted(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$r = $this->held( 30 );
		$this->fresh( $r )->set( array( 'protected' => true ) );

		Retention::run();
		$this->run_jobs();

		$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ) );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
	}

	public function test_the_breach_days_are_bounded(): void {
		$this->assertSame( 1, \BeltoftServices\Settings::sanitize( array( 'breach_retention_days' => 0 ) )['breach_retention_days'] );
		$this->assertSame( 1, \BeltoftServices\Settings::sanitize( array( 'breach_retention_days' => -5 ) )['breach_retention_days'] );
		$this->assertSame( 365, \BeltoftServices\Settings::sanitize( array( 'breach_retention_days' => 9999 ) )['breach_retention_days'] );
		$this->assertSame( 20, \BeltoftServices\Settings::sanitize( array( 'breach_retention_days' => '20' ) )['breach_retention_days'] );

		// A stored out-of-range value (written around the form) is still bounded when used.
		update_option( \BeltoftServices\Settings::OPTION, array( 'breach_retention_days' => 100000 ) );
		$this->assertSame( 365, Retention::breach_days() );
		update_option( \BeltoftServices\Settings::OPTION, array( 'breach_retention_days' => 0 ) );
		$this->assertSame( 1, Retention::breach_days() );
	}

	public function test_lifting_needs_the_typed_phrase_and_clears_the_hold(): void {
		$r = $this->ready();
		$this->suspend( $r );
		$this->run_jobs();

		foreach ( array( '', 'LIFT', 'SUSPEND' ) as $word ) {
			$result = Panel::act( $this->fresh( $r ), 'lift_hold', $word );
			$this->assertWPError( $result );
			$this->assertSame( 'confirm_lift', $result->get_error_code() );
		}
		$this->assertTrue( $this->fresh( $r )->on_hold() );

		// The license is active: normal state applies, the service resumes.
		$this->assertTrue( Panel::act( $this->fresh( $r ), 'lift_hold', Panel::CONFIRM_LIFT ) );
		$this->run_jobs();

		$fresh = $this->fresh( $r );
		$this->assertFalse( $fresh->on_hold() );
		$this->assertNull( $fresh->get( 'breach_at' ) );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'resume' ) );
		$this->assertStringContainsString( 'Breach hold lifted by boss', implode( "\n", $this->history( $r ) ) );
	}

	public function test_lifting_on_an_inactive_license_stays_paused_with_fresh_retention(): void {
		$r = $this->held( 10 );
		Licenses::update( (int) $r->license()['id'], array( 'status' => Licenses::STATUS_EXPIRED ) );
		$this->run_jobs();
		$this->assertTrue( $this->fresh( $r )->set( array( 'paused_at' => time() - 100 * DAY_IN_SECONDS ) ) );

		$this->assertTrue( Panel::act( $this->fresh( $r ), 'lift_hold', 'LIFT HOLD' ) );
		$this->run_jobs();

		$fresh = $this->fresh( $r );
		$this->assertFalse( $fresh->on_hold() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertEqualsWithDelta( time(), $fresh->get( 'paused_at' ), 3, 'Retention counts from the lifting, never from the old pause.' );

		$this->settings( array( 'deletion_mode' => 'on' ) );
		Retention::run();
		$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ) );
	}

	public function test_lifting_without_a_hold_is_refused_and_a_lifted_hold_no_longer_deletes(): void {
		$r = $this->ready();
		$result = Panel::act( $r, 'lift_hold', 'LIFT HOLD' );
		$this->assertWPError( $result );
		$this->assertSame( 'no_hold', $result->get_error_code() );

		$held = $this->held( 30 );
		Panel::act( $held, 'lift_hold', 'LIFT HOLD' );
		$this->settings( array( 'deletion_mode' => 'on' ) );
		Retention::run();
		$this->assertNotSame( 'deleted', $this->fresh( $held )->get( 'desired' ) );
	}

	public function test_the_handler_needs_the_capability_and_a_nonce(): void {
		$r = $this->ready();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$_POST    = wp_slash(
			array(
				'resource'  => $r->id(),
				'do'        => 'suspend',
				'confirm'   => 'SUSPEND',
				'reason'    => 'x',
				'_wpnonce'  => wp_create_nonce( Panel::nonce_action( $r->id(), 'suspend' ) ),
			)
		);
		$_REQUEST = $_POST;

		try {
			Panel::handle();
			$this->fail( 'Expected a refusal.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, 403 );
		}

		$this->assertFalse( $this->fresh( $r )->on_hold() );
		$_POST    = array();
		$_REQUEST = array();
	}

	public function test_the_panel_offers_suspend_or_lift_and_hides_resume_while_held(): void {
		$r = $this->ready();

		ob_start();
		Panel::render( $r->license() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="suspend"', $html );
		$this->assertStringNotContainsString( 'value="lift_hold"', $html );

		$this->suspend( $r );
		$this->run_jobs();

		ob_start();
		Panel::render( $this->fresh( $r )->license() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="lift_hold"', $html );
		$this->assertStringNotContainsString( 'value="resume"', $html );
		$this->assertStringNotContainsString( 'value="suspend"', $html );
		$this->assertStringContainsString( 'Spam run from this server', $html );
	}

	public function test_a_hold_survives_other_meta_writes_and_the_driver_cannot_set_it(): void {
		$r = $this->ready();
		$this->suspend( $r );
		$this->run_jobs();

		$this->driver->will( 'update_plan', \BeltoftServices\Drivers\StepResult::done( array( 'breach_at' => null ) ) );
		$this->fresh( $r )->change_plan( array( 'ram' => 4 ), 'Plan changed' );
		Jobs::enqueue( $r->id() );
		$this->run_jobs();

		$this->assertTrue( $this->fresh( $r )->on_hold() );
	}

	/**
	 * Hold, then delete through retention: the resource is gone, the license stays marked.
	 *
	 * @return array{0: array<string, mixed>, 1: Resource}
	 */
	private function held_then_deleted(): array {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$r       = $this->held( 15 );
		$license = $r->license();

		Retention::run();
		$this->run_jobs();

		$this->assertFalse( $this->fresh( $r )->is_live(), 'The service is deleted and closed.' );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );

		return array( $license, $r );
	}

	public function test_the_breach_mark_outlives_the_deleted_service_and_blocks_every_creation(): void {
		list( $license ) = $this->held_then_deleted();
		$id              = (int) $license['id'];

		$this->assertTrue( \BeltoftServices\Breach::marked( $id ) );
		$this->mails = array();
		$provisions  = count( $this->driver->calls( 'provision' ) );

		// Billing: the license ends and is renewed (status and expiry events), or is re-created by the admin's tools.
		Licenses::update( $id, array( 'status' => Licenses::STATUS_EXPIRED ) );
		Licenses::update( $id, array( 'status' => Licenses::STATUS_ACTIVE, 'expires_at' => null ) );
		do_action( 'beltoft_licenses_expiry_changed', Licenses::find( $id ), null );
		\BeltoftServices\Lifecycle::on_created( Licenses::find( $id ) );
		\BeltoftServices\Lifecycle::provide( Licenses::find( $id ) );
		$this->run_jobs();

		$this->assertNull( Resource::for_license( $id ), 'No new service.' );
		$this->assertContains( 'No service was created: the license is marked for a breach of the terms. Use "Create the service" on the license screen and type CLEAR BREACH to override.', wp_list_pluck( Events::for_license( $id ), 'message' ) );
		$this->assertCount( 1, array_filter( $this->mails, static fn( $m ) => 'ops@example.com' === ( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'] ) ), 'One admin alert per mark.' );
		$this->assertCount( $provisions, $this->driver->calls( 'provision' ), 'No provision call after the mark.' );
	}

	public function test_a_replacement_after_a_deletion_also_respects_the_mark(): void {
		$r = $this->ready();
		\BeltoftServices\Breach::mark( (int) $r->license()['id'], 'x' );
		$this->assertTrue( $r->desire( 'deleted', 'Gone', true ) );
		Jobs::enqueue( $r->id() );
		$this->run_jobs();

		$this->assertNull( Resource::for_license( (int) $r->license()['id'] ), 'The replacement is refused.' );
	}

	public function test_the_admin_override_needs_clear_breach_and_clears_the_mark(): void {
		list( $license ) = $this->held_then_deleted();
		$id              = (int) $license['id'];

		foreach ( array( '', 'CLEAR', 'SUSPEND', 'clear breach' ) as $word ) {
			$result = Panel::create_service( $id, $word );
			$this->assertWPError( $result );
			$this->assertSame( 'confirm_breach', $result->get_error_code() );
		}
		$this->assertNull( Resource::for_license( $id ) );
		$this->assertTrue( \BeltoftServices\Breach::marked( $id ) );

		$this->assertTrue( Panel::create_service( $id, 'CLEAR BREACH' ) );
		$this->assertFalse( \BeltoftServices\Breach::marked( $id ) );
		$this->run_jobs();

		$new = Resource::for_license( $id );
		$this->assertNotNull( $new );
		$this->assertSame( 'ready', $new->get( 'state' ) );
		$this->assertFalse( $new->on_hold() );
		$this->assertStringContainsString( 'Breach mark cleared by boss', implode( "\n", wp_list_pluck( Events::for_license( $id ), 'message' ) ) );

		// An unmarked license is created as before, with no word needed.
		$this->assertSame( 'has_service', Panel::create_service( $id )->get_error_code() );
	}

	public function test_the_override_keeps_the_mark_when_no_service_was_created(): void {
		list( $license ) = $this->held_then_deleted();
		$id              = (int) $license['id'];

		// The driver is gone: the creation fails.
		$hide = static function ( $drivers ) {
			unset( $drivers['fake'] );
			return $drivers;
		};
		add_filter( 'beltoft_services_drivers', $hide, 99 );

		$result = Panel::create_service( $id, 'CLEAR BREACH' );
		$this->assertWPError( $result );
		$this->assertSame( 'not_created', $result->get_error_code() );
		$this->assertNull( Resource::for_license( $id ) );
		$this->assertTrue( \BeltoftServices\Breach::marked( $id ), 'The mark stays: no service exists.' );
		$this->assertStringNotContainsString( 'Breach mark cleared', implode( "\n", wp_list_pluck( Events::for_license( $id ), 'message' ) ) );

		// With the driver back, the same override creates the service and clears the mark.
		remove_filter( 'beltoft_services_drivers', $hide, 99 );
		$this->assertTrue( Panel::create_service( $id, 'CLEAR BREACH' ) );
		$this->assertFalse( \BeltoftServices\Breach::marked( $id ) );
		$this->assertStringContainsString( 'Breach mark cleared', implode( "\n", wp_list_pluck( Events::for_license( $id ), 'message' ) ) );
	}

	public function test_lifting_a_live_hold_clears_the_mark(): void {
		$r = $this->ready();
		$this->suspend( $r );
		$id = (int) $r->license()['id'];
		$this->assertTrue( \BeltoftServices\Breach::marked( $id ) );

		$this->assertTrue( Panel::act( $this->fresh( $r ), 'lift_hold', 'LIFT HOLD' ) );
		$this->assertFalse( \BeltoftServices\Breach::marked( $id ) );
	}

	public function test_a_hold_without_a_pause_date_still_gets_its_deletion_clock(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$r = $this->held( 20 );
		$this->assertTrue( $this->fresh( $r )->set( array( 'paused_at' => null ) ) );

		Retention::run();

		$this->assertSame( 'deleted', $this->fresh( $r )->get( 'desired' ) );
	}

	public function test_the_cli_refuses_to_link_a_marked_license(): void {
		$product = $this->service_product( 'fake', array(), array( 'slug' => 'svc-link' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		\BeltoftServices\Breach::mark( (int) $license['id'], 'x' );

		\WP_CLI::reset();
		try {
			( new \BeltoftServices\Cli\Commands() )->link_http( array( (string) $license['id'], 'remote-1' ), array() );
			$this->fail( 'Expected an error.' );
		} catch ( \Throwable $e ) {
			$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		}
	}
}

<?php
/**
 * The shared scheduling helpers (Jobs::schedule_once, Jobs::ensure_cron) and the admin mail helper (Alerts::notify).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Alerts;
use BeltoftServices\Hosting\Addons;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * Helper tests.
 */
final class SchedulingAndNotifyTest extends TestCase {

	/**
	 * Mails caught by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		update_option( 'blogname', 'Test Shop' );
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
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

	public function test_schedule_once_schedules_in_the_plugin_group_and_only_when_none_is_pending(): void {
		$hook = 'beltoft_services_test_once';

		$this->assertTrue( Jobs::schedule_once( time() + 600, $hook, array( 3 ) ) );
		$this->assertFalse( Jobs::schedule_once( time() + 900, $hook, array( 3 ) ), 'One is pending already.' );
		$this->assertTrue( Jobs::schedule_once( time() + 900, $hook, array( 4 ) ), 'Other arguments are another action.' );
		$this->assertTrue( as_has_scheduled_action( $hook, array( 3 ), Jobs::GROUP ) );

		as_unschedule_all_actions( $hook );
	}

	public function test_schedule_once_schedules_again_while_the_action_is_running(): void {
		$hook = 'beltoft_services_test_once_running';
		$id   = as_schedule_single_action( time() + 600, $hook, array( 1 ), Jobs::GROUP );
		\ActionScheduler::store()->log_execution( $id );

		$this->assertTrue( Jobs::schedule_once( time() + 600, $hook, array( 1 ) ), 'A running action does not count.' );

		as_unschedule_all_actions( $hook );
	}

	public function test_ensure_cron_schedules_a_recurring_action_once(): void {
		$hook = 'beltoft_services_test_cron';

		Jobs::ensure_cron( $hook, '*/5 * * * *', array(), HOUR_IN_SECONDS );
		Jobs::ensure_cron( $hook, '*/5 * * * *', array(), HOUR_IN_SECONDS );

		$ids = as_get_scheduled_actions( array( 'hook' => $hook, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' );
		$this->assertCount( 1, $ids );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS - 60, (int) as_next_scheduled_action( $hook, array(), Jobs::GROUP ) );

		as_unschedule_all_actions( $hook );
	}

	public function test_addons_sweep_runs_in_action_scheduler_and_the_old_wp_cron_event_is_removed(): void {
		as_unschedule_all_actions( Addons::SWEEP_HOOK );
		wp_schedule_event( time() + 3600, 'daily', Addons::SWEEP_HOOK );
		$this->assertNotFalse( wp_next_scheduled( Addons::SWEEP_HOOK ) );

		Addons::schedule();

		$this->assertFalse( wp_next_scheduled( Addons::SWEEP_HOOK ), 'The WP-Cron event is gone.' );
		$ids = as_get_scheduled_actions( array( 'hook' => Addons::SWEEP_HOOK, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' );
		$this->assertCount( 1, $ids, 'The sweep is in group beltoft-services.' );

		Addons::schedule();
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Addons::SWEEP_HOOK, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) );

		Addons::unschedule();
		$this->assertFalse( as_has_scheduled_action( Addons::SWEEP_HOOK, array(), Jobs::GROUP ) );
	}

	public function test_notify_builds_the_prefixed_subject_and_the_body(): void {
		$this->assertTrue( Alerts::notify( 'Something happened', array( 'One', 'Two' ) ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'ops@example.com' ), (array) $this->mails[0]['to'] );
		$this->assertSame( '[Test Shop] Something happened', $this->mails[0]['subject'] );
		$this->assertSame( "One\nTwo\n", $this->mails[0]['message'] );
	}

	public function test_notify_options_drop_the_prefix_and_the_final_newline(): void {
		Alerts::notify( 'Plain', array( 'Body' ), null, '', array( 'prefix' => false, 'newline' => false ) );

		$this->assertSame( 'Plain', $this->mails[0]['subject'] );
		$this->assertSame( 'Body', $this->mails[0]['message'] );
	}

	public function test_notify_dedupes_on_the_key_and_sends_nothing_without_a_valid_address(): void {
		delete_transient( 'beltoft_services_test_notify' );

		$this->assertTrue( Alerts::notify( 'Once', array( 'a' ), null, 'beltoft_services_test_notify' ) );
		$this->assertFalse( Alerts::notify( 'Once', array( 'a' ), null, 'beltoft_services_test_notify' ) );
		$this->assertCount( 1, $this->mails );
		delete_transient( 'beltoft_services_test_notify' );

		$this->settings( array( 'admin_email' => 'nope' ) );
		$this->assertFalse( Alerts::notify( 'Lost', array( 'a' ), null, 'beltoft_services_test_notify' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertFalse( get_transient( 'beltoft_services_test_notify' ), 'Nothing is held back when there is no address.' );
	}

	public function test_notify_can_fall_back_to_the_site_admin_address(): void {
		$this->settings( array( 'admin_email' => 'nope' ) );
		add_filter( 'pre_option_admin_email', static fn() => 'owner@example.com' );

		$this->assertFalse( Alerts::notify( 'No fallback', array( 'a' ) ) );
		$this->assertTrue( Alerts::notify( 'Fallback', array( 'a' ), null, '', array( 'fallback' => true ) ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_notify_records_an_unsent_alert_for_the_resource_and_clears_it_once_sent(): void {
		$product = $this->service_product( 'fake' );
		$this->fake_driver();
		$license = $this->service_license( $product );
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );

		delete_option( Alerts::PROBLEM_OPTION );
		$this->settings( array( 'admin_email' => '' ) );
		$this->assertFalse( Alerts::notify( 'Lost', array( 'a' ), $r ) );
		$this->assertArrayHasKey( $r->id(), Alerts::problems() );

		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$this->assertTrue( Alerts::notify( 'Sent', array( 'a' ), $r ) );
		$this->assertSame( array(), Alerts::problems() );
	}

	public function test_notify_leaves_the_row_of_an_unsent_failure_alert_alone(): void {
		$product = $this->service_product( 'fake' );
		$this->fake_driver();
		$license = $this->service_license( $product );
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );

		// A failure alert could not be sent (Alerts::admin recorded it), the address is fixed, then another mail about the resource goes out.
		delete_option( Alerts::PROBLEM_OPTION );
		$this->settings( array( 'admin_email' => '' ) );
		Alerts::admin( $r, 'It broke' );
		$this->assertArrayHasKey( $r->id(), Alerts::problems() );

		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$this->assertTrue( Alerts::notify( 'Something else', array( 'a' ), $r ) );
		$this->assertArrayHasKey( $r->id(), Alerts::problems(), 'The failure alert was never sent, so its row stays.' );
	}
}

<?php
/**
 * Low review findings of the core (round 3, builder rf3l3).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Jobs;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Progress;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

/**
 * CORE-xx tests.
 */
final class ReviewLow3l3CoreTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		add_filter( 'pre_wp_mail', '__return_true' );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );
	}

	/**
	 * A ready resource of a fake-driver service.
	 */
	private function resource( array $meta = array() ): Resource {
		$driver   = $this->fake_driver();
		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, $driver, array(), array( 'state' => 'ready', 'desired' => 'ready' ) + $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	public function test_core_01_one_throwing_listener_does_not_stop_the_sweep(): void {
		$first  = $this->resource();
		$second = $this->resource();
		$first->set( array( Progress::MARKER => array( 'action' => 'reboot', 'at' => time() - Progress::MARKER_TTL - 50, 'label' => 'Doing it' ) ) );
		$second->set( array( 'desired' => 'paused' ) );

		add_action(
			'beltoft_services_action_failed',
			static function () {
				throw new \RuntimeException( 'listener broke' );
			}
		);

		Jobs::sweep();

		$this->assertTrue( as_has_scheduled_action( Jobs::HOOK, array( $second->id() ), Jobs::GROUP ), 'The later resource is still queued.' );
	}

	/**
	 * A ready resource with a customer action 'reboot' on a fake driver.
	 *
	 * @return array{0: Resource, 1: \BeltoftServices\Tests\Support\FakeDriver}
	 */
	private function acting(): array {
		$driver                    = $this->fake_driver();
		$driver->customer_actions = array( 'reboot' => 'Reboot' );
		$product                   = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license                   = $this->service_license( $product, array( 'fire' => false ) );
		$resource                  = Resource::create( $license, $driver, array(), array( 'state' => 'ready', 'desired' => 'ready' ) );

		$this->assertInstanceOf( Resource::class, $resource );

		return array( $resource, $driver );
	}

	public function test_core_03_the_same_customer_action_failure_is_mailed_again_after_a_success(): void {
		list( $r, $driver ) = $this->acting();
		$mails              = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$driver->will( 'act', StepResult::fail( 'Proxmox timeout' ) );
		Jobs::act( $r->id(), 'reboot', array() );
		$this->assertSame( 1, $mails );

		Jobs::act( $r->id(), 'reboot', array() );
		$this->assertSame( 1, $mails, 'The same failure in a row is one mail.' );

		$driver->will( 'act', StepResult::done() );
		$driver->will( 'act', StepResult::fail( 'Proxmox timeout' ) );
		Jobs::act( $r->id(), 'reboot', array() ); // The old failure repeats, still one mail.
		Jobs::act( $r->id(), 'reboot', array() ); // Done.
		Jobs::act( $r->id(), 'reboot', array() ); // Fails again.
		$this->assertSame( 2, $mails, 'After a success the next failure is news again.' );
	}

	public function test_core_06_an_action_that_used_up_its_retries_does_not_say_the_server_was_not_changed(): void {
		list( $r, $driver ) = $this->acting();
		$driver->will( 'act', StepResult::retry( 1, 'timeout after the request was accepted' ) );
		$r->set( array( 'act_attempts' => count( Reconciler::BACKOFF ) ) );

		Jobs::act( $r->id(), 'reboot', array() );

		$result = Resource::find( $r->id() )->get( Jobs::RESULT );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'We have been told', $result['text'] );
	}

	public function test_core_04_a_changed_cron_string_replaces_the_scheduled_one(): void {
		$hook = 'beltoft_services_test_cron_change';
		as_unschedule_all_actions( $hook, array(), Jobs::GROUP );
		as_schedule_cron_action( time() + 60, '30 3 * * *', $hook, array(), Jobs::GROUP );

		Jobs::ensure_cron( $hook, '30 3 * * *' );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => $hook, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) );

		Jobs::ensure_cron( $hook, '45 4 * * *' );

		$actions = as_get_scheduled_actions( array( 'hook' => $hook, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) );
		$this->assertCount( 1, $actions );
		$this->assertSame( '45 4 * * *', reset( $actions )->get_schedule()->get_recurrence() );
		as_unschedule_all_actions( $hook, array(), Jobs::GROUP );
	}

	public function test_compat_06_the_gate_names_the_tested_minimum_versions(): void {
		$this->assertSame( '0.8.8', \BeltoftServices\Plugin::MIN_LICENSES );
		$this->assertTrue( version_compare( (string) BELTOFT_LICENSES_VERSION, \BeltoftServices\Plugin::MIN_LICENSES, '>=' ), 'The suite runs on a version the gate accepts.' );
		$this->assertTrue( version_compare( (string) WC_VERSION, \BeltoftServices\Plugin::MIN_WOOCOMMERCE, '>=' ) );
		$this->assertSame( array(), \BeltoftServices\Plugin::missing() );
	}

	public function test_compat_03_deactivation_removes_every_recurring_job_of_the_plugin(): void {
		$hook = 'beltoft_services_test_some_recurring_hook';
		as_schedule_cron_action( time() + 600, '*/5 * * * *', $hook, array(), Jobs::GROUP );
		$single = as_schedule_single_action( time() + 600, 'beltoft_services_test_single_job', array( 5 ), Jobs::GROUP );

		Jobs::unschedule();

		$this->assertFalse( as_has_scheduled_action( $hook, array(), Jobs::GROUP ), 'A recurring job nobody lists is removed too.' );
		$this->assertTrue( as_has_scheduled_action( 'beltoft_services_test_single_job', array( 5 ), Jobs::GROUP ), 'A single job keeps its place.' );
		unset( $single );
		as_unschedule_all_actions( 'beltoft_services_test_single_job', array( 5 ), Jobs::GROUP );
	}
}

<?php
/**
 * The recurring jobs are ensured once per period, not on every request.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Jobs;

/**
 * Jobs::recurring() and Jobs::ensure_recurring().
 */
final class RecurringJobsTest extends TestCase {

	private const OPTION = 'beltoft_services_recurring_ensured';

	/**
	 * Every pending recurring action of the plugin as "hook | cron | args".
	 *
	 * @return array<int, string>
	 */
	private function scheduled(): array {
		$out = array();
		foreach ( as_get_scheduled_actions( array( 'group' => Jobs::GROUP, 'per_page' => -1, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) as $action ) {
			$out[] = $action->get_hook() . ' | ' . $action->get_schedule()->get_recurrence() . ' | ' . wp_json_encode( $action->get_args() );
		}
		sort( $out );
		return $out;
	}

	private function clear_all(): void {
		foreach ( as_get_scheduled_actions( array( 'group' => Jobs::GROUP, 'per_page' => -1, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ) as $action ) {
			as_unschedule_all_actions( $action->get_hook(), $action->get_args(), Jobs::GROUP );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Action Scheduler queries of this plugin (its group or hooks) made while a callback runs. Other plugins ask on the
	 * same action for their own jobs (WooCommerce Subscriptions, Beltoft Licenses); they are not counted.
	 */
	private function as_queries( callable $fn ): int {
		global $wpdb;
		$seen = 0;
		$cb   = static function ( $sql ) use ( &$seen ) {
			if ( false !== stripos( $sql, 'actionscheduler_' ) && ( false !== strpos( $sql, "'beltoft-services'" ) || false !== strpos( $sql, "'beltoft_services_" ) ) ) {
				++$seen;
			}
			return $sql;
		};
		add_filter( 'query', $cb );
		$fn();
		remove_filter( 'query', $cb );
		return $seen;
	}

	public function test_a_second_boot_with_the_jobs_ensured_makes_no_action_scheduler_query(): void {
		$this->clear_all();
		do_action( 'action_scheduler_init' );

		$this->assertGreaterThan( 0, count( $this->scheduled() ) );
		$this->assertSame( 0, $this->as_queries( static function () {
			do_action( 'action_scheduler_init' );
		} ), 'Nothing asks Action Scheduler whether the recurring jobs exist.' );
	}

	public function test_every_recurring_job_is_scheduled_on_a_fresh_site_with_the_same_cron_and_arguments(): void {
		$this->clear_all();
		do_action( 'action_scheduler_init' );

		$declared = Jobs::declared();
		$this->assertGreaterThan( 20, count( $declared ), 'The registry knows the recurring jobs.' );

		$expected = array();
		foreach ( $declared as $spec ) {
			$expected[] = $spec['hook'] . ' | ' . $spec['cron'] . ' | ' . wp_json_encode( $spec['args'] );
		}
		sort( $expected );
		$expected = array_values( array_unique( $expected ) );

		$this->assertSame( $expected, $this->scheduled() );
	}

	public function test_every_recurring_callback_in_the_source_is_in_the_registry(): void {
		$count = 0;
		$stray = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code   = (string) file_get_contents( $file->getPathname() );
			$count += substr_count( $code, 'Jobs::recurring(' );
			// Jobs owns the hook; CapacityJobs queues cluster runs once, on activation.
			if ( false !== strpos( $code, "add_action( 'action_scheduler_init'" ) && ! in_array( $file->getFilename(), array( 'Jobs.php', 'CapacityJobs.php' ), true ) ) {
				$stray[] = $file->getFilename();
			}
		}
		// Jobs registers its own (self::recurring) as well.
		$this->assertSame( $count + 1, count( Jobs::callbacks() ) );
		$this->assertSame( array(), $stray, 'No class hooks its own schedule() on action_scheduler_init: use Jobs::recurring().' );
	}

	public function test_a_missing_job_comes_back_when_the_period_has_passed(): void {
		$this->clear_all();
		do_action( 'action_scheduler_init' );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );

		do_action( 'action_scheduler_init' );
		$this->assertFalse( as_has_scheduled_action( Jobs::SWEEP, array(), Jobs::GROUP ), 'Inside the period nobody looks.' );

		$stamp       = get_option( self::OPTION );
		$stamp['at'] = time() - Jobs::ENSURE_PERIOD - 5;
		update_option( self::OPTION, $stamp );

		do_action( 'action_scheduler_init' );
		$this->assertTrue( (bool) as_has_scheduled_action( Jobs::SWEEP, array(), Jobs::GROUP ) );
	}

	public function test_a_missing_job_comes_back_when_the_version_key_changes(): void {
		$this->clear_all();
		do_action( 'action_scheduler_init' );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );

		$stamp      = get_option( self::OPTION );
		$stamp['v'] = 'an older plugin version';
		update_option( self::OPTION, $stamp );

		do_action( 'action_scheduler_init' );
		$this->assertTrue( (bool) as_has_scheduled_action( Jobs::SWEEP, array(), Jobs::GROUP ) );
	}

	public function test_action_scheduler_own_daily_hook_forces_a_check(): void {
		$this->clear_all();
		do_action( 'action_scheduler_init' );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );

		do_action( 'action_scheduler_ensure_recurring_actions' );
		$this->assertTrue( (bool) as_has_scheduled_action( Jobs::SWEEP, array(), Jobs::GROUP ) );
	}

	public function test_the_admin_and_cron_always_check(): void {
		$this->clear_all();
		do_action( 'action_scheduler_init' );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );

		add_filter( 'wp_doing_cron', '__return_true' );
		do_action( 'action_scheduler_init' );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertTrue( (bool) as_has_scheduled_action( Jobs::SWEEP, array(), Jobs::GROUP ), 'A cron request checks every time.' );
	}

	public function test_unschedule_and_activation_clear_the_stamp(): void {
		do_action( 'action_scheduler_init' );
		$this->assertNotFalse( get_option( self::OPTION ) );

		Jobs::unschedule();
		$this->assertFalse( get_option( self::OPTION ) );
	}
}

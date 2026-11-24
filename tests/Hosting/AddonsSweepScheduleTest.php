<?php
/**
 * The add-on sweep runs in Action Scheduler (moved here from SchedulingAndNotifyTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Hosting\Addons;
use BeltoftServices\Jobs;
use BeltoftServices\Tests\TestCase;

/**
 * Scheduling of the hosting add-on sweep.
 */
final class AddonsSweepScheduleTest extends TestCase {

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
}

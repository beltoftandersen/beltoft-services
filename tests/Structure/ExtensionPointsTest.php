<?php
/**
 * The core's neutral extension points, and that the Proxmox features attach to them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Proxmox\AppInstall;
use BeltoftServices\Proxmox\AppStatus;
use BeltoftServices\Proxmox\Vm\CoreHooks;
use BeltoftServices\Proxmox\Disk\DiskDriver;
use BeltoftServices\Proxmox\PrivateAttach;
use BeltoftServices\Tests\TestCase;

/**
 * Filters that replaced the core-to-Proxmox references.
 */
class ExtensionPointsTest extends TestCase {

	public function test_app_jobs_are_added_to_the_runner_hooks_in_order(): void {
		$hooks = apply_filters( 'beltoft_services_job_hooks', array() );

		$this->assertSame( array( AppInstall::HOOK, AppStatus::HOOK ), array_values( array_intersect( $hooks, array( AppInstall::HOOK, AppStatus::HOOK ) ) ) );
		$this->assertSame( 'beltoft_services_app_install', AppInstall::HOOK );
		$this->assertSame( 'beltoft_services_app_check', AppStatus::HOOK );
	}

	public function test_runner_hooks_include_a_hook_a_feature_adds(): void {
		$add = static function ( $hooks ) {
			$hooks[] = 'x_feature_hook';
			return $hooks;
		};
		add_filter( 'beltoft_services_job_hooks', $add );

		$m = new \ReflectionMethod( \BeltoftServices\Jobs::class, 'runner_hooks' );
		$m->setAccessible( true );
		$hooks = $m->invoke( null );
		remove_filter( 'beltoft_services_job_hooks', $add );

		$this->assertSame( array( \BeltoftServices\Jobs::HOOK, \BeltoftServices\Jobs::ACT ), array_slice( $hooks, 0, 2 ) );
		$this->assertContains( 'x_feature_hook', $hooks );
		$this->assertContains( AppInstall::HOOK, $hooks );
		$this->assertContains( AppStatus::HOOK, $hooks );
	}

	public function test_core_hooks_register_proxmox_state_and_retention_handlers(): void {
		$this->assertNotFalse( has_filter( 'beltoft_services_default_retention', array( \BeltoftServices\Proxmox\Vm\ProxmoxDriver::class, 'default_retention' ) ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_default_retention', array( DiskDriver::class, 'default_retention' ) ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_default_retention', array( \BeltoftServices\Proxmox\AppLicenceDriver::class, 'default_retention' ) ) );
		$this->assertNotFalse( has_action( 'beltoft_services_state_changed', array( \BeltoftServices\Proxmox\Vm\Access::class, 'on_state_changed' ) ) );
		$this->assertNotFalse( has_action( 'beltoft_services_state_changed', array( \BeltoftServices\Proxmox\Vm\Operations::class, 'on_state_changed' ) ) );
		$this->assertNotFalse( has_action( 'beltoft_services_state_changed', array( \BeltoftServices\Mail\MailAccess::class, 'on_state_changed' ) ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_park_action', array( CoreHooks::class, 'park_action' ) ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_failure_alert_lines', array( DiskDriver::class, 'failure_alert_lines' ) ) );
	}

	public function test_park_action_is_false_for_other_actions_and_keeps_an_earlier_yes(): void {
		$this->assertTrue( CoreHooks::park_action( true, 'anything', null ) );
		$this->assertFalse( CoreHooks::park_action( false, PrivateAttach::ATTACH, null ) );
		$this->assertFalse( apply_filters( 'beltoft_services_park_action', false, 'reboot', null ) );
	}

	public function test_failure_alert_lines_are_untouched_for_other_drivers(): void {
		$this->assertSame( array( 'a' ), DiskDriver::failure_alert_lines( array( 'a' ), null, array( 'order_id' => 5 ) ) );
		$this->assertSame( array( 'a' ), apply_filters( 'beltoft_services_failure_alert_lines', array( 'a' ), null, array( 'order_id' => 5 ) ) );
	}
}

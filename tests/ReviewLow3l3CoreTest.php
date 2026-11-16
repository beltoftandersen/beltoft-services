<?php
/**
 * Low review findings of the core (round 3, builder rf3l3).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Jobs;
use BeltoftServices\Progress;
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
}

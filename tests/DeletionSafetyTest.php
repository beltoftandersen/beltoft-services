<?php
/**
 * Deletion safety: a renewal cancels a retention deletion that has not started, such deletions are
 * held while deletion is not on, a linked VM is never shut down by a lapsed license.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Retention;

/**
 * Deletion safety tests.
 */
final class DeletionSafetyTest extends TestCase {

	/**
	 * Mails caught.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	/**
	 * The fake driver.
	 *
	 * @var \BeltoftServices\Tests\Support\FakeDriver
	 */
	private $driver;

	/**
	 * The product.
	 *
	 * @var \WC_Product
	 */
	private $product;

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		as_unschedule_all_actions( Retention::HOOK, array(), Jobs::GROUP );

		$this->driver  = $this->fake_driver();
		$this->product = $this->service_product( 'fake', array(), array( 'retention' => 30 ) );
		$this->settings( array( 'admin_email' => 'ops@example.com', 'warning_days' => 1, 'deletion_mode' => 'on' ) );
	}

	/**
	 * A paused service on a lapsed license, past its retention and warned, with retention's deletion requested.
	 */
	private function queued_for_deletion(): Resource {
		$license = $this->service_license( $this->product );
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'ready', $r->get( 'state' ) );
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_EXPIRED ) );
		$this->run_jobs();
		$r = Resource::find( $r->id() );
		$this->assertSame( 'paused', $r->get( 'state' ) );
		$this->assertTrue( $r->set( array( 'paused_at' => time() - 40 * DAY_IN_SECONDS, 'warned_at' => time() - 3 * DAY_IN_SECONDS ) ) );
		Retention::run();
		$r = Resource::find( $r->id() );
		$this->assertSame( 'deleted', $r->get( 'desired' ) );
		$this->assertSame( 'retention', $r->get( 'delete_origin' ) );

		return $r;
	}

	private function renew( Resource $r ): void {
		Licenses::update( (int) $r->license()['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
	}

	public function test_a_renewal_cancels_a_retention_deletion_that_has_not_started(): void {
		$r = $this->queued_for_deletion();
		$this->assertTrue( $r->get( 'replace_after_delete' ) );

		$this->renew( $r );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( '', (string) $fresh->get( 'delete_origin' ) );
		$this->assertFalse( $fresh->get( 'replace_after_delete' ) );

		$this->run_jobs();

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertTrue( $fresh->is_live() );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
	}

	public function test_a_renewal_during_a_started_deletion_is_refused(): void {
		$r = $this->queued_for_deletion();
		$this->assertTrue( $r->set( array( 'delete_started' => true ) ) );

		$this->renew( $r );

		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'desired' ), 'Terminal once a delete call was made.' );
	}

	public function test_an_admin_deletion_stays_terminal(): void {
		$license = $this->service_license( $this->product );
		$this->run_jobs();
		$r = Resource::for_license( (int) $license['id'] );
		$this->assertTrue( $r->desire( 'deleted', 'Deletion requested by admin', false ) );
		$this->assertFalse( $r->desire( 'ready', 'License active' ) );
		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'desired' ) );

		// An admin repeating a retention deletion takes it over.
		$retained = $this->queued_for_deletion();
		$this->assertTrue( $retained->desire( 'deleted', 'Deletion requested by admin', false ) );
		$this->renew( $retained );
		$this->assertSame( 'deleted', Resource::find( $retained->id() )->get( 'desired' ) );
	}

	public function test_the_license_is_checked_again_right_before_the_first_delete_call(): void {
		$r = $this->queued_for_deletion();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- active again with no listener run, as when a desire still waits for its lock.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'status' => 'active' ), array( 'id' => (int) $r->license()['id'] ) );

		$this->run_jobs();

		$fresh = Resource::find( $r->id() );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertTrue( $fresh->is_live() );
	}

	public function test_a_started_retention_deletion_runs_and_marks_the_first_delete_call(): void {
		$r = $this->queued_for_deletion();

		$this->run_jobs();

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'delete' ) );
		$this->assertTrue( $fresh->get( 'delete_started' ) );
	}

	public function test_retention_and_breach_deletions_are_held_while_deletion_is_not_on(): void {
		$r = $this->queued_for_deletion();
		$this->settings( array( 'deletion_mode' => 'off' ) );
		$this->mails = array();

		Jobs::run( $r->id() );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
		$this->assertNotSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertNotSame( 'deleting', $fresh->get( 'state' ) );
		$this->assertCount( 1, $this->mails, 'The admin is told once.' );

		// Report only holds as well; turning deletion on lets it through.
		$this->settings( array( 'deletion_mode' => 'report' ) );
		Jobs::run( $r->id() );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );

		$this->settings( array( 'deletion_mode' => 'on' ) );
		Jobs::run( $r->id() );
		$this->run_jobs();
		$this->assertSame( 'deleted', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'delete' ) );
	}

	public function test_a_breach_deletion_is_held_and_an_admin_deletion_still_runs_while_deletion_is_off(): void {
		$license = $this->service_license( $this->product );
		$this->run_jobs();
		$breach = Resource::for_license( (int) $license['id'] );
		$this->settings( array( 'deletion_mode' => 'off' ) );
		$this->assertTrue( $breach->desire( 'deleted', 'Retention period over: breach hold', false, 'breach' ) );
		Jobs::run( $breach->id() );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
		$this->assertSame( 'ready', Resource::find( $breach->id() )->get( 'state' ) );

		$other_license = $this->service_license( $this->product );
		$this->run_jobs();
		$admin = Resource::for_license( (int) $other_license['id'] );
		$this->assertTrue( $admin->desire( 'deleted', 'Deletion requested by admin', false ) );
		Jobs::run( $admin->id() );
		$this->assertSame( 'deleted', Resource::find( $admin->id() )->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'delete' ) );
	}

}

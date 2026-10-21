<?php
/**
 * Lifecycle: beltoft-licenses actions drive the desired state of services.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Lifecycle tests.
 */
final class LifecycleTest extends TestCase {

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

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );

		$this->driver = $this->fake_driver();
	}

	public function tear_down(): void {
		$this->set_import_running( false );

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
		// Only the admin alerts: the customer's service emails are tested in EmailsTest.
		$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
		if ( false === strpos( $to, 'customer@example.com' ) ) {
			$this->mails[] = $atts;
		}
		return true;
	}

	/**
	 * Sets beltoft-licenses' import flag.
	 *
	 * @param bool $running Running.
	 */
	private function set_import_running( bool $running ): void {
		$flag = new \ReflectionProperty( \BeltoftLicenses\Import::class, 'running' );
		$flag->setAccessible( true );
		$flag->setValue( null, $running );
	}

	/**
	 * A paid license whose service was provisioned.
	 *
	 * @param array<string, mixed> $data License columns.
	 * @return array{0: array<string, mixed>, 1: Resource}
	 */
	private function provisioned( array $data = array() ): array {
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license = $this->service_license( $product, $data );

		$this->run_jobs();

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertNotNull( $resource );
		$this->assertSame( 'ready', $resource->get( 'state' ) );

		return array( Licenses::find( (int) $license['id'] ), $resource );
	}

	/**
	 * A stand-in for a WC_Subscription, with what beltoft-licenses' Subscriptions reads.
	 *
	 * @param int $id  Subscription id.
	 * @param int $end End of the paid period.
	 * @return object
	 */
	private function subscription( int $id, int $end = 0 ) {
		return new class( $id, $end ) {
			/**
			 * Id.
			 *
			 * @var int
			 */
			private $id;

			/**
			 * End time.
			 *
			 * @var int
			 */
			private $end;

			public function __construct( int $id, int $end ) {
				$this->id  = $id;
				$this->end = $end;
			}

			public function get_id() {
				return $this->id;
			}

			public function get_time( $type ) {
				return 'end' === $type ? $this->end : 0;
			}
		};
	}

	/**
	 * The resource as stored now.
	 *
	 * @param Resource $r Resource.
	 */
	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	public function test_paid_license_creates_and_provisions(): void {
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license = $this->service_license( $product );

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertNotNull( $resource, 'The license created its service.' );
		$this->assertSame( 'requested', $resource->get( 'state' ) );
		$this->assertSame( Product::plan( $product->get_id() ), $resource->get( 'plan' ) );
		$this->assertSame( array(), $this->driver->calls(), 'Nothing runs in the request.' );
		$this->assertNotEmpty( Jobs::pending( $resource->id() ) );

		$this->run_jobs();

		$this->assertSame( 'ready', $this->fresh( $resource )->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'provision' ) );
	}

	public function test_non_service_license_is_ignored(): void {
		$product = $this->service_product( '', array(), array( 'slug' => 'plain-plugin' ) );
		$license = $this->service_license( $product );

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		Licenses::update( (int) $license['id'], array( 'max_activations' => 9 ) );

		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertSame( array(), $this->driver->calls() );
	}

	public function test_service_that_cannot_be_created_alerts_the_admin(): void {
		$product = $this->service_product( 'gone', array(), array( 'slug' => 'gone-service' ) );
		$license = $this->service_license( $product );

		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'driver "gone" is not available', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'driver "gone" is not available', implode( "\n", wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $license['id'] ), 'message' ) ) );
	}

	public function test_license_revoked_before_the_job_runs_never_provisions(): void {
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product );

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		$this->run_jobs();

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'paused', $resource->get( 'state' ) );
		$this->assertSame( array(), $this->driver->calls() );
	}

	public function test_medusa_cancel_keeps_service_until_paid_through(): void {
		list( $license, $resource ) = $this->provisioned( array( 'subscription_id' => 4242 ) );

		// The customer cancels: WCS's pending-cancel keeps the license active until the end of
		// the paid period, so the service keeps running.
		\BeltoftLicenses\Lifecycle\Subscriptions::pending_cancel( $this->subscription( 4242, time() + 10 * DAY_IN_SECONDS ) );
		$this->run_jobs();

		$this->assertSame( Licenses::STATUS_ACTIVE, Licenses::find( (int) $license['id'] )['status'] );
		$this->assertSame( 'ready', $this->fresh( $resource )->get( 'desired' ) );
		$this->assertSame( array(), $this->driver->calls( 'pause' ) );

		// The paid period ends: beltoft-licenses' expiry job expires the license, which pauses.
		Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		\BeltoftLicenses\Lifecycle\Expiry::run();
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'pause' ) );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
	}

	public function test_medusa_one_cancel_does_not_disable_the_other_plan(): void {
		$user = self::factory()->user->create();
		$one  = $this->service_product( 'fake', array(), array( 'max' => 3, 'slug' => 'plan-one' ) );
		$two  = $this->service_product( 'fake', array(), array( 'max' => 3, 'slug' => 'plan-two' ) );

		$license_one = $this->service_license( $one, array( 'user_id' => $user ) );
		$license_two = $this->service_license( $two, array( 'user_id' => $user ) );
		$this->run_jobs();

		$resource_one = Resource::for_license( (int) $license_one['id'] );
		$resource_two = Resource::for_license( (int) $license_two['id'] );
		$this->assertNotSame( $resource_one->id(), $resource_two->id() );
		$this->assertSame( 'ready', $resource_one->get( 'state' ) );
		$this->assertSame( 'ready', $resource_two->get( 'state' ) );

		Licenses::update( (int) $license_one['id'], array( 'expires_at' => time() - 60 ) );
		\BeltoftLicenses\Lifecycle\Expiry::run();
		$this->run_jobs();

		$this->assertSame( 'paused', $this->fresh( $resource_one )->get( 'state' ) );
		$this->assertSame( 'ready', $this->fresh( $resource_two )->get( 'state' ), 'The customer\'s other service keeps running.' );
		$this->assertSame( 'ready', $this->fresh( $resource_two )->get( 'desired' ) );
		$this->assertCount( 1, $this->driver->calls( 'pause' ) );
	}

	public function test_medusa_resume_reenables(): void {
		list( $license, $resource ) = $this->provisioned();

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		$this->run_jobs();
		$this->assertSame( 'paused', $this->fresh( $resource )->get( 'state' ) );

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertNull( $fresh->get( 'paused_at' ) );
		$this->assertCount( 1, $this->driver->calls( 'resume' ) );
		$this->assertCount( 1, $this->driver->calls( 'provision' ), 'The same service comes back, not a new one.' );
	}

	public function test_medusa_refund_pauses(): void {
		list( $license, $resource ) = $this->provisioned();

		// A full refund revokes the license (beltoft-licenses Lifecycle\Orders).
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertIsInt( $fresh->get( 'paused_at' ) );
		$this->assertCount( 1, $this->driver->calls( 'pause' ) );
		$this->assertContains( 'License revoked', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $license['id'] ), 'message' ) );
	}

	public function test_medusa_dunning_pauses_after_grace(): void {
		list( $license, $resource ) = $this->provisioned( array( 'subscription_id' => 5151 ) );

		// A failed renewal: on-hold starts the grace period; the license stays active.
		\BeltoftLicenses\Lifecycle\Subscriptions::on_hold( $this->subscription( 5151 ) );
		$this->run_jobs();

		$this->assertSame( Licenses::STATUS_ACTIVE, Licenses::find( (int) $license['id'] )['status'] );
		$this->assertSame( 'ready', $this->fresh( $resource )->get( 'state' ) );
		$this->assertSame( array(), $this->driver->calls( 'pause' ) );

		// Grace is over: the expiry job expires the license.
		Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		\BeltoftLicenses\Lifecycle\Expiry::run();
		$this->run_jobs();

		$this->assertSame( 'paused', $this->fresh( $resource )->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'pause' ) );
	}

	public function test_status_change_pauses_a_license_that_is_already_past_its_expiry(): void {
		list( $license, $resource ) = $this->provisioned();

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->run_jobs();

		$this->assertSame( 'paused', $this->fresh( $resource )->get( 'desired' ), 'Active but past its expiry is expired.' );
		$this->assertSame( array(), $this->driver->calls( 'resume' ) );
	}

	public function test_medusa_plan_change_updates_the_service(): void {
		list( $license, $resource ) = $this->provisioned();

		Licenses::update( (int) $license['id'], array( 'max_activations' => 5 ) );

		$this->assertTrue( $this->fresh( $resource )->get( 'plan_dirty' ) );

		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertCount( 1, $this->driver->calls( 'update_plan' ) );
		$this->assertFalse( $fresh->get( 'plan_dirty' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
	}

	public function test_variation_switch_takes_the_new_plan(): void {
		$this->driver->plan_fields = array( 'ram' => array( 'number', 'RAM', '', 2, true ) );

		$product    = $this->service_product( 'fake', array(), array( 'variations' => array( array( 'ram' => 4 ), array( 'ram' => 8 ) ) ) );
		$variations = $product->get_children();
		$license    = $this->service_license( $product, array( 'variation_id' => $variations[0] ) );
		$this->run_jobs();

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertSame( array( 'ram' => 4 ), $resource->get( 'plan' ) );

		Licenses::update( (int) $license['id'], array( 'variation_id' => $variations[1] ) );
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( array( 'ram' => 8 ), $fresh->get( 'plan' ) );
		$this->assertCount( 1, $this->driver->calls( 'update_plan' ) );
		$this->assertSame( array( 'ram' => 8 ), $this->driver->calls( 'update_plan' )[0]['resource']->get( 'plan' ) );
	}

	public function test_other_changes_leave_the_plan_alone(): void {
		list( $license, $resource ) = $this->provisioned();

		Licenses::update( (int) $license['id'], array( 'email' => 'new@example.com' ) );
		$this->run_jobs();

		$this->assertFalse( $this->fresh( $resource )->get( 'plan_dirty' ) );
		$this->assertSame( array(), $this->driver->calls( 'update_plan' ) );
	}

	public function test_plan_change_while_a_job_holds_the_lock_is_applied_later(): void {
		list( $license, $resource ) = $this->provisioned();

		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		Licenses::update( (int) $license['id'], array( 'max_activations' => 7 ) );
		remove_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		$other->close();

		$this->assertFalse( $this->fresh( $resource )->get( 'plan_dirty' ), 'Not written while the lock was held.' );

		// The retry is due a moment later.
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => \BeltoftServices\Lifecycle::PLAN_HOOK,
				'group'  => Jobs::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $actions );
		\BeltoftServices\Lifecycle::apply_plan( (int) $license['id'] );
		$this->run_jobs();

		$this->assertFalse( $this->fresh( $resource )->get( 'plan_dirty' ) );
		$this->assertCount( 1, $this->driver->calls( 'update_plan' ) );
	}

	public function test_reactivated_license_without_a_service_gets_a_new_one(): void {
		list( $license, $resource ) = $this->provisioned();

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_EXPIRED ) );
		$this->fresh( $resource )->desire( 'deleted', 'Retention period ended', true );
		$this->run_jobs();
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->run_jobs();

		$new = Resource::for_license( (int) $license['id'] );
		$this->assertNotNull( $new );
		$this->assertNotSame( $resource->id(), $new->id() );
		$this->assertSame( 'ready', $new->get( 'state' ) );
		$this->assertCount( 2, $this->driver->calls( 'provision' ) );
	}

	public function test_import_running_does_nothing(): void {
		list( $license, $resource ) = $this->provisioned();
		$product                    = wc_get_product( (int) $license['product_id'] );
		$calls                      = count( $this->driver->calls() );

		$this->set_import_running( true );

		$imported = $this->service_license( $product );
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_REVOKED ) );
		Licenses::update( (int) $license['id'], array( 'max_activations' => 9 ) );

		$this->set_import_running( false );
		$this->run_jobs();

		$this->assertNull( Resource::for_license( (int) $imported['id'] ), 'No service for an imported license.' );
		$fresh = $this->fresh( $resource );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertFalse( $fresh->get( 'plan_dirty' ) );
		$this->assertCount( $calls, $this->driver->calls() );
	}

	public function test_switch_to_another_driver_replaces_the_service(): void {
		$other = $this->fake_driver( 'fake2' );

		list( $license, $resource ) = $this->provisioned();
		$product                    = $this->service_product( 'fake2', array(), array( 'slug' => 'other-service' ) );

		Licenses::update( (int) $license['id'], array( 'product_id' => $product->get_id() ) );

		$this->assertSame( 'deleted', $this->fresh( $resource )->get( 'desired' ) );
		$this->assertTrue( $this->fresh( $resource )->get( 'replace_after_delete' ) );
		$this->assertFalse( $this->fresh( $resource )->get( 'plan_dirty' ), 'The old service never gets the new plan.' );

		$this->run_jobs();

		$old = $this->fresh( $resource );
		$this->assertSame( 'deleted', $old->get( 'state' ) );
		$this->assertFalse( $old->is_live() );
		$this->assertCount( 1, $this->driver->calls( 'delete' ) );
		$this->assertSame( array(), $this->driver->calls( 'update_plan' ) );

		$new = Resource::for_license( (int) $license['id'] );
		$this->assertNotNull( $new );
		$this->assertNotSame( $resource->id(), $new->id() );
		$this->assertSame( 'fake2', $new->get( 'driver' ) );
		$this->assertSame( Product::plan( $product->get_id() ), $new->get( 'plan' ) );
		$this->assertSame( 'ready', $new->get( 'state' ) );
		$this->assertCount( 1, $other->calls( 'provision' ) );
		$this->assertSame( array(), $other->calls( 'delete' ) );
	}

	public function test_switch_to_a_product_with_the_same_driver_updates_the_plan(): void {
		list( $license, $resource ) = $this->provisioned();
		$product                    = $this->service_product( 'fake', array(), array( 'slug' => 'bigger-service' ) );

		Licenses::update( (int) $license['id'], array( 'product_id' => $product->get_id() ) );
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertTrue( $fresh->is_live() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertCount( 1, $this->driver->calls( 'update_plan' ) );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
		$this->assertCount( 1, $this->driver->calls( 'provision' ) );
	}

	public function test_switch_to_another_driver_leaves_a_protected_service_and_alerts_once(): void {
		$other                      = $this->fake_driver( 'fake2' );
		list( $license, $resource ) = $this->provisioned();
		$resource->set( array( 'protected' => true ) );
		$product = $this->service_product( 'fake2', array(), array( 'slug' => 'other-service' ) );

		Licenses::update( (int) $license['id'], array( 'product_id' => $product->get_id() ) );
		Licenses::update( (int) $license['id'], array( 'max_activations' => 9 ) );
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertTrue( $fresh->is_live() );
		$this->assertSame( 'fake', $fresh->get( 'driver' ) );
		$this->assertFalse( $fresh->get( 'plan_dirty' ), 'The old plan stays.' );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
		$this->assertSame( array(), $other->calls() );
		$this->assertCount( 1, $this->mails, 'Alerted once per resource and product.' );
		$this->assertStringContainsString( 'Product changed to a product with another driver; decide on the license screen.', $this->mails[0]['message'] );
	}

	public function test_switch_to_another_driver_on_a_suspended_license_deletes_nothing(): void {
		$other                      = $this->fake_driver( 'fake2' );
		list( $license, $resource ) = $this->provisioned();
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		$this->run_jobs();

		$product = $this->service_product( 'fake2', array(), array( 'slug' => 'other-service' ) );
		Licenses::update( (int) $license['id'], array( 'product_id' => $product->get_id() ) );
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertTrue( $fresh->is_live() );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
		$this->assertSame( array(), $other->calls() );
		$this->assertCount( 1, $this->mails );
	}

	public function test_renewal_that_only_clears_the_expiry_resumes(): void {
		list( $license, $resource ) = $this->provisioned();

		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_SUSPENDED ) );
		Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_ACTIVE ) );
		$this->run_jobs();
		$this->assertSame( 'paused', $this->fresh( $resource )->get( 'state' ) );

		// A renewal as beltoft-licenses' Subscriptions::activated() does it on an active status.
		Licenses::update( (int) $license['id'], array( 'expires_at' => null ) );
		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'resume' ) );
	}

	public function test_expiry_moved_into_the_past_pauses(): void {
		list( $license, $resource ) = $this->provisioned();

		Licenses::update( (int) $license['id'], array( 'expires_at' => time() + DAY_IN_SECONDS ) );
		$this->assertSame( 'ready', $this->fresh( $resource )->get( 'desired' ), 'A future expiry changes nothing.' );

		Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		$this->run_jobs();

		$this->assertSame( 'paused', $this->fresh( $resource )->get( 'state' ) );
		$this->assertCount( 1, $this->driver->calls( 'pause' ) );
		$this->assertContains( 'License expired', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $license['id'] ), 'message' ) );
	}

	public function test_expiry_changes_while_the_lock_is_held_end_with_the_newest(): void {
		list( $license, $resource ) = $this->provisioned();

		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		Licenses::update( (int) $license['id'], array( 'expires_at' => time() - 60 ) );
		$this->assertSame( 'paused', $this->fresh( $resource )->pending_desire()['state'] );

		Licenses::update( (int) $license['id'], array( 'expires_at' => null ) );
		remove_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		$other->close();

		$this->assertSame( 'ready', $this->fresh( $resource )->pending_desire()['state'], 'The newer desire is queued.' );

		$this->run_jobs();

		$fresh = $this->fresh( $resource );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( array(), $this->driver->calls( 'pause' ) );
	}

	public function test_driver_conflict_alerts_again_after_the_driver_matched_again(): void {
		$this->fake_driver( 'fake2' );
		list( $license, $resource ) = $this->provisioned();
		$resource->set( array( 'protected' => true ) );
		$original = (int) $license['product_id'];
		$product  = $this->service_product( 'fake2', array(), array( 'slug' => 'other-service' ) );

		Licenses::update( (int) $license['id'], array( 'product_id' => $product->get_id() ) );
		$this->assertCount( 1, $this->mails );
		$this->assertNotFalse( get_option( \BeltoftServices\Lifecycle::CONFLICT_PREFIX . $resource->id() ) );

		Licenses::update( (int) $license['id'], array( 'product_id' => $original ) );
		$this->assertFalse( get_option( \BeltoftServices\Lifecycle::CONFLICT_PREFIX . $resource->id() ), 'Cleared once the driver matches.' );

		Licenses::update( (int) $license['id'], array( 'product_id' => $product->get_id() ) );
		$this->assertCount( 2, $this->mails, 'A later conflict alerts again.' );
	}
}

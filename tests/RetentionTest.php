<?php
/**
 * Retention: warning and deleting paused services after the product's retention period.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Retention;

/**
 * Retention tests.
 */
final class RetentionTest extends TestCase {

	/**
	 * Warnings fired: [resource id, deletes_at].
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	private $warnings = array();

	/**
	 * The fake driver.
	 *
	 * @var \BeltoftServices\Tests\Support\FakeDriver
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

		$this->warnings = array();
		add_action( 'beltoft_services_deletion_warning', array( $this, 'catch_warning' ), 10, 2 );
		add_filter( 'pre_wp_mail', '__return_true' );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		as_unschedule_all_actions( Retention::HOOK, array(), Jobs::GROUP );

		$this->driver  = $this->fake_driver();
		$this->product = $this->service_product( 'fake', array(), array( 'retention' => 30 ) );

		$this->settings( array( 'warning_days' => 1 ) );
	}

	/**
	 * Records a deletion warning.
	 *
	 * @param Resource $r          Resource.
	 * @param int      $deletes_at When it will be deleted.
	 */
	public function catch_warning( $r, $deletes_at ): void {
		$this->warnings[] = array( $r->id(), $deletes_at );
	}

	/**
	 * A paused resource of an expired license.
	 *
	 * @param float                $days_ago Days since it was paused.
	 * @param array<string, mixed> $meta     More meta.
	 * @param \WC_Product|null     $product  Product (default: 30 days retention).
	 */
	private function paused( float $days_ago, array $meta = array(), ?\WC_Product $product = null ): Resource {
		$license  = $this->service_license( $product ? $product : $this->product, array( 'fire' => false ) );
		$resource = Resource::create(
			$license,
			$this->driver,
			array(),
			array_merge(
				array(
					'state'     => 'paused',
					'desired'   => 'paused',
					'steps'     => array( 'created' ),
					'paused_at' => time() - (int) round( $days_ago * DAY_IN_SECONDS ),
				),
				$meta
			)
		);

		$this->assertInstanceOf( Resource::class, $resource );

		// The license ends (the listener keeps the pause and its date).
		Licenses::update( (int) $license['id'], array( 'status' => Licenses::STATUS_EXPIRED ) );
		$this->assertSame( $meta['paused_at'] ?? $resource->get( 'paused_at' ), Resource::find( $resource->id() )->get( 'paused_at' ) );

		return $resource;
	}

	/**
	 * History messages of a resource's license.
	 *
	 * @param Resource $r Resource.
	 * @return string[]
	 */
	private function history( Resource $r ): array {
		return wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	/**
	 * History messages starting with a prefix.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $prefix Prefix.
	 * @return string[]
	 */
	private function history_with( Resource $r, string $prefix ): array {
		return array_values( array_filter( $this->history( $r ), static fn( $m ) => 0 === strpos( $m, $prefix ) ) );
	}

	/**
	 * The resource as stored now.
	 *
	 * @param Resource $r Resource.
	 */
	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	public function test_retention_off_report_on(): void {
		$due      = $this->paused( 31, array( 'warned_at' => time() - 2 * DAY_IN_SECONDS ) );
		$warn     = $this->paused( 29.5 );
		$early    = $this->paused( 10 );
		$unwarned = $this->paused( 40 );

		// Off (the default): nothing at all.
		Retention::run();

		foreach ( array( $due, $warn, $early, $unwarned ) as $r ) {
			$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ) );
			$this->assertSame( array(), $this->history_with( $r, 'Would' ) );
		}
		$this->assertSame( array(), $this->warnings );

		// Report: only history, once per stage.
		$this->settings( array( 'deletion_mode' => 'report' ) );
		Retention::run();
		Retention::run();

		$this->assertCount( 1, $this->history_with( $due, 'Would delete' ) );
		$this->assertCount( 1, $this->history_with( $warn, 'Would warn' ) );
		$this->assertSame( array(), $this->history_with( $warn, 'Would delete' ) );
		$this->assertSame( array(), $this->history_with( $early, 'Would' ) );
		$this->assertSame( array(), $this->warnings );
		foreach ( array( $due, $warn, $early, $unwarned ) as $r ) {
			$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ) );
		}
		$this->assertNull( $this->fresh( $warn )->get( 'warned_at' ) );

		// On: warn once inside the warning window, delete once due and warned.
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$before = time();
		Retention::run();
		Retention::run();

		$this->assertSame( 'deleted', $this->fresh( $due )->get( 'desired' ) );
		$this->assertTrue( $this->fresh( $due )->get( 'replace_after_delete' ), 'A renewal during the deletion gets a fresh service.' );

		$warned = array_column( $this->warnings, 1, 0 );
		$this->assertCount( 2, $this->warnings, 'One warning each, never repeated.' );
		// Never less notice than warning_days: the daily run may warn later than the window opens.
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $warned[ $warn->id() ], 2 );
		$this->assertGreaterThanOrEqual( (int) $this->fresh( $warn )->get( 'paused_at' ) + 30 * DAY_IN_SECONDS, $warned[ $warn->id() ] );
		$this->assertGreaterThanOrEqual( $before, (int) $this->fresh( $warn )->get( 'warned_at' ) );
		$this->assertSame( 'paused', $this->fresh( $warn )->get( 'desired' ) );

		// Past its date but never warned: warned now, deleted warning_days later, not today.
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $warned[ $unwarned->id() ], 2 );
		$this->assertSame( 'paused', $this->fresh( $unwarned )->get( 'desired' ) );

		$this->assertSame( 'paused', $this->fresh( $early )->get( 'desired' ) );
		$this->assertArrayNotHasKey( $early->id(), $warned );

		$this->run_jobs();

		$this->assertSame( 'deleted', $this->fresh( $due )->get( 'state' ) );
		$this->assertFalse( $this->fresh( $due )->is_live() );
		$this->assertCount( 1, $this->driver->calls( 'delete' ) );
		$this->assertNull( Resource::for_license( (int) $due->license()['id'] ), 'No replacement on an expired license.' );

		// A day after its warning, the unwarned one goes too.
		$this->fresh( $unwarned )->set( array( 'warned_at' => time() - DAY_IN_SECONDS - 1 ) );
		Retention::run();
		$this->assertSame( 'deleted', $this->fresh( $unwarned )->get( 'desired' ) );
	}

	public function test_no_warning_days_deletes_on_the_date(): void {
		$this->settings(
			array(
				'deletion_mode' => 'on',
				'warning_days'  => 0,
			)
		);
		$due = $this->paused( 30.1 );

		Retention::run();

		$this->assertSame( 'deleted', $this->fresh( $due )->get( 'desired' ) );
		$this->assertSame( array(), $this->warnings );
	}

	public function test_protected_is_never_deleted(): void {
		$protected = $this->paused(
			100,
			array(
				'protected' => true,
				'warned_at' => time() - 50 * DAY_IN_SECONDS,
			)
		);

		$this->settings( array( 'deletion_mode' => 'report' ) );
		Retention::run();
		$this->settings( array( 'deletion_mode' => 'on' ) );
		Retention::run();
		$this->run_jobs();

		$fresh = $this->fresh( $protected );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertSame( array(), $this->history_with( $protected, 'Would' ) );
		$this->assertSame( array(), $this->warnings );
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
	}

	public function test_no_retention_or_active_license_is_never_deleted(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );

		$forever = $this->paused( 400, array( 'warned_at' => time() - 300 * DAY_IN_SECONDS ), $this->service_product( 'fake', array(), array( 'retention' => 0 ) ) );
		$active  = $this->paused( 400, array( 'warned_at' => time() - 300 * DAY_IN_SECONDS ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- active again, with no listener run (e.g. a desire still waiting for its lock).
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'status' => 'active' ), array( 'id' => (int) $active->license()['id'] ) );

		$ready = $this->paused( 400, array( 'warned_at' => time() - 300 * DAY_IN_SECONDS ) );
		$ready->desire( 'ready', 'License active' );

		Retention::run();

		$this->assertSame( 'paused', $this->fresh( $forever )->get( 'desired' ) );
		$this->assertSame( 'paused', $this->fresh( $active )->get( 'desired' ) );
		$this->assertSame( 'ready', $this->fresh( $ready )->get( 'desired' ) );
		$this->assertSame( array(), $this->warnings );
	}

	public function test_warning_from_an_earlier_pause_does_not_count(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );

		// Warned during an earlier pause (before this paused_at), then paused again.
		$r = $this->paused( 31, array( 'warned_at' => time() - 40 * DAY_IN_SECONDS ) );

		Retention::run();

		$this->assertSame( 'paused', $this->fresh( $r )->get( 'desired' ), 'Warned again first.' );
		$this->assertCount( 1, $this->warnings );
	}

	public function test_a_failing_warning_listener_does_not_stop_the_run(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$first  = $this->paused( 29.5 );
		$second = $this->paused( 29.5 );

		add_action(
			'beltoft_services_deletion_warning',
			static function ( $r ) use ( $first ) {
				if ( $r->id() === $first->id() ) {
					throw new \RuntimeException( 'Mail server down' );
				}
			},
			5
		);

		Retention::run();

		$this->assertNotNull( $this->fresh( $first )->get( 'warned_at' ), 'Warned once; never repeated.' );
		$this->assertNotNull( $this->fresh( $second )->get( 'warned_at' ) );
		$this->assertCount( 1, $this->warnings );
	}

	public function test_medusa_warning_job_cannot_loop(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );

		$ids = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$ids[] = $this->paused( 29.5, array( 'warned_at' => time() - HOUR_IN_SECONDS ) )->id();
		}

		$table   = \BeltoftLicenses\Install::tables()['activations'];
		$batches = 0;
		$kinds   = array();
		$count   = static function ( $query ) use ( $table, &$batches, &$kinds ) {
			if ( 0 === strpos( ltrim( $query ), 'SELECT id FROM `' . $table . '`' ) && false !== strpos( $query, 'LIMIT' ) && preg_match( "/kind = '([^']+)'/", $query, $kind ) ) {
				$kinds[ $kind[1] ] = ( $kinds[ $kind[1] ] ?? 0 ) + 1;
			}
			// Batches of this test's kind; every other registered kind (e.g. http) is read once.
			if ( 0 === strpos( ltrim( $query ), 'SELECT id FROM `' . $table . '`' ) && false !== strpos( $query, 'LIMIT' ) && false !== strpos( $query, "kind = 'fake'" ) ) {
				++$batches;
				if ( $batches > 10 ) {
					throw new \RuntimeException( 'The retention job loops.' );
				}
			}
			return $query;
		};

		add_filter( 'query', $count );
		Retention::run();
		remove_filter( 'query', $count );

		$this->assertSame( 2, $batches, 'Two batches: 50, then 10.' );
		foreach ( $kinds as $kind => $queries ) {
			$this->assertSame( 'fake' === $kind ? 2 : 1, $queries, sprintf( 'Batches of kind %s.', $kind ) );
		}
		$this->assertSame( array(), $this->warnings, 'Already warned: no warning again.' );
		foreach ( $ids as $id ) {
			$this->assertSame( 'paused', Resource::find( $id )->get( 'desired' ) );
		}
	}

	public function test_scheduled_daily_once_and_removed_on_deactivation(): void {
		$this->assertNotFalse( has_action( Retention::HOOK, array( Retention::class, 'run' ) ) );
		$this->assertNotFalse( has_action( 'action_scheduler_init', array( Retention::class, 'schedule' ) ) );

		Retention::schedule();
		Retention::schedule();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Retention::HOOK,
				'group'  => Jobs::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);

		$this->assertCount( 1, $actions );
		$schedule = reset( $actions )->get_schedule();
		$this->assertInstanceOf( \ActionScheduler_CronSchedule::class, $schedule );
		$this->assertSame( '30 3 * * *', $schedule->get_recurrence() );

		Retention::unschedule();
		$this->assertFalse( as_has_scheduled_action( Retention::HOOK, array(), Jobs::GROUP ) );
	}

	public function test_renewal_between_check_and_deletion_wins(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$due = $this->paused( 31, array( 'warned_at' => time() - 2 * DAY_IN_SECONDS ) );
		$this->run_jobs();

		// The renewal lands after retention read the resource and the license (it reads the
		// settings a second time for warning_days), before it asks for the deletion.
		$reads   = 0;
		$renewed = false;
		$renew   = function ( $value ) use ( $due, &$reads, &$renewed ) {
			if ( ! $renewed && 2 === ++$reads ) {
				$renewed = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the renewal of another request.
				$GLOBALS['wpdb']->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'status' => 'active' ), array( 'id' => (int) $due->license()['id'] ) );
				Resource::find( $due->id() )->desire( 'ready', 'License active' );
			}
			return $value;
		};

		add_filter( 'option_' . \BeltoftServices\Settings::OPTION, $renew );
		Retention::run();
		remove_filter( 'option_' . \BeltoftServices\Settings::OPTION, $renew );

		$this->assertTrue( $renewed );
		$fresh = $this->fresh( $due );
		$this->assertSame( 'ready', $fresh->get( 'desired' ), 'The renewal wins.' );
		$this->assertNull( $fresh->pending_desire() );

		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'delete' ) );
	}

	public function test_busy_lock_skips_the_deletion_until_the_next_run(): void {
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$due = $this->paused( 31, array( 'warned_at' => time() - 2 * DAY_IN_SECONDS ) );
		$this->run_jobs();

		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $due->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		Retention::run();
		remove_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		$other->close();

		$fresh = $this->fresh( $due );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertNull( $fresh->pending_desire(), 'Never queued.' );
		$this->assertSame( array(), Jobs::pending( $due->id() ) );

		// The next run deletes it.
		Retention::run();
		$this->assertSame( 'deleted', $this->fresh( $due )->get( 'desired' ) );
	}
}

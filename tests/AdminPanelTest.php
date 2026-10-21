<?php
/**
 * The admin license panel, its actions and WooCommerce → Services.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Events;
use BeltoftServices\Admin\ListTable;
use BeltoftServices\Admin\Panel;
use BeltoftServices\Admin\Screen;
use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Admin panel and list tests.
 */
final class AdminPanelTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		delete_option( Alerts::PROBLEM_OPTION );

		parent::tear_down();
	}

	/**
	 * A resource of a fake-driver service product.
	 *
	 * @param array<string, mixed> $meta   Initial meta.
	 * @param string               $driver Driver id.
	 * @return array{0: Resource, 1: FakeDriver}
	 */
	private function resource( array $meta = array(), string $driver = 'fake' ): array {
		$fake     = $this->fake_driver( $driver );
		$product  = $this->service_product( $driver, array(), array( 'slug' => 'svc-' . $driver ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, $fake, array( 'ram' => 2 ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return array( $resource, $fake );
	}

	/**
	 * Logs in an administrator named "boss".
	 */
	private function admin(): int {
		$id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'boss',
			)
		);
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * Posts an action to the admin-post handler; returns the redirect target.
	 *
	 * @param array<string, mixed> $post  Posted values.
	 * @param bool                 $nonce Add a valid nonce.
	 */
	private function post( array $post, bool $nonce = true ): string {
		if ( $nonce ) {
			$post['_wpnonce'] = wp_create_nonce( Panel::nonce_action( (int) $post['resource'], (string) $post['do'] ) );
		}

		$_POST    = wp_slash( $post );
		$_REQUEST = $_POST;
		$target   = '';

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		);

		try {
			Panel::handle();
		} catch ( \RuntimeException $e ) {
			$target = $e->getMessage();
		} finally {
			remove_all_filters( 'wp_redirect' );
		}

		return $target;
	}

	/**
	 * The newest history message of the resource's license.
	 *
	 * @param Resource $r Resource.
	 */
	private function last_event( Resource $r ): string {
		$events = Events::for_license( (int) $r->license()['id'], 1 );

		return $events ? (string) $events[0]['message'] : '';
	}

	public function test_admin_actions_need_capability_and_nonce(): void {
		list( $resource ) = $this->resource( array( 'state' => 'ready' ) );

		// No capability: 403, nothing changes.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		try {
			$this->post(
				array(
					'resource' => $resource->id(),
					'do'       => 'pause',
				)
			);
			$this->fail( 'A user without manage_woocommerce must be stopped.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'desired' ) );
		}

		// No nonce: stopped, nothing changes.
		$this->admin();
		try {
			$this->post(
				array(
					'resource' => $resource->id(),
					'do'       => 'pause',
				),
				false
			);
			$this->fail( 'A request without a nonce must die.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'desired' ) );
		}

		// A nonce for another action does not do.
		$_POST    = array(
			'resource' => $resource->id(),
			'do'       => 'delete',
			'confirm'  => 'DELETE',
			'_wpnonce' => wp_create_nonce( Panel::nonce_action( $resource->id(), 'pause' ) ),
		);
		$_REQUEST = $_POST;
		try {
			Panel::handle();
			$this->fail( 'A nonce for another action must not pass.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'desired' ) );
		}

		// Capability and nonce: done, back to the license screen, history names the admin.
		$target = $this->post(
			array(
				'resource' => $resource->id(),
				'do'       => 'pause',
			)
		);

		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'desired' ) );
		$this->assertStringContainsString( 'license=' . (int) $resource->license()['id'], $target );
		$this->assertStringContainsString( 'beltoft_services_notice=pause', $target );
		$this->assertStringContainsString( 'boss', $this->last_event( $resource ) );
		$this->assertNotEmpty( Jobs::pending( $resource->id() ), 'The resource is queued.' );
	}

	public function test_pause_resume_protect_and_retry(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$this->admin();

		$this->assertTrue( Panel::act( $resource, 'pause' ) );
		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'desired' ) );

		$this->assertTrue( Panel::act( Resource::find( $resource->id() ), 'resume' ) );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'desired' ) );
		$this->assertStringContainsString( 'boss', $this->last_event( $resource ) );

		$this->assertTrue( Panel::act( Resource::find( $resource->id() ), 'protect' ) );
		$this->assertTrue( Resource::find( $resource->id() )->get( 'protected' ) );
		$this->assertStringContainsString( 'boss', $this->last_event( $resource ) );
		$this->assertTrue( Panel::act( Resource::find( $resource->id() ), 'unprotect' ) );
		$this->assertFalse( Resource::find( $resource->id() )->get( 'protected' ) );

		// A failed provision waits for an admin; Retry now resets the count and runs it again.
		$failed = Resource::find( $resource->id() );
		$failed->set(
			array(
				'state'          => 'failed',
				'op'             => 'provision',
				'attempts'       => 6,
				'failed_op'      => 'provision',
				'failed_desired' => 'ready',
				'last_error'     => 'Host unreachable',
			)
		);
		$this->assertNull( \BeltoftServices\Reconciler::operation( Resource::find( $resource->id() ) ) );

		$this->assertTrue( Panel::act( Resource::find( $resource->id() ), 'retry' ) );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 0, $fresh->get( 'attempts' ) );
		$this->assertSame( 'provision', \BeltoftServices\Reconciler::operation( $fresh ) );
		$this->assertStringContainsString( 'boss', $this->last_event( $resource ) );

		$this->run_jobs();
		$this->assertCount( 1, $driver->calls( 'provision' ), 'The failed operation runs again.' );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );

		$this->assertWPError( Panel::act( Resource::find( $resource->id() ), 'explode' ) );
	}

	public function test_delete_needs_typed_confirmation(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'paused' ) );
		$this->admin();

		foreach ( array( '', 'delete', 'DELETE ', 'yes' ) as $typed ) {
			$result = Panel::act( Resource::find( $resource->id() ), 'delete', $typed );
			$this->assertWPError( $result, 'Typed: "' . $typed . '"' );
			$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'desired' ) );
		}

		// Through the handler too: refused, with the error on the way back.
		$target = $this->post(
			array(
				'resource' => $resource->id(),
				'do'       => 'delete',
				'confirm'  => 'nope',
			)
		);
		$this->assertStringContainsString( 'beltoft_services_error=', $target );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'desired' ) );

		// A protected service is not deleted, even when confirmed.
		Panel::act( Resource::find( $resource->id() ), 'protect' );
		$this->assertWPError( Panel::act( Resource::find( $resource->id() ), 'delete', 'DELETE' ) );
		Panel::act( Resource::find( $resource->id() ), 'unprotect' );

		$this->assertTrue( Panel::act( Resource::find( $resource->id() ), 'delete', 'DELETE' ) );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'deleted', $fresh->get( 'desired' ) );
		$this->assertFalse( $fresh->get( 'replace_after_delete' ), 'An admin deletion is not replaced.' );
		$this->assertStringContainsString( 'boss', $this->last_event( $resource ) );

		$this->run_jobs();
		$this->assertCount( 1, $driver->calls( 'delete' ) );
		$this->assertFalse( Resource::find( $resource->id() )->is_live() );
	}

	public function test_mark_deleted_makes_no_driver_call(): void {
		// A stranded delete: the service is gone, the driver keeps failing to delete it.
		list( $resource, $driver ) = $this->resource(
			array(
				'state'          => 'failed',
				'desired'        => 'deleted',
				'op'             => 'delete',
				'attempts'       => 6,
				'failed_op'      => 'delete',
				'failed_desired' => 'deleted',
				'last_error'     => 'No delete endpoint is configured',
			)
		);
		$this->admin();

		$changes = array();
		add_action(
			'beltoft_services_state_changed',
			static function ( $r, $old, $new ) use ( &$changes ) {
				$changes[] = array( $old, $new, $r->is_live() );
			},
			10,
			3
		);

		$target = $this->post(
			array(
				'resource' => $resource->id(),
				'do'       => 'mark_deleted',
			)
		);
		$this->assertStringContainsString( 'beltoft_services_notice=mark_deleted', $target );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertSame( 'deleted', $fresh->get( 'desired' ) );
		$this->assertFalse( $fresh->is_live(), 'The resource is closed.' );
		$this->assertSame( array( array( 'failed', 'deleted', false ) ), $changes, 'Listeners see a closed resource.' );
		$this->assertStringContainsString( 'boss', $this->last_event( $resource ) );
		$this->assertNull( Resource::for_license( (int) $resource->license()['id'] ) );

		Jobs::enqueue( $resource->id() );
		$this->run_jobs();
		$this->assertSame( array(), $driver->calls(), 'No driver call at all.' );

		// A closed resource cannot be acted on again.
		$this->assertWPError( Panel::act( Resource::find( $resource->id() ), 'retry' ) );
	}

	public function test_actions_report_a_busy_resource(): void {
		list( $resource ) = $this->resource( array( 'state' => 'ready' ) );
		$this->admin();
		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );

		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0], 'The second connection holds the lock.' );

		try {
			$result = Panel::act( $resource, 'protect' );
			$this->assertWPError( $result );
			$this->assertFalse( Resource::find( $resource->id() )->get( 'protected' ) );
			$this->assertWPError( Panel::act( $resource, 'mark_deleted' ) );
			$this->assertTrue( Resource::find( $resource->id() )->is_live() );
		} finally {
			$other->close();
		}
	}

	public function test_panel_renders_state_problem_driver_part_and_forms(): void {
		list( $resource ) = $this->resource(
			array(
				'state'      => 'failed',
				'failed_op'  => 'provision',
				'attempts'   => 6,
				'last_error' => 'Host <b>unreachable</b>',
			)
		);
		$this->admin();
		update_option(
			Alerts::PROBLEM_OPTION,
			array(
				$resource->id() => array(
					'time'     => time(),
					'resource' => $resource->id(),
					'message'  => 'A service failure alert could not be sent: the alert address is not a valid email address.',
				),
			)
		);

		ob_start();
		do_action( 'beltoft_licenses_admin_license', $resource->license() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'fake-admin', $html );
		$this->assertStringContainsString( 'Host &lt;b&gt;unreachable&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'alert could not be sent', $html );
		foreach ( array( 'retry', 'pause', 'delete', 'mark_deleted', 'protect' ) as $do ) {
			$this->assertStringContainsString( 'value="' . $do . '"', $html );
		}
		$this->assertStringContainsString( 'name="confirm"', $html );

		// Nothing for a license that is not a service.
		ob_start();
		do_action( 'beltoft_licenses_admin_license', array( 'id' => 999999, 'product_id' => 0, 'product_slug' => 'plain-plugin' ) );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_site_wide_notice_when_an_alert_could_not_be_sent(): void {
		list( $resource ) = $this->resource();
		$this->admin();

		ob_start();
		Panel::notices();
		$this->assertStringNotContainsString( 'could not be sent', (string) ob_get_clean() );

		update_option(
			Alerts::PROBLEM_OPTION,
			array(
				$resource->id() => array(
					'time'     => time(),
					'resource' => $resource->id(),
					'message'  => 'A service failure alert could not be sent: the alert address is not a valid email address.',
				),
			)
		);

		ob_start();
		Panel::notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'could not be sent', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Panel::notices();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_list_table_filters(): void {
		list( $ready ) = $this->resource( array( 'state' => 'ready' ) );
		list( $failed ) = $this->resource(
			array(
				'state'      => 'failed',
				'failed_op'  => 'provision',
				'attempts'   => 6,
				'last_error' => 'Boom',
			)
		);
		list( $other ) = $this->resource( array( 'state' => 'paused' ), 'other' );
		list( $closed ) = $this->resource( array( 'state' => 'deleted' ) );
		$closed->close();

		$ids = static fn( array $resources ) => array_map( static fn( Resource $r ) => $r->id(), $resources );

		$all = $ids( Screen::resources( array() ) );
		$this->assertEqualsCanonicalizing( array( $ready->id(), $failed->id(), $other->id() ), $all, 'Live resources of every registered kind.' );

		$this->assertSame( array( $other->id() ), $ids( Screen::resources( array( 'driver' => 'other' ) ) ) );
		$this->assertEqualsCanonicalizing( array( $ready->id(), $failed->id() ), $ids( Screen::resources( array( 'driver' => 'fake' ) ) ) );
		$this->assertSame( array( $ready->id() ), $ids( Screen::resources( array( 'state' => 'ready' ) ) ) );
		$this->assertSame( array( $failed->id() ), $ids( Screen::resources( array( 'failed' => true ) ) ) );
		$this->assertSame( array(), $ids( Screen::resources( array( 'driver' => 'other', 'failed' => true ) ) ) );

		// Filters from the request are cleaned.
		$_GET = array(
			'driver' => 'other',
			'state'  => 'nonsense',
			'failed' => '1',
		);
		$this->assertSame(
			array(
				'driver' => 'other',
				'state'  => '',
				'failed' => true,
			),
			Screen::filters()
		);

		// The table links each row to its license screen.
		$this->admin();
		set_current_screen( 'woocommerce_page_' . Screen::PAGE );
		$_GET  = array();
		$table = new ListTable();
		$table->prepare_items();
		ob_start();
		$table->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( esc_url( \BeltoftLicenses\Admin\Screen::url( array( 'license' => (int) $failed->license()['id'] ) ) ), $html );
		$this->assertStringContainsString( 'Boom', $html );
	}
}

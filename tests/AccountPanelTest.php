<?php
/**
 * The My Account service panel.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Panel;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Panel tests.
 */
final class AccountPanelTest extends TestCase {

	/**
	 * A fake-driver resource owned by a new customer, who is logged in.
	 *
	 * @param array<string, mixed> $meta    Resource meta.
	 * @param array<string, mixed> $license License columns.
	 * @return array{0: Resource, 1: FakeDriver}
	 */
	private function resource( array $meta = array( 'state' => 'ready' ), array $license = array() ): array {
		$driver = $this->fake_driver();

		$driver->customer_actions = array( 'restart' => 'Restart now' );

		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$row      = $this->service_license( $product, array_merge( array( 'fire' => false ), $license ) );
		$resource = Resource::create( $row, $driver, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		if ( ! empty( $row['user_id'] ) ) {
			wp_set_current_user( (int) $row['user_id'] );
		}

		return array( $resource, $driver );
	}

	/**
	 * The panel's HTML for a license, as the licenses template prints it.
	 *
	 * @param array<string, mixed> $license License.
	 */
	private function render( array $license ): string {
		ob_start();
		do_action( 'beltoft_licenses_account_license', $license, View::license( $license ) );
		return (string) ob_get_clean();
	}

	public function test_panel_renders_state_and_driver_part(): void {
		list( $resource, $driver ) = $this->resource();

		$html = $this->render( $resource->license() );

		$this->assertStringContainsString( 'beltoft-services-panel', $html );
		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringContainsString( 'fake-account', $html );
		$this->assertStringContainsString( 'data-action="restart"', $html );
		$this->assertStringContainsString( 'Restart now', $html );
		$this->assertStringContainsString( 'data-resource="' . $resource->id() . '"', $html );
		$this->assertStringContainsString( 'data-state="ready"', $html );
		$this->assertStringContainsString( 'data-desired="ready"', $html );
		$this->assertStringNotContainsString( 'data-idle', $html, 'A ready service is asked for its status on load.' );
		$this->assertSame( array(), $driver->calls(), 'Rendering calls no driver operation.' );
	}

	public function test_a_paused_service_is_not_polled_on_load(): void {
		list( $resource ) = $this->resource();
		$resource->set( array( 'state' => 'paused', 'desired' => 'paused' ) );

		$html = $this->render( $resource->license() );

		$this->assertStringContainsString( 'data-resource="' . $resource->id() . '"', $html );
		$this->assertStringContainsString( 'data-idle="1"', $html );
	}

	public function test_badges_by_state(): void {
		list( $resource ) = $this->resource( array( 'state' => 'provisioning' ) );

		$this->assertStringContainsString( 'Getting ready', $this->render( $resource->license() ) );
		$this->assertStringNotContainsString( 'data-action=', $this->render( $resource->license() ), 'No actions until ready.' );

		$resource->set( array( 'state' => 'paused', 'desired' => 'paused' ) );
		$html = $this->render( $resource->license() );
		$this->assertStringContainsString( 'Paused', $html );
		$this->assertStringNotContainsString( 'data-action=', $html );

		$resource->set( array( 'state' => 'failed' ) );
		$this->assertStringContainsString( 'Failed: contact us', $this->render( $resource->license() ) );

		$this->assertSame( 'Getting ready', Panel::label( 'requested' ) );
		$this->assertSame( 'Getting ready', Panel::label( 'provisioning' ) );
	}

	public function test_a_removed_service_says_so_and_unknown_states_read_as_failed(): void {
		foreach ( array( 'deleting', 'deleted' ) as $state ) {
			$this->assertSame( 'Removed', Panel::label( $state ), $state );
			$this->assertSame( 'This service has been removed.', Panel::message( $state ), $state );
			$this->assertStringNotContainsStringIgnoringCase( 'paused', Panel::message( $state ) );
		}

		foreach ( array( '', 'something-new', 'invalid' ) as $state ) {
			$this->assertSame( 'failed', Panel::group( $state ), 'State "' . $state . '" is not "Getting ready".' );
			$this->assertSame( 'Failed: contact us', Panel::label( $state ) );
		}

		// A row without its metadata reads as failed too.
		list( $resource ) = $this->resource();
		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['activations'], array( 'meta' => '{}' ), array( 'id' => $resource->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- corrupt the row on purpose.
		$html = $this->render( $resource->license() );
		$this->assertStringContainsString( 'Failed: contact us', $html );
		$this->assertStringNotContainsString( 'Getting ready', $html );
	}

	public function test_the_script_does_not_leave_a_queued_action_disabled_forever(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.js' );

		$this->assertStringContainsString( 'settle( panel, 5 )', $js, 'After a 202 it looks a few times.' );
		$this->assertMatchesRegularExpression( '/rounds < 1 \) \{\s+window\.location\.reload\(\)/', $js, 'Then it reloads, which gives the button back.' );
	}

	public function test_a_failed_state_never_shows_the_last_error(): void {
		list( $resource ) = $this->resource( array( 'state' => 'failed', 'last_error' => 'POST /v1/tenants: HTTP 500: internal stack trace secret-host.internal' ) );

		$html = $this->render( $resource->license() );

		$this->assertStringContainsString( 'Failed: contact us', $html );
		$this->assertStringNotContainsString( 'secret-host', $html );
		$this->assertStringNotContainsString( 'HTTP 500', $html );
		$this->assertStringNotContainsString( 'stack trace', $html );
	}

	public function test_a_license_owned_by_another_logged_in_user_shows_no_controls(): void {
		list( $resource ) = $this->resource();
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'someone-else@example.com' ) ) );

		$html = $this->render( $resource->license() );

		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringNotContainsString( 'data-resource=', $html );
		$this->assertStringNotContainsString( 'data-action=', $html );
		$this->assertStringNotContainsString( 'Restart now', $html );
		$this->assertStringNotContainsString( 'fake-account', $html );
		$this->assertStringNotContainsString( 'data-notice', $html );
	}

	public function test_nothing_for_licenses_that_are_not_services(): void {
		$product = $this->service_product( '' );
		$license = $this->service_license( $product, array( 'fire' => false ) );

		$this->assertSame( '', $this->render( $license ) );
	}

	public function test_guest_license_shows_the_state_without_controls(): void {
		list( $resource ) = $this->resource( array( 'state' => 'ready' ), array( 'user_id' => null ) );
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'customer@example.com' ) ) );

		$html = $this->render( $resource->license() );

		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringNotContainsString( 'data-resource=', $html );
		$this->assertStringNotContainsString( 'data-action=', $html );
		$this->assertStringNotContainsString( 'fake-account', $html );
	}

	public function test_assets_only_on_the_licenses_endpoint(): void {
		Panel::assets();
		$this->assertFalse( wp_script_is( 'beltoft-services-account', 'enqueued' ) );

		global $wp;
		$wp->query_vars[ \BeltoftLicenses\Account\Endpoint::slug() ] = '';

		Panel::assets();

		$this->assertTrue( wp_script_is( 'beltoft-services-account', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'beltoft-services-account', 'enqueued' ) );

		$data = (string) wp_scripts()->get_data( 'beltoft-services-account', 'data' );
		$this->assertStringContainsString( wp_create_nonce( 'wp_rest' ), $data );
		$this->assertStringContainsString( 'beltoft-services/v1/resources/', $data );
		$this->assertStringContainsString( 'admin-ajax.php', $data, 'Where a fresh REST nonce comes from (core action rest-nonce).' );
		$this->assertStringContainsString( 'Your session has expired. Please reload the page.', $data );

		unset( $wp->query_vars[ \BeltoftLicenses\Account\Endpoint::slug() ] );
		wp_dequeue_script( 'beltoft-services-account' );
		wp_dequeue_style( 'beltoft-services-account' );
	}
}

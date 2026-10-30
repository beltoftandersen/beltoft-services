<?php
/** The licence of a paid app: its link to the server, the install job, the tier switch, the events and the end. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\AppInstall;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\AppTab;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Resource;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * Documentation values only: FakeClient, a made-up vendor, no network.
 */
final class AppLicenceTest extends PaidAppCase {

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	/**
	 * A server with a docker tier, the licence provisioned (ready and linked).
	 *
	 * @return array{server: Resource, licence: Resource, user: int, order: \WC_Order, tiers: array<string, \WC_Product_Variation>}
	 */
	private function with_licence( string $tier = 'basic', array $extra = array() ): array {
		$tiers = $this->tier_product( array( 'basic' => '5', 'pro' => '12' ), $extra );
		$o     = $this->ordered( $tiers[ $tier ], $tier );
		Jobs::run( $o['licence']->id() );
		$o['licence'] = $this->fresh( $o['licence'] );
		$o['tiers']   = $tiers;

		return $o;
	}

	private function pane( Resource $server ): string {
		wp_set_current_user( (int) $server->license()['user_id'] );
		ob_start();
		AppTab::pane( AppTab::SLUG, $server->id() );

		return (string) ob_get_clean();
	}

	// -- The licence is linked to the server -------------------------------------------------------

	public function test_the_tier_line_becomes_a_license_linked_to_the_server_of_the_order(): void {
		$o = $this->with_licence( 'pro' );

		$this->assertSame( 'docker', $o['server']->get( 'app_id' ), 'The server carries the app from its order line.' );
		$this->assertSame( 'pro', $o['server']->get( 'app_tier' ) );
		$this->assertSame( 'ready', $o['licence']->get( 'state' ) );
		$this->assertSame( (int) $o['server']->license()['id'], (int) $o['licence']->get( 'server_license' ) );
		$this->assertSame( 'applic', $o['licence']->get( 'kind' ) ?? 'applic' );
		$this->assertSame( 'proxmox-app', $o['licence']->get( 'driver' ) );
		$this->assertSame( 'pro', ( (array) $o['licence']->get( 'plan' ) )['tier'], 'The tier is on the licence plan.' );
		$this->assertSame( $o['licence']->id(), AppLicence::current( $o['server'] )->id() );
		$this->assertSame( 'active', AppLicence::state( $o['licence'] ) );
		$this->assertSame( $o['server']->id(), AppLicence::server_of( $o['licence'] )->id() );
	}

	public function test_a_server_built_without_the_app_does_not_get_the_licence_and_the_subscription_is_cancelled(): void {
		$tiers = $this->tier_product();
		$o     = $this->ordered( $tiers['basic'], 'basic' );
		$this->assertTrue( $o['server']->set( array( 'app_id' => '' ) ) );
		Jobs::run( $o['licence']->id() );

		$licence = $this->fresh( $o['licence'] );
		$this->assertSame( 'failed', $licence->get( 'state' ) );
		$this->assertStringContainsString( 'built without this app', (string) $licence->get( 'last_error' ) );
		$subscription = wcs_get_subscription( (int) $licence->license()['subscription_id'] );
		$this->assertSame( 'cancelled', $subscription->get_status() );
	}

	// -- The install job ----------------------------------------------------------------------------

	public function test_the_install_job_carries_the_tier_and_the_tiers_limits(): void {
		add_filter( 'beltoft_services_app_licence_plan_fields', static fn( $f ) => $f + array( 'domains' => array( 'number', 'Domains', '', 1, true ) ) );
		$o = $this->with_licence( 'pro', array( 'pro' => array( 'domains' => '10' ) ) );

		$result = AppInstall::queue( $o['server'], $this->fake );
		$this->assertTrue( $result->is_done(), $result->error() );
		$job = json_decode( $this->fake->guest_file( 5000, Apps::JOB ), true );

		$this->assertSame( 'docker', $job['app'] );
		$this->assertSame( 'pro', $job['tier'] );
		$this->assertSame( array( 'domains' => 10 ), $job['licence'], 'The tier\'s other plan fields, for the recipe.' );
		$this->assertSame( 'pro', $this->fresh( $o['server'] )->get( 'app_tier' ) );
	}

	public function test_a_free_apps_install_job_is_unchanged(): void {
		$o = $this->ordered( null );
		$this->assertTrue( $o['server']->set( array( 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_tier' => null ) ) );
		$this->assertFalse( AppLicence::paid( 'wordpress' ) );
		$this->assertNull( AppLicence::view( $this->fresh( $o['server'] ) ) );

		$result = AppInstall::queue( $this->fresh( $o['server'] ), $this->fake );
		$this->assertTrue( $result->is_done(), $result->error() );
		$job = json_decode( $this->fake->guest_file( 5000, Apps::JOB ), true );
		$this->assertArrayNotHasKey( 'tier', $job );
		$this->assertArrayNotHasKey( 'licence', $job );
		$this->assertStringNotContainsString( 'data-app-licence', $this->pane( $this->fresh( $o['server'] ) ) );
	}

	public function test_the_install_waits_for_a_missing_licence_then_gives_up_and_never_goes_ahead_without_one(): void {
		$this->tier_product();
		$o = $this->ordered( null );
		$this->assertTrue( $o['server']->set( array( 'app_id' => 'docker', 'app_version' => '29', 'app_state' => 'pending', 'app_tier' => null, OsField::META => 9001, 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ) ) ) );

		AppInstall::run( $o['server']->id() );
		$server = $this->fresh( $o['server'] );
		$this->assertNull( $this->fake->guest_file( 5000, Apps::JOB ), 'No install without the licence.' );
		$this->assertSame( 'pending', $server->get( 'app_state' ) );
		$this->assertGreaterThan( time(), (int) $server->get( 'app_licence_wait_until' ) );

		$this->assertTrue( $server->set( array( 'app_licence_wait_until' => time() - 1 ) ) );
		AppInstall::run( $o['server']->id() );
		$this->assertSame( 'failed', $this->fresh( $o['server'] )->get( 'app_state' ) );
		$this->assertNull( $this->fake->guest_file( 5000, Apps::JOB ) );
	}

	public function test_an_install_after_the_licence_ended_is_refused(): void {
		$o = $this->with_licence();
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => 'revoked' ) ) );
		$this->assertSame( 'ended', AppLicence::state( $this->fresh( $o['licence'] ) ) );

		$result = AppInstall::queue( $this->fresh( $o['server'] ), $this->fake );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'The app licence has ended', $result->error() );
	}

	// -- Changing tier --------------------------------------------------------------------------------

	public function test_the_app_tab_offers_the_other_tiers_as_a_subscriptions_switch(): void {
		$o = $this->with_licence( 'basic' );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
		wp_set_current_user( $o['user'] );

		$view = AppLicence::view( $this->fresh( $o['server'] ) );
		$this->assertSame( 'active', $view['state'] );
		$this->assertSame( 'Basic', $view['tier'] );
		$this->assertCount( 1, $view['tiers'], 'Only the other tier.' );
		$this->assertSame( 'Pro', $view['tiers'][0]['label'] );
		$url = html_entity_decode( $view['tiers'][0]['url'] );
		$this->assertStringContainsString( 'switch-subscription=' . (int) $o['licence']->license()['subscription_id'], $url );
		$this->assertStringContainsString( 'variation_id=' . $o['tiers']['pro']->get_id(), $url );
		$this->assertStringContainsString( '_wcsnonce=', $url );

		$html = $this->pane( $this->fresh( $o['server'] ) );
		$this->assertStringContainsString( 'data-app-licence="active"', $html );
		$this->assertStringContainsString( 'Tier: Basic', $html );
		$this->assertStringContainsString( '>Pro</a>', $html );

		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_false' );
		$this->assertSame( array(), AppLicence::view( $this->fresh( $o['server'] ) )['tiers'], 'No link when WooCommerce Subscriptions will not switch the line.' );
	}

	public function test_a_switch_cart_for_the_tier_line_passes_the_cart_checks_but_a_direct_add_does_not(): void {
		$o = $this->with_licence( 'basic' );
		wp_set_current_user( $o['user'] );
		$pro = $o['tiers']['pro'];

		$this->assertFalse( \BeltoftServices\Proxmox\AppLicenceCart::validate( true, $pro->get_parent_id(), 1, $pro->get_id(), array(), array() ), 'Without a server in the cart.' );
		wc_clear_notices();
		$this->assertTrue( \BeltoftServices\Proxmox\AppLicenceCart::validate( true, $pro->get_parent_id(), 1, $pro->get_id(), array(), array( 'subscription_switch' => array( 'subscription_id' => 1 ) ) ) );
		$data = \BeltoftServices\Proxmox\AppLicenceCart::item_data( array( 'subscription_switch' => array( 'subscription_id' => 1 ) ), $pro->get_parent_id() );
		$this->assertArrayNotHasKey( \BeltoftServices\Proxmox\AppLicence::CART_FOR, $data, 'A flow cart gets no new link to a server.' );
	}

	// -- Events a vendor driver can use -----------------------------------------------------------------

	public function test_a_tier_change_a_hold_a_resume_and_the_end_each_fire_the_event_once(): void {
		$o       = $this->with_licence( 'basic' );
		$license = (int) $o['licence']->license()['id'];
		$id      = $o['licence']->id();
		$this->events = array();

		// Tier change: the license moves to the other variation, as a Subscriptions switch does.
		$this->assertTrue( Licenses::update( $license, array( 'variation_id' => $o['tiers']['pro']->get_id() ) ) );
		Jobs::run( $id );
		$this->assertSame( array( array( 'server' => $o['server']->id(), 'app' => 'docker', 'tier' => 'pro', 'event' => 'tier_changed', 'licence' => $id ) ), $this->events );
		Jobs::run( $id );
		$this->assertCount( 1, $this->events, 'The same plan again is no change.' );

		// On hold.
		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_SUSPENDED ) ) );
		Jobs::run( $id );
		$this->assertSame( 'paused', $this->events[1]['event'] );
		$this->assertSame( 'paused', AppLicence::view( $this->fresh( $o['server'] ) )['state'] );

		// Active again.
		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_ACTIVE ) ) );
		Jobs::run( $id );
		$this->assertSame( 'resumed', $this->events[2]['event'] );

		// Ended.
		$this->assertTrue( Licenses::update( $license, array( 'status' => Licenses::STATUS_REVOKED ) ) );
		Jobs::run( $id );
		$this->assertSame( 'cancelled', $this->events[3]['event'] );
		$this->assertSame( 'pro', $this->events[3]['tier'] );
		Jobs::run( $id );
		$this->assertCount( 4, $this->events, 'An end already announced is not announced again.' );
		$this->assertSame( 'ready', $this->fresh( $o['server'] )->get( 'state' ), 'The server keeps running.' );
	}

	public function test_an_ended_licence_is_said_on_the_app_tab_and_nothing_is_uninstalled(): void {
		$o = $this->with_licence();
		$this->assertTrue( $o['server']->set( array( 'app_state' => 'ready' ) ) );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'status' => Licenses::STATUS_EXPIRED ) ) );
		Jobs::run( $o['licence']->id() );

		$html = $this->pane( $this->fresh( $o['server'] ) );
		$this->assertStringContainsString( 'data-app-licence="ended"', $html );
		$this->assertStringContainsString( 'The licence for this app has ended.', $html );
		$this->assertStringContainsString( 'Nothing is removed from your server.', $html );
		$server = $this->fresh( $o['server'] );
		$this->assertSame( 'ready', $server->get( 'state' ) );
		$this->assertSame( 'docker', $server->get( 'app_id' ) );
		$this->assertSame( 'ready', $server->get( 'app_state' ) );
		$this->assertCount( 0, $this->calls_to( 'DELETE' ), 'Nothing was deleted on the cluster.' );
	}

	/**
	 * Calls of a method.
	 *
	 * @param string $method HTTP method.
	 * @return array<int, array<string, mixed>>
	 */
	private function calls_to( string $method ): array {
		return array_values( array_filter( $this->fake->calls(), static fn( $c ) => $c['method'] === $method ) );
	}

	// -- The end of the server or of the app -------------------------------------------------------------

	public function test_deleting_the_server_ends_the_app_licence_and_its_subscription(): void {
		$o            = $this->with_licence();
		$subscription = (int) $o['licence']->license()['subscription_id'];
		$this->events = array();
		$this->mails  = array();
		$this->assertSame( 'active', wcs_get_subscription( $subscription )->get_status() );

		do_action( 'beltoft_services_state_changed', $this->fresh( $o['server'] ), 'ready', 'deleted' );

		$this->assertSame( 'cancelled', wcs_get_subscription( $subscription )->get_status() );
		$this->assertSame( 'deleted', $this->fresh( $o['licence'] )->get( 'state' ) );
		$this->assertSame( 'cancelled', $this->events[0]['event'] );
		$this->assertCount( 0, $this->mails, 'The subscription ended, so there is nothing to alert.' );
	}

	public function test_a_server_subscription_that_ends_takes_the_app_licence_subscription_with_it(): void {
		$o            = $this->with_licence();
		$server_sub   = wcs_create_subscription( array( 'customer_id' => $o['user'], 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->assertTrue( Licenses::update( (int) $o['server']->license()['id'], array( 'subscription_id' => $server_sub->get_id() ) ) );
		$licence_sub  = (int) $o['licence']->license()['subscription_id'];

		$server_sub->update_status( 'cancelled' );

		$this->assertSame( 'cancelled', wcs_get_subscription( $licence_sub )->get_status() );
		$this->assertStringContainsString( 'App licence subscription #' . $licence_sub . ' ends with this server.', implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $server_sub->get_id() ) ) ) ) );
	}

	public function test_reinstalling_without_the_app_or_with_another_ends_the_licence_but_the_same_app_keeps_it(): void {
		$o            = $this->with_licence();
		$subscription = (int) $o['licence']->license()['subscription_id'];

		// The same app again: the licence stays.
		do_action( 'beltoft_services_reinstall_started', $this->fresh( $o['server'] ), array() );
		$this->assertSame( 'active', wcs_get_subscription( $subscription )->get_status() );
		$this->assertSame( '', (string) $this->fresh( $o['licence'] )->get( AppLicence::ENDED ) );

		// Another app (here none): the licence ends, the server is not touched.
		$this->assertTrue( $o['server']->set( array( 'app_id' => '', 'app_version' => '' ) ) );
		do_action( 'beltoft_services_reinstall_started', $this->fresh( $o['server'] ), array() );
		$this->assertSame( 'cancelled', wcs_get_subscription( $subscription )->get_status() );
		$this->assertNotSame( '', (string) $this->fresh( $o['licence'] )->get( AppLicence::ENDED ) );
		$this->assertSame( 'ended', AppLicence::state( $this->fresh( $o['licence'] ) ) );
		$this->assertSame( 'ready', $this->fresh( $o['server'] )->get( 'state' ) );
	}

	public function test_a_reinstall_cannot_pick_a_paid_app(): void {
		$o = $this->with_licence();
		$this->assertTrue( $o['server']->set( array( 'app_id' => '', 'app_version' => '', 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );
		$method  = new \ReflectionMethod( AppReinstall::class, 'offers' );
		$offers  = $method->invoke( null, $this->fresh( $o['server'] ), '' );

		$this->assertIsArray( $offers );
		$this->assertArrayHasKey( 'wordpress', $offers['apps'], 'A free app is still offered.' );
		$this->assertArrayNotHasKey( 'docker', $offers['apps'], 'A paid app is bought with the server, not at a reinstall.' );
	}

	public function test_the_licence_drivers_plan_fields_and_the_service_slug(): void {
		$driver = \BeltoftServices\Drivers\Registry::get( 'proxmox-app' );
		$this->assertSame( 'applic', $driver->kind() );
		$this->assertSame( array( 'app', 'tier', 'tier_label' ), array_keys( $driver->plan_fields() ) );
		$this->assertContains( 'proxmox-app', \BeltoftServices\Proxmox\Stock::addon_drivers() );
		$this->tier_product();
		$this->assertContains( self::$slug, \BeltoftServices\Product::service_slugs() );
	}
}

<?php
/** Reinstalling an existing server to a paid app: checkout first, the reinstall follows the payment. FakeClient only. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppLicenceCart;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\AppReinstallPaid;
use BeltoftServices\Resource;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * The fixture paid app is docker (see PaidAppCase). The server of these tests has no app and a plan big enough for it.
 */
final class PaidAppReinstallTest extends PaidAppCase {

	/** @var array<string, \WC_Product_Variation> */
	private array $tiers = array();

	public function set_up(): void {
		parent::set_up();
		AppReinstallPaid::register();
		$this->tiers = $this->tier_product();
	}

	/**
	 * A ready server without an app, on a plan docker runs on.
	 *
	 * @return array{server: Resource, user: int}
	 */
	private function plain_server( int $vmid = 5000 ): array {
		$o = $this->ordered( null, 'basic', $vmid );
		$this->assertTrue( $o['server']->set( array( 'app_id' => '', 'app_version' => '', 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );

		return array( 'server' => $this->fresh( $o['server'] ), 'user' => $o['user'] );
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	/** @return array<string, string> */
	private function args( string $tier = 'pro', string $app = 'docker/29' ): array {
		return array( 'confirm' => 'REINSTALL', 'app_version' => $app, 'app_tier' => $tier );
	}

	/**
	 * The paid order for a server: the licence line is linked to the server's license id, and the licence resource is made from it.
	 *
	 * @param string $source usage for a pay-as-you-go licence.
	 */
	private function bought( Resource $server, int $user, \WC_Product_Variation $variation, string $source = '' ): Resource {
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$line  = $order->add_product( $variation, 1 );
		$item  = $order->get_item( $line );
		$item->add_meta_data( AppLicence::ITEM_UID, wp_generate_uuid4(), true );
		$item->add_meta_data( AppLicence::ITEM_SERVER, (int) $server->license()['id'], true );
		$item->save();
		$order->save();
		$data = array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id(), 'variation_id' => $variation->get_id(), 'email' => 'christian.beltoft@gmail.com' );

		if ( 'usage' === $source ) {
			$data['source'] = 'usage';
		} else {
			$subscription = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
			$subscription->add_product( $variation, 1 );
			$subscription->set_requires_manual_renewal( true );
			$subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
			$subscription->save();
			global $wpdb;
			$wpdb->delete( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $subscription->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture reset.
			$data['subscription_id'] = $subscription->get_id();
		}

		$row     = $this->service_license( wc_get_product( $variation->get_parent_id() ), $data );
		$driver  = Registry::get( 'proxmox-app' );
		$licence = Resource::create( $row, $driver, Product::plan( $variation->get_parent_id(), $variation->get_id() ), Lifecycle::initial_meta( $row, $driver ) );
		$this->assertInstanceOf( Resource::class, $licence );

		return Resource::find( $licence->id() );
	}

	private function queued_reinstall( Resource $server ): array {
		return as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) );
	}

	// -- The form ---------------------------------------------------------------------------------

	public function test_the_form_offers_the_paid_app_with_its_tiers_and_a_note(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );
		ob_start();
		AppReinstall::fields( $s['server']->id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="docker/29"', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*value="docker/29"[^>]*data-paid#s', $html, 'The tile says it is paid.' );
		$this->assertMatchesRegularExpression( '#<fieldset[^>]*data-reinstall-tiers="docker"[^>]*hidden#', $html );
		$this->assertStringContainsString( 'name="app_tier" value="basic"', $html );
		$this->assertStringContainsString( 'name="app_tier" value="pro"', $html );
		$this->assertStringContainsString( 'excl. VAT', $html );
		$this->assertStringContainsString( 'monthly licence', $html );
		$this->assertStringNotContainsString( '—', $html, 'No em dash in customer text.' );
		$this->assertStringContainsString( 'value="wordpress', $html, 'A free app is offered as before.' );
	}

	public function test_a_paid_app_with_no_tier_on_sale_is_not_offered(): void {
		$s = $this->plain_server();
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		foreach ( $this->tiers as $v ) {
			$v->set_status( 'private' );
			$v->save();
		}
		ob_start();
		AppReinstall::fields( $s['server']->id() );
		$html = (string) ob_get_clean();
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );

		$this->assertStringNotContainsString( 'docker/', $html );
	}

	// -- Before payment ---------------------------------------------------------------------------

	public function test_choosing_a_paid_app_goes_to_checkout_and_reinstalls_nothing(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );
		$url = AppReinstallPaid::redirect( '', $s['server'], 'reinstall', $this->args( 'pro' ) );

		$this->assertIsString( $url );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( (string) $this->tiers['pro']->get_parent_id(), $query['add-to-cart'] );
		$this->assertSame( (string) $this->tiers['pro']->get_id(), $query['variation_id'] );
		$this->assertSame( (string) $s['server']->license()['id'], $query[ AppLicence::CART_FOR ], 'The line is for this server.' );
		$this->assertNotEmpty( $query['beltoft_services_applic_nonce'] );

		$server = $this->fresh( $s['server'] );
		$this->assertSame( 'ready', $server->get( 'state' ) );
		$this->assertSame( '', (string) $server->get( 'app_id' ), 'Nothing is reinstalled before the licence is paid.' );
		$this->assertNull( $server->get( 'wipe' ) );
		$this->assertSame( array(), $this->queued_reinstall( $server ) );
		$pending = AppReinstallPaid::pending( $server );
		$this->assertSame( 'docker', $pending['app'] );
		$this->assertSame( 'pro', $pending['tier'] );
	}

	public function test_the_route_answers_a_paid_pick_with_the_checkout_address(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $s['server']->id() . '/actions/reinstall' );
		$request->set_param( 'args', $this->args( 'basic' ) );
		$request->set_param( 'action', 'reinstall' );
		$request->set_param( 'id', $s['server']->id() );
		$reply = \BeltoftServices\Api\Routes::action( $request );

		$this->assertSame( 200, $reply->get_status() );
		$this->assertStringContainsString( 'add-to-cart=', (string) $reply->get_data()['redirect'] );
		$this->assertSame( array(), $this->queued_reinstall( $s['server'] ) );
	}

	public function test_a_paid_pick_is_refused_without_a_tier_a_wrong_tier_or_the_confirmation(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );

		$none = AppReinstallPaid::redirect( '', $s['server'], 'reinstall', array( 'confirm' => 'REINSTALL', 'app_version' => 'docker/29' ) );
		$this->assertInstanceOf( \WP_Error::class, $none );
		$this->assertStringContainsString( 'Choose a tier', $none->get_error_message() );

		$bad = AppReinstallPaid::redirect( '', $s['server'], 'reinstall', $this->args( 'gold' ) );
		$this->assertInstanceOf( \WP_Error::class, $bad );

		$args = $this->args();
		$args['confirm'] = 'no';
		$this->assertInstanceOf( \WP_Error::class, AppReinstallPaid::redirect( '', $s['server'], 'reinstall', $args ) );
		$this->assertNull( AppReinstallPaid::pending( $this->fresh( $s['server'] ) ) );
	}

	public function test_the_reinstall_action_itself_refuses_a_paid_app_until_the_licence_is_paid(): void {
		$s = $this->plain_server();
		$this->assertStringContainsString( 'licence to be paid first', (string) Actions::check( $s['server'], 'reinstall', $this->args() ) );

		$licence = $this->bought( $s['server'], $s['user'], $this->tiers['pro'] );
		$this->assertNull( Actions::check( $this->fresh( $s['server'] ), 'reinstall', $this->args() ), 'An active licence of the app on the server lets it run.' );
		$this->assertSame( 'active', AppLicence::state( $licence ) );
	}

	public function test_a_free_app_is_still_reinstalled_at_once(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );

		$this->assertSame( '', AppReinstallPaid::redirect( '', $s['server'], 'reinstall', array( 'confirm' => 'REINSTALL', 'app_version' => 'wordpress/7.1.4' ) ) );
		$this->assertNull( Actions::check( $s['server'], 'reinstall', array( 'confirm' => 'REINSTALL', 'app_version' => 'wordpress/7.1.4' ) ), 'The free app is accepted as before, with no licence.' );
	}

	// -- The cart line ----------------------------------------------------------------------------

	public function test_the_licence_line_is_accepted_only_for_the_server_that_waits_for_it(): void {
		$s = $this->plain_server();
		$v = $this->tiers['pro'];
		wp_set_current_user( $s['user'] );
		AppReinstallPaid::redirect( '', $s['server'], 'reinstall', $this->args( 'pro' ) );
		$id    = (int) $s['server']->license()['id'];
		$nonce = wp_create_nonce( 'beltoft-services-applic:' . $id );
		$data  = array( AppLicence::CART_FOR => $id, 'beltoft_services_applic_nonce' => $nonce );

		$this->assertTrue( AppLicenceCart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), $data ) );

		$basic = $this->tiers['basic'];
		$this->assertFalse( AppLicenceCart::validate( true, $basic->get_parent_id(), 1, $basic->get_id(), array(), $data ), 'Another tier than the one chosen on the form.' );
		$this->assertFalse( AppLicenceCart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array( AppLicence::CART_FOR => $id, 'beltoft_services_applic_nonce' => 'bad' ) ), 'A wrong nonce.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertFalse( AppLicenceCart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array( AppLicence::CART_FOR => $id, 'beltoft_services_applic_nonce' => wp_create_nonce( 'beltoft-services-applic:' . $id ) ) ), 'Not the owner.' );
		$this->assertFalse( AppLicenceCart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array( AppLicence::CART_FOR => 'cart_server' ) ), 'No server in the cart.' );
	}

	public function test_a_server_that_waits_for_nothing_takes_no_licence_line(): void {
		$s = $this->plain_server();
		$v = $this->tiers['pro'];
		wp_set_current_user( $s['user'] );
		$id = (int) $s['server']->license()['id'];

		$this->assertFalse( AppLicenceCart::validate( true, $v->get_parent_id(), 1, $v->get_id(), array(), array( AppLicence::CART_FOR => $id, 'beltoft_services_applic_nonce' => wp_create_nonce( 'beltoft-services-applic:' . $id ) ) ) );
	}

	public function test_the_cart_line_of_an_existing_server_is_not_the_new_server_line(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );
		AppReinstallPaid::redirect( '', $s['server'], 'reinstall', $this->args( 'pro' ) );
		$id = (int) $s['server']->license()['id'];
		$v  = $this->tiers['pro'];
		$_REQUEST[ AppLicence::CART_FOR ]            = (string) $id;
		$_REQUEST['beltoft_services_applic_nonce'] = wp_create_nonce( 'beltoft-services-applic:' . $id );
		$key = WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), array( 'attribute_tier' => 'Pro' ) );
		unset( $_REQUEST[ AppLicence::CART_FOR ], $_REQUEST['beltoft_services_applic_nonce'] );

		$this->assertNotFalse( $key );
		$this->assertTrue( AppReinstallPaid::in_cart() );
		$this->assertNull( AppLicenceCart::line(), 'It does not count as the line of a new server.' );
		do_action( 'woocommerce_cart_item_removed' );
		$this->assertTrue( AppReinstallPaid::in_cart(), 'Nothing cleans it away as an orphan.' );

		$errors = new \WP_Error();
		AppReinstallPaid::checkout_validation( array(), $errors );
		$this->assertSame( array(), $errors->get_error_messages() );

		$this->assertTrue( $this->fresh( $s['server'] )->set( array( AppReinstallPaid::PENDING => null ) ) );
		AppReinstallPaid::checkout_validation( array(), $errors );
		$this->assertNotEmpty( $errors->get_error_messages(), 'The request was dropped meanwhile: the order is not placed.' );
	}

	// -- After payment ----------------------------------------------------------------------------

	public function test_the_paid_licence_links_to_the_server_and_queues_the_kept_reinstall(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );
		AppReinstallPaid::redirect( '', $s['server'], 'reinstall', $this->args( 'pro' ) );

		$licence = $this->bought( $this->fresh( $s['server'] ), $s['user'], $this->tiers['pro'] );
		$this->assertSame( (int) $s['server']->license()['id'], (int) $licence->get( 'server_license' ), 'The licence belongs to that server.' );
		$this->assertSame( array(), $this->queued_reinstall( $s['server'] ) );

		Jobs::run( $licence->id() );
		$licence = $this->fresh( $licence );
		$this->assertSame( 'ready', $licence->get( 'state' ), (string) $licence->get( 'last_error' ) );
		$this->assertNull( AppReinstallPaid::pending( $this->fresh( $s['server'] ) ), 'The request is used once.' );
		$this->assertCount( 1, $this->queued_reinstall( $s['server'] ), 'The reinstall of the customer is queued now.' );
	}

	public function test_the_queued_reinstall_runs_with_the_app_and_the_licence_hands_its_tier_to_the_install(): void {
		$s = $this->plain_server();
		wp_set_current_user( $s['user'] );
		AppReinstallPaid::redirect( '', $s['server'], 'reinstall', $this->args( 'pro' ) );
		$licence = $this->bought( $this->fresh( $s['server'] ), $s['user'], $this->tiers['pro'] );
		Jobs::run( $licence->id() );

		$this->run_jobs();
		$server = $this->fresh( $s['server'] );

		$this->assertSame( 'docker', $server->get( 'app_id' ), (string) $server->get( 'last_error' ) );
		$this->assertSame( 'pro', $server->get( 'app_tier' ) );
		$this->assertSame( '', (string) $this->fresh( $licence )->get( AppLicence::ENDED ), 'The new licence is kept.' );
		$for_install = AppLicence::for_install( $server );
		$this->assertIsArray( $for_install );
		$this->assertSame( 'pro', $for_install['tier'] );
	}

	public function test_a_licence_paid_with_no_request_is_not_delivered(): void {
		$s       = $this->plain_server();
		$licence = $this->bought( $s['server'], $s['user'], $this->tiers['pro'] );
		Jobs::run( $licence->id() );
		$licence = $this->fresh( $licence );

		$this->assertSame( 'failed', $licence->get( 'state' ) );
		$this->assertStringContainsString( 'built without this app', (string) $licence->get( 'last_error' ) );
		$this->assertSame( array(), $this->queued_reinstall( $s['server'] ) );
	}

	// -- Leaving the app --------------------------------------------------------------------------

	public function test_switching_from_a_paid_app_to_another_ends_the_old_licence_as_before(): void {
		$o = $this->ordered( $this->tiers['basic'], 'basic' );
		Jobs::run( $o['licence']->id() );
		$subscription = (int) $o['licence']->license()['subscription_id'];

		$this->assertTrue( $o['server']->set( array( 'app_id' => 'wordpress', 'app_version' => '6' ) ) );
		do_action( 'beltoft_services_reinstall_started', $this->fresh( $o['server'] ), array() );

		$this->assertSame( 'cancelled', wcs_get_subscription( $subscription )->get_status() );
		$this->assertSame( 'ended', AppLicence::state( $this->fresh( $o['licence'] ) ) );
	}
}

<?php
/** A paid app on a pay-as-you-go server: billed by the hour from the usage ledger, never a fixed monthly line. FakeClient only. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppLicenceCart;
use BeltoftServices\Proxmox\AppReinstallPaid;
use BeltoftServices\Proxmox\DiskField;
use BeltoftServices\Resource;
use BeltoftServices\Usage\BillingField;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * Tiers: basic 5 and pro 12 a month. The hourly rate is the monthly price x 1.1 / 730 and the cap the monthly price x 1.1.
 */
final class PaidAppUsageTest extends PaidAppCase {

	/** @var array<string, \WC_Product_Variation> Monthly tiers. */
	private array $tiers = array();

	/** @var array<string, \WC_Product_Variation> Their pay-as-you-go siblings. */
	private array $usage = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Ledger::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		AppReinstallPaid::register();
		$this->tiers = $this->tier_product();
		$this->usage = $this->usage_siblings( $this->tiers, array( 'basic', 'pro' ) );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		parent::tear_down();
	}

	/**
	 * A pay-as-you-go server with a paid app and its usage licence, both ready and activated $hours ago.
	 *
	 * @return array{server: Resource, licence: Resource, user: int}
	 */
	private function usage_pair( string $tier = 'basic', int $hours = 5 ): array {
		$o       = $this->ordered( null, $tier );
		$user    = $o['user'];
		$server  = $o['server'];
		$this->assertTrue( $server->set( array( 'app_version' => '29' ) ) );
		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'source' => 'usage' ), array( 'id' => (int) $server->license()['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$server = Resource::find( $server->id() );

		$v     = $this->usage[ $tier ];
		$row   = $this->service_license( wc_get_product( $v->get_parent_id() ), array( 'fire' => false, 'user_id' => $user, 'variation_id' => $v->get_id(), 'source' => 'usage', 'email' => 'christian.beltoft@gmail.com' ) );
		$meta  = array( 'server_license' => (int) $server->license()['id'] );
		$lic   = Resource::create( $row, Registry::get( 'proxmox-app' ), Product::plan( $v->get_parent_id(), $v->get_id() ), $meta );
		$this->assertInstanceOf( Resource::class, $lic );
		Jobs::run( $lic->id() );
		$lic = Resource::find( $lic->id() );
		$this->assertSame( 'ready', $lic->get( 'state' ), (string) $lic->get( 'last_error' ) );
		$this->assertNotNull( $lic->get( Meter::READY_AT ) );
		$wpdb->update( \BeltoftLicenses\Install::tables()['activations'], array( 'activated_at' => gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ), array( 'id' => $lic->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$lic->set( array( Meter::READY_AT => time() - $hours * HOUR_IN_SECONDS ) );

		return array( 'server' => Resource::find( $server->id() ), 'licence' => Resource::find( $lic->id() ), 'user' => $user );
	}

	/** @return array<int, array<string, mixed>> */
	private function rows( int $resource_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Ledger::table() . ' WHERE resource_id = %d ORDER BY hour_bucket', $resource_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
	}

	// -- Rate, meter, cap ---------------------------------------------------------------------------

	public function test_a_usage_licence_is_one_hourly_item_at_the_tiers_monthly_price_plus_the_premium(): void {
		$p     = $this->usage_pair( 'pro' );
		$items = Rates::items( $p['licence'] );

		$this->assertCount( 1, $items );
		$this->assertSame( 'app', $items[0]['item'] );
		$this->assertSame( 'hour', $items[0]['unit'] );
		$this->assertSame( 1.0, $items[0]['quantity'] );
		$this->assertSame( Rates::hourly( 12.0, 10.0 ), $items[0]['rate'] );
		$this->assertSame( 0.01808219, $items[0]['rate'], '12 x 1.1 / 730' );
	}

	public function test_the_meter_writes_an_app_row_every_hour_and_the_cap_is_the_monthly_price_plus_premium(): void {
		$p   = $this->usage_pair( 'basic', 5 );
		$now = time();
		Meter::run( $now );
		$rows = $this->rows( $p['licence']->id() );

		$this->assertGreaterThanOrEqual( 4, count( $rows ) );
		$this->assertSame( 'app', $rows[0]['item'] );
		$this->assertSame( (string) Rates::hourly( 5.0, 10.0 ), rtrim( (string) $rows[0]['rate'], '0' ) === '' ? '0' : (string) (float) $rows[0]['rate'] );
		$before = count( $rows );
		Meter::run( $now );
		$this->assertCount( $before, $this->rows( $p['licence']->id() ), 'Running again writes nothing twice.' );

		// A whole month of hours costs at most the monthly price x 1.1.
		$month = array();
		for ( $h = 0; $h < 740; $h++ ) {
			$month[] = array( 'id' => $h, 'resource_id' => $p['licence']->id(), 'item' => 'app', 'quantity' => '1', 'unit' => 'hour', 'rate' => (string) Rates::hourly( 5.0, 10.0 ), 'hour_bucket' => gmdate( 'Y-m-d H:00:00', strtotime( '2026-10-01 00:00:00 UTC' ) + ( $h % 700 ) * HOUR_IN_SECONDS ) );
		}
		$line = Renewal::statement( $month )['lines'][0];
		$this->assertTrue( $line['capped'] );
		$this->assertSame( 5.5, $line['charge'] );
	}

	public function test_the_app_licence_shows_with_its_server_on_the_usage_screens_and_the_invoice(): void {
		$p = $this->usage_pair( 'basic', 4 );
		$this->assertTrue( $p['server']->set( array( 'server_name' => 'web1' ) ) );
		Meter::run( time() );

		$this->assertSame( 'app licence', Renewal::item_label( 'app' ) );
		$this->assertStringStartsWith( 'web1 (', Renewal::label( $p['licence']->id() ) );
		$this->assertStringNotContainsString( '—', Renewal::item_label( 'app' ) );
	}

	public function test_ending_the_app_closes_the_usage_licence_and_the_meter_stops(): void {
		$p = $this->usage_pair( 'basic', 6 );
		Meter::run( time() );
		$this->assertNotEmpty( $this->rows( $p['licence']->id() ) );

		do_action( 'beltoft_services_reinstall_started', $this->fresh_server( $p['server'], array( 'app_id' => 'wordpress' ) ), array() );
		$licence = Resource::find( $p['licence']->id() );

		$this->assertSame( 'ended', AppLicence::state( $licence ) );
		$this->assertNotNull( \BeltoftLicenses\Licenses::activation( $licence->id() )['deactivated_at'] );
		$this->assertSame( array( 'cancelled' ), array_column( array_filter( $this->events, static fn( $e ) => 'cancelled' === $e['event'] ), 'event' ) );
		Meter::run( time() + 3 * HOUR_IN_SECONDS );
		$count = count( $this->rows( $licence->id() ) );
		Meter::run( time() + 9 * HOUR_IN_SECONDS );
		$this->assertSame( $count, count( $this->rows( $licence->id() ) ), 'No more rows after the hour it ended in.' );
	}

	private function fresh_server( Resource $server, array $meta ): Resource {
		$this->assertTrue( $server->set( $meta ) );

		return Resource::find( $server->id() );
	}

	public function test_the_app_tab_says_it_is_billed_by_the_hour_and_offers_no_subscription_switch(): void {
		$p = $this->usage_pair( 'basic' );
		wp_set_current_user( $p['user'] );
		$view = AppLicence::view( $p['server'] );

		$this->assertSame( 'active', $view['state'] );
		$this->assertStringContainsString( 'per hour', $view['usage'] );
		$this->assertStringContainsString( 'max', $view['usage'] );
		$this->assertSame( array(), $view['tiers'], 'There is no subscription of its own to switch.' );
	}

	// -- Checkout ----------------------------------------------------------------------------------

	public function test_a_monthly_server_gets_the_monthly_tier_and_a_usage_server_its_usage_sibling(): void {
		$this->assertSame( $this->tiers['pro']->get_id(), AppLicence::line_variation( AppLicence::tier( 'docker', 'pro' ), false )->get_id() );
		$this->assertSame( $this->usage['pro']->get_id(), AppLicence::line_variation( AppLicence::tier( 'docker', 'pro' ), true )->get_id() );
		$this->assertStringContainsString( 'per hour', AppLicence::usage_price( AppLicence::tier( 'docker', 'pro' ) ) );
		$this->assertCount( 2, AppLicence::tiers( 'docker' ), 'The usage siblings are no tiers of their own.' );
	}

	public function test_a_tier_with_no_usage_sibling_is_never_sold_to_a_usage_server_as_a_monthly_line(): void {
		remove_all_filters( 'woocommerce_product_is_in_stock' );
		$only = $this->usage_siblings( $this->tiers, array( 'basic' ) );
		$this->assertNotEmpty( $only );
		wp_delete_post( $this->usage['pro']->get_id(), true );
		clean_post_cache( $this->tiers['pro']->get_parent_id() );
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );

		$pro = AppLicence::tier( 'docker', 'pro' );
		$this->assertNull( AppLicence::usage_variation( $pro ) );
		$this->assertNull( AppLicence::line_variation( $pro, true ), 'No fallback to the monthly variation.' );
		$this->assertSame( '', AppLicence::usage_price( $pro ) );
	}

	public function test_a_licence_line_for_a_usage_server_must_be_the_usage_variation(): void {
		$p = $this->usage_pair( 'basic' );
		$server = $p['server'];
		wp_set_current_user( $p['user'] );
		$this->assertTrue( $server->set( array( 'app_id' => '', 'app_version' => '', 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );
		$server = Resource::find( $server->id() );
		$this->assertTrue( AppReinstallPaid::server_usage( (int) $server->license()['id'] ) );

		$this->assertSame( '', AppReinstallPaid::redirect( '', $server, 'reinstall', array( 'confirm' => 'REINSTALL', 'app_version' => 'docker/29', 'app_tier' => 'pro' ) ), 'The server still holds an active licence of the app: nothing more to buy.' );
		AppLicence::end( $p['licence'], 'Test: the licence ended.' );

		$url = AppReinstallPaid::redirect( '', $server, 'reinstall', array( 'confirm' => 'REINSTALL', 'app_version' => 'docker/29', 'app_tier' => 'pro' ) );
		$this->assertStringContainsString( 'add-to-cart', (string) $url );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( (string) $this->usage['pro']->get_id(), $query['variation_id'], 'The hourly line, never the monthly one.' );

		$id   = (int) $server->license()['id'];
		$data = array( AppLicence::CART_FOR => $id, 'beltoft_services_applic_nonce' => wp_create_nonce( 'beltoft-services-applic:' . $id ) );
		$m    = $this->tiers['pro'];
		$u    = $this->usage['pro'];
		$this->assertTrue( AppLicenceCart::validate( true, $u->get_parent_id(), 1, $u->get_id(), array(), $data ) );
		$this->assertFalse( AppLicenceCart::validate( true, $m->get_parent_id(), 1, $m->get_id(), array(), $data ), 'A pay-as-you-go customer is never given a monthly subscription line.' );
	}

	public function test_the_reinstall_form_shows_the_hourly_price_for_a_usage_server(): void {
		$p = $this->usage_pair( 'basic' );
		wp_set_current_user( $p['user'] );
		$this->assertTrue( $p['server']->set( array( 'app_id' => '', 'app_version' => '', 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );
		ob_start();
		\BeltoftServices\Proxmox\AppReinstall::fields( $p['server']->id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'per hour', $html );
		$this->assertStringContainsString( 'billed by the hour', $html );
		$this->assertStringNotContainsString( 'monthly licence', $html );
		$this->assertStringNotContainsString( 'excl. VAT</span>', str_replace( 'excluding VAT', '', $html ), 'No monthly price is shown.' );
	}

	public function test_a_paid_usage_licence_is_delivered_and_bills_with_no_subscription_of_its_own(): void {
		$p = $this->usage_pair( 'pro' );
		$this->assertSame( 'usage', $p['licence']->license()['source'] );
		$this->assertSame( 0, (int) ( $p['licence']->license()['subscription_id'] ?? 0 ), 'No fixed monthly subscription was made for it.' );
	}

	// -- The cart of a new server -----------------------------------------------------------------

	/**
	 * A server product with a monthly and a pay-as-you-go variation of one plan (price 10 and 0).
	 *
	 * @return array{m: \WC_Product_Variation, u: \WC_Product_Variation}
	 */
	private function server_variations(): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'VPS ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$billing = new \WC_Product_Attribute();
		$billing->set_name( 'Billing' );
		$billing->set_options( array( 'Monthly', 'Pay as you go' ) );
		$billing->set_visible( true );
		$billing->set_variation( true );
		$product->set_attributes( array( $billing ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'vps-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );
		$out = array();

		foreach ( array( 'm' => array( 'Monthly', '10' ), 'u' => array( 'Pay as you go', '0' ) ) as $short => $def ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'billing' => $def[0] ) );
			$v->set_regular_price( $def[1] );
			$v->set_status( 'publish' );

			if ( 'u' === $short ) {
				$v->update_meta_data( Rates::META_BILLING, 'usage' );
			}

			$v->save();
			$out[ $short ] = wc_get_product( $v->get_id() );
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	private function put_server_in_cart( \WC_Product_Variation $variation ): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertNotFalse( WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation->get_id(), \BeltoftServices\Proxmox\BackupField::attributes( $variation ) ) );
	}

	private function tier_line_variation(): int {
		$line = AppLicenceCart::line();

		return null === $line ? 0 : (int) $line['item']['variation_id'];
	}

	public function test_the_tier_line_follows_the_servers_billing_in_the_cart(): void {
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['m'] );
		$this->assertFalse( AppLicenceCart::server_usage() );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'pro' ) );
		$this->assertSame( $this->tiers['pro']->get_id(), $this->tier_line_variation(), 'A monthly server: the monthly tier.' );

		$this->assertTrue( BillingField::swap( true )['ok'] );
		$this->assertTrue( AppLicenceCart::server_usage() );
		$this->assertSame( $this->usage['pro']->get_id(), $this->tier_line_variation(), 'The server went pay as you go: so did the tier.' );

		$this->assertTrue( BillingField::swap( false )['ok'] );
		$this->assertSame( $this->tiers['pro']->get_id(), $this->tier_line_variation() );
	}

	public function test_a_usage_server_in_the_cart_gets_the_usage_tier_at_once_and_it_costs_nothing_at_checkout(): void {
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['u'] );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'basic' ) );
		$this->assertSame( $this->usage['basic']->get_id(), $this->tier_line_variation() );
		$this->assertSame( 0.0, (float) wc_get_product( $this->tier_line_variation() )->get_price() );
	}

	public function test_a_tier_with_no_hourly_price_leaves_the_cart_when_the_server_goes_pay_as_you_go(): void {
		$server = $this->server_variations();
		wp_delete_post( $this->usage['pro']->get_id(), true );
		clean_post_cache( $this->tiers['pro']->get_parent_id() );
		$this->put_server_in_cart( $server['m'] );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'pro' ) );

		$this->assertTrue( BillingField::swap( true )['ok'] );
		$this->assertNull( AppLicenceCart::line(), 'The monthly line is not kept for a pay-as-you-go server.' );
		$this->assertFalse( AppLicenceCart::choose( 'docker', 'pro' ), 'And it cannot be added.' );

		do_action( 'woocommerce_checkout_process' );
		$errors = new \WP_Error();
		$_POST[ \BeltoftServices\Proxmox\AppField::POST_APP ]  = 'docker';
		$_POST[ \BeltoftServices\Proxmox\AppField::POST_TIER ] = 'pro';
		$_POST[ \BeltoftServices\Proxmox\AppField::POST_VERSION ] = '29';
		\BeltoftServices\Proxmox\AppField::validate_classic( array(), $errors );
		$this->assertNotEmpty( $errors->get_error_messages(), 'Checkout does not go on without the tier line.' );
	}
}

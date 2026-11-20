<?php
/**
 * The pay as you go twin of the IPv4 address: billing follows the server or database, the usage engine meters it through the
 * driver's own hourly item, the dashboard removes it without a subscription, and a monthly parent keeps the monthly address.
 * Fake clients only; nothing is charged.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Ipv4\Ipv4Cart;
use BeltoftServices\Proxmox\Ipv4\Ipv4Driver;
use BeltoftServices\Proxmox\Ipv4\Ipv4Meta;
use BeltoftServices\Proxmox\Ipv4\Ipv4Panels;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Cart;
use BeltoftServices\Usage\CustomerDelete;
use BeltoftServices\Usage\Items;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;

require_once __DIR__ . '/Ipv4TestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/Ipv4Product.php';

class Ipv4UsageTest extends Ipv4TestCase {

	use \BeltoftServices\Tests\Support\Ipv4Product;

	/** @var array<string, \WC_Product_Variation> m = monthly, u = pay as you go. */
	private $v = array();

	public function set_up(): void {
		parent::set_up();
		remove_all_filters( 'beltoft_services_ipv4_alerts' );
		WC()->cart->empty_cart();
		$this->v = $this->ipv4_product();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_REQUEST['beltoft_services_ipv4_for'], $_REQUEST['beltoft_services_ipv4_nonce'] );
		parent::tear_down();
	}

	/** A ready dedicated-plan server whose licence is pay as you go (source usage) or monthly. */
	private function parent( bool $usage ): Resource {
		$server = $this->server( 1 );

		if ( $usage ) {
			Licenses::update( (int) $server->license()['id'], array( 'source' => 'usage' ) );
		}

		wp_set_current_user( (int) $server->license()['user_id'] );

		return Resource::find( $server->id() );
	}

	/** One address of the usage twin (or the monthly one) for a server, provisioned. */
	private function address( Resource $server, bool $usage ): Resource {
		$variation = $this->v[ $usage ? 'u' : 'm' ];
		$parent    = wc_get_product( $variation->get_parent_id() );
		$license   = $this->service_license(
			$parent,
			array(
				'fire'         => false,
				'user_id'      => (int) $server->license()['user_id'],
				'source'       => $usage ? 'usage' : 'order',
				'variation_id' => $variation->get_id(),
			)
		);
		$addon     = Resource::create( $license, Registry::get( Ipv4Driver::ID ), Product::plan( $parent->get_id(), $variation->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $addon );
		$this->settle( $addon );

		return Resource::find( $addon->id() );
	}

	public function test_billing_follows_the_parent(): void {
		$usage = $this->parent( true );
		$this->assertSame( $this->v['u']->get_id(), Ipv4Cart::product_for( $usage )->get_id() );
		$this->assertSame( $this->v['m']->get_id(), Ipv4Cart::product( false )->get_id() );
		$this->assertSame( $this->v['u']->get_id(), Ipv4Cart::product( true )->get_id() );

		$monthly = $this->parent( false );
		$this->assertSame( $this->v['m']->get_id(), Ipv4Cart::product_for( $monthly )->get_id() );
	}

	public function test_the_dashboard_offers_the_pay_as_you_go_address_with_the_cap_as_its_price(): void {
		$server = $this->parent( true );
		$view   = Ipv4Panels::view( $server );
		$query  = array();
		wp_parse_str( (string) wp_parse_url( $view['add_url'], PHP_URL_QUERY ), $query );

		$this->assertSame( $this->v['u']->get_id(), (int) $query['variation_id'] );
		$this->assertStringContainsString( 'billed by the hour', $view['add_price'] );
		$this->assertStringContainsString( 'at most', $view['add_price'] );

		$monthly = Ipv4Panels::view( $this->parent( false ) );
		wp_parse_str( (string) wp_parse_url( $monthly['add_url'], PHP_URL_QUERY ), $query );
		$this->assertSame( $this->v['m']->get_id(), (int) $query['variation_id'], 'A monthly server keeps the monthly address.' );
		$this->assertStringNotContainsString( 'billed by the hour', $monthly['add_price'] );
	}

	public function test_the_cart_takes_only_the_twin_of_the_parents_billing(): void {
		$server = $this->parent( true );
		$query  = array();
		wp_parse_str( (string) wp_parse_url( Ipv4Panels::view( $server )['add_url'], PHP_URL_QUERY ), $query );
		$_REQUEST['beltoft_services_ipv4_for']   = (string) $query['beltoft_services_ipv4_for'];
		$_REQUEST['beltoft_services_ipv4_nonce'] = (string) $query['beltoft_services_ipv4_nonce'];
		$u = $this->v['u'];
		$m = $this->v['m'];

		wc_clear_notices();
		$this->assertFalse( Ipv4Cart::validate( true, $m->get_parent_id(), 1, $m->get_id(), array(), array() ), 'No monthly subscription address for a pay as you go server.' );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
		$this->assertTrue( Ipv4Cart::validate( true, $u->get_parent_id(), 1, $u->get_id(), array(), array() ) );

		$added = WC()->cart->add_to_cart( $u->get_parent_id(), 1, $u->get_id(), array( 'attribute_billing' => 'Pay as you go' ) );
		$this->assertNotFalse( $added );
		$this->assertSame( 1, Cart::usage_units(), 'It is a pay as you go unit: the usage account rules apply.' );
		$this->assertSame( 0, Cart::service_units(), 'It is not a service of its own for the new-account limit.' );
	}

	public function test_a_monthly_parent_refuses_the_pay_as_you_go_address(): void {
		$server = $this->parent( false );
		$query  = array();
		wp_parse_str( (string) wp_parse_url( Ipv4Panels::view( $server )['add_url'], PHP_URL_QUERY ), $query );
		$_REQUEST['beltoft_services_ipv4_for']   = (string) $query['beltoft_services_ipv4_for'];
		$_REQUEST['beltoft_services_ipv4_nonce'] = (string) $query['beltoft_services_ipv4_nonce'];
		$u = $this->v['u'];

		wc_clear_notices();
		$this->assertFalse( Ipv4Cart::validate( true, $u->get_parent_id(), 1, $u->get_id(), array(), array() ) );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	public function test_the_usage_engine_meters_the_address_through_its_driver(): void {
		$server = $this->parent( true );
		$addon  = $this->address( $server, true );
		$this->assertSame( 'ready', $addon->get( 'state' ), (string) $addon->get( 'last_error' ) );
		$this->assertSame( 'extra', $addon->get( Ipv4Meta::ROLE ) );

		$items = Rates::items( $addon );
		$this->assertCount( 1, $items );
		$this->assertSame( 'ipv4', $items[0]['item'], 'The ledger item name.' );
		$this->assertSame( 1.0, $items[0]['quantity'] );
		$this->assertSame( 'hour', $items[0]['unit'] );
		$this->assertEqualsWithDelta( Rates::hourly( 2.04, 0.0 ), $items[0]['rate'], 0.0000001 );
		$this->assertEqualsWithDelta( 2.04, Rates::for_usage( $this->v['u'] )['cap'], 0.0001, 'The cap is the monthly price.' );

		$this->assertSame( 'IPv4 address', Renewal::item_label( 'ipv4' ) );
		$this->assertSame( 'IPv4 address for web1', Renewal::line_subject( 'ipv4', 'web1' ) );
		$this->assertFalse( Items::is_plan( 'ipv4' ) );
		$this->assertTrue( Items::driver_of( $addon )->usage_is_addon() );
	}

	public function test_the_dashboard_removes_a_pay_as_you_go_address_through_the_usage_engine(): void {
		$server = $this->parent( true );
		$addon  = $this->address( $server, true );
		$view   = Ipv4Panels::view( Resource::find( $server->id() ) );
		$row    = end( $view['addresses'] );

		$this->assertSame( '', $row['remove_url'], 'No subscription cancel link.' );
		$this->assertSame( $addon->id(), $row['delete_id'] );
		$this->assertSame( 'ipv4', CustomerDelete::kind( $addon ) );

		$address = (string) $addon->get( Ipv4Meta::ADDRESS );
		$done    = CustomerDelete::run( $addon, (int) $addon->license()['user_id'], CustomerDelete::word( $addon ) );
		$this->assertTrue( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertSame( Licenses::STATUS_REVOKED, Licenses::find( (int) $addon->license()['id'] )['status'] );

		$this->settle( $addon );
		$fresh = Resource::find( $addon->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ), (string) $fresh->get( 'last_error' ) );
		$this->assertFalse( $fresh->is_live(), 'The activation is closed: metering stops.' );
		$this->assertSame( 'free', IpPool::find( $address )['state'], 'The address is back in the pool.' );
		$this->assertSame( 'ready', Resource::find( $server->id() )->get( 'state' ) );
	}

	public function test_the_main_address_cannot_be_removed_while_extras_are_left(): void {
		$server = $this->parent( true );
		$addon  = $this->address( $server, true );
		$addon->set( array( Ipv4Meta::ROLE => 'main' ) );
		$this->address( $server, true );

		$this->assertNotNull( CustomerDelete::blocker( Resource::find( $addon->id() ) ) );
	}

	public function test_the_main_address_of_a_server_without_ipv6_cannot_be_removed(): void {
		$server = $this->parent( true );
		$addon  = $this->address( $server, true );
		$addon->set( array( Ipv4Meta::ROLE => 'main' ) );
		$server->set( array( 'ip6' => '' ) );

		$this->assertNotNull( CustomerDelete::blocker( Resource::find( $addon->id() ) ) );
		$view = Ipv4Panels::view( Resource::find( $server->id() ) );
		$this->assertSame( 0, end( $view['addresses'] )['delete_id'] );
	}
}

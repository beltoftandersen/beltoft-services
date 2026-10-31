<?php
/**
 * The read tools: services, details, usage, recovery points, access list, orders, subscriptions, cards, account,
 * catalogue, claims, licenses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Resource;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Read tool tests.
 */
final class ReadsTest extends McpTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	/**
	 * @return array{0: int, 1: string} Customer and a read token.
	 */
	private function reader(): array {
		$user = self::factory()->user->create();

		return array( $user, $this->oauth( $user ) );
	}

	public function test_list_services_adds_license_and_subscription_and_can_filter_by_type(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user );
		$r    = $this->domain_paid_by( $user, $sub );
		$db   = $this->database( $user );
		$tok  = $this->oauth( $user );

		$all = $this->json( $this->tool( 'list_services', array(), $tok ) )['services'];
		$row = array_values( array_filter( $all, static fn( $s ) => $s['id'] === $r->id() ) )[0];

		$this->assertSame( (int) $r->license()['id'], $row['license_id'] );
		$this->assertSame( $sub->get_id(), $row['subscription_id'] );
		$this->assertCount( 2, $all );

		$only = $this->json( $this->tool( 'list_services', array( 'type' => 'db' ), $tok ) )['services'];
		$this->assertSame( array( $db->id() ), array_column( $only, 'id' ) );
		$this->assertSame( array(), $this->json( $this->tool( 'list_services', array( 'type' => 'nothing' ), $tok ) )['services'] );
	}

	public function test_service_details_of_a_domain_a_database_and_nobody_elses(): void {
		$user   = self::factory()->user->create();
		$sub    = $this->subscription( $user );
		$domain = $this->domain_paid_by( $user, $sub );
		$db     = $this->database( $user );
		$tok    = $this->oauth( $user );

		$d = $this->json( $this->tool( 'service_details', array( 'id' => $domain->id() ), $tok ) );
		$this->assertSame( 'domain', $d['type'] );
		$this->assertSame( 'example.com', $d['domain']['name'] );
		$this->assertTrue( $d['domain']['transfer_locked'] );
		$this->assertSame( array( 'ns1.example.net', 'ns2.example.net' ), $d['domain']['name_servers'] );
		$this->assertSame( $sub->get_id(), $d['subscription']['id'] );
		$this->assertSame( 'active', $d['subscription']['status'] );
		$this->assertContains( 'Save DNS records', $d['dashboard_offers'] );

		$b = $this->json( $this->tool( 'service_details', array( 'id' => $db->id() ), $tok ) );
		$this->assertSame( 'db', $b['type'] );
		$this->assertSame( '10.64.0.2', $b['database']['host'] );
		$this->assertSame( self::FP, $b['database']['tls_fingerprint'] );

		// No password, no certificate, no link with a nonce, no address of the visitor.
		$text = $this->text( $this->tool( 'service_details', array( 'id' => $db->id() ), $tok ) ) . $this->text( $this->tool( 'service_details', array( 'id' => $domain->id() ), $tok ) );

		foreach ( array( self::PASSWORD, 'BEGIN CERTIFICATE', '_wpnonce', 'wp_rest', 'my_ip', 'add_url', 'remove_url', 'ca_url' ) as $bad ) {
			$this->assertStringNotContainsString( $bad, $text );
		}

		$thief = $this->oauth( self::factory()->user->create() );
		$this->assertTrue( $this->tool( 'service_details', array( 'id' => $db->id() ), $thief )['isError'] );
		$this->assertNotContains( 'Claude: service details read', array_column( Events::for_license( (int) $db->license()['id'] ), 'message' ) );
	}

	public function test_usage_summary_has_servers_and_the_pay_as_you_go_part(): void {
		list( , $tok ) = $this->reader();

		$data = $this->json( $this->tool( 'usage_summary', array(), $tok ) );

		$this->assertSame( array(), $data['servers'] );
		$this->assertNull( $data['pay_as_you_go'] );
	}

	public function test_recovery_points_of_a_database_and_of_a_service_without_any(): void {
		$db  = $this->database();
		$tok = $this->oauth( $this->owner( $db ) );
		$db->set( array( 'backup_list' => array( array( 'volid' => 'store:backup/vzdump-1', 'ctime' => 1790000000, 'size' => 2048 ) ), 'backup_ok_at' => 1790000000 ) );

		$none = $this->tool( 'list_recovery_points', array( 'id' => $db->id() ), $tok );
		$data = $this->json( $none );
		$this->assertArrayHasKey( 'backups', $data );
		$this->assertArrayNotHasKey( 'snapshots', $data, 'A database has no snapshots.' );

		$domain = $this->domain( $this->owner( $db ) );
		$this->assertTrue( $this->tool( 'list_recovery_points', array( 'id' => $domain->id() ), $tok )['isError'] );
		$this->assertTrue( $this->tool( 'list_recovery_points', array( 'id' => $db->id() ), $this->oauth( self::factory()->user->create() ) )['isError'] );
	}

	public function test_database_access_state_and_list_without_the_visitors_address(): void {
		$db  = $this->database();
		$tok = $this->oauth( $this->owner( $db ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';

		$data = $this->json( $this->tool( 'get_database_access', array( 'id' => $db->id() ), $tok ) );
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertArrayHasKey( 'state', $data );
		$this->assertSame( array(), $data['entries'] );
		$this->assertFalse( $data['open_to_everyone'] );
		$this->assertStringNotContainsString( '198.51.100.20', wp_json_encode( $data ) );
		$this->assertTrue( $this->tool( 'get_database_access', array( 'id' => $db->id() ), $this->oauth( self::factory()->user->create() ) )['isError'] );
	}

	public function test_an_unpaid_order_points_to_its_page_in_the_dashboard_and_never_to_the_pay_link_with_its_key(): void {
		list( $user, $tok ) = $this->reader();
		$order = wc_create_order( array( 'customer_id' => $user, 'status' => 'pending' ) );
		$item  = new \WC_Product_Simple();
		$item->set_name( 'Thing' );
		$item->set_regular_price( '9.00' );
		$item->save();
		$order->add_product( $item, 1 );
		$order->calculate_totals();
		$order->save();

		foreach ( array( $this->tool( 'list_orders', array(), $tok ), $this->tool( 'get_order', array( 'order_id' => $order->get_id() ), $tok ) ) as $result ) {
			$text = $this->text( $result );
			$this->assertStringNotContainsString( $order->get_order_key(), $text );
			$this->assertStringNotContainsString( 'pay_for_order', $text );
		}

		$row = $this->json( $this->tool( 'list_orders', array(), $tok ) )['orders'][0];
		$this->assertTrue( $row['needs_payment'] );
		$this->assertSame( $order->get_view_order_url(), $row['order_page'] );
	}

	public function test_orders_list_and_get_only_the_customers_own_shop_orders(): void {
		list( $user, $tok ) = $this->reader();
		$mine  = wc_create_order( array( 'customer_id' => $user ) );
		$theirs = wc_create_order( array( 'customer_id' => self::factory()->user->create() ) );
		$item  = new \WC_Product_Simple();
		$item->set_name( 'Thing' );
		$item->set_regular_price( '9.00' );
		$item->save();
		$mine->add_product( $item, 2 );
		$mine->set_status( 'pending' );
		$mine->calculate_totals();
		$mine->save();
		$sub = $this->subscription( $user );

		$list = $this->json( $this->tool( 'list_orders', array(), $tok ) )['orders'];
		$this->assertSame( array( $mine->get_id() ), array_column( $list, 'id' ) );
		$this->assertTrue( $list[0]['needs_payment'] );
		$this->assertStringContainsString( 'Thing x 2', $list[0]['items'][0] );

		$one = $this->json( $this->tool( 'get_order', array( 'order_id' => $mine->get_id() ), $tok ) );
		$this->assertSame( 'Thing', $one['items'][0]['name'] );
		$this->assertSame( 2, $one['items'][0]['quantity'] );

		foreach ( array( $theirs->get_id(), $sub->get_id(), 999999 ) as $other ) {
			$this->assertTrue( $this->tool( 'get_order', array( 'order_id' => $other ), $tok )['isError'], (string) $other );
		}

		$this->assertTrue( $this->tool( 'list_orders', array( 'limit' => 21 ), $tok )['isError'] );
	}

	public function test_subscriptions_say_what_the_dashboard_offers_and_who_they_pay_for(): void {
		list( $user, $tok ) = $this->reader();
		$sub    = $this->subscription( $user, '12.00', false, 'active' );
		$held   = $this->subscription( $user, '5.00', true, 'on-hold' );
		$domain = $this->domain_paid_by( $user, $sub );
		$other  = $this->subscription( self::factory()->user->create() );

		$all = $this->json( $this->tool( 'list_subscriptions', array(), $tok ) )['subscriptions'];
		$ids = array_column( $all, 'id' );

		$this->assertContains( $sub->get_id(), $ids );
		$this->assertContains( $held->get_id(), $ids );
		$this->assertNotContains( $other->get_id(), $ids );

		$row = array_values( array_filter( $all, static fn( $s ) => $s['id'] === $sub->get_id() ) )[0];
		$this->assertSame( array( $domain->id() ), $row['services'] );
		$this->assertTrue( $row['renews_by_itself'] );
		$this->assertSame( array_keys( wcs_get_all_user_actions_for_subscription( $sub, $user ) ), $row['dashboard_offers'], 'The same oracle as the dashboard buttons.' );

		$only = $this->json( $this->tool( 'list_subscriptions', array( 'status' => 'on-hold' ), $tok ) )['subscriptions'];
		$this->assertSame( array( $held->get_id() ), array_column( $only, 'id' ) );
		$this->assertTrue( $this->tool( 'list_subscriptions', array( 'status' => 'bogus' ), $tok )['isError'] );
	}

	public function test_payment_methods_show_the_card_and_never_its_token(): void {
		list( $user, $tok ) = $this->reader();
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'pm_secret_token_value' );
		$token->set_gateway_id( 'bacs' );
		$token->set_user_id( $user );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '01' );
		$token->set_expiry_year( '2020' );
		$token->set_default( true );
		$token->save();

		$other = new \WC_Payment_Token_CC();
		$other->set_token( 'pm_someone_else' );
		$other->set_gateway_id( 'bacs' );
		$other->set_user_id( self::factory()->user->create() );
		$other->set_card_type( 'visa' );
		$other->set_last4( '1111' );
		$other->set_expiry_month( '12' );
		$other->set_expiry_year( '2099' );
		$other->save();

		$result = $this->tool( 'list_payment_methods', array(), $tok );
		$data   = $this->json( $result );

		$this->assertCount( 1, $data['payment_methods'] );
		$this->assertSame( 'Visa ending 4242', $data['payment_methods'][0]['name'] );
		$this->assertTrue( $data['payment_methods'][0]['default'] );
		$this->assertTrue( $data['payment_methods'][0]['expired'] );
		$this->assertSame( '01/2020', $data['payment_methods'][0]['expires'] );
		$this->assertStringNotContainsString( 'pm_secret_token_value', $this->text( $result ) );
		$this->assertStringNotContainsString( '1111', $this->text( $result ) );
	}

	public function test_get_account_has_the_customers_own_address_only(): void {
		list( $user, $tok ) = $this->reader();
		$customer = new \WC_Customer( $user );
		$customer->set_billing_first_name( 'Ann' );
		$customer->set_billing_address_1( 'Main Street 1' );
		$customer->set_billing_country( 'DK' );
		$customer->set_billing_city( 'Aarhus' );
		$customer->save();

		$data = $this->json( $this->tool( 'get_account', array(), $tok ) );

		$this->assertSame( 'Ann', $data['billing_address']['first_name'] );
		$this->assertSame( 'Aarhus', $data['billing_address']['city'] );
		$this->assertSame( 'DK', $data['billing_address']['country'] );
		$this->assertArrayHasKey( 'email', $data );
		$this->assertStringNotContainsString( 'user_pass', $this->text( $this->tool( 'get_account', array(), $tok ) ) );
	}

	public function test_the_catalogue_kinds(): void {
		list( , $tok ) = $this->reader();
		update_option( Prices::OPTION, array( 'com' => array( 'price' => 14, 'restricted' => false, 'create' => 8, 'renew' => 9, 'transfer' => 8 ), 'zz' => array( 'price' => 5, 'restricted' => true ) ) );

		$prices = $this->json( $this->tool( 'catalog', array( 'kind' => 'domain_prices' ), $tok ) );
		$this->assertContains( 'com', array_column( $prices['endings'], 'ending' ) );
		$this->assertNotContains( 'zz', array_column( $prices['endings'], 'ending' ), 'A restricted ending that is not on the allow-list is not sold.' );

		$this->assertIsArray( $this->json( $this->tool( 'catalog', array( 'kind' => 'server_plans' ), $tok ) )['plans'] );
		$this->assertIsArray( $this->json( $this->tool( 'catalog', array( 'kind' => 'database_plans' ), $tok ) )['plans'] );
		$this->assertTrue( $this->tool( 'catalog', array( 'kind' => 'os_and_apps' ), $tok )['isError'] );
		$this->assertTrue( $this->tool( 'catalog', array( 'kind' => 'os_and_apps', 'variation_id' => 99999 ), $tok )['isError'] );
		$this->assertTrue( $this->tool( 'catalog', array( 'kind' => 'bogus' ), $tok )['isError'] );
		$this->assertTrue( $this->tool( 'catalog', array( 'kind' => 'addons' ), $tok )['isError'], 'Add-ons need a service.' );

		$db = $this->database();
		$own = $this->oauth( $this->owner( $db ) );
		$this->assertArrayHasKey( 'backups', $this->json( $this->tool( 'catalog', array( 'kind' => 'addons', 'id' => $db->id() ), $own ) ) );
		$this->assertTrue( $this->tool( 'catalog', array( 'kind' => 'addons', 'id' => $db->id() ), $tok )['isError'] );
	}

	public function test_dns_claims_are_off_unless_the_site_offers_them(): void {
		list( , $tok ) = $this->reader();

		$this->assertTrue( $this->tool( 'list_dns_claims', array(), $tok )['isError'] );
	}

	public function test_licenses_and_their_keys_are_a_normal_read(): void {
		list( $user, $tok ) = $this->reader();
		$product = new \WC_Product_Simple();
		$product->set_name( 'Plugin' );
		$product->set_regular_price( '10' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'plugin-' . wp_generate_password( 5, false ) );
		$product->save();
		$license = \BeltoftLicenses\Licenses::create( array( 'product_id' => $product->get_id(), 'product_slug' => (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ), 'email' => 'x@example.org', 'user_id' => $user, 'max_activations' => 2, 'source' => 'order' ) );
		\BeltoftLicenses\Licenses::activate( $license, 'shop.example.org' );
		$service = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false, 'user_id' => $user ) );

		$list = $this->json( $this->tool( 'list_licenses', array(), $tok ) )['licenses'];
		$mine = array_values( array_filter( $list, static fn( $l ) => $l['id'] === (int) $license['id'] ) )[0];

		$this->assertSame( 2, $mine['sites']['allowed'] );
		$this->assertContains( 'shop.example.org', $mine['sites']['list'] );
		$this->assertStringNotContainsString( $license['license_key'], $this->text( $this->tool( 'list_licenses', array(), $tok ) ), 'The key is its own tool.' );

		$key = $this->json( $this->tool( 'get_license_key', array( 'license_id' => (int) $license['id'] ), $tok ) );
		$this->assertSame( $license['license_key'], $key['key'] );

		$this->assertTrue( $this->tool( 'get_license_key', array( 'license_id' => (int) $service['id'] ), $tok )['isError'] === true, 'A service license has no key to show.' );
		$this->assertTrue( $this->tool( 'get_license_key', array( 'license_id' => (int) $license['id'] ), $this->oauth( self::factory()->user->create() ) )['isError'] );
	}

	public function test_every_read_tool_works_with_a_read_connection_and_changes_nothing(): void {
		$db  = $this->database();
		$tok = $this->oauth( $this->owner( $db ) );
		$before = (int) $this->count_events( $db );

		foreach ( array( 'service_details', 'list_recovery_points', 'get_database_access' ) as $name ) {
			$this->assertFalse( $this->tool( $name, array( 'id' => $db->id() ), $tok )['isError'], $name );
		}

		// Reads leave no row in the history.
		$this->assertSame( $before, $this->count_events( $db ) );
		$this->assertFalse( \BeltoftServices\Jobs::acting( $db->id() ) );
		$this->assertSame( Tokens::READ, Tokens::level( 'read' ) );
	}

	private function count_events( Resource $r ): int {
		return count( Events::for_license( (int) $r->license()['id'] ) );
	}
}

<?php
/**
 * The everyday action tools: services, domains, subscriptions, licenses, orders, billing address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Jobs;
use BeltoftServices\Mcp\Audit;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Resource;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Action tool tests.
 */
final class ActsTest extends McpTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	/**
	 * A fake-driver server that offers the given actions.
	 *
	 * @param string[] $actions Action names.
	 */
	private function offering( array $actions ): Resource {
		$driver                   = $this->fake_driver();
		$driver->customer_actions = array_combine( $actions, $actions );
		$row                      = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false ) );

		return Resource::create( $row, $driver, array(), array( 'state' => 'ready' ) );
	}

	/**
	 * @return string[] The history of a service.
	 */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	public function test_every_action_tool_refuses_a_read_connection_and_changes_nothing(): void {
		$r    = $this->offering( array( 'rename', 'private_attach', 'private_detach', 'app_retry', 'lock', 'set_nameservers', 'check_delegation' ) );
		$user = $this->owner( $r );
		$read = $this->oauth( $user );

		foreach ( Tools::definitions() as $name => $tool ) {
			if ( Tokens::ACTIONS !== $tool['level'] ) {
				continue;
			}

			$result = $this->tool( $name, $this->dummy( $tool['schema'], $r->id() ), $read );

			$this->assertTrue( $result['isError'], $name );
			$this->assertStringContainsString( 'only read', $this->text( $result ), $name );
		}

		$this->assertEmpty( $this->history( $r ) );
		$this->assertFalse( Jobs::acting( $r->id() ) );
	}

	public function test_service_actions_are_queued_through_the_dashboard_route_and_logged(): void {
		$r     = $this->offering( array( 'rename', 'private_attach', 'private_detach', 'app_retry' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::ACTIONS );
		$calls = array(
			array( 'rename_server', array( 'id' => $r->id(), 'name' => 'web2' ), 'rename requested' ),
			array( 'private_network', array( 'id' => $r->id(), 'action' => 'attach' ), 'private network attach requested' ),
			array( 'private_network', array( 'id' => $r->id(), 'action' => 'detach' ), 'private network detach requested' ),
			array( 'retry_app_install', array( 'id' => $r->id() ), 'app install retry requested' ),
		);

		foreach ( $calls as $call ) {
			$this->assertTrue( $this->json( $this->tool( $call[0], $call[1], $token ) )['requested'], $call[0] );
			$this->assertContains( 'Claude: ' . $call[2], $this->history( $r ) );
		}

		// An action the service does not offer is refused by the dashboard's own check, and says so in the history.
		$plain = $this->offering( array( 'reboot' ) );
		$other = $this->oauth( $this->owner( $plain ), Tokens::ACTIONS );
		$this->assertTrue( $this->tool( 'rename_server', array( 'id' => $plain->id(), 'name' => 'x' ), $other )['isError'] );
		$this->assertContains( 'Claude: rename requested (refused)', $this->history( $plain ) );

		// Someone else's service is refused and nothing is written to its history.
		$thief = $this->oauth( self::factory()->user->create(), Tokens::ACTIONS );
		$this->assertTrue( $this->tool( 'rename_server', array( 'id' => $r->id(), 'name' => 'mine' ), $thief )['isError'] );
		$this->assertNotContains( 'Claude: rename requested (refused)', $this->history( $r ) );
	}

	public function test_domain_settings_runs_name_server_lock_and_delegation_through_the_domain_actions(): void {
		$user   = self::factory()->user->create();
		$domain = $this->domain( $user );
		$token  = $this->oauth( $user, Tokens::ACTIONS );

		// Custom name servers are for Full access and a confirmation; our own stay at actions.
		$custom = array( 'id' => $domain->id(), 'action' => 'set_nameservers', 'mode' => 'custom', 'hosts' => array( 'ns1.other.example', 'ns2.other.example' ) );
		$this->assertStringContainsString( 'Full access', $this->text( $this->tool( 'domain_settings', $custom, $token ) ) );
		$this->assertStringContainsString( 'confirmation', $this->text( $this->tool( 'domain_settings', $custom, $this->oauth( $user, Tokens::FULL ) ) ) );
		$this->assertFalse( Jobs::acting( $domain->id() ) );

		// The dashboard's own validation of the mode.
		$this->assertTrue( $this->tool( 'domain_settings', array( 'id' => $domain->id(), 'action' => 'set_nameservers' ), $token )['isError'], 'No mode.' );
		$this->assertFalse( Jobs::acting( $domain->id() ) );

		$ok = $this->json( $this->tool( 'domain_settings', array( 'id' => $domain->id(), 'action' => 'lock' ), $token ) );
		$this->assertTrue( $ok['requested'] );
		$this->assertTrue( Jobs::acting( $domain->id() ) );
		$this->assertContains( 'Claude: lock requested', $this->history( $domain ) );

		// Our own name servers stay an everyday action.
		$ours = $this->domain( $user, 'other.example' );
		$this->assertTrue( $this->json( $this->tool( 'domain_settings', array( 'id' => $ours->id(), 'action' => 'set_nameservers', 'mode' => 'ours' ), $token ) )['requested'] );
		$this->assertContains( 'Claude: set nameservers requested', $this->history( $ours ) );
	}

	public function test_set_database_access_adds_and_removes_addresses_and_cannot_open_to_everyone(): void {
		$db = $this->database();
		foreach ( array( '192.0.2.10', '192.0.2.11', '192.0.2.12' ) as $address ) {
			IpPool::add( $address );
		}

		$this->assertNotNull( IpPool::hold( $db->id() ) );
		$this->assertTrue( IpPool::assign( $db->id() ) );
		$plan = (array) Resource::find( $db->id() )->get( 'plan' );
		$db->set( array( 'plan' => array_merge( $plan, array( 'public_ip' => 1 ) ) ) );
		$token = $this->oauth( $this->owner( $db ), Tokens::ACTIONS );

		$add = $this->tool( 'set_database_access', array( 'id' => $db->id(), 'op' => 'add', 'address' => '203.0.113.7', 'label' => 'Office' ), $token );
		$this->assertFalse( $add['isError'], $this->text( $add ) );
		$this->assertContains( 'Claude: database access list: add', $this->history( $db ) );

		$list = $this->json( $this->tool( 'get_database_access', array( 'id' => $db->id() ), $token ) );
		$this->assertSame( array( '203.0.113.7' ), array_column( $list['entries'], 'address' ) );
		$this->assertSame( 'Office', $list['entries'][0]['label'] );

		// The route's own checks: private addresses and duplicates.
		$this->assertTrue( $this->tool( 'set_database_access', array( 'id' => $db->id(), 'op' => 'add', 'address' => '10.0.0.5' ), $token )['isError'] );
		$this->assertTrue( $this->tool( 'set_database_access', array( 'id' => $db->id(), 'op' => 'add', 'address' => '203.0.113.7' ), $token )['isError'] );

		// Opening to everyone is not a choice of the tool, at any level.
		foreach ( array( Tokens::ACTIONS, Tokens::FULL ) as $level ) {
			$result = $this->tool( 'set_database_access', array( 'id' => $db->id(), 'op' => 'world_on' ), $this->oauth( $this->owner( $db ), $level ) );
			$this->assertTrue( $result['isError'] );
		}

		$this->assertFalse( $this->json( $this->tool( 'get_database_access', array( 'id' => $db->id() ), $token ) )['open_to_everyone'] );

		$gone = $this->tool( 'set_database_access', array( 'id' => $db->id(), 'op' => 'remove', 'address' => '203.0.113.7' ), $token );
		$this->assertFalse( $gone['isError'], $this->text( $gone ) );
	}

	public function test_auto_renew_goes_through_the_shared_service_and_leaves_notes(): void {
		$user   = self::factory()->user->create();
		update_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle', 'yes' );
		$sub    = $this->subscription( $user, '12.00' );
		$domain = $this->domain_paid_by( $user, $sub );
		$token  = $this->oauth( $user, Tokens::ACTIONS );
		$this->assertFalse( (bool) wcs_get_subscription( $sub->get_id() )->is_manual() );

		$off = $this->tool( 'set_auto_renew', array( 'subscription_id' => $sub->get_id(), 'on' => false ), $token );
		$this->assertFalse( $off['isError'], $this->text( $off ) );
		$this->assertFalse( $this->json( $off )['renews_by_itself'] );
		$this->assertTrue( (bool) wcs_get_subscription( $sub->get_id() )->is_manual() );

		$notes = implode( ' ', array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) ) );
		$this->assertStringContainsString( 'turned off automatic renewals through Claude', $notes );
		$this->assertStringContainsString( 'Claude: auto-renew turned off', $notes );
		$this->assertContains( 'Claude: auto-renew turned off', $this->history( $domain ) );

		// The setting that hides the toggle in the dashboard hides it here too.
		update_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle', 'no' );
		$this->assertTrue( $this->tool( 'set_auto_renew', array( 'subscription_id' => $sub->get_id(), 'on' => true ), $token )['isError'] );
		update_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle', 'yes' );

		$other = $this->subscription( self::factory()->user->create() );
		$this->assertTrue( $this->tool( 'set_auto_renew', array( 'subscription_id' => $other->get_id(), 'on' => false ), $token )['isError'] );
		$this->assertFalse( (bool) wcs_get_subscription( $other->get_id() )->is_manual() );
		delete_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle' );
	}

	public function test_reactivate_does_only_what_the_dashboard_offers(): void {
		$user  = self::factory()->user->create();
		$held  = $this->subscription( $user, '5.00', true, 'on-hold' );
		$token = $this->oauth( $user, Tokens::ACTIONS );
		$offer = isset( wcs_get_all_user_actions_for_subscription( $held, $user )['reactivate'] );

		$result = $this->tool( 'reactivate_subscription', array( 'subscription_id' => $held->get_id() ), $token );

		$this->assertSame( ! $offer, $result['isError'] );
		$this->assertSame( $offer ? 'active' : 'on-hold', wcs_get_subscription( $held->get_id() )->get_status() );

		$active = $this->subscription( $user );
		$this->assertTrue( $this->tool( 'reactivate_subscription', array( 'subscription_id' => $active->get_id() ), $token )['isError'], 'Nothing to reactivate.' );

		$other = $this->subscription( self::factory()->user->create(), '5.00', true, 'on-hold' );
		$this->assertTrue( $this->tool( 'reactivate_subscription', array( 'subscription_id' => $other->get_id() ), $token )['isError'] );
		$this->assertSame( 'on-hold', wcs_get_subscription( $other->get_id() )->get_status() );
	}

	public function test_free_license_site_removes_a_site_of_the_customers_own_license(): void {
		$user    = self::factory()->user->create();
		$product = new \WC_Product_Simple();
		$product->set_name( 'Plugin' );
		$product->set_regular_price( '10' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'plugin-' . wp_generate_password( 5, false ) );
		$product->save();
		$license = \BeltoftLicenses\Licenses::create( array( 'product_id' => $product->get_id(), 'product_slug' => (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ), 'email' => 'x@example.org', 'user_id' => $user, 'max_activations' => 2, 'source' => 'order' ) );
		\BeltoftLicenses\Licenses::activate( $license, 'shop.example.org' );
		$token = $this->oauth( $user, Tokens::ACTIONS );

		$this->assertTrue( $this->tool( 'free_license_site', array( 'license_id' => (int) $license['id'], 'domain' => 'unknown.example.org' ), $token )['isError'] );
		$this->assertCount( 1, \BeltoftLicenses\Licenses::live_activations( (int) $license['id'], 'site' ) );

		$done = $this->tool( 'free_license_site', array( 'license_id' => (int) $license['id'], 'domain' => 'https://Shop.Example.org/' ), $token );
		$this->assertFalse( $done['isError'], $this->text( $done ) );
		$this->assertCount( 0, \BeltoftLicenses\Licenses::live_activations( (int) $license['id'], 'site' ) );
		$this->assertContains( 'Claude: site removed (shop.example.org)', array_column( Events::for_license( (int) $license['id'] ), 'message' ) );

		$thief = $this->oauth( self::factory()->user->create(), Tokens::ACTIONS );
		\BeltoftLicenses\Licenses::activate( $license, 'again.example.org' );
		$this->assertTrue( $this->tool( 'free_license_site', array( 'license_id' => (int) $license['id'], 'domain' => 'again.example.org' ), $thief )['isError'] );
		$this->assertCount( 1, \BeltoftLicenses\Licenses::live_activations( (int) $license['id'], 'site' ) );
	}

	public function test_cancel_unpaid_order_cancels_only_an_unpaid_order_of_the_customer(): void {
		$user    = self::factory()->user->create();
		$token   = $this->oauth( $user, Tokens::ACTIONS );
		$pending = wc_create_order( array( 'customer_id' => $user, 'status' => 'pending' ) );
		$paid    = wc_create_order( array( 'customer_id' => $user, 'status' => 'processing' ) );
		$theirs  = wc_create_order( array( 'customer_id' => self::factory()->user->create(), 'status' => 'pending' ) );

		$done = $this->tool( 'cancel_unpaid_order', array( 'order_id' => $pending->get_id() ), $token );
		$this->assertFalse( $done['isError'], $this->text( $done ) );
		$this->assertSame( 'cancelled', wc_get_order( $pending->get_id() )->get_status() );

		$this->assertTrue( $this->tool( 'cancel_unpaid_order', array( 'order_id' => $paid->get_id() ), $token )['isError'] );
		$this->assertSame( 'processing', wc_get_order( $paid->get_id() )->get_status() );
		$this->assertTrue( $this->tool( 'cancel_unpaid_order', array( 'order_id' => $theirs->get_id() ), $token )['isError'] );
		$this->assertSame( 'pending', wc_get_order( $theirs->get_id() )->get_status() );
		$this->assertTrue( $this->tool( 'cancel_unpaid_order', array( 'order_id' => $this->subscription( $user )->get_id() ), $token )['isError'], 'A subscription is not an order.' );
	}

	public function test_update_billing_address_validates_like_the_account_form_and_keeps_the_country(): void {
		$user     = self::factory()->user->create();
		$customer = new \WC_Customer( $user );
		$customer->set_billing_country( 'DK' );
		$customer->set_billing_first_name( 'Ann' );
		$customer->set_billing_city( 'Odense' );
		$customer->set_billing_postcode( '5000' );
		$customer->save();
		$token = $this->oauth( $user, Tokens::ACTIONS );

		$done = $this->tool( 'update_billing_address', array( 'city' => 'Aarhus', 'postcode' => '8000', 'address_1' => 'Main Street 1' ), $token );
		$this->assertFalse( $done['isError'], $this->text( $done ) );

		$fresh = new \WC_Customer( $user );
		$this->assertSame( 'Aarhus', $fresh->get_billing_city() );
		$this->assertSame( '8000', $fresh->get_billing_postcode() );
		$this->assertSame( 'Ann', $fresh->get_billing_first_name(), 'Fields not named stay.' );
		$this->assertSame( 'DK', $fresh->get_billing_country() );
		$this->assertStringContainsString( 'Claude: billing address changed (city: "Odense" to "Aarhus"; postcode: "5000" to "8000"; address_1: "" to "Main Street 1")', implode( ' ', array_column( Audit::lines( $user ), 'text' ) ) );

		// A bad postcode: nothing is saved.
		$bad = $this->tool( 'update_billing_address', array( 'city' => 'Elsewhere', 'postcode' => 'ZZZ-1' ), $token );
		$this->assertTrue( $bad['isError'] );
		$this->assertSame( 'Aarhus', ( new \WC_Customer( $user ) )->get_billing_city() );

		// A required field may not be emptied.
		$this->assertTrue( $this->tool( 'update_billing_address', array( 'first_name' => '' ), $token )['isError'] );
		$this->assertSame( 'Ann', ( new \WC_Customer( $user ) )->get_billing_first_name() );

		// The country, the VAT number and the e-mail are not arguments.
		foreach ( array( 'country', 'email', 'eu_vat_number', 'customer_type' ) as $key ) {
			$this->assertTrue( $this->tool( 'update_billing_address', array( $key => 'DE' ), $token )['isError'], $key );
		}

		$this->assertTrue( $this->tool( 'update_billing_address', array(), $token )['isError'], 'Nothing to change.' );
		$this->assertSame( 'DK', ( new \WC_Customer( $user ) )->get_billing_country() );
	}

	public function test_the_direct_action_tools_have_their_own_limit(): void {
		$user  = self::factory()->user->create();
		$token = $this->oauth( $user, Tokens::ACTIONS );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertFalse( 'Too many requests. Please wait a minute.' === $this->text( $this->tool( 'cancel_unpaid_order', array( 'order_id' => 999990 + $i ), $token ) ) );
		}

		$this->assertSame( 'Too many requests. Please wait a minute.', $this->text( $this->tool( 'cancel_unpaid_order', array( 'order_id' => 1 ), $token ) ) );
	}
}

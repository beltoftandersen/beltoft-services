<?php
/**
 * The tools of Full access that cannot be undone or change where money goes: each one's summary, what it runs, the
 * history it leaves, and what it refuses. The confirmation itself is in ConfirmationsTest.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Mcp\Audit;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Full access tool tests.
 */
final class FullToolsTest extends McpTestCase {

	private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDqjJLYRRTB9Z55AmrMBnnZqXMgIfW4QWnlAurKuKSeI test@example';

	/**
	 * @var FakeDriver
	 */
	private $driver;

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
		$this->driver                   = $this->fake_driver();
		$this->driver->customer_actions = array_combine( $actions, $actions );
		$row                            = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false ) );

		return Resource::create( $row, $this->driver, array(), array( 'state' => 'ready' ) );
	}

	/**
	 * @return string[]
	 */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	/**
	 * Asks for the summary and then confirms with its token.
	 *
	 * @param string               $name  Tool.
	 * @param array<string, mixed> $args  Arguments.
	 * @param string               $token Connection.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The summary and the result of the confirmed call.
	 */
	private function confirmed( string $name, array $args, string $token ): array {
		$first = $this->json( $this->tool( $name, $args, $token ) );
		$this->assertTrue( $first['requires_confirmation'], $name );

		return array( $first, $this->tool( $name, $args + array( 'confirm' => $first['confirm'] ), $token ) );
	}

	public function test_delete_and_roll_back_a_snapshot(): void {
		$r     = $this->offering( array( 'snapshot_delete', 'snapshot_rollback' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		$sum = $this->json( $this->tool( 'rollback_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ), $token ) );
		$this->assertStringContainsString( 'Everything that changed on the server after that snapshot is lost', $sum['summary'] );
		$this->assertStringContainsString( 'stopped while this runs', $sum['summary'] );

		list( , $done ) = $this->confirmed( 'rollback_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ), $token );
		$this->assertTrue( $this->json( $done )['requested'] );
		$this->run_jobs();
		$this->assertSame( array( 'snapshot_rollback', array( 'id' => 'abcd1234', 'confirm' => 'ROLLBACK' ) ), $this->driver->calls( 'act' )[0]['args'], 'The dashboard\'s typed word is supplied by the server.' );

		list( , $done ) = $this->confirmed( 'delete_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ), $token );
		$this->assertTrue( $this->json( $done )['requested'] );
		$this->run_jobs();
		$this->assertSame( array( 'snapshot_delete', array( 'id' => 'abcd1234' ) ), $this->driver->calls( 'act' )[1]['args'] );
		$this->assertContains( 'Claude: rollback snapshot confirmed by the customer', $this->history( $r ) );
		$this->assertContains( 'Claude: roll back to snapshot requested', $this->history( $r ) );

		// A snapshot id that is not one is refused by the schema, and a service of someone else's is not found.
		$this->assertTrue( $this->tool( 'delete_snapshot', array( 'id' => $r->id() ), $token )['isError'] );
		$thief = $this->oauth( self::factory()->user->create(), Tokens::FULL );
		$this->assertTrue( $this->tool( 'rollback_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ), $thief )['isError'] );
	}

	public function test_restore_a_backup_supplies_the_typed_word_and_names_the_server(): void {
		$r     = $this->offering( array( 'restore' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$args  = array( 'id' => $r->id(), 'backup' => 'backup:backup/vm/100/2026-10-01T00:00:00Z' );

		list( $sum, $done ) = $this->confirmed( 'restore_backup', $args, $token );
		$this->assertStringContainsString( 'Restore server', $sum['summary'] );
		$this->assertStringContainsString( 'replaces everything on the server', $sum['summary'] );
		$this->assertStringContainsString( 'down until it has finished', $sum['summary'] );
		$this->assertTrue( $this->json( $done )['requested'] );
		$this->run_jobs();
		$this->assertSame( array( 'restore', array( 'volid' => $args['backup'], 'confirm' => 'RESTORE' ) ), $this->driver->calls( 'act' )[0]['args'] );
		$this->assertContains( 'Claude: restore from backup requested', $this->history( $r ) );
	}

	public function test_reinstall_maps_the_choices_to_the_dashboards_arguments(): void {
		$r     = $this->offering( array( 'reinstall' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$args  = array( 'id' => $r->id(), 'os' => 7, 'app' => 'none' );

		list( $sum, $done ) = $this->confirmed( 'reinstall_server', $args, $token );
		$this->assertStringContainsString( 'Erase server', $sum['summary'] );
		$this->assertStringContainsString( 'Everything on the server is lost', $sum['summary'] );
		$this->assertStringContainsString( 'once an hour', $sum['summary'] );
		$this->assertTrue( $this->json( $done )['requested'] );
		$this->run_jobs();
		$this->assertSame( array( 'reinstall', array( 'os' => '7', 'app_version' => 'none', 'confirm' => 'REINSTALL' ) ), $this->driver->calls( 'act' )[0]['args'] );
	}

	public function test_a_reinstall_that_needs_a_paid_licence_returns_where_to_finish_it_and_no_link_that_cannot_work(): void {
		$r     = $this->offering( array( 'reinstall' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		add_filter( 'beltoft_services_action_redirect', static fn( $url, $res, $action ) => 'reinstall' === $action ? 'https://shop.example/checkout/?add-to-cart=9&beltoft_services_applic_nonce=abc123' : $url, 10, 3 );

		list( , $done ) = $this->confirmed( 'reinstall_server', array( 'id' => $r->id(), 'app' => 'wordpress/6.8' ), $token );
		$out            = $this->json( $done );

		$this->assertFalse( $out['requested'] );
		$this->assertTrue( $out['licence_needed'] );
		$this->assertStringContainsString( 'Nothing was erased', $out['message'] );
		$this->assertStringNotContainsString( 'abc123', $this->text( $done ), 'A nonce made for a browser session is not handed out.' );
		$this->assertStringNotContainsString( 'add-to-cart', $this->text( $done ) );
		$this->run_jobs();
		$this->assertSame( array(), $this->driver->calls( 'act' ) );
	}

	public function test_replace_ssh_key_checks_the_key_and_says_what_it_does(): void {
		$r     = $this->offering( array( 'replace_ssh_key' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		$bad = $this->tool( 'replace_ssh_key', array( 'id' => $r->id(), 'key' => 'not a key' ), $token );
		$this->assertTrue( $bad['isError'] );
		$this->assertSame( 'text', $bad['content'][0]['type'] );
		$this->assertStringNotContainsString( 'confirm', $this->text( $bad ), 'No token for a key that would be refused.' );

		list( $sum, $done ) = $this->confirmed( 'replace_ssh_key', array( 'id' => $r->id(), 'key' => self::KEY ), $token );
		$this->assertStringContainsString( 'ssh-ed25519 key ending', $sum['summary'] );
		$this->assertStringContainsString( 'cannot log in over SSH', $sum['summary'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $sum ), 'Only the end of the key is shown.' );
		$this->assertTrue( $this->json( $done )['requested'] );
		$this->run_jobs();
		$this->assertSame( 'replace_ssh_key', $this->driver->calls( 'act' )[0]['args'][0] );
	}

	public function test_reset_the_firewall_and_the_server_password_return_nothing_secret(): void {
		$r     = $this->offering( array( 'fw_reset', 'reset_password' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		list( $sum, $done ) = $this->confirmed( 'reset_firewall', array( 'id' => $r->id() ), $token );
		$this->assertStringContainsString( 'default rules', $sum['summary'] );
		$this->assertTrue( $this->json( $done )['requested'] );

		$this->run_jobs();
		list( $sum, $done ) = $this->confirmed( 'reset_server_password', array( 'id' => $r->id() ), $token );
		$this->assertStringContainsString( 'old password stops working at once', $sum['summary'] );
		$out = $this->json( $done );
		$this->assertTrue( $out['requested'] );
		$this->assertStringContainsString( 'not shown here', $out['message'] );
		$this->run_jobs();
		$this->assertSame( array( 'fw_reset', 'reset_password' ), array_map( static fn( $c ) => $c['args'][0], $this->driver->calls( 'act' ) ) );
	}

	public function test_the_memory_full_policy_of_a_valkey_database(): void {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'valkey', 'major' => 8, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$db      = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array() );
		$db->set( array( 'state' => 'ready', 'desired' => 'ready', 'db_state' => 'ready', 'db_address' => '10.64.0.2' ) );
		$token = $this->oauth( $this->owner( $db ), Tokens::FULL );

		$bad = $this->tool( 'set_database_eviction_policy', array( 'id' => $db->id(), 'policy' => 'everything; reboot' ), $token );
		$this->assertTrue( $bad['isError'], 'A setting that is not on the database\'s list gets no token.' );

		list( $sum, $done ) = $this->confirmed( 'set_database_eviction_policy', array( 'id' => $db->id(), 'policy' => 'allkeys-lru' ), $token );
		$this->assertStringContainsString( 'Remove the least recently used keys', $sum['summary'] );
		$this->assertStringContainsString( 'It is "Refuse new writes" now', $sum['summary'] );
		$this->assertTrue( $this->json( $done )['requested'], $this->text( $done ) );
		$this->assertTrue( Jobs::acting( $db->id() ) );
	}

	public function test_cancel_a_subscription_says_price_vat_and_end_and_cancels_as_the_dashboard_does(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, '12.00', true );
		$row  = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$r    = Resource::create( $row, $this->fake_driver(), array(), array( 'state' => 'ready' ) );
		$tok  = $this->oauth( $user, Tokens::FULL );

		list( $sum, $done ) = $this->confirmed( 'cancel_subscription', array( 'subscription_id' => $sub->get_id() ), $tok );
		$this->assertStringContainsString( 'It costs', $sum['summary'] );
		$this->assertStringContainsString( 'every year', $sum['summary'] );
		$this->assertMatchesRegularExpression( '/no VAT is charged|VAT included/', $sum['summary'] );
		$this->assertStringContainsString( 'It is paid until', $sum['summary'] );

		$out = $this->json( $done );
		$this->assertSame( 'pending-cancel', $out['status'] );
		$this->assertTrue( wcs_get_subscription( $sub->get_id() )->has_status( 'pending-cancel' ) );
		$this->assertContains( 'Claude: subscription cancelled', $this->history( $r ) );
		$this->assertContains( 'Claude: cancel subscription confirmed by the customer', $this->history( $r ) );
		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) );
		$this->assertContains( 'Claude: subscription cancelled', $notes );

		// Someone else's subscription, and one that cannot be cancelled any more.
		$thief = $this->oauth( self::factory()->user->create(), Tokens::FULL );
		$this->assertTrue( $this->tool( 'cancel_subscription', array( 'subscription_id' => $sub->get_id() ), $thief )['isError'] );
		$this->assertTrue( $this->tool( 'cancel_subscription', array( 'subscription_id' => $sub->get_id() ), $tok )['isError'], 'Already ending.' );
		$this->assertTrue( $this->tool( 'cancel_subscription', array( 'subscription_id' => 99999999 ), $tok )['isError'] );
	}

	public function test_a_domain_registration_is_never_cancelled_through_a_connected_app(): void {
		$user   = self::factory()->user->create();
		$sub    = $this->subscription( $user, '12.00', true );
		$domain = $this->domain_paid_by( $user, $sub );
		$tok    = $this->oauth( $user, Tokens::FULL );

		$result = $this->tool( 'cancel_subscription', array( 'subscription_id' => $sub->get_id() ), $tok );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'registered domain', $this->text( $result ) );
		$this->assertStringContainsString( 'set_auto_renew', $this->text( $result ) );
		$this->assertTrue( wcs_get_subscription( $sub->get_id() )->has_status( 'active' ) );

		$this->assertTrue( $this->tool( 'remove_addon', array( 'id' => $domain->id() ), $tok )['isError'], 'Not an add-on.' );
	}

	public function test_remove_an_addon_cancels_its_subscription_and_only_an_addons(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, '5.00', true );
		$row  = $this->service_license( $this->service_product( 'proxmox-disk', array(), array( 'slug' => 'disk-' . wp_generate_password( 4, false ) ) ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		$disk = Resource::create( $row, Registry::get( 'proxmox-disk' ), array(), array( 'state' => 'ready', 'size_gb' => 50 ) );
		$tok  = $this->oauth( $user, Tokens::FULL );

		list( $sum, $done ) = $this->confirmed( 'remove_addon', array( 'id' => $disk->id() ), $tok );
		$this->assertStringContainsString( 'Extra disk 50 GB', $sum['summary'] );
		$this->assertStringContainsString( 'The extra disk and its data are removed', $sum['summary'] );
		$this->assertStringContainsString( 'snapshots that include it are deleted', $sum['summary'] );
		$this->assertSame( 'pending-cancel', $this->json( $done )['status'] );
		$this->assertContains( 'Claude: add-on removed', $this->history( $disk ) );

		// A server is not an add-on: it is cancelled with cancel_subscription, which says what happens to the data.
		$server = $this->server( $user );
		$this->assertTrue( $this->tool( 'remove_addon', array( 'id' => $server->id() ), $tok )['isError'] );
		$this->assertStringContainsString( 'cancel_subscription', $this->text( $this->tool( 'remove_addon', array( 'id' => $server->id() ), $tok ) ) );
	}

	/**
	 * A saved card of a customer.
	 */
	private function card( int $user, string $last4, string $year = '2040', bool $default = false ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_secret_' . $last4 );
		$token->set_gateway_id( 'bacs' );
		$token->set_card_type( 'visa' );
		$token->set_last4( $last4 );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( $year );
		$token->set_user_id( $user );
		$token->set_default( $default );
		$token->save();

		return $token;
	}

	public function test_set_the_default_card_only_among_the_customers_own_saved_cards(): void {
		$user  = self::factory()->user->create();
		$old   = $this->card( $user, '1111', '2040', true );
		$new   = $this->card( $user, '4242' );
		$tok   = $this->oauth( $user, Tokens::FULL );
		$other = $this->card( self::factory()->user->create(), '9999' );

		list( $sum, $done ) = $this->confirmed( 'set_default_payment_method', array( 'token_id' => $new->get_id() ), $tok );
		$this->assertStringContainsString( 'Visa ending 4242', $sum['summary'] );
		$this->assertStringContainsString( 'Visa ending 1111', $sum['summary'] );
		$this->assertStringNotContainsString( 'tok_secret', wp_json_encode( array( $sum, $done ) ), 'The gateway\'s token is never shown.' );
		$this->assertTrue( $this->json( $done )['default'] );
		$this->assertSame( $new->get_id(), \WC_Payment_Tokens::get_customer_default_token( $user )->get_id() );
		$this->assertContains( 'Claude: default payment method set to Visa ending 4242', array_column( Audit::lines( $user ), 'text' ) );

		// Not another customer's, not the one that already is the default, not an expired one, not one that does not exist.
		$this->assertTrue( $this->tool( 'set_default_payment_method', array( 'token_id' => $other->get_id() ), $tok )['isError'] );
		$this->assertTrue( $this->tool( 'set_default_payment_method', array( 'token_id' => $new->get_id() ), $tok )['isError'] );
		$old_one = $this->card( $user, '0001', '2020' );
		$this->assertStringContainsString( 'expired', $this->text( $this->tool( 'set_default_payment_method', array( 'token_id' => $old_one->get_id() ), $tok ) ) );
		$this->assertTrue( $this->tool( 'set_default_payment_method', array( 'token_id' => 99999999 ), $tok )['isError'] );
		$this->assertSame( $new->get_id(), \WC_Payment_Tokens::get_customer_default_token( $user )->get_id() );
		$this->assertNotSame( $old->get_id(), $new->get_id() );
	}

	public function test_a_database_is_opened_to_everyone_only_after_the_confirmation(): void {
		$db = $this->database();

		foreach ( array( '192.0.2.10', '192.0.2.11' ) as $address ) {
			IpPool::add( $address );
		}

		$this->assertNotNull( IpPool::hold( $db->id() ) );
		$this->assertTrue( IpPool::assign( $db->id() ) );
		$db->set( array( 'plan' => array_merge( (array) Resource::find( $db->id() )->get( 'plan' ), array( 'public_ip' => 1 ) ) ) );
		$tok = $this->oauth( $this->owner( $db ), Tokens::FULL );

		$args  = array( 'id' => $db->id(), 'op' => 'world_on' );
		$sum   = $this->json( $this->tool( 'set_database_access', $args, $tok ) );
		$this->assertStringContainsString( 'Anyone on the internet can then try to log in', $sum['summary'] );
		$this->assertFalse( $this->json( $this->tool( 'get_database_access', array( 'id' => $db->id() ), $tok ) )['open_to_everyone'], 'A summary alone does not open it.' );
		$this->assertFalse( Jobs::acting( $db->id() ) );

		$done = $this->tool( 'set_database_access', $args + array( 'confirm' => $sum['confirm'] ), $tok );
		$this->assertFalse( $done['isError'], $this->text( $done ) );
		$this->assertTrue( $this->json( $this->tool( 'get_database_access', array( 'id' => $db->id() ), $tok ) )['open_to_everyone'], 'The typed phrase of the dashboard was supplied by the server.' );
		$this->assertContains( 'Claude: database access list: world on', $this->history( $db ) );
	}

	public function test_custom_name_servers_are_set_only_after_the_confirmation(): void {
		$user   = self::factory()->user->create();
		$domain = $this->domain( $user );
		$tok    = $this->oauth( $user, Tokens::FULL );
		$args   = array( 'id' => $domain->id(), 'action' => 'set_nameservers', 'mode' => 'custom', 'hosts' => array( 'ns1.other.example', 'ns2.other.example' ) );

		$first = $this->json( $this->tool( 'domain_settings', $args, $tok ) );
		$this->assertStringContainsString( 'ns1.other.example, ns2.other.example', $first['summary'] );
		$this->assertStringContainsString( 'go offline', $first['summary'] );
		$this->assertFalse( Jobs::acting( $domain->id() ) );

		$done = $this->tool( 'domain_settings', $args + array( 'confirm' => $first['confirm'] ), $tok );
		$this->assertFalse( $done['isError'], $this->text( $done ) );
		$this->assertTrue( Jobs::acting( $domain->id() ) );

		// Our own name servers stay an everyday action with no confirmation at any level.
		$ours = $this->domain( $user, 'other.example' );
		$this->assertTrue( $this->json( $this->tool( 'domain_settings', array( 'id' => $ours->id(), 'action' => 'set_nameservers', 'mode' => 'ours' ), $tok ) )['requested'] );
	}

	public function test_a_summary_never_holds_a_password_a_link_with_a_nonce_or_a_dash(): void {
		$r     = $this->offering( array( 'snapshot_delete', 'snapshot_rollback', 'restore', 'reinstall', 'replace_ssh_key', 'fw_reset', 'reset_password' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$texts = array();

		foreach ( array(
			array( 'delete_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ) ),
			array( 'rollback_snapshot', array( 'id' => $r->id(), 'snapshot' => 'abcd1234' ) ),
			array( 'restore_backup', array( 'id' => $r->id(), 'backup' => 'backup:backup/vm/100/x' ) ),
			array( 'reinstall_server', array( 'id' => $r->id() ) ),
			array( 'reset_firewall', array( 'id' => $r->id() ) ),
			array( 'reset_server_password', array( 'id' => $r->id() ) ),
		) as $call ) {
			$texts[] = $this->text( $this->tool( $call[0], $call[1], $token ) );
		}

		foreach ( $texts as $text ) {
			$this->assertStringNotContainsString( '—', $text );
			$this->assertStringNotContainsString( '_wpnonce', $text );
			$this->assertStringNotContainsString( 'wcsnonce', $text );
			$this->assertDoesNotMatchRegularExpression( '#https?://#', $text );
		}
	}
}

<?php
/**
 * delete_server and delete_database: Full access, a confirmation that names what is deleted, the refusals, and the same flow as the dashboard.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mcp\Pricing;
use BeltoftServices\Resource;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Delete tools.
 */
final class DeleteToolsTest extends McpTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	private function usage( Resource $r ): Resource {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'source' => 'usage' ), array( 'id' => (int) $r->license()['id'] ) );
		$this->email_to( $r );

		return Resource::find( $r->id() );
	}

	private function vm( int $user ): Resource {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox' ), array(), array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $r );

		return $this->usage( $r );
	}

	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	public function test_the_tools_are_full_access_only_and_need_a_confirmation_that_is_not_idempotent(): void {
		foreach ( array( 'delete_server', 'delete_database' ) as $name ) {
			$tool = \BeltoftServices\Mcp\Tools::definitions()[ $name ];
			$this->assertSame( Tokens::FULL, $tool['level'], $name );
			$this->assertTrue( $tool['confirm'], $name );
			$this->assertTrue( $tool['destructive'], $name );
			$this->assertFalse( $tool['idempotent'], $name );
		}

		$user = self::factory()->user->create();
		$db   = $this->usage( $this->database( $user ) );

		foreach ( array( Tokens::READ, Tokens::ACTIONS ) as $level ) {
			$this->assertTrue( $this->tool( 'delete_database', array( 'id' => $db->id() ), $this->oauth( $user, $level ) )['isError'], $level );
		}

		$this->assertSame( 'ready', Resource::find( $db->id() )->get( 'desired' ) );
	}

	public function test_delete_database_says_what_goes_and_when_billing_stops_then_deletes_as_the_dashboard_does(): void {
		$user  = self::factory()->user->create();
		$db    = $this->usage( $this->database( $user ) );
		$other = $this->usage( $this->database( $user ) );
		$token = $this->oauth( $user, Tokens::FULL );

		$first = $this->json( $this->tool( 'delete_database', array( 'id' => $db->id() ), $token ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertStringContainsString( 'all its data, including backups', $first['summary'] );
		$this->assertStringContainsString( 'cannot be undone', $first['summary'] );
		$this->assertStringContainsString( 'Billing stops in the hour it is deleted', $first['summary'] );
		$this->assertStringContainsString( 'The database is billed every hour it exists', $first['summary'] );
		$this->assertSame( 'ready', Resource::find( $db->id() )->get( 'desired' ), 'Nothing is deleted before the confirmation.' );

		$done = $this->tool( 'delete_database', array( 'id' => $db->id(), 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $this->json( $done )['deleting'] );

		$fresh = Resource::find( $db->id() );
		$this->assertSame( 'deleted', $fresh->get( 'desired' ) );
		$this->assertSame( '', (string) $fresh->get( 'delete_origin' ) );
		$this->assertSame( Licenses::STATUS_REVOKED, Licenses::find( (int) $db->license()['id'] )['status'] );
		$this->assertContains( 'Customer deleted the service', $this->history( $db ) );
		$this->assertContains( 'Claude: delete database confirmed by the customer', $this->history( $db ) );
		$this->assertSame( 'ready', Resource::find( $other->id() )->get( 'desired' ) );

		$again = $this->tool( 'delete_database', array( 'id' => $db->id(), 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $again['isError'], 'A confirmation works once.' );
		$this->assertTrue( $this->tool( 'delete_database', array( 'id' => $db->id() ), $token )['isError'], 'Already being deleted.' );
	}

	public function test_delete_server_and_the_refusals(): void {
		$user   = self::factory()->user->create();
		$server = $this->vm( $user );
		$db     = $this->usage( $this->database( $user ) );
		$token  = $this->oauth( $user, Tokens::FULL );

		$first = $this->json( $this->tool( 'delete_server', array( 'id' => $server->id() ), $token ) );
		$this->assertStringContainsString( 'all its data, including snapshots and backups', $first['summary'] );
		$this->assertStringContainsString( 'A stopped server is still billed', $first['summary'] );

		// The wrong kind says which tool to use; somebody else's, a monthly one and a protected one are refused with no token made.
		$kind = $this->tool( 'delete_server', array( 'id' => $db->id() ), $token );
		$this->assertTrue( $kind['isError'] );
		$this->assertStringContainsString( 'delete_database', $this->text( $kind ) );
		$this->assertTrue( $this->tool( 'delete_server', array( 'id' => $server->id() ), $this->oauth( self::factory()->user->create(), Tokens::FULL ) )['isError'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'source' => 'order' ), array( 'id' => (int) $server->license()['id'] ) );
		$this->assertTrue( $this->tool( 'delete_server', array( 'id' => $server->id() ), $token )['isError'], 'A monthly server is not deleted here.' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'source' => 'usage' ), array( 'id' => (int) $server->license()['id'] ) );

		$server->set( array( 'protected' => true ) );
		$protected = $this->tool( 'delete_server', array( 'id' => $server->id() ), $token );
		$this->assertTrue( $protected['isError'] );
		$this->assertStringContainsString( 'protected', $this->text( $protected ) );
		$server->set( array( 'protected' => false ) );

		$done = $this->tool( 'delete_server', array( 'id' => $server->id(), 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $this->json( $done )['deleting'], $this->text( $done ) );
		$this->assertSame( 'deleted', Resource::find( $server->id() )->get( 'desired' ) );
	}

	public function test_the_price_note_states_the_billing_rule(): void {
		$note = Pricing::note();
		$this->assertStringContainsString( 'An hour that has started counts as a whole hour', $note );
		$this->assertStringContainsString( 'a stopped server is still billed until it is deleted', $note );
	}
}

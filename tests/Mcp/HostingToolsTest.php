<?php
/**
 * The MCP tools of WordPress hosting, over the fake node and the fake agent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

require_once dirname( __DIR__ ) . '/Hosting/HostingCase.php';
require_once __DIR__ . '/CallsTools.php';

use BeltoftServices\Api\Tokens;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Hosting\HostingCase;

/**
 * Hosting tools: the levels, the dashboard's own checks, the confirmation and what they run.
 */
final class HostingToolsTest extends HostingCase {

	use CallsTools;

	public function set_up(): void {
		parent::set_up();
		$this->mcp_set_up();

		// One key for the node secrets and the MCP alike.
		remove_all_filters( 'beltoft_services_key' );
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );
		$this->save_node();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		$this->mcp_tear_down();
		parent::tear_down();
	}

	private function account(): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100, 'backup_days' => 14 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function user( Resource $r ): int {
		return (int) $r->license()['user_id'];
	}

	public function test_the_levels_of_the_hosting_tools(): void {
		$defs = Tools::definitions();

		foreach ( array( 'hosting_info', 'hosting_usage', 'list_hosting_backups' ) as $name ) {
			$this->assertSame( Tokens::READ, $defs[ $name ]['level'], $name );
		}

		foreach ( array( 'add_site_domain', 'set_php_version', 'clear_site_cache', 'create_hosting_backup' ) as $name ) {
			$this->assertSame( Tokens::ACTIONS, $defs[ $name ]['level'], $name );
			$this->assertFalse( $defs[ $name ]['confirm'], $name );
		}

		foreach ( array( 'restore_hosting_backup', 'delete_site' ) as $name ) {
			$this->assertSame( Tokens::FULL, $defs[ $name ]['level'], $name );
			$this->assertTrue( $defs[ $name ]['confirm'], $name );
		}
	}

	public function test_reads_and_everyday_actions(): void {
		$r     = $this->account();
		$id    = $r->id();
		$first = 'w' . $id;
		( new HostingActions( $r ) )->add_site( 'blog', 'Blog', '8.3', false );
		$token = $this->oauth( $this->user( $r ), Tokens::ACTIONS );

		$info = $this->json( $this->tool( 'hosting_info', array( 'id' => $id ), $token ) );
		$this->assertCount( 2, $info['sites'] );

		$usage = $this->json( $this->tool( 'hosting_usage', array( 'id' => $id ), $token ) );
		$this->assertArrayHasKey( 'storage_percent', $usage );
		$this->assertSame( 10, $usage['storage_limit_gb'] );

		$list = $this->json( $this->tool( 'list_hosting_backups', array( 'id' => $id ), $token ) );
		$this->assertSame( array(), $list['backups'] );

		$domain = $this->json( $this->tool( 'add_site_domain', array( 'id' => $id, 'site' => 'blog', 'domain' => 'www.example.org' ), $token ) );
		$this->assertSame( 'www.example.org', $domain['added'] );
		$this->assertNotNull( SiteDomains::find( 'www.example.org' ) );

		$php = $this->json( $this->tool( 'set_php_version', array( 'id' => $id, 'site' => 'blog', 'php' => '8.4', 'memory_mb' => 256 ), $token ) );
		$this->assertIsArray( $php );
		$this->assertSame( '8.4', Sites::find( $id, 'blog' )['php'] );

		$over = $this->tool( 'set_php_version', array( 'id' => $id, 'site' => 'blog', 'php' => '8.4', 'memory_mb' => 4096 ), $token );
		$this->assertTrue( $over['isError'], 'The plan ceiling holds for a tool as for the dashboard.' );

		$cache = $this->json( $this->tool( 'clear_site_cache', array( 'id' => $id, 'site' => $first ), $token ) );
		$this->assertSame( $first, $cache['cleared'] );

		$backup = $this->json( $this->tool( 'create_hosting_backup', array( 'id' => $id ), $token ) );
		$this->assertTrue( $backup['started'] );
	}

	public function test_a_stranger_and_a_read_token_are_refused(): void {
		$r        = $this->account();
		$stranger = $this->oauth( self::factory()->user->create(), Tokens::ACTIONS );
		$this->assertTrue( $this->tool( 'hosting_info', array( 'id' => $r->id() ), $stranger )['isError'] );
		$this->assertTrue( $this->tool( 'create_hosting_backup', array( 'id' => $r->id() ), $stranger )['isError'] );

		$read = $this->oauth( $this->user( $r ), Tokens::READ );
		$this->assertFalse( $this->tool( 'hosting_info', array( 'id' => $r->id() ), $read )['isError'] );
		$this->assertTrue( $this->tool( 'add_site_domain', array( 'id' => $r->id(), 'site' => 'w' . $r->id(), 'domain' => 'a.example.org' ), $read )['isError'] );
	}

	public function test_delete_site_summarises_confirms_and_keeps_the_last_one(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_site( 'blog', 'Blog', '8.3', false );
		$token = $this->oauth( $this->user( $r ), Tokens::FULL );

		$first = $this->json( $this->tool( 'delete_site', array( 'id' => $r->id(), 'site' => 'blog' ), $token ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertStringContainsString( 'for good', $first['summary'] );
		$this->assertNotNull( Sites::find( $r->id(), 'blog' ), 'Nothing is deleted before the confirmation.' );

		$done = $this->json( $this->tool( 'delete_site', array( 'id' => $r->id(), 'site' => 'blog', 'confirm' => $first['confirm'] ), $token ) );
		$this->assertSame( 'blog', $done['deleted'] );
		$this->assertNull( Sites::find( $r->id(), 'blog' ) );

		$last = $this->tool( 'delete_site', array( 'id' => $r->id(), 'site' => 'w' . $r->id() ), $token );
		$this->assertTrue( $last['isError'], 'The last website stays.' );
		$this->assertTrue( $this->tool( 'delete_site', array( 'id' => $r->id(), 'site' => 'ghost' ), $token )['isError'] );
	}

	public function test_restore_names_a_listed_backup_confirms_and_starts_the_restore(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_site( 'blog', 'Blog', '8.3', false );
		$this->assertTrue( ( new HostingBackups( Resource::find( $r->id() ) ) )->start( 'manual' ) );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$volid = HostingBackups::stored( Resource::find( $r->id() ) )[0]['volid'];
		$token = $this->oauth( $this->user( $r ), Tokens::FULL );

		$list = $this->json( $this->tool( 'list_hosting_backups', array( 'id' => $r->id() ), $token ) );
		$this->assertSame( $volid, $list['backups'][0]['volid'] );

		$this->assertTrue( $this->tool( 'restore_hosting_backup', array( 'id' => $r->id(), 'scope' => 'account', 'backup' => 'pbs-store:backup/ct/1/x' ), $token )['isError'], 'Only a listed backup.' );
		$this->assertTrue( $this->tool( 'restore_hosting_backup', array( 'id' => $r->id(), 'scope' => 'files', 'backup' => $volid ), $token )['isError'], 'A website must be named for files.' );

		$args  = array( 'id' => $r->id(), 'scope' => 'files', 'backup' => $volid, 'site' => 'blog' );
		$first = $this->json( $this->tool( 'restore_hosting_backup', $args, $token ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertStringContainsString( 'safety copy', $first['summary'] );
		$this->assertFalse( HostingRestore::active( Resource::find( $r->id() ) ) );

		$done = $this->json( $this->tool( 'restore_hosting_backup', $args + array( 'confirm' => $first['confirm'] ), $token ) );
		$this->assertTrue( $done['requested'] );
		$this->assertTrue( HostingRestore::active( Resource::find( $r->id() ) ) );
	}
}

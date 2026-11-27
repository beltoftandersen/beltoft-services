<?php
/**
 * The "Connect to your database" box of the Access tab: the visitor's address against the allow-list, the host and the ready command
 * per engine, the IPv6-only line and its template. Pure: no database, no Proxmox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Db;

use BeltoftServices\Proxmox\Db\DbConnect;
use BeltoftServices\Proxmox\Db\DbEngine;
use BeltoftServices\Proxmox\Db\DbEngines;
use BeltoftServices\Proxmox\Db\MssqlEngine;
use BeltoftServices\Proxmox\Db\PostgresEngine;
use BeltoftServices\Proxmox\Db\ValkeyEngine;
use BeltoftServices\Tests\TestCase;

final class DbConnectTest extends TestCase {

	private const V6 = '2a01:4f8:1:2::10';

	/** @param array<string, mixed> $in */
	private function box( DbEngine $engine, array $in = array() ): array {
		return DbConnect::build( $engine, $in + array( 'address' => '', 'address6' => self::V6, 'my_ip' => '2001:db8::5', 'entries' => array(), 'world' => false, 'editable' => true, 'private_host' => '10.64.0.2', 'private_string' => 'x' ) );
	}

	public function test_the_visitors_address_is_allowed_missing_open_unreachable_or_unknown(): void {
		$e = new PostgresEngine();

		$this->assertSame( 'missing', $this->box( $e )['state'] );
		$this->assertTrue( $this->box( $e )['can_allow'], 'One click adds the address.' );
		$this->assertFalse( $this->box( $e, array( 'editable' => false ) )['can_allow'], 'A stopped database or a change in progress: no button.' );
		$this->assertSame( 'allowed', $this->box( $e, array( 'entries' => array( '2001:db8::/64' ) ) )['state'], 'A range covers the address.' );
		$this->assertSame( 'allowed', $this->box( $e, array( 'entries' => array( '2001:db8::5' ) ) )['state'] );
		$this->assertSame( 'open', $this->box( $e, array( 'world' => true ) )['state'] );
		$this->assertFalse( $this->box( $e, array( 'world' => true ) )['can_allow'] );
		$this->assertSame( 'unknown', $this->box( $e, array( 'my_ip' => '' ) )['state'] );

		// A visitor on IPv4 and a database with IPv6 only: no address of the visitor's kind can connect.
		$v4 = $this->box( $e, array( 'my_ip' => '203.0.113.9' ) );
		$this->assertSame( 'unreachable', $v4['state'] );
		$this->assertFalse( $v4['can_allow'] );
		$this->assertSame( 4, $v4['my_family'] );

		$this->assertSame( 'missing', $this->box( $e, array( 'my_ip' => '203.0.113.9', 'address' => '192.0.2.8' ) )['state'] );
		$this->assertSame( 'allowed', $this->box( $e, array( 'my_ip' => '203.0.113.9', 'address' => '192.0.2.8', 'entries' => array( '203.0.113.0/24' ) ) )['state'] );
	}

	public function test_the_host_follows_the_visitors_kind_and_the_ipv6_only_flag_follows_the_addresses(): void {
		$e = new PostgresEngine();

		$both = $this->box( $e, array( 'address' => '192.0.2.8' ) );
		$this->assertSame( self::V6, $both['host'] );
		$this->assertFalse( $both['ipv6_only'] );
		$this->assertCount( 2, $both['hosts'] );
		$this->assertSame( '192.0.2.8', $this->box( $e, array( 'address' => '192.0.2.8', 'my_ip' => '203.0.113.9' ) )['host'] );

		$only = $this->box( $e );
		$this->assertTrue( $only['ipv6_only'] );
		$this->assertCount( 1, $only['hosts'] );
	}

	public function test_every_engine_has_a_ready_command_with_its_tls_settings_and_a_bracketed_ipv6_url(): void {
		$pg = new PostgresEngine();
		$this->assertSame( 'psql "postgresql://app_user:PASSWORD@[' . self::V6 . ']:5432/' . $pg->database() . '?sslmode=verify-full&sslrootcert=ca.crt"', $pg->command( self::V6 ) );
		$this->assertStringContainsString( '@192.0.2.8:5432/', $pg->command( '192.0.2.8' ) );

		$valkey = new ValkeyEngine();
		$this->assertSame( 'valkey-cli --tls --cacert ca.crt -h ' . self::V6 . ' -p 6380 --user app_user --pass PASSWORD', $valkey->command( self::V6 ), 'The client takes the address as it is.' );
		$this->assertStringContainsString( 'rediss://app_user:PASSWORD@[' . self::V6 . ']:6380', $valkey->connections( self::V6 )[0]['value'] );

		$ms = new MssqlEngine();
		$this->assertSame( 'sqlcmd -S 192.0.2.8,1433 -d ' . $ms->database() . ' -U ' . $ms->login() . ' -P PASSWORD -N', $ms->command( '192.0.2.8' ) );

		$this->assertSame( '[::1]', DbEngines::uri_host( '::1' ) );
		$this->assertSame( '[::1]', DbEngines::uri_host( '[::1]' ) );
		$this->assertSame( '10.0.0.1', DbEngines::uri_host( '10.0.0.1' ) );
	}

	public function test_the_box_prints_the_steps_the_details_and_one_button(): void {
		$connect = $this->box( new ValkeyEngine(), array( 'add_url' => '/checkout/?add=1', 'add_price' => '5 EUR a month', 'private_string' => 'rediss://app_user:PASSWORD@10.64.0.2:6380' ) );
		$view    = array( 'ca_url' => '/ca' );
		$link    = static fn( string $slug ): string => '/db/' . $slug;

		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/db-connect.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Connect to your database', $html );
		$this->assertSame( 4, substr_count( $html, '<li>' ) - substr_count( $html, '<li><' ) );
		$this->assertStringContainsString( 'data-db-access-allow="2001:db8::5"', $html );
		$this->assertStringContainsString( 'Allow my address', $html );
		$this->assertStringContainsString( 'This database has an IPv6 address only.', $html );
		$this->assertStringContainsString( 'test-ipv6.com', $html );
		$this->assertStringContainsString( 'href="/checkout/?add=1"', $html );
		$this->assertStringContainsString( 'buy an IPv4 address (5 EUR a month)', $html );
		$this->assertStringContainsString( '<code>6380</code>', $html );
		$this->assertStringContainsString( '<code>app_user</code>', $html );
		$this->assertStringContainsString( 'valkey-cli --tls --cacert ca.crt -h ' . self::V6, $html );
		$this->assertStringContainsString( 'href="/db/password"', $html );
		$this->assertStringContainsString( 'href="/db/network"', $html );
		$this->assertStringContainsString( '10.64.0.2', $html );
		$this->assertSame( substr_count( $html, 'data-db-copy' ), substr_count( $html, 'data-copied' ), 'Every copy button says what it did.' );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
	}

	public function test_an_allowed_address_has_no_button_and_a_database_with_ipv4_has_no_ipv6_line(): void {
		$connect = $this->box( new PostgresEngine(), array( 'address' => '192.0.2.8', 'entries' => array( '2001:db8::5' ) ) );
		$view    = array( 'ca_url' => '' );
		$link    = static fn( string $slug ): string => '';

		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/db-connect.php';
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-db-access-allow', $html );
		$this->assertStringContainsString( 'Your address is allowed', $html );
		$this->assertStringNotContainsString( 'IPv6 address only', $html );
		$this->assertStringContainsString( 'The database has an IPv6 and an IPv4 address.', $html );
	}

	public function test_the_server_line_says_ipv6_only_and_links_the_add_on(): void {
		$ipv6_only = array( 'kind' => 'server', 'add_url' => '/checkout/?add=2', 'add_price' => '', 'adding' => false, 'sold_out' => false );

		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/ipv6-only.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'This server has an IPv6 address only. To connect over SSH your own connection needs IPv6. Test it at <a href="https://test-ipv6.com/"', $html );
		$this->assertStringContainsString( 'The browser console under Console always works.', $html );
		$this->assertStringContainsString( 'Or <a href="/checkout/?add=2" data-ipv6-add>buy an IPv4 address</a>.', $html );

		$ipv6_only['add_url'] = '';
		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/ipv6-only.php';
		$this->assertStringNotContainsString( 'Or <a', (string) ob_get_clean(), 'No link when no address can be added.' );
	}

	public function test_the_check_button_is_printed_only_with_a_test_page(): void {
		$ipv6_only = array( 'kind' => 'database', 'add_url' => '/c', 'add_price' => '', 'adding' => false, 'sold_out' => false );

		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/ipv6-only.php';
		$this->assertStringNotContainsString( 'data-ipv6-check', (string) ob_get_clean() );

		$this->settings( array( 'ipv6_test_url' => 'https://ipv6test.example.test/ping' ) );
		delete_transient( 'beltoft_services_ipv6_test_aaaa_' . md5( 'ipv6test.example.test' ) );
		add_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_true' );
		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/ipv6-only.php';
		$html = (string) ob_get_clean();
		remove_filter( 'beltoft_services_ipv6_test_has_aaaa', '__return_true' );

		$this->assertStringContainsString( '>Check my connection</button>', $html );
		$this->assertStringContainsString( 'data-none="Your connection has no IPv6. Buy an IPv4 address, or connect from your own server over the private network."', $html );
		$this->assertStringContainsString( 'data-ipv6-result', $html );
	}
}

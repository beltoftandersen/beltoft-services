<?php
/**
 * Helper for refactors of the MCP ordering and pricing tools that must not change behaviour (companion of McpDumpTest, same
 * SURFACE_DUMP=<directory>).
 *
 * Writes mcp-money.txt: the catalog, the plans, every price_quote, the billing and usage summaries, and the first step (the
 * summary and the refusals, nothing is ordered) of order_server and order_database for each kind of choice, for a customer with
 * a usage account and for one without, with ids, times and confirm values normalised. FakeClient only; nothing is charged.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';
require_once dirname( __DIR__ ) . '/Mcp/CallsTools.php';
require_once dirname( __DIR__ ) . '/Mcp/DatabaseFixture.php';
require_once dirname( __DIR__ ) . '/Mcp/ServerDiskFixture.php';
require_once dirname( __DIR__ ) . '/Support/Ipv4Product.php';

/**
 * Not an assertion test.
 */
final class MoneyDumpTest extends UsageTestCase {

	use \BeltoftServices\Tests\Mcp\CallsTools;
	use \BeltoftServices\Tests\Mcp\DatabaseFixture;
	use \BeltoftServices\Tests\Mcp\ServerDiskFixture;
	use \BeltoftServices\Tests\Support\Ipv4Product;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		$this->seed_private_nets();
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
			$order->delete( true );
		}

		$this->mcp_tear_down();
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	/**
	 * @param string $text Text.
	 * @param array  $ids  Value => label.
	 */
	private function normal( string $text, array $ids ): string {
		$text = preg_replace( '/VPS [A-Za-z0-9]{5}\b/', 'VPS NAME', $text );
		$text = preg_replace( '/"confirm":\s*"[^"]*"/', '"confirm":"TOKEN"', $text );
		$text = preg_replace( '/\b1[5-9]\d{8}\b/', 'TIMESTAMP', (string) $text );

		return preg_replace_callback(
			'/("(?:[a-z_]*id|variation_id|plan)":\s*"?|[?&]plan=)(\d+)/',
			static fn( $m ) => $m[1] . ( $ids[ (int) $m[2] ] ?? $m[2] ),
			(string) $text
		);
	}

	public function test_dump(): void {
		$dir = (string) getenv( 'SURFACE_DUMP' );

		if ( '' === $dir ) {
			$this->markTestSkipped( 'Only runs with SURFACE_DUMP=<directory>.' );
		}

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$user    = $this->new_user();
		$account = $this->usage_account( $user );
		$this->save_cluster( array( 'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'default_template' => '9000' ) );
		$server  = $this->usage_product();
		$monthly = $this->monthly_vps();
		$this->capacity( 4000 );
		$db   = $this->database_product();
		$disk = $this->disk_product();
		$this->ipv4_product();

		for ( $i = 1; $i <= 4; $i++ ) {
			\BeltoftServices\Proxmox\Network\IpPool::add( '198.51.100.' . $i );
		}

		$this->settings( array( 'usage_new_limit' => 0, 'db_disk_max_gb' => 1000, 'db_disk_step_gb' => 10, 'disk_max_extra' => 3, 'disk_reserve_gb' => 0, 'gift_credit' => 1 ) );
		Offers::reset();

		foreach ( array( 'billing_first_name' => 'Test', 'billing_last_name' => 'Customer', 'billing_email' => self::MAIL, 'billing_phone' => '+4512345678', 'billing_address_1' => 'Teststreet 1', 'billing_city' => 'Aarhus', 'billing_postcode' => '8000', 'billing_country' => 'DK' ) as $k => $v ) {
			update_user_meta( $user, $k, $v );
		}

		$plain = $this->new_user();
		$ids   = array(
			$user                              => 'USER',
			$plain                             => 'PLAIN',
			$account->get_id()                 => 'ACCOUNT',
			(int) $server['u-None']->get_id()  => 'SERVER_PLAN',
			(int) $server['u-Daily']->get_id() => 'SERVER_PLAN_DAILY',
			(int) $db['u-0-0']->get_id()       => 'DB_PLAN',
			(int) $db['m-0-0']->get_id()       => 'DB_PLAN_MONTHLY',
			(int) $monthly['m-None']->get_id() => 'SERVER_PLAN_MONTHLY',
		);
		krsort( $ids );

		$full  = $this->oauth( $user, Tokens::FULL );
		$read  = $this->oauth( $user, Tokens::READ );
		$other = $this->oauth( $plain, Tokens::FULL );
		$sp    = (int) $server['u-None']->get_id();
		$dp    = (int) $db['u-0-0']->get_id();
		$out   = '';
		$put   = function ( string $label, string $token, string $tool, array $args ) use ( &$out, $ids ): void {
			$result = $this->tool( $tool, $args, $token );
			$out   .= $label . ' ' . $tool . ' ' . $this->normal( (string) wp_json_encode( $args ), $ids ) . ( ! empty( $result['isError'] ) ? ' ERROR ' : ' ' ) . $this->normal( $this->text( $result ), $ids ) . "\n";
		};

		$reads = array(
			array( 'catalog', array( 'kind' => 'server_plans' ) ),
			array( 'catalog', array( 'kind' => 'database_plans' ) ),
			array( 'catalog', array( 'kind' => 'os_and_apps', 'variation_id' => $sp ) ),
			array( 'catalog', array( 'kind' => 'os_and_apps' ) ),
			array( 'catalog', array( 'kind' => 'addons' ) ),
			array( 'catalog', array( 'kind' => 'addons', 'id' => 999999 ) ),
			array( 'catalog', array( 'kind' => 'domain_prices' ) ),
			array( 'list_server_plans', array() ),
			array( 'billing_summary', array() ),
			array( 'usage_summary', array() ),
		);

		foreach ( $reads as $call ) {
			$put( 'read', $read, $call[0], $call[1] );
		}

		$quotes = array(
			array( 'plan' => $sp ),
			array( 'plan' => $sp, 'backups' => true ),
			array( 'plan' => $sp, 'extra_disk_gb' => 50 ),
			array( 'plan' => $sp, 'extra_disk_gb' => 100, 'backups' => true ),
			array( 'plan' => $sp, 'extra_disk_gb' => 70 ),
			array( 'plan' => $sp, 'public_ipv4' => true ),
			array( 'plan' => $sp, 'extra_storage_gb' => 50 ),
			array( 'plan' => $dp ),
			array( 'plan' => $dp, 'extra_storage_gb' => 50 ),
			array( 'plan' => $dp, 'extra_storage_gb' => 7 ),
			array( 'plan' => $dp, 'public_ipv4' => true ),
			array( 'plan' => $dp, 'public_ipv4' => true, 'extra_storage_gb' => 50 ),
			array( 'plan' => $dp, 'backups' => true ),
			array( 'plan' => $dp, 'extra_disk_gb' => 50 ),
			array( 'plan' => 999999 ),
			array( 'plan' => (int) $monthly['m-None']->get_id() ),
		);

		foreach ( $quotes as $args ) {
			$put( 'quote', $read, 'price_quote', $args );
		}

		$key    = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDqjJLYRRTB9Z55AmrMBnnZqXMgIfW4QWnlAurKuKSeI test@example';
		$orders = array(
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => '9001', 'server_name' => 'web-one' ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => '9001', 'server_name' => 'web-one', 'ssh_key' => $key ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => '9000' ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => 'Ubuntu 24.04', 'backups' => true ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => '9001', 'extra_disk_gb' => 50 ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => '9001', 'extra_disk_gb' => 70 ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => '1234' ) ),
			array( 'order_server', array( 'plan' => (string) $sp ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'app' => 'docker/1', 'os' => '9001' ) ),
			array( 'order_server', array( 'plan' => (string) $monthly['m-None']->get_id(), 'os' => '9001' ) ),
			array( 'order_server', array( 'plan' => '999999', 'os' => '9001' ) ),
			array( 'order_server', array( 'plan' => (string) $sp, 'os' => '9001', 'confirm' => 'bsc_wrong' ) ),
			array( 'order_database', array( 'plan' => (string) $dp ) ),
			array( 'order_database', array( 'plan' => (string) $dp, 'extra_storage_gb' => 50 ) ),
			array( 'order_database', array( 'plan' => (string) $dp, 'public_ipv4' => true ) ),
			array( 'order_database', array( 'plan' => (string) $dp, 'public_ipv4' => true, 'extra_storage_gb' => 50 ) ),
			array( 'order_database', array( 'plan' => (string) $dp, 'extra_storage_gb' => 7 ) ),
			array( 'order_database', array( 'plan' => (string) $db['m-0-0']->get_id() ) ),
			array( 'order_database', array( 'plan' => '999999' ) ),
			array( 'order_database', array( 'plan' => (string) $dp, 'confirm' => 'bsc_wrong' ) ),
		);

		foreach ( $orders as $call ) {
			$put( 'order-account', $full, $call[0], $call[1] );
			$put( 'order-noaccount', $other, $call[0], $call[1] );
			$put( 'order-readtoken', $read, $call[0], $call[1] );
		}

		// A hidden plan.
		$server['u-None']->set_status( 'private' );
		$server['u-None']->save();
		Offers::reset();
		$put( 'hidden', $read, 'list_server_plans', array() );
		$put( 'hidden', $full, 'order_server', array( 'plan' => (string) $sp, 'os' => '9001' ) );

		$this->assertNotFalse( file_put_contents( rtrim( $dir, '/' ) . '/mcp-money.txt', $out ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertNotEmpty( $disk );
	}
}

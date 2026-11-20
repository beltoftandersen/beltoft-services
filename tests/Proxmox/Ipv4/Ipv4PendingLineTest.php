<?php
/**
 * A kept address line of the new server (Domains\PendingLines, after a stale session write) comes back only when its billing is
 * the one of the server in the cart and the server can take one more; checkout refuses a line of the other billing.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftServices\Proxmox\Addon\AddonCart;
use BeltoftServices\Proxmox\Backup\BackupField;
use BeltoftServices\Proxmox\Ipv4\Ipv4Cart;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Tests\Support\Ipv4Product;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__, 2 ) . '/Usage/UsageTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/Ipv4Product.php';

final class Ipv4PendingLineTest extends UsageTestCase {

	use Ipv4Product;

	/** @var array<string, \WC_Product_Variation> */
	private $v = array();

	/** @var array<string, \WC_Product_Variation> */
	private $ip = array();

	public function set_up(): void {
		parent::set_up();
		$this->v  = $this->usage_product();
		$this->ip = $this->ipv4_product();
		Offers::reset();
		WC()->cart->empty_cart();
		add_filter( 'beltoft_services_ipv4_max_per_server', static fn() => 2 );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		Offers::reset();
		parent::tear_down();
	}

	private function server( string $key ): void {
		$server = $this->v[ $key ];
		$this->assertNotFalse( WC()->cart->add_to_cart( $server->get_parent_id(), 1, $server->get_id(), BackupField::attributes( $server ) ) );
	}

	/** A kept address line as the session stores it. */
	private function item( string $billing ): array {
		$v = $this->ip[ $billing ];

		return array( 'product_id' => $v->get_parent_id(), 'variation_id' => $v->get_id(), 'quantity' => 1, 'beltoft_services_ipv4_for' => 'cart_server', 'beltoft_services_ipv4_uid' => wp_generate_uuid4() );
	}

	private function put( array $item ): void {
		$contents                    = WC()->cart->get_cart_contents();
		$contents[ md5( wp_json_encode( $item ) ) ] = $item;
		WC()->cart->set_cart_contents( $contents );
	}

	public function test_a_kept_line_of_the_other_billing_is_not_put_back(): void {
		$this->server( 'u-None' );
		$this->assertTrue( AddonCart::pending_can_put_back( null, $this->item( 'u' ) ) );
		$this->assertFalse( AddonCart::pending_can_put_back( null, $this->item( 'm' ) ), 'A monthly address for a pay as you go server.' );
	}

	public function test_a_kept_line_beyond_the_limit_is_not_put_back(): void {
		$this->server( 'u-None' );
		$this->put( $this->item( 'u' ) );
		$this->assertTrue( AddonCart::pending_can_put_back( null, $this->item( 'u' ) ) );
		$this->put( $this->item( 'u' ) );
		$this->assertFalse( AddonCart::pending_can_put_back( null, $this->item( 'u' ) ) );
	}

	public function test_checkout_refuses_a_line_of_the_other_billing(): void {
		$this->server( 'u-None' );
		$this->put( $this->item( 'm' ) );
		$errors = new \WP_Error();
		Ipv4Cart::checkout_validation( array(), $errors );
		$this->assertContains( 'ipv4_billing', $errors->get_error_codes() );
	}

	public function test_the_billing_swap_is_announced_and_each_feature_follows_in_order(): void {
		$this->assertSame( 10, has_action( 'beltoft_services_billing_swapped', array( \BeltoftServices\Apps\AppLicenceCart::class, 'rematch' ) ) );
		$this->assertSame( 20, has_action( 'beltoft_services_billing_swapped', array( \BeltoftServices\Proxmox\Disk\DiskField::class, 'rematch' ) ) );
		$this->assertSame( 30, has_action( 'beltoft_services_billing_swapped', array( \BeltoftServices\Proxmox\Ipv4\Ipv4Field::class, 'rematch' ) ) );

		$this->server( 'm-None' );
		$fired = 0;
		add_action( 'beltoft_services_billing_swapped', static function () use ( &$fired ): void { ++$fired; }, 40 );
		$this->assertTrue( \BeltoftServices\Usage\BillingField::swap( true )['ok'] );
		$this->assertSame( 1, $fired );
	}

	public function test_checkout_reads_the_target_once_however_many_lines(): void {
		add_filter( 'beltoft_services_ipv4_max_per_server', static fn() => 5 );
		$this->server( 'u-None' );

		foreach ( range( 1, 3 ) as $ignore ) {
			$this->put( $this->item( 'u' ) );
		}

		$reads = 0;
		$count = static function ( $max ) use ( &$reads ) {
			++$reads;
			return $max;
		};
		add_filter( 'beltoft_services_ipv4_max_per_server', $count, 99 );
		Ipv4Cart::checkout_validation( array(), new \WP_Error() );
		remove_filter( 'beltoft_services_ipv4_max_per_server', $count, 99 );

		$this->assertSame( 1, $reads, 'The target scans the cart: once per validation, not per line.' );
	}

	public function test_the_add_on_product_is_looked_up_once_per_request_and_again_after_a_save(): void {
		AddonCart::reset_products();
		$queries = 0;
		$count   = static function ( $posts, $query ) use ( &$queries ) {
			if ( 'product' === $query->get( 'post_type' ) && '' !== (string) $query->get( 'meta_key' ) ) {
				++$queries;
			}

			return $posts;
		};
		add_filter( 'posts_pre_query', $count, 10, 2 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertNotNull( Ipv4Cart::product( true ) );
			$this->assertNotNull( Ipv4Cart::product( false ) );
		}

		$this->assertSame( 2, $queries, 'One lookup per billing.' );
		$this->ip['u']->set_regular_price( '0' );
		$this->ip['u']->save();
		Ipv4Cart::product( true );
		$this->assertSame( 3, $queries, 'A saved product starts the lookup again.' );
		remove_filter( 'posts_pre_query', $count, 10 );
	}
}

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
}

<?php
/**
 * The public cart companion answer (CartCompanion::keeps) and the features that give it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\CartCompanion;
use BeltoftServices\Hosting\HostingDriver;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Tests\Support\FakeDriver;
use BeltoftServices\Usage\Account;

/**
 * Built from Addon::all(): a kind added to the model is tested here without a change.
 */
class CartCompanionTest extends TestCase {

	/** A line shaped like a cart line. */
	private function line( int $product_id, array $data = array() ): array {
		return array_merge( $data, array( 'product_id' => $product_id ) );
	}

	/** A plain product id tied to a fake driver of this id (or none). */
	private function product_id( string $driver = '' ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'P ' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->save();
		if ( '' !== $driver ) {
			$fake = new FakeDriver( $driver );
			add_filter(
				'beltoft_services_drivers',
				static function ( $drivers ) use ( $fake ) {
					$drivers[ $fake->id() ] = $fake;
					return $drivers;
				}
			);
			update_post_meta( $product->get_id(), Product::META_DRIVER, $driver );
		}
		return $product->get_id();
	}

	public function test_the_model_has_kinds_to_test(): void {
		$this->assertNotEmpty( Addon::all() );
		$this->assertContains( true, array_map( static fn( Addon $a ) => $a->accepts_cart_server, Addon::all() ), 'At least one kind is bought with the server of the cart.' );
	}

	public function test_every_add_on_kind_that_accepts_the_cart_server_stays_beside_it(): void {
		$server = $this->line( $this->product_id() );
		foreach ( Addon::all() as $a ) {
			$new = $this->line( $this->product_id(), array( $a->cart_for() => 'cart_server' ) );
			$this->assertSame( $a->accepts_cart_server, CartCompanion::keeps( $server, $new ), $a->kind . ': a new line for cart_server' );
		}
	}

	public function test_an_add_on_line_for_an_existing_server_is_a_purchase_of_its_own(): void {
		$other = $this->line( $this->product_id() );
		foreach ( Addon::all() as $a ) {
			$new = $this->line( $this->product_id(), array( $a->cart_for() => 123 ) );
			$this->assertFalse( CartCompanion::keeps( $other, $new ), $a->kind );
		}
	}

	public function test_an_existing_add_on_line_does_not_survive_a_plain_new_product(): void {
		$plain = $this->line( $this->product_id() );
		foreach ( Addon::all() as $a ) {
			$old = $this->line( $this->product_id(), array( $a->cart_for() => 'cart_server' ) );
			$this->assertFalse( CartCompanion::keeps( $old, $plain ), $a->kind );
		}
	}

	public function test_a_plain_second_product_is_not_a_companion(): void {
		$this->assertFalse( CartCompanion::keeps( $this->line( $this->product_id() ), $this->line( $this->product_id() ) ) );
	}

	public function test_the_usage_account_line_stays_in_both_directions(): void {
		$usage = $this->product_id();
		$main  = $this->line( $this->product_id() );
		$this->assertFalse( CartCompanion::keeps( $this->line( $usage ), $main ), 'Not set yet.' );
		update_option( Account::OPTION_PRODUCT, $usage );
		$this->assertTrue( CartCompanion::keeps( $this->line( $usage ), $main ), 'A new purchase keeps the usage account.' );
		$this->assertTrue( CartCompanion::keeps( $main, $this->line( $usage ) ), 'Adding the usage account removes nothing.' );
	}

	public function test_a_domain_and_a_mail_or_hosting_plan_sit_together_in_both_directions(): void {
		$domain = array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'register' ) );
		foreach ( array( MailDriver::ID, HostingDriver::ID ) as $driver ) {
			$service = $this->line( $this->product_id( $driver ) );
			$d       = $this->line( $this->product_id(), $domain );
			$this->assertTrue( CartCompanion::keeps( $service, $d ), $driver . ': new domain keeps the service' );
			$this->assertTrue( CartCompanion::keeps( $d, $service ), $driver . ': new service keeps the domain' );
			$this->assertFalse( CartCompanion::keeps( $d, $this->line( $this->product_id( $driver ), $domain ) ), $driver . ': a domain line is never the service' );
			$this->assertFalse( CartCompanion::keeps( $this->line( $this->product_id() ), $service ), $driver . ': an ordinary product is not kept' );
			$this->assertFalse( CartCompanion::keeps( $service, $this->line( $this->product_id( $driver ) ) ), $driver . ': a service does not keep another service' );
		}
	}

	public function test_a_new_domain_does_not_keep_the_old_domain(): void {
		$domain = array( 'beltoft_services_domain' => array( 'domain' => 'a.example', 'mode' => 'register' ) );
		$this->assertFalse( CartCompanion::keeps( $this->line( $this->product_id(), $domain ), $this->line( $this->product_id(), $domain ) ) );
	}
}

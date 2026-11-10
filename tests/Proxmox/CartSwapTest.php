<?php
/**
 * The checkout add-ons of a new server (backups, extra disk) swap the cart one after the other without losing a choice.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\BackupField;
use BeltoftServices\Proxmox\CartSwap;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Tests\Mcp\ServerDiskFixture;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';
require_once dirname( __DIR__ ) . '/Mcp/ServerDiskFixture.php';

/**
 * CartSwap.
 */
final class CartSwapTest extends UsageTestCase {

	use ServerDiskFixture;

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $v = array();

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $disk = array();

	public function set_up(): void {
		parent::set_up();

		$this->settings( array( 'offer_monthly' => 1, 'usage_new_limit' => 0, 'disk_max_extra' => 3, 'disk_reserve_gb' => 0, 'disk_promise_percent' => 100 ) );
		$this->v = $this->usage_product();
		$this->capacity( 4000 );
		$this->disk = $this->disk_product();
		update_option( 'woocommerce_currency', 'EUR' );
		Offers::reset();
		wp_set_current_user( $this->new_user() );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	private function cart_with( \WC_Product_Variation $v ): void {
		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), BackupField::attributes( $v ) ) );
		WC()->session->save_data(); // As a finished request would: a swap starts from the saved session.
	}

	/**
	 * @return array{server: int, disk: int, lines: int}
	 */
	private function seen(): array {
		$out = array( 'server' => 0, 'disk' => 0, 'lines' => 0 );

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['beltoft_services_disk_for'] ) ) {
				$out['disk'] = (int) $item['variation_id'];
				++$out['lines'];
			} elseif ( (int) $item['product_id'] === $this->v['m-None']->get_parent_id() ) {
				$out['server'] = (int) $item['variation_id'];
				++$out['lines'];
			}
		}

		return $out;
	}

	public function test_backups_then_disk_keeps_both_and_so_does_the_other_order(): void {
		$this->cart_with( $this->v['u-None'] );
		$this->assertTrue( CartSwap::apply( true, null )['ok'] );
		$answer = CartSwap::apply( true, $this->disk['u-50']->get_id() );
		$this->assertTrue( $answer['ok'] );
		$this->assertSame( array( 'server' => $this->v['u-Daily']->get_id(), 'disk' => $this->disk['u-50']->get_id(), 'lines' => 2 ), $this->seen() );
		$this->assertTrue( $answer['with'] );
		$this->assertSame( $this->disk['u-50']->get_id(), $answer['disk'] );

		$this->cart_with( $this->v['u-None'] );
		$this->assertTrue( CartSwap::apply( null, $this->disk['u-100']->get_id() )['ok'] );
		$this->assertTrue( CartSwap::apply( true, $this->disk['u-100']->get_id() )['ok'] );
		$this->assertSame( array( 'server' => $this->v['u-Daily']->get_id(), 'disk' => $this->disk['u-100']->get_id(), 'lines' => 2 ), $this->seen() );
	}

	public function test_a_swap_that_sends_both_choices_fixes_a_cart_that_lacks_one(): void {
		$this->cart_with( $this->v['u-None'] );
		$this->assertTrue( DiskField::choose( $this->disk['u-50']->get_id() ) );

		$this->assertTrue( CartSwap::apply( true, $this->disk['u-50']->get_id() )['ok'] );

		$this->assertSame( array( 'server' => $this->v['u-Daily']->get_id(), 'disk' => $this->disk['u-50']->get_id(), 'lines' => 2 ), $this->seen(), 'A resent disk is not added twice or dropped.' );
	}

	public function test_the_billing_stays_right_when_the_add_ons_swap_after_a_billing_swap(): void {
		$this->cart_with( $this->v['m-None'] );
		$this->assertTrue( CartSwap::apply( true, $this->disk['m-50']->get_id() )['ok'] );
		$this->assertSame( array( 'server' => $this->v['m-Daily']->get_id(), 'disk' => $this->disk['m-50']->get_id(), 'lines' => 2 ), $this->seen() );

		$this->assertTrue( \BeltoftServices\Usage\BillingField::swap( true )['ok'] );
		WC()->session->save_data();
		$this->assertSame( array( 'server' => $this->v['u-Daily']->get_id(), 'disk' => $this->disk['u-50']->get_id(), 'lines' => $this->seen()['lines'] ), $this->seen() );

		$this->assertTrue( CartSwap::apply( false, $this->disk['u-100']->get_id() )['ok'] );
		$seen = $this->seen();
		$this->assertSame( $this->v['u-None']->get_id(), $seen['server'], 'Still pay as you go.' );
		$this->assertSame( $this->disk['u-100']->get_id(), $seen['disk'] );
	}

	public function test_an_invalid_combination_is_refused_and_changes_nothing(): void {
		$this->cart_with( $this->v['u-None'] );

		$answer = CartSwap::apply( true, $this->disk['m-50']->get_id() );

		$this->assertFalse( $answer['ok'], 'A monthly size does not go with a pay as you go server.' );
		$this->assertSame( array( 'server' => $this->v['u-None']->get_id(), 'disk' => 0, 'lines' => 1 ), $this->seen(), 'The backups were not switched on either.' );
		$this->assertFalse( $answer['with'], 'The reply says what the cart holds.' );

		$this->assertFalse( CartSwap::apply( null, 999999 )['ok'] );

		WC()->cart->empty_cart();
		WC()->session->save_data();
		$this->assertFalse( CartSwap::apply( true, null )['ok'], 'No server in the cart.' );
	}

	public function test_the_endpoints_apply_both_choices_whichever_one_is_posted(): void {
		$this->cart_with( $this->v['u-None'] );
		$_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce( BackupField::NONCE );
		$_POST['with']  = '1';
		$_POST['disk']  = (string) $this->disk['u-50']->get_id();
		$answer         = BackupField::respond();

		$this->assertSame( 200, $answer[1] );
		$this->assertSame( array( 'server' => $this->v['u-Daily']->get_id(), 'disk' => $this->disk['u-50']->get_id() ), array_intersect_key( $this->seen(), array( 'server' => 1, 'disk' => 1 ) ) );

		// A post that names only one choice leaves the other as it is.
		unset( $_POST['with'] );
		$_POST['disk'] = (string) $this->disk['u-100']->get_id();
		$this->assertSame( 200, BackupField::respond()[1] );
		$this->assertSame( array( 'server' => $this->v['u-Daily']->get_id(), 'disk' => $this->disk['u-100']->get_id() ), array_intersect_key( $this->seen(), array( 'server' => 1, 'disk' => 1 ) ) );

		$_POST['disk'] = '0';
		$_POST['with'] = '0';
		$this->assertSame( 200, BackupField::respond()[1] );
		$this->assertSame( array( 'server' => $this->v['u-None']->get_id(), 'disk' => 0 ), array_intersect_key( $this->seen(), array( 'server' => 1, 'disk' => 1 ) ) );

		$_POST['disk'] = (string) $this->disk['m-50']->get_id();
		$this->assertSame( 409, BackupField::respond()[1] );
		unset( $_POST['nonce'], $_POST['with'], $_POST['disk'], $_REQUEST['nonce'] );
	}

	public function test_a_swap_starting_on_a_stale_cart_reads_the_saved_one_first(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		WC()->session->init_session_cookie();
		$this->cart_with( $this->v['u-None'] );
		$stale = WC()->cart->get_cart();

		// The first request saves backups ...
		$this->assertTrue( CartSwap::apply( true, null )['ok'] );
		$saved = WC()->session->get_session( WC()->session->get_customer_id(), array() );
		$this->assertNotEmpty( $saved['cart'] ?? array(), 'The swap saves the session itself, before it lets go of the lock.' );

		// ... while the second one had loaded the cart before that.
		WC()->cart->set_cart_contents( $stale );
		WC()->session->set( 'cart', WC()->cart->get_cart_for_session() );
		$this->assertSame( $this->v['u-None']->get_id(), $this->seen()['server'] );

		$this->assertTrue( CartSwap::apply( null, $this->disk['u-50']->get_id() )['ok'] );

		$this->assertSame( array( 'server' => $this->v['u-Daily']->get_id(), 'disk' => $this->disk['u-50']->get_id() ), array_intersect_key( $this->seen(), array( 'server' => 1, 'disk' => 1 ) ) );
	}
}

<?php
/** .dk checkout gate and cart selection tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DkAgreement;
use BeltoftServices\Domains\Cart;
use BeltoftServices\Tests\TestCase;

class DkAgreementTest extends TestCase {
	public function test_dk_is_gated_until_wording_approval(): void {
		$this->assertFalse( DkAgreement::ready() );
	}

	public function test_only_new_or_transferred_dk_names_need_acceptance(): void {
		$items = array(
			array( 'beltoft_services_domain' => array( 'domain' => 'one.dk', 'mode' => 'register' ) ),
			array( 'beltoft_services_domain' => array( 'domain' => 'two.dk', 'mode' => 'transfer' ) ),
			array( 'beltoft_services_domain' => array( 'domain' => 'three.com', 'mode' => 'register' ) ),
			array( 'beltoft_services_domain' => array( 'domain' => 'one.dk', 'mode' => 'dns' ) ),
		);
		$this->assertSame( array( 'one.dk', 'two.dk' ), DkAgreement::domains( $items ) );
	}

	/** @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_approved_classic_checkout_displays_and_records_agreement(): void {
		define( 'BELTOFT_SERVICES_DK_AGREEMENT_APPROVED', true );
		$this->assertTrue( DkAgreement::ready() );
		$product = $this->service_product( 'domain' );
		wc_load_cart();
		$data = Cart::line_data( 'agree.dk', 'register' );
		$this->assertIsArray( $data );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), $data );
		ob_start();
		DkAgreement::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'agree.dk', $html );
		$this->assertStringContainsString( DkAgreement::TERMS_URL, $html );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		DkAgreement::validate();
		$this->assertGreaterThan( 0, wc_notice_count( 'error' ) );
		wc_clear_notices();
		$_POST['beltoft_dk_agreement'] = array( 'agree.dk' );
		DkAgreement::validate();
		$this->assertSame( 0, wc_notice_count( 'error' ) );
		unset( $_POST['beltoft_dk_agreement'] );
		$order = wc_create_order();
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Registrant' );
		DkAgreement::record( $order, array() );
		$proof = $order->get_meta( '_beltoft_services_dk_agreement', true );
		$this->assertSame( array( 'agree.dk' ), $proof['domains'] );
		$this->assertSame( 'Test Registrant', $proof['applicant'] );
		$this->assertSame( DkAgreement::TERMS_VERSION, $proof['version'] );
		try {
			DkAgreement::block_store_api( $order, new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
			$this->fail( 'Store API checkout should require the classic .dk agreement.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
			$this->assertSame( 'dk_checkout_agreement_required', $e->error_code );
		}
	}
}

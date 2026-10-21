<?php
/**
 * Service products.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Account\View;
use BeltoftServices\Product;

/**
 * Driver, plan and retention per product, and how service licenses look to beltoft-licenses.
 */
class ProductTest extends TestCase {

	/**
	 * Plan fields the fake driver offers.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private function fields(): array {
		return array(
			'ram'   => array( 'number', 'RAM', 'MB', 512, true ),
			'disk'  => array( 'number', 'Disk', 'GB', 10, false ),
			'label' => array( 'text', 'Label', '', 'basic', true ),
			'beta'  => array( 'checkbox', 'Beta', '', false, true ),
		);
	}

	public function test_plan_merges_driver_defaults_product_and_variation(): void {
		$fake              = $this->fake_driver();
		$fake->plan_fields = $this->fields();

		$product = $this->service_product(
			'fake',
			array(
				'ram'  => 1024,
				'beta' => true,
			),
			array(
				'variations' => array(
					array(
						'ram'   => 4096,
						'disk'  => 500,
						'label' => 'big',
					),
					array(),
				),
			)
		);

		$ids = $product->get_children();

		$this->assertSame(
			array(
				'ram'   => 1024,
				'disk'  => 10,
				'label' => 'basic',
				'beta'  => true,
			),
			Product::plan( $product->get_id() ),
			'Driver defaults, then the product.'
		);
		$this->assertSame(
			array(
				'ram'   => 4096,
				'disk'  => 10,
				'label' => 'big',
				'beta'  => true,
			),
			Product::plan( $product->get_id(), $ids[0] ),
			'The variation overrides variation fields only; disk is not one.'
		);
		$this->assertSame( Product::plan( $product->get_id() ), Product::plan( $product->get_id(), $ids[1] ) );
		$this->assertSame( Product::plan( $product->get_id(), $ids[0] ), Product::plan( $ids[0] ), 'A variation id alone works.' );
	}

	public function test_retention_trial_and_connection_have_defaults(): void {
		$this->fake_driver();

		$plain = $this->service_product();
		$this->assertSame( 0, Product::retention_days( $plain->get_id() ) );
		$this->assertTrue( Product::trial_requires_payment( $plain->get_id() ) );
		$this->assertSame( '', Product::connection( $plain->get_id() ) );

		$set = $this->service_product(
			'fake',
			array(),
			array(
				'retention'              => 30,
				'trial_requires_payment' => 'no',
				'connection'             => 'main',
			)
		);
		$this->assertSame( 30, Product::retention_days( $set->get_id() ) );
		$this->assertFalse( Product::trial_requires_payment( $set->get_id() ) );
		$this->assertSame( 'main', Product::connection( $set->get_id() ) );
	}

	public function test_driver_for_and_is_service(): void {
		$fake = $this->fake_driver();

		$service = $this->service_product();
		$this->assertTrue( Product::is_service( $service->get_id() ) );
		$this->assertSame( $fake, Product::driver_for( $service->get_id() ) );

		$other = new \WC_Product_Simple();
		$other->save();
		$this->assertFalse( Product::is_service( $other->get_id() ) );
		$this->assertNull( Product::driver_for( $other->get_id() ) );

		$orphan = $this->service_product( 'gone' );
		$this->assertTrue( Product::is_service( $orphan->get_id() ), 'A missing driver does not make the product a plain one.' );
		$this->assertNull( Product::driver_for( $orphan->get_id() ) );
	}

	public function test_service_license_is_hidden_from_the_license_api(): void {
		$this->fake_driver();
		$service = $this->service_product();
		$license = $this->service_license( $service, array( 'fire' => false ) );

		$unknown = $this->validate( 'BLTFT-NOPE-NOPE-NOPE' );
		$hidden  = $this->validate( $license['license_key'] );

		$this->assertFalse( $hidden->get_data()['valid'] );
		$this->assertSame( 'not_found', $hidden->get_data()['status'] );
		$this->assertSame( $unknown->get_status(), $hidden->get_status() );
		$this->assertSame( $unknown->get_data(), $hidden->get_data() );
	}

	public function test_resource_label_and_key_display_follow_the_driver(): void {
		$this->fake_driver();
		$license = $this->service_license( $this->service_product(), array( 'fire' => false ) );

		$this->assertSame( '2 of 3 units', apply_filters( 'beltoft_licenses_resource_label', '2 of 3 sites', $license, 2, 3 ) );
		$this->assertFalse( apply_filters( 'beltoft_licenses_account_show_key', true, $license ) );
	}

	public function test_non_service_licenses_are_untouched(): void {
		$this->fake_driver();

		$product = new \WC_Product_Simple();
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'plain-plugin' );
		$product->save();
		$license = $this->service_license( $product, array( 'fire' => false ) );

		$this->assertSame( '2 of 3 sites', apply_filters( 'beltoft_licenses_resource_label', '2 of 3 sites', $license, 2, 3 ) );
		$this->assertTrue( apply_filters( 'beltoft_licenses_account_show_key', true, $license ) );
		$this->assertSame( $license, apply_filters( 'beltoft_licenses_api_license', $license, $license['license_key'] ) );
		$this->assertSame( 200, $this->validate( $license['license_key'] )->get_status() );
	}

	public function test_product_tab_saves_driver_plan_and_variation_overrides(): void {
		$fake              = $this->fake_driver();
		$fake->plan_fields = $this->fields();

		$product = $this->service_product( 'fake', array(), array( 'variations' => array( array() ) ) );
		delete_post_meta( $product->get_id(), Product::META_DRIVER );
		Product::forget_slug( 'test-service' );
		$product = wc_get_product( $product->get_id() );

		$_POST = array(
			Product::META_DRIVER      => 'fake',
			Product::META_CONNECTION  => 'main',
			Product::META_RETENTION   => '45',
			Product::FIELD_MARKER     => 'fake',
			Product::META_PLAN . 'ram' => '2048',
			Product::META_PLAN . 'beta' => '1',
		);
		Product::save( $product );
		$product->save();
		$this->assertSame( 'fake', get_post_meta( $product->get_id(), Product::META_DRIVER, true ) );
		$this->assertSame( 45, Product::retention_days( $product->get_id() ) );
		$this->assertFalse( Product::trial_requires_payment( $product->get_id() ), 'An unchecked box saves no.' );
		$this->assertSame( 2048, Product::plan( $product->get_id() )['ram'] );
		$this->assertTrue( Product::plan( $product->get_id() )['beta'] );

		$_POST = array(
			Product::META_DRIVER => 'unregistered',
		);
		Product::save( $product );
		$this->assertSame( 'fake', $product->get_meta( Product::META_DRIVER ), 'An unknown driver does not replace the saved one.' );

		$_POST     = array( Product::META_PLAN . 'ram' => array( 3 => '8192' ) );
		$variation = wc_get_product( $product->get_children()[0] );
		$product->update_meta_data( Product::META_DRIVER, 'fake' );
		$product->save();
		Product::save_variation( $variation, 3 );
		$variation->save();
		$this->assertSame( 8192, Product::plan( $product->get_id(), $variation->get_id() )['ram'] );

		$_POST = array();
	}

	public function test_license_stays_hidden_after_its_product_is_deleted(): void {
		$this->fake_driver();
		$product = $this->service_product();
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$unknown = $this->validate( 'BLTFT-NOPE-NOPE-NOPE' );

		wp_delete_post( $product->get_id(), true );

		$this->assertSame( $unknown->get_data(), $this->validate( $license['license_key'] )->get_data() );
		$this->assertFalse( apply_filters( 'beltoft_licenses_account_show_key', true, $license ) );
	}

	public function test_releasing_the_slug_through_the_admin_action_makes_the_license_validate_again(): void {
		$this->fake_driver();
		$product = $this->service_product();
		$license = $this->service_license( $product, array( 'fire' => false ) );

		$_POST = array(
			Product::META_DRIVER   => 'fake',
			Product::FIELD_RELEASE => 'yes',
		);
		Product::save( $product );
		$product->save();
		$_POST = array();

		$this->assertFalse( Product::is_service( $product->get_id() ) );
		$this->assertNotContains( 'test-service', Product::service_slugs() );
		$this->assertTrue( $this->validate( $license['license_key'] )->get_data()['valid'] );
	}

	public function test_saving_a_product_remembers_its_slug(): void {
		$this->fake_driver();
		$product = new \WC_Product_Simple();
		$product->save();

		$_POST = array(
			Product::META_DRIVER             => 'fake',
			\BeltoftLicenses\Software::META_SLUG => 'saved-slug',
		);
		Product::save( $product );
		$_POST = array();

		$this->assertContains( 'saved-slug', Product::service_slugs() );
	}

	public function test_software_license_with_a_missing_product_is_unaffected(): void {
		$this->fake_driver();
		$license = \BeltoftLicenses\Licenses::create(
			array(
				'product_id'   => 999999,
				'product_slug' => 'ordinary-plugin',
				'email'        => 'a@example.com',
			)
		);

		$this->assertSame( $license, apply_filters( 'beltoft_licenses_api_license', $license, $license['license_key'] ) );
		$this->assertTrue( $this->validate( $license['license_key'] )->get_data()['valid'] );
	}

	public function test_unavailable_driver_is_kept_and_listed(): void {
		$product = $this->service_product( 'gone' );
		$product = wc_get_product( $product->get_id() );

		$_POST = array( Product::META_DRIVER => '' );
		Product::save( $product );
		$this->assertSame( 'gone', $product->get_meta( Product::META_DRIVER ), 'Empty does not wipe the saved driver.' );

		include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$GLOBALS['product_object'] = $product;
		ob_start();
		Product::panel();
		$html = ob_get_clean();
		unset( $GLOBALS['product_object'] );
		$_POST = array();

		$this->assertStringContainsString( 'gone (unavailable)', $html );
	}

	public function test_save_without_the_service_panel_changes_nothing(): void {
		$this->fake_driver();
		$product = $this->service_product( 'fake', array(), array( 'retention' => 30, 'connection' => 'main', 'trial_requires_payment' => 'no' ) );
		$product = wc_get_product( $product->get_id() );

		$_POST = array( 'something_else' => '1' );
		Product::save( $product );
		$product->save();
		$_POST = array();

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'fake', $product->get_meta( Product::META_DRIVER ) );
		$this->assertSame( 30, Product::retention_days( $product->get_id() ) );
		$this->assertSame( 'main', Product::connection( $product->get_id() ) );
		$this->assertFalse( Product::trial_requires_payment( $product->get_id() ) );
	}

	/**
	 * Calls the beltoft-licenses validate route.
	 *
	 * @param string $key License key.
	 * @return \WP_REST_Response
	 */
	private function validate( string $key ): \WP_REST_Response {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$settings = get_option( \BeltoftLicenses\Settings::OPTION, array() );
		update_option( \BeltoftLicenses\Settings::OPTION, array_merge( (array) $settings, array( 'rate_limit_requests' => 0 ) ) );

		$_SERVER['REQUEST_URI'] = '/wp-json/beltoft-licenses/v1/validate';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$request = new \WP_REST_Request( 'POST', '/beltoft-licenses/v1/validate' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'license_key' => $key ) ) );

		$response = rest_get_server()->dispatch( $request );
		$wp_rest_server = null;

		return $response;
	}
}

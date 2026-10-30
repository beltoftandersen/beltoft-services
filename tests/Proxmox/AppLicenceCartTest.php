<?php
/** Ordering a paid app with a server: the tiers in the app field, the second cart line and the refusals. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppLicenceCart;
use BeltoftServices\Proxmox\DiskField;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * Documentation values only. The fixture paid app is docker (see PaidAppCase).
 */
final class AppLicenceCartTest extends PaidAppCase {

	private function html(): string {
		ob_start();
		AppField::render_classic();

		return (string) ob_get_clean();
	}

	/**
	 * Posts values and runs the classic validation.
	 *
	 * @param array<string, string> $post Values by POST key.
	 * @return string[] Error messages.
	 */
	private function check( array $post ): array {
		do_action( 'woocommerce_checkout_process' );

		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		$errors = new \WP_Error();
		AppField::validate_classic( array(), $errors );

		return $errors->get_error_messages();
	}

	private function cart_with_server(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->vm_product()->get_id() ) );
	}

	// -- The catalogue -------------------------------------------------------------------------------

	public function test_an_app_is_paid_only_when_its_definition_says_so_and_free_apps_are_unchanged(): void {
		$this->assertTrue( AppLicence::paid( 'docker' ) );
		$this->assertSame( self::$slug, AppLicence::slug( 'docker' ) );
		$this->assertFalse( AppLicence::paid( 'wordpress' ) );
		$this->assertFalse( AppLicence::paid( 'nothing' ) );
		$this->assertArrayNotHasKey( 'licence', (array) \BeltoftServices\Proxmox\Apps::definition( 'wordpress' ) );
		remove_filter( 'beltoft_services_apps', array( $this, 'paid_docker' ), 10 );
		$this->assertFalse( AppLicence::paid( 'docker' ), 'The shipped docker app is free.' );
		add_filter( 'beltoft_services_apps', array( $this, 'paid_docker' ), 10, 2 );
		add_filter( 'beltoft_services_apps', static function ( $data, $id ) {
			if ( 'wordpress' === $id && is_array( $data ) ) {
				$data['licence'] = array( 'product_slug' => 'Bad Slug!' );
			}
			return $data;
		}, 11, 2 );
		$this->assertFalse( AppLicence::paid( 'wordpress' ), 'A slug that is not a slug is no licence.' );
	}

	public function test_the_tiers_are_the_purchasable_variations_with_their_price_and_plan_fields(): void {
		$this->assertSame( array(), AppLicence::tiers( 'docker' ), 'No product yet.' );

		$v = $this->tier_product( array( 'basic' => '5', 'pro' => '12' ), array( 'pro' => array( 'tier_label' => 'Professional', 'domains' => '10' ) ) );
		add_filter( 'beltoft_services_app_licence_plan_fields', static fn( $f ) => $f + array( 'domains' => array( 'number', 'Domains', '', 1, true ) ) );
		$tiers = AppLicence::tiers( 'docker' );

		$this->assertSame( array( 'basic', 'pro' ), array_keys( $tiers ) );
		$this->assertSame( 'Basic', $tiers['basic']['label'], 'The variation name when no tier name is set.' );
		$this->assertSame( 'Professional', $tiers['pro']['label'] );
		$this->assertStringContainsString( '12', $tiers['pro']['price'] );
		$this->assertStringContainsString( 'excl. VAT', $tiers['pro']['price'] );
		$this->assertSame( 10, $tiers['pro']['plan']['domains'], 'A tier carries the plan fields a vendor driver added.' );
		$this->assertSame( 'docker', $tiers['pro']['plan']['app'] );

		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		$v['pro']->set_status( 'private' );
		$v['pro']->save();
		$this->assertSame( array( 'basic' ), array_keys( AppLicence::tiers( 'docker', false ) ), 'A tier that is not published is never listed.' );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
	}

	// -- The app field -------------------------------------------------------------------------------

	public function test_the_field_shows_a_paid_apps_tiers_with_price_and_hides_a_paid_app_with_no_tier_to_buy(): void {
		$this->cart_with_server();
		$this->assertStringNotContainsString( 'value="docker"', $this->html(), 'Nobody can buy a tier of docker: it is not offered.' );
		$this->assertStringContainsString( 'value="wordpress"', $this->html(), 'A free app is offered as before.' );

		$this->tier_product();
		$html = $this->html();
		$this->assertStringContainsString( 'value="docker"', $html );
		$this->assertMatchesRegularExpression( '#<fieldset[^>]*data-app-tiers="docker"[^>]*hidden#', $html, 'The tiers are hidden until the app is chosen.' );
		$this->assertStringContainsString( 'name="' . AppField::POST_TIER . '" value="basic"', $html );
		$this->assertStringContainsString( 'name="' . AppField::POST_TIER . '" value="pro"', $html );
		$this->assertStringContainsString( 'excl. VAT', $html );
		$this->assertStringNotContainsString( 'data-app-tiers="wordpress"', $html );
	}

	public function test_the_tiers_show_when_the_picker_carried_the_app(): void {
		$this->tier_product();
		wp_set_current_user( self::factory()->user->create() );
		$vm = $this->vm_product();
		WC()->cart->add_to_cart( $vm->get_id(), 1, 0, array(), array( \BeltoftServices\Proxmox\Picked::KEY => array( 'app' => 'docker', 'tier' => 'pro' ) ) );
		$html = $this->html();
		$this->assertDoesNotMatchRegularExpression( '#<fieldset[^>]*data-app-tiers="docker"[^>]*hidden#', $html );
		$this->assertMatchesRegularExpression( '#value="pro"[^>]*checked#', $html );
	}

	// -- Refusals before payment -----------------------------------------------------------------------

	public function test_a_paid_app_without_a_tier_or_with_an_unknown_tier_is_refused(): void {
		$this->tier_product();
		$this->cart_with_server();

		$this->assertSame( array( 'Docker has a monthly licence. Choose a tier, or no app.' ), $this->check( array( AppField::POST_APP => 'docker' ) ) );
		$this->assertSame( array( 'Choose one of the listed tiers.' ), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'gold' ) ) );
		$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'wordpress' ) ), 'A free app needs no tier.' );
		$this->assertSame( array(), $this->check( array( AppField::POST_APP => '' ) ) );
	}

	public function test_a_tier_that_cannot_be_bought_is_refused_but_a_paid_order_still_builds(): void {
		$v = $this->tier_product();
		$this->cart_with_server();
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'pro' ) );
		$v['pro']->set_stock_status( 'outofstock' );
		$v['pro']->save();
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );

		$offer = AppField::offer();
		$this->assertIsArray( $offer );
		$strict = AppField::resolve( $offer, array( 'app' => 'docker', 'tier' => 'pro' ), get_current_user_id() );
		$this->assertSame( 'This tier cannot be bought now. Choose another tier or no app.', $strict );
		$loose = AppField::resolve( $offer, array( 'app' => 'docker', 'tier' => 'pro' ), get_current_user_id(), false );
		$this->assertIsArray( $loose, 'The order is paid: the server is built with the app.' );
		$this->assertSame( 'pro', $loose['tier'] );
	}

	// -- The cart line ---------------------------------------------------------------------------------

	public function test_choosing_a_tier_adds_a_second_cart_line_linked_to_the_server_and_changing_it_replaces_it(): void {
		$v = $this->tier_product();
		$this->cart_with_server();

		$this->assertTrue( AppLicenceCart::choose( 'docker', 'basic' ) );
		$this->assertCount( 2, WC()->cart->get_cart() );
		$line = AppLicenceCart::line();
		$this->assertSame( $v['basic']->get_id(), (int) $line['item']['variation_id'] );
		$this->assertSame( 'cart_server', $line['item'][ AppLicence::CART_FOR ] );
		$this->assertSame( 1, $line['item']['quantity'] );
		$this->assertStringContainsString( '_applic_', AppLicenceCart::recurring_key( 'base', $line['item'] ) );

		$this->assertTrue( AppLicenceCart::choose( 'docker', 'basic' ), 'The same tier again changes nothing.' );
		$this->assertCount( 2, WC()->cart->get_cart() );

		$this->assertTrue( AppLicenceCart::choose( 'docker', 'pro' ) );
		$this->assertCount( 2, WC()->cart->get_cart(), 'One tier line at most.' );
		$this->assertSame( $v['pro']->get_id(), (int) AppLicenceCart::line()['item']['variation_id'] );

		WC()->cart->calculate_totals();
		$this->assertCount( 1, WC()->cart->recurring_carts, 'The tier renews in a cart of its own.' );
		$this->assertStringContainsString( '_applic_', (string) array_key_first( WC()->cart->recurring_carts ) );

		$this->assertTrue( AppLicenceCart::sync( '', '' ) );
		$this->assertCount( 1, WC()->cart->get_cart(), 'No app takes the line out.' );
		$this->assertFalse( AppLicenceCart::choose( 'docker', 'gold' ), 'An unknown tier adds nothing.' );
		$this->assertSame( 0, AppLicenceCart::lines() );
	}

	public function test_a_tier_line_cannot_be_added_without_a_new_server_and_a_removed_server_takes_it_along(): void {
		$v = $this->tier_product();
		wp_set_current_user( self::factory()->user->create() );
		$this->assertFalse( WC()->cart->add_to_cart( $v['basic']->get_parent_id(), 1, $v['basic']->get_id(), array( 'attribute_tier' => 'Basic' ) ), 'Directly, with no server in the cart.' );
		$this->assertFalse( AppLicenceCart::choose( 'docker', 'basic' ) );
		wc_clear_notices();

		$key = WC()->cart->add_to_cart( $this->vm_product()->get_id() );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'basic' ) );
		WC()->cart->remove_cart_item( $key );
		$this->assertCount( 0, WC()->cart->get_cart(), 'A licence for no server is not left in the cart.' );
	}

	public function test_the_checkout_check_wants_exactly_the_chosen_tier_in_the_cart(): void {
		$v = $this->tier_product();
		$this->cart_with_server();

		$this->assertSame( array( 'The tier is not in your cart. Reload the page and choose the tier again.' ), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'pro' ) ), 'The posted tier has no line.' );

		$this->assertTrue( AppLicenceCart::choose( 'docker', 'basic' ) );
		$this->assertSame( array( 'The tier is not in your cart. Reload the page and choose the tier again.' ), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'pro' ) ), 'The line is another tier.' );
		$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'basic' ) ) );

		$this->assertCount( 1, array_filter( $this->check( array( AppField::POST_APP => 'wordpress' ) ) ), 'A tier line for another app is refused.' );
		$this->assertSame( array( 'An app licence is in your cart but no app is chosen. Remove the licence or choose the app.' ), $this->check( array( AppField::POST_APP => '' ) ) );
		$this->assertSame( $v['basic']->get_id(), (int) AppLicenceCart::line()['item']['variation_id'] );
	}

	public function test_the_order_line_remembers_the_tier_and_the_server_link(): void {
		$v = $this->tier_product();
		$this->cart_with_server();
		AppLicenceCart::choose( 'docker', 'pro' );
		$item = new \WC_Order_Item_Product();
		$line = AppLicenceCart::line();
		AppLicenceCart::order_item( $item, $line['key'], $line['item'], new \WC_Order() );
		$this->assertSame( 'cart_server', $item->get_meta( AppLicence::ITEM_SERVER ) );
		$this->assertNotSame( '', $item->get_meta( AppLicence::ITEM_UID ) );

		$server = new \WC_Order_Item_Product();
		do_action( 'woocommerce_checkout_process' );
		$_POST[ AppField::POST_APP ]  = 'docker';
		$_POST[ AppField::POST_TIER ] = 'pro';
		$server->set_product_id( DiskField::cart_server()['item']['product_id'] );
		AppField::save_on_item( $server );
		$this->assertSame( 'pro', $server->get_meta( AppField::ITEM_TIER ) );
		$this->assertSame( 'docker', $server->get_meta( AppField::ITEM_APP ) );
		$this->assertSame( $v['pro']->get_id(), (int) $line['item']['variation_id'] );
	}
}

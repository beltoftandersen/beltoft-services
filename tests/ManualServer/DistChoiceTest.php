<?php
/**
 * The operating system choice of a dedicated server: the lists are the supplier's, cached, grouped in family tiles, and the exact string goes into the order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\Jobs;
use BeltoftServices\ManualServer\DistCatalog;
use BeltoftServices\ManualServer\DistField;
use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\ManualServer\ImageCatalog;
use BeltoftServices\ManualServer\RobotOrder;
use BeltoftServices\Proxmox\Vm\Tiles;
use BeltoftServices\Resource;

require_once __DIR__ . '/ManualServerTestCase.php';

final class DistChoiceTest extends ManualServerTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->automatic();
		delete_option( DistCatalog::OPTION );
		delete_option( ImageCatalog::OPTION );
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		unset( $_POST[ DistField::POST_KEY ] );
		remove_all_filters( 'beltoft_services_key' );
		FakeRobotClient::reset();
		parent::tear_down();
	}

	public function test_the_list_is_what_the_supplier_lists_for_the_product_without_the_rescue_entry(): void {
		$list = DistCatalog::for_product( 'AX52' );

		$this->assertSame( array_values( array_diff( FakeRobotClient::DISTS, array( 'Rescue system' ) ) ), $list );
		$this->assertContains( 'Ubuntu 24.04 LTS minimal', $list, 'The exact string, not a mapped name.' );
		$this->assertSame( array(), DistCatalog::for_product( 'NOPE' ) );
		$this->assertTrue( DistCatalog::offers( 'AX52', 'Debian 12.5 minimal' ) );
		$this->assertFalse( DistCatalog::offers( 'AX52', 'Debian 12' ) );
		$this->assertFalse( DistCatalog::offers( 'AX52', 'Rescue system' ) );
	}

	public function test_the_list_is_cached_for_a_day_and_a_failed_read_keeps_the_old_copy(): void {
		DistCatalog::for_product( 'AX52' );
		$state = get_option( DistCatalog::OPTION );
		$this->assertNotEmpty( $state['products']['EX44'] );

		FakeRobotClient::reset();
		$this->assertNotEmpty( DistCatalog::for_product( 'AX52' ), 'Served from the cache: no second read.' );

		$state['at']    = time() - 2 * DAY_IN_SECONDS;
		$state['tried'] = time() - DAY_IN_SECONDS;
		update_option( DistCatalog::OPTION, $state );
		$this->settings( array( 'dedicated_client' => 'live' ) );
		$this->assertSame( $state['products']['AX52'], DistCatalog::all()['AX52'], 'The live client is unavailable (no constant): the stale copy stays.' );
	}

	public function test_dists_are_grouped_into_families_with_a_version_inside_the_tile(): void {
		$entries = DistCatalog::entries( array( 'Debian 12.5 minimal', 'Debian 11.9 minimal', 'Ubuntu 24.04 LTS minimal', 'Alma Linux 9.4 minimal', 'Arch Linux latest minimal', 'CentOS Stream 9 minimal' ) );

		$this->assertSame( array( 'Debian', 'Debian', 'Ubuntu', 'Alma Linux', 'Arch Linux', 'CentOS Stream' ), array_column( $entries, 'family' ) );
		$this->assertSame( 'Debian 12.5 minimal', $entries[0]['value'] );
		$this->assertSame( '12.5 minimal', $entries[0]['short'] );

		$items = Tiles::family_items( $entries, '' );
		$this->assertCount( 5, $items );
		$this->assertSame( 'Debian 12.5 minimal', $items[0]['value'], 'The newest of a family is shown first.' );
		$versions = json_decode( $items[0]['attrs']['data-versions'], true );
		$this->assertSame( array( 'Debian 12.5 minimal', 'Debian 11.9 minimal' ), array_column( $versions, 'value' ) );

		$this->assertSame( 'Debian 11.9 minimal', Tiles::family_items( $entries, 'Debian 11.9 minimal' )[0]['value'], 'The family of the checked value shows it.' );

		ob_start();
		Tiles::print_group( array( 'kind' => 'os', 'id' => 'x', 'name' => 'n', 'legend' => 'System', 'items' => $items ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-tiles-os-version', $html );
		$this->assertStringContainsString( 'value="Debian 12.5 minimal"', $html );
	}

	public function test_image_names_are_cleaned_and_grouped(): void {
		$this->assertSame( array( 'Debian-1201-bookworm-amd64-base.tar.gz', 'x.tgz' ), ImageCatalog::clean( array( 'Debian-1201-bookworm-amd64-base.tar.gz', 'Debian-1201-bookworm-amd64-base.tar.gz.sig', '../x.tar.gz', 'x.tgz', 'a b.tar.gz', 'notes.txt', '' ) ) );

		$entries = ImageCatalog::entries( array( 'Debian-1201-bookworm-amd64-base.tar.gz', 'CentOS-Stream-9-amd64-base.tar.gz', 'Ubuntu-2404-noble-amd64-base.tar.gz' ) );
		$this->assertSame( array( 'Debian', 'CentOS Stream', 'Ubuntu' ), array_column( $entries, 'family' ) );
		$this->assertSame( '1201 bookworm', $entries[0]['short'] );
		$this->assertSame( 'Debian-1201-bookworm-amd64-base.tar.gz', $entries[0]['value'] );

		$this->assertSame( array(), ImageCatalog::names(), 'No list yet: nothing is offered.' );
		$this->settings( array( 'dedicated_images' => "Debian-1201-bookworm-amd64-base.tar.gz\nbad name" ) );
		$this->assertSame( array( 'Debian-1201-bookworm-amd64-base.tar.gz' ), ImageCatalog::names() );
		ImageCatalog::remember( array( 'Rocky-94-amd64-base.tar.gz' ) );
		$this->assertSame( array( 'Rocky-94-amd64-base.tar.gz' ), ImageCatalog::names(), 'What a rescue system listed wins over the setting.' );
	}

	private function cart_with_server(): \WC_Product_Variation {
		$v = $this->server_product();
		$v['u']->update_meta_data( \BeltoftServices\Product::META_PLAN . 'server_ref', 'AX52' );
		$v['u']->save();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $v['u']->get_parent_id(), 1, $v['u']->get_id(), array( 'attribute_server' => 'AMD Ryzen 7, 64 GB', 'attribute_billing' => 'Pay as you go' ) );

		return $v['u'];
	}

	public function test_checkout_asks_for_one_of_the_listed_strings(): void {
		$this->cart_with_server();
		DistCatalog::all( true );

		ob_start();
		DistField::render_classic();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . DistField::POST_KEY . '"', $html );
		$this->assertStringContainsString( 'value="Ubuntu 24.04 LTS minimal"', $html );
		$this->assertStringNotContainsString( 'Rescue system', $html );

		do_action( 'woocommerce_checkout_process' );
		foreach ( array( '', 'Ubuntu 24', 'Rescue system' ) as $bad ) {
			$_POST[ DistField::POST_KEY ] = $bad;
			$errors                       = new \WP_Error();
			DistField::validate_classic( array(), $errors );
			$this->assertTrue( $errors->has_errors(), 'Refused: ' . $bad );
		}

		$_POST[ DistField::POST_KEY ] = 'Ubuntu 24.04 LTS minimal';
		$errors                       = new \WP_Error();
		DistField::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_a_product_the_supplier_lists_nothing_for_asks_nothing(): void {
		$v = $this->server_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $v['u']->get_parent_id(), 1, $v['u']->get_id(), array( 'attribute_server' => 'AMD Ryzen 7, 64 GB', 'attribute_billing' => 'Pay as you go' ) );
		DistCatalog::all( true );

		$choice = DistField::cart_choice();
		$this->assertNull( $choice );
		$errors = new \WP_Error();
		do_action( 'woocommerce_checkout_process' );
		DistField::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_a_cart_without_a_dedicated_server_is_not_asked(): void {
		WC()->cart->empty_cart();
		DistCatalog::all( true );
		$this->assertNull( DistField::cart_choice() );
		ob_start();
		DistField::render_classic();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_chosen_string_is_sent_unchanged_in_the_order(): void {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );
		$r->set( array( DistField::META => 'Debian 12.5 minimal' ) );
		Jobs::run( $r->id() );

		$orders = FakeRobotClient::orders();
		$this->assertCount( 1, $orders );
		$this->assertSame( 'Debian 12.5 minimal', $orders[0]['dist'] );
		$this->assertNotEmpty( Resource::find( $r->id() )->get( RobotOrder::TRANSACTION ) );
	}

	public function test_without_a_choice_the_plan_or_the_setting_decides_as_before(): void {
		$this->settings( array( 'dedicated_dist' => 'Debian 11.9 minimal' ) );
		$v = $this->server_product();
		$r = $this->waiting_server( $v['u'], $this->new_user() );
		Jobs::run( $r->id() );

		$this->assertSame( 'Debian 11.9 minimal', FakeRobotClient::orders()[0]['dist'] );
	}
}

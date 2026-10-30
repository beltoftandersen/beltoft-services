<?php
/** The server picker with a paid app: the tier is checked like at checkout and its line joins the server in the cart. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\AppLicenceCart;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Picked;

require_once __DIR__ . '/BackupTestCase.php';
require_once __DIR__ . '/PaidAppFixtures.php';

/**
 * Documentation values only. FakeClient and the capacity cache: nothing reaches a network.
 */
final class AppLicencePickerTest extends BackupTestCase {

	use PaidAppFixtures;

	/** @var array<string, \WC_Product_Variation> */
	private array $v = array();

	/** @var array<string, \WC_Product_Variation> */
	private array $tiers = array();

	private int $user = 0;

	public function set_up(): void {
		parent::set_up();

		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		add_filter( 'beltoft_services_apps', array( $this, 'paid_docker' ), 10, 2 );
		AppField::register();
		AppLicence::register();
		AppLicenceCart::register();

		$this->save_cluster( array(
			'templates'              => "9000 = Debian 12\n9001 = Ubuntu 24.04",
			'default_template'       => '9000',
			'vendor_snippet'         => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
			'apps_ready'             => 1,
			'apps_enabled'           => array( 'docker', 'wordpress' ),
			'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) );

		$this->v = $this->variable_product( array( 'None' => '10', 'Daily' => '12' ) );

		// Plan b is the bigger one, which docker fits.
		foreach ( array( 'b-None', 'b-Daily' ) as $key ) {
			foreach ( array( 'cores' => '4', 'memory_mb' => '8192', 'disk_gb' => '100' ) as $field => $value ) {
				$this->v[ $key ]->update_meta_data( \BeltoftServices\Product::META_PLAN . $field, $value );
			}

			$this->v[ $key ]->save();
		}

		$this->tiers = $this->tier_product();
		$this->user  = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Offers::reset();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		remove_filter( 'beltoft_services_apps', array( $this, 'paid_docker' ), 10 );
		Offers::reset();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * @param array<string, string> $over Fields.
	 * @return array{redirect: string, errors: array<string, string>}
	 */
	private function send( array $over = array() ): array {
		Offers::reset();

		return ServerPicker::process(
			array_merge(
				array(
					ServerPicker::FIELD => wp_create_nonce( ServerPicker::NONCE ),
					ServerPicker::PLAN  => (string) $this->v['b-None']->get_id(),
					OsField::POST_KEY   => '9000',
				),
				$over
			),
			$this->user
		);
	}

	public function test_a_paid_app_without_a_tier_is_refused_and_nothing_is_added(): void {
		$result = $this->send( array( AppField::POST_APP => 'docker' ) );

		$this->assertSame( 'Docker has a monthly licence. Choose a tier, or no app.', $result['errors']['app'] ?? '' );
		$this->assertCount( 0, WC()->cart->get_cart() );

		$result = $this->send( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'gold' ) );
		$this->assertSame( 'Choose one of the listed tiers.', $result['errors']['app'] ?? '' );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_a_tier_is_carried_and_its_line_joins_the_server_and_follows_it_when_the_server_is_picked_again(): void {
		$result = $this->send( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'pro' ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 2, WC()->cart->get_cart(), 'The server and the tier.' );

		$carried = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$carried = $item[ Picked::KEY ] ?? $carried;
		}
		$this->assertSame( 'pro', $carried['tier'] );
		$this->assertSame( 'docker', $carried['app'] );
		$this->assertSame( $this->tiers['pro']->get_id(), (int) AppLicenceCart::line()['item']['variation_id'] );

		$this->send( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'basic' ) );
		$this->assertCount( 2, WC()->cart->get_cart(), 'The earlier try is replaced, tier line included.' );
		$this->assertSame( $this->tiers['basic']->get_id(), (int) AppLicenceCart::line()['item']['variation_id'] );

		$this->send( array( AppField::POST_APP => 'wordpress' ) );
		$this->assertCount( 1, WC()->cart->get_cart(), 'A free app has no tier line, and the old one is gone.' );
		$this->assertSame( 0, AppLicenceCart::lines() );
	}

	public function test_checkout_accepts_what_the_picker_carried_without_a_posted_field(): void {
		$this->send( array( AppField::POST_APP => 'docker', AppField::POST_TIER => 'pro' ) );
		do_action( 'woocommerce_checkout_process' );
		$errors = new \WP_Error();
		AppField::validate_classic( array(), $errors );
		$this->assertSame( array(), $errors->get_error_messages() );
	}
}

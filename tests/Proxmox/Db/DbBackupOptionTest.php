<?php
/**
 * Daily backups as an option of a database plan, like on a VPS: a Backups None/Daily attribute with sibling variations,
 * the checkout box, the extra storage that is kept, the picker that lists a plan once, and the price lines. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Db;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backup\BackupField;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Db\DbDisk;
use BeltoftServices\Proxmox\Db\DbDiskField;
use BeltoftServices\Proxmox\Db\DbPicker;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Backup/BackupTestCase.php';

class DbBackupOptionTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->seed_private_nets();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_POST['nonce'], $_POST['extra'], $_POST['with'], $_REQUEST['nonce'] );

		parent::tear_down();
	}

	/**
	 * A database product with Extra storage None and 50 GB, each with Backups None and Daily (30 days).
	 *
	 * @return array<string, \WC_Product_Variation> Keys such as e0-n, e0-d, e50-n, e50-d.
	 */
	private function db_variations(): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Database ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$attrs = array();

		foreach ( array( 'Extra storage' => array( 'None', '50 GB' ), 'Backups' => array( 'None', 'Daily' ) ) as $name => $options ) {
			$a = new \WC_Product_Attribute();
			$a->set_name( $name );
			$a->set_options( $options );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}

		$product->set_attributes( $attrs );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'engine' => 'postgres', 'major' => '18', 'cores' => '1', 'memory_mb' => '2048', 'disk_gb' => '20', 'public_ip' => '0' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 0 => array( 'None', 12 ), 50 => array( '50 GB', 16 ) ) as $extra => $set ) {
			foreach ( array( 'n' => 'None', 'd' => 'Daily' ) as $code => $backups ) {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'extra-storage' => $set[0], 'backups' => $backups ) );
				$v->set_regular_price( (string) ( $set[1] + ( 'd' === $code ? 3 : 0 ) ) );
				$v->set_status( 'publish' );
				$v->update_meta_data( Product::META_PLAN . 'extra_disk_gb', (string) $extra );
				$v->update_meta_data( Product::META_PLAN . 'backup_days', 'd' === $code ? '30' : '' );
				$v->save();
				$out[ 'e' . $extra . '-' . $code ] = $v;
			}
		}

		return $out;
	}

	private function add( \WC_Product_Variation $v ): string {
		$attrs = array();

		foreach ( $v->get_attributes() as $name => $value ) {
			$attrs[ 'attribute_' . $name ] = $value;
		}

		$key = WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), $attrs );
		$this->assertNotFalse( $key, 'Added to the cart: ' . wp_json_encode( wc_get_notices() ) );

		return (string) $key;
	}

	private function in_cart(): int {
		$cart = WC()->cart->get_cart();

		return (int) reset( $cart )['variation_id'];
	}

	private function box(): string {
		ob_start();
		BackupField::render();

		return (string) ob_get_clean();
	}

	public function test_the_daily_variation_is_the_backup_sibling_of_its_plan_and_keeps_the_extra_storage(): void {
		$v = $this->db_variations();

		$this->assertSame( $v['e0-d']->get_id(), Backups::sibling( $v['e0-n'], true )->get_id() );
		$this->assertSame( $v['e50-d']->get_id(), Backups::sibling( $v['e50-n'], true )->get_id(), 'The same storage with backups.' );
		$this->assertSame( $v['e50-n']->get_id(), Backups::sibling( $v['e50-d'], false )->get_id() );
		$this->assertNull( Backups::sibling( $v['e0-d'], true ), 'Already has them.' );
	}

	public function test_the_storage_ladder_of_a_plan_stays_within_its_backup_choice(): void {
		$v = $this->db_variations();

		$this->assertSame( array( 0, 50 ), array_keys( DbDisk::ladder( $v['e0-d'] ) ) );
		$this->assertSame( $v['e50-d']->get_id(), DbDisk::ladder( $v['e0-d'] )[50]->get_id(), 'A plan with backups steps to storage with backups.' );
		$this->assertSame( $v['e50-n']->get_id(), DbDisk::ladder( $v['e0-n'] )[50]->get_id() );
	}

	public function test_a_new_database_gets_the_daily_backups_box_with_the_database_words(): void {
		$v = $this->db_variations();
		$this->assertSame( '', $this->box(), 'No database in the cart: no box.' );

		$this->add( $v['e0-n'] );
		$html = $this->box();
		$this->assertStringContainsString( 'Daily backups', $html );
		$this->assertStringContainsString( 'whole database server is backed up every night and kept for 30 days', $html );
		$this->assertStringNotContainsString( 'boot disk', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00b7}", $html );
		$this->assertTrue( BackupField::cart_server()['database'] );
	}

	public function test_the_box_swaps_the_database_line_and_keeps_the_extra_storage(): void {
		$v = $this->db_variations();
		$this->add( $v['e50-n'] );

		$this->assertTrue( BackupField::swap( true )['ok'] );
		$this->assertSame( $v['e50-d']->get_id(), $this->in_cart(), 'Backups on, 50 GB kept.' );

		$this->assertTrue( DbDiskField::swap( 0 )['ok'] );
		$this->assertSame( $v['e0-d']->get_id(), $this->in_cart(), 'Storage off, backups kept.' );

		$this->assertTrue( BackupField::swap( false )['ok'] );
		$this->assertSame( $v['e0-n']->get_id(), $this->in_cart() );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_the_cart_line_shows_the_backups_row_only_with_backups(): void {
		$v = $this->db_variations();
		$this->add( $v['e0-d'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$rows = BackupField::item_data( array(), $item );
		$this->assertSame( 'Daily backups', $rows[0]['key'] );

		WC()->cart->empty_cart();
		$this->add( $v['e0-n'] );
		$cart = WC()->cart->get_cart();
		$item = reset( $cart );
		$this->assertSame( array(), BackupField::item_data( array(), $item ) );
	}

	public function test_a_server_and_a_database_in_one_cart_get_no_box(): void {
		$v = $this->db_variations();
		$this->add( $v['e0-n'] );
		$this->assertNotNull( BackupField::cart_server() );

		$server = $this->variable_product();
		$attrs  = array();

		foreach ( $server['a-None']->get_attributes() as $name => $value ) {
			$attrs[ 'attribute_' . $name ] = $value;
		}

		WC()->cart->add_to_cart( $server['a-None']->get_parent_id(), 1, $server['a-None']->get_id(), $attrs );
		$this->assertNull( BackupField::cart_server(), 'The box could not say which one it is for.' );
	}

	public function test_the_picker_lists_a_plan_once_and_says_backups_are_an_option(): void {
		$v     = $this->db_variations();
		$plans = DbPicker::plans();

		$this->assertArrayHasKey( $v['e0-n']->get_id(), $plans );
		$this->assertArrayNotHasKey( $v['e0-d']->get_id(), $plans, 'The Daily variation is the option, not a plan.' );
		$this->assertArrayNotHasKey( $v['e50-n']->get_id(), $plans );
		$this->assertTrue( $plans[ $v['e0-n']->get_id() ]['backups_option'] );
		$this->assertSame( 0, $plans[ $v['e0-n']->get_id() ]['backup_days'] );
	}

	public function test_the_backups_option_costs_the_difference_of_the_two_variations(): void {
		$v = $this->db_variations();
		$this->assertStringContainsString( '3.00', Backups::price_difference( $v['e0-n'], $v['e0-d'] ) );
	}

	public function test_the_breakdown_of_a_daily_variation_has_its_own_backups_part(): void {
		$v      = $this->db_variations();
		$driver = \BeltoftServices\Drivers\Registry::get( 'proxmox-db' );
		$parts  = ( $driver->usage_breakdown()['parts'] )( $v['e50-d'], static fn( $x ) => (float) $x->get_regular_price() );

		$this->assertSame( 12.0, $parts['base'] );
		$this->assertSame( 4.0, $parts['storage'] );
		$this->assertSame( 3.0, $parts['backups'] );
		$this->assertSame( 50, $parts['gb'] );
	}
}

<?php
/**
 * The Proxmox answer to the line label filter: an extra disk line says which server it is for.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Vm;

use BeltoftServices\LineNames;
use BeltoftServices\Proxmox\Vm\ServerName;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Backup/BackupTestCase.php';

/**
 * Seen through LineNames::label(), as the cart, orders and emails reach it. Fake cluster only.
 */
class CoreHooksTest extends BackupTestCase {

	/**
	 * The one variation of a new product of a driver.
	 *
	 * @param string $driver Driver id.
	 */
	private function variation_of( string $driver ): \WC_Product_Variation {
		$product = $this->service_product( $driver, array(), array( 'variations' => array( array() ), 'slug' => 'lbl-' . wp_generate_password( 5, false ) ) );

		return wc_get_product( $product->get_children()[0] );
	}

	public function test_an_extra_disk_line_names_its_server(): void {
		$server = $this->ready_vm( array(), array( ServerName::META => 'web1' ) );
		$disk   = $this->variation_of( 'proxmox-disk' );
		$plain  = LineNames::label( $disk );

		$this->assertNotSame( '', $plain );
		$this->assertSame( $plain . ' for web1', LineNames::label( $disk, (int) $server->license()['id'] ) );
	}

	public function test_an_extra_disk_line_is_unchanged_without_a_known_named_server(): void {
		$unnamed = $this->ready_vm();
		$disk    = $this->variation_of( 'proxmox-disk' );
		$plain   = LineNames::label( $disk );

		$this->assertSame( $plain, LineNames::label( $disk, 0 ), 'No server id.' );
		$this->assertSame( $plain, LineNames::label( $disk, 999999 ), 'Unknown id.' );
		$this->assertSame( $plain, LineNames::label( $disk, (int) $unnamed->license()['id'] ), 'A server without a name.' );
	}

	public function test_a_line_of_another_driver_never_gets_the_server_suffix(): void {
		$server = $this->ready_vm( array(), array( ServerName::META => 'web1' ) );
		$other  = $this->variation_of( 'proxmox' );
		$plain  = LineNames::label( $other );

		$this->assertNotSame( '', $plain );
		$this->assertSame( $plain, LineNames::label( $other, (int) $server->license()['id'] ) );
	}
}

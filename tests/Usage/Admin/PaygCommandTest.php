<?php
/**
 * wp beltoft-services payg check: the same checks as the editor.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Usage\PaygCommand;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaygTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/WpCliStub.php';

/**
 * Command, called directly.
 */
class PaygCommandTest extends PaygTestCase {

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
	}

	private function lines(): string {
		return implode( "\n", array_column( \WP_CLI::$lines, 1 ) );
	}

	private function failure( callable $call ): string {
		try {
			$call();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_check_prints_the_preview_and_every_check_and_fails_on_an_error(): void {
		$product = $this->payg_product();
		( new PaygCommand() )->check( array( (string) $product->get_id() ), array() );

		$this->assertStringContainsString( 'Billing: pay as you go by the hour', $this->lines() );
		$this->assertStringContainsString( 'EUR 15.00 monthly, 0.0226 per hour, max 16.50', $this->lines() );
		$this->assertSame( 6, substr_count( $this->lines(), '[OK]' ) + substr_count( $this->lines(), '[WARN]' ) );
		$this->assertStringContainsString( 'No errors.', $this->lines() );

		$product->update_meta_data( Rates::META_PREMIUM, '-1' );
		$product->save();
		\WP_CLI::reset();

		$this->assertSame( 'The pay as you go setup has errors.', $this->failure( fn() => ( new PaygCommand() )->check( array( (string) $product->get_id() ), array() ) ) );
		$this->assertStringContainsString( '[ERROR] The premium is not a number of 0 or more.', $this->lines() );
	}

	public function test_a_product_is_found_by_sku_and_an_unknown_one_is_an_error(): void {
		$product = $this->payg_product();
		$product->set_sku( 'PAYG-CLI-' . wp_generate_password( 4, false ) );
		$product->save();

		( new PaygCommand() )->check( array( $product->get_sku() ), array() );
		$this->assertStringContainsString( 'No errors.', $this->lines() );
		$this->assertSame( 'No such product.', $this->failure( fn() => ( new PaygCommand() )->check( array( 'NO-SUCH-SKU' ), array() ) ) );
	}

	public function test_a_per_unit_product_prints_what_a_thousand_units_cost(): void {
		$product = $this->payg_product( 'counting', 'counted', array( 'Per order' => '0' ) );
		$v       = $this->variation_with( $product, 'plan', 'Per order' );
		$v->update_meta_data( Rates::META_UNIT_STEPS, '500:0.025,0:0.015' );
		$v->save();

		( new PaygCommand() )->check( array( (string) $product->get_id() ), array() );

		$this->assertStringContainsString( 'Billing: pay per unit', $this->lines() );
		$this->assertStringContainsString( 'EUR 1000 units 20.00', $this->lines() );
	}

	public function test_monthly_products_and_services_that_cannot_be_billed_by_use_say_so(): void {
		( new PaygCommand() )->check( array( (string) $this->monthly_product( 'proxmox' )->get_id() ), array() );
		$this->assertSame( 'Billed as a monthly subscription. Nothing to check.', $this->lines() );

		\WP_CLI::reset();
		( new PaygCommand() )->check( array( (string) $this->monthly_product( 'fake' )->get_id() ), array() );
		$this->assertSame( 'This service cannot be billed by use.', $this->lines() );
	}
}

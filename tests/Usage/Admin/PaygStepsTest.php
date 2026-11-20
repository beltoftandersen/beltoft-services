<?php
/**
 * The price steps text: strict parse, compose, cost.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Usage\Admin\PaygSteps;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaygTestCase.php';

/**
 * Steps.
 */
class PaygStepsTest extends PaygTestCase {

	public function test_a_valid_text_parses_to_rows_and_composes_back(): void {
		$rows = PaygSteps::parse( '500:0.025,2000:0.02,0:0.015' );

		$this->assertSame( array( array( '500', '0.025' ), array( '2000', '0.02' ), array( '0', '0.015' ) ), $rows );
		$this->assertSame( '500:0.025,2000:0.02,0:0.015', PaygSteps::compose( $rows ) );
		$this->assertSame( array(), PaygSteps::parse( '  ' ) );
	}

	public function test_a_text_rates_could_not_read_is_refused(): void {
		foreach ( array( '500:0.025,400:0.02,0:0.01', '0:0.01,500:0.02', '500:abc', '500:-1', 'x:1', '1:2:3', '0.5:1' ) as $bad ) {
			$this->assertNull( PaygSteps::parse( $bad ), $bad );
		}
	}

	public function test_the_same_steps_typed_differently_are_the_same(): void {
		$this->assertTrue( PaygSteps::same( '0.015', '0:0.015' ) );
		$this->assertTrue( PaygSteps::same( '500:0.50,0:0.1', '500:0.5,0:0.10' ) );
		$this->assertFalse( PaygSteps::same( '500:0.5,0:0.1', '500:0.5,0:0.2' ) );
	}

	public function test_the_cost_is_each_steps_units_at_its_price(): void {
		$product = $this->payg_product( 'counting', 'counted', array( 'Per order' => '0' ) );
		$v       = $this->variation_with( $product, 'plan', 'Per order' );
		$v->update_meta_data( Rates::META_UNIT_STEPS, '500:0.025,2000:0.02,0:0.015' );

		$this->assertEqualsWithDelta( 22.5, PaygSteps::cost( Rates::unit_steps( $v ), 1000 ), 0.0001 );
		$this->assertEqualsWithDelta( 12.5, PaygSteps::cost( Rates::unit_steps( $v ), 500 ), 0.0001 );
	}
}

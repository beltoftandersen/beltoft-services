<?php
/**
 * The credit history names what an invoice paid with credit was for, and links to it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Invoice;
use BeltoftServices\Credit\Page;

/**
 * History rows of credit used on an order.
 */
final class HistoryLabelTest extends CreditTestCase {

	/**
	 * An order of the customer with one product.
	 *
	 * @param int    $user Customer.
	 * @param string $name Product name.
	 */
	private function order_with( int $user, string $name ): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * The newest ledger row of a customer.
	 *
	 * @param int $user Customer.
	 */
	private function newest( int $user ): array {
		return Balance::history( $user, 1 )[0];
	}

	public function test_credit_used_on_an_order_names_the_product_and_links_the_order(): void {
		$user  = self::factory()->user->create();
		$order = $this->order_with( $user, 'WordPress hosting <b>Pro</b>' );

		$this->assertTrue( Balance::credit( $user, 5000, Balance::PROMO, 'test', 'grant-' . $user, 'Welcome' ) );
		$this->assertTrue( Balance::debit( $user, 1000, Balance::INVOICE, Invoice::REF_TYPE, (string) $order->get_id(), 'Used on invoice #' . $order->get_order_number() ) );

		$html = Page::row_html( $this->newest( $user ) );

		$this->assertStringContainsString( '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . $order->get_order_number() . '</a>', $html );
		$this->assertStringContainsString( ': WordPress hosting Pro', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_someone_elses_order_and_other_rows_stay_plain_text(): void {
		$user  = self::factory()->user->create();
		$other = $this->order_with( self::factory()->user->create(), 'Not yours' );

		$this->assertTrue( Balance::credit( $user, 5000, Balance::PROMO, 'test', 'grant-' . $user, 'Welcome <i>x</i>' ) );
		$this->assertSame( esc_html( Page::row_label( $this->newest( $user ) ) ), Page::row_html( $this->newest( $user ) ) );

		$this->assertTrue( Balance::debit( $user, 1000, Balance::INVOICE, Invoice::REF_TYPE, (string) $other->get_id(), 'Used on invoice #1' ) );
		$html = Page::row_html( $this->newest( $user ) );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'Not yours', $html );
	}
}

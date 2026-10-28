<?php
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsSchema;
use BeltoftServices\Domains\ThankYou;
use BeltoftServices\Tests\TestCase;

final class ThankYouTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		DnsSchema::maybe_upgrade();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
	}

	private function order( string $mode, ?bool $ds = null, string $status = 'completed' ): \WC_Order {
		$user  = self::factory()->user->create();
		$claim = DnsClaims::create( $user, 'example.com' );
		if ( null !== $ds ) {
			DnsClaims::set_status( (int) $claim['id'], 'consumed', array( 'ds_present' => $ds ? 1 : 0 ) );
		}
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'DNS hosting' );
		$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => 'example.com', 'mode' => $mode, 'claim_id' => (int) $claim['id'] ), true );
		$order->add_item( $item );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	private function page( int $order_id ): string {
		ob_start();
		$order = wc_get_order( $order_id );
		global $wp;
		$wp->query_vars['order-received'] = $order_id;
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
		if ( $order ) {
			do_action( 'woocommerce_order_details_before_order_table', $order );
		}
		do_action( 'woocommerce_thankyou', $order_id );
		remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		unset( $wp->query_vars['order-received'] );
		return (string) ob_get_clean();
	}

	public function test_dns_order_gets_next_steps_once(): void {
		$html = $this->page( $this->order( 'dns' )->get_id() );
		$this->assertSame( 1, substr_count( $html, 'Next steps for example.com' ) );
		$this->assertStringContainsString( 'ns1.example.net', $html );
		$this->assertStringContainsString( 'ns2.example.net', $html );
		$this->assertStringContainsString( 'If your domain uses DNSSEC', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_known_ds_gives_a_firm_step_and_no_ds_gives_none(): void {
		$this->assertStringContainsString( 'Your domain uses DNSSEC. Remove the DS record', $this->page( $this->order( 'dns', true )->get_id() ) );
		$html = $this->page( $this->order( 'dns', false )->get_id() );
		$this->assertStringNotContainsString( 'DNSSEC', $html );
		$this->assertStringContainsString( 'change its name servers', $html );
	}

	public function test_a_later_view_of_the_order_does_not_repeat_the_steps(): void {
		$order = $this->order( 'dns' );
		ob_start();
		do_action( 'woocommerce_order_details_before_order_table', $order );
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', (string) ob_get_clean() );
	}

	public function test_other_orders_and_failed_orders_get_nothing(): void {
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', $this->page( $this->order( 'register' )->get_id() ) );
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', $this->page( $this->order( 'dns', null, 'failed' )->get_id() ) );
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', $this->page( 0 ) );
	}
}

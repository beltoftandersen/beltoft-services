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

	private function order( string $mode, ?bool $ds = null, string $status = 'completed', string $dns = '' ): \WC_Order {
		$user  = self::factory()->user->create();
		$claim = DnsClaims::create( $user, 'example.com' );
		if ( null !== $ds ) {
			DnsClaims::set_status( (int) $claim['id'], 'consumed', array( 'ds_present' => $ds ? 1 : 0 ) );
		}
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'DNS hosting' );
		$item->add_meta_data( '_beltoft_services_domain', array( 'domain' => 'example.com', 'mode' => $mode, 'claim_id' => (int) $claim['id'] ) + ( '' !== $dns ? array( 'dns' => $dns ) : array() ), true );
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

	public function test_a_transfer_order_says_what_happens_now_once(): void {
		$html = $this->page( $this->order( 'transfer' )->get_id() );
		$this->assertSame( 1, substr_count( $html, 'What happens now with example.com' ) );
		$this->assertStringContainsString( 'may ask you to approve the transfer', $html );
		$this->assertStringContainsString( 'The transfer takes up to 5 days.', $html );
		$this->assertStringContainsString( 'We email you when the domain is with us.', $html );
		$this->assertStringContainsString( 'Your DNS stays where it is now. Your name servers do not change.', $html );
		$this->assertStringNotContainsString( 'Add your DNS records', $html );
		$this->assertStringNotContainsString( 'uses our name servers', $html );
		$this->assertStringNotContainsString( 'Your DNS stays as it is.', $html );
		$this->assertStringContainsString( esc_url( wc_get_account_endpoint_url( 'domains' ) ), $html );
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', $html, 'No DNS block for a transfer.' );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_a_transfer_of_a_domain_our_dns_already_serves_says_the_dns_stays(): void {
		$order = $this->order( 'transfer' );
		$user  = (int) $order->get_customer_id();
		$claim = DnsClaims::create( $user, 'example.com' );
		$this->assertTrue( \BeltoftServices\Domains\DnsAssignments::reserve( $user, (int) $claim['id'], 'example.com' ) );
		$this->assertTrue( \BeltoftServices\Domains\DnsAssignments::activate( 'example.com', $user, 1, 1 ) );
		$html = $this->page( $order->get_id() );
		$this->assertStringContainsString( 'Your DNS stays as it is.', $html );
		$this->assertStringNotContainsString( 'uses our name servers', $html );
		$this->assertStringNotContainsString( 'name servers do not change', $html );
	}

	public function test_a_transfer_says_what_happens_for_each_dns_choice(): void {
		$first = $this->page( $this->order( 'transfer', null, 'completed', 'first' )->get_id() );
		$this->assertStringContainsString( 'The transfer starts when your domain uses our name servers.', $first );
		$this->assertStringContainsString( 'set at your current registrar', $first );
		$this->assertStringContainsString( 'Turn off DNSSEC there first.', $first );
		$this->assertStringContainsString( 'within 14 days', $first );
		$this->assertStringContainsString( 'The transfer takes up to 5 days.', $first );
		$this->assertStringNotContainsString( 'Your DNS stays where it is now', $first );
		$after = $this->page( $this->order( 'transfer', null, 'completed', 'after' )->get_id() );
		$this->assertStringContainsString( 'We have asked for the transfer of your domain.', $after );
		$this->assertStringContainsString( 'keeps using its current name servers until the transfer is done. Then we move it to ours.', $after );
		$keep = $this->page( $this->order( 'transfer', null, 'completed', 'keep' )->get_id() );
		$this->assertStringContainsString( 'Your DNS stays where it is now. Your name servers do not change.', $keep );
		$this->assertStringContainsString( 'may switch it off', $keep );
		foreach ( array( $first, $after, $keep ) as $html ) {
			$this->assertStringNotContainsString( "\u{2014}", $html );
			$this->assertStringNotContainsString( "\u{00B7}", $html );
		}
	}

	public function test_the_transfer_days_have_a_filter(): void {
		add_filter( 'beltoft_services_transfer_days', static fn() => 7 );
		$this->assertStringContainsString( 'up to 7 days', $this->page( $this->order( 'transfer' )->get_id() ) );
	}

	public function test_a_failed_transfer_order_prints_nothing(): void {
		$this->assertStringNotContainsString( 'beltoft-transfer-next-steps', $this->page( $this->order( 'transfer', null, 'failed' )->get_id() ) );
	}

	public function test_a_dns_order_has_no_transfer_block(): void {
		$this->assertStringNotContainsString( 'beltoft-transfer-next-steps', $this->page( $this->order( 'dns' )->get_id() ) );
	}

	public function test_other_orders_and_failed_orders_get_nothing(): void {
		$this->assertStringNotContainsString( 'beltoft-transfer-next-steps', $this->page( $this->order( 'register' )->get_id() ) );
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', $this->page( $this->order( 'register' )->get_id() ) );
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', $this->page( $this->order( 'dns', null, 'failed' )->get_id() ) );
		$this->assertStringNotContainsString( 'beltoft-dns-next-steps', $this->page( 0 ) );
	}
}

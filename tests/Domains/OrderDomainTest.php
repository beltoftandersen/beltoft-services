<?php
/**
 * The domain tiles of an order form: the shared markup, the cart reading and the removal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\OrderDomain;
use BeltoftServices\Tests\TestCase;

/**
 * OrderDomain.
 */
class OrderDomainTest extends TestCase {

	public function tear_down(): void {
		unset( $_POST['nonce'], $_POST['domain'], $_REQUEST['nonce'] );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		parent::tear_down();
	}

	private function add_domain_line( string $name, string $mode = 'register' ): string {
		$domain = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$key    = WC()->cart->add_to_cart( $domain->get_id(), 1, 0, array(), array( 'beltoft_services_domain' => array( 'domain' => $name, 'mode' => $mode ) ) );

		if ( ! $key ) {
			$this->markTestSkipped( 'The fixture domain product cannot be put in the cart here.' );
		}

		return (string) $key;
	}

	public function test_the_cart_domains_are_the_register_and_transfer_lines_only(): void {
		WC()->cart->empty_cart();
		$this->assertSame( array(), OrderDomain::cart_domains() );

		$this->add_domain_line( 'Fresh.example', 'register' );
		$dns = $this->add_domain_line( 'dns-only.example', 'register' );
		WC()->cart->cart_contents[ $dns ]['beltoft_services_domain']['mode'] = 'dns';

		$this->assertSame( array( array( 'domain' => 'fresh.example', 'mode' => 'register' ) ), OrderDomain::cart_domains() );
	}

	public function test_the_tiles_are_a_real_radio_group_and_the_checked_one_is_marked(): void {
		ob_start();
		OrderDomain::print_tiles( array(
			'name'    => 'f[mode]',
			'id'      => 'f',
			'legend'  => 'Where',
			'checked' => 'transfer',
			'items'   => array(
				array( 'value' => 'register' ) + OrderDomain::tile( 'register' ),
				array( 'value' => 'transfer' ) + OrderDomain::tile( 'transfer' ),
				array( 'value' => 'own' ) + OrderDomain::tile( 'own' ),
			),
		) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<fieldset', $html );
		$this->assertStringContainsString( '<legend', $html );
		$this->assertSame( 3, substr_count( $html, 'type="radio" name="f[mode]"' ) );
		$this->assertMatchesRegularExpression( '/value="transfer" checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="register" checked/', $html );
		$this->assertStringContainsString( 'Register a new domain', $html );
		$this->assertStringContainsString( 'Transfer a domain', $html );
		$this->assertStringContainsString( 'Use my own domain', $html );
	}

	public function test_a_panel_shows_the_chosen_domain_or_the_form(): void {
		ob_start();
		OrderDomain::print_panel( 'register', 'f', true, '', '' );
		$empty = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-od-panel="register"', $empty );
		$this->assertStringContainsString( 'data-od-go', $empty );
		$this->assertStringContainsString( '>Search</button>', $empty );
		$this->assertMatchesRegularExpression( '/data-od-chosen hidden/', $empty );
		$this->assertDoesNotMatchRegularExpression( '/data-od-form hidden/', $empty );

		ob_start();
		OrderDomain::print_panel( 'transfer', 'f', false, 'moving.example', '', 'A note.' );
		$chosen = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-od-panel="transfer" hidden', $chosen );
		$this->assertStringContainsString( 'Transfer of moving.example is added to this order.', $chosen );
		$this->assertStringContainsString( 'data-od-remove', $chosen );
		$this->assertMatchesRegularExpression( '/data-od-form hidden/', $chosen );
		$this->assertStringContainsString( '>Continue</button>', $chosen );
		$this->assertStringContainsString( 'A note.', $chosen );
		$this->assertStringNotContainsString( 'name=', preg_replace( '/<input type="hidden"[^>]*>/', '', $chosen ), 'The search inputs are never posted with the checkout.' );
	}

	public function test_the_script_gets_the_existing_routes_and_texts_without_dashes_or_dots_as_separators(): void {
		$config = OrderDomain::config();

		$this->assertStringContainsString( '/domains/check', $config['check'] );
		$this->assertStringContainsString( '/domains/cart', $config['cart'] );
		$this->assertNotSame( '', $config['nonce'] );
		$this->assertNotSame( '', $config['restNonce'] );
		$this->assertNotSame( '', $config['removeNonce'] );

		foreach ( OrderDomain::texts() as $text ) {
			$this->assertStringNotContainsString( "\u{2014}", $text );
			$this->assertStringNotContainsString( "\u{00B7}", $text );
		}
	}

	public function test_removing_takes_out_the_domain_line_and_nothing_else(): void {
		WC()->cart->empty_cart();
		$this->add_domain_line( 'Fresh.example' );
		$other = $this->service_product( 'fake' );
		WC()->cart->add_to_cart( $other->get_id() );

		$this->assertSame( 0, OrderDomain::remove( 'not-in-cart.example' ) );
		$this->assertSame( 0, OrderDomain::remove( '' ) );
		$this->assertCount( 2, WC()->cart->get_cart() );

		$this->assertSame( 1, OrderDomain::remove( ' FRESH.example ' ) );
		$this->assertSame( array(), OrderDomain::cart_domains() );
		$this->assertCount( 1, WC()->cart->get_cart(), 'The service line stays.' );
	}

	public function test_the_removal_call_needs_its_nonce_and_a_domain_in_the_cart(): void {
		WC()->cart->empty_cart();
		$this->add_domain_line( 'Fresh.example' );

		$_POST['domain'] = 'fresh.example';
		$this->assertSame( 403, OrderDomain::respond()[1], 'No nonce.' );
		$this->assertSame( array( array( 'domain' => 'fresh.example', 'mode' => 'register' ) ), OrderDomain::cart_domains() );

		$_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce( OrderDomain::NONCE );
		$this->assertSame( array( array( 'ok' => true, 'removed' => 1 ), 200 ), OrderDomain::respond() );
		$this->assertSame( array(), OrderDomain::cart_domains() );
		$this->assertSame( 409, OrderDomain::respond()[1], 'Nothing left to remove.' );
	}

	public function test_the_removal_endpoint_is_hooked(): void {
		$this->assertNotFalse( has_action( 'wc_ajax_' . OrderDomain::AJAX, array( OrderDomain::class, 'ajax' ) ) );
	}
}

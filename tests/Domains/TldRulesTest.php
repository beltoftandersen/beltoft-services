<?php
/** Registrant rules per extension. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\Search;
use BeltoftServices\Domains\TldRules;
use BeltoftServices\Tests\TestCase;

class TldRulesTest extends TestCase {
	private function cart_with( string $domain ): void {
		$product = $this->service_product( 'domain' );
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), Cart::line_data( $domain, 'register' ) );
	}

	public function test_a_eu_domain_needs_an_eu_billing_country(): void {
		$this->cart_with( 'rules.eu' );
		$denied = new \WP_Error();
		TldRules::validate( array( 'billing_country' => 'US' ), $denied );
		$this->assertSame( 'beltoft_services_eu_registrant', $denied->get_error_code() );

		$allowed = new \WP_Error();
		TldRules::validate( array( 'billing_country' => 'DK' ), $allowed );
		$this->assertFalse( $allowed->has_errors() );
	}

	public function test_other_extensions_are_not_limited_by_country(): void {
		$this->cart_with( 'rules.com' );
		$errors = new \WP_Error();
		TldRules::validate( array( 'billing_country' => 'US' ), $errors );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_search_notes_name_what_matters_and_sites_can_change_them(): void {
		$this->assertSame( 'The registrant must be in the EU.', Search::note( 'eu' ) );
		$this->assertSame( 'Websites on this extension need HTTPS.', Search::note( '.app' ) );
		$this->assertSame( '', Search::note( 'com' ) );
		add_filter( 'beltoft_services_domain_tld_notes', static fn( $n ) => array( 'com' => 'Plain note.' ) + $n );
		$this->assertSame( 'Plain note.', Search::note( 'com' ) );
	}
}

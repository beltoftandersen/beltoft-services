<?php
/**
 * The domain search markup: the shortcode is unchanged and the builder honours custom texts.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Bricks;

use BeltoftServices\Api\Routes;
use BeltoftServices\Bricks\Integration;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\TestCase;

/**
 * Tests.
 */
final class DomainSearchMarkupTest extends TestCase {

	public function test_the_shortcode_output_is_what_it_always_was(): void {
		$expected = '<div class="beltoft-domain-search" data-check="' . esc_url( rest_url( Routes::NAMESPACE . '/domains/check' ) ) . '" data-cart="' . esc_url( rest_url( Routes::NAMESPACE . '/domains/cart' ) ) . '" data-transfer-dns="' . esc_url( rest_url( Routes::NAMESPACE . '/domains/transfer-dns' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wc_store_api' ) ) . '" data-rest-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"><label for="beltoft-domain-name">Find a domain</label><input id="beltoft-domain-name" type="search" autocomplete="off"><button type="button">Search</button><div class="beltoft-domain-results" aria-live="polite"></div></div>';
		$this->assertSame( $expected, do_shortcode( '[beltoft_domain_search]' ) );
		$this->assertTrue( wp_script_is( 'beltoft-services-domain-search', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'beltoft-services-domain-search', 'enqueued' ) );
	}

	public function test_the_markup_builder_uses_custom_texts_and_escapes_them(): void {
		$html = Search::inner_markup( array( 'label' => 'Your <b>domain</b>', 'placeholder' => 'mysite.com', 'button' => 'Go' ) );
		$this->assertStringContainsString( '>Your &lt;b&gt;domain&lt;/b&gt;</label>', $html );
		$this->assertStringContainsString( 'placeholder="mysite.com"', $html );
		$this->assertStringContainsString( '<button type="button">Go</button>', $html );
	}

	public function test_empty_texts_fall_back_to_the_defaults(): void {
		$html = Search::inner_markup( array( 'label' => '', 'button' => '' ) );
		$this->assertStringContainsString( '>Find a domain</label>', $html );
		$this->assertStringContainsString( '<button type="button">Search</button>', $html );
		$this->assertStringNotContainsString( 'placeholder', $html );
	}

	public function test_a_transfer_address_adds_the_link_under_the_form(): void {
		$html = Search::inner_markup( array(), '#transfer' );
		$this->assertStringContainsString( '<p class="beltoft-domain-search__transfer"><a href="#transfer">Transfer a domain you already own</a></p>', $html );
		$this->assertLessThan( strpos( $html, 'beltoft-domain-results' ), strpos( $html, 'beltoft-domain-search__transfer' ) );
		$this->assertStringContainsString( '>Move it</a>', Search::inner_markup( array( 'transfer' => 'Move it' ), '/domains/#transfer' ) );
		$this->assertStringNotContainsString( 'beltoft-domain-search__transfer', Search::inner_markup( array(), '  ' ) );
		$this->assertStringContainsString( 'href="#transfer"', do_shortcode( '[beltoft_domain_search transfer_url="#transfer"]' ) );
	}

	public function test_the_transfer_shortcode_is_its_own_form(): void {
		$html = do_shortcode( '[beltoft_domain_transfer]' );
		$this->assertStringStartsWith( '<div class="beltoft-domain-transfer-box" data-check="', $html );
		$this->assertStringContainsString( '>Domain to transfer</label><input id="beltoft-transfer-domain" type="text" inputmode="url" autocomplete="off"><button type="button">Continue</button><div class="beltoft-domain-transfer-result" aria-live="polite"></div>', $html );
		$this->assertStringNotContainsString( 'type="search"', $html, 'The search script binds to the search input only.' );
		$this->assertTrue( wp_script_is( 'beltoft-services-domain-search', 'enqueued' ) );
	}

	public function test_the_search_offers_no_transfer_button_and_greys_out_a_taken_domain(): void {
		$js  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.js' );
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.css' );
		$this->assertStringNotContainsString( "'Transfer domain'", $js );
		$this->assertStringContainsString( "taken: __('Taken', 'beltoft-services')", $js );
		$this->assertStringContainsString( "item.status === 'free' && item.price", $js, 'A taken domain shows no price.' );
		$this->assertStringContainsString( '.beltoft-domain-result--taken', $css );
		$this->assertStringContainsString( 'initTransfer', $js );
		$this->assertStringContainsString( "primary && item.status !== 'taken'", $js, 'A taken domain is not highlighted.' );
	}

	public function test_the_script_hooks_stay_in_the_markup(): void {
		$html = Search::inner_markup();
		$this->assertStringContainsString( 'type="search"', $html );
		$this->assertStringContainsString( 'class="beltoft-domain-results"', $html );
		$this->assertSame( array( 'data-check', 'data-cart', 'data-transfer-dns', 'data-nonce', 'data-rest-nonce' ), array_keys( Search::root_data() ) );
	}

	/**
	 * Own process: other tests load stand-in Bricks classes, and a class cannot be unloaded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_registered_without_bricks(): void {
		$this->assertFalse( class_exists( '\\Bricks\\Elements', false ) );
		$this->assertSame( array(), Integration::register_elements() );
		$this->assertNotFalse( has_action( 'init', array( Integration::class, 'register_elements' ) ) );
		$this->assertSame( 11, has_action( 'init', array( Integration::class, 'register_elements' ) ) );
	}

	public function test_the_stylesheet_has_no_fixed_site_colours_and_no_radius(): void {
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.css' );
		$bare = str_replace( array( 'var(--green, #28513e)', 'var(--rule, #d6d2c8)', 'var(--ink-grey, #59615c)' ), '', $css );
		$this->assertDoesNotMatchRegularExpression( '/#(28513e|d6d2c8|59615c)/i', $bare );
		$this->assertStringContainsString( 'var(--bds-accent, var(--green, #28513e))', $css );
		$this->assertStringNotContainsString( 'border-radius', $css );
	}

	public function test_the_script_hands_the_search_to_the_search_page_and_starts_a_search_from_the_address(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.js' );
		$this->assertStringContainsString( 'root.dataset.searchUrl', $js );
		$this->assertStringContainsString( 'window.location.assign(target', $js );
		$this->assertStringContainsString( "new URLSearchParams(window.location.search).get(param)", $js );
		// The back button keeps the term: saved before the hand-off, and in the address of the results page.
		$this->assertStringContainsString( 'remember(typed, null)', $js );
		$this->assertStringContainsString( 'window.history.replaceState', $js );
		$this->assertStringContainsString( 'if (saved.data && !root.dataset.searchUrl) render(saved.data, saved.q);', $js );
		// Arriving with a term scrolls the search into view, below a fixed header.
		$this->assertMatchesRegularExpression( '/input\.value = asked;\s*root\.scrollIntoView\(/', $js );
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.css' );
		$this->assertStringContainsString( 'scroll-margin-top: var(--bds-scroll-offset, 96px);', $css );
	}

	public function test_the_script_shows_the_offer_only_with_a_free_domain_and_handles_extension_links(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.js' );
		$this->assertStringContainsString( "offer.hidden = !shown.some(function (item) { return item.status === 'free'; });", $js );
		$this->assertStringContainsString( '[data-beltoft-domain-ext]', $js );
		$this->assertStringContainsString( 'root.beltoftSearch = search;', $js );
	}
}

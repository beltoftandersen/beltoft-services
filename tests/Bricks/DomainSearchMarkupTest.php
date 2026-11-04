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
}

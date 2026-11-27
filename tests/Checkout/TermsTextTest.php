<?php
/**
 * The checkout terms box shows the text of the terms page, in the visitor's language.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Checkout;

use BeltoftServices\Checkout\TermsText;
use BeltoftServices\Tests\TestCase;

class TermsTextTest extends TestCase {

	private function page( string $title, string $bricks_text, string $post_content, string $status = 'publish' ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => $title, 'post_status' => $status, 'post_content' => $post_content ) );
		update_post_meta(
			$id,
			'_bricks_page_content_2',
			array(
				array( 'id' => 'aaaaaa', 'name' => 'heading', 'parent' => 0, 'settings' => array( 'text' => $title ) ),
				array( 'id' => 'bbbbbb', 'name' => 'text', 'parent' => 'aaaaaa', 'settings' => array( 'text' => $bricks_text ) ),
			)
		);

		return $id;
	}

	public function tear_down(): void {
		remove_all_filters( 'woocommerce_get_terms_page_id' );
		delete_option( 'woocommerce_terms_page_id' );
		remove_all_actions( 'woocommerce_checkout_terms_and_conditions' );
		parent::tear_down();
	}

	private function box(): string {
		remove_all_actions( 'woocommerce_checkout_terms_and_conditions' );
		TermsText::register();
		add_action( 'woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30 );
		ob_start();
		do_action( 'woocommerce_checkout_terms_and_conditions' );

		return (string) ob_get_clean();
	}

	public function test_the_box_shows_the_bricks_text_and_not_the_old_post_content(): void {
		$en = $this->page( 'Terms of Sale', '<h2>12E. Databases</h2><p>Daily backup is an add-on.</p>', '<p>The old text, 7 daily backups.</p>' );
		update_option( 'woocommerce_terms_page_id', $en );

		$html = $this->box();
		$this->assertStringContainsString( 'woocommerce-terms-and-conditions', $html );
		$this->assertStringContainsString( 'Daily backup is an add-on.', $html );
		$this->assertStringNotContainsString( 'The old text', $html );
		$this->assertSame( TermsText::html(), wc_format_content( wp_kses_post( '<h2>12E. Databases</h2><p>Daily backup is an add-on.</p>' ) ), 'The same text as the page element.' );
	}

	public function test_a_language_plugin_that_answers_with_the_danish_page_gives_the_danish_text(): void {
		$en = $this->page( 'Terms of Sale', '<p>English terms.</p>', '' );
		$da = $this->page( 'Salgsbetingelser', '<h2>12E. Databaser</h2><p>Daglig backup er et tilkøb.</p>', '' );
		update_option( 'woocommerce_terms_page_id', $en );
		add_filter( 'woocommerce_get_terms_page_id', static fn() => $da );

		$html = $this->box();
		$this->assertStringContainsString( 'Daglig backup er et tilkøb.', $html );
		$this->assertStringNotContainsString( 'English terms.', $html );
	}

	public function test_the_text_is_sanitized(): void {
		$id = $this->page( 'Terms of Sale', '<p>Fine</p><script>alert(1)</script><img src=x onerror=alert(2)>', '' );
		update_option( 'woocommerce_terms_page_id', $id );

		$html = $this->box();
		$this->assertStringContainsString( 'Fine', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
	}

	public function test_a_page_without_bricks_text_or_not_published_keeps_woocommerces_box(): void {
		$plain = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>Plain page text.</p>' ) );
		update_option( 'woocommerce_terms_page_id', $plain );
		$this->assertSame( '', TermsText::html() );
		$this->assertStringContainsString( 'Plain page text.', $this->box() );

		$draft = $this->page( 'Terms', '<p>Draft text.</p>', '', 'draft' );
		update_option( 'woocommerce_terms_page_id', $draft );
		$this->assertSame( '', TermsText::html() );
	}
}

<?php
/** The text domain loader and the script translations. @package BeltoftServices */
namespace BeltoftServices\Tests;

use BeltoftServices\Plugin;

class TextdomainTest extends TestCase {
	public function test_the_loader_is_hooked_on_init_and_the_header_names_the_domain_path(): void {
		$this->assertNotFalse( has_action( 'init', array( Plugin::class, 'load_textdomain' ) ) );
		$header = file_get_contents( BELTOFT_SERVICES_FILE );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Text Domain:\s+beltoft-services\s*$/m', $header );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Domain Path:\s+\/languages\s*$/m', $header );
	}

	public function test_the_domain_search_script_depends_on_wp_i18n_and_has_its_translations_registered(): void {
		\BeltoftServices\Domains\Search::enqueue_assets();
		$script = wp_scripts()->registered['beltoft-services-domain-search'];
		$this->assertContains( 'wp-i18n', $script->deps );
	}

	public function test_the_search_script_has_no_hardcoded_customer_text(): void {
		$js = file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain-search.js' );
		$this->assertStringContainsString( "__('Register domain', 'beltoft-services')", $js );
		$this->assertStringNotContainsString( "'Register domain')", str_replace( "__('Register domain', 'beltoft-services')", '', $js ) );
	}
}

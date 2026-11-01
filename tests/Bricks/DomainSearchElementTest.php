<?php
/**
 * The Bricks element with a minimal Bricks stub. Runs in its own process: the stub defines the \Bricks classes,
 * which must not exist for the other tests (the "without Bricks" guard).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Bricks;

use BeltoftServices\Bricks\Integration;
use BeltoftServices\Tests\TestCase;

/**
 * Tests.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DomainSearchElementTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( class_exists( '\\Bricks\\Element', false ) ) { return; }
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a minimal stand-in for the Bricks classes, test only.
			'namespace Bricks;
			class Element {
				public $controls = array(); public $control_groups = array(); public $settings = array(); public $attrs = array();
				public function set_attribute( $key, $name, $value = null ) { $this->attrs[ $key ][ $name ][] = $value; }
				public function render_attributes( $key ) { $out = array(); foreach ( $this->attrs[ $key ] as $n => $v ) { $out[] = $n . "=\"" . esc_attr( implode( " ", $v ) ) . "\""; } return implode( " ", $out ); }
			}
			class Elements {
				public static $registered = array();
				public static function register_element( $file, $name = "", $class = "" ) { self::$registered[ $name ] = array( $file, $class ); }
			}'
		);
	}

	private function element( array $settings = array() ) {
		$class         = Integration::ELEMENTS['beltoft-domain-search']['class'];
		require_once BELTOFT_SERVICES_PATH . 'src/Bricks/DomainSearchElement.php';
		$element           = new $class();
		$element->settings = $settings;
		$element->set_control_groups();
		$element->set_controls();
		return $element;
	}

	public function test_the_element_is_registered_when_bricks_exists(): void {
		$this->assertSame( array( 'beltoft-domain-search' ), Integration::register_elements() );
		$this->assertSame( Integration::ELEMENTS['beltoft-domain-search']['class'], \Bricks\Elements::$registered['beltoft-domain-search'][1] );
		$this->assertFileExists( \Bricks\Elements::$registered['beltoft-domain-search'][0] );
	}

	public function test_every_control_has_a_label_a_known_group_and_a_css_rule_or_is_content(): void {
		$element = $this->element();
		$this->assertNotEmpty( $element->controls );
		foreach ( $element->controls as $key => $control ) {
			$this->assertNotEmpty( $control['label'], $key );
			$this->assertArrayHasKey( $control['group'], $element->control_groups, $key );
			$this->assertTrue( isset( $control['css'] ) || 'separator' === $control['type'] || 'texts' === $control['group'], $key );
		}
	}

	public function test_no_control_offers_a_border_radius_or_a_fixed_site_value(): void {
		$json = (string) wp_json_encode( $this->element()->controls );
		$this->assertStringNotContainsString( 'radius', $json );
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{6}/i', $json );
	}

	public function test_style_controls_target_the_markup_classes_and_hover(): void {
		$controls = $this->element()->controls;
		$this->assertSame( '> button:hover', $controls['searchButtonHoverBackground']['css'][0]['selector'] );
		$this->assertSame( '.beltoft-domain-action:hover', $controls['resultButtonHoverColor']['css'][0]['selector'] );
		$this->assertSame( '.beltoft-domain-result--primary', $controls['primaryBackground']['css'][0]['selector'] );
		$this->assertSame( '--bds-bar', $controls['loadingBar']['css'][0]['property'] );
		$this->assertSame( 'typography', $controls['nameTypography']['type'] );
	}

	public function test_render_prints_the_shortcode_markup_with_the_custom_texts(): void {
		$element = $this->element( array( 'labelText' => 'Pick a name', 'placeholder' => 'name.com', 'buttonText' => 'Look up' ) );
		ob_start();
		$element->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'class="beltoft-domain-search"', $html );
		$this->assertStringContainsString( 'data-check="', $html );
		$this->assertStringContainsString( 'data-rest-nonce="', $html );
		$this->assertStringContainsString( '>Pick a name</label>', $html );
		$this->assertStringContainsString( 'placeholder="name.com"', $html );
		$this->assertStringContainsString( '>Look up</button>', $html );
		$this->assertStringContainsString( 'class="beltoft-domain-results"', $html );
	}

	public function test_render_without_settings_matches_the_shortcode_inner_markup(): void {
		ob_start();
		$this->element()->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( \BeltoftServices\Domains\Search::inner_markup(), $html );
	}
}

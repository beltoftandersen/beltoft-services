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
				public $id = "abc123"; public $controls = array(); public $control_groups = array(); public $settings = array(); public $attrs = array();
				public function set_attribute( $key, $name, $value = null ) { $this->attrs[ $key ][ $name ][] = $value; }
				public function render_attributes( $key ) { $out = array(); foreach ( $this->attrs[ $key ] as $n => $v ) { $out[] = $n . "=\"" . esc_attr( implode( " ", $v ) ) . "\""; } return implode( " ", $out ); }
			}
			class Query {
				public static $object = null;
				public static function get_loop_object( $id = "" ) { return self::$object; }
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
		$this->assertSame( array( 'beltoft-domain-search', 'beltoft-domain-search-form', 'beltoft-domain-transfer' ), Integration::register_elements() );
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

	public function test_the_transfer_address_control_adds_the_link(): void {
		ob_start();
		$this->element( array( 'transferUrl' => '#transfer', 'transferLinkText' => 'Move a domain here' ) )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<a href="#transfer">Move a domain here</a>', $html );
		$this->assertSame( 'texts', $this->element()->controls['transferUrl']['group'] );
	}

	public function test_a_search_page_address_sends_the_search_there_with_the_url_parameter(): void {
		ob_start();
		$this->element( array( 'searchUrl' => '/domains/' ) )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-search-url="/domains/"', $html );
		$this->assertStringContainsString( 'data-param="domain"', $html );
		$this->assertSame( 'texts', $this->element()->controls['searchUrl']['group'] );
	}

	public function test_the_transfer_link_in_a_result_row_has_its_own_style_after_the_form_link(): void {
		$controls = $this->element()->controls;
		$keys     = array_keys( $controls );
		$this->assertSame( '.beltoft-domain-results .beltoft-domain-result__transfer', $controls['resultLinkTypography']['css'][0]['selector'] );
		$this->assertGreaterThan( array_search( 'linkTypography', $keys, true ), array_search( 'resultLinkTypography', $keys, true ) );
	}

	public function test_an_offer_under_the_results_is_printed_hidden_with_its_link(): void {
		ob_start();
		$this->element( array( 'offerText' => 'Add hosting with email', 'offerLinkText' => 'See the plans', 'offerUrl' => '/wordpress-hosting/' ) )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<p class="beltoft-domain-offer" hidden>Add hosting with email <a class="beltoft-domain-offer__link" href="/wordpress-hosting/">See the plans</a></p>', $html );
		$controls = $this->element()->controls;
		$this->assertSame( 'texts', $controls['offerText']['group'] );
		$this->assertSame( '.beltoft-domain-offer', $controls['offerBackground']['css'][0]['selector'] );
		$this->assertSame( '.beltoft-domain-offer__link', $controls['offerLinkTypography']['css'][0]['selector'] );
	}

	public function test_without_an_offer_text_there_is_no_offer(): void {
		ob_start();
		$this->element( array( 'offerUrl' => '/wordpress-hosting/' ) )->render();
		$this->assertStringNotContainsString( 'beltoft-domain-offer', (string) ob_get_clean() );
	}

	public function test_without_a_search_page_address_the_search_stays_on_the_page(): void {
		ob_start();
		$this->element()->render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-search-url', $html );
		$this->assertStringContainsString( 'data-param="domain"', $html );
	}

	public function test_the_transfer_element_renders_its_own_form_without_search_controls(): void {
		Integration::register_elements();
		require_once BELTOFT_SERVICES_PATH . 'src/Bricks/DomainTransferElement.php';
		$el           = new \BeltoftServices\Bricks\DomainTransferElement();
		$el->settings = array( 'buttonText' => 'Check' );
		$el->set_control_groups();
		$el->set_controls();
		$this->assertArrayNotHasKey( 'transferUrl', $el->controls );
		$this->assertArrayNotHasKey( 'takenBackground', $el->controls );
		$this->assertArrayHasKey( 'transferInputBorder', $el->controls );
		$this->assertSame( 'Domain to transfer', $el->controls['labelText']['placeholder'] );
		foreach ( $el->controls as $key => $control ) {
			$this->assertArrayHasKey( $control['group'], $el->control_groups, $key );
		}
		ob_start();
		$el->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'class="beltoft-domain-transfer-box"', $html );
		$this->assertStringContainsString( 'data-cart="', $html );
		$this->assertStringContainsString( '>Check</button>', $html );
		$this->assertStringNotContainsString( 'class="beltoft-domain-search"', $html );
	}

	public function test_render_without_settings_matches_the_shortcode_inner_markup(): void {
		ob_start();
		$this->element()->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( \BeltoftServices\Domains\Search::inner_markup(), $html );
	}

	public function test_the_form_element_is_a_get_form_with_the_param_and_the_current_term(): void {
		$this->assertCount( 3, Integration::register_elements() );
		require_once BELTOFT_SERVICES_PATH . 'src/Bricks/DomainSearchFormElement.php';
		$_GET['domain'] = 'abc.com';
		$form           = new \BeltoftServices\Bricks\DomainSearchFormElement();
		$form->settings = array( 'buttonText' => 'Find' );
		$form->set_control_groups();
		$form->set_controls();
		$this->assertArrayHasKey( 'inputBorder', $form->controls );
		$this->assertArrayNotHasKey( 'resultButtonBackground', $form->controls );
		$this->assertArrayNotHasKey( 'rowBorder', $form->controls );
		foreach ( $form->controls as $key => $control ) {
			$this->assertArrayHasKey( $control['group'], $form->control_groups, $key );
		}
		ob_start();
		$form->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<form class="beltoft-domain-form" method="get"', $html );
		$this->assertStringContainsString( 'name="domain" value="abc.com"', $html );
		$this->assertStringContainsString( '>Find</button>', $html );
		$this->assertStringNotContainsString( 'beltoft-domain-search"', $html, 'The search script must not bind to this form.' );
	}

	public function test_the_tags_resolve_in_a_domain_row_and_stay_empty_elsewhere(): void {
		\BeltoftServices\Bricks\DomainQuery::register();
		$row = \BeltoftServices\Bricks\DomainQuery::rows( array( array( 'domain' => 'a.com', 'status' => 'free', 'price' => 14, 'note' => '' ) ), 'a.com' )[0];
		$list = apply_filters( 'bricks/dynamic_tags_list', array() );
		$names = array_column( $list, 'name' );
		$this->assertContains( '{bds_domain}', $names );
		$this->assertContains( '{bds_available}', $names );
		$this->assertSame( 'Domain search', end( $list )['group'] );

		$this->assertSame( 'x ', apply_filters( 'bricks/dynamic_data/render_content', 'x {bds_domain}', null, 'text' ), 'Outside a loop row the tag is empty.' );
		\Bricks\Query::$object = $row;
		$this->assertSame( '<b>a.com</b> 1 ' . \BeltoftServices\Usage\Rates::money( 14.0, 0 ) . ' / year', apply_filters( 'bricks/frontend/render_data', '<b>{bds_domain}</b> {bds_available} {bds_price}', null ) );
		$this->assertSame( 'available', apply_filters( 'bricks/dynamic_data/render_tag', 'bds_status', null, 'text' ) );
		$this->assertSame( 'com', apply_filters( 'bricks/dynamic_data/render_tag', '{bds_tld}', null, 'text' ) );
		$this->assertSame( 'other', apply_filters( 'bricks/dynamic_data/render_tag', 'other', null, 'text' ), 'Other tags are not touched.' );
		$this->assertSame( array( 'k' ), apply_filters( 'bricks/dynamic_data/render_tag', array( 'k' ), null, 'text' ) );
		$this->assertSame( '{post_title} a.com', apply_filters( 'bricks/dynamic_data/render_content', '{post_title} {bds_domain}', null, 'text' ), 'Unrelated tags stay.' );
		$link = apply_filters( 'bricks/dynamic_data/render_content', '{bds_add_url}', null, 'link' );
		$this->assertStringContainsString( 'admin-post.php?action=beltoft_domain_add&domain=a.com', $link );
		$this->assertSame( \BeltoftServices\Bricks\DomainQuery::TYPE, apply_filters( 'bricks/query/loop_object_type', null, $row, '' ) );
		$this->assertNotNull( apply_filters( 'bricks/query/loop_object_id', 0, $row, '' ) );
		$this->assertSame( 'p', apply_filters( 'bricks/query/loop_object_type', 'p', array( 'x' ), '' ), 'Other loop objects are not touched.' );
	}
}

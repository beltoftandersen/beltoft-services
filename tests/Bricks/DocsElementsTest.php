<?php
/**
 * The Bricks elements for the mail setup guide and the API reference.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Bricks;

use BeltoftServices\Api\Reference;
use BeltoftServices\Bricks\ApiReferenceElement;
use BeltoftServices\Bricks\Integration;
use BeltoftServices\Mail\MailSetupElement;
use BeltoftServices\Mail\MailSetup;

require_once dirname( __DIR__ ) . '/Mail/MailTestCase.php';

/**
 * Tests. The first test runs before the Bricks stubs exist, so keep it first.
 */
final class DocsElementsTest extends \BeltoftServices\Tests\Mail\MailTestCase {

	public function tear_down(): void {
		wp_dequeue_style( MailSetup::HANDLE );
		wp_deregister_style( MailSetup::HANDLE );
		parent::tear_down();
	}

	/**
	 * Minimal stand-ins for the Bricks classes.
	 */
	private function stub_bricks(): void {
		if ( class_exists( '\Bricks\Element', false ) ) {
			return;
		}

		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
			'namespace Bricks {
				class Element {
					public $settings = array();
					public $controls = array();
					public $control_groups = array();
					public function render_attributes( $key ) { return \'class="brxe-test"\'; }
				}
				class Elements {
					public static $registered = array();
					public static function register_element( $file, $name = "", $class = "" ) { self::$registered[ $name ] = array( $file, $class ); }
				}
			}'
		);
	}

	public function test_nothing_is_registered_without_bricks(): void {
		$this->assertFalse( class_exists( '\Bricks\Elements', false ) );
		$this->assertNotFalse( has_action( 'init', array( Integration::class, 'register_elements' ) ) );

		Integration::register_elements();

		$this->assertFalse( class_exists( 'BeltoftServices\Mail\MailSetupElement', false ), 'The element classes are not loaded without Bricks.' );
	}

	public function test_both_elements_register_when_bricks_exists(): void {
		$this->stub_bricks();
		\Bricks\Elements::$registered = array();

		Integration::register_elements();

		$this->assertSame( array( 'beltoft-mail-setup', 'beltoft-api-reference', 'beltoft-domain-search', 'beltoft-domain-search-form', 'beltoft-domain-transfer' ), array_keys( \Bricks\Elements::$registered ), 'One list, in the order the features and the platform add them.' );
		foreach ( \Bricks\Elements::$registered as $name => $entry ) {
			$this->assertFileExists( $entry[0] );
			require_once $entry[0];
			$this->assertTrue( class_exists( $entry[1] ), $name );
		}
	}

	public function test_the_elements_come_from_one_filter_each_owner_adds_its_own(): void {
		$this->stub_bricks();
		\Bricks\Elements::$registered = array();

		$this->assertSame( 10, has_filter( 'beltoft_services_bricks_elements', array( \BeltoftServices\Mail\BricksElements::class, 'elements' ) ) );
		$this->assertSame( 20, has_filter( 'beltoft_services_bricks_elements', array( Integration::class, 'platform_elements' ) ) );
		$this->assertSame( 30, has_filter( 'beltoft_services_bricks_elements', array( \BeltoftServices\Domains\BricksElements::class, 'elements' ) ) );

		$add = static function ( $elements ) {
			$elements['beltoft-test-element'] = array( 'file' => __FILE__, 'class' => 'Beltoft_Test_Element' );
			return $elements;
		};
		add_filter( 'beltoft_services_bricks_elements', $add, 99 );

		$this->assertSame( 'beltoft-test-element', array_slice( Integration::register_elements(), -1 )[0] );
		$this->assertSame( array( __FILE__, 'Beltoft_Test_Element' ), \Bricks\Elements::$registered['beltoft-test-element'] );

		remove_filter( 'beltoft_services_bricks_elements', $add, 99 );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function controls_of( \Bricks\Element $element ): array {
		$element->set_control_groups();
		$element->set_controls();

		return $element->controls;
	}

	public function test_controls_have_groups_labels_and_square_css_only(): void {
		$this->stub_bricks();
		require_once dirname( __DIR__, 2 ) . '/src/Mail/MailSetupElement.php';
		require_once dirname( __DIR__, 2 ) . '/src/Bricks/ApiReferenceElement.php';

		foreach ( array( new MailSetupElement(), new ApiReferenceElement() ) as $element ) {
			$this->assertSame( 'beltoft services', $element->category );
			$controls = $this->controls_of( $element );
			$this->assertNotEmpty( $controls );

			foreach ( $controls as $key => $control ) {
				$this->assertArrayHasKey( 'type', $control, $key );
				if ( 'info' !== $control['type'] ) {
					$this->assertNotSame( '', (string) ( $control['label'] ?? '' ), $key );
				}
				if ( isset( $control['group'] ) ) {
					$this->assertArrayHasKey( $control['group'], $element->control_groups, $key );
				}
				foreach ( $control['css'] ?? array() as $rule ) {
					$this->assertStringNotContainsString( 'radius', $rule['property'], $key );
					$this->assertStringNotContainsString( ',', $rule['selector'], 'One selector per rule: ' . $key );
				}
			}
		}
	}

	public function test_mail_controls_map_to_the_stylesheet_variables(): void {
		$this->stub_bricks();
		require_once dirname( __DIR__, 2 ) . '/src/Mail/MailSetupElement.php';
		$controls = $this->controls_of( new MailSetupElement() );
		$css      = file_get_contents( dirname( __DIR__, 2 ) . '/assets/mail-setup.css' );

		$properties = array();
		foreach ( $controls as $control ) {
			foreach ( $control['css'] ?? array() as $rule ) {
				if ( 0 === strpos( $rule['property'], '--' ) ) {
					$properties[] = $rule['property'];
				}
			}
		}

		$this->assertContains( '--bsms-th-bg', $properties );
		$this->assertContains( '--bsms-marker', $properties );
		foreach ( array_unique( $properties ) as $property ) {
			$this->assertStringContainsString( 'var(' . $property, $css, $property . ' is read by the stylesheet' );
		}
	}

	public function test_api_controls_map_to_the_stylesheet_variables(): void {
		$this->stub_bricks();
		require_once dirname( __DIR__, 2 ) . '/src/Bricks/ApiReferenceElement.php';
		$controls = $this->controls_of( new ApiReferenceElement() );
		$css      = file_get_contents( dirname( __DIR__, 2 ) . '/assets/api-reference.css' ) . file_get_contents( dirname( __DIR__, 2 ) . '/assets/api-reference-full.css' );

		foreach ( $controls as $control ) {
			foreach ( $control['css'] ?? array() as $rule ) {
				if ( 0 === strpos( $rule['property'], '--' ) ) {
					$this->assertStringContainsString( 'var(' . $rule['property'], $css, $rule['property'] . ' is read by a stylesheet' );
				}
			}
		}
	}

	public function test_the_mail_element_renders_the_shortcode_markup_with_custom_texts(): void {
		$this->stub_bricks();
		require_once dirname( __DIR__, 2 ) . '/src/Mail/MailSetupElement.php';
		$this->settings( array( 'mail_host' => 'mail.example.net', 'mail_dav_url' => 'https://dav.example.net/' ) );

		$shortcode = do_shortcode( '[' . MailSetup::SHORTCODE . ']' );
		$element   = new MailSetupElement();

		ob_start();
		$element->render();
		$plain = (string) ob_get_clean();
		$this->assertSame( '<div class="brxe-test">' . $shortcode . '</div>', $plain, 'No custom text: the same markup as the shortcode.' );

		$element->settings = array(
			'settings_title' => 'Server <b>values</b>',
			'intro'          => 'Own intro',
			'jump_label'     => 'Pick one:',
			'dav_title'      => 'Agenda',
			'faq_title'      => 'FAQ',
			'unknown'        => 'ignored',
		);
		ob_start();
		$element->render();
		$custom = (string) ob_get_clean();

		$this->assertStringContainsString( 'Server &lt;b&gt;values&lt;/b&gt;', $custom, 'Custom texts are escaped.' );
		$this->assertStringContainsString( 'Own intro', $custom );
		$this->assertStringContainsString( 'Pick one:', $custom );
		$this->assertSame( 2, substr_count( $custom, 'Agenda' ) );
		$this->assertSame( 2, substr_count( $custom, '>FAQ<' ) );
		$this->assertStringNotContainsString( 'Settings for every app', $custom );
		$this->assertStringNotContainsString( 'Jump to your app:', $custom );
		$this->assertStringContainsString( 'iPhone and iPad', $custom, 'Everything else stays.' );
	}

	public function test_empty_texts_keep_the_defaults(): void {
		$this->settings( array( 'mail_host' => 'mail.example.net' ) );

		$this->assertSame( MailSetup::html(), MailSetup::html( array( 'settings_title' => '', 'intro' => '  ', 'faq_title' => '' ) ) );
	}

	public function test_the_api_element_renders_the_shortcode_output_in_both_layouts(): void {
		$this->stub_bricks();
		require_once dirname( __DIR__, 2 ) . '/src/Bricks/ApiReferenceElement.php';
		$element = new ApiReferenceElement();

		$this->assertSame( 'full', $element->layout() );

		ob_start();
		$element->render();
		$full = (string) ob_get_clean();
		$this->assertSame( '<div class="brxe-test">' . do_shortcode( '[' . Reference::SHORTCODE . ' layout="full"]' ) . '</div>', $full );
		$this->assertStringContainsString( 'bsdocs', $full );

		$element->settings = array( 'layout' => 'default' );
		ob_start();
		$element->render();
		$default = (string) ob_get_clean();
		$this->assertSame( '<div class="brxe-test">' . do_shortcode( '[' . Reference::SHORTCODE . ']' ) . '</div>', $default );
		$this->assertStringContainsString( 'beltoft-services-apiref', $default );
	}
}

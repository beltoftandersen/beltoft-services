<?php
/**
 * The Bricks Builder side of the plugin: registers the plugin's native elements, only when Bricks is active.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Bricks elements. Without Bricks nothing is loaded and nothing else changes.
 *
 * The elements come from the filter beltoft_services_bricks_elements: element name => array( 'file' => absolute path of
 * the element file, 'class' => the class in it ). The files extend \Bricks\Element, so they are only loaded once Bricks
 * exists. This class adds the API reference element; a feature adds its own (Mail\BricksElements, Domains\BricksElements).
 * Bricks sees them in filter priority order: the mail setup guide (10), the API reference (20), the domain elements (30).
 */
final class Integration {

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_elements' ), 11 );
		add_filter( 'beltoft_services_bricks_elements', array( __CLASS__, 'platform_elements' ), 20 );
	}

	/**
	 * The element of this folder: the API reference.
	 *
	 * @param array<string, array{file: string, class: string}> $elements Elements so far.
	 * @return array<string, array{file: string, class: string}>
	 */
	public static function platform_elements( $elements ): array {
		$elements                          = is_array( $elements ) ? $elements : array();
		$elements['beltoft-api-reference'] = array(
			'file'  => __DIR__ . '/ApiReferenceElement.php',
			'class' => ApiReferenceElement::class,
		);

		return $elements;
	}

	/** Registers each element with Bricks. Returns the element names it registered (none without Bricks). */
	public static function register_elements(): array {
		if ( ! class_exists( '\\Bricks\\Elements' ) || ! class_exists( '\\Bricks\\Element' ) ) {
			return array();
		}

		/**
		 * The Bricks elements of the plugin.
		 *
		 * @param array<string, array{file: string, class: string}> $elements Element name => file and class.
		 */
		$elements = apply_filters( 'beltoft_services_bricks_elements', array() );
		$done     = array();

		foreach ( (array) $elements as $name => $element ) {
			\Bricks\Elements::register_element( $element['file'], $name, $element['class'] );
			$done[] = $name;
		}

		return $done;
	}
}

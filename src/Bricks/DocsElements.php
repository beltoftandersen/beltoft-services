<?php
/**
 * Registers the documentation elements with Bricks, when Bricks is active.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks elements for the mail setup guide and the API reference. The shortcodes stay the source of the markup; the
 * elements only add controls. Nothing loads, and nothing is hooked beyond one init callback, without Bricks.
 */
final class DocsElements {

	/**
	 * Element name => file and class.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const ELEMENTS = array(
		'beltoft-mail-setup'    => array( 'MailSetupElement.php', 'BeltoftServices\\Bricks\\MailSetupElement' ),
		'beltoft-api-reference' => array( 'ApiReferenceElement.php', 'BeltoftServices\\Bricks\\ApiReferenceElement' ),
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add_elements' ), 11 );
	}

	/**
	 * Register the elements, only when the Bricks classes exist.
	 */
	public static function add_elements(): void {
		if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Bricks\Element' ) ) {
			return;
		}

		foreach ( self::ELEMENTS as $name => $element ) {
			\Bricks\Elements::register_element( __DIR__ . '/' . $element[0], $name, $element[1] );
		}
	}
}

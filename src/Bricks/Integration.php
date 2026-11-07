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
 */
final class Integration {

	/** The element files, by element name. They extend \\Bricks\\Element, so they are only loaded once Bricks exists. */
	public const ELEMENTS = array(
		'beltoft-domain-search' => array( 'file' => 'DomainSearchElement.php', 'class' => DomainSearchElement::class ),
		'beltoft-domain-search-form' => array( 'file' => 'DomainSearchFormElement.php', 'class' => DomainSearchFormElement::class ),
		'beltoft-domain-transfer' => array( 'file' => 'DomainTransferElement.php', 'class' => DomainTransferElement::class ),
	);

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_elements' ), 11 );
	}

	/** Registers each element with Bricks. Returns the element names it registered (none without Bricks). */
	public static function register_elements(): array {
		if ( ! class_exists( '\\Bricks\\Elements' ) || ! class_exists( '\\Bricks\\Element' ) ) {
			return array();
		}
		DomainQuery::register();
		$done = array();
		foreach ( self::ELEMENTS as $name => $element ) {
			\Bricks\Elements::register_element( plugin_dir_path( __FILE__ ) . $element['file'], $name, $element['class'] );
			$done[] = $name;
		}
		return $done;
	}
}

<?php
/**
 * Hands the mail setup guide element to Bricks.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the element to the filter beltoft_services_bricks_elements. The element file extends \Bricks\Element, so it is only
 * named here, never loaded: Bricks\Integration loads it when Bricks is active.
 */
final class BricksElements {

	public static function register(): void {
		add_filter( 'beltoft_services_bricks_elements', array( __CLASS__, 'elements' ), 10 );
	}

	/**
	 * @param array<string, array{file: string, class: string}> $elements Elements so far.
	 * @return array<string, array{file: string, class: string}>
	 */
	public static function elements( $elements ): array {
		$elements                       = is_array( $elements ) ? $elements : array();
		$elements['beltoft-mail-setup'] = array(
			'file'  => __DIR__ . '/MailSetupElement.php',
			'class' => MailSetupElement::class,
		);

		return $elements;
	}
}

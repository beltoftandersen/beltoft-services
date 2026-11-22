<?php
/**
 * Hands the domain elements to Bricks and registers the domain query type.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the three domain elements to the filter beltoft_services_bricks_elements (their files extend \Bricks\Element, so they are
 * only named here: Bricks\Integration loads them when Bricks is active) and, when Bricks is active, hooks the domain
 * availability query type and its tags (DomainQuery).
 */
final class BricksElements {

	/** Element name => file and class. */
	private const ELEMENTS = array(
		'beltoft-domain-search'      => array( 'DomainSearchElement.php', DomainSearchElement::class ),
		'beltoft-domain-search-form' => array( 'DomainSearchFormElement.php', DomainSearchFormElement::class ),
		'beltoft-domain-transfer'    => array( 'DomainTransferElement.php', DomainTransferElement::class ),
	);

	public static function register(): void {
		add_filter( 'beltoft_services_bricks_elements', array( __CLASS__, 'elements' ), 30 );
		add_action( 'init', array( __CLASS__, 'register_query' ), 11 );
	}

	/**
	 * @param array<string, array{file: string, class: string}> $elements Elements so far.
	 * @return array<string, array{file: string, class: string}>
	 */
	public static function elements( $elements ): array {
		$elements = is_array( $elements ) ? $elements : array();

		foreach ( self::ELEMENTS as $name => $element ) {
			$elements[ $name ] = array(
				'file'  => __DIR__ . '/' . $element[0],
				'class' => $element[1],
			);
		}

		return $elements;
	}

	/**
	 * The query type, tags and add handler, only with Bricks.
	 */
	public static function register_query(): void {
		if ( ! class_exists( '\\Bricks\\Elements' ) || ! class_exists( '\\Bricks\\Element' ) ) {
			return;
		}

		DomainQuery::register();
	}
}

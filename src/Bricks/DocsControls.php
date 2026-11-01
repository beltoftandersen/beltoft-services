<?php
/**
 * Small builders for Bricks controls shared by the documentation elements.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Plain arrays in the shape Bricks expects; nothing here needs Bricks to be loaded.
 */
final class DocsControls {

	/**
	 * A colour that sets one or more CSS custom properties on the element root.
	 *
	 * @param string       $group Control group.
	 * @param string       $label Label.
	 * @param array<int, string> $vars  Custom properties (with the leading dashes).
	 * @return array<string, mixed>
	 */
	public static function color_var( string $group, string $label, array $vars ): array {
		return array(
			'group' => $group,
			'label' => $label,
			'type'  => 'color',
			'css'   => self::rules( $vars, '' ),
		);
	}

	/**
	 * A size (with units) that sets custom properties on the root.
	 *
	 * @param string             $group Control group.
	 * @param string             $label Label.
	 * @param array<int, string> $vars  Custom properties.
	 * @return array<string, mixed>
	 */
	public static function size_var( string $group, string $label, array $vars ): array {
		return array(
			'group' => $group,
			'label' => $label,
			'type'  => 'number',
			'units' => true,
			'css'   => self::rules( $vars, '' ),
		);
	}

	/**
	 * A typography control on one or more selectors (relative to the element root).
	 *
	 * @param string             $group     Control group.
	 * @param string             $label     Label.
	 * @param array<int, string> $selectors Selectors.
	 * @return array<string, mixed>
	 */
	public static function typography( string $group, string $label, array $selectors ): array {
		return array(
			'group' => $group,
			'label' => $label,
			'type'  => 'typography',
			'css'   => self::rules( array( 'font' ), $selectors ),
		);
	}

	/**
	 * A plain property on selectors, for any control type.
	 *
	 * @param string             $group     Control group.
	 * @param string             $label     Label.
	 * @param string             $type      Bricks control type.
	 * @param string             $property  CSS property.
	 * @param array<int, string> $selectors Selectors.
	 * @return array<string, mixed>
	 */
	public static function property( string $group, string $label, string $type, string $property, array $selectors ): array {
		return array(
			'group' => $group,
			'label' => $label,
			'type'  => $type,
			'css'   => self::rules( array( $property ), $selectors ),
		);
	}

	/**
	 * The css entries of properties x selectors. An empty selector list means the element root.
	 *
	 * @param array<int, string> $properties Properties.
	 * @param array<int, string>|string $selectors Selectors.
	 * @return array<int, array<string, string>>
	 */
	public static function rules( array $properties, $selectors ): array {
		$selectors = is_array( $selectors ) ? $selectors : array( $selectors );
		$selectors = $selectors ? $selectors : array( '' );
		$rules     = array();

		foreach ( $selectors as $selector ) {
			foreach ( $properties as $property ) {
				$rules[] = array(
					'property' => $property,
					'selector' => $selector,
				);
			}
		}

		return $rules;
	}
}

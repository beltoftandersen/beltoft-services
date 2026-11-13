<?php
/**
 * The language of an order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Text written onto an order later (usage lines, the period of a line) is written in the language the customer ordered in: the
 * order meta named by the setting usage_order_language_meta picks an installed WordPress locale.
 */
final class OrderLanguage {

	/**
	 * Runs a callback in the language of an order: the order meta named by the setting usage_order_language_meta ("da", "da_DK")
	 * picks an installed WordPress locale; the site's locale is put back afterwards, also when the callback throws.
	 *
	 * @param \WC_Order $order Order.
	 * @param callable  $fn    What to run.
	 */
	public static function run( \WC_Order $order, callable $fn ): void {
		$key      = trim( (string) Settings::get( 'usage_order_language_meta' ) );
		$locale   = '' !== $key ? self::locale_for( (string) $order->get_meta( $key ) ) : '';
		$switched = '' !== $locale && determine_locale() !== $locale && switch_to_locale( $locale );

		try {
			$fn();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * The installed WordPress locale for a language value: the same code ("da_DK", "da-DK"), else the first installed locale of that
	 * language ("da"), else ''.
	 *
	 * @param string $value Language as the order keeps it.
	 */
	public static function locale_for( string $value ): string {
		$value = strtolower( str_replace( '-', '_', trim( $value ) ) );

		if ( '' === $value ) {
			return '';
		}

		$installed = array_merge( array( 'en_US' ), get_available_languages() );

		foreach ( $installed as $locale ) {
			if ( strtolower( $locale ) === $value ) {
				return $locale;
			}
		}

		foreach ( $installed as $locale ) {
			if ( strtolower( strtok( $locale, '_' ) ) === $value ) {
				return $locale;
			}
		}

		return '';
	}
}

<?php
/**
 * The words a customer types to confirm a step that cannot be undone, in the page language.
 *
 * The routes, the API and the MCP tools keep the English word (RESTORE, DELETE, ...). The account page shows the word of
 * its own language and the script sends the English one when the shown word was typed (beltoftStatus.phrase).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * The shown form of each confirm word.
 */
final class Phrases {

	/**
	 * The word as the page shows it for an English confirm word; an unknown word is returned as it is.
	 *
	 * @param string $english The English word the route knows.
	 */
	public static function shown( string $english ): string {
		switch ( $english ) {
			case 'RESTORE':
				/* translators: The word the customer types to confirm a restore from a backup. It must stay in capitals. */
				return __( 'RESTORE', 'beltoft-services' );
			case 'DELETE':
				/* translators: The word the customer types to confirm a deletion. It must stay in capitals. */
				return __( 'DELETE', 'beltoft-services' );
			case 'REINSTALL':
				/* translators: The word the customer types to confirm a reinstall of a server. It must stay in capitals. */
				return __( 'REINSTALL', 'beltoft-services' );
			case 'ROLLBACK':
				/* translators: The word the customer types to go back to a snapshot. It must stay in capitals. */
				return __( 'ROLLBACK', 'beltoft-services' );
			case 'CANCEL':
				/* translators: The word the customer types to cancel a certificate. It must stay in capitals. */
				return __( 'CANCEL', 'beltoft-services' );
		}

		return $english;
	}

	/**
	 * The attributes that let the script send the English word when the shown one was typed.
	 *
	 * @param string $english The English word the route knows.
	 * @return string Attributes for an input, escaped, with a leading space.
	 */
	public static function attrs( string $english ): string {
		return ' data-phrase-send="' . esc_attr( $english ) . '" data-phrase-shown="' . esc_attr( self::shown( $english ) ) . '"';
	}

	/**
	 * What to send for a typed word: the English word when the shown one was typed, else what was typed.
	 *
	 * @param string $typed   Typed.
	 * @param string $english The English word the route knows.
	 */
	public static function sent( string $typed, string $english ): string {
		return trim( $typed ) === self::shown( $english ) ? $english : $typed;
	}
}

<?php
/**
 * Random passwords the plugin generates for the machines it provisions.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the server and the mailbox passwords, so neither has to reach into the other's feature.
 */
final class Passwords {

	private const LENGTH = 24;

	/**
	 * 62 alphanumerics and 11 symbols: no quote, backslash, backtick or space, so the value
	 * survives a shell, JSON and a URL-encoded form unchanged.
	 */
	private const CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.!@#%^*+=';

	/**
	 * A new random password: 24 characters, each drawn with random_int.
	 */
	public static function generate(): string {
		$max      = strlen( self::CHARSET ) - 1;
		$password = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$password .= self::CHARSET[ random_int( 0, $max ) ];
		}

		return $password;
	}
}

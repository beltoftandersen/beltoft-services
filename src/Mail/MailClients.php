<?php
/**
 * Builds the mailcow client.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a LiveMailcowClient is built. It is built only when the setting says "live" AND the constant
 * BELTOFT_SERVICES_MAIL_LIVE is true in wp-config.php AND the address and key are set. No filter can switch it on.
 */
final class MailClients {

	/**
	 * Whether wp-config.php allows live mailcow calls.
	 */
	public static function live_allowed(): bool {
		return defined( 'BELTOFT_SERVICES_MAIL_LIVE' ) && true === constant( 'BELTOFT_SERVICES_MAIL_LIVE' );
	}

	/**
	 * The client: a FakeMailcowClient (state kept in an option) unless live is chosen and allowed.
	 */
	public static function for(): MailcowClient {
		return self::build( self::live_allowed() );
	}

	/**
	 * for() with the allowance given.
	 *
	 * @param bool $allowed Whether wp-config.php allows live calls.
	 */
	public static function build( bool $allowed ): MailcowClient {
		$client = new FakeMailcowClient( true );
		$live   = false;

		if ( $allowed && 'live' === MailSettings::get( 'mail_client' ) ) {
			$url = MailSettings::api_url();
			$key = MailSettings::api_key();

			if ( '' !== $url && '' !== $key ) {
				try {
					$client = new LiveMailcowClient( $url, $key );
					$live   = true;
				} catch ( \InvalidArgumentException $e ) {
					$client = new FakeMailcowClient( true );
				}
			}
		}

		/**
		 * Lets tests inject a client (a FakeMailcowClient). A LiveMailcowClient it returns is ignored unless the constant
		 * already allows live calls and the settings chose live.
		 *
		 * @param MailcowClient $client The client.
		 */
		$filtered = apply_filters( 'beltoft_services_mail_client', $client );

		if ( $filtered instanceof FakeMailcowClient || ( $live && $filtered instanceof LiveMailcowClient ) ) {
			return $filtered;
		}

		return $client;
	}
}

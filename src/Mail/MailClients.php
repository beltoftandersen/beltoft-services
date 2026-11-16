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
		return \BeltoftServices\Drivers\LiveGate::constant_true( 'BELTOFT_SERVICES_MAIL_LIVE' );
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

			$client = new UnavailableMailcowClient();

			if ( '' !== $url && '' !== $key ) {
				try {
					$client = new LiveMailcowClient( $url, $key );
					$live   = true;
				} catch ( \InvalidArgumentException $e ) {
					$client = new UnavailableMailcowClient();
				}
			}

			if ( ! $live ) {
				// Never the fake here: it would record steps as done that never happened on the real server.
				\BeltoftServices\Alerts::notify(
					'Mail server connection is not usable',
					array( 'Live mail is switched on, but the mail server address or API key is missing, not https or cannot be read. Mail jobs wait and retry until it is fixed in Settings, Services, Mail.' ),
					null,
					'beltoft_services_mail_unavailable_alert'
				);
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

<?php
/**
 * The one place a Robot client is built, and the live gate.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Drivers\LiveGate;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Real orders cost real money. The real Robot host is reached only when the setting `dedicated_client` is `live` AND the
 * constant BELTOFT_SERVICES_DEDICATED_LIVE is true in wp-config.php. The mode `test_server` (staging) uses the same HTTP client
 * against another address and is REFUSED for the real host and any your-server.de host. The mode `fake` is the in-process fake.
 * No filter can switch a live client on; a filter may only inject a fake.
 */
final class DedicatedClients {
	public const CONSTANT  = 'BELTOFT_SERVICES_DEDICATED_LIVE';
	public const REAL_HOST = 'robot-ws.your-server.de';

	/** The address of the real web service. */
	public static function real_url(): string {
		return 'https://' . self::REAL_HOST;
	}

	/** Is the live constant on? */
	public static function live_allowed(): bool {
		return LiveGate::constant_true( self::CONSTANT );
	}

	/** The client for this request. */
	public static function for(): RobotClient {
		return self::build( self::live_allowed() );
	}

	/**
	 * What kind of client the settings give: fake, test_server, live or unavailable.
	 *
	 * @param bool|null $allowed The live constant (null: read it).
	 */
	public static function kind( ?bool $allowed = null ): string {
		$mode = (string) Settings::get( 'dedicated_client' );

		if ( 'live' === $mode ) {
			return ( $allowed ?? self::live_allowed() ) && null !== self::http_client( false ) ? 'live' : 'unavailable';
		}

		if ( 'test_server' === $mode ) {
			return null !== self::http_client( true ) ? 'test_server' : 'unavailable';
		}

		return 'fake';
	}

	/** Does the client spend real money? */
	public static function is_live(): bool {
		return 'live' === self::kind();
	}

	/**
	 * @param bool $allowed The live constant.
	 */
	public static function build( bool $allowed ): RobotClient {
		$kind = self::kind( $allowed );

		if ( 'live' === $kind || 'test_server' === $kind ) {
			return self::http_client( 'test_server' === $kind ) ?? new UnavailableRobotClient();
		}

		if ( 'unavailable' === $kind ) {
			\BeltoftServices\Alerts::notify(
				'Dedicated server ordering is not usable',
				array( 'The client is set to live or test server, but the live constant is off, the address is not allowed or the login is missing. Dedicated servers wait and retry until it is fixed in Settings, Dedicated servers.' ),
				null,
				'beltoft_services_dedicated_unavailable_alert'
			);

			return new UnavailableRobotClient();
		}

		$fake     = new FakeRobotClient();
		$filtered = apply_filters( 'beltoft_services_dedicated_client', $fake );

		return $filtered instanceof FakeRobotClient ? $filtered : $fake;
	}

	/**
	 * The HTTP client, or null when the address or login is not allowed.
	 *
	 * @param bool $test_server True for the test server mode: the real host is refused.
	 */
	private static function http_client( bool $test_server ): ?LiveRobotClient {
		$url  = ManualServerSettings::api_url();
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$real = self::REAL_HOST === $host || '.your-server.de' === substr( $host, -15 );

		if ( $test_server && $real ) {
			return null;
		}

		// The live mode may only talk to the real host: a typo must not send a paid order to some other address.
		if ( ! $test_server && ! $real ) {
			return null;
		}

		try {
			return new LiveRobotClient( $url, ManualServerSettings::secret( 'dedicated_api_user' ), ManualServerSettings::secret( 'dedicated_api_password' ) );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}
}

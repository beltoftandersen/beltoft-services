<?php
/**
 * Builds the storage client.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a LiveStorageClient is built. It is built only when the setting says "live" AND the constant
 * BELTOFT_SERVICES_STORAGE_LIVE is true in wp-config.php AND the address and token are set. No filter can switch it on.
 */
final class StorageClients {

	/**
	 * Whether wp-config.php allows live storage calls.
	 */
	public static function live_allowed(): bool {
		return \BeltoftServices\Drivers\LiveGate::constant_true( 'BELTOFT_SERVICES_STORAGE_LIVE' );
	}

	/**
	 * The client: the fake unless live is chosen and allowed.
	 */
	public static function for(): StorageClient {
		return self::build( self::live_allowed() );
	}

	/**
	 * Is the client in use a live one (a real, paid provider)?
	 */
	public static function is_live(): bool {
		return self::for() instanceof LiveStorageClient;
	}

	/**
	 * for() with the allowance given.
	 *
	 * @param bool $allowed Whether wp-config.php allows live calls.
	 */
	public static function build( bool $allowed ): StorageClient {
		$client = new FakeStorageClient();
		$live   = false;

		if ( $allowed && 'live' === (string) \BeltoftServices\Settings::get( 'storage_client' ) ) {
			$url   = StorageSettings::api_url();
			$token = StorageSettings::api_token();

			$client = new UnavailableStorageClient();

			if ( '' !== $url && '' !== $token ) {
				try {
					$client = new LiveStorageClient( $url, $token );
					$live   = true;
				} catch ( \InvalidArgumentException $e ) {
					$client = new UnavailableStorageClient();
				}
			}

			if ( ! $live ) {
				// Never the fake here: it would record steps as done that never happened at the provider.
				\BeltoftServices\Alerts::notify(
					'Storage provider connection is not usable',
					array( 'Live storage is switched on, but the API address or token is missing, not https or cannot be read. Storage jobs wait and retry until it is fixed in Settings, Services, Backup storage.' ),
					null,
					'beltoft_services_storage_unavailable_alert'
				);
			}
		}

		/**
		 * Lets tests inject a client. Only a FakeStorageClient is taken: a live client is never built by a filter.
		 *
		 * @param StorageClient $client The client.
		 */
		$filtered = apply_filters( 'beltoft_services_storage_client', $client );

		return $filtered instanceof FakeStorageClient && ! $live ? $filtered : $client;
	}
}

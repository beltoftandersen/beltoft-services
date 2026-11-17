<?php
/**
 * Builds the alias DNS writer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a CloudflareAliasDns is built: the setting `storage_alias_dns_client` says live AND the constant
 * BELTOFT_SERVICES_STORAGE_DNS_LIVE is true in wp-config.php AND the token and zone id are set. No filter can switch it on.
 */
final class AliasDnsClients {

	/**
	 * Whether wp-config.php allows live DNS writes for aliases.
	 */
	public static function live_allowed(): bool {
		return \BeltoftServices\Drivers\LiveGate::constant_true( 'BELTOFT_SERVICES_STORAGE_DNS_LIVE' );
	}

	/**
	 * The writer.
	 */
	public static function for(): AliasDns {
		return self::build( self::live_allowed() );
	}

	/**
	 * for() with the allowance given.
	 *
	 * @param bool $allowed Whether wp-config.php allows live writes.
	 */
	public static function build( bool $allowed ): AliasDns {
		$client = new FakeAliasDns();
		$live   = false;

		if ( $allowed && 'live' === (string) \BeltoftServices\Settings::get( 'storage_alias_dns_client' ) ) {
			$client = new UnavailableAliasDns();

			try {
				$client = new CloudflareAliasDns( StorageSettings::cf_zone_id(), StorageSettings::cf_token() );
				$live   = true;
			} catch ( \InvalidArgumentException $e ) {
				$client = new UnavailableAliasDns();
				\BeltoftServices\Alerts::notify(
					'Storage alias DNS connection is not usable',
					array( 'Live DNS writes for storage host names are switched on, but the DNS API token or zone id is missing or not valid. Alias steps wait and retry until it is fixed in Settings, Services, Backup storage.' ),
					null,
					'beltoft_services_storage_dns_unavailable_alert'
				);
			}
		}

		/**
		 * Lets tests inject a writer. Only a FakeAliasDns is taken: a live writer is never built by a filter.
		 *
		 * @param AliasDns $client The writer.
		 */
		$filtered = apply_filters( 'beltoft_services_storage_alias_dns', $client );

		return $filtered instanceof FakeAliasDns && ! $live ? $filtered : $client;
	}
}

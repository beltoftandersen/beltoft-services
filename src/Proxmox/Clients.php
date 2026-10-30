<?php
/**
 * Builds the client for a cluster.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a LiveClient is built. It is built only when the cluster says "live" AND the
 * constant BELTOFT_SERVICES_PROXMOX_LIVE is true in wp-config.php. No filter can switch it on.
 */
final class Clients {

	/**
	 * Whether wp-config.php allows live Proxmox calls.
	 */
	public static function live_allowed(): bool {
		return defined( 'BELTOFT_SERVICES_PROXMOX_LIVE' ) && true === constant( 'BELTOFT_SERVICES_PROXMOX_LIVE' );
	}

	/**
	 * Is this cluster live (and allowed to be) but without an explicit site tag? Its ownership tag
	 * would be a guess, so nothing may be done to it.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 */
	public static function untagged( array $cluster, bool $allowed ): bool {
		return $allowed && 'live' === ( $cluster['client'] ?? 'fake' ) && '' === ProxmoxSettings::explicit_site_tag();
	}

	/**
	 * The gate before a client is built for a cluster: the retry text when the cluster is live
	 * without a site tag (and the admin is alerted, once a day), otherwise null. A caller turns
	 * it into a retry without progress, so the work waits for the tag and nothing is faked.
	 *
	 * @param string               $id      Cluster id.
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 */
	public static function gate( string $id, array $cluster, bool $allowed ): ?string {
		if ( ! self::untagged( $cluster, $allowed ) ) {
			return null;
		}

		\BeltoftServices\Alerts::site_tag( $id );

		/* Admin text, not shown to customers. */
		return sprintf( 'Set the site tag for cluster %s', $id );
	}

	/**
	 * Which client a cluster gets: live only when it says so AND the allowance is true.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 * @return string 'live' or 'fake'.
	 */
	public static function kind( array $cluster, bool $allowed ): string {
		return $allowed && 'live' === ( $cluster['client'] ?? 'fake' ) ? 'live' : 'fake';
	}

	/**
	 * The client for a cluster: a FakeClient unless the cluster's client is live and the
	 * constant is true. Cluster keys used here: client (fake|live), persist (fake only).
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 */
	public static function for( array $cluster ): Client {
		return self::build( $cluster, self::live_allowed() );
	}

	/**
	 * for() with the allowance given. A live cluster without a site tag throws: a caller that
	 * did not pass Clients::gate() gets no client rather than a fake.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param bool                 $allowed Whether wp-config.php allows live calls.
	 *
	 * @throws \RuntimeException When the cluster is live and has no site tag.
	 */
	public static function build( array $cluster, bool $allowed ): Client {
		if ( self::untagged( $cluster, $allowed ) ) {
			throw new \RuntimeException( 'The site tag is not set.' );
		}

		$live = 'live' === self::kind( $cluster, $allowed );

		$client = $live
			? new LiveClient( $cluster, true )
			: new FakeClient( ! empty( $cluster['persist'] ) );

		$public = $cluster;
		unset( $public['token_secret'] );

		/**
		 * Lets tests inject a client (a FakeClient). It does not receive the allowance or the token
		 * secret, and a LiveClient it returns is ignored unless the constant already allows live calls.
		 *
		 * @param Client               $client  The client.
		 * @param array<string, mixed> $cluster Cluster settings without the token secret.
		 */
		$filtered = apply_filters( 'beltoft_services_proxmox_client', $client, $public );

		if ( $filtered instanceof FakeClient || ( $live && $filtered instanceof LiveClient ) ) {
			return $filtered;
		}

		return $client;
	}
}

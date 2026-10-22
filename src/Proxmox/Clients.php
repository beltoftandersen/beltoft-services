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
	 * The client for a cluster: a FakeClient unless the cluster's client is live and the
	 * constant is true. Cluster keys used here: client (fake|live), persist (fake only).
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 */
	public static function for( array $cluster ): Client {
		$live = 'live' === ( $cluster['client'] ?? 'fake' ) && self::live_allowed();

		$client = $live
			? new LiveClient( $cluster, true )
			: new FakeClient( ! empty( $cluster['persist'] ) );

		/**
		 * Lets tests inject a client (a FakeClient). It does not receive the allowance, and a
		 * LiveClient it returns is ignored unless the constant already allows live calls.
		 *
		 * @param Client               $client  The client.
		 * @param array<string, mixed> $cluster Cluster settings.
		 */
		$filtered = apply_filters( 'beltoft_services_proxmox_client', $client, $cluster );

		if ( $filtered instanceof Client && ( $live || ! $filtered instanceof LiveClient ) ) {
			return $filtered;
		}

		return $client;
	}
}

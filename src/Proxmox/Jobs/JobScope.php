<?php
/**
 * Which clusters the Proxmox jobs may look at, and the cluster listing they share.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Jobs;

use BeltoftServices\Proxmox\Client;
use BeltoftServices\Proxmox\Clients;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Which clusters the Proxmox jobs may look at, and the cluster listing they share.
 */
final class JobScope {

	/**
	 * The clusters the jobs may look at, with a client each. Nothing while the kill switch is on.
	 * A fake cluster counts only when it keeps its state (staging): a plain fake has nothing
	 * to read.
	 *
	 * @return array<string, array{cluster: array<string, mixed>, client: Client}>
	 */
	public static function clusters(): array {
		if ( Settings::get( 'pause_all' ) ) {
			return array();
		}

		$out = array();

		foreach ( array_keys( Clusters::all() ) as $id ) {
			$cluster = Clusters::get( (string) $id );

			if ( null === $cluster ) {
				continue;
			}

			if ( 'live' !== $cluster['client'] && ( empty( $cluster['persist'] ) || ! is_array( get_option( FakeClient::OPTION, null ) ) ) ) {
				continue;
			}

			// A live cluster without a site tag is left alone (and the admin told).
			if ( null !== Clients::gate( (string) $id, $cluster, Clients::live_allowed() ) ) {
				continue;
			}

			try {
				$out[ (string) $id ] = array(
					'cluster' => $cluster,
					'client'  => Clients::for( $cluster ),
				);
			} catch ( \Throwable $e ) {
				unset( $e ); // This cluster is skipped; the next run tries again.
			}
		}

		return $out;
	}

	/**
	 * GET /cluster/resources?type=vm, or null when it fails.
	 *
	 * @param Client $client Client.
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function vm_rows( Client $client ): ?array {
		$rows = $client->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) );

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : null;
	}
}

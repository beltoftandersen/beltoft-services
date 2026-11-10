<?php
/**
 * The snapshot data of the customer panel.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Snapshot;

use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * What the account panel shows about the snapshots of a server.
 */
final class PanelData {

	/**
	 * What the account panel shows. No Proxmox call.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null Null when the server gets no snapshots and has none.
	 */
	public static function panel( Resource $r ): ?array {
		$entries = Policy::entries( $r );
		$enabled = Policy::enabled( $r );

		if ( ! $enabled && ! $entries ) {
			return null;
		}

		return array(
			'enabled'  => $enabled,
			'slots'    => Policy::slots( $r ),
			'used'     => count( $entries ),
			'days'     => Policy::days_for( $r ),
			'rolling'  => is_array( $r->get( Snapshots::ROLLBACK ) ),
			'entries'  => array_map(
				static fn( $e ) => array(
					'id'         => (string) $e['id'],
					'label'      => (string) ( $e['label'] ?? '' ),
					'taken_at'   => (int) ( $e['taken_at'] ?? 0 ),
					'expires_at' => (int) ( $e['expires_at'] ?? 0 ),
					'consistent' => ! empty( $e['consistent'] ),
					'state'      => (string) ( $e['state'] ?? 'ok' ),
				),
				$entries
			),
		);
	}
}

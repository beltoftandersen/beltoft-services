<?php
/** Ends the licence of an add-on whose service was closed with its deleted parent. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Addon;

use BeltoftLicenses\Licenses;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Alerts;
use BeltoftServices\Resource;
use BeltoftServices\Usage\CustomerDelete;

defined( 'ABSPATH' ) || exit;

/**
 * An add-on (IPv4 address, extra disk, snapshot pack, app licence, managed) closed by AddonCascade has no service left, so its
 * licence ends the way a customer-deleted service's licence does: revoked without an email, with the same history line
 * (CustomerDelete::end_license()). A pay as you go add-on has no subscription of its own (the usage account is not touched). A
 * flat-priced one with its own subscription has the subscription cancelled once every licence on it has ended; when the subscription
 * also holds a live licence, it is left and the admin is told.
 */
final class AddonEnd {

	/**
	 * Ends the licence of an add-on closed with its parent. Safe to call again.
	 *
	 * @param Resource $addon Add-on resource (closed).
	 * @return bool True when the licence was ended now.
	 */
	public static function end( Resource $addon ): bool {
		$license = $addon->license();

		if ( ! $license || ServiceArea::ended( $license ) ) {
			return false;
		}

		CustomerDelete::end_license( $license );
		self::stop_billing( $addon, $license );

		return true;
	}

	/**
	 * Cancels the add-on's own subscription when nothing else lives on it.
	 *
	 * @param Resource             $addon   Add-on.
	 * @param array<string, mixed> $license Licence row (as it was before it was revoked).
	 */
	private static function stop_billing( Resource $addon, array $license ): void {
		$id = (int) ( $license['subscription_id'] ?? 0 );

		// Pay as you go: the line is on the usage account, which keeps billing the customer's other services.
		if ( $id < 1 || 'usage' === (string) ( $license['source'] ?? '' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $id );

		if ( ! $subscription instanceof \WC_Subscription || in_array( $subscription->get_status(), array( 'cancelled', 'expired', 'trash' ), true ) ) {
			return;
		}

		foreach ( Licenses::where( 'subscription_id', $id ) as $other ) {
			if ( ! ServiceArea::ended( (array) Licenses::find( (int) $other['id'] ) ) ) {
				Alerts::admin( $addon, 'The parent was deleted but subscription #' . $id . ' still holds the live licence #' . (int) $other['id'] . '. The subscription was not cancelled.' );

				return;
			}
		}

		try {
			$subscription->update_status( 'cancelled', __( 'Cancelled: the service it paid for was deleted with its parent.', 'beltoft-services' ) );
		} catch ( \Exception $e ) {
			Alerts::admin( $addon, 'Subscription #' . $id . ' could not be cancelled after the parent was deleted: ' . $e->getMessage() );
		}
	}

	/**
	 * The add-on licences that are still active while their own service is closed and their parent's licence has ended: the
	 * leftovers of a deletion before this class existed.
	 *
	 * @return array<int, array{license_id: int, user_id: int, kind: string, parent_license: int, resource: Resource}>
	 */
	public static function orphans(): array {
		$out = array();

		foreach ( Addon::all() as $a ) {
			foreach ( Resource::find_many( Resource::latest_closed_ids_of_kind( $a->kind ) ) as $resource ) {
				$license = $resource->license();
				$parent  = (int) $resource->get( 'server_license' );

				// A kept app licence has a live service and no parent; a closed one with no parent is not ours to judge.
				if ( $a->driver_id !== (string) $resource->get( 'driver' ) || ! $license || ServiceArea::ended( $license ) || Resource::for_license( (int) $license['id'] ) || $parent < 1 ) {
					continue;
				}

				$parent_license = Licenses::find( $parent );

				if ( ! $parent_license || ! ServiceArea::ended( $parent_license ) ) {
					continue;
				}

				$out[ (int) $license['id'] ] = array(
					'license_id'     => (int) $license['id'],
					'user_id'        => (int) ( $license['user_id'] ?? 0 ),
					'kind'           => $a->kind,
					'parent_license' => $parent,
					'resource'       => $resource,
				);
			}
		}

		ksort( $out );

		return array_values( $out );
	}
}

<?php
/**
 * Pause, resume, resize and delete of a storage box.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The provider has no real suspend: pausing turns every way in off (nothing reaches the box from outside) and keeps the data,
 * so a paused box still costs money. Resizing is upward only and passes the spend guard. Deleting is off by default.
 */
final class BoxLifecycle {

	/**
	 * Pause: access off, the switches it had are kept for resume.
	 *
	 * @param Resource $r Resource.
	 */
	public static function pause( Resource $r ): StepResult {
		$id = Boxes::id( $r );

		if ( '' === $id ) {
			return StepResult::done();
		}

		$client = StorageClients::for();
		$before = (array) $r->get( 'storage_access_before' );

		if ( array() === $before ) {
			$box = $client->get_box( $id );

			if ( is_wp_error( $box ) ) {
				return StepResult::retry( 300, Boxes::why( $box ) );
			}

			$before = (array) $box['access'];
		}

		$set = $client->set_access( $id, Boxes::locked_access() );

		if ( is_wp_error( $set ) ) {
			return StepResult::retry( 300, Boxes::why( $set ) );
		}

		return StepResult::done( array( 'storage_access_before' => $before, 'storage_access' => Boxes::locked_access() + $before, 'storage_locked_at' => time() ), 'Storage access turned off' );
	}

	/**
	 * Resume: the switches it had before the pause, or the defaults.
	 *
	 * @param Resource $r Resource.
	 */
	public static function resume( Resource $r ): StepResult {
		$id = Boxes::id( $r );

		if ( '' === $id ) {
			return Provision::run( $r );
		}

		$access = (array) $r->get( 'storage_access_before' );
		$access = array() === $access ? Boxes::default_access() : $access;
		$set    = StorageClients::for()->set_access( $id, $access );

		if ( is_wp_error( $set ) ) {
			return StepResult::retry( 300, Boxes::why( $set ) );
		}

		return StepResult::done( array( 'storage_access' => $access, 'storage_access_before' => array(), 'storage_locked_at' => 0 ), 'Storage access turned on' );
	}

	/**
	 * A changed plan: a larger type only. A smaller or unknown type leaves the box as it is and tells staff.
	 *
	 * @param Resource $r Resource.
	 */
	public static function update_plan( Resource $r ): StepResult {
		list( $type ) = Provision::plan( $r );
		$id           = Boxes::id( $r );
		$have         = (string) $r->get( 'storage_type' );

		if ( '' === $id || '' === $type || $type === $have ) {
			return StepResult::done();
		}

		$client = StorageClients::for();
		$types  = $client->types();

		if ( is_wp_error( $types ) ) {
			return StepResult::retry( 300, Boxes::why( $types ) );
		}

		if ( ! isset( $types[ $type ] ) || ( isset( $types[ $have ] ) && $types[ $type ]['size_bytes'] < $types[ $have ]['size_bytes'] ) ) {
			Alerts::admin( $r, sprintf( 'A plan change to the box type "%1$s" (now "%2$s") was not applied: storage is upgrade only and the type must be sold.', $type, $have ) );

			return StepResult::done( array(), 'Plan change not applied: upgrade only' );
		}

		$product = (int) ( $r->license()['product_id'] ?? 0 );
		$why     = StorageSpendGuard::price_problem( $product, $type ) ?? StorageSpendGuard::budget_problem( $type, $r );

		if ( null !== $why ) {
			Alerts::admin( $r, $why . ' The storage was not resized.' );

			return StepResult::done( array(), 'Plan change not applied: spend guard' );
		}

		$done = $client->change_type( $id, $type );

		if ( is_wp_error( $done ) ) {
			Alerts::admin( $r, 'Resizing the storage failed: ' . Boxes::why( $done ) );

			return StepResult::retry( 900, Boxes::why( $done ) );
		}

		return StepResult::done( array( 'storage_type' => $type, 'storage_size_bytes' => (int) $types[ $type ]['size_bytes'], 'storage_checked_at' => 0 ), 'Storage resized' );
	}

	/**
	 * Delete: only when the setting allows it. Lifts the delete lock, then deletes.
	 *
	 * @param Resource $r Resource.
	 */
	public static function delete( Resource $r ): StepResult {
		$id = Boxes::id( $r );

		if ( '' === $id ) {
			return StepResult::done( array( 'storage_password' => '' ) );
		}

		if ( ! Settings::get( 'storage_deletes_enabled' ) ) {
			Alerts::notify(
				'Storage box deletion is held',
				array( 'A storage box is due for deletion, but "Allow deleting boxes at the provider" is off in Settings, Backup storage. The data stays and the box still costs money.' ),
				$r,
				'beltoft_services_storage_delete_held_' . $r->id(),
				array( 'ttl' => 30 * DAY_IN_SECONDS )
			);

			return StepResult::wait( DAY_IN_SECONDS, 'Deleting storage boxes is off', time() + 60 * DAY_IN_SECONDS );
		}

		$client = StorageClients::for();
		$lift   = $client->set_protection( $id, false );

		if ( is_wp_error( $lift ) ) {
			return StepResult::retry( 900, Boxes::why( $lift ) );
		}

		$gone = $client->delete_box( $id );

		if ( is_wp_error( $gone ) ) {
			return StepResult::retry( 900, Boxes::why( $gone ) );
		}

		HostAlias::remove( $r );

		return StepResult::done( array( 'storage_password' => '', 'storage_box_id' => '', 'storage_alias' => '' ), 'Storage box deleted' );
	}
}

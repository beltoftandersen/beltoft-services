<?php
/**
 * The breach mark of a license: it outlives the service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Events;

defined( 'ABSPATH' ) || exit;

/**
 * "Suspend for breach" marks the LICENSE, not only its resource: a deleted resource is closed and
 * a renewal or status change would otherwise make a new service for the customer who was
 * suspended. beltoft-licenses has no license meta, and the latest closed resource row is not
 * reliable (several can exist, and an import can replace them), so the mark lives in one option
 * per license (autoload off). It is set by the suspension, cleared by lifting a live hold or by
 * the admin's explicit "Create the service" with the typed confirmation, and refuses every
 * automatic creation of a service (Lifecycle::provide, the reconciler's replacement, the CLI).
 */
final class Breach {

	public const PREFIX  = 'beltoft_services_breach_';
	public const CONFIRM = 'CLEAR BREACH';

	/**
	 * The option of a license.
	 *
	 * @param int $license_id License id.
	 */
	private static function option( int $license_id ): string {
		return self::PREFIX . $license_id;
	}

	/**
	 * Marks a license.
	 *
	 * @param int    $license_id License id.
	 * @param string $reason     Reason (admin only).
	 */
	public static function mark( int $license_id, string $reason ): void {
		update_option(
			self::option( $license_id ),
			array(
				'at'      => time(),
				'reason'  => $reason,
				'alerted' => 0,
			),
			false
		);
	}

	/**
	 * The mark, or null.
	 *
	 * @param int $license_id License id.
	 * @return array{at: int, reason: string, alerted: int}|null
	 */
	public static function get( int $license_id ): ?array {
		$value = get_option( self::option( $license_id ), null );

		return is_array( $value ) && isset( $value['at'] ) ? array(
			'at'      => (int) $value['at'],
			'reason'  => (string) ( $value['reason'] ?? '' ),
			'alerted' => (int) ( $value['alerted'] ?? 0 ),
		) : null;
	}

	/**
	 * Is the license marked?
	 *
	 * @param int $license_id License id.
	 */
	public static function marked( int $license_id ): bool {
		return null !== self::get( $license_id );
	}

	/**
	 * Removes the mark.
	 *
	 * @param int $license_id License id.
	 */
	public static function clear( int $license_id ): void {
		delete_option( self::option( $license_id ) );
	}

	/**
	 * Refuses a creation for a marked license: a history event every time, one admin alert per
	 * mark. Returns true when the license is marked (the caller creates nothing).
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function refuse( array $license ): bool {
		$id   = (int) ( $license['id'] ?? 0 );
		$mark = self::get( $id );

		if ( ! $mark ) {
			return false;
		}

		$text = 'No service was created: the license is marked for a breach of the terms. Use "Create the service" on the license screen and type ' . self::CONFIRM . ' to override.';

		Events::add( $id, 'service', $text );

		if ( ! $mark['alerted'] ) {
			$mark['alerted'] = 1;
			update_option( self::option( $id ), $mark, false );
			Alerts::license( $license, $text );
		}

		return true;
	}
}

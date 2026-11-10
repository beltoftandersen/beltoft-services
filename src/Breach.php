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

	/**
	 * Puts a service on breach hold, under its lock: the license is marked, the service paused
	 * and nothing but lift() resumes it. Returns true, false when a write failed, null when the
	 * lock is busy, or the refusal code ("deleting", "held").
	 *
	 * @param Resource $r      Resource.
	 * @param string   $who    Admin login.
	 * @param string   $reason Why (already cleaned).
	 * @return bool|string|null
	 */
	public static function suspend( Resource $r, string $who, string $reason ) {
		$refused = '';
		$stored  = $r->locked(
			static function () use ( $r, $who, $reason, &$refused ): bool {
				if ( 'deleted' === $r->get( 'desired' ) ) {
					$refused = 'deleting';
					return false;
				}

				if ( $r->on_hold() ) {
					$refused = 'held';
					return false;
				}

				if ( ! $r->set(
					array(
						'breach_at'     => time(),
						'breach_reason' => $reason,
					)
				) ) {
					return false;
				}

				// The license is marked too: the mark outlives the service.
				self::mark( (int) ( $r->license()['id'] ?? 0 ), $reason );

				// The lock is re-entrant within this connection. Stored or queued: the hold is
				// in place either way, so a resume is refused from here on.
				$r->desire( 'paused', sprintf( 'Suspended for breach by %1$s: %2$s', $who, $reason ) );
				return true;
			}
		);

		return '' !== $refused ? $refused : $stored;
	}

	/**
	 * Lifts the breach hold, under the resource lock: ready on an active license, otherwise paused
	 * with the retention counted from now. Returns true, false when a write failed, null when the
	 * lock is busy, or the refusal code ("no_hold").
	 *
	 * @param Resource $r   Resource.
	 * @param string   $who Admin login.
	 * @return bool|string|null
	 */
	public static function lift( Resource $r, string $who ) {
		$license_id = (int) ( $r->license()['id'] ?? 0 );
		$refused    = '';
		$stored     = $r->locked(
			static function () use ( $r, $who, $license_id, &$refused ): bool {
				if ( ! $r->on_hold() ) {
					$refused = 'no_hold';
					return false;
				}

				$active = \BeltoftLicenses\Licenses::STATUS_ACTIVE === \BeltoftLicenses\Licenses::effective_status( $r->license() );
				$meta   = array(
					'breach_at'      => null,
					'breach_reason'  => '',
					'breach_alerted' => null,
				);

				// Still paused: the retention period starts now, never from the pause the hold began.
				if ( ! $active ) {
					$meta['paused_at']       = time();
					$meta['warned_at']       = null;
					$meta['warn_started_at'] = null;
					$meta['warn_alerted']    = null;
					$meta['reported']        = '';
				}

				if ( ! $r->set( $meta ) ) {
					return false;
				}

				self::clear( $license_id );

				$text = sprintf( 'Breach hold lifted by %s', $who );

				if ( $active ) {
					$r->desire( 'ready', $text );
				} else {
					Events::add( $license_id, 'service', $text );
				}

				return true;
			}
		);

		return '' !== $refused ? $refused : $stored;
	}
}

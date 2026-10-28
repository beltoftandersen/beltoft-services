<?php
/**
 * Snapshot expiry: what is due, the clamp when paid slots end, and the customer notices.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Events;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes meta only, never Proxmox: the hourly job (ProxmoxJobs::snapshots) uses it to pick the servers
 * whose snapshots need work and queues the internal actions for them.
 *
 * Meta `snapshot_notice` ({reason: key}) is the dedupe of the customer emails: a reason is sent once for a key
 * (a snapshot id, a volume id, the set of clamped ids), so a retry or a second run never mails twice.
 */
final class SnapshotExpiry {

	public const EMAIL_ID = 'beltoft_services_snapshot_notice';
	public const NOTICE   = 'snapshot_notice';
	public const REASONS  = array( 'disk_removed', 'slots_reduced', 'expired' );

	/**
	 * Days a snapshot that lost its slot or its paid time is still kept at least (D7).
	 */
	public const GRACE_DAYS = 3;

	/**
	 * Does the server have a snapshot to delete now: one past its time, or one half deleted?
	 *
	 * @param Resource $r   Server resource.
	 * @param int      $now Time.
	 */
	public static function due( Resource $r, int $now ): bool {
		foreach ( Snapshots::entries( $r ) as $entry ) {
			if ( self::is_due( $entry, $now ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this entry to be deleted now?
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param int                  $now   Time.
	 */
	public static function is_due( array $entry, int $now ): bool {
		return 'deleting' === ( $entry['state'] ?? '' ) || ( 'ok' === ( $entry['state'] ?? 'ok' ) && (int) ( $entry['expires_at'] ?? 0 ) <= $now );
	}

	/**
	 * The earliest time a snapshot of the server expires, or 0 when it has none.
	 *
	 * @param Resource $r Server resource.
	 */
	public static function next( Resource $r ): int {
		$next = 0;

		foreach ( Snapshots::entries( $r ) as $entry ) {
			$at = (int) ( $entry['expires_at'] ?? 0 );

			if ( $at > 0 && ( 0 === $next || $at < $next ) ) {
				$next = $at;
			}
		}

		return $next;
	}

	/**
	 * D7: when the paid slots are fewer than before, the snapshots over the count, and (with no paid slot at all)
	 * those kept for the paid time, get `expires_at = max( taken_at + free days, now + 3 days )`. Never later than
	 * they were, and nothing is deleted here. The customer is told once per set of clamped snapshots; an empty
	 * round ends the episode, so a later reduction is told again.
	 *
	 * @param Resource $r   Server resource.
	 * @param int|null $now Time.
	 * @return int How many snapshots were shortened.
	 */
	public static function clamp( Resource $r, ?int $now = null ): int {
		$now     = $now ?? time();
		$changed = 0;

		$r->locked(
			static function () use ( $r, $now, &$changed ): bool {
				$entries = Snapshots::entries( $r );
				$paid    = Snapshots::paid_slots( $r );
				$free    = max( 1, (int) Settings::get( 'snapshot_free_days' ) ) * DAY_IN_SECONDS;
				$over    = max( 0, count( $entries ) - Snapshots::slots( $r ) );
				$ids     = array();
				$until   = 0;

				foreach ( $entries as $i => $entry ) {
					if ( 'ok' !== ( $entry['state'] ?? 'ok' ) ) {
						continue;
					}

					$taken     = (int) ( $entry['taken_at'] ?? 0 );
					$expires   = (int) ( $entry['expires_at'] ?? 0 );
					$too_long  = 0 === $paid && $expires > $taken + $free;
					$no_slot   = $i < $over; // The oldest ones lose their slot first.
					$shortened = max( $taken + $free, $now + self::GRACE_DAYS * DAY_IN_SECONDS );

					if ( ( $too_long || $no_slot ) && $shortened < $expires ) {
						$entries[ $i ]['expires_at'] = $shortened;
						$ids[]                       = (string) $entry['id'];
						$until                       = 0 === $until ? $shortened : min( $until, $shortened );
					}
				}

				$changed = count( $ids );

				if ( 0 === $changed ) {
					return null !== $r->get( self::NOTICE ) && self::forget( $r, 'slots_reduced' );
				}

				if ( ! $r->set( array( Snapshots::LIST => $entries ) ) ) {
					$changed = 0;

					return false;
				}

				return self::notify( $r, 'slots_reduced', md5( implode( ',', $ids ) ), array( 'deletes_at' => $until, 'snapshot_count' => $changed ) );
			}
		);

		return $changed;
	}

	/**
	 * Tells the customer of the server once for a reason and a key. The key is stored before the mail goes, so a
	 * mail that fails is not repeated. `expired` is off unless the setting is on.
	 *
	 * @param Resource             $r      Server resource.
	 * @param string               $reason disk_removed, slots_reduced or expired.
	 * @param string               $key    What the notice is about; the same key is told once.
	 * @param array<string, mixed> $data   Values for the email.
	 * @return bool Whether it was sent.
	 */
	public static function notify( Resource $r, string $reason, string $key, array $data = array() ): bool {
		if ( ! in_array( $reason, self::REASONS, true ) || ( 'expired' === $reason && ! Settings::get( 'snapshot_expired_email' ) ) ) {
			return false;
		}

		$told = (array) $r->get( self::NOTICE );

		if ( ( $told[ $reason ] ?? null ) === $key ) {
			return false;
		}

		$told[ $reason ] = $key;

		if ( ! $r->set( array( self::NOTICE => $told ) ) ) {
			return false;
		}

		$sent = Emails::send( self::EMAIL_ID, $r, array( 'snapshot_reason' => $reason ) + $data );

		if ( $sent ) {
			Events::add( (int) ( $r->license()['id'] ?? 0 ), 'service', 'Snapshot email: ' . $reason );
		}

		return $sent;
	}

	/**
	 * Ends an episode: the reason may be told again.
	 *
	 * @param Resource $r      Server resource.
	 * @param string   $reason Reason.
	 */
	private static function forget( Resource $r, string $reason ): bool {
		$told = (array) $r->get( self::NOTICE );

		if ( ! isset( $told[ $reason ] ) ) {
			return false;
		}

		unset( $told[ $reason ] );

		return $r->set( array( self::NOTICE => $told ) );
	}
}

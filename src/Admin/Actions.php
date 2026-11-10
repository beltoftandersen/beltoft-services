<?php
/**
 * The work of the admin actions on a service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Admin;

use BeltoftLicenses\Events;
use BeltoftServices\Breach;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One method per admin action that Panel::act dispatches. Each returns true, or the error code
 * that Panel::errors() has the notice for (busy and error included). The capability, the nonce,
 * the closed check and the queueing of the job stay in the Panel.
 */
final class Actions {

	/**
	 * Turns what a locked write returned into the result of an action: null is a busy lock,
	 * false a failed write, a refusal code is the notice of that refusal.
	 *
	 * @param bool|string|null $stored Result of the locked work: true, false, null or a refusal code.
	 * @return true|string
	 */
	private static function result( $stored ) {
		if ( is_string( $stored ) ) {
			return $stored;
		}

		if ( null === $stored ) {
			return 'busy';
		}

		return $stored ? true : 'error';
	}

	/**
	 * Resets the attempt count so the failed operation runs again.
	 *
	 * @param Resource $r   Resource.
	 * @param string   $who Admin login.
	 * @return true|string
	 */
	public static function retry( Resource $r, string $who ) {
		$license_id = (int) ( $r->license()['id'] ?? 0 );

		return self::result(
			$r->locked(
				static function () use ( $r, $who, $license_id ): bool {
					if ( ! $r->reset_retries() ) {
						return false;
					}

					Events::add( $license_id, 'service', sprintf( 'Retry requested by %s', $who ) );
					return true;
				}
			)
		);
	}

	/**
	 * Pauses or resumes: the desired state.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $who    Admin login.
	 * @param string   $action pause or resume.
	 * @return true|string
	 */
	public static function pause_resume( Resource $r, string $who, string $action ) {
		if ( 'deleted' === $r->get( 'desired' ) ) {
			return 'deleting';
		}

		if ( 'resume' === $action && $r->on_hold() ) {
			return 'hold';
		}

		// Stored now, or queued for the next step when the lock is busy.
		$r->desire( 'pause' === $action ? 'paused' : 'ready', sprintf( 'pause' === $action ? 'Paused by %s' : 'Resumed by %s', $who ) );

		return true;
	}

	/**
	 * The desired state deleted, without a replacement; needs the typed confirmation and an
	 * unprotected resource.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $who     Admin login.
	 * @param string   $confirm Typed confirmation.
	 * @return true|string
	 */
	public static function delete( Resource $r, string $who, string $confirm ) {
		if ( Panel::CONFIRM !== $confirm ) {
			return 'confirm';
		}

		$refused = false;
		$stored  = $r->locked(
			static function () use ( $r, $who, &$refused ): bool {
				if ( $r->get( 'protected' ) ) {
					$refused = true;
					return false;
				}

				// The lock is re-entrant within this connection.
				return $r->desire( 'deleted', sprintf( 'Deletion requested by %s', $who ), false );
			}
		);

		return $refused ? 'protected' : self::result( $stored );
	}

	/**
	 * Protects or unprotects: retention and driver switches never delete a protected service.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $who     Admin login.
	 * @param bool     $protect True to protect.
	 * @return true|string
	 */
	public static function protect( Resource $r, string $who, bool $protect ) {
		$license_id = (int) ( $r->license()['id'] ?? 0 );

		return self::result(
			$r->locked(
				static function () use ( $r, $who, $license_id, $protect ): bool {
					if ( ! $r->set( array( 'protected' => $protect ) ) ) {
						return false;
					}

					Events::add( $license_id, 'service', sprintf( $protect ? 'Protected by %s' : 'Unprotected by %s', $who ) );
					return true;
				}
			)
		);
	}

	/**
	 * The breach hold: needs the typed confirmation and a reason.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $who     Admin login.
	 * @param string   $confirm Typed confirmation.
	 * @param string   $reason  Why.
	 * @return true|string
	 */
	public static function suspend( Resource $r, string $who, string $confirm, string $reason ) {
		if ( Panel::CONFIRM_SUSPEND !== $confirm ) {
			return 'confirm_suspend';
		}

		$reason = trim( wp_strip_all_tags( $reason ) );

		if ( '' === $reason ) {
			return 'reason';
		}

		$reason = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, Panel::REASON_MAX ) : substr( $reason, 0, Panel::REASON_MAX );

		return self::result( Breach::suspend( $r, $who, $reason ) );
	}

	/**
	 * Lifts the breach hold; needs the typed confirmation.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $who     Admin login.
	 * @param string   $confirm Typed confirmation.
	 * @return true|string
	 */
	public static function lift_hold( Resource $r, string $who, string $confirm ) {
		if ( Panel::CONFIRM_LIFT !== $confirm ) {
			return 'confirm_lift';
		}

		return self::result( Breach::lift( $r, $who ) );
	}

	/**
	 * Closes the resource as deleted without calling the service; needs the typed confirmation.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $who     Admin login.
	 * @param string   $confirm Typed confirmation.
	 * @return true|string
	 */
	public static function mark_deleted( Resource $r, string $who, string $confirm ) {
		if ( Panel::CONFIRM_MARK !== $confirm ) {
			return 'confirm_mark';
		}

		$done = Reconciler::mark_deleted( $r, $who );

		return 'ok' === $done ? true : $done;
	}

	/**
	 * Queues the lifting of a network quarantine: the firewall job restores the outgoing policy
	 * and clears the flag when Proxmox shows it.
	 *
	 * @param Resource $r   Resource.
	 * @param string   $who Admin login.
	 * @return true|string
	 */
	public static function quarantine_lift( Resource $r, string $who ) {
		return \BeltoftServices\Proxmox\Network\Quarantine::request_lift( $r, $who ) ? true : 'no_quarantine';
	}
}

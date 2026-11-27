<?php
/**
 * Try again for an email domain whose ownership proof was not found in time.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftLicenses\Events;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The failure "proof not found" stores mail_proof_failed_at (MailDriver::prove). For the days of the setting mail_retry_days
 * the customer may press Try again: the set up runs again from the proof, with a new 14 days for the record. Everything the set
 * up checks is checked again, the name claim included (MailDomain::taken), so a name another service proved in the meantime is
 * refused. Any other failure is a system failure and keeps the support text.
 */
final class MailRetry {

	/**
	 * The moment the time to try again ends, 0 when this failure cannot be retried.
	 *
	 * @param Resource $r Resource.
	 */
	public static function until( Resource $r ): int {
		$at = (int) $r->get( 'mail_proof_failed_at' );

		if ( 'failed' !== (string) $r->get( 'state' ) || 'provision' !== (string) $r->get( 'failed_op' ) || $at <= 0 ) {
			return 0;
		}

		return $at + MailSettings::retry_seconds();
	}

	/**
	 * Can the customer press Try again now?
	 *
	 * @param Resource $r Resource.
	 */
	public static function open( Resource $r ): bool {
		$until = self::until( $r );

		return MailSettings::retry_seconds() > 0 && $until > 0 && time() < $until;
	}

	/**
	 * The state for the page: open, expired (the proof failed and the time is over) or none (another failure).
	 *
	 * @param Resource $r Resource.
	 */
	public static function state( Resource $r ): string {
		if ( self::open( $r ) ) {
			return 'open';
		}

		return (int) $r->get( 'mail_proof_failed_at' ) > 0 && 'failed' === (string) $r->get( 'state' ) ? 'expired' : 'none';
	}

	/**
	 * Starts the set up again: a new 14 days for the proof, the count of failed attempts cleared, the state back to set up.
	 *
	 * @param Resource $r Resource.
	 * @return bool|null True when it was started, false when it cannot be, null when the lock was busy.
	 */
	public static function run( Resource $r ): ?bool {
		$id     = $r->id();
		$stored = $r->locked(
			static function () use ( $id ): bool {
				$fresh = Resource::find( $id );

				if ( ! $fresh || ! self::open( $fresh ) ) {
					return false;
				}

				$meta = array_merge(
					Resource::retry_reset(),
					array(
						'mail_proof_until'     => time() + MailDns::WAIT_DAYS * DAY_IN_SECONDS,
						'mail_proof_failed_at' => 0,
						'failed_op'            => '',
						'last_error'           => '',
						// Back in set up. No listener acts on this state, so nothing is announced.
						'state'                => 'provisioning',
					)
				);

				if ( ! $fresh->set( $meta ) ) {
					return false;
				}

				Events::add( (int) ( $fresh->license()['id'] ?? 0 ), 'service', 'Set up started again by the customer' );

				return true;
			}
		);

		if ( null === $stored || ! is_bool( $stored ) ) {
			return null;
		}

		if ( true === $stored ) {
			Jobs::enqueue( $id );
		}

		return $stored;
	}
}

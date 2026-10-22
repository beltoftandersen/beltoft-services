<?php
/**
 * Generated VM passwords and their one-time reveal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The password a VM is given is generated here, stored encrypted in the resource meta
 * BEFORE it is sent anywhere (so a crash or retry can never lose it), shown to the customer
 * once, and wiped. It is never put in an email, an event, a log or an order.
 */
final class Access {

	/**
	 * Resource meta keys (not reserved: see Reconciler::RESERVED).
	 */
	public const META_REVEAL = 'reveal';
	public const META_UNTIL  = 'reveal_until';

	/**
	 * Set while a reset password is stored but not yet applied to the VM: it cannot be revealed then.
	 */
	public const META_RESET = 'password_reset';

	/**
	 * Days the customer has to read the password.
	 */
	public const REVEAL_DAYS = 7;

	private const LENGTH = 24;

	/**
	 * 62 alphanumerics and 11 symbols: no quote, backslash, backtick or space, so the value
	 * survives a shell, JSON and a URL-encoded form unchanged.
	 */
	private const CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.!@#%^*+=';

	/**
	 * A new random password: 24 characters, each drawn with random_int.
	 */
	public static function generate_password(): string {
		$max      = strlen( self::CHARSET ) - 1;
		$password = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$password .= self::CHARSET[ random_int( 0, $max ) ];
		}

		return $password;
	}

	/**
	 * The password this resource's VM gets: the one already stored (a retry reuses it), or a
	 * new one, stored encrypted before the caller sends it to Proxmox. Call it from a driver
	 * step, which runs under the resource lock.
	 *
	 * @param Resource $r Resource.
	 * @return string The plain password, for the one request that sends it.
	 * @throws \RuntimeException When BELTOFT_SERVICES_KEY is missing (nothing is stored then).
	 */
	public static function pending_password( Resource $r ): string {
		$stored = $r->get( self::META_REVEAL );

		if ( is_string( $stored ) && '' !== $stored ) {
			$plain = Crypto::decrypt( $stored );

			if ( null !== $plain && '' !== $plain ) {
				return $plain;
			}
		}

		$password  = self::generate_password();
		$encrypted = Crypto::encrypt( $password );

		if ( ! $r->set(
			array(
				self::META_REVEAL => $encrypted,
				self::META_UNTIL  => time() + self::REVEAL_DAYS * DAY_IN_SECONDS,
			)
		) ) {
			throw new \RuntimeException( 'The password could not be stored.' );
		}

		return $password;
	}

	/**
	 * The password of a customer's "reset password": a new one, stored encrypted (and marked as
	 * not yet applied) BEFORE it is sent anywhere. A retry of the same reset reuses the stored
	 * one; a reset that was already finished always gets a new one. Call it from a driver step,
	 * which runs under the resource lock. The plain password is returned for the requests that
	 * send it, never stored, logged or put in an event.
	 *
	 * @param Resource $r Resource.
	 * @return string The plain password.
	 * @throws \RuntimeException When it cannot be stored (nothing is sent then).
	 */
	public static function reset_password( Resource $r ): string {
		if ( $r->get( self::META_RESET ) ) {
			$stored = $r->get( self::META_REVEAL );
			$plain  = is_string( $stored ) && '' !== $stored ? Crypto::decrypt( $stored ) : null;

			if ( null !== $plain && '' !== $plain ) {
				return $plain;
			}
		}

		$password  = self::generate_password();
		$encrypted = Crypto::encrypt( $password );

		if ( ! $r->set(
			array(
				self::META_REVEAL => $encrypted,
				self::META_UNTIL  => time() + self::REVEAL_DAYS * DAY_IN_SECONDS,
				self::META_RESET  => true,
			)
		) ) {
			throw new \RuntimeException( 'The password could not be stored.' );
		}

		return $password;
	}

	/**
	 * The reset password is on the VM: from now on the customer can reveal it.
	 *
	 * @param Resource $r Resource.
	 * @return bool Whether it was stored.
	 */
	public static function finish_reset( Resource $r ): bool {
		return $r->set( array( self::META_RESET => null ) );
	}

	/**
	 * Is a password stored that the customer may still reveal?
	 *
	 * @param Resource $r Resource.
	 */
	public static function revealable( Resource $r ): bool {
		$stored = $r->get( self::META_REVEAL );

		return is_string( $stored ) && '' !== $stored && (int) $r->get( self::META_UNTIL ) >= time() && ! $r->get( self::META_RESET );
	}

	/**
	 * Takes the password for its owner and wipes it, inside the resource lock so two requests
	 * at once cannot both get it. It is wiped before it is returned: if the wipe cannot be
	 * stored, nothing is revealed.
	 *
	 * @param Resource $r       Resource.
	 * @param int      $user_id The requesting user; must own the license.
	 * @return string|null The password, or null: not the owner, none stored, expired (and then
	 *                     wiped), not decryptable, or the lock stayed busy.
	 */
	public static function take_reveal( Resource $r, int $user_id ): ?string {
		$taken = null;

		$r->locked(
			static function () use ( $r, $user_id, &$taken ): bool {
				$license = $r->license();

				if ( $user_id <= 0 || empty( $license['user_id'] ) || (int) $license['user_id'] !== $user_id ) {
					return false;
				}

				$stored = $r->get( self::META_REVEAL );

				// A reset password that is not on the VM yet would only lock the customer out.
				if ( ! is_string( $stored ) || '' === $stored || $r->get( self::META_RESET ) ) {
					return false;
				}

				$wipe = array(
					self::META_REVEAL => '',
					self::META_UNTIL  => 0,
				);

				if ( (int) $r->get( self::META_UNTIL ) < time() ) {
					return $r->set( $wipe );
				}

				$plain = Crypto::decrypt( $stored );

				if ( null === $plain || '' === $plain || ! $r->set( $wipe ) ) {
					return false;
				}

				$taken = $plain;

				return true;
			}
		);

		return $taken;
	}

	/**
	 * Wipes the stored password, read or not (a deleted or closed VM has nothing left to reveal).
	 *
	 * @param Resource $r Resource.
	 * @return bool Whether nothing is stored afterwards.
	 */
	public static function purge( Resource $r ): bool {
		$stored = $r->get( self::META_REVEAL );

		if ( ( ! is_string( $stored ) || '' === $stored ) && 0 === (int) $r->get( self::META_UNTIL ) ) {
			return true;
		}

		return $r->set(
			array(
				self::META_REVEAL => '',
				self::META_UNTIL  => 0,
				self::META_RESET  => null,
			)
		);
	}

	/**
	 * Listener of beltoft_services_state_changed: a deleted VM service keeps no password. This
	 * also covers "Mark deleted", which closes the resource without calling the driver.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'deleted' === $new && $r instanceof Resource && SshKeyField::DRIVER === $r->get( 'driver' ) ) {
			self::purge( $r );
		}
	}
}

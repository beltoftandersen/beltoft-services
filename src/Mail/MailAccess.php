<?php
/**
 * Mailbox passwords and their one-time reveal.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Crypto;
use BeltoftServices\Passwords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A mailbox password is generated here (with the generator the server passwords use), stored encrypted in the domain
 * resource's meta BEFORE it is sent to the mail server (a crash or retry never loses it), shown to the customer once
 * and wiped. It is never put in an email, an event, a log or an order. The meta mail_reveal maps a mailbox address to
 * [ pw (encrypted), until (time), pending (bool) ]; pending is true while the password is stored but not yet set on
 * the mail server, and a pending password cannot be revealed (it would only lock the customer out).
 */
final class MailAccess {

	public const META = 'mail_reveal';

	/** Days the customer has to read a password. */
	public const REVEAL_DAYS = 7;

	/**
	 * A new password, stored encrypted and pending. Call it inside Resource::locked().
	 *
	 * @param Resource $r     Resource.
	 * @param string   $email Mailbox address.
	 * @return string The plain password, for the one request that sets it.
	 * @throws \RuntimeException When it cannot be stored (nothing is sent then).
	 */
	public static function begin( Resource $r, string $email ): string {
		$password = Passwords::generate();
		$all      = self::all( $r );

		$all[ strtolower( $email ) ] = array(
			'pw'      => Crypto::encrypt( $password ),
			'until'   => time() + self::REVEAL_DAYS * DAY_IN_SECONDS,
			'pending' => true,
		);

		if ( ! $r->set( array( self::META => $all ) ) ) {
			throw new \RuntimeException( 'The password could not be stored.' );
		}

		return $password;
	}

	/**
	 * The password is set on the mail server: from now on it can be revealed.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $email Mailbox address.
	 */
	public static function finish( Resource $r, string $email ): bool {
		$email = strtolower( $email );
		$ok    = false;

		// Read and written inside the resource lock: the whole map is rewritten, so a stale copy would bring back a password that was just revealed.
		$r->locked(
			static function () use ( $r, $email, &$ok ): bool {
				$all = self::all( $r );

				if ( ! isset( $all[ $email ] ) ) {
					$ok = true;

					return true;
				}

				$all[ $email ]['pending'] = false;
				$ok                       = $r->set( array( self::META => $all ) );

				return $ok;
			}
		);

		return $ok;
	}

	/**
	 * The password could not be set: forgets it.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $email Mailbox address.
	 */
	public static function abort( Resource $r, string $email ): bool {
		return self::purge( $r, $email );
	}

	/**
	 * Is a password stored that the customer may still reveal?
	 *
	 * @param Resource $r     Resource.
	 * @param string   $email Mailbox address.
	 */
	public static function revealable( Resource $r, string $email ): bool {
		$row = self::all( $r )[ strtolower( $email ) ] ?? null;

		return is_array( $row ) && '' !== (string) ( $row['pw'] ?? '' ) && (int) ( $row['until'] ?? 0 ) >= time() && empty( $row['pending'] );
	}

	/**
	 * Takes the password for its owner and wipes it, inside the resource lock so two requests at once cannot both get
	 * it. It is wiped before it is returned: if the wipe cannot be stored, nothing is revealed.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $email   Mailbox address.
	 * @param int      $user_id The requesting user; must own the license.
	 * @return string|null The password, or null: not the owner, none stored, pending, expired (and then wiped), not
	 *                     readable, or the lock stayed busy.
	 */
	public static function take( Resource $r, string $email, int $user_id ): ?string {
		$taken = null;
		$email = strtolower( $email );

		$r->locked(
			static function () use ( $r, $email, $user_id, &$taken ): bool {
				$license = $r->license();

				if ( $user_id <= 0 || empty( $license['user_id'] ) || (int) $license['user_id'] !== $user_id ) {
					return false;
				}

				$all = self::all( $r );
				$row = $all[ $email ] ?? null;

				if ( ! is_array( $row ) || '' === (string) ( $row['pw'] ?? '' ) || ! empty( $row['pending'] ) ) {
					return false;
				}

				$plain = (int) ( $row['until'] ?? 0 ) >= time() ? Crypto::decrypt( (string) $row['pw'] ) : null;

				unset( $all[ $email ] );

				if ( ! $r->set( array( self::META => $all ) ) || null === $plain || '' === $plain ) {
					return false;
				}

				$taken = $plain;

				return true;
			}
		);

		return $taken;
	}

	/**
	 * Wipes one stored password (a deleted mailbox has nothing left to reveal).
	 *
	 * @param Resource $r     Resource.
	 * @param string   $email Mailbox address.
	 */
	public static function purge( Resource $r, string $email ): bool {
		$email = strtolower( $email );
		$ok    = false;

		// Inside the resource lock, like finish().
		$r->locked(
			static function () use ( $r, $email, &$ok ): bool {
				$all = self::all( $r );

				if ( ! isset( $all[ $email ] ) ) {
					$ok = true;

					return true;
				}

				unset( $all[ $email ] );
				$ok = $r->set( array( self::META => $all ) );

				return $ok;
			}
		);

		return $ok;
	}

	/**
	 * Wipes every stored password of the domain.
	 *
	 * @param Resource $r Resource.
	 */
	public static function purge_all( Resource $r ): bool {
		return array() === self::all( $r ) || $r->set( array( self::META => array() ) );
	}

	/**
	 * Wipes the passwords whose time to read them has passed (nobody keeps an encrypted password forever).
	 *
	 * @param Resource $r Resource.
	 * @return int How many were wiped.
	 */
	public static function purge_expired( Resource $r ): int {
		$count = 0;

		$r->locked(
			static function () use ( $r, &$count ): bool {
				$all = self::all( $r );

				foreach ( $all as $email => $row ) {
					if ( ! is_array( $row ) || (int) ( $row['until'] ?? 0 ) < time() ) {
						unset( $all[ $email ] );
						++$count;
					}
				}

				if ( 0 === $count ) {
					return true;
				}

				if ( ! $r->set( array( self::META => $all ) ) ) {
					$count = 0;

					return false;
				}

				return true;
			}
		);

		return $count;
	}

	/**
	 * Listens to state changes: a deleted mail service keeps no password.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
	}

	/**
	 * Listener of beltoft_services_state_changed: a deleted mail service keeps no password.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'deleted' === $new && $r instanceof Resource && MailDriver::ID === $r->get( 'driver' ) ) {
			self::purge_all( $r );
		}
	}

	/**
	 * The stored entries.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, array<string, mixed>>
	 */
	private static function all( Resource $r ): array {
		$all = $r->get( self::META );

		return is_array( $all ) ? $all : array();
	}
}

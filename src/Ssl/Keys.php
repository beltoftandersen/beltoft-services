<?php
/** The private key of a certificate we made for the customer: encrypted, shown once, wiped. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Crypto;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The key is stored with Crypto before the request is made from it, so a crash cannot lose it. The customer can take it once,
 * for REVEAL_DAYS after the certificate is issued; it is wiped when it is taken, when the days pass, and when the service is
 * deleted. It is in no email, history line, log, order meta, REST reply other than the one reveal, or MCP tool. A customer who
 * misses it reissues the certificate for free with a new key.
 */
final class Keys {
	public const META_KEY   = 'ssl_key_enc';
	public const META_UNTIL = 'ssl_key_until';

	/** The key of a reissue the registrar has not accepted or delivered yet: kept apart so the stored key is never overwritten. */
	public const META_KEY_NEXT   = 'ssl_key_enc_next';
	public const META_UNTIL_NEXT = 'ssl_key_until_next';

	/** Days the customer has to take the key, counted from the issue. */
	public const REVEAL_DAYS = 7;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'on_state_changed' ), 10, 3 );
	}

	/**
	 * The key already stored for this resource (a retry reuses it), or null.
	 *
	 * @param Resource $r Resource.
	 */
	public static function existing( Resource $r ): ?string {
		$stored = $r->get( self::META_KEY );

		if ( is_string( $stored ) && '' !== $stored ) {
			$plain = Crypto::decrypt( $stored );

			if ( null !== $plain && '' !== $plain ) {
				return $plain;
			}
		}

		return null;
	}

	/**
	 * The meta that stores a new private key encrypted, to be saved in the same call as the request made from it. Null when the
	 * key cannot be encrypted (no BELTOFT_SERVICES_KEY), in which case nothing may be made from it.
	 *
	 * @param string $pem    Private key.
	 * @param int    $window Seconds the key is kept at least.
	 * @return array<string, mixed>|null
	 */
	public static function meta( string $pem, int $window ): ?array {
		try {
			$encrypted = Crypto::encrypt( $pem );
		} catch ( \RuntimeException $e ) {
			return null;
		}

		return array( self::META_KEY => $encrypted, self::META_UNTIL => time() + $window );
	}

	/**
	 * The certificate is issued: the customer's days start now.
	 *
	 * @param Resource $r Resource.
	 */
	public static function start_window( Resource $r ): bool {
		$stored = $r->get( self::META_KEY );

		return ! is_string( $stored ) || '' === $stored || $r->set( array( self::META_UNTIL => time() + self::REVEAL_DAYS * DAY_IN_SECONDS ) );
	}

	/**
	 * Is a key stored that the customer may still take?
	 *
	 * @param Resource $r Resource.
	 */
	public static function revealable( Resource $r ): bool {
		$stored = $r->get( self::META_KEY );

		return is_string( $stored ) && '' !== $stored && (int) $r->get( 'ssl_issued_at' ) > 0 && (int) $r->get( self::META_UNTIL ) >= time() && ! self::reissuing( $r );
	}

	/**
	 * Is a reissue under way (asked for and not delivered, or a request kept for a retry)? While it is, the stored key is not
	 * shown: it is about to be replaced.
	 *
	 * @param Resource $r Resource.
	 */
	public static function reissuing( Resource $r ): bool {
		return (bool) $r->get( 'ssl_reissue_pending' ) || '' !== (string) $r->get( 'ssl_csr_next' );
	}

	/**
	 * Forgets a reissue that was not accepted or never came: its key, request and mode.
	 *
	 * @param Resource $r Resource.
	 */
	public static function discard_pending( Resource $r ): bool {
		return $r->set( array( self::META_KEY_NEXT => '', self::META_UNTIL_NEXT => 0, 'ssl_csr_next' => null, 'ssl_key_mode_next' => null, 'ssl_reissue_pending' => false, 'ssl_reissue_at' => null ) );
	}

	/**
	 * Takes the key for its owner and wipes it, inside the resource lock so two requests cannot both get it. It is wiped before
	 * it is returned: if the wipe cannot be stored, nothing is revealed.
	 *
	 * @param Resource $r       Resource.
	 * @param int      $user_id The requesting user; must own the license.
	 * @return string|null The key, or null.
	 */
	public static function take( Resource $r, int $user_id ): ?string {
		$taken = null;

		$r->locked(
			static function () use ( $r, $user_id, &$taken ): bool {
				$license = $r->license();

				if ( $user_id <= 0 || empty( $license['user_id'] ) || (int) $license['user_id'] !== $user_id ) {
					return false;
				}

				$stored = $r->get( self::META_KEY );

				// Not before the certificate exists: a key shown early would only be lost.
				if ( ! is_string( $stored ) || '' === $stored || ! (int) $r->get( 'ssl_issued_at' ) || self::reissuing( $r ) ) {
					return false;
				}

				$wipe = array( self::META_KEY => '', self::META_UNTIL => 0 );

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
	 * Wipes the stored key, taken or not.
	 *
	 * @param Resource $r Resource.
	 * @return bool Whether nothing is stored afterwards.
	 */
	public static function purge( Resource $r ): bool {
		$stored = $r->get( self::META_KEY );

		if ( ( ! is_string( $stored ) || '' === $stored ) && 0 === (int) $r->get( self::META_UNTIL ) && '' === (string) $r->get( self::META_KEY_NEXT ) ) {
			return true;
		}

		return $r->set( array( self::META_KEY => '', self::META_UNTIL => 0, self::META_KEY_NEXT => '', self::META_UNTIL_NEXT => 0 ) );
	}

	/**
	 * Listener of beltoft_services_state_changed: a deleted certificate service keeps no key.
	 *
	 * @param mixed  $r   Resource.
	 * @param string $old Old state.
	 * @param string $new New state.
	 */
	public static function on_state_changed( $r, $old, $new ): void {
		unset( $old );

		if ( 'deleted' === $new && $r instanceof Resource && SslDriver::ID === $r->get( 'driver' ) ) {
			self::purge( $r );
		}
	}

	/**
	 * Wipes the keys whose window has passed (the daily job).
	 */
	public static function sweep(): int {
		$wiped = 0;

		foreach ( Resource::live_ids( SslDriver::KIND ) as $id ) {
			$r = Resource::find( $id );

			if ( $r && SslDriver::ID === $r->get( 'driver' ) && '' !== (string) $r->get( self::META_KEY ) && (int) $r->get( self::META_UNTIL ) < time() && self::purge( $r ) ) {
				++$wiped;
			}
		}

		return $wiped;
	}
}

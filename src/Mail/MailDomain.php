<?php
/**
 * Which domains may get email, and how a customer proves a domain is theirs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\Ownership;
use BeltoftServices\Resource;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Domains\HostName;

defined( 'ABSPATH' ) || exit;

/**
 * A mail resource is one email domain (meta mail_domain). Three questions are answered here, all before anything is
 * created on the mail server: is the name usable, is it free (not reserved, not another service's), and does the
 * customer hold it. A domain whose zone our own name servers serve for the customer is theirs by that fact. Any
 * other domain needs a TXT record at _beltoft-verify.<domain> that holds a token only this service knows, read from
 * the domain's own delegated name servers (ProofResolver), the same proof DNS hosting for a domain registered
 * elsewhere uses.
 */
final class MailDomain {

	public const META_DOMAIN = 'mail_domain';
	public const META_TOKEN  = 'mail_verify_token';

	/** Resource meta: how the proof step passed: the TXT record, a zone of ours, or a proof made through another service. */
	public const META_VIA   = 'mail_proof_via';
	public const VIA_RECORD = 'record';
	public const VIA_ZONE   = 'zone';
	public const VIA_OTHER  = 'other';

	/**
	 * The name as it would be typed into DNS, or null when it is not a usable domain.
	 *
	 * @param mixed $raw Typed value.
	 */
	public static function normalise( $raw ): ?string {
		return HostName::normalise( $raw );
	}

	/**
	 * Is the name, or a name above it, in the reserved list?
	 *
	 * @param string $name Normalised domain.
	 */
	public static function reserved( string $name ): bool {
		foreach ( MailSettings::reserved() as $reserved ) {
			if ( '' !== $reserved && ( $name === $reserved || str_ends_with( $name, '.' . $reserved ) ) ) {
				return true;
			}
		}

		// The mail host's own name is never a customer's mail domain.
		$host = MailSettings::host();

		return '' !== $host && $name === $host;
	}

	/**
	 * Another live mail service that holds the name. Only a service that passed the ownership proof holds it
	 * (the proof step is recorded in `steps`; the domain is added on the mail server after it). An order that
	 * is still waiting for its proof, or that failed, holds nothing, so nobody can block a name by ordering it.
	 *
	 * @param string $name      Normalised domain.
	 * @param int    $resource  The resource asking, left out of the search.
	 */
	public static function taken( string $name, int $resource = 0 ): bool {
		foreach ( Resource::live_ids( MailDriver::KIND ) as $id ) {
			if ( $id === $resource ) {
				continue;
			}

			$other = Resource::find( $id );

			if ( ! $other || in_array( $other->get( 'state' ), array( 'deleted', 'failed' ), true ) || $name !== (string) $other->get( self::META_DOMAIN ) ) {
				continue;
			}

			if ( in_array( 'proof', (array) $other->get( 'steps' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The reason a name cannot be used, in words for the customer, or null.
	 *
	 * @param mixed $raw      Typed value.
	 * @param int   $resource The resource asking.
	 */
	public static function refusal( $raw, int $resource = 0 ): ?string {
		$name = self::normalise( $raw );

		if ( null === $name ) {
			return __( 'That is not a domain name we can use. Enter a name such as example.com, without https:// or a path.', 'beltoft-services' );
		}

		if ( self::reserved( $name ) ) {
			return __( 'That domain cannot be used for email here.', 'beltoft-services' );
		}

		if ( self::taken( $name, $resource ) ) {
			return __( 'That domain already has email here.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * Does our own DNS serve this name for the customer, so they can be given records in it?
	 *
	 * @param string $name    Normalised domain.
	 * @param int    $user_id Customer.
	 */
	public static function on_our_servers( string $name, int $user_id ): bool {
		return in_array( $name, ZoneAccess::zones( $user_id ), true );
	}

	/**
	 * The token this service showed before the central one existed (stored once per resource). Still accepted for this
	 * service; none is made any more.
	 *
	 * @param Resource $r Resource.
	 * @return string '' when the service never had one.
	 */
	public static function legacy_token( Resource $r ): string {
		return (string) $r->get( self::META_TOKEN );
	}

	/**
	 * The record the customer adds to prove the domain, as a DNS table row: the one record of the customer and domain
	 * that every service uses (Domains\Ownership).
	 *
	 * @param string $name    Domain.
	 * @param int    $user_id Customer.
	 * @return array{name:string,type:string,value:string}
	 */
	public static function proof_record( string $name, int $user_id ): array {
		return Ownership::record( $user_id, $name );
	}

	/**
	 * Answers the ownership filter (Domains\Ownership::FILTER): a mail service of this customer for this name or a name
	 * above it, not ended, whose proof step passed with the TXT record itself. A service that took its proof from a zone or
	 * from another service does not answer, and a service in the same registrable domain only answers for the names it
	 * covers (Ownership::covers).
	 *
	 * @param mixed $proven  Answer so far.
	 * @param mixed $name    The name.
	 * @param mixed $user_id The customer.
	 * @return mixed
	 */
	public static function answer( $proven, $name, $user_id ) {
		if ( true === $proven || ! is_string( $name ) || (int) $user_id <= 0 ) {
			return $proven;
		}

		foreach ( Ownership::chain( $name ) as $candidate ) {
			foreach ( Resource::find_by_meta( MailDriver::KIND, self::META_DOMAIN, $candidate ) as $id ) {
				$r = Resource::find( (int) $id );

				if ( $r
					&& $r->is_live()
					&& ! in_array( $r->get( 'state' ), array( 'deleted', 'failed' ), true )
					&& (int) ( $r->license()['user_id'] ?? 0 ) === (int) $user_id
					&& in_array( 'proof', (array) $r->get( 'steps' ), true )
					&& self::VIA_RECORD === $r->get( self::META_VIA )
					&& Ownership::covers( $candidate, $name )
				) {
					return true;
				}
			}
		}

		return $proven;
	}
}

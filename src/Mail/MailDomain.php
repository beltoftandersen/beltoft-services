<?php
/**
 * Which domains may get email, and how a customer proves a domain is theirs.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Domains\ProofResolver;
use BeltoftServices\Proxmox\AppDomains;
use BeltoftServices\Proxmox\AppOwnDomain;
use BeltoftServices\Resource;

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

	/**
	 * The name as it would be typed into DNS, or null when it is not a usable domain.
	 *
	 * @param mixed $raw Typed value.
	 */
	public static function normalise( $raw ): ?string {
		return AppOwnDomain::normalise( $raw );
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
	 * Another live mail service that already has the name.
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

			if ( $other && 'deleted' !== $other->get( 'state' ) && $name === (string) $other->get( self::META_DOMAIN ) ) {
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
		return in_array( $name, AppDomains::zones( $user_id ), true );
	}

	/**
	 * The token the customer publishes: stored once per resource.
	 *
	 * @param Resource $r Resource.
	 * @return string '' when it could not be stored.
	 */
	public static function token( Resource $r ): string {
		$token = (string) $r->get( self::META_TOKEN );

		if ( '' !== $token ) {
			return $token;
		}

		$token = 'beltoft-' . strtolower( wp_generate_password( 32, false ) );

		return $r->set( array( self::META_TOKEN => $token ) ) ? $token : '';
	}

	/**
	 * The record the customer adds to prove the domain, as a DNS table row.
	 *
	 * @param string $name  Domain.
	 * @param string $token Token.
	 * @return array{name:string,type:string,value:string}
	 */
	public static function proof_record( string $name, string $token ): array {
		return array(
			'name'  => ProofResolver::TXT_LABEL . '.' . $name,
			'type'  => 'TXT',
			'value' => $token,
		);
	}

	/**
	 * Reads the proof from the domain's delegated name servers.
	 *
	 * @param string $name  Domain.
	 * @param string $token Token.
	 * @return array{status:string,reason:string} found, missing, unknown (try again) or support (cannot be proven).
	 */
	public static function check_proof( string $name, string $token ): array {
		$delegation = self::proof( 'delegation', array( $name ) );

		if ( 'ok' !== ( $delegation['status'] ?? '' ) ) {
			return array(
				'status' => (string) ( $delegation['status'] ?? 'unknown' ),
				'reason' => (string) ( $delegation['reason'] ?? '' ),
			);
		}

		$txt = self::proof( 'check_txt', array( $name, $token, $delegation ) );

		return array(
			'status' => (string) ( $txt['status'] ?? 'unknown' ),
			'reason' => (string) ( $txt['reason'] ?? '' ),
		);
	}

	/**
	 * One ProofResolver call. The test-only filter is the one the DNS claim jobs use.
	 *
	 * @param string            $call delegation or check_txt.
	 * @param array<int, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private static function proof( string $call, array $args ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- the same test-only filter as DnsClaimJobs.
		$result = apply_filters( 'beltoft_services_dns_proof_result', null, $call, $args );

		if ( is_array( $result ) ) {
			return $result;
		}

		return 'check_txt' === $call
			? ProofResolver::check_txt( (string) $args[0], (string) $args[1], (array) $args[2] )
			: ProofResolver::delegation( (string) $args[0] );
	}
}

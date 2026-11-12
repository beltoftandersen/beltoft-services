<?php
/**
 * DNS for the names of hosting sites.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\Ownership;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Domains\ZoneWriter;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 8.1 to 8.3. Three kinds of name, each with its own rule:
 * - preview: `<slug>.<preview zone>`, a zone this site runs. A and AAAA go to the edge address of the node.
 * - ours: a name inside a zone the customer holds with our name servers (Domains\ZoneAccess). The A and AAAA sets
 *   at that name are replaced (replace mode); a CNAME at the name, a paused zone or a zone that is not the
 *   customer's refuses the write, and no other record of the zone is read back or rewritten.
 * - external: nothing is written. The customer points the name at the edge address; the name waits until a public
 *   lookup shows it.
 *
 * Writes go through the DNS client of the Domains code (a FakeDns unless that layer is live), inside the zone lock.
 * Methods return true, a plain-words reason when it can never work (string), or a WP_Error when a later try may
 * succeed.
 */
final class SiteDns {

	/**
	 * TTL of the records written, in seconds (one hour: the address of a website rarely changes, and resolvers keep it).
	 */
	public const TTL = 3600;

	/**
	 * The edge addresses of a node: A, and AAAA when the node has one.
	 *
	 * @param array<string, mixed> $node Node settings.
	 * @return array{A: string, AAAA: string} Empty strings when not set.
	 */
	public static function edge( array $node ): array {
		return array(
			'A'    => (string) ( $node['edge_ip'] ?? '' ),
			'AAAA' => (string) ( $node['edge_ipv6'] ?? '' ),
		);
	}

	/**
	 * What an external domain must point at, as the account shows it.
	 *
	 * @param array<string, mixed> $node Node settings.
	 * @return array<int, array{type: string, value: string}>
	 */
	public static function instructions( array $node, string $host = '', int $resource_id = 0 ): array {
		$out = array();

		foreach ( self::edge( $node ) as $type => $value ) {
			if ( '' !== $value ) {
				$out[] = array(
					'type'  => $type,
					'value' => $value,
				);
			}
		}

		// The proof that the customer controls the name: the one TXT record of the customer and domain (Domains\Ownership),
		// shown only while the name is not proven already.
		if ( '' !== $host && $resource_id > 0 && ! self::leans( $resource_id, $host ) ) {
			$rec   = Ownership::record( self::owner( $resource_id ), $host );
			$out[] = array(
				'type'  => $rec['type'],
				'name'  => $rec['name'],
				'value' => $rec['value'],
			);
		}

		return $out;
	}

	/** Label of the ownership proof record, in front of the host name. */
	public const PROOF_LABEL = '_beltoft-verify';

	/** Resource meta: the names whose proof was the TXT record itself, host => time. Only these answer for other features. */
	public const META_PROOFS = 'host_proofs';

	/** The name being checked now: its own stored state does not answer for itself. */
	private static string $excluding = '';

	/**
	 * The customer of a hosting account.
	 *
	 * @param int $resource_id Hosting resource id.
	 */
	public static function owner( int $resource_id ): int {
		$r = Resource::find( $resource_id );

		return $r ? (int) ( $r->license()['user_id'] ?? 0 ) : 0;
	}

	/**
	 * The older ownership token of a name for an account (not stored: made again from the site's secret keys). Still
	 * accepted next to the central value (Domains\Ownership), so a record added before 0.15.0 keeps working.
	 *
	 * @param int    $resource_id Hosting resource id.
	 * @param string $host        Host name.
	 */
	public static function proof_token( int $resource_id, string $host ): string {
		return substr( hash_hmac( 'sha256', 'hosting-domain|' . $resource_id . '|' . strtolower( $host ), wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Is the name one the operator keeps for itself (setting "domains customers may not add", one per line; a name
	 * under a listed domain counts)?
	 *
	 * @param string $host Host name.
	 */
	public static function denied( string $host ): bool {
		$list = preg_split( '/[\s,]+/', strtolower( (string) Settings::get( 'hosting_denied_domains' ) ), -1, PREG_SPLIT_NO_EMPTY );

		/**
		 * Domains customers may not point at their hosting.
		 *
		 * @param string[] $list Lower case domains.
		 */
		$list = (array) apply_filters( 'beltoft_services_hosting_denied_domains', is_array( $list ) ? $list : array() );

		foreach ( $list as $domain ) {
			$domain = rtrim( strtolower( (string) $domain ), '.' );

			if ( '' !== $domain && ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The zone of a name among the zones the customer holds with our name servers: the longest match.
	 *
	 * @param int    $user_id Customer.
	 * @param string $host    Host name.
	 * @return string|null Zone name, or null when the name is in none.
	 */
	public static function zone_for( int $user_id, string $host ): ?string {
		return ZoneAccess::zone_of( $user_id, $host );
	}

	/**
	 * Points the preview name of a site at the edge.
	 *
	 * @param string               $slug Site slug.
	 * @param array<string, mixed> $node Node settings.
	 * @return true|string|\WP_Error
	 */
	public static function publish_preview( string $slug, array $node ) {
		$zone = Config::preview_zone();
		$host = EdgeMap::preview_host( $slug );

		if ( '' === $zone || '' === $host ) {
			return __( 'The hosting preview zone is not set.', 'beltoft-services' );
		}

		return self::publish( $zone, $host, $node, false );
	}

	/**
	 * Points a name inside one of the customer's zones at the edge.
	 *
	 * @param int                  $user_id Customer.
	 * @param string               $host    Host name.
	 * @param array<string, mixed> $node    Node settings.
	 * @return true|string|\WP_Error
	 */
	public static function publish_ours( int $user_id, string $host, array $node ) {
		$zone = self::zone_for( $user_id, $host );

		if ( null === $zone ) {
			return __( 'The name is not in one of your domains on our name servers.', 'beltoft-services' );
		}

		return self::publish( $zone, $host, $node, true );
	}

	/**
	 * Replaces the A (and AAAA) set at a name.
	 *
	 * @param string               $zone     Zone.
	 * @param string               $host     Host name.
	 * @param array<string, mixed> $node     Node settings.
	 * @param bool                 $customer A customer's zone: a paused zone refuses.
	 * @return true|string|\WP_Error
	 */
	private static function publish( string $zone, string $host, array $node, bool $customer ) {
		$edge = self::edge( $node );

		if ( '' === $edge['A'] ) {
			return __( 'The node has no edge address.', 'beltoft-services' );
		}

		$rows = array();

		foreach ( $edge as $type => $value ) {
			if ( '' !== $value ) {
				$rows[] = array(
					'name'  => $host,
					'type'  => $type,
					'ttl'   => self::TTL,
					'value' => $value,
				);
			}
		}

		$policy = static function ( array $data, array $sets ) use ( $host, $customer ) {
			foreach ( (array) ( $data['rrsets'] ?? array() ) as $set ) {
				if ( is_array( $set ) && strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) === $host && 'CNAME' === strtoupper( (string) ( $set['type'] ?? '' ) ) ) {
					return __( 'The name is an alias (CNAME). Remove it first.', 'beltoft-services' );
				}

				// A preview name is ours to make and nobody else's to lose: any other record at the name (a mail host, a name server, a
				// TXT) is not ours, and an address that is not one of our edge's is not ours either. Not overwritten.
				if ( ! $customer && is_array( $set ) && strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) === $host ) {
					$type   = strtoupper( (string) ( $set['type'] ?? '' ) );
					$values = array_map( static fn( $r ) => (string) ( is_array( $r ) ? ( $r['content'] ?? '' ) : '' ), (array) ( $set['records'] ?? array() ) );
					$ours   = array();

					foreach ( $sets as $mine ) {
						if ( strtoupper( (string) ( $mine['type'] ?? '' ) ) === $type ) {
							foreach ( (array) ( $mine['records'] ?? array() ) as $m ) {
								$ours[] = (string) ( is_array( $m ) ? ( $m['content'] ?? '' ) : '' );
							}
						}
					}

					if ( ! in_array( $type, array( 'A', 'AAAA' ), true ) || array() !== array_diff( $values, $ours ) ) {
						return __( 'That temporary address is already in use.', 'beltoft-services' );
					}
				}
			}

			return $sets;
		};

		$result = ZoneWriter::write( null, $zone, $rows, $policy, $customer );

		if ( is_wp_error( $result ) ) {
			return $result; // A transient failure or the zone lock being busy, as it is.
		}

		if ( 'refused' === $result['status'] ) {
			switch ( $result['reason'] ) {
				case 'invalid':
					return __( 'The name is not valid for a DNS record.', 'beltoft-services' );
				case 'no_zone':
					return __( 'The DNS zone does not exist.', 'beltoft-services' );
				case 'paused':
					return __( 'DNS hosting is paused for this domain.', 'beltoft-services' );
				default:
					return $result['reason'];
			}
		}

		return true;
	}

	/**
	 * Removes the A and AAAA sets at a name, but only when they hold nothing except our edge addresses: a record
	 * the customer changed since is left alone.
	 *
	 * @param string               $zone Zone.
	 * @param string               $host Host name.
	 * @param array<string, mixed> $node Node settings.
	 * @return true|\WP_Error
	 */
	public static function unpublish( string $zone, string $host, array $node ) {
		$ours = array_filter( self::edge( $node ) );

		return ZoneWriter::remove(
			null,
			$zone,
			static function ( array $data ) use ( $host, $ours ): array {
				$delete = array();

				foreach ( (array) ( $data['rrsets'] ?? array() ) as $set ) {
					$type = strtoupper( (string) ( $set['type'] ?? '' ) );

					if ( ! is_array( $set ) || strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) !== $host || ! in_array( $type, array( 'A', 'AAAA' ), true ) ) {
						continue;
					}

					$values = array_map( static fn( $r ) => (string) ( $r['content'] ?? '' ), (array) ( $set['records'] ?? array() ) );

					if ( array() !== $values && array() === array_diff( $values, $ours ) ) {
						$delete[] = array(
							'name'       => (string) $set['name'],
							'type'       => $type,
							'changetype' => 'DELETE',
						);
					}
				}

				return $delete;
			}
		); // A WP_Error is a transient failure or the zone lock being busy.
	}

	/**
	 * Adds a domain to a site: ours (written now) or external (waiting for the customer). The name is recorded
	 * first, so a retry finds it.
	 *
	 * @param Resource             $r    Resource.
	 * @param string               $slug Site slug.
	 * @param string               $host Host name.
	 * @param array<string, mixed> $node Node settings.
	 * @return array{kind: string, state: string}|string|\WP_Error The result, a refusal in plain words, or a transient error.
	 */
	public static function add_domain( Resource $r, string $slug, string $host, array $node ) {
		$host = strtolower( rtrim( trim( $host ), '.' ) );
		$site = Sites::find( $r->id(), $slug );

		if ( null === $site ) {
			return __( 'The website does not exist.', 'beltoft-services' );
		}

		if ( ! SiteDomains::valid_host( $host ) ) {
			return __( 'The domain name is not valid.', 'beltoft-services' );
		}

		$zone = Config::preview_zone();

		if ( '' !== $zone && ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) ) {
			return __( 'That name belongs to the preview zone.', 'beltoft-services' );
		}

		if ( self::denied( $host ) ) {
			return __( 'That domain cannot be used for hosting.', 'beltoft-services' );
		}

		$existing = SiteDomains::find( $host );

		if ( null !== $existing && $existing['resource_id'] !== $r->id() ) {
			return __( 'The domain is already used by another account.', 'beltoft-services' );
		}

		$user = (int) ( $r->license()['user_id'] ?? 0 );
		$ours = null !== self::zone_for( $user, $host );
		$kind = $ours ? 'ours' : 'external';

		if ( ! SiteDomains::add( $r->id(), (int) $site['id'], $host, $kind ) ) {
			return __( 'The domain could not be recorded.', 'beltoft-services' );
		}

		if ( $ours ) {
			$done = self::publish_ours( $user, $host, $node );

			if ( true !== $done ) {
				return $done;
			}

			SiteDomains::set_state( $host, 'active' );

			return array(
				'kind'  => 'ours',
				'state' => 'active',
			);
		}

		return array(
			'kind'  => 'external',
			'state' => (string) SiteDomains::find( $host )['state'],
		);
	}

	/**
	 * Looks at an external name: it is active once the customer has proved the name is theirs (see proof()) AND a public
	 * lookup shows our edge address, and goes back to waiting if either stops showing. A lookup that fails changes
	 * nothing. Pointing at us alone proves nothing: anybody can point a name they do not own.
	 *
	 * @param string               $host Host name.
	 * @param array<string, mixed> $node Node settings.
	 * @return string|null The state now, or null when it could not be told.
	 */
	public static function check_external( string $host, array $node ): ?string {
		$row = SiteDomains::find( $host );

		if ( null === $row || 'external' !== $row['kind'] ) {
			return null;
		}

		$edge  = self::edge( $node );
		$found = DnsQuery::addresses( $host, 'A' );

		if ( is_wp_error( $found ) ) {
			return null;
		}

		$points = '' !== $edge['A'] && in_array( $edge['A'], $found, true );
		$proved = false;

		// The proof is looked for only while the name points at us: a name that does not is waiting whatever it proves.
		if ( $points ) {
			$proved = self::proof( (int) $row['resource_id'], $host );

			if ( null === $proved ) {
				return null;
			}
		}

		$state = $proved && $points ? 'active' : 'waiting';

		SiteDomains::set_state( $host, $state );

		return $state;
	}

	/**
	 * Does the name have the customer's proof now? Order: a name below an active name of this account; another stored
	 * proof of the same customer (a zone of ours, a claim, another service: Ownership::source, never this name's own earlier
	 * state); then the record itself (Ownership::check, the system resolver as the fallback). The name is marked as proven
	 * by the record only in the last case, so it answers for other services only while the record is there.
	 *
	 * @param int    $resource_id Hosting resource id.
	 * @param string $host        Host name.
	 * @return bool|null True, false, or null when the lookup could not tell.
	 */
	private static function proof( int $resource_id, string $host ): ?bool {
		$user = self::owner( $resource_id );

		if ( self::leans( $resource_id, $host ) ) {
			self::mark( $resource_id, $host, false );

			return true;
		}

		$check = Ownership::check( $user, $host, array( self::proof_token( $resource_id, $host ) ), true );

		if ( 'unknown' === $check['status'] ) {
			return null;
		}

		self::mark( $resource_id, $host, 'found' === $check['status'] );

		return 'found' === $check['status'];
	}

	/**
	 * Is the name proven without looking at DNS: below an active name of the account, or proven by something else
	 * (a zone of ours, a claim, another service of the same customer)? The name's own stored state never counts.
	 *
	 * @param int    $resource_id Hosting resource id.
	 * @param string $host        Host name.
	 */
	public static function leans( int $resource_id, string $host ): bool {
		if ( self::under_verified_domain( $resource_id, $host ) ) {
			return true;
		}

		$before          = self::$excluding;
		self::$excluding = $host;

		try {
			return '' !== Ownership::source( self::owner( $resource_id ), $host );
		} finally {
			self::$excluding = $before;
		}
	}

	/**
	 * Notes whether the proof of a name is the record itself.
	 *
	 * @param int    $resource_id Hosting resource id.
	 * @param string $host        Host name.
	 * @param bool   $by_record   True when the record was found.
	 */
	private static function mark( int $resource_id, string $host, bool $by_record ): void {
		$r = Resource::find( $resource_id );

		if ( ! $r ) {
			return;
		}

		$marks = (array) $r->get( self::META_PROOFS );

		if ( $by_record === isset( $marks[ $host ] ) ) {
			return;
		}

		if ( $by_record ) {
			$marks[ $host ] = time();
		} else {
			unset( $marks[ $host ] );
		}

		$r->set( array( self::META_PROOFS => $marks ) );
	}

	/**
	 * Answers the ownership filter (Domains\Ownership::FILTER): the customer has an active outside name of one of their
	 * hosting accounts, at or above this name, whose proof was the TXT record. A name active only because another service or
	 * the zone proved it does not answer (a proof is never passed on in a circle), and the name being checked does not answer
	 * for itself.
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
			$row = $candidate === self::$excluding ? null : SiteDomains::find( $candidate );

			if ( null === $row || 'external' !== $row['kind'] || 'active' !== $row['state'] ) {
				continue;
			}

			$r = Resource::find( (int) $row['resource_id'] );

			if ( $r && HostingDriver::ID === $r->get( 'driver' ) && $r->is_live() && (int) ( $r->license()['user_id'] ?? 0 ) === (int) $user_id && isset( ( (array) $r->get( self::META_PROOFS ) )[ $candidate ] ) ) {
				return true;
			}
		}

		return $proven;
	}

	/**
	 * Is the name a subdomain of a domain this account already has active (a domain in the customer's zone, or an
	 * outside domain that was proved)? Subdomains of such a domain need no proof of their own and are not counted or
	 * charged: only the address has to point at us.
	 *
	 * @param int    $resource_id Hosting resource id.
	 * @param string $host        Host name.
	 */
	public static function under_verified_domain( int $resource_id, string $host ): bool {
		foreach ( SiteDomains::for_resource( $resource_id ) as $row ) {
			if ( 'preview' !== $row['kind'] && 'active' === $row['state'] && $row['host'] !== $host && str_ends_with( $host, '.' . $row['host'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes a name: the record we wrote (preview and ours) and the row. An external name only loses its row.
	 *
	 * @param Resource             $r    Resource.
	 * @param string               $host Host name.
	 * @param array<string, mixed> $node Node settings.
	 * @return true|\WP_Error
	 */
	public static function remove_domain( Resource $r, string $host, array $node ) {
		$row = SiteDomains::find( $host );

		if ( null === $row || $row['resource_id'] !== $r->id() ) {
			return true;
		}

		if ( 'preview' === $row['kind'] ) {
			$zone = Config::preview_zone();
		} elseif ( 'ours' === $row['kind'] ) {
			$zone = self::zone_for( (int) ( $r->license()['user_id'] ?? 0 ), $host );
		} else {
			$zone = null;
		}

		if ( null !== $zone && '' !== $zone ) {
			$done = self::unpublish( $zone, $host, $node );

			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		SiteDomains::remove( $host );

		return true;
	}
}

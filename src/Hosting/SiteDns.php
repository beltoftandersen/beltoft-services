<?php
/**
 * DNS for the names of hosting sites.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\ZoneAccess;
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
	 * TTL of the records written, in seconds.
	 */
	public const TTL = 300;

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

		// The proof that the customer controls the name: a TXT record with a token only this account and name can show.
		if ( '' !== $host && $resource_id > 0 ) {
			$out[] = array(
				'type'  => 'TXT',
				'name'  => self::PROOF_LABEL . '.' . $host,
				'value' => self::proof_token( $resource_id, $host ),
			);
		}

		return $out;
	}

	/** Label of the ownership proof record, in front of the host name. */
	public const PROOF_LABEL = '_beltoft-verify';

	/**
	 * The ownership token of a name for an account (not stored: made again from the site's secret keys).
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
		$best = null;

		foreach ( ZoneAccess::zones( $user_id ) as $zone ) {
			if ( ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) && ( null === $best || strlen( $zone ) > strlen( $best ) ) ) {
				$best = $zone;
			}
		}

		return $best;
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
			return 'The hosting preview zone is not set.';
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
			return 'The name is not in one of your domains on our name servers.';
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
			return 'The node has no edge address.';
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

		$sets = Records::validate( $zone, $rows );

		if ( is_wp_error( $sets ) ) {
			return 'The name is not valid for a DNS record.';
		}

		$result = Records::locked(
			$zone,
			static function () use ( $zone, $host, $sets, $customer ) {
				$data = DnsClients::dns()->zone( $zone );

				if ( is_wp_error( $data ) ) {
					return new \WP_Error( 'transient', 'The DNS zone could not be read.' );
				}

				if ( ! is_array( $data ) ) {
					return 'The DNS zone does not exist.';
				}

				if ( $customer && Records::paused( $zone, $data ) ) {
					return 'DNS hosting is paused for this domain.';
				}

				foreach ( (array) ( $data['rrsets'] ?? array() ) as $set ) {
					if ( is_array( $set ) && strtolower( rtrim( (string) ( $set['name'] ?? '' ), '.' ) ) === $host && 'CNAME' === strtoupper( (string) ( $set['type'] ?? '' ) ) ) {
						return 'The name is an alias (CNAME). Remove it first.';
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
							return 'That temporary address is already in use.';
						}
					}
				}

				$done = DnsClients::dns()->replace_rrsets( $zone, $sets );

				return is_wp_error( $done ) ? new \WP_Error( 'transient', 'The DNS record could not be saved.' ) : true;
			}
		);

		return $result; // A WP_Error is a transient failure or the zone lock being busy.
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

		$result = Records::locked(
			$zone,
			static function () use ( $zone, $host, $ours ) {
				$data = DnsClients::dns()->zone( $zone );

				if ( is_wp_error( $data ) ) {
					return new \WP_Error( 'transient', 'The DNS zone could not be read.' );
				}

				if ( ! is_array( $data ) ) {
					return true; // No zone, no record.
				}

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

				if ( array() === $delete ) {
					return true;
				}

				$done = DnsClients::dns()->replace_rrsets( $zone, $delete );

				return is_wp_error( $done ) ? new \WP_Error( 'transient', 'The DNS record could not be removed.' ) : true;
			}
		);

		return $result; // A WP_Error is a transient failure or the zone lock being busy.
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
			return 'The website does not exist.';
		}

		if ( ! SiteDomains::valid_host( $host ) ) {
			return 'The domain name is not valid.';
		}

		$zone = Config::preview_zone();

		if ( '' !== $zone && ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) ) {
			return 'That name belongs to the preview zone.';
		}

		if ( self::denied( $host ) ) {
			return 'That domain cannot be used for hosting.';
		}

		$existing = SiteDomains::find( $host );

		if ( null !== $existing && $existing['resource_id'] !== $r->id() ) {
			return 'The domain is already used by another account.';
		}

		$user = (int) ( $r->license()['user_id'] ?? 0 );
		$ours = null !== self::zone_for( $user, $host );
		$kind = $ours ? 'ours' : 'external';

		if ( ! SiteDomains::add( $r->id(), (int) $site['id'], $host, $kind ) ) {
			return 'The domain could not be recorded.';
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
	 * Looks at an external name: it is active once the customer has proved the name is theirs (the TXT record
	 * `_beltoft-verify.<name>` with the account's token) AND a public lookup shows our edge address, and goes back to
	 * waiting if either stops showing. A lookup that fails changes nothing. Pointing at us alone proves nothing: anybody
	 * can point a name they do not own.
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
		$texts = DnsQuery::texts( self::PROOF_LABEL . '.' . $host );

		if ( is_wp_error( $found ) || is_wp_error( $texts ) ) {
			return null;
		}

		$proved = in_array( self::proof_token( (int) $row['resource_id'], $host ), array_map( static fn( $t ) => trim( (string) $t, " \t\"" ), $texts ), true );
		$state  = $proved && '' !== $edge['A'] && in_array( $edge['A'], $found, true ) ? 'active' : 'waiting';

		SiteDomains::set_state( $host, $state );

		return $state;
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

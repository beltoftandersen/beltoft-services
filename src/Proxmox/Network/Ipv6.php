<?php
/**
 * The IPv6 address of a server, derived from its VMID.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Proxmox\Pve\Clusters;

defined( 'ABSPATH' ) || exit;

/**
 * Each cluster that has IPv6 on owns one /64 (cluster field `ipv6_prefix`). Every VMID owns a reserved /80 of it,
 * `<prefix>:<vmid in hex>::/80`, and a server gets one address in that block, `<prefix>:<vmid in hex>::1`, on-link in
 * the /64 of the bridge. Nothing is allocated or released: the VMID is the allocation (unique per cluster, kept for the
 * life of the server). Routing the whole /80 to a server later needs no renumbering.
 *
 * A VMID is reused after a server is deleted (VmRecords::allocate takes the lowest free id), so a new server can get a
 * deleted server's address. Who held an address at a given time is therefore answered by the log (IpHistory, kind
 * `public6`), never by this derivation alone. Pure functions: no database access except vmid_for(), which reads the
 * clusters.
 */
final class Ipv6 {

	/** The log kind (IpHistory) of an IPv6 holding. */
	public const KIND = 'public6';

	/**
	 * The /64 of a cluster as 8 packed bytes, or null when the cluster has IPv6 off or the text is not a /64.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 */
	public static function prefix_bytes( array $cluster ): ?string {
		return self::parse_prefix( (string) ( $cluster['ipv6_prefix'] ?? '' ) );
	}

	/**
	 * The 8 bytes of a `<address>/64` text, or null.
	 *
	 * @param string $text For example 2a01:4f8:262:430c::/64.
	 */
	public static function parse_prefix( string $text ): ?string {
		$text = trim( $text );

		if ( 1 !== preg_match( '#^([0-9A-Fa-f:]+)/64$#D', $text, $m ) ) {
			return null;
		}

		$packed = self::pack( $m[1] );

		// Host bits must be zero: a prefix with an interface part is a typing mistake, not a network.
		if ( null === $packed || "\0\0\0\0\0\0\0\0" !== substr( $packed, 8 ) ) {
			return null;
		}

		return substr( $packed, 0, 8 );
	}

	/**
	 * The address of a server, or null when IPv6 is off for the cluster or the VMID does not fit in 16 bits.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param int                  $vmid    VMID.
	 */
	public static function address( array $cluster, int $vmid ): ?string {
		$prefix = self::prefix_bytes( $cluster );

		if ( null === $prefix || $vmid < 1 || $vmid > 65535 ) {
			return null;
		}

		return self::text( $prefix . pack( 'n', $vmid ) . "\0\0\0\0\0\1" );
	}

	/**
	 * The reserved /80 of a server as text (`2a01:4f8:262:430c:c8::/80`), or null.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param int                  $vmid    VMID.
	 */
	public static function block( array $cluster, int $vmid ): ?string {
		$prefix = self::prefix_bytes( $cluster );

		if ( null === $prefix || $vmid < 1 || $vmid > 65535 ) {
			return null;
		}

		return self::text( $prefix . pack( 'n', $vmid ) . "\0\0\0\0\0\0" ) . '/80';
	}

	/**
	 * The gateway the guest uses: the cluster's own setting, else the first address of the /64 (the host's address on the bridge).
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 */
	public static function gateway( array $cluster ): ?string {
		$prefix = self::prefix_bytes( $cluster );

		if ( null === $prefix ) {
			return null;
		}

		$own = self::normalise( (string) ( $cluster['ipv6_gateway'] ?? '' ) );

		return null !== $own ? $own : self::text( $prefix . "\0\0\0\0\0\0\0\1" );
	}

	/**
	 * The `ip6=...,gw6=...` part of ipconfig0 for a server, or '' when the cluster has IPv6 off.
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param int                  $vmid    VMID.
	 */
	public static function ipconfig( array $cluster, int $vmid ): string {
		$address = self::address( $cluster, $vmid );
		$gateway = self::gateway( $cluster );

		return null === $address || null === $gateway ? '' : 'ip6=' . $address . '/64,gw6=' . $gateway;
	}

	/**
	 * Which cluster and VMID an address belongs to, from the prefixes of the saved clusters. The derivation only: the
	 * holder at a past time comes from IpHistory.
	 *
	 * @param string $address IPv6 address.
	 * @return array{cluster: string, vmid: int}|null
	 */
	public static function vmid_for( string $address ): ?array {
		$packed = self::pack( $address );

		if ( null === $packed ) {
			return null;
		}

		foreach ( Clusters::all() as $id => $cluster ) {
			$prefix = self::prefix_bytes( $cluster );

			if ( null !== $prefix && substr( $packed, 0, 8 ) === $prefix ) {
				$vmid = (int) unpack( 'n', substr( $packed, 8, 2 ) )[1];

				return $vmid > 0 ? array( 'cluster' => (string) $id, 'vmid' => $vmid ) : null;
			}
		}

		return null;
	}

	/**
	 * Is the text a valid IPv6 address (and not an IPv4 one)?
	 *
	 * @param string $address Text.
	 */
	public static function is_address( string $address ): bool {
		return null !== self::pack( $address );
	}

	/**
	 * The normalised (compressed, lower case) form of an IPv6 address, or null.
	 *
	 * @param string $address Text.
	 */
	public static function normalise( string $address ): ?string {
		$packed = self::pack( $address );

		return null === $packed ? null : self::text( $packed );
	}

	/**
	 * Is the address inside the /64 of the cluster?
	 *
	 * @param array<string, mixed> $cluster Cluster settings.
	 * @param string               $address Address.
	 */
	public static function in_prefix( array $cluster, string $address ): bool {
		$prefix = self::prefix_bytes( $cluster );
		$packed = self::pack( $address );

		return null !== $prefix && null !== $packed && substr( $packed, 0, 8 ) === $prefix;
	}

	/**
	 * The 16 packed bytes of an IPv6 address, or null (also for IPv4 text and for anything with a zone or a prefix length).
	 *
	 * @param string $address Text.
	 */
	public static function pack( string $address ): ?string {
		$address = trim( $address );

		if ( false === strpos( $address, ':' ) || false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return null;
		}

		$packed = inet_pton( $address );

		return false === $packed || 16 !== strlen( $packed ) ? null : $packed;
	}

	/**
	 * Packed bytes as the normalised text.
	 *
	 * @param string $packed 16 bytes.
	 */
	public static function text( string $packed ): string {
		return (string) inet_ntop( $packed );
	}

	// -- ipconfig0 ---------------------------------------------------------------------------------

	/**
	 * The ipconfig0 string with the IPv6 keys (`ip6`, `gw6`) taken out: what the IPv4 side says, untouched and in order.
	 *
	 * @param string $ipconfig0 The VM's ipconfig0.
	 */
	public static function strip( string $ipconfig0 ): string {
		$keep = array();

		foreach ( '' === $ipconfig0 ? array() : explode( ',', $ipconfig0 ) as $token ) {
			if ( ! in_array( explode( '=', $token, 2 )[0], array( 'ip6', 'gw6' ), true ) ) {
				$keep[] = $token;
			}
		}

		return implode( ',', $keep );
	}

	/**
	 * The IPv6 keys of an ipconfig0, normalised: `ip6` as `address/length`, `gw6` as an address. Empty for a key that is
	 * missing or does not parse (`auto`, `dhcp`).
	 *
	 * @param string $ipconfig0 The VM's ipconfig0.
	 * @return array{ip6: string, gw6: string}
	 */
	public static function keys( string $ipconfig0 ): array {
		$out = array( 'ip6' => '', 'gw6' => '' );

		foreach ( '' === $ipconfig0 ? array() : explode( ',', $ipconfig0 ) as $token ) {
			$pair = explode( '=', $token, 2 );

			if ( 'ip6' === $pair[0] && isset( $pair[1] ) && 1 === preg_match( '#^([0-9A-Fa-f:]+)/(\d{1,3})$#D', $pair[1], $m ) && null !== self::normalise( $m[1] ) ) {
				$out['ip6'] = self::normalise( $m[1] ) . '/' . (int) $m[2];
			} elseif ( 'gw6' === $pair[0] && isset( $pair[1] ) && null !== self::normalise( $pair[1] ) ) {
				$out['gw6'] = (string) self::normalise( $pair[1] );
			}
		}

		return $out;
	}

	/**
	 * The VM's ipconfig0 with the IPv6 part this cluster wants for the VMID. The IPv4 part is the VM's own and is never
	 * rewritten. A cluster with IPv6 off leaves the string as it is (a written address stays, see the rollout notes).
	 *
	 * @param string               $ipconfig0 The VM's ipconfig0.
	 * @param array<string, mixed> $cluster   Cluster settings.
	 * @param int                  $vmid      VMID.
	 */
	public static function with_ipconfig( string $ipconfig0, array $cluster, int $vmid ): string {
		$wanted = self::ipconfig( $cluster, $vmid );

		if ( '' === $wanted ) {
			return $ipconfig0;
		}

		$v4 = self::strip( $ipconfig0 );

		return '' === $v4 ? $wanted : $v4 . ',' . $wanted;
	}

	/**
	 * Does the ipconfig0 carry the IPv6 part this cluster wants for the VMID? True when the cluster has IPv6 off (nothing wanted).
	 *
	 * @param string               $ipconfig0 The VM's ipconfig0.
	 * @param array<string, mixed> $cluster   Cluster settings.
	 * @param int                  $vmid      VMID.
	 */
	public static function has_ipconfig( string $ipconfig0, array $cluster, int $vmid ): bool {
		$wanted = self::ipconfig( $cluster, $vmid );

		return '' === $wanted || self::keys( $ipconfig0 ) === self::keys( $wanted );
	}
}

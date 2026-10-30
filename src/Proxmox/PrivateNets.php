<?php
/**
 * Allocation of customer private VLANs and addresses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Locks;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * An operator seeds /28 networks explicitly after configuring the matching bridge. No network
 * configuration is inferred from the public Proxmox cluster settings. Allocation never calls PVE.
 */
final class PrivateNets {

	/** Days before an empty customer network can be handed to a different customer. */
	private const QUARANTINE_DAYS = 30;

	/**
	 * Add one unused RFC 1918 /28 and its VLAN to the pool. Overlapping networks are refused.
	 */
	public static function add( int $vlan, string $cidr ): bool {
		global $wpdb;

		$network = self::network( $cidr );
		if ( $vlan < 1 || $vlan > 4094 || null === $network || ! self::db_ready() || self::public_vlan_in_use( $vlan ) ) {
			return false;
		}

		list( $locked, $added ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $vlan, $network ) {
				$table = Schema::tables()['private_nets'];
				// Aligned /28s overlap only when their CIDRs match. Unique keys reject both duplicate CIDRs and VLANs.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				return 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (vlan, cidr, state, updated_at) VALUES (%d, %s, %s, %s)', $table, $vlan, $network['cidr'], 'free', gmdate( 'Y-m-d H:i:s' ) ) );
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}

		return (bool) $added;
	}

	/**
	 * Get the customer's network, or atomically assign the lowest free one.
	 *
	 * @return ?array<string,mixed> Null when the seeded pool is empty.
	 */
	public static function hold( int $user_id ): ?array {
		if ( $user_id < 1 || ! self::db_ready() ) {
			return null;
		}

		list( $locked, $row ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static fn() => self::hold_locked( $user_id )
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}

		return $row;
	}

	/** The one network+address reservation operation used by attach. */
	public static function reserve( int $user_id, int $resource_id ): ?array {
		if ( ! self::valid_resource_owner( $user_id, $resource_id ) || ! self::db_ready() ) {
			return null;
		}
		list( $locked, $result ) = Locks::with( Locks::name( 'privnet', 0 ), Locks::allocation_wait(), static function () use ( $user_id, $resource_id ) {
			$net = self::hold_locked( $user_id );
			$address = $net && in_array( $net['state'], array( 'held', 'held_return' ), true ) ? self::address_locked( $net, $resource_id ) : null;
			return $net && $address ? array( 'network' => $net, 'address' => $address ) : null;
		} );
		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}
		return $result;
	}

	/** Caller holds the privnet lock. */
	private static function hold_locked( int $user_id ): ?array {
		global $wpdb;
		$existing = self::for_user( $user_id );
		if ( null !== $existing ) {
			if ( 'quarantine' === $existing['state'] ) {
				// Keep the original quarantine timestamp. If this return's first attach fails,
				// abandon_address() restores the quarantine with its remaining time.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- returning customer keeps their VLAN.
				$wpdb->update( Schema::tables()['private_nets'], array( 'state' => 'held_return' ), array( 'id' => $existing['id'], 'state' => 'quarantine' ) );
				return self::for_user( $user_id );
			}
			return $existing;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one UPDATE claims one row; unique user_id backs it.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET user_id = %d, state = 'held', updated_at = %s WHERE state = 'free' AND user_id IS NULL ORDER BY id LIMIT 1", Schema::tables()['private_nets'], $user_id, gmdate( 'Y-m-d H:i:s' ) ) );
		return self::for_user( $user_id );
	}

	/** Customer's assigned network, including a quarantined one. */
	public static function for_user( int $user_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', Schema::tables()['private_nets'], $user_id ), ARRAY_A );
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/** Existing address with its network owner, without allocating anything. */
	public static function for_resource( int $resource_id ): ?array {
		global $wpdb;
		if ( $resource_id < 1 || ! self::db_ready() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT i.address, i.net_id, n.user_id, n.vlan, n.cidr, n.state FROM %i i JOIN %i n ON n.id = i.net_id WHERE i.resource_id = %d', Schema::tables()['private_ips'], Schema::tables()['private_nets'], $resource_id ), ARRAY_A );
		return is_array( $row ) ? array( 'address' => (string) $row['address'], 'net_id' => (int) $row['net_id'], 'user_id' => (int) $row['user_id'], 'vlan' => (int) $row['vlan'], 'cidr' => (string) $row['cidr'], 'state' => (string) $row['state'] ) : null;
	}

	/** Return an existing reservation without allocating a new network or address. */
	public static function address_for_resource( int $resource_id ): ?string {
		$row = self::for_resource( $resource_id );
		return $row ? $row['address'] : null;
	}

	/** A public VLAN on any configured cluster cannot be seeded into the private pool. */
	public static function public_vlan_in_use( int $vlan ): bool {
		foreach ( Clusters::all() as $cluster ) {
			if ( $vlan > 0 && $vlan === (int) ( $cluster['vlan'] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a seeded private VLAN would conflict with a cluster's public VLAN. */
	public static function uses_vlan( int $vlan ): bool {
		global $wpdb;
		if ( $vlan < 1 || ! self::db_ready() ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- validation reads fresh pool state.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE vlan = %d', Schema::tables()['private_nets'], $vlan ) );
	}

	/** Read-only capacity preflight before disconnecting a transferred VM from its old VLAN. */
	public static function capacity_for_user( int $user_id ): bool {
		global $wpdb;
		if ( $user_id < 1 || ! self::db_ready() ) {
			return false;
		}
		$net = self::for_user( $user_id );
		if ( null === $net ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh pool state.
			return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE state = 'free' AND user_id IS NULL LIMIT 1", Schema::tables()['private_nets'] ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh pool state.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE net_id = %d', Schema::tables()['private_ips'], $net['id'] ) );
		return $count < 13;
	}

	/** Undo a definitely rejected first attach; an unexposed empty network is free immediately. */
	public static function abandon_address( int $user_id, int $resource_id ): bool {
		global $wpdb;
		list( $locked, $done ) = Locks::with( Locks::name( 'privnet', 0 ), Locks::allocation_wait(), static function () use ( $wpdb, $user_id, $resource_id ) {
			$row = self::for_resource( $resource_id );
			if ( null === $row || $row['user_id'] !== $user_id ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
			if ( 1 !== $wpdb->delete( Schema::tables()['private_ips'], array( 'resource_id' => $resource_id, 'net_id' => $row['net_id'] ) ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE net_id = %d', Schema::tables()['private_ips'], $row['net_id'] ) );
			if ( 0 === $count && 'held_return' === $row['state'] ) {
				// The earlier 30-day quarantine still applies; leave updated_at unchanged.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- restore the returning customer's quarantine.
				$wpdb->update( Schema::tables()['private_nets'], array( 'state' => 'quarantine' ), array( 'id' => $row['net_id'], 'user_id' => $user_id, 'state' => 'held_return' ) );
			} elseif ( 0 === $count && 'held' === $row['state'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a newly claimed, unexposed network can be free immediately.
				$wpdb->update( Schema::tables()['private_nets'], array( 'user_id' => null, 'state' => 'free', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row['net_id'], 'user_id' => $user_id, 'state' => 'held' ) );
			}
			return true;
		} );
		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}
		return (bool) $done;
	}

	/**
	 * Assign an address to a resource owned by this customer. .0 is the network, .1 is reserved
	 * for a possible gateway, and .15 is broadcast. No default gateway is put on this NIC.
	 */
	public static function address( int $user_id, int $resource_id ): ?string {
		if ( ! self::valid_resource_owner( $user_id, $resource_id ) || ! self::db_ready() ) {
			return null;
		}

		list( $locked, $address ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $user_id, $resource_id ) {
				$net = self::for_user( $user_id );
				if ( null === $net || ! in_array( $net['state'], array( 'held', 'held_return' ), true ) ) {
					return null;
				}
				return self::address_locked( $net, $resource_id );
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}

		return $address;
	}

	/** Caller holds the privnet lock. */
	private static function address_locked( array $net, int $resource_id ): ?string {
		global $wpdb;
		$table = Schema::tables()['private_ips'];
		$existing = self::for_resource( $resource_id );
		if ( null !== $existing ) {
			return $existing['net_id'] === $net['id'] ? $existing['address'] : null;
		}
		$base = self::network( $net['cidr'] )['first'];
		for ( $host = 2; $host <= 14; $host++ ) {
			$ip = long2ip( $base + $host );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- unique address and resource keys are backstops.
			$written = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (net_id, resource_id, address, updated_at) VALUES (%d, %d, %s, %s)', $table, $net['id'], $resource_id, $ip, gmdate( 'Y-m-d H:i:s' ) ) );
			if ( 1 === $written ) {
				return $ip;
			}
		}
		return null;
	}

	private static function valid_resource_owner( int $user_id, int $resource_id ): bool {
		$resource = Resource::find( $resource_id );
		$license = $resource ? $resource->license() : array();
		return $user_id > 0 && $resource_id > 0 && $resource && $resource->is_valid() && 'deleted' !== $resource->get( 'state' ) && (int) ( $license['user_id'] ?? 0 ) === $user_id;
	}

	/** Remove a resource's address after its VM has actually been removed. */
	public static function release_address( int $resource_id ): bool {
		global $wpdb;
		list( $locked, $released ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $resource_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				return 1 === $wpdb->delete( Schema::tables()['private_ips'], array( 'resource_id' => $resource_id ) );
			}
		);
		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}
		return (bool) $released;
	}

	/** Release a deleted VM's address and quarantine its network if this was the last one. */
	public static function release_and_retire( int $resource_id ): bool {
		global $wpdb;
		list( $locked, $done ) = Locks::with( Locks::name( 'privnet', 0 ), Locks::allocation_wait(), static function () use ( $wpdb, $resource_id ) {
			$row = self::for_resource( $resource_id );
			if ( null === $row ) {
				return true; // Idempotent after a completed prior cleanup.
			}
			// Quarantine first when this is the last address. If the later DELETE fails or the
			// process stops, a retry still sees the reservation and can finish it safely.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE net_id = %d', Schema::tables()['private_ips'], $row['net_id'] ) );
			if ( 1 === $count && 'quarantine' !== $row['state'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- last address starts quarantine.
				if ( 1 !== $wpdb->update( Schema::tables()['private_nets'], array( 'state' => 'quarantine', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row['net_id'], 'state' => $row['state'] ) ) ) {
					return false;
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
			return 1 === $wpdb->delete( Schema::tables()['private_ips'], array( 'resource_id' => $resource_id, 'net_id' => $row['net_id'] ) );
		} );
		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}
		return (bool) $done;
	}

	/**
	 * Mark an empty customer's network for 30-day quarantine. Existing addresses keep it held.
	 */
	public static function retire( int $user_id ): bool {
		global $wpdb;
		list( $locked, $retired ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $user_id ) {
				$net = self::for_user( $user_id );
				if ( null === $net || ! in_array( $net['state'], array( 'held', 'held_return' ), true ) ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE net_id = %d', Schema::tables()['private_ips'], $net['id'] ) );
				if ( 0 !== (int) $count ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				return 1 === $wpdb->update( Schema::tables()['private_nets'], array( 'state' => 'quarantine', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $net['id'], 'state' => $net['state'] ) );
			}
		);
		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}
		return (bool) $retired;
	}

	/** Reclaim quarantined rows only after 30 days, and only if still empty. */
	public static function reclaim(): int {
		global $wpdb;
		if ( ! self::db_ready() ) {
			return 0;
		}
		try {
			list( $locked, $reclaimed ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb ) {
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::QUARANTINE_DAYS * DAY_IN_SECONDS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i n SET n.user_id = NULL, n.state = 'free', n.updated_at = %s WHERE n.state = 'quarantine' AND n.updated_at < %s AND NOT EXISTS (SELECT 1 FROM %i i WHERE i.net_id = n.id)", Schema::tables()['private_nets'], gmdate( 'Y-m-d H:i:s' ), $cutoff, Schema::tables()['private_ips'] ) );
			}
			);
		} catch ( \RuntimeException $e ) {
			return 0;
		}
		return $locked ? $reclaimed : 0;
	}

	/** A strict private /28, aligned to 16 addresses. */
	private static function network( string $cidr ): ?array {
		if ( ! preg_match( '#^([0-9.]+)/28$#D', $cidr, $m ) || false === filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return null;
		}
		$ip = (int) sprintf( '%u', ip2long( $m[1] ) );
		if ( 0 !== ( $ip & 15 ) ) {
			return null;
		}
		$private = ( $ip & 0xff000000 ) === 0x0a000000 || ( $ip & 0xfff00000 ) === 0xac100000 || ( $ip & 0xffff0000 ) === 0xc0a80000;
		return $private ? array( 'first' => $ip, 'cidr' => long2ip( $ip ) . '/28' ) : null;
	}

	/** Whether an address is an allocatable host (.2 through .14) of a private /28. */
	public static function contains_host( string $cidr, string $address ): bool {
		$network = self::network( $cidr );
		if ( null === $network || false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}
		$ip = (int) sprintf( '%u', ip2long( $address ) );
		return $ip >= $network['first'] + 2 && $ip <= $network['first'] + 14;
	}

	/** @return array<string,mixed> */
	private static function shape( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['user_id'] = null === $row['user_id'] ? null : (int) $row['user_id'];
		$row['vlan']    = (int) $row['vlan'];
		return $row;
	}

	private static function db_ready(): bool {
		return Schema::DB_VERSION === get_option( Schema::OPTION_DB_VERSION );
	}
}

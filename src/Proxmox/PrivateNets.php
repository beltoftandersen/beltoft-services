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
		if ( $vlan < 1 || $vlan > 4094 || null === $network || ! self::db_ready() ) {
			return false;
		}

		list( $locked, $added ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $vlan, $network ) {
				$table = Schema::tables()['private_nets'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- allocation state must be fresh.
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT vlan, cidr FROM %i', $table ), ARRAY_A );
				foreach ( (array) $rows as $row ) {
					$other = self::network( (string) $row['cidr'] );
					if ( (int) $row['vlan'] === $vlan || ( null !== $other && $other['first'] === $network['first'] ) ) {
						return false;
					}
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				return 1 === $wpdb->insert( $table, array( 'vlan' => $vlan, 'cidr' => $network['cidr'], 'state' => 'free', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
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
		global $wpdb;
		if ( $user_id < 1 || ! self::db_ready() ) {
			return null;
		}

		list( $locked, $row ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $user_id ) {
				$existing = self::for_user( $user_id );
				if ( null !== $existing ) {
					if ( 'quarantine' === $existing['state'] ) {
						// The same customer may return during quarantine; the VLAN was never reassigned.
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
						$wpdb->update( Schema::tables()['private_nets'], array( 'state' => 'held', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $existing['id'], 'state' => 'quarantine' ) );
						return self::for_user( $user_id );
					}
					return $existing;
				}

				// One UPDATE claims one row. Unique user_id is the race backstop.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				$wpdb->query( $wpdb->prepare( "UPDATE %i SET user_id = %d, state = 'held', updated_at = %s WHERE state = 'free' AND user_id IS NULL ORDER BY id LIMIT 1", Schema::tables()['private_nets'], $user_id, gmdate( 'Y-m-d H:i:s' ) ) );
				return self::for_user( $user_id );
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}

		return $row;
	}

	/** Customer's assigned network, including a quarantined one. */
	public static function for_user( int $user_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', Schema::tables()['private_nets'], $user_id ), ARRAY_A );
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/** Return an existing reservation without allocating a new network or address. */
	public static function address_for_resource( int $resource_id ): ?string {
		global $wpdb;
		if ( $resource_id < 1 || ! self::db_ready() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
		$address = $wpdb->get_var( $wpdb->prepare( 'SELECT address FROM %i WHERE resource_id = %d', Schema::tables()['private_ips'], $resource_id ) );
		return is_string( $address ) ? $address : null;
	}

	/**
	 * Assign an address to a resource owned by this customer. .0 is the network, .1 is reserved
	 * for a possible gateway, and .15 is broadcast. No default gateway is put on this NIC.
	 */
	public static function address( int $user_id, int $resource_id ): ?string {
		global $wpdb;
		$resource = Resource::find( $resource_id );
		$license  = $resource ? $resource->license() : array();
		if ( $user_id < 1 || $resource_id < 1 || ! $resource || ! $resource->is_valid() || 'deleted' === $resource->get( 'state' ) || (int) ( $license['user_id'] ?? 0 ) !== $user_id || ! self::db_ready() ) {
			return null;
		}

		list( $locked, $address ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $user_id, $resource_id ) {
				$net = self::for_user( $user_id );
				if ( null === $net || 'held' !== $net['state'] ) {
					return null;
				}

				$table = Schema::tables()['private_ips'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d', $table, $resource_id ), ARRAY_A );
				if ( is_array( $existing ) ) {
					return (int) $existing['net_id'] === $net['id'] ? (string) $existing['address'] : null;
				}

				$base = self::network( $net['cidr'] )['first'];
				for ( $host = 2; $host <= 14; $host++ ) {
					$ip = long2ip( $base + $host );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- unique (net_id,address) is the backstop.
					$written = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (net_id, resource_id, address, updated_at) VALUES (%d, %d, %s, %s)', $table, $net['id'], $resource_id, $ip, gmdate( 'Y-m-d H:i:s' ) ) );
					if ( 1 === $written ) {
						return $ip;
					}
				}
				return null;
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}

		return $address;
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
				if ( null === $net || 'held' !== $net['state'] ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh allocation state.
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE net_id = %d', Schema::tables()['private_ips'], $net['id'] ) );
				if ( 0 !== (int) $count ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				return 1 === $wpdb->update( Schema::tables()['private_nets'], array( 'state' => 'quarantine', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $net['id'], 'state' => 'held' ) );
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
		list( $locked, $reclaimed ) = Locks::with(
			Locks::name( 'privnet', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb ) {
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::QUARANTINE_DAYS * DAY_IN_SECONDS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own allocation table.
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i n SET n.user_id = NULL, n.state = 'free', n.updated_at = %s WHERE n.state = 'quarantine' AND n.updated_at < %s AND NOT EXISTS (SELECT 1 FROM %i i WHERE i.net_id = n.id)", Schema::tables()['private_nets'], gmdate( 'Y-m-d H:i:s' ), $cutoff, Schema::tables()['private_ips'] ) );
			}
		);
		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}
		return $reclaimed;
	}

	/** A strict private /28, aligned to 16 addresses. */
	private static function network( string $cidr ): ?array {
		if ( ! preg_match( '#^([0-9.]+)/28$#', $cidr, $m ) || false === filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return null;
		}
		$ip = (int) sprintf( '%u', ip2long( $m[1] ) );
		if ( 0 !== ( $ip & 15 ) ) {
			return null;
		}
		$private = ( $ip & 0xff000000 ) === 0x0a000000 || ( $ip & 0xfff00000 ) === 0xac100000 || ( $ip & 0xffff0000 ) === 0xc0a80000;
		return $private ? array( 'first' => $ip, 'cidr' => long2ip( $ip ) . '/28' ) : null;
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

<?php
/**
 * The pool of addresses handed to virtual machines.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Locks;

defined( 'ABSPATH' ) || exit;

/**
 * One address per resource, lowest id first. Atomicity comes from a named lock around one
 * UPDATE statement (never a transaction), with the unique key on `resource_id` as the backstop.
 * IPv4 only in 0.2.0.
 */
final class IpPool {

	public const FREE_TRANSIENT = 'beltoft_services_proxmox_ip_free';

	/**
	 * Largest network add() expands: /20 is 4094 hosts.
	 */
	private const MIN_PREFIX = 20;

	/**
	 * Adds an address, or every host address of a network, as free. Addresses already in the pool
	 * are left alone. A gateway inside the network is not added: it is not for a machine.
	 *
	 * @param string  $address_or_cidr 192.0.2.10 or 192.0.2.8/29.
	 * @param ?string $gateway         Gateway stored with each row.
	 * @param ?int    $prefix          Prefix length stored with each row; defaults to the CIDR's, or 32 for a bare address.
	 * @return int How many addresses were added (0 when the input is not valid IPv4).
	 */
	public static function add( string $address_or_cidr, ?string $gateway = null, ?int $prefix = null ): int {
		global $wpdb;

		if ( null !== $gateway && '' !== $gateway && false === filter_var( $gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 0;
		}

		$hosts = self::expand( trim( $address_or_cidr ), $cidr_prefix );

		if ( array() === $hosts ) {
			return 0;
		}

		$prefix  = null === $prefix ? $cidr_prefix : $prefix;
		$gateway = ( null === $gateway || '' === $gateway ) ? null : $gateway;
		$added   = 0;
		$table   = Schema::tables()['ips'];
		$now     = gmdate( 'Y-m-d H:i:s' );

		foreach ( $hosts as $host ) {
			if ( $host === $gateway ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the pool is the plugin's own table; INSERT IGNORE keeps adding idempotent.
			$added += (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (address, prefix, gateway, state, updated_at) VALUES (%s, %d, %s, %s, %s)', $table, $host, $prefix, $gateway, 'free', $now ) );
		}

		self::changed();

		return $added;
	}

	/**
	 * Host addresses of an address or a network, lowest first.
	 *
	 * @param string $input       Address or CIDR.
	 * @param int    $out_prefix  Set to the prefix length.
	 * @return string[] Empty when the input is not valid, or the network is larger than /20.
	 */
	private static function expand( string $input, &$out_prefix ): array {
		$out_prefix = 32;
		$parts      = explode( '/', $input );

		if ( count( $parts ) > 2 || false === filter_var( $parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return array();
		}

		$ip = (int) ip2long( $parts[0] );

		if ( 1 === count( $parts ) ) {
			return array( long2ip( $ip ) );
		}

		if ( ! ctype_digit( $parts[1] ) ) {
			return array();
		}

		$prefix = (int) $parts[1];

		if ( $prefix > 32 || $prefix < self::MIN_PREFIX ) {
			return array();
		}

		$out_prefix = $prefix;
		$size       = 1 << ( 32 - $prefix );
		$network    = $ip & ( ( 0xFFFFFFFF << ( 32 - $prefix ) ) & 0xFFFFFFFF );
		$hosts      = array();

		if ( $prefix >= 31 ) { // /32 is one address, /31 two (RFC 3021): no network or broadcast address.
			$first = $network;
			$last  = $network + $size - 1;
		} else {
			$first = $network + 1;
			$last  = $network + $size - 2;
		}

		for ( $n = $first; $n <= $last; $n++ ) {
			$hosts[] = long2ip( $n );
		}

		return $hosts;
	}

	/**
	 * The address a resource holds, or the lowest free one, which it now holds.
	 *
	 * @param int $resource_id Activation id of the resource.
	 * @return ?array{id: int, address: string, prefix: int, gateway: ?string, state: string, resource_id: ?int} Null when the pool has no free address.
	 */
	public static function hold( int $resource_id ): ?array {
		global $wpdb;

		list( $locked, $row ) = Locks::with(
			Locks::name( 'ippool', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $resource_id ) {
				$existing = self::for_resource( $resource_id );

				if ( $existing ) {
					return $existing;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one statement picks and takes the address.
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'held', resource_id = %d, updated_at = %s WHERE state = 'free' ORDER BY id ASC LIMIT 1", Schema::tables()['ips'], $resource_id, gmdate( 'Y-m-d H:i:s' ) ) );

				return 1 === $taken ? self::for_resource( $resource_id ) : null;
			}
		);

		if ( ! $locked ) {
			// Not "no address": the caller retries.
			throw new \RuntimeException( 'The IP pool is busy.' );
		}

		self::changed();

		return $row;
	}

	/**
	 * The address a resource holds (held or assigned), or null.
	 *
	 * @param int $resource_id Activation id.
	 * @return ?array{id: int, address: string, prefix: int, gateway: ?string, state: string, resource_id: ?int}
	 */
	public static function for_resource( int $resource_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- allocation state must be read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d', Schema::tables()['ips'], $resource_id ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Marks the held address as assigned: the machine now uses it.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function assign( int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one statement.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'assigned', updated_at = %s WHERE resource_id = %d AND state = 'held'", Schema::tables()['ips'], gmdate( 'Y-m-d H:i:s' ), $resource_id ) );

		$row = self::for_resource( $resource_id );

		self::changed();

		return null !== $row && 'assigned' === $row['state'];
	}

	/**
	 * Gives the resource's address back to the pool. A blocked address is never freed.
	 *
	 * @param int $resource_id Activation id.
	 * @return bool Whether the resource held an address.
	 */
	public static function release( int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one statement.
		$freed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'free', resource_id = NULL, updated_at = %s WHERE resource_id = %d AND state IN ('held', 'assigned')", Schema::tables()['ips'], gmdate( 'Y-m-d H:i:s' ), $resource_id ) );

		self::changed();

		return $freed > 0;
	}

	/**
	 * Gives one specific free address to a resource, as in use (adopting a machine that already
	 * uses it). False when the address is not free.
	 *
	 * @throws \RuntimeException When the pool is busy (not "no": try again).
	 *
	 * @param string $address     Address.
	 * @param int    $resource_id Activation id.
	 */
	public static function take( string $address, int $resource_id ): bool {
		global $wpdb;

		list( $locked, $taken ) = Locks::with(
			Locks::name( 'ippool', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $address, $resource_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one statement.
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'assigned', resource_id = %d, updated_at = %s WHERE address = %s AND state = 'free' AND resource_id IS NULL", Schema::tables()['ips'], $resource_id, gmdate( 'Y-m-d H:i:s' ), $address ) );
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The IP pool is busy.' );
		}

		self::changed();

		return 1 === $taken;
	}

	/**
	 * The pool row of an address, or null.
	 *
	 * @param string $address Address.
	 * @return ?array{id: int, address: string, prefix: int, gateway: ?string, state: string, resource_id: ?int}
	 */
	public static function find( string $address ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must be fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE address = %s', Schema::tables()['ips'], $address ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Every address with the state of the resource that holds it, lowest first.
	 *
	 * @return array<int, array{id: int, address: string, prefix: int, gateway: ?string, state: string, resource_id: ?int, leftover: string}>
	 */
	public static function rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an admin list must be fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY INET_ATON(address) ASC LIMIT 5000', Schema::tables()['ips'] ), ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$shaped             = self::shape( $row );
			$shaped['leftover'] = self::leftover( $shaped );
			$out[]              = $shaped;
		}

		return $out;
	}

	/**
	 * Why an address in use may be released by an admin: 'failed' (held by a resource that failed:
	 * nothing was built) or 'closed' (the resource was closed, for instance by "Mark deleted", and
	 * the machine may still exist). Empty for everything else.
	 *
	 * @param array<string, mixed> $row Shaped row.
	 */
	public static function leftover( array $row ): string {
		if ( ! in_array( $row['state'], array( 'held', 'assigned' ), true ) || empty( $row['resource_id'] ) ) {
			return '';
		}

		$activation = \BeltoftLicenses\Licenses::activation( (int) $row['resource_id'] );

		if ( null === $activation || ! empty( $activation['deactivated_at'] ) ) {
			return 'closed';
		}

		$resource = \BeltoftServices\Resource::find( (int) $row['resource_id'] );

		return 'held' === $row['state'] && $resource && 'failed' === $resource->get( 'state' ) ? 'failed' : '';
	}

	/**
	 * Must an admin type RELEASE <ip> to release this leftover? Yes when a machine may still exist:
	 * a closed resource, or a failed one that has a VM record (the clone may have left a VM behind).
	 *
	 * @param array<string, mixed> $row Row from rows().
	 */
	public static function needs_typed_release( array $row ): bool {
		return 'closed' === $row['leftover'] || ( 'failed' === $row['leftover'] && null !== VmRecords::for_resource( (int) $row['resource_id'] ) );
	}

	/**
	 * Releases the address of a failed or closed resource.
	 *
	 * @param string $address Address.
	 * @return string The kind that was released ('failed' or 'closed'), or '' when this address is not a leftover.
	 */
	public static function release_leftover( string $address ): string {
		foreach ( self::rows() as $row ) {
			if ( $row['address'] === $address && '' !== $row['leftover'] ) {
				if ( ! self::release( (int) $row['resource_id'] ) ) {
					return '';
				}

				// A closed resource's VM row goes with its address (the admin typed the release):
				// otherwise its VMID would stay taken for ever. A failed resource keeps its row, as
				// a clone may have left a machine behind for the admin to look at.
				if ( 'closed' === $row['leftover'] ) {
					VmRecords::remove( (int) $row['resource_id'] );
				}

				return $row['leftover'];
			}
		}

		return '';
	}

	/**
	 * Takes a free address out of circulation. False when there is no such free address (unknown,
	 * or in use: an address in use cannot be blocked).
	 *
	 * @throws \RuntimeException When the pool is busy (not "no": try again).
	 *
	 * @param string $address Address.
	 */
	public static function block( string $address ): bool {
		return self::switch_state( $address, 'free', 'blocked' );
	}

	/**
	 * Puts a blocked address back into circulation. False when there is no such blocked address.
	 *
	 * @throws \RuntimeException When the pool is busy (not "no": try again).
	 *
	 * @param string $address Address.
	 */
	public static function unblock( string $address ): bool {
		return self::switch_state( $address, 'blocked', 'free' );
	}

	/**
	 * Moves an address between two states under the pool lock, so it cannot be handed out in between.
	 *
	 * @param string $address Address.
	 * @param string $from    Required state.
	 * @param string $to      New state.
	 */
	private static function switch_state( string $address, string $from, string $to ): bool {
		global $wpdb;

		list( $locked, $changed ) = Locks::with(
			Locks::name( 'ippool', 0 ),
			Locks::allocation_wait(),
			static function () use ( $wpdb, $address, $from, $to ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one statement.
				return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET state = %s, updated_at = %s WHERE address = %s AND state = %s AND resource_id IS NULL', Schema::tables()['ips'], $to, gmdate( 'Y-m-d H:i:s' ), $address, $from ) );
			}
		);

		if ( ! $locked ) {
			throw new \RuntimeException( 'The IP pool is busy.' );
		}

		self::changed();

		return $changed > 0;
	}

	/**
	 * How many addresses are free. Cached for 60 seconds and cleared on every change.
	 */
	public static function free_count(): int {
		global $wpdb;

		$cached = get_transient( self::FREE_TRANSIENT );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in a transient below.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE state = 'free'", Schema::tables()['ips'] ) );
		set_transient( self::FREE_TRANSIENT, $count, MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Clears the cached count.
	 */
	private static function changed(): void {
		delete_transient( self::FREE_TRANSIENT );
	}

	/**
	 * Row with typed values.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array{id: int, address: string, prefix: int, gateway: ?string, state: string, resource_id: ?int}
	 */
	private static function shape( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'address'     => (string) $row['address'],
			'prefix'      => (int) $row['prefix'],
			'gateway'     => null === $row['gateway'] ? null : (string) $row['gateway'],
			'state'       => (string) $row['state'],
			'resource_id' => null === $row['resource_id'] ? null : (int) $row['resource_id'],
		);
	}
}

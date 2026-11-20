<?php
/**
 * The IPv4 add-on: which server or database an address belongs to, which address it is, and what role it has.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Lifecycle;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Addon\AddonLink;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Proxmox\Vm\Profiles;
use BeltoftServices\Proxmox\Vm\VpsProfile;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One add-on resource is one address. Each add-on has a role on its parent (a server or a database):
 *
 * - main: the address is the parent's own, as a dedicated server's or a database's public address is. The pool row is held
 *   by the PARENT resource, so everything that reads the parent's address (`IpPool::for_resource()`, the `ip` meta, the
 *   firewall, the apps) finds it as before. A database always gets main; a VPS Basic (no IPv4 in its plan) gets main for
 *   its first address; a dedicated VPS has its main address already.
 * - extra: one more address on a server that already has a main one. The pool row is held by the ADD-ON resource, so a
 *   server has its main row and one row per extra address, and each add-on can be taken away alone.
 *
 * Meta of the add-on: `ipv4_role` (main or extra, decided once under the parent's lock), `ipv4_address` (the address once
 * taken) and `ipv4_ended` (the address is being given back: the parent no longer counts it).
 */
final class Ipv4Meta {

	public const ROLE    = 'ipv4_role';
	public const ADDRESS = 'ipv4_address';
	public const ENDED   = 'ipv4_ended';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
		add_filter( 'user_has_cap', array( __CLASS__, 'guard_cancel' ), 20, 4 );
	}

	/**
	 * The parent chosen at add to cart, from the order line.
	 *
	 * @param array<string, mixed> $meta    Meta so far.
	 * @param array<string, mixed> $license License row.
	 * @param Driver               $driver  Driver of the license.
	 * @return array<string, mixed>
	 */
	public static function initial_meta( array $meta, array $license, Driver $driver ): array {
		return AddonLink::initial_meta( self::addon(), $meta, $license, $driver );
	}

	/**
	 * The add-on kind.
	 */
	public static function addon(): Addon {
		return Addon::for_driver( Ipv4Driver::ID );
	}

	/**
	 * Is the resource a database?
	 *
	 * @param Resource $r Resource.
	 */
	public static function is_db( Resource $r ): bool {
		return DbDriver::ID === $r->get( 'driver' );
	}

	/**
	 * Is the resource a server or a database that can have the add-on: one of ours, not adopted?
	 *
	 * @param Resource|null $r Resource.
	 */
	public static function is_parent( ?Resource $r ): bool {
		return $r && in_array( (string) $r->get( 'driver' ), self::addon()->parent_drivers(), true ) && true !== $r->get( 'linked' );
	}

	/**
	 * The server or database an add-on belongs to, or null. A line bought together with its parent has no `server_license`
	 * yet: it is the one server or database of the same paid order.
	 *
	 * @param Resource $addon Add-on resource.
	 */
	public static function parent_of( Resource $addon ): ?Resource {
		$id = (int) $addon->get( 'server_license' );

		if ( $id < 1 && (int) $addon->get( 'server_order_id' ) > 0 ) {
			$id = (int) self::parent_in_order( (int) $addon->get( 'server_order_id' ), (int) ( $addon->license()['user_id'] ?? 0 ) );
		}

		if ( $id < 1 ) {
			return null;
		}

		$parent = Resource::for_license( $id );

		return $parent && in_array( (string) $parent->get( 'driver' ), self::addon()->parent_drivers(), true ) ? $parent : null;
	}

	/**
	 * The one server or database license of a paid order for one customer, or null.
	 *
	 * @param int $order_id Order id.
	 * @param int $user_id  Customer.
	 */
	public static function parent_in_order( int $order_id, int $user_id ): ?int {
		$found = array();

		foreach ( Licenses::where( 'order_id', $order_id ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) === $user_id && AddonLink::is_parent( self::addon(), $license ) ) {
				$found[] = (int) $license['id'];
			}
		}

		return 1 === count( $found ) ? $found[0] : null;
	}

	/**
	 * The add-ons of one parent, whatever their state.
	 *
	 * @param Resource $parent Server or database.
	 * @return Resource[]
	 */
	public static function for_parent( Resource $parent ): array {
		return AddonLink::for_server( self::addon(), $parent );
	}

	/**
	 * The add-ons that hold a place: not failed, deleted or revoked, and not being given back.
	 *
	 * @param Resource $parent Server or database.
	 * @return Resource[]
	 */
	public static function reserved_for( Resource $parent ): array {
		return array_values(
			array_filter(
				self::for_parent( $parent ),
				static fn( Resource $a ) => ! in_array( $a->get( 'state' ), array( 'failed', 'deleted', 'deleting' ), true ) && 'revoked' !== ( $a->license()['status'] ?? '' ) && true !== $a->get( self::ENDED )
			)
		);
	}

	/**
	 * Does an add-on count now: ready, meant to be ready, and its licence active.
	 *
	 * @param Resource $addon Add-on.
	 */
	public static function counts( Resource $addon ): bool {
		return 'ready' === $addon->get( 'state' ) && 'ready' === $addon->get( 'desired' ) && Lifecycle::is_active( $addon->license() ) && true !== $addon->get( self::ENDED );
	}

	/**
	 * How many addresses a parent may have through the add-on: one for a database, `ipv4_max_extra` for a server (default 16).
	 *
	 * @param Resource $parent Server or database.
	 */
	public static function limit( Resource $parent ): int {
		if ( self::is_db( $parent ) ) {
			// A database built with the old plan field `public_ip` already has its one address.
			return \BeltoftServices\Proxmox\Db\DbIp::plan_wants( (array) $parent->get( 'plan' ) ) ? 0 : 1;
		}

		return max( 1, (int) apply_filters( 'beltoft_services_ipv4_max_per_server', 16, $parent ) );
	}

	/**
	 * Does a server have its own IPv4 address outside the add-on: a plan with `public_ip` 1 (a dedicated VPS)?
	 *
	 * @param Resource $parent Server or database.
	 */
	public static function has_plan_address( Resource $parent ): bool {
		return ! self::is_db( $parent ) && VpsProfile::plan_has_ipv4( (array) $parent->get( 'plan' ) );
	}

	/**
	 * Does the server (or database) have a main IPv4 address now?
	 *
	 * @param Resource $parent Server or database.
	 */
	public static function has_main( Resource $parent ): bool {
		return self::has_plan_address( $parent ) || false !== filter_var( (string) $parent->get( 'ip' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || null !== IpPool::for_resource( $parent->id() );
	}

	/**
	 * The role the next add-on would get: main when the parent has no main address and no add-on that reserved it.
	 *
	 * @param Resource $parent Server or database.
	 * @param Resource $addon  The add-on asking (its own role does not count).
	 */
	public static function next_role( Resource $parent, Resource $addon ): string {
		if ( self::has_plan_address( $parent ) ) {
			return 'extra';
		}

		foreach ( self::reserved_for( $parent ) as $other ) {
			if ( $other->id() !== $addon->id() && 'main' === $other->get( self::ROLE ) ) {
				return 'extra';
			}
		}

		return self::is_db( $parent ) || ! self::has_main( $parent ) ? 'main' : 'extra';
	}

	/**
	 * Decides the role of an add-on once, under the parent's lock (two lines bought together get one main and one extra),
	 * and stores it.
	 *
	 * @param Resource $parent Server or database.
	 * @param Resource $addon  Add-on.
	 * @return string main, extra, or '' when the lock was busy or the role could not be stored.
	 */
	public static function decide_role( Resource $parent, Resource $addon ): string {
		$fixed = (string) $addon->get( self::ROLE );

		if ( in_array( $fixed, array( 'main', 'extra' ), true ) ) {
			return $fixed;
		}

		list( $locked, $role ) = Locks::with(
			Locks::name( 'ipv4_role', $parent->id() ),
			Locks::allocation_wait(),
			static function () use ( $parent, $addon ): string {
				$role = self::next_role( $parent, $addon );

				return $addon->set( array( self::ROLE => $role ) ) ? $role : '';
			}
		);

		return $locked ? (string) $role : '';
	}

	/**
	 * Which resource holds the pool row of an add-on: the parent for a main address, the add-on for an extra one.
	 *
	 * @param Resource $addon  Add-on.
	 * @param Resource $parent Parent.
	 */
	public static function owner_id( Resource $addon, Resource $parent ): int {
		return 'main' === $addon->get( self::ROLE ) ? $parent->id() : $addon->id();
	}

	/**
	 * The addresses an add-on gives a parent, in the order they were taken: role, address and the add-on.
	 *
	 * @param Resource $parent Server or database.
	 * @return array<int, array{role: string, address: string, addon: Resource, live: bool}>
	 */
	public static function addresses( Resource $parent ): array {
		$out = array();

		foreach ( self::reserved_for( $parent ) as $addon ) {
			$address = (string) $addon->get( self::ADDRESS );

			if ( '' === $address || ! in_array( $addon->get( self::ROLE ), array( 'main', 'extra' ), true ) ) {
				continue;
			}

			$row = IpPool::find( $address );

			$out[] = array(
				'role'    => (string) $addon->get( self::ROLE ),
				'address' => $address,
				'addon'   => $addon,
				'live'    => null !== $row && 'assigned' === $row['state'],
			);
		}

		return $out;
	}

	/**
	 * The extra addresses a server's firewall (ipfilter) must allow: every one an add-on of the server has taken and still holds
	 * (not one that is being given back), whether or not the pool says "assigned" yet.
	 *
	 * @param Resource $server Server.
	 * @return string[]
	 */
	public static function extra_addresses( Resource $server ): array {
		$out = array();

		foreach ( self::addresses( $server ) as $entry ) {
			if ( 'extra' === $entry['role'] ) {
				$out[] = $entry['address'];
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * May the main address of a server be taken away by the customer: the server keeps another address (IPv6) and no extra address
	 * depends on it. A database has no other way in, but loses only its public address.
	 *
	 * @param Resource $parent Server or database.
	 */
	public static function main_removable( Resource $parent ): bool {
		return self::is_db( $parent ) || ( '' !== (string) $parent->get( 'ip6' ) && array() === self::extra_addresses( $parent ) );
	}

	/**
	 * Filter user_has_cap: the customer cannot change (so cannot cancel) the subscription of the main address of a server while
	 * that would leave extra addresses without it or the server without any address. The plugin's own cancels (a deleted
	 * server takes its add-ons with it) do not ask a capability.
	 *
	 * @param mixed $allcaps Capabilities.
	 * @param mixed $caps    Required capabilities.
	 * @param mixed $args    Capability, user, subscription id.
	 * @return mixed
	 */
	public static function guard_cancel( $allcaps, $caps = array(), $args = array() ) {
		if ( ! is_array( $allcaps ) || ! is_array( $args ) || 'edit_shop_subscription_status' !== ( $args[0] ?? '' ) || empty( $args[2] ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return $allcaps;
		}

		$subscription = wcs_get_subscription( (int) $args[2] );

		if ( ! $subscription instanceof \WC_Subscription || ! $subscription->has_status( 'active' ) ) {
			return $allcaps;
		}

		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$addon = Resource::for_license( (int) $license['id'] );

			if ( ! $addon || Ipv4Driver::ID !== $addon->get( 'driver' ) || 'main' !== $addon->get( self::ROLE ) ) {
				continue;
			}

			$parent = self::parent_of( $addon );

			if ( $parent && ! self::is_db( $parent ) && ! self::main_removable( $parent ) ) {
				$allcaps['edit_shop_subscription_status'] = false;
			}
		}

		return $allcaps;
	}

	/**
	 * Does a database want a public address through the add-on (an add-on that is not given back)?
	 *
	 * @param Resource $db Database.
	 */
	public static function db_wants( Resource $db ): bool {
		return self::is_db( $db ) && array() !== self::reserved_for( $db );
	}

	/**
	 * May one more address be added to a parent now: it is ready, and under the limit.
	 *
	 * @param Resource $parent Server or database.
	 */
	public static function room( Resource $parent ): bool {
		return count( self::reserved_for( $parent ) ) < self::limit( $parent );
	}

	/**
	 * An add-on whose parent was deleted has been ended by AddonCascade: its extra address goes back to the pool (the main
	 * one went with the parent), it is closed and the admin told.
	 *
	 * @param Resource $addon Add-on.
	 */
	public static function after_parent_deleted( Resource $addon ): void {
		IpPool::release( $addon->id() );
		$addon->close();
		\BeltoftServices\Alerts::admin( $addon, 'The parent was deleted while the IPv4 address subscription may still bill. Check subscription #' . (int) ( $addon->license()['subscription_id'] ?? 0 ) . '.' );
	}

	/**
	 * Why an address cannot be removed now, or null: the main address of a server is the way out while extra addresses are left, and
	 * the parent must not be changing.
	 *
	 * @param Resource $addon Add-on.
	 */
	public static function removal_blocker( Resource $addon ): ?string {
		$parent = self::parent_of( $addon );

		if ( null !== $parent && 'main' === $addon->get( self::ROLE ) && ! self::is_db( $parent ) && array() !== self::extra_addresses( $parent ) ) {
			return __( 'Remove the extra IPv4 addresses first. The first address is the main address of the server.', 'beltoft-services' );
		}

		if ( null !== $parent && '' !== Ipv4Server::working( $parent ) ) {
			return __( 'An IPv4 address is being changed. Please try again when it is done.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * Does the server count as a VPS (not a database)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function is_server( Resource $r ): bool {
		return ProxmoxDriver::ID === $r->get( 'driver' ) && Profiles::for_resource( $r ) instanceof VpsProfile;
	}
}

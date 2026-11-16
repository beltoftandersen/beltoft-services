<?php
/**
 * Test set-up of private networks without a resource: hold a customer's network, give a resource an address, and put an
 * empty network into quarantine. Production reserves through PrivateNets::reserve() and frees through release_and_retire();
 * these use the same private steps (hold_locked, address_locked) under the same pool lock.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Network\PrivateNets;
use BeltoftServices\Proxmox\Vm\Schema;

/**
 * Private network fixture.
 */
final class PrivateNetsHarness {

	/**
	 * Calls a private step of PrivateNets.
	 *
	 * @param string $name Method.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private static function step( string $name, ...$args ) {
		$m = new \ReflectionMethod( PrivateNets::class, $name );
		$m->setAccessible( true );

		return $m->invoke( null, ...$args );
	}

	/**
	 * The pool lock around one step.
	 *
	 * @param callable $fn Step.
	 * @return mixed
	 */
	private static function locked( callable $fn ) {
		list( $locked, $result ) = Locks::with( Locks::name( 'privnet', 0 ), Locks::allocation_wait(), $fn );

		if ( ! $locked ) {
			throw new \RuntimeException( 'The private network pool is busy.' );
		}

		return $result;
	}

	/**
	 * The customer's network, or the lowest free one.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>|null
	 */
	public static function hold( int $user_id ): ?array {
		if ( $user_id < 1 || ! self::step( 'db_ready' ) ) {
			return null;
		}

		return self::locked( static fn() => self::step( 'hold_locked', $user_id ) );
	}

	/**
	 * An address of the customer's default network for a resource of theirs.
	 *
	 * @param int $user_id     Customer.
	 * @param int $resource_id Resource.
	 */
	public static function address( int $user_id, int $resource_id ): ?string {
		if ( ! self::step( 'valid_resource_owner', $user_id, $resource_id ) || ! self::step( 'db_ready' ) ) {
			return null;
		}

		return self::locked(
			static function () use ( $user_id, $resource_id ) {
				$net = PrivateNets::for_user( $user_id );

				if ( null === $net || ! in_array( $net['state'], array( 'held', 'held_return' ), true ) ) {
					return null;
				}

				return self::step( 'address_locked', $net, $resource_id );
			}
		);
	}

	/**
	 * Puts the customer's empty held network into quarantine; false while an address is on it.
	 *
	 * @param int $user_id Customer.
	 */
	public static function retire( int $user_id ): bool {
		global $wpdb;

		return (bool) self::locked(
			static function () use ( $wpdb, $user_id ) {
				$net = PrivateNets::for_user( $user_id );

				if ( null === $net || ! in_array( $net['state'], array( 'held', 'held_return' ), true ) ) {
					return false;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE net_id = %d', Schema::tables()['private_ips'], $net['id'] ) );

				if ( 0 !== (int) $count ) {
					return false;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				return 1 === $wpdb->update( Schema::tables()['private_nets'], array( 'state' => 'quarantine', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $net['id'], 'state' => $net['state'] ) );
			}
		);
	}
}

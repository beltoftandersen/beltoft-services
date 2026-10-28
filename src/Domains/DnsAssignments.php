<?php
/**
 * Which customer holds a domain name on our name servers. One holder at a time.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Locks;

defined( 'ABSPATH' ) || exit;

/**
 * The primary key on the domain is the real guard; the named lock only orders the checks.
 */
final class DnsAssignments {

	/**
	 * The assignments table.
	 */
	private static function table(): string {
		return DnsSchema::tables()['assignments'];
	}

	/**
	 * Current time as a UTC DATETIME string, optionally shifted.
	 *
	 * @param int $offset Seconds to add.
	 */
	private static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * The generic refusal that never says who holds the domain.
	 */
	private static function taken(): \WP_Error {
		return new \WP_Error( 'domain_taken', __( 'This domain cannot be added here. Contact support.', 'beltoft-services' ) );
	}

	/**
	 * The row for a domain.
	 *
	 * @param string $domain Domain.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $domain ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, always fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE domain = %s', self::table(), strtolower( $domain ) ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reserves a domain for a customer while they check out.
	 *
	 * @param int    $user_id  Customer.
	 * @param int    $claim_id Their verified claim.
	 * @param string $domain   Domain.
	 * @param int    $seconds  How long the reservation lasts.
	 * @return true|\WP_Error
	 */
	public static function reserve( int $user_id, int $claim_id, string $domain, int $seconds = 2 * HOUR_IN_SECONDS ) {
		$domain = strtolower( $domain );

		list( $locked, $result ) = Locks::with(
			Locks::domain( $domain ),
			5,
			static function () use ( $user_id, $claim_id, $domain, $seconds ) {
				return self::reserve_locked( $user_id, $claim_id, $domain, $seconds );
			}
		);

		return $locked ? $result : new \WP_Error( 'busy', __( 'Please try again in a moment.', 'beltoft-services' ) );
	}

	/**
	 * The reservation decision, with the domain lock held.
	 *
	 * @param int    $user_id  Customer.
	 * @param int    $claim_id Claim.
	 * @param string $domain   Domain.
	 * @param int    $seconds  Reservation length.
	 * @return true|\WP_Error
	 */
	private static function reserve_locked( int $user_id, int $claim_id, string $domain, int $seconds ) {
		global $wpdb;

		$row   = self::get( $domain );
		$until = self::now( $seconds );
		$now   = self::now();

		if ( null === $row ) {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$ok = $wpdb->insert(
				self::table(),
				array(
					'domain'         => $domain,
					'user_id'        => $user_id,
					'claim_id'       => $claim_id,
					'status'         => 'reserved',
					'reserved_until' => $until,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
			$error = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $suppress );

			if ( $ok ) {
				return true;
			}

			// A duplicate key means another request won: a refusal. Anything else is a real failure.
			if ( false !== stripos( $error, 'Duplicate entry' ) ) {
				return self::taken();
			}

			return new \WP_Error( 'db_error', __( 'The domain could not be reserved. Try again.', 'beltoft-services' ) );
		}

		if ( (int) $row['user_id'] === $user_id ) {
			if ( 'reserved' !== $row['status'] ) {
				return new \WP_Error( 'already_yours', __( 'This domain is already added to your account.', 'beltoft-services' ) );
			}

			// A row tied to an order is never re-pointed: that order still needs its proof.
			if ( ! empty( $row['order_id'] ) ) {
				return new \WP_Error( 'order_open', __( 'Finish or cancel your open order for this domain first.', 'beltoft-services' ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET claim_id = %d, reserved_until = %s, updated_at = %s WHERE domain = %s AND user_id = %d AND status = 'reserved' AND ( order_id IS NULL OR order_id = 0 )",
					self::table(),
					$claim_id,
					$until,
					$now,
					$domain,
					$user_id
				)
			);

			if ( false !== $changed && ( (int) $changed > 0 || self::refreshed( $domain, $user_id, $claim_id ) ) ) {
				return true;
			}

			return new \WP_Error( 'order_open', __( 'Finish or cancel your open order for this domain first.', 'beltoft-services' ) );
		}

		if ( 'reserved' === $row['status'] && null !== $row['reserved_until'] && $row['reserved_until'] < $now ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET user_id = %d, claim_id = %d, reserved_until = %s, order_id = NULL, order_item_id = NULL, updated_at = %s WHERE domain = %s AND status = 'reserved' AND user_id = %d AND reserved_until = %s",
					self::table(),
					$user_id,
					$claim_id,
					$until,
					$now,
					$domain,
					(int) $row['user_id'],
					$row['reserved_until']
				)
			);

			return 1 === (int) $changed ? true : self::taken();
		}

		return self::taken();
	}

	/**
	 * A refresh that changed nothing (same values in the same second) still counts when the row is as asked.
	 *
	 * @param string $domain   Domain.
	 * @param int    $user_id  Customer.
	 * @param int    $claim_id Claim.
	 */
	private static function refreshed( string $domain, int $user_id, int $claim_id ): bool {
		$row = self::get( $domain );

		return $row && 'reserved' === $row['status'] && (int) $row['user_id'] === $user_id && (int) $row['claim_id'] === $claim_id && empty( $row['order_id'] );
	}

	/**
	 * Ties the customer's reservation to their order, and keeps it for a while longer.
	 *
	 * @param string $domain        Domain.
	 * @param int    $user_id       Customer.
	 * @param int    $order_id      Order.
	 * @param int    $order_item_id Order item.
	 * @param int    $seconds       New reservation length.
	 */
	public static function bind_order( string $domain, int $user_id, int $order_id, int $order_item_id, int $seconds = 7 * DAY_IN_SECONDS ): bool {
		global $wpdb;

		// A row already tied to another order is never moved: one proof, one order.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET order_id = %d, order_item_id = %d, reserved_until = %s, updated_at = %s WHERE domain = %s AND user_id = %d AND status = 'reserved' AND ( order_id IS NULL OR order_id = 0 OR order_id = %d )",
				self::table(),
				$order_id,
				$order_item_id,
				self::now( $seconds ),
				self::now(),
				strtolower( $domain ),
				$user_id,
				$order_id
			)
		);

		$row = self::get( $domain );

		return null !== $row && 'reserved' === $row['status'] && (int) $row['user_id'] === $user_id && (int) $row['order_id'] === $order_id;
	}

	/**
	 * Reserved becomes active when the service exists. Safe to repeat.
	 *
	 * @param string $domain      Domain.
	 * @param int    $user_id     Customer.
	 * @param int    $license_id  License.
	 * @param int    $resource_id Resource.
	 */
	public static function activate( string $domain, int $user_id, int $license_id, int $resource_id ): bool {
		global $wpdb;

		$row = self::get( $domain );

		if ( null === $row || (int) $row['user_id'] !== $user_id ) {
			return false;
		}

		if ( 'active' === $row['status'] ) {
			return (int) $row['resource_id'] === $resource_id;
		}

		if ( 'reserved' !== $row['status'] ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'active', license_id = %d, resource_id = %d, reserved_until = NULL, updated_at = %s WHERE domain = %s AND user_id = %d AND status = 'reserved'",
				self::table(),
				$license_id,
				$resource_id,
				self::now(),
				strtolower( $domain ),
				$user_id
			)
		);

		return 1 === (int) $changed;
	}

	/**
	 * Marks a held domain as being released.
	 *
	 * @param string $domain Domain.
	 */
	public static function release_pending( string $domain ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'release_pending', updated_at = %s WHERE domain = %s AND status = 'active'",
				self::table(),
				self::now(),
				strtolower( $domain )
			)
		);

		return 1 === (int) $changed;
	}

	/**
	 * Marks a resource's own row as being released: only when the row is active and belongs to this
	 * resource, so a row handed to a registered domain is left alone.
	 *
	 * @param \BeltoftServices\Resource $r Resource.
	 */
	public static function release_pending_for( \BeltoftServices\Resource $r ): bool {
		$row = self::get( (string) $r->get( 'domain' ) );

		if ( null === $row || 'active' !== $row['status'] || (int) $row['resource_id'] !== $r->id() || (int) $row['user_id'] !== (int) ( $r->license()['user_id'] ?? 0 ) ) {
			return false;
		}

		return self::release_pending( (string) $row['domain'] );
	}

	/**
	 * Marks the first clear check of a release_pending row. For these rows reserved_until holds
	 * the time of the first clear check (UTC), or NULL when the parent last listed our name
	 * servers. updated_at is left alone: it says since when the row waits.
	 *
	 * @param string      $domain Domain.
	 * @param string|null $when   UTC DATETIME, or null to clear the mark.
	 */
	public static function mark_clear( string $domain, ?string $when ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; wpdb writes a null as NULL.
		$wpdb->update( self::table(), array( 'reserved_until' => $when ), array( 'domain' => strtolower( $domain ), 'status' => 'release_pending' ) );

		$row = self::get( $domain );

		return null !== $row && 'release_pending' === $row['status'] && ( $row['reserved_until'] ?? null ) === $when;
	}

	/**
	 * Hands an active row to another service of the same customer (a registered domain takes over
	 * a DNS-only service). The row stays active. Safe to repeat.
	 *
	 * @param string $domain      Domain.
	 * @param int    $user_id     Customer.
	 * @param int    $license_id  New license.
	 * @param int    $resource_id New resource.
	 */
	public static function move_to( string $domain, int $user_id, int $license_id, int $resource_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET license_id = %d, resource_id = %d, updated_at = %s WHERE domain = %s AND user_id = %d AND status = 'active'",
				self::table(),
				$license_id,
				$resource_id,
				self::now(),
				strtolower( $domain ),
				$user_id
			)
		);

		$row = self::get( $domain );

		return null !== $row && 'active' === $row['status'] && (int) $row['user_id'] === $user_id && (int) $row['resource_id'] === $resource_id;
	}

	/**
	 * Staff override: the domain becomes a reservation for this customer, replacing any row.
	 *
	 * @param string $domain  Domain.
	 * @param int    $user_id Customer.
	 * @param int    $seconds How long the reservation lasts.
	 */
	public static function assign_reserved( string $domain, int $user_id, int $seconds = 7 * DAY_IN_SECONDS ) {
		global $wpdb;

		$domain = strtolower( $domain );
		$now    = self::now();
		$row    = self::get( $domain );

		if ( null !== $row ) {
			$expired = 'reserved' === $row['status'] && ! empty( $row['reserved_until'] ) && $row['reserved_until'] < $now;

			if ( ! $expired && (int) $row['user_id'] !== $user_id ) {
				return new \WP_Error( 'assignment_held', __( 'The domain is held by another assignment. Release it first.', 'beltoft-services' ) );
			}

			if ( ! $expired && in_array( $row['status'], array( 'active', 'release_pending' ), true ) ) {
				return new \WP_Error( 'assignment_held', __( 'The domain already has an active or releasing assignment for this customer. Nothing changed.', 'beltoft-services' ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table; REPLACE swaps the one row by its primary key.
		$ok = false !== $wpdb->replace(
			self::table(),
			array(
				'domain'         => $domain,
				'user_id'        => $user_id,
				'claim_id'       => null,
				'status'         => 'reserved',
				'reserved_until' => self::now( $seconds ),
				'order_id'       => null,
				'order_item_id'  => null,
				'license_id'     => null,
				'resource_id'    => null,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		return $ok ? true : new \WP_Error( 'db_error', __( 'The assignment could not be written.', 'beltoft-services' ) );
	}

	/**
	 * Frees a domain.
	 *
	 * @param string $domain Domain.
	 */
	public static function release( string $domain ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return 1 === (int) $wpdb->delete( self::table(), array( 'domain' => strtolower( $domain ) ) );
	}

	/**
	 * Frees the reservations bound to an order that did not complete.
	 *
	 * @param int $order_id Order.
	 * @return int Rows deleted.
	 */
	public static function release_for_order( int $order_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (int) $wpdb->delete(
			self::table(),
			array(
				'order_id' => $order_id,
				'status'   => 'reserved',
			)
		);
	}

	/**
	 * Deletes reservations past their time. Active and release_pending rows are never touched.
	 *
	 * @return int Rows deleted.
	 */
	public static function expire_reservations(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status = 'reserved' AND reserved_until IS NOT NULL AND reserved_until < %s",
				self::table(),
				self::now()
			)
		);
	}

	/**
	 * Rows with a status.
	 *
	 * @param string $status reserved, active or release_pending.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_status( string $status ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, always fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY domain ASC', self::table(), $status ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many domains are held (active or being released).
	 */
	public static function open_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, always fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ( 'active', 'release_pending' )", self::table() ) );
	}
}

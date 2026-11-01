<?php
/**
 * A customer's account credit balance: the append-only ledger and its one writer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Every change of a balance is one row of {prefix}bs_credit: signed integer cents, the balance after it, and
 * a reference that says why. The unique key (kind, ref_type, ref_id) makes each write idempotent: the same
 * reference never counts twice, so a payment webhook that arrives again or a job that runs twice is harmless.
 * Writes hold the customer's named lock (Locks::balance()), read the balance from the ledger and keep the
 * cached user meta beltoft_services_credit_cents in step. Amounts are in the store currency, as paid: a top-up carries no VAT. Cents
 * only: no float is ever stored.
 */
final class Balance {

	public const META = 'beltoft_services_credit_cents';

	public const TOPUP      = 'topup';
	public const INVOICE    = 'invoice';
	public const PROMO      = 'promo';
	public const REFUND     = 'refund';
	public const CHARGEBACK = 'chargeback';
	public const ADJUST     = 'adjust';

	/**
	 * Table name.
	 */
	public static function table(): string {
		return Schema::tables()['credit'];
	}

	/**
	 * Whole cents of a money amount, rounded half up (0.285 is 29 cents).
	 *
	 * @param mixed $amount Amount in the major unit: a number or a numeric string.
	 */
	public static function cents( $amount ): int {
		return (int) round( round( (float) $amount, 2 ) * 100 );
	}

	/**
	 * The balance in cents, from the cached meta (the ledger is rebuilt into it on a write).
	 *
	 * @param int $user_id Customer.
	 */
	public static function get( int $user_id ): int {
		$cached = get_user_meta( $user_id, self::META, true );

		return '' === $cached ? self::recount( $user_id ) : (int) $cached;
	}

	/**
	 * The balance as the ledger says, and the cache set to it.
	 *
	 * @param int $user_id Customer.
	 */
	public static function recount( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$sum = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( SUM( amount_cents ), 0 ) FROM %i WHERE user_id = %d', self::table(), $user_id ) );
		update_user_meta( $user_id, self::META, $sum );

		return $sum;
	}

	/**
	 * Adds credit. A positive amount; the same reference is written once.
	 *
	 * @param int    $user_id  Customer.
	 * @param int    $cents    Cents, more than 0.
	 * @param string $kind     topup, refund-reversal as adjust, etc.
	 * @param string $ref_type What the reference points at.
	 * @param string $ref_id   Which one.
	 * @param string $note     Short note.
	 * @return bool Whether a row was written (false: already written, or the lock could not be had).
	 */
	public static function credit( int $user_id, int $cents, string $kind, string $ref_type, string $ref_id, string $note = '' ): bool {
		return $cents > 0 && self::write( $user_id, $cents, $kind, $ref_type, $ref_id, $note );
	}

	/**
	 * Takes credit away. A positive amount is subtracted. The balance may go below 0.
	 *
	 * @param int    $user_id  Customer.
	 * @param int    $cents    Cents, more than 0.
	 * @param string $kind     invoice, refund, chargeback or adjust.
	 * @param string $ref_type What the reference points at.
	 * @param string $ref_id   Which one.
	 * @param string $note     Short note.
	 * @return bool Whether a row was written.
	 */
	public static function debit( int $user_id, int $cents, string $kind, string $ref_type, string $ref_id, string $note = '' ): bool {
		return $cents > 0 && self::write( $user_id, -$cents, $kind, $ref_type, $ref_id, $note );
	}

	/**
	 * Runs work while holding the customer's balance lock, so a read, a decision and several writes stay together.
	 * The lock is re-entrant: credit() and debit() inside take it again.
	 *
	 * @param int      $user_id  Customer.
	 * @param callable $callback Work.
	 * @return array{0: bool, 1: mixed} Whether the lock was taken, and the result.
	 */
	public static function locked( int $user_id, callable $callback ): array {
		return Locks::with( Locks::balance( $user_id ), Locks::balance_wait(), $callback );
	}

	/**
	 * Has this reference been written?
	 *
	 * @param string $kind     Kind.
	 * @param string $ref_type Reference type.
	 * @param string $ref_id   Reference id.
	 */
	public static function exists( string $kind, string $ref_type, string $ref_id ): bool {
		return null !== self::find( $kind, $ref_type, $ref_id );
	}

	/**
	 * The row of a reference.
	 *
	 * @param string $kind     Kind.
	 * @param string $ref_type Reference type.
	 * @param string $ref_id   Reference id.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $kind, string $ref_type, string $ref_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE kind = %s AND ref_type = %s AND ref_id = %s', self::table(), $kind, $ref_type, $ref_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The sum of the rows of one kind whose reference id starts with a prefix (signed cents).
	 *
	 * @param string $kind     Kind.
	 * @param string $ref_type Reference type.
	 * @param string $prefix   Start of the reference id.
	 */
	public static function sum_prefix( string $kind, string $ref_type, string $prefix ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( SUM( amount_cents ), 0 ) FROM %i WHERE kind = %s AND ref_type = %s AND ref_id LIKE %s', self::table(), $kind, $ref_type, $wpdb->esc_like( $prefix ) . '%' ) );
	}

	/**
	 * The newest rows of a customer.
	 *
	 * @param int $user_id Customer.
	 * @param int $limit   Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function history( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d', self::table(), $user_id, max( 1, $limit ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Writes one row under the lock.
	 *
	 * @param int    $user_id  Customer.
	 * @param int    $signed   Signed cents.
	 * @param string $kind     Kind.
	 * @param string $ref_type Reference type.
	 * @param string $ref_id   Reference id.
	 * @param string $note     Note.
	 */
	private static function write( int $user_id, int $signed, string $kind, string $ref_type, string $ref_id, string $note ): bool {
		global $wpdb;

		if ( $user_id < 1 || '' === $kind || '' === $ref_id ) {
			return false;
		}

		list( $locked, $written ) = self::locked(
			$user_id,
			static function () use ( $wpdb, $user_id, $signed, $kind, $ref_type, $ref_id, $note ): bool {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
				$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( SUM( amount_cents ), 0 ) FROM %i WHERE user_id = %d', self::table(), $user_id ) );
				$done   = $wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (user_id, kind, amount_cents, balance_after_cents, ref_type, ref_id, note, created) VALUES (%d, %s, %d, %d, %s, %s, %s, %s)',
						self::table(),
						$user_id,
						$kind,
						$signed,
						$before + $signed,
						$ref_type,
						$ref_id,
						substr( $note, 0, 255 ),
						gmdate( 'Y-m-d H:i:s' )
					)
				);
				// phpcs:enable

				update_user_meta( $user_id, self::META, 1 === (int) $done ? $before + $signed : $before );

				return 1 === (int) $done;
			}
		);

		return $locked && true === $written;
	}
}

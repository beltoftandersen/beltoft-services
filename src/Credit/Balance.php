<?php
/**
 * A customer's account credit balance: the append-only ledger and its one writer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Vm\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Every change of a balance is one row of {prefix}bs_credit: signed integer cents, the balance after it, and
 * a reference that says why. The unique key (kind, ref_type, ref_id) makes each write idempotent: the same
 * reference never counts twice, so a payment webhook that arrives again or a job that runs twice is harmless.
 * Writes hold the customer's named lock (Locks::balance()), read the balance from the ledger and keep the
 * cached user meta beltoft_services_credit_cents in step. Amounts are in the customer's credit currency, as paid: a top-up carries no VAT.
 * A customer's balance has ONE currency, the one of the first row (an empty value is the shop's currency): a write in another currency is
 * refused and the admin is told once, also at a zero balance, so the running balance column always means one currency. Cents only: no float is ever stored.
 */
final class Balance {

	public const META = 'beltoft_services_credit_cents';

	/** User meta: the currency of the customer's credit (cache of the newest ledger row). */
	public const META_CURRENCY = 'beltoft_services_credit_currency';

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
	 * Hooks.
	 */
	public static function register(): void {
		// The customer's own credit currency answers before the usage account's (Usage registers at 20).
		add_filter( 'beltoft_services_user_currency', array( __CLASS__, 'user_currency' ), 10, 2 );
	}

	/**
	 * Answer for the filter beltoft_services_user_currency: the currency of the customer's credit, when they have any and nothing earlier answered.
	 *
	 * @param mixed $code    Answer so far.
	 * @param mixed $user_id Customer.
	 * @return mixed
	 */
	public static function user_currency( $code, $user_id = 0 ) {
		if ( is_string( $code ) && '' !== $code ) {
			return $code;
		}

		$own = self::currency( (int) $user_id );

		return '' !== $own ? $own : $code;
	}

	/**
	 * The currency of a customer's credit: that of the newest ledger row (an empty value is the shop's currency), or '' when the
	 * customer has no rows yet.
	 *
	 * @param int $user_id Customer.
	 */
	public static function currency( int $user_id ): string {
		$cached = (string) get_user_meta( $user_id, self::META_CURRENCY, true );

		if ( '' !== $cached ) {
			return $cached;
		}

		$have = self::row_currency( $user_id );

		if ( null === $have ) {
			return '';
		}

		update_user_meta( $user_id, self::META_CURRENCY, $have );

		return $have;
	}

	/**
	 * The credit currency, else the currency the customer would be priced in (Currency::of_user()): what a page about their credit says.
	 *
	 * @param int $user_id Customer.
	 */
	public static function currency_or_user( int $user_id ): string {
		$own = self::currency( $user_id );

		return '' !== $own ? $own : Currency::of_user( $user_id );
	}

	/**
	 * The balance in a currency: the balance when the customer's credit is in that currency, else 0. A balance in another currency is
	 * never counted, never added to, and never applied.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $currency Currency code, '' for the shop's.
	 */
	public static function get_in( int $user_id, string $currency ): int {
		$want = Currency::clean( $currency );
		$want = '' !== $want ? $want : Currency::base();
		$own  = self::currency( $user_id );

		return '' !== $own && $own === $want ? self::get( $user_id ) : 0;
	}

	/**
	 * The currency of the newest ledger row of a customer: null with no rows.
	 *
	 * @param int $user_id Customer.
	 */
	private static function row_currency( int $user_id ): ?string {
		global $wpdb;

		if ( ! Schema::has_currency() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			return null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d LIMIT 1', self::table(), $user_id ) ) ? null : Currency::base();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT currency FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 1', self::table(), $user_id ), ARRAY_A );

		return is_array( $row ) ? Currency::of_row( $row ) : null;
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
		delete_user_meta( $user_id, self::META_CURRENCY );

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
	 * @param string $currency Currency of the amount, '' for the shop's. Refused when the customer's credit is in another currency.
	 * @return bool Whether a row was written (false: already written, the lock could not be had, or another currency).
	 */
	public static function credit( int $user_id, int $cents, string $kind, string $ref_type, string $ref_id, string $note = '', string $currency = '' ): bool {
		return $cents > 0 && self::write( $user_id, $cents, $kind, $ref_type, $ref_id, $note, $currency );
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
	 * @param string $currency Currency of the amount, '' for the shop's. Refused when the customer's credit is in another currency.
	 * @return bool Whether a row was written.
	 */
	public static function debit( int $user_id, int $cents, string $kind, string $ref_type, string $ref_id, string $note = '', string $currency = '' ): bool {
		return $cents > 0 && self::write( $user_id, -$cents, $kind, $ref_type, $ref_id, $note, $currency );
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
	private static function write( int $user_id, int $signed, string $kind, string $ref_type, string $ref_id, string $note, string $currency = '' ): bool {
		global $wpdb;

		if ( $user_id < 1 || '' === $kind || '' === $ref_id ) {
			return false;
		}

		// A value that is not a currency code writes nothing; '' is the shop's currency.
		$target = Currency::clean( $currency );

		if ( '' === $target && '' !== $currency ) {
			return false;
		}

		$target = '' !== $target ? $target : Currency::base();

		list( $locked, $written ) = self::locked(
			$user_id,
			static function () use ( $wpdb, $user_id, $signed, $kind, $ref_type, $ref_id, $note, $target ): bool {
				// One currency per customer, also at a zero balance: another currency is refused and told once.
				$have = self::row_currency( $user_id );

				if ( ( null !== $have && $have !== $target ) || ( ! Schema::has_currency() && Currency::base() !== $target ) ) {
					Alerts::notify(
						/* translators: %d: customer id */
						sprintf( __( 'Credit in two currencies for customer %d', 'beltoft-services' ), $user_id ),
						array( sprintf( 'A credit change of %1$d cents in %2$s was refused for customer %3$d: their credit is in %4$s (kind %5$s, reference %6$s %7$s). Nothing was written.', $signed, $target, $user_id, $have ?? Currency::base(), $kind, $ref_type, $ref_id ) ),
						null,
						'beltoft_services_credit_mixed_' . $user_id
					);

					return false;
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
				$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( SUM( amount_cents ), 0 ) FROM %i WHERE user_id = %d', self::table(), $user_id ) );
				$has    = Schema::has_currency();
				$column = $has ? ', currency' : '';
				$value  = $has ? ', %s' : '';
				$extra  = $has ? array( $target ) : array();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the column list is fixed text.
				$done = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO %i (user_id, kind, amount_cents, balance_after_cents, ref_type, ref_id, note, created$column) VALUES (%d, %s, %d, %d, %s, %s, %s, %s$value)",
						self::table(),
						$user_id,
						$kind,
						$signed,
						$before + $signed,
						$ref_type,
						$ref_id,
						substr( $note, 0, 255 ),
						gmdate( 'Y-m-d H:i:s' ),
						...$extra
					)
				);
				// phpcs:enable

				update_user_meta( $user_id, self::META, 1 === (int) $done ? $before + $signed : $before );

				if ( 1 === (int) $done ) {
					update_user_meta( $user_id, self::META_CURRENCY, $target );
				}

				return 1 === (int) $done;
			}
		);

		return $locked && true === $written;
	}
}

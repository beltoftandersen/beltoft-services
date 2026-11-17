<?php
/**
 * The free intro of a counted service: the first days after its first event cost nothing.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Counted;

use BeltoftServices\Alerts;
use BeltoftServices\IntroCoupon;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The window starts when the plugin receives the service's first event (never the order's own date) and lasts the setting
 * `counted_intro_days` (7), or the variation's own `_beltoft_services_intro_days`. Events received inside it are free.
 *
 * One intro per customer, per store and per database. At the first event four keys are looked up: the customer's user id and
 * normalised email (the licences the customer holds of the product besides this one, the same lookup as IntroCoupon::held(),
 * and the claims table), the store and the database of the first event. A key claimed by another service ends the matter:
 * no intro, every event bills. Otherwise the keys are claimed. Claims are hashes, are never deleted and survive the service.
 * The keys stop the careless repeat (buying again, a second service for the same shop), not a deliberate one.
 *
 * Resource meta: `intro_started_at`, `intro_ends_at` (unix time) or `intro_denied` (the reason, a short word).
 */
final class Intro {

	public const STARTED = 'intro_started_at';
	public const ENDS    = 'intro_ends_at';
	public const DENIED  = 'intro_denied';

	public const KINDS = array( 'user', 'email', 'store', 'odoo' );

	/**
	 * The table of claims.
	 */
	public static function table(): string {
		return Schema::tables()['intro_claims'];
	}

	/**
	 * Days of intro for a resource: the variation's meta, else the setting. 0 means none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function days( Resource $r ): int {
		$variation = wc_get_product( (int) ( $r->license()['variation_id'] ?? 0 ) );
		$own       = $variation instanceof \WC_Product ? (string) $variation->get_meta( '_beltoft_services_intro_days' ) : '';
		$days      = '' !== $own && is_numeric( $own ) ? (int) $own : (int) Settings::get( 'counted_intro_days' );

		/**
		 * Filters the days of free intro of a counted service.
		 *
		 * @param int      $days Days, 0 for none.
		 * @param Resource $r    Resource.
		 */
		return max( 0, (int) apply_filters( 'beltoft_services_counted_intro_days', $days, $r ) );
	}

	/**
	 * The hash a claim is stored under.
	 *
	 * @param string $kind  One of KINDS.
	 * @param string $value The user id, normalised email, store key or database key.
	 */
	public static function hash( string $kind, string $value ): string {
		return hash_hmac( 'sha256', $kind . '|' . strtolower( trim( $value ) ), wp_salt( 'auth' ) );
	}

	/**
	 * The keys of a service at its first event.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $event First event (store_key, odoo_key).
	 * @return array<string, string> kind => value, empty values left out.
	 */
	public static function keys( Resource $r, array $event ): array {
		$license = $r->license();
		$keys    = array(
			'user'  => (int) ( $license['user_id'] ?? 0 ) > 0 ? (string) (int) $license['user_id'] : '',
			'email' => IntroCoupon::normalise_email( sanitize_email( (string) ( $license['email'] ?? '' ) ) ),
			'store' => (string) ( $event['store_key'] ?? '' ),
			'odoo'  => (string) ( $event['odoo_key'] ?? '' ),
		);

		return array_filter( $keys, static fn( $v ) => '' !== $v );
	}

	/**
	 * Who holds a claim of a kind: the resource id, or 0 when nobody does.
	 *
	 * @param string $kind  Kind.
	 * @param string $value Value.
	 */
	public static function claimed_by( string $kind, string $value ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT resource_id FROM %i WHERE kind = %s AND key_hash = %s', self::table(), $kind, self::hash( $kind, $value ) ) );
	}

	/**
	 * Writes a claim; one that exists is left as it is.
	 *
	 * @param string $kind        Kind.
	 * @param string $value       Value.
	 * @param int    $resource_id Resource.
	 * @param int    $now         Unix time.
	 */
	private static function claim( string $kind, string $value, int $resource_id, int $now ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (kind, key_hash, resource_id, claimed_at) VALUES (%s, %s, %d, %s)', self::table(), $kind, self::hash( $kind, $value ), $resource_id, gmdate( 'Y-m-d H:i:s', $now ) ) );
	}

	/**
	 * Decides and records the intro of a resource at its first event. Does nothing once decided.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $event The first event.
	 * @param int                  $now   Unix time of receipt.
	 */
	public static function decide( Resource $r, array $event, int $now ): void {
		if ( null !== $r->get( self::STARTED ) || '' !== (string) $r->get( self::DENIED ) ) {
			return;
		}

		$days = self::days( $r );

		if ( $days < 1 ) {
			$r->set( array( self::DENIED => 'off' ) );
			return;
		}

		$license = $r->license();
		$keys    = self::keys( $r, $event );
		$reason  = '';

		// Another licence of this product held by the customer (this one is one of the holdings).
		if ( IntroCoupon::holdings( (int) ( $license['product_id'] ?? 0 ), (int) ( $license['user_id'] ?? 0 ), (string) ( $license['email'] ?? '' ) ) > 1 ) {
			$reason = 'customer';
		}

		foreach ( '' === $reason ? $keys : array() as $kind => $value ) {
			$holder = self::claimed_by( $kind, $value );

			if ( $holder > 0 && $holder !== $r->id() ) {
				$reason = $kind;
				break;
			}
		}

		if ( '' !== $reason ) {
			$r->set( array( self::DENIED => $reason ) );
			return;
		}

		foreach ( $keys as $kind => $value ) {
			self::claim( $kind, $value, $r->id(), $now );
		}

		$r->set(
			array(
				self::STARTED => $now,
				self::ENDS    => $now + $days * DAY_IN_SECONDS,
			)
		);
	}

	/**
	 * Is the intro of a resource running at a time?
	 *
	 * @param Resource $r   Resource.
	 * @param int      $now Unix time.
	 */
	public static function active( Resource $r, int $now ): bool {
		$ends = $r->get( self::ENDS );

		return null !== $ends && $now >= (int) $r->get( self::STARTED ) && $now < (int) $ends;
	}

	/**
	 * The intro of a resource for the customer views: null when it never started (and may still), or has been refused.
	 *
	 * @param Resource $r   Resource.
	 * @param int      $now Unix time.
	 * @return array{state: string, started: int, ends: int, days_left: int}
	 */
	public static function status( Resource $r, int $now ): array {
		$started = $r->get( self::STARTED );
		$ends    = $r->get( self::ENDS );

		if ( null === $started || null === $ends ) {
			return array(
				'state'     => '' !== (string) $r->get( self::DENIED ) ? 'none' : 'waiting',
				'started'   => 0,
				'ends'      => 0,
				'days_left' => 0,
			);
		}

		return array(
			'state'     => $now < (int) $ends ? 'running' : 'ended',
			'started'   => (int) $started,
			'ends'      => (int) $ends,
			'days_left' => $now < (int) $ends ? (int) ceil( ( (int) $ends - $now ) / DAY_IN_SECONDS ) : 0,
		);
	}

	/**
	 * Tells the admin once when a service's free orders pass the limit (setting counted_intro_alert).
	 *
	 * @param Resource $r Resource.
	 */
	public static function check_limit( Resource $r ): void {
		$limit = (int) Settings::get( 'counted_intro_alert' );

		if ( $limit < 1 || Store::count( $r->id(), Store::FREE ) <= $limit ) {
			return;
		}

		Alerts::notify(
			/* translators: %d: resource id */
			sprintf( __( 'Many free orders for service %d', 'beltoft-services' ), $r->id() ),
			array( sprintf( 'Service %1$d has more than %2$d orders in its free intro. It may be abused; staff can end the intro early by setting its intro_ends_at.', $r->id(), $limit ) ),
			$r,
			'beltoft_services_intro_many_' . $r->id()
		);
	}
}

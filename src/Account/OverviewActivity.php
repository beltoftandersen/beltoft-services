<?php
/**
 * The recent activity of the account Overview, read from the license history.
 *
 * @package BeltoftServices\Account
 */

namespace BeltoftServices\Account;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The feed of what the customer did or is told about, in plain words. Only history texts the plugin wrote and
 * that are listed in MESSAGE_PATTERNS can become a sentence. Overview::describe() delegates to describe().
 */
final class OverviewActivity {

	/**
	 * How many of the newest matching history rows are read to find the feed's items.
	 */
	private const HISTORY_ROWS = 20;

	/**
	 * The history texts that can become a customer sentence, as written by the plugin ("*" is any text). A text
	 * without a "*" must match completely. The rest is never read, so rows that only start alike do not use up
	 * the window.
	 */
	private const MESSAGE_PATTERNS = array(
		'Customer took the snapshot "*"',
		'Snapshot "*" deleted',
		'Server rolled back to the snapshot "*" of *',
		'Server restored from a backup*',
		'Server restored from the backup*',
		'Service provisioned',
		'Service paused',
	);

	/**
	 * Whether a subscription's newest renewal order is a failed one, by subscription id (asked once each).
	 *
	 * @var array<int, bool>
	 */
	private static $newest_failed = array();

	/**
	 * Forgets what was asked.
	 */
	public static function reset(): void {
		self::$newest_failed = array();
	}

	/**
	 * The recent activity, newest first: only what the customer did or is told about, in plain words.
	 *
	 * The history of the services (the license events) is written for the admin; its texts are matched against
	 * a short list of known ones and everything else is left out, so nothing admin-only can show. A backup that
	 * finished comes from the server's own record.
	 *
	 * @param array<int, array<string, mixed>> $licenses      The customer's licenses.
	 * @param array<int, string>               $names         License id => name.
	 * @param array<int, \WC_Subscription>     $subscriptions Subscriptions.
	 * @param array<int, Resource>             $resources     Server resources.
	 * @return array<int, array<string, mixed>>
	 */
	public static function activity( array $licenses, array $names, array $subscriptions, array $resources ): array {
		global $wpdb;

		$ids           = array();
		$subscription_of = array();

		foreach ( $licenses as $license ) {
			if ( isset( $names[ (int) $license['id'] ] ) ) {
				$ids[]                                       = (int) $license['id'];
				$subscription_of[ (int) $license['id'] ] = (int) ( $license['subscription_id'] ?? 0 );
			}
		}

		$items = array();

		if ( $ids && class_exists( \BeltoftLicenses\Install::class ) ) {
			$table = \BeltoftLicenses\Install::tables()['events'];
			$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$conds = array();
			$likes = array();

			foreach ( self::MESSAGE_PATTERNS as $pattern ) {
				if ( false === strpos( $pattern, '*' ) ) {
					$conds[] = 'message = %s';
					$likes[] = $pattern;
					continue;
				}

				$conds[] = 'message LIKE %s';
				$likes[] = implode( '%', array_map( array( $wpdb, 'esc_like' ), explode( '*', $pattern ) ) );
			}

			$like = implode( ' OR ', $conds );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, one query per page; the placeholders are generated.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT license_id, message, created_at FROM %i WHERE type = 'service' AND license_id IN ($in) AND ($like) ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					array_merge( array( $table ), $ids, $likes, array( self::HISTORY_ROWS ) )
				),
				ARRAY_A
			);
			// phpcs:enable

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$license_id = (int) $row['license_id'];
				$held       = false;
				$failed     = false;
				$at         = (int) strtotime( $row['created_at'] . ' UTC' );
				$sub        = $subscription_of[ $license_id ] ?? 0;

				if ( 'Service paused' === $row['message'] && $sub > 0 && isset( $subscriptions[ $sub ] ) && $subscriptions[ $sub ] instanceof \WC_Subscription ) {
					$held   = $subscriptions[ $sub ]->has_status( 'on-hold' );
					$failed = $held && self::newest_renewal_failed( $subscriptions[ $sub ] );
				}

				$text = self::describe( (string) $row['message'], (string) ( $names[ $license_id ] ?? '' ), $held, $failed );

				if ( '' !== $text ) {
					$items[] = array(
						'at'   => $at,
						'text' => $text,
					);
				}

				if ( count( $items ) >= Overview::FEED ) {
					break;
				}
			}
		}

		foreach ( $resources as $id => $resource ) {
			$done = (int) $resource->get( 'backup_ok_at' );

			if ( $done > 0 && isset( $names[ $id ] ) ) {
				$items[] = array(
					'at'   => $done,
					/* translators: %s: server name */
					'text' => sprintf( __( '%s: backup finished', 'beltoft-services' ), $names[ $id ] ),
				);
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['at'] <=> $a['at'];
			}
		);

		$items = array_slice( $items, 0, Overview::FEED );

		foreach ( $items as $i => $item ) {
			$items[ $i ]['label'] = self::when( (int) $item['at'] );
			$items[ $i ]['time']  = gmdate( 'c', (int) $item['at'] );
		}

		return $items;
	}

	/**
	 * Whether the subscription's newest renewal order is a failed one. Asked once per subscription, by loading
	 * only that one order.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private static function newest_renewal_failed( \WC_Subscription $subscription ): bool {
		$id = $subscription->get_id();

		if ( ! isset( self::$newest_failed[ $id ] ) ) {
			$newest                     = $subscription->get_last_order( 'all', 'renewal' );
			self::$newest_failed[ $id ] = $newest instanceof \WC_Order && $newest->has_status( 'failed' );
		}

		return self::$newest_failed[ $id ];
	}

	/**
	 * A history message as a customer sentence, or '' when it is not for customers.
	 *
	 * @param string $message History text.
	 * @param string $name    The service's name.
	 * @param bool   $held    Whether its subscription is on hold.
	 * @param bool   $failed  Whether a failed renewal payment came before the pause.
	 */
	public static function describe( string $message, string $name, bool $held, bool $failed = false ): string {
		if ( '' === $name ) {
			return '';
		}

		if ( 1 === preg_match( '/^Customer took the snapshot "(.*)"$/sD', $message, $m ) ) {
			/* translators: 1: server name, 2: snapshot name */
			return sprintf( __( '%1$s: snapshot "%2$s" taken', 'beltoft-services' ), $name, $m[1] );
		}

		if ( 1 === preg_match( '/^Snapshot "(.*)" deleted$/sD', $message, $m ) ) {
			/* translators: 1: server name, 2: snapshot name */
			return sprintf( __( '%1$s: snapshot "%2$s" deleted', 'beltoft-services' ), $name, $m[1] );
		}

		if ( 1 === preg_match( '/^Server rolled back to the snapshot "(.*)" of /s', $message, $m ) ) {
			/* translators: 1: server name, 2: snapshot name */
			return sprintf( __( '%1$s: went back to snapshot "%2$s"', 'beltoft-services' ), $name, $m[1] );
		}

		if ( 0 === strpos( $message, 'Server restored from a backup' ) || 0 === strpos( $message, 'Server restored from the backup' ) ) {
			/* translators: %s: server name */
			return sprintf( __( '%s: restored from a backup', 'beltoft-services' ), $name );
		}

		if ( 'Service provisioned' === $message ) {
			/* translators: %s: name of the server or service */
			return sprintf( __( '%s: ready', 'beltoft-services' ), $name );
		}

		if ( 'Service paused' === $message && $held ) {
			if ( ! $failed ) {
				/* translators: %s: name of the server or service */
				return sprintf( __( '%s: paused', 'beltoft-services' ), $name );
			}

			/* translators: %s: name of the server or service */
			return sprintf( __( '%s: payment failed, paused', 'beltoft-services' ), $name );
		}

		return '';
	}

	/**
	 * When, short: the time today, "Yesterday", else the date.
	 *
	 * @param int $time Unix time.
	 */
	private static function when( int $time ): string {
		$day = wp_date( 'Y-m-d', $time );

		if ( wp_date( 'Y-m-d' ) === $day ) {
			return wp_date( (string) get_option( 'time_format' ), $time );
		}

		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			return __( 'Yesterday', 'beltoft-services' );
		}

		return wp_date( (string) get_option( 'date_format' ), $time );
	}

}

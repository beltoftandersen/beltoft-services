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
 * The customer's activity log, read from the license history: what they did and what happened to their services, in plain
 * words, newest first, a page at a time.
 *
 * Only history texts that ActivityMap lists can become a sentence (the one door; raw text is never printed). The rows are read
 * by license ids and type, newest first, with an id cursor, and matched in PHP over bounded chunks: a request reads at most
 * SCAN_CAP rows. A run of the same sentence for the same service on the same day is one line with a count. A line names the
 * service by its own name (server name, domain) and, while the service exists, links to its page; an ended service keeps the
 * name its closed record holds and is not a link.
 */
final class OverviewActivity {

	/**
	 * Lines on the Overview before "Show more".
	 */
	public const FIRST = 8;

	/**
	 * Lines one "Show more" adds.
	 */
	public const MORE = 20;

	/**
	 * Most lines one request returns.
	 */
	public const MAX = 50;

	/**
	 * Rows read from the events table per query.
	 */
	private const CHUNK = 100;

	/**
	 * Most rows read for one request, however many lines they give.
	 */
	private const SCAN_CAP = 1000;

	/**
	 * Most licenses read for one customer.
	 */
	private const LICENSES = 500;

	/**
	 * Seconds within which a manual backup line makes the nightly backup line of the server redundant.
	 */
	private const BACKUP_TWIN = 900;

	/**
	 * Whether a subscription is on hold and its newest renewal order failed, by subscription id (asked once each).
	 *
	 * @var array<int, string>
	 */
	private static $pause_state = array();

	/**
	 * Handle of the script of the "Show more" button and the service select.
	 */
	public const HANDLE = 'beltoft-services-activity';

	/**
	 * Loads the script of the log, with what it reads: the address of the route, the nonce and its words.
	 */
	public static function script(): void {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/activity.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/activity.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesActivity',
			array(
				'root'    => esc_url_raw( rest_url( \BeltoftServices\Api\Routes::NAMESPACE . '/activity' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'first'   => self::FIRST,
				'more'    => self::MORE,
				'strings' => array(
					'loading' => __( 'Loading', 'beltoft-services' ),
					'failed'  => __( 'Could not load the activity. Please try again.', 'beltoft-services' ),
					'empty'   => __( 'Nothing to show for this service yet.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * Forgets what was asked.
	 */
	public static function reset(): void {
		self::$pause_state = array();
	}

	/**
	 * The customer's own licenses, ended ones included. A license counts only when its user is the customer: an email match
	 * on a guest license is not enough (the rule of Api\Services).
	 *
	 * @param int $user_id Customer.
	 * @return array<int, array<string, mixed>> License id => license row, the newest last.
	 */
	public static function licenses_of( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( \BeltoftLicenses\Account\Endpoint::class ) ) {
			return array();
		}

		$out = array();

		foreach ( array_slice( array_values( \BeltoftLicenses\Account\Endpoint::licenses_for_user( $user_id ) ), -self::LICENSES ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) === $user_id && (int) ( $license['id'] ?? 0 ) > 0 ) {
				$out[ (int) $license['id'] ] = $license;
			}
		}

		return $out;
	}

	/**
	 * The feed for the Overview: the first lines, grouped by day, and what the select needs.
	 *
	 * @param int $user_id Customer.
	 * @return array{days: array<int, array<string, mixed>>, next: int, services: array<int, string>}
	 */
	public static function feed( int $user_id ): array {
		$licenses = self::licenses_of( $user_id );
		$page     = self::page( $licenses, 0, self::FIRST );

		return array(
			'days'     => self::days( $page['lines'] ),
			'next'     => $page['next'],
			'services' => $page['services'],
		);
	}

	/**
	 * One page of the log.
	 *
	 * @param array<int, array<string, mixed>> $licenses The customer's own licenses (see licenses_of()).
	 * @param int                              $before   Cursor: only events with a lower id are read; 0 for the newest.
	 * @param int                              $limit    Most lines.
	 * @param int                              $service  Only this license; 0 for all.
	 * @return array{lines: array<int, array<string, mixed>>, next: int, services: array<int, string>} Next is the cursor of the following page, 0 at the end; services (license id => name) only on the first page without a filter.
	 */
	public static function page( array $licenses, int $before, int $limit, int $service = 0 ): array {
		$empty = array( 'lines' => array(), 'next' => 0, 'services' => array() );
		$limit = max( 1, min( self::MAX, $limit ) );
		$ids   = $service > 0 ? ( isset( $licenses[ $service ] ) ? array( $service ) : array() ) : array_keys( $licenses );

		if ( ! $ids || ! class_exists( \BeltoftLicenses\Install::class ) ) {
			return $empty;
		}

		$lines    = array();
		$keys     = array();
		$consumed = $before;
		$cursor   = $before;
		$scanned  = 0;
		$stopped  = false;
		$ended    = false;

		while ( $scanned < self::SCAN_CAP && ! $stopped ) {
			$rows = self::rows( $ids, $cursor, self::CHUNK );

			foreach ( $rows as $row ) {
				++$scanned;
				$cursor     = (int) $row['id'];
				$license_id = (int) $row['license_id'];
				$pause      = '';

				if ( ActivityMap::is_pause( (string) $row['message'] ) ) {
					$pause = self::pause_state( (int) ( $licenses[ $license_id ]['subscription_id'] ?? 0 ) );
				}

				$said = ActivityMap::sentence( (string) $row['message'], $pause );

				if ( null === $said ) {
					continue;
				}

				$at  = (int) strtotime( $row['created_at'] . ' UTC' );
				$key = $license_id . '|' . $said['text'] . '|' . wp_date( 'Y-m-d', $at );

				if ( isset( $keys[ $key ] ) ) {
					// The same thing again on the same day: one line, counted. A single fact (a deletion) stays one.
					if ( ! $said['once'] ) {
						++$lines[ $keys[ $key ] ]['count'];
					}

					$consumed = $cursor;
					continue;
				}

				if ( count( $lines ) >= $limit ) {
					$stopped = true;
					break;
				}

				$keys[ $key ] = count( $lines );
				$lines[]      = array(
					'id'      => (int) $row['id'],
					'at'      => $at,
					'license' => $license_id,
					'text'    => $said['text'],
					'count'   => 1,
				);
				$consumed     = $cursor;
			}

			if ( count( $rows ) < self::CHUNK ) {
				$ended = true;
				break;
			}
		}

		$next = $stopped ? $consumed : ( $ended ? 0 : $cursor );

		if ( 0 === $before && 0 === $service && ! $stopped ) {
			$lines = self::with_backups( $lines, $licenses, $ids, $limit );
		}

		$by_license = self::identify( $licenses, array_unique( array_column( $lines, 'license' ) ) );

		foreach ( $lines as $i => $line ) {
			$who                      = $by_license[ $line['license'] ] ?? array( 'name' => '', 'url' => '' );
			$lines[ $i ]['name']      = $who['name'];
			$lines[ $i ]['url']       = $who['url'];
			$lines[ $i ]['time']      = wp_date( 'H:i', (int) $line['at'] );
			$lines[ $i ]['datetime']  = gmdate( 'c', (int) $line['at'] );
			$lines[ $i ]['sentence']  = $line['count'] > 1 ? self::counted( (string) $line['text'], (int) $line['count'] ) : (string) $line['text'];
		}

		return array(
			'lines'    => $lines,
			'next'     => $next,
			'services' => 0 === $before && 0 === $service ? self::services( $licenses, $ids ) : array(),
		);
	}

	/**
	 * Groups lines by day, newest first.
	 *
	 * @param array<int, array<string, mixed>> $lines Lines of page().
	 * @return array<int, array{day: string, label: string, lines: array<int, array<string, mixed>>}>
	 */
	public static function days( array $lines ): array {
		$days = array();

		foreach ( $lines as $line ) {
			$day = wp_date( 'Y-m-d', (int) $line['at'] );

			if ( ! $days || $days[ count( $days ) - 1 ]['day'] !== $day ) {
				$days[] = array(
					'day'   => $day,
					'label' => self::day_label( $day ),
					'lines' => array(),
				);
			}

			$days[ count( $days ) - 1 ]['lines'][] = $line;
		}

		return $days;
	}

	/**
	 * "Today", "Yesterday", else the date in the site's format.
	 *
	 * @param string $day Day as Y-m-d in the site's time zone.
	 */
	private static function day_label( string $day ): string {
		if ( wp_date( 'Y-m-d' ) === $day ) {
			return __( 'Today', 'beltoft-services' );
		}

		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			return __( 'Yesterday', 'beltoft-services' );
		}

		return wp_date( (string) get_option( 'date_format' ), (int) strtotime( $day . ' 12:00:00 UTC' ) );
	}

	/**
	 * A sentence with the number of times it happened.
	 *
	 * @param string $text  Sentence.
	 * @param int    $count How many times.
	 */
	private static function counted( string $text, int $count ): string {
		/* translators: 1: what happened, for example "Backup deleted", 2: how many times, 2 or more */
		return sprintf( _n( '%1$s, %2$d time', '%1$s, %2$d times', $count, 'beltoft-services' ), $text, $count );
	}

	/**
	 * One chunk of history rows, newest first.
	 *
	 * @param int[] $ids    License ids.
	 * @param int   $before Only rows with a lower id; 0 for no bound.
	 * @param int   $limit  Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( array $ids, int $before, int $limit ): array {
		global $wpdb;

		$table = \BeltoftLicenses\Install::tables()['events'];
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$bound = $before > 0 ? $before : PHP_INT_MAX;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, one bounded query per chunk; the placeholders are generated.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, license_id, message, created_at FROM %i WHERE type = 'service' AND license_id IN ($in) AND id < %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $table ), array_values( $ids ), array( $bound, $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * What a pause line may say: '' when its subscription is not on hold (the line is then not shown), ActivityMap::HELD,
	 * or ActivityMap::FAILED when the newest renewal order failed. Asked once per subscription.
	 *
	 * @param int $subscription_id Subscription id.
	 */
	private static function pause_state( int $subscription_id ): string {
		if ( $subscription_id <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		if ( ! isset( self::$pause_state[ $subscription_id ] ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
			$state        = '';

			if ( $subscription instanceof \WC_Subscription && $subscription->has_status( 'on-hold' ) ) {
				$newest = $subscription->get_last_order( 'all', 'renewal' );
				$state  = $newest instanceof \WC_Order && $newest->has_status( 'failed' ) ? ActivityMap::FAILED : ActivityMap::HELD;
			}

			self::$pause_state[ $subscription_id ] = $state;
		}

		return self::$pause_state[ $subscription_id ];
	}

	/**
	 * The names and addresses of services, each asked once for the page: two queries for any number (the live resources, the
	 * closed ones of the licenses that have none).
	 *
	 * @param array<int, array<string, mixed>> $licenses Own licenses.
	 * @param int[]                            $ids      License ids to name.
	 * @return array<int, array{name: string, url: string}> License id => name and address ('' when the service is gone).
	 */
	private static function identify( array $licenses, array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => isset( $licenses[ $id ] ) ) );

		if ( ! $ids ) {
			return array();
		}

		Resource::preload( $ids );

		$live    = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$resource = Resource::for_license( $id );

			if ( $resource ) {
				$live[ $id ] = $resource;
			} else {
				$missing[] = $id;
			}
		}

		$closed = $missing ? Resource::latest_closed( $missing ) : array();
		$out    = array();

		foreach ( $ids as $id ) {
			$license  = $licenses[ $id ];
			$resource = $live[ $id ] ?? ( $closed[ $id ] ?? null );
			$type     = Slugs::type_of( $license );
			$name     = $resource ? Slugs::name_of( $resource, $license, $type ) : '';

			if ( '' === $name ) {
				$name = (string) apply_filters( 'beltoft_licenses_account_title', \BeltoftLicenses\Account\View::product_name( $license ), $license );
			}

			$out[ $id ] = array(
				'name' => $name,
				'url'  => isset( $live[ $id ] ) && ! Resource::gone( $live[ $id ] ) ? self::url( $license, $type ) : '',
			);
		}

		return $out;
	}

	/**
	 * The page of a living service.
	 *
	 * @param array<string, mixed> $license License row.
	 * @param string               $type    Type of service (see Slugs::type_of()).
	 */
	private static function url( array $license, string $type ): string {
		if ( 'servers' === $type ) {
			return ServerLinks::url( $license );
		}

		foreach ( ServiceArea::all() as $area ) {
			if ( $area->key_name() === $type ) {
				return $area->url( $license );
			}
		}

		return '';
	}

	/**
	 * The services that have history, for the select: license id => name, in name order. One query for at most 50 services.
	 *
	 * @param array<int, array<string, mixed>> $licenses Own licenses.
	 * @param int[]                            $ids      License ids.
	 * @return array<int, string>
	 */
	private static function services( array $licenses, array $ids ): array {
		global $wpdb;

		$table = \BeltoftLicenses\Install::tables()['events'];
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, one query for the select; the placeholders are generated.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT license_id FROM %i WHERE type = 'service' AND license_id IN ($in) LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $table ), array_values( $ids ) )
			)
		);
		// phpcs:enable

		$named = self::identify( $licenses, array_map( 'intval', (array) $found ) );
		$out   = array();

		foreach ( $named as $id => $who ) {
			$out[ $id ] = $who['name'];
		}

		natcasesort( $out );

		return $out;
	}

	/**
	 * The nightly backup of a server is kept on the server's record, not in the history. When the log is short it is added as
	 * a line, unless a backup the customer took within minutes of it is already there.
	 *
	 * @param array<int, array<string, mixed>> $lines    Lines.
	 * @param array<int, array<string, mixed>> $licenses Own licenses.
	 * @param int[]                            $ids      License ids.
	 * @param int                              $limit    Lines of a page.
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_backups( array $lines, array $licenses, array $ids, int $limit ): array {
		if ( count( $lines ) >= $limit ) {
			return $lines;
		}

		Resource::preload( $ids );

		$text = __( 'Backup taken', 'beltoft-services' );

		foreach ( $ids as $id ) {
			$resource = Resource::for_license( (int) $id );
			$done     = $resource ? (int) $resource->get( 'backup_ok_at' ) : 0;

			if ( $done <= 0 || Resource::gone( $resource ) || count( $lines ) >= $limit ) {
				continue;
			}

			foreach ( $lines as $line ) {
				if ( (int) $line['license'] === (int) $id && $text === $line['text'] && abs( (int) $line['at'] - $done ) <= self::BACKUP_TWIN ) {
					continue 2;
				}
			}

			$lines[] = array(
				'id'      => 0,
				'at'      => $done,
				'license' => (int) $id,
				'text'    => $text,
				'count'   => 1,
			);
		}

		usort(
			$lines,
			static function ( array $a, array $b ): int {
				return $b['at'] <=> $a['at'];
			}
		);

		return $lines;
	}

	/**
	 * A history message as a customer sentence, or '' when it is not for customers (see ActivityMap).
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

		$pause = $held ? ( $failed ? ActivityMap::FAILED : ActivityMap::HELD ) : '';
		$said  = ActivityMap::sentence( $message, $pause );

		return $said ? $said['text'] : '';
	}
}

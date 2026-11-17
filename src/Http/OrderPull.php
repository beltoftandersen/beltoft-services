<?php
/**
 * Pulls the counted events (orders a service processed) of each counted service, once an hour.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Http;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Usage\Counted\Intro;
use BeltoftServices\Usage\Counted\Rule;
use BeltoftServices\Usage\Counted\Store;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Pull, not push: the plugin asks the service for events after its cursor (resource meta `events_cursor`, the highest remote
 * sequence taken) through the signed driver, and judges each one itself. It is called from the hourly meter, so no new schedule exists.
 *
 * An event is stored once (unique remote sequence, and unique store and order). It is judged at receipt: free in the free intro
 * or for a service with a free rate; held when the day's cap is passed; billable otherwise. A page whose sequences are not above the
 * cursor, or a total lower than what is held, stops the pull for that resource and tells the admin. A total higher than what is
 * held makes the next run read from the start once (idempotent), and tells the admin when it still differs.
 */
final class OrderPull {

	public const CURSOR  = 'events_cursor';
	public const STOPPED = 'events_stopped';
	public const ERRORS  = 'events_errors';
	public const LAST    = 'events_last_pull';
	public const PAGE    = 500;
	public const PAGES   = 10;

	/**
	 * Pulls every counted service.
	 *
	 * @param int $now Unix time.
	 * @return int Events stored.
	 */
	public static function run( int $now ): int {
		$stored = 0;

		foreach ( self::counted( $now ) as $r ) {
			$stored += self::pull( $r, $now );
		}

		return $stored;
	}

	/**
	 * The live counted services (billing kind counted, on the usage account).
	 *
	 * @param int $now Unix time.
	 * @return Resource[]
	 */
	public static function counted( int $now ): array {
		$out  = array();
		$rows = Licenses::resources_of_source( 'usage', gmdate( 'Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS ) );

		foreach ( (array) $rows as $row ) {
			$r = Resource::find( (int) $row['id'] );

			if ( $r && $r->is_valid() && Rates::is_counted( (int) ( $r->license()['variation_id'] ?? 0 ) ) && 'deleted' !== $r->get( 'state' ) ) {
				$out[] = $r;
			}
		}

		return $out;
	}

	/**
	 * Pulls one resource.
	 *
	 * @param Resource $r   Resource.
	 * @param int      $now Unix time.
	 * @return int Events stored.
	 */
	public static function pull( Resource $r, int $now ): int {
		$driver = \BeltoftServices\Drivers\Registry::get( (string) $r->get( 'driver' ) );

		if ( ! $driver instanceof HttpDriver || '' !== (string) $r->get( self::STOPPED ) ) {
			return 0;
		}

		$cursor = (int) $r->get( self::CURSOR );
		$stored = 0;
		$remote = null;

		for ( $page = 0; $page < self::PAGES; $page++ ) {
			$answer = $driver->read_events( $r, 'events', array( 'after' => $cursor, 'limit' => self::PAGE ) );

			if ( ! $answer['ok'] ) {
				self::failed( $r, $answer );
				return $stored;
			}

			$data   = $answer['data'];
			$orders = is_array( $data['orders'] ?? null ) ? $data['orders'] : array();
			$remote = isset( $data['count_total'] ) && is_numeric( $data['count_total'] ) ? (int) $data['count_total'] : null;

			// The cursor only moves forward: a page that goes back, or a total below what is held, is not trusted.
			foreach ( $orders as $order ) {
				if ( ! is_array( $order ) || ! isset( $order['seq'] ) || (int) $order['seq'] <= $cursor ) {
					self::stop( $r, 'The service sent a sequence at or below the cursor (' . $cursor . ').' );
					return $stored;
				}

				$cursor = (int) $order['seq'];

				if ( self::take( $r, $order, $now ) ) {
					++$stored;
				}
			}

			if ( null === ( $data['next'] ?? null ) || ! $orders ) {
				break;
			}
		}

		$r->set( array( self::CURSOR => $cursor, self::LAST => $now, self::ERRORS => 0 ) );

		if ( null !== $remote ) {
			self::reconcile( $r, $remote );
		}

		Intro::check_limit( $r );

		return $stored;
	}

	/**
	 * Judges and stores one event. False when it was seen before or is not valid.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $order The service's event.
	 * @param int                  $now   Unix time of receipt.
	 */
	private static function take( Resource $r, array $order, int $now ): bool {
		$store = (string) ( $order['store_key'] ?? '' );
		$id    = (string) ( $order['woo_order_id'] ?? '' );
		$date  = (string) ( $order['order_date'] ?? '' );
		$stamp = '' !== $date ? strtotime( $date ) : false;

		// A sane event: known source, bounded keys, a numeric order id, a date that is not in the future.
		if ( '' === $store || strlen( $store ) > 255 || strlen( (string) ( $order['odoo_key'] ?? '' ) ) > 255 || 1 !== preg_match( '/^\d{1,20}\z/', $id ) || ! in_array( (string) ( $order['source'] ?? '' ), array( 'webhook', 'backfill' ), true ) || ( false !== $stamp && $stamp > $now + DAY_IN_SECONDS ) ) {
			Alerts::notify(
				/* translators: %d: resource id */
				sprintf( __( 'Invalid event from service %d', 'beltoft-services' ), $r->id() ),
				array( sprintf( 'Service %1$d sent an event that was refused (sequence %2$s).', $r->id(), (string) ( $order['seq'] ?? '?' ) ) ),
				$r,
				'beltoft_services_event_invalid_' . $r->id()
			);

			return false;
		}

		$event = array(
			'resource_id'     => $r->id(),
			'user_id'         => (int) ( $r->license()['user_id'] ?? 0 ),
			'remote_seq'      => (int) $order['seq'],
			'store_key'       => $store,
			'odoo_key'        => (string) ( $order['odoo_key'] ?? '' ),
			'woo_order_id'    => $id,
			'order_number'    => substr( (string) ( $order['order_number'] ?? $id ), 0, 64 ),
			'order_date'      => false !== $stamp ? gmdate( 'Y-m-d H:i:s', $stamp ) : '',
			'status'          => substr( (string) ( $order['status'] ?? '' ), 0, 32 ),
			'total'           => substr( (string) ( $order['total'] ?? '' ), 0, 32 ),
			'currency'        => substr( (string) ( $order['currency'] ?? '' ), 0, 8 ),
			'odoo_order_name' => substr( (string) ( $order['odoo_order_name'] ?? '' ), 0, 255 ),
			'source'          => (string) $order['source'],
			'received_at'     => $now,
		);

		// The same store and order is one event for life, whatever the service's sequence says.
		if ( Store::has_order( $r->id(), $store, $id ) ) {
			return false;
		}

		list( $event['state'], $event['free_reason'] ) = self::judge( $r, $event, $now );

		return Store::add( $event );
	}

	/**
	 * The state of a new event: free (a free rate or the intro), held (over the day's cap) or billable.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $event Event.
	 * @param int                  $now   Unix time.
	 * @return array{0: string, 1: string}
	 */
	private static function judge( Resource $r, array $event, int $now ): array {
		if ( Rule::free_rate( $r ) ) {
			return array( Store::FREE, 'rate' );
		}

		Intro::decide( $r, $event, $now );

		if ( Intro::active( $r, $now ) ) {
			return array( Store::FREE, 'intro' );
		}

		$cap = self::cap( $r, $now );

		if ( Store::received_since( $r->id(), $now - DAY_IN_SECONDS ) >= $cap ) {
			Alerts::notify(
				/* translators: %d: resource id */
				sprintf( __( 'Orders held for service %d', 'beltoft-services' ), $r->id() ),
				array( sprintf( 'Service %1$d passed its daily cap of %2$d new orders. Further orders are held, not billed, until staff release them.', $r->id(), $cap ) ),
				$r,
				'beltoft_services_event_cap_' . $r->id()
			);

			return array( Store::HELD, '' );
		}

		return array( Store::BILLABLE, '' );
	}

	/**
	 * The most events a service may add in a day before they are held: the larger of the setting and a multiple of its trailing
	 * 14-day daily average.
	 *
	 * @param Resource $r   Resource.
	 * @param int      $now Unix time.
	 */
	public static function cap( Resource $r, int $now ): int {
		$average = Store::received_between( $r->id(), $now - 15 * DAY_IN_SECONDS, $now - DAY_IN_SECONDS ) / 14;

		return max( (int) Settings::get( 'counted_cap_min' ), (int) ceil( (float) Settings::get( 'counted_cap_factor' ) * $average ) );
	}

	/**
	 * Compares the service's total with what is held.
	 *
	 * @param Resource $r      Resource.
	 * @param int      $remote Total the service reports.
	 */
	private static function reconcile( Resource $r, int $remote ): void {
		$local = Store::count( $r->id() );

		if ( $local === $remote ) {
			$r->set( array( 'events_regap' => 0 ) );
			return;
		}

		if ( $local > $remote ) {
			self::stop( $r, sprintf( 'The service reports %d events but %d are held.', $remote, $local ) );
			return;
		}

		// Fewer held than reported: read from the start once; if it still differs the next run tells the admin.
		if ( ! (int) $r->get( 'events_regap' ) ) {
			$r->set( array( self::CURSOR => 0, 'events_regap' => 1 ) );
			return;
		}

		Alerts::notify(
			/* translators: %d: resource id */
			sprintf( __( 'Event counts differ for service %d', 'beltoft-services' ), $r->id() ),
			array( sprintf( 'Service %1$d reports %2$d events and %3$d are held, also after reading from the start.', $r->id(), $remote, $local ) ),
			$r,
			'beltoft_services_event_gap_' . $r->id()
		);
	}

	/**
	 * Stops the pull of a resource and tells the admin.
	 *
	 * @param Resource $r   Resource.
	 * @param string   $why Reason.
	 */
	private static function stop( Resource $r, string $why ): void {
		$r->set( array( self::STOPPED => $why ) );
		Alerts::notify(
			/* translators: %d: resource id */
			sprintf( __( 'Event pull stopped for service %d', 'beltoft-services' ), $r->id() ),
			array( $why, 'Nothing more is pulled for this service until the meta events_stopped is cleared.' ),
			$r,
			'beltoft_services_event_stop_' . $r->id()
		);
	}

	/**
	 * A failed read: counted, and after three in a row the admin is told (not for a gone tenant).
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $answer Answer.
	 */
	private static function failed( Resource $r, array $answer ): void {
		$errors = (int) $r->get( self::ERRORS ) + 1;
		$r->set( array( self::ERRORS => $errors ) );

		if ( 3 === $errors && 404 !== (int) $answer['code'] ) {
			Alerts::notify(
				/* translators: %d: resource id */
				sprintf( __( 'Events not pulled for service %d', 'beltoft-services' ), $r->id() ),
				array( sprintf( 'Reading the events of service %1$d failed three times in a row (%2$s, status %3$d).', $r->id(), (string) $answer['error'], (int) $answer['code'] ) ),
				$r,
				'beltoft_services_event_pull_' . $r->id()
			);
		}
	}
}

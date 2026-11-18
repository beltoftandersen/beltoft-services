<?php
/**
 * The automatic order, delivery and cancellation of a dedicated server through the Robot webservice.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Flow (doc: Server ordering, Server, Cancellation):
 * 1. Guard (live only). 2. Write the marker `robot_ordering` and the encrypted root password, THEN POST /order/server/transaction
 *    (product_id and location from the plan, addon[]=primary_ipv4 because a server is IPv6 only without it, dist when set, the
 *    password; the documentation lets a request carry authorized_key[] or password, and the transaction answer returns no password).
 * 3. Store the transaction id at once. A call whose outcome is not known (no answer, 5xx) is NEVER sent again: the marker stays,
 *    staff are told and use Mark ready or check Robot by hand. A documented refusal (400, 412, 403) placed nothing and clears the marker.
 * 4. Poll GET /order/server/transaction/{id} until "ready" (statuses: ready, in process, cancelled). Then store server_number and
 *    server_ip, read GET /server/{number} for server_ipv6_net, set `manual_ready_at`: the reconciler makes the service ready.
 * 5. Cancel: POST /server/{number}/cancellation with cancellation_date=now (immediately) and reserve_location=false.
 */
final class RobotOrder {
	public const TRANSACTION = 'robot_transaction';
	public const NUMBER      = 'robot_server_number';
	public const ORDERING    = 'robot_ordering';
	public const IPV6        = 'manual_ipv6';

	/** Is ordering at the supplier switched on (otherwise staff buy by hand)? */
	public static function enabled(): bool {
		return (bool) Settings::get( 'dedicated_enabled' );
	}

	/**
	 * The provision step.
	 *
	 * @param Resource $r Resource.
	 */
	public static function provision( Resource $r ): StepResult {
		if ( '' === (string) $r->get( self::TRANSACTION ) ) {
			$placed = self::place( $r );

			if ( $placed instanceof StepResult ) {
				return $placed;
			}

			$r = Resource::find( $r->id() ) ?? $r;
		}

		return self::follow( $r );
	}

	/**
	 * Places the order, once.
	 *
	 * @param Resource $r Resource.
	 * @return StepResult|null A result to return, or null when the transaction id is stored.
	 */
	private static function place( Resource $r ): ?StepResult {
		if ( (int) $r->get( self::ORDERING ) > 0 ) {
			$why = 'The order was sent, but its answer was not stored. It is not sent again. Check Robot, then press Mark ready with the server details.';
			Alerts::admin( $r, $why );

			return StepResult::fail( $why );
		}

		$kind   = DedicatedClients::kind();
		$client = DedicatedClients::for();

		if ( 'unavailable' === $kind ) {
			return StepResult::retry( 3600, 'Automatic ordering is not usable (Settings, Dedicated servers).' );
		}

		if ( 'live' === $kind ) {
			$stop = DedicatedSpendGuard::guard( $r );

			if ( $stop ) {
				return $stop;
			}
		}

		$plan     = (array) $r->get( 'plan' );
		$password = wp_generate_password( 24, false, false );
		$dist     = '' !== (string) ( $plan['server_dist'] ?? '' ) ? (string) $plan['server_dist'] : trim( (string) Settings::get( 'dedicated_dist' ) );

		// The marker and the password are stored before the call, so a lost answer can never lead to a second order.
		if ( ! $r->set( array( self::ORDERING => time(), ManualServerDelivery::PASSWORD => Crypto::encrypt( $password ) ) ) ) {
			return StepResult::retry( 60, 'The order marker could not be stored.' );
		}

		$answer = $client->order(
			array(
				'product_id' => (string) ( $plan['server_ref'] ?? '' ),
				'location'   => (string) ( $plan['server_location_code'] ?? '' ),
				'dist'       => $dist,
				'password'   => $password,
				'addon'      => array( 'primary_ipv4' ),
			)
		);

		if ( is_wp_error( $answer ) ) {
			return self::order_failed( $r, $answer );
		}

		$id = (string) ( $answer['id'] ?? '' );

		if ( '' === $id ) {
			Alerts::admin( $r, 'Robot answered the order without a transaction id. It is not sent again. Check Robot.' );

			return StepResult::fail( 'Robot answered the order without a transaction id.' );
		}

		$r->set( array( self::TRANSACTION => $id, self::ORDERING => null ) );

		if ( 'live' === $kind ) {
			DedicatedSpendGuard::count_created();
		}

		return null;
	}

	/**
	 * A refused or lost order.
	 *
	 * @param Resource  $r     Resource.
	 * @param \WP_Error $error Error.
	 */
	private static function order_failed( Resource $r, \WP_Error $error ): StepResult {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$code   = (string) $error->get_error_code();

		// The documented refusals place nothing: the marker goes, so staff may retry.
		if ( in_array( $status, array( 400, 403, 412 ), true ) ) {
			$r->set( array( self::ORDERING => null ) );

			if ( 403 === $status && 'RATE_LIMIT_EXCEEDED' === $code ) {
				return StepResult::retry( 3600, 'Robot rate limit reached (20 orders a day).' );
			}

			$why = sprintf( 'Robot refused the order: %1$s (%2$d).', $code, $status );
			Alerts::admin( $r, $why . ' Nothing was ordered. Fix it, or buy by hand and press Mark ready.' );

			return StepResult::fail( $why );
		}

		// A 500 or no answer: it is not known whether the order exists. Never sent again.
		$why = sprintf( 'The order outcome is not known (%1$s, %2$d). It is not sent again. Check Robot, then press Mark ready with the server details.', $code, $status );
		Alerts::admin( $r, $why );

		return StepResult::fail( $why );
	}

	/**
	 * Reads the transaction: poll while in process, deliver when ready.
	 *
	 * @param Resource $r Resource.
	 */
	private static function follow( Resource $r ): StepResult {
		$client = DedicatedClients::for();
		$tx     = $client->transaction( (string) $r->get( self::TRANSACTION ) );

		if ( is_wp_error( $tx ) ) {
			$data = $tx->get_error_data();

			if ( is_array( $data ) && 404 === (int) ( $data['status'] ?? 0 ) ) {
				Alerts::admin( $r, 'Robot does not know the transaction ' . $r->get( self::TRANSACTION ) . '. Check Robot, then press Mark ready.' );

				return StepResult::fail( 'Robot does not know the transaction.' );
			}

			return StepResult::retry( 60, 'The transaction could not be read: ' . $tx->get_error_code() );
		}

		$status = (string) ( $tx['status'] ?? '' );

		if ( 'cancelled' === $status ) {
			Alerts::admin( $r, 'Robot cancelled the transaction ' . $r->get( self::TRANSACTION ) . '. Nothing was delivered. Press Mark ready after buying by hand, or cancel the service.' );

			return StepResult::fail( 'Robot cancelled the order.' );
		}

		$number = (int) ( $tx['server_number'] ?? 0 );

		if ( 'ready' !== $status || $number < 1 ) {
			ManualServerDelivery::overdue_mail( $r );

			return StepResult::poll( 60, 'Waiting for the supplier to deliver the server', Reconciler::POLL_BUDGET_MAX );
		}

		$meta = array(
			self::NUMBER                         => $number,
			ManualServerDelivery::PROVIDER_NO    => (string) $number,
			ManualServerDelivery::IPS            => array_values( array_filter( array( (string) ( $tx['server_ip'] ?? '' ) ) ) ),
			ManualServerDelivery::READY_AT       => time(),
			'manual_ready_by'                    => 'automatic',
		);
		$server = $client->server( $number );

		if ( is_array( $server ) && ! empty( $server['server_ipv6_net'] ) ) {
			$meta[ self::IPV6 ] = (string) $server['server_ipv6_net'];
		}

		return StepResult::done( $meta, 'Server delivered by the supplier' );
	}

	/**
	 * The delete step: cancels at the supplier at once, or tells staff when nothing was ordered through the API.
	 *
	 * @param Resource $r Resource.
	 */
	public static function cancel( Resource $r ): StepResult {
		$number = (int) $r->get( self::NUMBER );
		$id     = (string) $r->get( self::TRANSACTION );

		$by_hand = (int) $r->get( ManualServerDelivery::READY_AT ) > 0 && 'automatic' !== (string) $r->get( 'manual_ready_by' );

		// Nothing was ordered through the API, or staff delivered it by hand: staff cancel it at the supplier.
		if ( $by_hand || ( $number < 1 && '' === $id ) ) {
			ManualServerDelivery::staff_mail( $r, 'cancel' );

			return StepResult::done( array(), 'Staff told to cancel the server at the supplier' );
		}

		$client = DedicatedClients::for();

		if ( $number < 1 ) {
			$tx = $client->transaction( $id );

			if ( is_wp_error( $tx ) ) {
				return StepResult::retry( 300, 'The transaction could not be read: ' . $tx->get_error_code() );
			}

			if ( 'cancelled' === ( $tx['status'] ?? '' ) ) {
				return StepResult::done( array(), 'The order was cancelled at the supplier' );
			}

			$number = (int) ( $tx['server_number'] ?? 0 );

			// No documented call cancels a transaction that is still in process: wait until the server exists.
			if ( 'ready' !== ( $tx['status'] ?? '' ) || $number < 1 ) {
				return StepResult::poll( 60, 'Waiting for the server to exist before it is cancelled', Reconciler::POLL_BUDGET_MAX );
			}
		}

		$done = $client->cancel( $number );

		if ( is_wp_error( $done ) ) {
			$data    = $done->get_error_data();
			$status  = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			$already = 409 === $status && false !== stripos( $done->get_error_message(), 'already cancelled' );

			if ( ! $already && 404 !== $status ) {
				return StepResult::retry( 300, 'The cancellation failed: ' . $done->get_error_code() . ' (' . $status . ')' );
			}

			if ( 404 === $status ) {
				Alerts::admin( $r, 'Robot does not know server ' . $number . ' on cancel. Check Robot.' );
			}
		}

		Alerts::notify(
			sprintf( 'Dedicated server cancelled at the supplier (service %d)', $r->id() ),
			array( 'Server number ' . $number . ' was cancelled immediately through the API. Billing to the customer has stopped.' ),
			$r,
			'beltoft_services_robot_cancelled_' . $r->id(),
			array( 'ttl' => 30 * DAY_IN_SECONDS )
		);

		return StepResult::done( array( 'robot_cancelled_at' => time() ), 'Server cancelled at the supplier' );
	}
}

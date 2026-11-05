<?php
/** The yearly renewal of a certificate order: billing date, paid renewal, sweep. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Domains\Renewals;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The subscription is charged SslSettings::renew_days() before the order's expiry (the registrar renews an order only within
 * 30 days of it). When that renewal order is paid, the registrar renewal is requested, once per paid order, after the cost
 * guard; the new expiry read back from the order is what proves it. An unpaid renewal asks nothing of the registrar: the order
 * just expires, and the service ends through the licence expiry like any other.
 */
final class SslRenewals {
	public const SWEEP_HOOK  = 'beltoft_services_ssl_renew_sweep';
	public const SWEEP_CRON  = '50 4 * * *';
	public const SWEEP_LIMIT = 20;

	/** Hooks. */
	public static function register(): void {
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'on_paid' ), 20, 2 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/** Schedules the daily sweep, once. */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::SWEEP_HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::SWEEP_CRON, self::SWEEP_HOOK, array(), Jobs::GROUP );
		}
	}

	/** Removes the sweep (plugin deactivation). */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SWEEP_HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * The time the next renewal is charged: before the expiry, but never in the past.
	 *
	 * @param string   $expiry Order expiry, UTC.
	 * @param int|null $now    Now.
	 */
	public static function next_payment_at( string $expiry, ?int $now = null ): int {
		$now     = $now ?? time();
		$expires = strtotime( $expiry . ' UTC' );

		return false === $expires ? 0 : max( $now + HOUR_IN_SECONDS, $expires - SslSettings::renew_days() * DAY_IN_SECONDS );
	}

	/**
	 * Dates the subscription's next payment from the order's expiry.
	 *
	 * @param Resource $r Resource.
	 */
	public static function set_dates( Resource $r ): StepResult {
		$subscription_id = (int) ( $r->license()['subscription_id'] ?? 0 );

		if ( ! $subscription_id ) {
			return StepResult::done();
		}

		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : false;

		if ( ! $sub ) {
			return StepResult::retry( 3600, 'The certificate subscription is unavailable.' );
		}

		$next = self::next_payment_at( (string) $r->get( 'ssl_order_expires' ) );

		if ( ! $next ) {
			return StepResult::retry( 3600, 'The order expiry date is unavailable.' );
		}

		try {
			$saved = $sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ), 'gmt' );
		} catch ( \Exception $e ) {
			return StepResult::retry( 3600, 'The subscription billing date could not be set.' );
		}

		return $saved ? StepResult::done( array( 'next_payment_at' => $next ) ) : StepResult::retry( 3600, 'The subscription billing date could not be set.' );
	}

	/**
	 * A paid renewal order queues the registrar renewal of its certificate.
	 *
	 * @param mixed $subscription Subscription.
	 * @param mixed $order        Renewal order.
	 */
	public static function on_paid( $subscription, $order ): void {
		if ( ! $subscription instanceof \WC_Subscription || ! $order instanceof \WC_Order || ! $order->is_paid() ) {
			return;
		}

		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$r = Resource::for_license( (int) $license['id'] );

			if ( $r && SslDriver::ID === $r->get( 'driver' ) ) {
				Jobs::queue_act( $r->id(), 'renew', array( 'order' => (string) $order->get_id() ) );
			}
		}
	}

	/**
	 * Paid renewal orders of the last 30 days that no renewal job finished: queues them again (at most 20 a run).
	 *
	 * @return int How many were queued.
	 */
	public static function sweep(): int {
		$queued = 0;
		$since  = time() - 30 * DAY_IN_SECONDS;

		foreach ( Resource::live_ids( SslDriver::KIND ) as $id ) {
			$r   = Resource::find( $id );
			$sub = $r && $r->is_live() && SslDriver::ID === $r->get( 'driver' ) && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $r->license()['subscription_id'] ?? 0 ) ) : false;

			if ( ! $sub ) {
				continue;
			}

			$done = array_map( 'strval', (array) $r->get( 'renewed_orders' ) );

			foreach ( (array) $sub->get_related_orders( 'ids', 'renewal' ) as $order_id ) {
				$order   = wc_get_order( (int) $order_id );
				$created = $order ? $order->get_date_created() : null;

				if ( ! $order || ! $order->is_paid() || ! $created || $created->getTimestamp() < $since || in_array( (string) $order_id, $done, true ) || self::open( $r->id(), (string) $order_id ) ) {
					continue;
				}

				Jobs::queue_act( $r->id(), 'renew', array( 'order' => (string) $order_id ) );

				if ( ++$queued >= self::SWEEP_LIMIT ) {
					return $queued;
				}
			}
		}

		return $queued;
	}

	/**
	 * Is a renew job for this order waiting or running?
	 *
	 * @param int    $resource_id Resource.
	 * @param string $order_id    Renewal order.
	 */
	private static function open( int $resource_id, string $order_id ): bool {
		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			if ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'args' => array( $resource_id, 'renew', array( 'order' => $order_id ) ), 'group' => Jobs::GROUP, 'status' => $status, 'per_page' => 1 ), 'ids' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renews the registrar order for one paid renewal order. Idempotent: a renewal already recorded is done, a request that was
	 * sent is never sent again, and the expiry that moved later is the proof.
	 *
	 * @param Resource $r        Resource.
	 * @param string   $order_id Paid renewal order.
	 */
	public static function renew( Resource $r, string $order_id ): StepResult {
		$order  = ctype_digit( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		$sub_id = (int) ( $r->license()['subscription_id'] ?? 0 );
		$linked = $order && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ? wcs_get_subscriptions_for_renewal_order( $order ) : array();

		if ( ! $order || ! $order->is_paid() || ! $sub_id || ! isset( $linked[ $sub_id ] ) ) {
			return StepResult::fail( 'No paid renewal order belongs to this certificate.' );
		}

		$done = array_map( 'strval', (array) $r->get( 'renewed_orders' ) );

		if ( in_array( $order_id, $done, true ) ) {
			return StepResult::done();
		}

		$id = (string) $r->get( 'remote_id' );

		if ( '' === $id ) {
			return StepResult::retry( 3600, 'The registrar order id is unavailable.' );
		}

		$started  = (array) $r->get( 'renew_started' );
		$baseline = (string) ( $started[ $order_id ] ?? $r->get( 'ssl_order_expires' ) );
		$detail   = Clients::registrar()->request( 'GET', '/ssl/orders/' . $id );

		if ( is_wp_error( $detail ) ) {
			return StepResult::retry( 3600, 'The registrar renewal could not be checked.' );
		}

		$expiry = (string) ( $detail['expiration_date'] ?? '' );

		if ( Renewals::later( $expiry, $baseline ) ) {
			return self::finish( $r, $order_id, $expiry, $done, $started );
		}

		if ( isset( $started[ $order_id ] ) ) {
			return StepResult::retry( 3600, 'A renewal request is awaiting registrar confirmation.' );
		}

		$product = (int) ( ( (array) $r->get( 'plan' ) )['ssl_product'] ?? 0 );
		$blocked = SslGuard::guard( $r, $product, SslGuard::item_of_order( $order ) );

		if ( $blocked ) {
			return $blocked;
		}

		$started[ $order_id ] = $baseline;

		if ( ! $r->set( array( 'renew_started' => $started ) ) ) {
			return StepResult::retry( 3600, 'The renewal could not be recorded before requesting it.' );
		}

		$reply = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/renew', array( 'id' => (int) $id ) );

		if ( is_wp_error( $reply ) ) {
			// A timeout or a 5xx may have gone through: the marker stays. A refusal means nothing was bought: it is freed.
			if ( LiveClient::is_transient( $reply ) || 'credentials_missing' === $reply->get_error_code() ) {
				return StepResult::retry( 3600, 'The registrar renewal needs staff attention.' );
			}

			if ( 'op_920' === $reply->get_error_code() ) {
				Alerts::admin( $r, 'Registrar balance is too low.' );
			}

			unset( $started[ $order_id ] );
			$r->set( array( 'renew_started' => $started ) );

			return StepResult::retry( 21600, 'The registrar renewal was refused (' . $reply->get_error_code() . ') and will be tried again.' );
		}

		$detail = Clients::registrar()->request( 'GET', '/ssl/orders/' . $id );

		if ( is_wp_error( $detail ) ) {
			return StepResult::retry( 3600, 'The registrar renewal is awaiting confirmation.' );
		}

		$expiry = (string) ( $detail['expiration_date'] ?? '' );

		return Renewals::later( $expiry, $baseline ) ? self::finish( $r, $order_id, $expiry, $done, $started ) : StepResult::retry( 3600, 'A renewal request is awaiting registrar confirmation.' );
	}

	/**
	 * Records a finished renewal and dates the next one.
	 *
	 * @param Resource             $r        Resource.
	 * @param string               $order_id Renewal order.
	 * @param string               $expiry   New expiry.
	 * @param string[]             $done     Orders done.
	 * @param array<string, mixed> $started  Requests sent.
	 */
	private static function finish( Resource $r, string $order_id, string $expiry, array $done, array $started ): StepResult {
		$done[] = $order_id;
		unset( $started[ $order_id ] );
		$r->set( array( 'ssl_order_expires' => $expiry ) );
		$dates = self::set_dates( $r );

		if ( ! $dates->is_done() ) {
			return $dates;
		}

		return StepResult::done( array_merge( $dates->meta(), array( 'renewed_orders' => $done, 'renew_started' => $started, 'ssl_order_expires' => $expiry ) ), 'SSL certificate renewed after paid subscription order ' . $order_id );
	}
}

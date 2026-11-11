<?php
/** The WooCommerce Subscriptions bookkeeping of a yearly renewal, shared by domains and certificates. @package BeltoftServices */
namespace BeltoftServices\Billing;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Dates the subscription's next payment from the registrar expiry, queues the renewal job when a renewal order is paid, and
 * queues it again for paid orders that no job finished. The registrar step (the money) stays with each driver's renew().
 */
final class RenewalSweep {

	/**
	 * The time the next renewal is charged: before the expiry, but never in the past.
	 *
	 * @param string   $expiry      Expiry, UTC.
	 * @param int      $days_before Days before the expiry.
	 * @param int|null $now         Now.
	 */
	public static function next_payment_at( string $expiry, int $days_before, ?int $now = null ): int {
		$now     = $now ?? time();
		// strtotime( ' UTC' ) is the current time: an unknown expiry must not become "now".
		if ( '' === trim( $expiry ) ) { return 0; }
		$expires = strtotime( $expiry . ' UTC' );
		if ( false === $expires ) { return 0; }
		return max( $now + HOUR_IN_SECONDS, $expires - $days_before * DAY_IN_SECONDS );
	}

	/**
	 * Dates the subscription's next payment from the expiry.
	 *
	 * @param Resource $r                    Resource.
	 * @param string   $expiry               Expiry, UTC (read by the caller from the resource).
	 * @param string   $no_subscription_text Retry text when the subscription is missing.
	 * @param string   $no_expiry_text       Retry text when the expiry is missing.
	 * @param int      $days_before          Days before the expiry.
	 */
	public static function set_dates( Resource $r, string $expiry, string $no_subscription_text, string $no_expiry_text, int $days_before ): StepResult {
		$subscription_id = (int) ( $r->license()['subscription_id'] ?? 0 );
		if ( ! $subscription_id ) { return StepResult::done(); }
		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : false;
		if ( ! $sub ) { return StepResult::retry( 3600, $no_subscription_text ); }
		$next = self::next_payment_at( $expiry, $days_before );
		if ( ! $next ) { return StepResult::retry( 3600, $no_expiry_text ); }
		$dates = array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) );
		// A trial end later than the new payment date is invalid in Subscriptions (a free transfer starts with a one year placeholder trial): the trial ends with the payment.
		if ( (int) $sub->get_time( 'trial_end' ) > $next ) { $dates['trial_end'] = $dates['next_payment']; }
		try { $saved = $sub->update_dates( $dates, 'gmt' ); }
		catch ( \Exception $e ) { return StepResult::retry( 3600, 'The subscription billing date could not be set.' ); }
		return $saved ? StepResult::done( array( 'next_payment_at' => $next ) ) : StepResult::retry( 3600, 'The subscription billing date could not be set.' );
	}

	/**
	 * A paid renewal order queues the renewal job of every resource of the subscription that this driver runs.
	 *
	 * @param mixed  $subscription Subscription.
	 * @param mixed  $order        Renewal order.
	 * @param string $driver_id    Driver id.
	 */
	public static function on_paid( $subscription, $order, string $driver_id ): void {
		if ( ! $subscription instanceof \WC_Subscription || ! $order instanceof \WC_Order || ! $order->is_paid() ) { return; }
		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$r = Resource::for_license( (int) $license['id'] );
			if ( $r && $driver_id === $r->get( 'driver' ) ) { Jobs::queue_act( $r->id(), 'renew', array( 'order' => (string) $order->get_id() ) ); }
		}
	}

	/**
	 * Paid renewal orders of the last 30 days that no renewal job finished: queues the renewal again (at most $limit a run).
	 * A job that is already waiting or running for the order is left alone.
	 *
	 * @param string $kind      Resource kind.
	 * @param string $driver_id Driver id.
	 * @param int    $limit     Most renewals queued in one run.
	 * @return int How many renewals were queued.
	 */
	public static function sweep( string $kind, string $driver_id, int $limit ): int {
		$queued = 0;
		$since  = time() - 30 * DAY_IN_SECONDS;
		foreach ( Resource::live_ids( $kind ) as $id ) {
			$r = Resource::find( $id );
			if ( ! $r || ! $r->is_live() || $driver_id !== $r->get( 'driver' ) ) { continue; }
			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $r->license()['subscription_id'] ?? 0 ) ) : false;
			if ( ! $sub ) { continue; }
			$done = array_map( 'strval', (array) $r->get( 'renewed_orders' ) );
			foreach ( (array) $sub->get_related_orders( 'ids', 'renewal' ) as $order_id ) {
				$order   = wc_get_order( (int) $order_id );
				$created = $order ? $order->get_date_created() : null;
				if ( ! $order || ! $order->is_paid() || ! $created || $created->getTimestamp() < $since || in_array( (string) $order_id, $done, true ) ) { continue; }
				if ( self::act_open( $r->id(), (string) $order_id ) ) { continue; }
				Jobs::queue_act( $r->id(), 'renew', array( 'order' => (string) $order_id ) );
				if ( ++$queued >= $limit ) { return $queued; }
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
	public static function act_open( int $resource_id, string $order_id ): bool {
		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			if ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'args' => array( $resource_id, 'renew', array( 'order' => $order_id ) ), 'group' => Jobs::GROUP, 'status' => $status, 'per_page' => 1 ), 'ids' ) ) { return true; }
		}
		return false;
	}
}

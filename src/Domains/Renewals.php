<?php
/** Subscription billing date and paid registry renewals. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

final class Renewals {
	public const TRANSFER_HOOK = 'beltoft_services_transfer_renew';
	public const SWEEP_HOOK    = 'beltoft_services_domain_renew_sweep';
	public const SWEEP_CRON    = '45 4 * * *';
	public const SWEEP_LIMIT   = 20;

	public static function register(): void {
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'on_paid' ), 20, 2 );
		add_action( self::TRANSFER_HOOK, array( __CLASS__, 'on_transfer_due' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/** Schedules the daily sweep for stuck paid renewals, once. */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::SWEEP_HOOK, array(), Jobs::GROUP ) ) {
			as_schedule_cron_action( time(), self::SWEEP_CRON, self::SWEEP_HOOK, array(), Jobs::GROUP );
		}
	}

	/** Removes the daily sweep (plugin deactivation). */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( self::SWEEP_HOOK, array(), Jobs::GROUP ); }
	}

	/**
	 * Paid renewal orders of the last 30 days that no renewal job finished: queues the renewal again (at most 20 a run).
	 * A job that is already waiting or running for the order is left alone.
	 *
	 * @return int How many renewals were queued.
	 */
	public static function sweep(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) { return 0; }
		$queued = 0;
		$since = time() - 30 * DAY_IN_SECONDS;
		foreach ( Resource::live_ids( 'domain' ) as $id ) {
			$r = Resource::find( $id );
			if ( ! $r || ! $r->is_live() || 'domain' !== $r->get( 'driver' ) ) { continue; }
			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $r->license()['subscription_id'] ?? 0 ) ) : false;
			if ( ! $sub ) { continue; }
			$done = array_map( 'strval', (array) $r->get( 'renewed_orders' ) );
			foreach ( (array) $sub->get_related_orders( 'ids', 'renewal' ) as $order_id ) {
				$order = wc_get_order( (int) $order_id );
				$created = $order ? $order->get_date_created() : null;
				if ( ! $order || ! $order->is_paid() || ! $created || $created->getTimestamp() < $since || in_array( (string) $order_id, $done, true ) ) { continue; }
				if ( self::act_open( $r->id(), (string) $order_id ) ) { continue; }
				Jobs::queue_act( $r->id(), 'renew', array( 'order' => (string) $order_id ) );
				if ( ++$queued >= self::SWEEP_LIMIT ) { return $queued; }
			}
		}
		return $queued;
	}

	/** Is a renew job for this order waiting or running? */
	private static function act_open( int $resource_id, string $order_id ): bool {
		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$found = as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'args' => array( $resource_id, 'renew', array( 'order' => $order_id ) ), 'group' => Jobs::GROUP, 'status' => $status, 'per_page' => 1 ), 'ids' );
			if ( $found ) { return true; }
		}
		return false;
	}

	public static function next_payment_at( string $expiry, ?int $now = null ): int {
		$now = $now ?? time();
		$expires = strtotime( $expiry . ' UTC' );
		if ( false === $expires ) { return 0; }
		$days = Settings::get( 'domain_renew_days_before' );
		$days = is_numeric( $days ) ? max( 1, min( 60, (int) $days ) ) : 30;
		return max( $now + HOUR_IN_SECONDS, $expires - $days * DAY_IN_SECONDS );
	}

	public static function set_dates( Resource $r ): StepResult {
		$license = $r->license();
		$subscription_id = (int) ( $license['subscription_id'] ?? 0 );
		if ( ! $subscription_id ) { return StepResult::done(); }
		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : false;
		if ( ! $sub ) { return StepResult::retry( 3600, 'The domain subscription is unavailable.' ); }
		$next = self::next_payment_at( (string) ( $r->get( 'prepaid_expiry' ) ?: $r->get( 'expires_at' ) ) );
		if ( ! $next ) { return StepResult::retry( 3600, 'The registrar expiry date is unavailable.' ); }
		try { $saved = $sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ), 'gmt' ); }
		catch ( \Exception $e ) { return StepResult::retry( 3600, 'The subscription billing date could not be set.' ); }
		return $saved ? StepResult::done( array( 'next_payment_at' => $next ) ) : StepResult::retry( 3600, 'The subscription billing date could not be set.' );
	}

	public static function on_paid( $subscription, $order ): void {
		if ( ! $subscription instanceof \WC_Subscription || ! $order instanceof \WC_Order || ! $order->is_paid() ) { return; }
		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$r = Resource::for_license( (int) $license['id'] );
			if ( $r && 'domain' === $r->get( 'driver' ) ) { Jobs::queue_act( $r->id(), 'renew', array( 'order' => (string) $order->get_id() ) ); }
		}
	}

	/** One scheduled event per prepaid transfer, queued when its registry renewal window opens. */
	public static function on_transfer_due( int $resource_id ): void {
		$r = Resource::find( $resource_id );
		if ( $r && $r->is_live() && 'domain' === $r->get( 'driver' ) && ! $r->get( 'transfer_renewed' ) ) {
			Jobs::queue_act( $resource_id, 'transfer_renew' );
		}
	}

	/** Buy the prepaid year after a transfer that did not itself add one. */
	public static function transfer_year( Resource $r ): StepResult {
		if ( $r->get( 'transfer_renewed' ) ) { return StepResult::done(); }
		$name = (string) $r->get( 'domain' );
		$before = (string) $r->get( 'transfer_expiry_before' );
		$baseline = (string) $r->get( 'transfer_renew_baseline' );
		$active_expiry = (string) $r->get( 'expires_at' );
		$cost = Prices::transfer_cost( $name );
		if ( null === $cost ) { return StepResult::retry( 3600, 'The transfer cost is unavailable.' ); }
		$needed = 0.0 === $cost || ( '' !== $before && ! self::later( $active_expiry, $before ) );
		if ( ! $needed ) { return StepResult::done(); }
		$id = (string) $r->get( 'remote_id' );
		if ( '' === $id ) { return StepResult::retry( 3600, 'The registrar id is unavailable.' ); }
		$baseline = '' === $baseline ? $active_expiry : $baseline;
		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );
		if ( is_wp_error( $detail ) ) { return StepResult::retry( 3600, 'The transfer renewal could not be checked.' ); }
		$expiry = (string) ( $detail['expiration_date'] ?? '' );
		if ( self::later( $expiry, $baseline ) ) {
			return StepResult::done( array( 'expires_at' => $expiry, 'transfer_renewed' => true, 'prepaid_expiry' => null ), 'Prepaid transfer year renewed' );
		}
		$expiry_time = strtotime( $baseline . ' UTC' );
		if ( str_ends_with( $name, '.dk' ) && false !== $expiry_time && time() < $expiry_time - 55 * DAY_IN_SECONDS ) {
			$when = $expiry_time - 55 * DAY_IN_SECONDS;
			if ( ! $r->get( 'transfer_year_scheduled' ) ) {
				$action_id = as_schedule_single_action( $when, self::TRANSFER_HOOK, array( $r->id() ), Jobs::GROUP );
				if ( ! $action_id ) { return StepResult::retry( 3600, 'The prepaid renewal could not be scheduled.' ); }
			}
			$prepaid = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $expiry_time ) );
			return StepResult::done( array( 'transfer_year_scheduled' => true, 'transfer_renew_baseline' => $baseline, 'prepaid_expiry' => $prepaid ), 'Prepaid transfer renewal scheduled' );
		}
		if ( $r->get( 'transfer_renew_started' ) ) { return StepResult::retry( 3600, 'The prepaid renewal is awaiting registry confirmation.' ); }
		// This year is bought on the transfer order's money: that line must cover the registrar's renewal cost.
		$blocked = SpendGuard::guard( $r, $name, 'renew', SpendGuard::item_of_resource( $r ) );
		if ( $blocked ) { return $blocked; }
		if ( ! $r->set( array( 'transfer_renew_started' => true, 'transfer_renew_baseline' => $baseline ) ) ) { return StepResult::retry( 3600, 'The prepaid renewal could not be recorded.' ); }
		$reply = Clients::registrar()->request( 'POST', '/domains/' . $id . '/renew', array( 'period' => 1 ) );
		if ( is_wp_error( $reply ) ) {
			// A refusal that reached the registrar means nothing was bought: free the marker so the next attempt asks again. A timeout or 5xx keeps it (it may have gone through).
			if ( ! self::refusal( $r, $reply ) ) { return StepResult::retry( 3600, 'The prepaid renewal needs staff attention.' ); }
			$r->set( array( 'transfer_renew_started' => false ) );
			return StepResult::retry( 3600, 'The prepaid renewal was refused and will be tried again.' );
		}
		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );
		if ( is_wp_error( $detail ) ) { return StepResult::retry( 3600, 'The prepaid renewal is awaiting registry confirmation.' ); }
		$expiry = (string) ( $detail['expiration_date'] ?? '' );
		return self::later( $expiry, $baseline ) ? StepResult::done( array( 'expires_at' => $expiry, 'transfer_renewed' => true, 'prepaid_expiry' => null ), 'Prepaid transfer year renewed' ) : StepResult::retry( 3600, 'The prepaid renewal is awaiting registry confirmation.' );
	}

	public static function renew( Resource $r, string $order_id ): StepResult {
		$order = ctype_digit( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		$sub_id = (int) ( $r->license()['subscription_id'] ?? 0 );
		$linked = $order && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ? wcs_get_subscriptions_for_renewal_order( $order ) : array();
		if ( ! $order || ! $order->is_paid() || ! $sub_id || ! isset( $linked[ $sub_id ] ) ) { return StepResult::fail( 'No paid renewal order belongs to this domain.' ); }
		$done = (array) $r->get( 'renewed_orders' );
		if ( in_array( $order_id, $done, true ) ) { return StepResult::done(); }
		$started = (array) $r->get( 'renew_started' );
		$baseline = (string) ( $started[ $order_id ] ?? $r->get( 'expires_at' ) );
		$id = (string) $r->get( 'remote_id' );
		if ( '' === $id ) { return StepResult::retry( 3600, 'The registrar id is unavailable.' ); }
		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );
		if ( is_wp_error( $detail ) ) { return StepResult::retry( 3600, 'The registrar renewal could not be checked.' ); }
		$expiry = (string) ( $detail['expiration_date'] ?? '' );
		if ( self::later( $expiry, $baseline ) ) { return self::finish_renewal( $r, $order_id, $expiry, $done, $started ); }
		if ( isset( $started[ $order_id ] ) ) { return StepResult::retry( 3600, 'A renewal request is awaiting registry confirmation.' ); }
		$blocked = SpendGuard::guard( $r, (string) $r->get( 'domain' ), 'renew', SpendGuard::item_of_order( $order, (string) $r->get( 'domain' ) ) );
		if ( $blocked ) { return $blocked; }
		$started[ $order_id ] = $baseline;
		if ( ! $r->set( array( 'renew_started' => $started ) ) ) { return StepResult::retry( 3600, 'The renewal could not be recorded before requesting it.' ); }
		$reply = Clients::registrar()->request( 'POST', '/domains/' . $id . '/renew', array( 'period' => 1 ) );
		if ( is_wp_error( $reply ) ) {
			// A refusal that reached the registrar means nothing was bought: free this order's marker so the next attempt asks again. A timeout or 5xx keeps it (it may have gone through).
			if ( ! self::refusal( $r, $reply ) ) { return StepResult::retry( 3600, 'The registrar renewal needs staff attention.' ); }
			unset( $started[ $order_id ] );
			$r->set( array( 'renew_started' => $started ) );
			return StepResult::retry( 3600, 'The registrar renewal was refused and will be tried again.' );
		}
		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );
		if ( is_wp_error( $detail ) ) { return StepResult::retry( 3600, 'The registrar renewal is awaiting confirmation.' ); }
		$expiry = (string) ( $detail['expiration_date'] ?? '' );
		return self::later( $expiry, $baseline ) ? self::finish_renewal( $r, $order_id, $expiry, $done, $started ) : StepResult::retry( 3600, 'A renewal request is awaiting registry confirmation.' );
	}

	/** Did the registrar answer and refuse (so nothing was bought)? Alerts the admin once when its balance is the reason. */
	private static function refusal( Resource $r, \WP_Error $error ): bool {
		if ( 'op_920' === $error->get_error_code() ) {
			Alerts::admin( $r, 'Registrar balance is too low.' );
			return true;
		}
		return ! LiveClient::is_transient( $error ) && 'credentials_missing' !== $error->get_error_code();
	}

	private static function finish_renewal( Resource $r, string $order_id, string $expiry, array $done, array $started ): StepResult {
		$done[] = $order_id;
		unset( $started[ $order_id ] );
		$r->set( array( 'expires_at' => $expiry ) );
		$dates = self::set_dates( $r );
		if ( ! $dates->is_done() ) { return $dates; }
		return StepResult::done( array_merge( $dates->meta(), array( 'renewed_orders' => $done, 'renew_started' => $started, 'expires_at' => $expiry ) ), 'Domain renewed after paid subscription order ' . $order_id );
	}

	public static function later( string $a, string $b ): bool {
		$first = strtotime( $a . ' UTC' );
		$second = strtotime( $b . ' UTC' );
		return false !== $first && false !== $second && $first > $second;
	}
}

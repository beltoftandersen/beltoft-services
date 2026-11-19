<?php
/** Where a domain stands towards its expiry: paid up, renewal due, expired and restorable, or ended. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One place that works out the stage, read by the notices, the admin panel and the account page. Pure reads: nothing here writes.
 *
 * - active:  the licence is active (the renewal is paid or not yet due), or the expiry is not known;
 * - due:     the licence is not active and the expiry is still ahead (the domain works until then);
 * - expired: the expiry has passed, the licence is not active and the restore window (if known) is still open;
 * - ended:   the window is over.
 * A domain whose window is not known stays expired.
 */
final class Stage {

	public const ACTIVE  = 'active';
	public const DUE     = 'due';
	public const EXPIRED = 'expired';
	public const ENDED   = 'ended';

	/**
	 * The stage of a domain resource.
	 *
	 * @param Resource $r   Domain resource.
	 * @param int|null $now Unix time, now by default.
	 * @return array{stage: string, expiry: int|null, window_end: int|null, window_days: int|null, days_to_expiry: int|null, days_after: int|null, unpaid: bool}
	 */
	public static function of( Resource $r, ?int $now = null ): array {
		$now     = $now ?? time();
		$expires = (string) $r->get( 'expires_at' );
		$expiry  = '' !== $expires ? strtotime( $expires . ' UTC' ) : false;
		$out     = array(
			'stage'          => self::ACTIVE,
			'expiry'         => $expiry ? (int) $expiry : null,
			'window_end'     => null,
			'window_days'    => null,
			'days_to_expiry' => null,
			'days_after'     => null,
			'unpaid'         => false,
		);

		if ( ! $expiry ) {
			return $out;
		}

		$days                  = RestoreFacts::window_days( (string) $r->get( 'domain' ) );
		$out['window_days']    = $days;
		$out['window_end']     = null === $days ? null : (int) $expiry + $days * DAY_IN_SECONDS;
		$out['days_to_expiry'] = (int) ceil( ( $expiry - $now ) / DAY_IN_SECONDS );
		$out['days_after']     = $now >= $expiry ? (int) floor( ( $now - $expiry ) / DAY_IN_SECONDS ) : null;

		if ( ! self::licence_lapsed( $r ) ) {
			return $out;
		}

		$out['unpaid'] = self::payment_failed( $r );

		if ( $now < $expiry ) {
			$out['stage'] = self::DUE;
		} elseif ( null === $out['window_end'] || $now < $out['window_end'] ) {
			$out['stage'] = self::EXPIRED;
		} else {
			$out['stage'] = self::ENDED;
		}

		return $out;
	}

	/**
	 * Is the domain's licence no longer active (suspended for a missing payment, expired, revoked)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function licence_lapsed( Resource $r ): bool {
		$license = $r->license();

		return ! empty( $license ) && Licenses::STATUS_ACTIVE !== Licenses::effective_status( $license );
	}

	/**
	 * Is the domain's subscription on hold, which is what an unpaid renewal does to it?
	 *
	 * @param Resource $r Resource.
	 */
	public static function payment_failed( Resource $r ): bool {
		$subscription = self::subscription( $r );

		return $subscription && $subscription->has_status( 'on-hold' );
	}

	/**
	 * The WooCommerce subscription of the domain, or null.
	 *
	 * @param Resource $r Resource.
	 */
	public static function subscription( Resource $r ): ?\WC_Subscription {
		$id = (int) ( $r->license()['subscription_id'] ?? 0 );

		if ( $id <= 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}

		$subscription = wcs_get_subscription( $id );

		return $subscription instanceof \WC_Subscription ? $subscription : null;
	}

	/**
	 * Where the customer pays the open renewal: the payment page of the unpaid renewal order, else the subscription in the account.
	 *
	 * @param Resource $r Resource.
	 */
	public static function pay_url( Resource $r ): string {
		$subscription = self::subscription( $r );

		if ( ! $subscription ) {
			return '';
		}

		$order = $subscription->get_last_order( 'all', 'renewal' );

		if ( $order instanceof \WC_Order && $order->needs_payment() ) {
			return (string) $order->get_checkout_payment_url();
		}

		return (string) $subscription->get_view_order_url();
	}
}

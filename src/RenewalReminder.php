<?php
/**
 * A reminder before a long renewal of a service subscription.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Emails\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions has one customer renewal reminder for the whole shop (a few days before). A product can
 * ask for an earlier, longer reminder of its own: the Service tab's "Renewal reminder, days before" (empty = off). It
 * is a customer email (WooCommerce > Emails, "Renewal reminder") sent by an Action Scheduler job that is scheduled
 * from the subscription's next payment date and made again when that date changes.
 *
 * - Only an active subscription is reminded (not pending, on hold, pending cancellation or cancelled).
 * - Only a renewal that is further away than the offset (a yearly renewal with 30 days: yes; a monthly one: no).
 * - Once per renewal: the next payment time that was told is kept on the subscription, so a rescheduling or a repeated
 *   job does not send it again. A subscription whose date moves gets a new reminder for the new date.
 * - What it says: the plan, the renewal date, the full renewal price as WooCommerce Subscriptions shows it (VAT
 *   included where the shop shows prices with VAT), and how to cancel from My Account.
 */
final class RenewalReminder {

	public const META      = '_beltoft_services_renewal_notice_days';
	public const SENT_META = '_beltoft_services_renewal_notice_sent';
	public const HOOK      = 'beltoft_services_renewal_reminder';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_subscription_date_updated', array( __CLASS__, 'on_date' ), 10, 2 );
		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'on_status' ), 10, 1 );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * The offset in days a product asks for, 0 when none.
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function days( int $product_id ): int {
		$raw = get_post_meta( Product::parent_id( $product_id ), self::META, true );

		return is_scalar( $raw ) && ctype_digit( (string) $raw ) ? min( 365, (int) $raw ) : 0;
	}

	/**
	 * The product setting as it is saved from the editor: a whole number of days, or '' for none.
	 *
	 * @param string $raw Raw value.
	 */
	public static function clean_days( string $raw ): string {
		$raw = trim( $raw );

		return ctype_digit( $raw ) && (int) $raw > 0 ? (string) min( 365, (int) $raw ) : '';
	}

	/**
	 * The offset of a subscription: the largest of its service products', 0 when none asks.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private static function offset( $subscription ): int {
		$days = 0;

		foreach ( $subscription->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Product::is_service( (int) $item->get_product_id() ) ) {
				$days = max( $days, self::days( (int) $item->get_product_id() ) );
			}
		}

		return $days;
	}

	/**
	 * Seconds in a billing period (a month counts 30 days, a year 365).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private static function period_seconds( $subscription ): int {
		$unit = array( 'day' => 1, 'week' => 7, 'month' => 30, 'year' => 365 )[ (string) $subscription->get_billing_period() ] ?? 30;

		return max( 1, (int) $subscription->get_billing_interval() ) * $unit * DAY_IN_SECONDS;
	}

	/**
	 * Action woocommerce_subscription_date_updated: the next payment moved.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function on_date( $subscription ): void {
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) {
			self::schedule( $subscription );
		}
	}

	/**
	 * Action woocommerce_subscription_status_updated: scheduled while active, taken away otherwise.
	 *
	 * @param mixed $subscription Subscription.
	 */
	public static function on_status( $subscription ): void {
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ) {
			self::schedule( $subscription );
		}
	}

	/**
	 * Schedules the one reminder of the subscription's next renewal, replacing an earlier schedule.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @return int|null The time it will be sent, null when none is scheduled.
	 */
	public static function schedule( $subscription ): ?int {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return null;
		}

		$id = (int) $subscription->get_id();
		as_unschedule_all_actions( self::HOOK, array( $id ), Jobs::GROUP );

		$at = self::due_time( $subscription );

		if ( null === $at ) {
			return null;
		}

		as_schedule_single_action( $at, self::HOOK, array( $id ), Jobs::GROUP );

		return $at;
	}

	/**
	 * When the reminder of the next renewal is due, or null when there is none to send.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	private static function due_time( $subscription ): ?int {
		$days = self::offset( $subscription );

		if ( $days <= 0 || 'active' !== $subscription->get_status() ) {
			return null;
		}

		$next = (int) $subscription->get_time( 'next_payment' );
		$now  = Jobs::now();

		if ( $next <= $now || $days * DAY_IN_SECONDS >= self::period_seconds( $subscription ) || (int) $subscription->get_meta( self::SENT_META ) === $next ) {
			return null;
		}

		$at = $next - $days * DAY_IN_SECONDS;

		// Already inside the window (the subscription began or moved late): soon, but only when there is still time to act.
		return $at > $now ? $at : ( $next - $now > DAY_IN_SECONDS ? $now + MINUTE_IN_SECONDS : null );
	}

	/**
	 * The job: sends the reminder when it is still right to.
	 *
	 * @param mixed $subscription_id Subscription id.
	 * @return bool Whether an email was sent.
	 */
	public static function run( $subscription_id ): bool {
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) $subscription_id ) : false;

		if ( ! $subscription ) {
			return false;
		}

		$at = self::due_time( $subscription );

		// Nothing to send now (cancelled, told already, date gone): done. Not yet due (the date moved later): scheduled again.
		if ( null === $at ) {
			return false;
		}

		if ( $at > Jobs::now() + MINUTE_IN_SECONDS ) {
			self::schedule( $subscription );

			return false;
		}

		$next = (int) $subscription->get_time( 'next_payment' );
		$sent = false;

		// The price is written in the currency of the subscription, whatever this job runs in. With no way to put that currency in force the
		// mail is not sent (a price in the wrong currency is worse than a late reminder) and the admin is told once per currency.
		list( $ok, $price_html ) = Currency::with( Currency::of_order( $subscription ), static fn() => (string) $subscription->get_formatted_order_total() );

		if ( ! $ok ) {
			Alerts::notify(
				/* translators: %s: currency code */
				sprintf( __( 'Renewal reminder not sent in %s', 'beltoft-services' ), Currency::of_order( $subscription ) ),
				array( sprintf( 'The renewal reminder of subscription %1$d was not sent: its price is in %2$s and that currency could not be put in force.', $subscription->get_id(), Currency::of_order( $subscription ) ) ),
				null,
				'beltoft_services_reminder_currency_' . strtolower( Currency::of_order( $subscription ) )
			);

			return false;
		}

		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || ! Product::is_service( (int) ( $license['product_id'] ?? 0 ) ) ) {
				continue;
			}

			$names = array();

			foreach ( $subscription->get_items() as $item ) {
				$names[] = (string) $item->get_name();
			}

			$sent = Emails::send(
				'beltoft_services_renewal_reminder',
				$resource,
				array(
					'renewal' => array(
						'date'       => wp_date( 'j F Y', $next ),
						'plan'       => implode( ', ', array_filter( $names ) ),
						'price_html' => (string) $price_html,
						'view_url'   => (string) $subscription->get_view_order_url(),
					),
				)
			);

			break; // One email for the subscription.
		}

		if ( $sent ) {
			$subscription->update_meta_data( self::SENT_META, $next );
			$subscription->save();
		}

		return $sent;
	}
}

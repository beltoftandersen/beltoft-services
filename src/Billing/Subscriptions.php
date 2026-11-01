<?php
/**
 * Cancelling a subscription or an add-on, and the words about it: one place for the dashboard, REST and the MCP tools.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Billing;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a customer may cancel, what will happen if they do, and the cancelling itself, which is always
 * WooCommerce Subscriptions' own customer status change (WCS_User_Change_Status_Handler::change_users_subscription(),
 * what the Cancel button of the subscription page runs). The callers add their own trail (order note, history).
 * The functions need no session except cancel() with $quiet, which reads the notices WooCommerce made.
 */
final class Subscriptions {

	/**
	 * Add-on kinds (the driver's kind) that may be removed on their own.
	 */
	public const ADDON_KINDS = array( 'disk', 'snapshot', 'applic' );

	/**
	 * The customer's own subscription.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Subscription id.
	 * @return \WC_Subscription|\WP_Error
	 */
	public static function owned( int $user_id, int $id ) {
		$s = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : null;

		if ( ! $s instanceof \WC_Subscription || $user_id <= 0 || (int) $s->get_user_id() !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This subscription is not available.', 'beltoft-services' ) );
		}

		return $s;
	}

	/**
	 * The services a subscription pays for, as the customer's own.
	 *
	 * @param \WC_Subscription $s       Subscription.
	 * @param int              $user_id Customer.
	 * @return Resource[]
	 */
	public static function services_of( \WC_Subscription $s, int $user_id ): array {
		$out = array();

		foreach ( Licenses::where( 'subscription_id', $s->get_id() ) as $license ) {
			$r = (int) ( $license['user_id'] ?? 0 ) === $user_id ? Resource::for_license( (int) $license['id'] ) : null;

			if ( $r && 'deleted' !== $r->get( 'state' ) ) {
				$out[] = $r;
			}
		}

		return $out;
	}

	/**
	 * The price, period and VAT of a subscription for the customer.
	 *
	 * @param \WC_Subscription $s Subscription.
	 */
	public static function price_line( \WC_Subscription $s ): string {
		$total    = (float) $s->get_total();
		$currency = (string) $s->get_currency();
		$every    = (int) $s->get_billing_interval();
		// WooCommerce Subscriptions words it with the number when it is more than one ("2 months") and without when it is one ("month").
		$unit     = function_exists( 'wcs_get_subscription_period_strings' ) ? (string) wcs_get_subscription_period_strings( $every, (string) $s->get_billing_period() ) : $every . ' ' . (string) $s->get_billing_period();
		/* translators: %s: period such as month or 2 months. */
		$period   = sprintf( __( 'every %s', 'beltoft-services' ), $unit );

		if ( $total <= 0 ) {
			return __( 'It is free.', 'beltoft-services' );
		}

		$tax = (float) $s->get_total_tax();

		return sprintf(
			/* translators: 1: price, 2: period, 3: VAT note. */
			__( 'It costs %1$s %2$s, %3$s.', 'beltoft-services' ),
			Rates::money( $total, 2, $currency ),
			$period,
			$tax > 0
				/* translators: %s: VAT amount. */
				? sprintf( __( 'VAT included (%s)', 'beltoft-services' ), Rates::money( $tax, 2, $currency ) )
				: __( 'no VAT is charged', 'beltoft-services' )
		);
	}

	/**
	 * When an active subscription would end if it were cancelled now: the end of the paid period.
	 *
	 * @param \WC_Subscription $s Subscription.
	 * @return string The end date in the site's format, or '' when it ends at once.
	 */
	public static function ends_on( \WC_Subscription $s ): string {
		$end = $s->has_status( 'active' ) ? (string) $s->calculate_date( 'end_of_prepaid_term' ) : '';
		$at  = '' !== $end && '0' !== $end ? strtotime( $end . ' UTC' ) : false;

		return $at && $at > time() ? wp_date( (string) get_option( 'date_format' ), $at ) : '';
	}

	/**
	 * Does WooCommerce Subscriptions let this customer cancel this subscription? The dashboard's button (its action
	 * list), the status machine and the customer's capability must all agree: the strictest of the three counts.
	 *
	 * @param \WC_Subscription $s       Subscription.
	 * @param int              $user_id Customer.
	 */
	public static function wcs_allows_cancel( \WC_Subscription $s, int $user_id ): bool {
		return isset( wcs_get_all_user_actions_for_subscription( $s, $user_id )['cancel'] )
			&& $s->can_be_updated_to( 'cancelled' )
			&& user_can( $user_id, 'edit_shop_subscription_status', $s->get_id() );
	}

	/**
	 * Why a subscription cannot be cancelled by the customer, or '' when it can. A registered domain is never
	 * cancelled this way.
	 *
	 * @param \WC_Subscription $s        Subscription.
	 * @param int              $user_id  Customer.
	 * @param Resource[]       $services What it pays for.
	 */
	public static function cancel_blocker( \WC_Subscription $s, int $user_id, array $services ): string {
		foreach ( $services as $r ) {
			if ( 'domain' === (string) $r->get( 'driver' ) ) {
				return __( 'A registered domain is not cancelled here. Turn automatic renewal off instead (set_auto_renew), or the customer contacts support.', 'beltoft-services' );
			}
		}

		if ( ! self::wcs_allows_cancel( $s, $user_id ) ) {
			return __( 'The dashboard does not offer cancelling for this subscription.', 'beltoft-services' );
		}

		return '';
	}

	/**
	 * The customer's subscription with the services it pays for, when it can be cancelled.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Subscription id.
	 * @return array{0: \WC_Subscription, 1: Resource[]}|\WP_Error
	 */
	public static function cancellable( int $user_id, int $id ) {
		$s = self::owned( $user_id, $id );

		if ( $s instanceof \WP_Error ) {
			return $s;
		}

		$services = self::services_of( $s, $user_id );
		$why      = self::cancel_blocker( $s, $user_id, $services );

		return '' !== $why ? new \WP_Error( 'beltoft_services_tool', $why ) : array( $s, $services );
	}

	/**
	 * What happens when a subscription is cancelled: price, end date, nothing refunded, what stops.
	 *
	 * @param \WC_Subscription $s        Subscription.
	 * @param Resource[]       $services What it pays for.
	 * @param string[]         $names    The customer's names for the services, in the same order.
	 * @return array{text: string, state: string}
	 */
	public static function cancel_text( \WC_Subscription $s, array $services, array $names ): array {
		$end  = self::ends_on( $s );
		$text = sprintf(
			/* translators: 1: names of the services, 2: price sentence. */
			__( 'Cancel the subscription of %1$s. %2$s', 'beltoft-services' ),
			$names ? implode( ', ', $names ) : __( 'this subscription', 'beltoft-services' ),
			self::price_line( $s )
		);

		$text .= ' ' . ( '' !== $end
			/* translators: %s: date. */
			? sprintf( __( 'It is paid until %s: the service keeps working until then and nothing is charged again after that.', 'beltoft-services' ), $end )
			: __( 'It ends now and nothing is charged again.', 'beltoft-services' ) );

		$days  = 0;
		$kinds = array();

		foreach ( $services as $r ) {
			$days    = max( $days, Product::retention_days( (int) ( $r->license()['product_id'] ?? 0 ) ) );
			$driver  = Registry::get( (string) $r->get( 'driver' ) );
			$kinds[] = $driver ? $driver->kind() : '';
		}

		if ( in_array( 'vm', $kinds, true ) || in_array( 'db', $kinds, true ) ) {
			$text .= ' ' . ( $days > 0 && 'on' === Settings::get( 'deletion_mode' )
				/* translators: %d: number of days. */
				? sprintf( __( 'When it has ended, the server or database stops, and its data is deleted %d days later. It cannot be brought back after that.', 'beltoft-services' ), $days )
				: __( 'When it has ended, the server or database stops. Its data is not kept for you after it is removed.', 'beltoft-services' ) );
		}

		if ( in_array( 'disk', $kinds, true ) ) {
			$text .= ' ' . __( 'The extra disk and its data are removed, and snapshots that include it are deleted too.', 'beltoft-services' );
		}

		if ( in_array( 'snapshot', $kinds, true ) ) {
			$text .= ' ' . __( 'The snapshot slots are removed, and snapshots that no longer fit in the remaining slots are deleted.', 'beltoft-services' );
		}

		if ( in_array( 'applic', $kinds, true ) ) {
			$text .= ' ' . __( 'The app licence ends, and the app on the server may stop working without it.', 'beltoft-services' );
		}

		$ids = array_map( static fn( $r ) => $r->id(), $services );
		sort( $ids );

		return array( 'text' => $text, 'state' => $s->get_id() . '|' . $s->get_status() . '|' . $end . '|' . implode( ',', $ids ) );
	}

	/**
	 * Is this service an add-on that may be removed on its own (a disk, snapshot slots or an app licence)?
	 *
	 * @param Resource $r Service.
	 */
	public static function addon_blocker( Resource $r ): string {
		$driver = Registry::get( (string) $r->get( 'driver' ) );

		return $driver && in_array( $driver->kind(), self::ADDON_KINDS, true ) ? '' : __( 'This is not an add-on. Use cancel_subscription to cancel a service.', 'beltoft-services' );
	}

	/**
	 * Cancels a subscription with WooCommerce Subscriptions' customer status change. The caller has checked
	 * cancellable().
	 *
	 * @param \WC_Subscription $s     Subscription.
	 * @param callable|null    $quiet Runs a callback and returns what WooCommerce said (Mcp\Notices::capture), for callers without a browser session; null lets WooCommerce add its notice for the customer.
	 * @return array{ok: bool, errors: string[], messages: string[], subscription: \WC_Subscription|null, pending: bool, ends: string}
	 */
	public static function cancel( \WC_Subscription $s, ?callable $quiet = null ): array {
		$id    = $s->get_id();
		$said  = array( 'errors' => array(), 'messages' => array() );
		$apply = static fn() => \WCS_User_Change_Status_Handler::change_users_subscription( $s, 'cancelled' );

		if ( null !== $quiet ) {
			$said = $quiet( $apply );
		} else {
			$apply();
		}

		$now     = wcs_get_subscription( $id );
		$ok      = $now instanceof \WC_Subscription && $now->has_status( array( 'cancelled', 'pending-cancel' ) );
		$pending = $ok && $now->has_status( 'pending-cancel' );
		$end     = $pending ? (string) $now->get_date( 'end', 'site' ) : '';
		$at      = '' !== $end && '0' !== $end ? strtotime( $end ) : false;

		return array(
			'ok'           => $ok,
			'errors'       => $said['errors'],
			'messages'     => $said['messages'],
			'subscription' => $now instanceof \WC_Subscription ? $now : null,
			'pending'      => $pending,
			'ends'         => $at ? wp_date( 'Y-m-d', $at ) : '',
		);
	}

	/**
	 * Cancels an unpaid order, as the Cancel link of the order page does, for the customer's own order only. Stricter
	 * than the link: an order that started a live subscription is not cancelled here, because that would cancel the
	 * subscription.
	 *
	 * @param int    $user_id  Customer.
	 * @param int    $order_id Order id.
	 * @param string $note     Order note.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function cancel_order( int $user_id, int $order_id, string $note ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || $user_id <= 0 || (int) $order->get_customer_id() !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This order is not available.', 'beltoft-services' ) );
		}

		/** This filter is documented in WooCommerce, class-wc-form-handler.php. */
		$valid = apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order );

		if ( ! $order->has_status( $valid ) || ! current_user_can( 'cancel_order', $order_id ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This order can no longer be cancelled.', 'beltoft-services' ) );
		}

		// WooCommerce Subscriptions cancels the subscriptions of a parent order with it: that is cancelling a service.
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'parent' ) ) {
			foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
				if ( ! $subscription->has_status( array( 'cancelled', 'expired', 'trash' ) ) ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'This order started a subscription, so cancelling it would cancel the subscription. The customer does that in the dashboard.', 'beltoft-services' ) );
				}
			}
		}

		$order->update_status( 'cancelled', $note );

		do_action( 'woocommerce_cancelled_order', $order->get_id() );

		return array( 'order_id' => $order_id, 'status' => 'cancelled' );
	}
}

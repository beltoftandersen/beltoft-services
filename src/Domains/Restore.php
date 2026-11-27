<?php
/** Pay-to-restore: the restore order of an expired domain and the job that restores it at the registrar. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftLicenses\Licenses;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Alerts;
use BeltoftServices\Currency;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 2026-11-19-unpaid-domains.md sections 3.4 and 3.5, with the owner decision of 2026-11-27: Openprovider's restore semantics are not
 * known, so the most expensive case is assumed. The restore order always charges the normal renewal of one year AND the reactivation fee
 * (the full restore cost times the markup), even while the registrar might restore free.
 *
 * Everything is behind the setting domain_restore_enabled (off by default).
 *
 * The order: made from the domain page when the stage is `expired`, one WooCommerce order with two fee lines (the renewal year and the
 * reactivation fee), paid on WooCommerce's own payment page. Fee lines, not product lines: nothing here can provision a service, and no
 * licence is made for the order. The order carries the resource, the domain and the baseline renewal date.
 *
 * The job (internal action `restore`, queued when the order is paid, under the resource lock and the domain lock):
 *  1. the order is paid, belongs to this resource and is not in `restored_orders`;
 *  2. .dk stops here with a staff alert, no registrar write (Openprovider has no API restore for .dk yet);
 *  3. GET the domain: already ACT with a later renewal date than the baseline means done, nothing is called;
 *  4. the window is over or the domain is deleted: no call, staff decide on a refund;
 *  5. SpendGuard::guard_restore() (the paid lines must cover renewal cost plus restore cost);
 *  6. marker `restore_started` BEFORE the POST, kept on a timeout or 5xx, freed on a definitive refusal;
 *  7. POST /domains/{id}/restore, then `restore_posted`;
 *  8. fresh GET: only if the date did NOT move, POST /domains/{id}/renew with period 1 (its own marker `restore_renew_started`);
 *  9. name servers are put back only when they differ; never a DELETE.
 */
final class Restore {

	public const ACTION  = 'restore';
	public const SETTING = 'domain_restore_enabled';

	/** Order meta: array resource, domain, baseline, renewal, fee, currency. */
	public const ORDER_META = '_beltoft_services_domain_restore';

	/** Fee line meta: the role of the line, `renewal` or `fee`. */
	public const LINE_META = '_beltoft_services_restore_line';

	/** Posted field of the Restore button. */
	public const FIELD = 'beltoft_domain_restore';

	/** Seconds after a restore POST during which the registry may still show the old date: the separate renew waits this long (registry lag, plan 3.5 step 8). */
	public const RENEW_WAIT = 6 * HOUR_IN_SECONDS;

	/** What a customer reads while a paid restore waits for staff. */
	public const WAITING = 'Your restore payment is received. Restoring the domain needs our staff. We have been told.';

	/** Is pay-to-restore on? */
	public static function enabled(): bool {
		return (bool) Settings::get( self::SETTING );
	}

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_domain_restore_url', array( __CLASS__, 'restore_url' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 20 );
		add_action( Renewals::SWEEP_HOOK, array( __CLASS__, 'sweep' ), 20 );
	}

	/**
	 * What the restore order of a domain costs, or null when it cannot be offered now: the feature is off, the domain is not an expired
	 * registration of ours, a price is unknown, or a paid restore is still being carried out.
	 *
	 * @param Resource $r Domain resource.
	 * @return array{renewal: float, fee: float, currency: string, total: float, year_order: int}|null
	 */
	public static function offer( Resource $r ): ?array {
		if ( ! self::enabled() || 'domain' !== $r->get( 'driver' ) || ! $r->is_live() || Stage::EXPIRED !== Stage::of( $r )['stage'] || null !== self::paid_open( $r ) ) {
			return null;
		}

		$amounts = self::amounts( $r );

		return is_array( $amounts ) ? $amounts : null;
	}

	/**
	 * The two amounts of the order, excluding tax, in the customer's currency: the normal yearly price and the reactivation fee.
	 *
	 * @param Resource $r Domain resource.
	 * @return array{renewal: float, fee: float, currency: string, total: float, year_order: int}|null
	 */
	public static function amounts( Resource $r ): ?array {
		$domain   = (string) $r->get( 'domain' );
		$currency = Currency::of_resource( $r );
		$tld      = RestoreFacts::row( $domain )[0];
		$price    = '' === $tld ? null : Prices::price( $tld, $currency );
		$base_fee = ReactivationFee::fee( $domain );

		if ( null === $price || null === $base_fee ) {
			return null;
		}

		$fee = ReactivationFee::amount( $base_fee, $currency, $domain );

		if ( null === $fee ) {
			return null;
		}

		// A renewal paid after the expiry counts toward the restore: the customer pays the year once.
		$year    = self::year_paid( $r );
		$renewal = null === $year ? (float) $price : 0.0;

		return array(
			'renewal'    => $renewal,
			'fee'        => $fee,
			'currency'   => $currency,
			'total'      => round( $renewal + $fee, 2 ),
			'year_order' => null === $year ? 0 : $year,
		);
	}

	/**
	 * The late renewal order whose year counts toward the restore of the domain's current expiry, or null.
	 *
	 * @param Resource $r Domain resource.
	 */
	public static function year_paid( Resource $r ): ?int {
		$paid = $r->get( 'restore_year_paid' );

		return is_array( $paid ) && (int) ( $paid['order'] ?? 0 ) > 0 && (string) ( $paid['baseline'] ?? '' ) === (string) $r->get( 'expires_at' ) ? (int) $paid['order'] : null;
	}

	/**
	 * The paid restore order of this domain that no job has finished yet, or null.
	 *
	 * @param Resource $r Domain resource.
	 */
	public static function paid_open( Resource $r ): ?\WC_Order {
		$id = (int) $r->get( 'restore_order' );

		if ( $id <= 0 || in_array( (string) $id, array_map( 'strval', (array) $r->get( 'restored_orders' ) ), true ) ) {
			return null;
		}

		$order = wc_get_order( $id );

		return $order instanceof \WC_Order && $order->is_paid() ? $order : null;
	}

	/**
	 * The address the restore button and the notices point at: the domain's page in My Account.
	 *
	 * @param mixed $url Address so far.
	 * @param mixed $r   Resource.
	 * @return mixed
	 */
	public static function restore_url( $url, $r ) {
		if ( ! $r instanceof Resource || '' !== (string) $url || null === self::offer( $r ) ) {
			return $url;
		}

		$license = $r->license();

		return $license ? ServiceArea::get( Domains::class )->url( $license ) : $url;
	}

	/** The nonce action of a license's restore form. @param int $license_id License id. */
	public static function nonce_action( int $license_id ): string {
		return 'beltoft-services-domain-restore-' . $license_id;
	}

	/** Listener of template_redirect: the posted Restore button. */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below, per license.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::FIELD ] ) || ! is_user_logged_in() ) {
			return;
		}

		$license_id = isset( $_POST['license'] ) ? absint( wp_unslash( $_POST['license'] ) ) : 0;
		$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $license_id <= 0 || ! wp_verify_nonce( $nonce, self::nonce_action( $license_id ) ) ) {
			wc_add_notice( __( 'This page expired. Reload it and try again.', 'beltoft-services' ), 'error' );
			wp_safe_redirect( ServiceArea::get( Domains::class )->url_list() );
			exit;
		}

		$result = self::process( get_current_user_id(), $license_id );

		if ( '' !== $result['message'] ) {
			wc_add_notice( $result['message'], 'error' );
		}

		wp_safe_redirect( $result['redirect'] );
		exit;
	}

	/**
	 * Makes (or finds) the restore order for a customer's domain.
	 *
	 * @param int $user_id    Customer.
	 * @param int $license_id License of the domain.
	 * @return array{ok: bool, message: string, redirect: string} Redirect is the payment page when ok.
	 */
	public static function process( int $user_id, int $license_id ): array {
		$license  = Licenses::find( $license_id );
		$resource = $license ? Resource::for_license( (int) $license['id'] ) : null;
		$area     = ServiceArea::get( Domains::class );

		if ( ! $license || ! $resource || ! $area->is_member( $license ) || (int) ( $license['user_id'] ?? 0 ) !== $user_id ) {
			return array( 'ok' => false, 'message' => __( 'That domain was not found.', 'beltoft-services' ), 'redirect' => $area->url_list() );
		}

		$back = $area->url( $license );

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:domain-restore', 10, MINUTE_IN_SECONDS ) ) {
			return array( 'ok' => false, 'message' => __( 'Too many requests. Wait a minute and try again.', 'beltoft-services' ), 'redirect' => $back );
		}

		$order = self::create_order( $resource, $user_id );

		if ( is_wp_error( $order ) ) {
			return array( 'ok' => false, 'message' => $order->get_error_message(), 'redirect' => $back );
		}

		return array( 'ok' => true, 'message' => '', 'redirect' => (string) $order->get_checkout_payment_url() );
	}

	/**
	 * The restore order of an expired domain: two fee lines on one pending order. An unpaid order made earlier for the same state is used
	 * again (a second click, or a customer who left the payment page); one that no longer fits is cancelled.
	 *
	 * @param Resource $r       Domain resource.
	 * @param int      $user_id Customer.
	 * @return \WC_Order|\WP_Error
	 */
	public static function create_order( Resource $r, int $user_id ) {
		$not_available = new \WP_Error( 'restore_unavailable', __( 'This domain cannot be restored from here. Contact us.', 'beltoft-services' ), array( 'status' => 409 ) );
		$amounts       = self::offer( $r );
		$license       = $r->license();

		if ( null === $amounts || ! $license || (int) ( $license['user_id'] ?? 0 ) !== $user_id || ! function_exists( 'wc_create_order' ) ) {
			return $not_available;
		}

		$domain   = (string) $r->get( 'domain' );
		$baseline = (string) $r->get( 'expires_at' );
		$problem  = SpendGuard::problem_restore_amounts( $domain, $amounts['renewal'] + $amounts['fee'], $amounts['currency'], $amounts['year_order'] > 0 );

		if ( null !== $problem ) {
			Alerts::admin( $r, $problem . ' No restore order was made for ' . $domain . '.' );

			return $not_available;
		}

		$earlier = (int) $r->get( 'restore_order' ) > 0 ? wc_get_order( (int) $r->get( 'restore_order' ) ) : false;

		if ( $earlier instanceof \WC_Order && ! $earlier->is_paid() && $earlier->has_status( array( 'pending', 'failed' ) ) ) {
			$meta = $earlier->get_meta( self::ORDER_META );

			if ( is_array( $meta ) && (string) ( $meta['baseline'] ?? '' ) === $baseline && abs( (float) ( $meta['renewal'] ?? 0 ) - $amounts['renewal'] ) < 0.005 && abs( (float) ( $meta['fee'] ?? 0 ) - $amounts['fee'] ) < 0.005 && (int) ( $meta['year_order'] ?? 0 ) === $amounts['year_order'] && (string) ( $meta['currency'] ?? '' ) === $amounts['currency'] ) {
				return $earlier;
			}

			$earlier->update_status( 'cancelled', __( 'Replaced by a new restore order for this domain.', 'beltoft-services' ) );
		}

		$order = wc_create_order( array( 'customer_id' => $user_id, 'status' => 'pending', 'created_via' => 'beltoft-services-restore' ) );

		if ( ! $order instanceof \WC_Order ) {
			return $not_available;
		}

		$order->set_currency( $amounts['currency'] );
		$subscription = Stage::subscription( $r );
		$billing      = $subscription ? (array) $subscription->get_address( 'billing' ) : array();

		if ( $billing ) {
			$order->set_address( $billing, 'billing' );
		}

		$product   = Cart::domain_product();
		$tax_class = $product ? $product->get_tax_class() : '';
		$shown     = HostName::display( $domain );
		$lines     = array();

		if ( $amounts['renewal'] > 0.0 ) {
			/* translators: %s: domain name. */
			$lines['renewal'] = array( sprintf( __( 'Renewal of %s for one year', 'beltoft-services' ), $shown ), $amounts['renewal'] );
		}

		if ( $amounts['fee'] > 0.0 ) {
			/* translators: %s: domain name. */
			$lines['fee'] = array( sprintf( __( 'Reactivation fee for %s', 'beltoft-services' ), $shown ), $amounts['fee'] );
		}

		foreach ( $lines as $role => $line ) {
			$item = new \WC_Order_Item_Fee();
			$item->set_name( $line[0] );
			$item->set_amount( $line[1] );
			$item->set_total( $line[1] );
			$item->set_tax_status( 'taxable' );
			$item->set_tax_class( $tax_class );
			$item->add_meta_data( self::LINE_META, $role, true );
			$order->add_item( $item );
		}

		$order->update_meta_data(
			self::ORDER_META,
			array(
				'resource'  => $r->id(),
				'domain'    => strtolower( $domain ),
				'baseline'  => $baseline,
				'renewal'   => $amounts['renewal'],
				'fee'       => $amounts['fee'],
				'currency'  => $amounts['currency'],
				'year_order' => $amounts['year_order'],
			)
		);
		$order->calculate_totals( true );
		$order->add_order_note( sprintf( 'Restore order for %s (expired %s): renewal and reactivation fee.', $domain, $baseline ) );
		$order->save();
		$r->set( array( 'restore_order' => $order->get_id() ) );

		return $order;
	}

	/**
	 * A restore order was paid: queues the restore job for its domain, once.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function on_paid( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
			return;
		}

		$meta = $order->get_meta( self::ORDER_META );
		$r    = is_array( $meta ) ? Resource::find( (int) ( $meta['resource'] ?? 0 ) ) : null;

		if ( ! $r || 'domain' !== $r->get( 'driver' ) || in_array( (string) $order->get_id(), array_map( 'strval', (array) $r->get( 'restored_orders' ) ), true ) || self::act_open( $r->id(), (string) $order->get_id() ) ) {
			return;
		}

		Jobs::queue_act( $r->id(), self::ACTION, array( 'order' => (string) $order->get_id() ) );
	}

	/**
	 * Paid restore orders of the last 30 days that no job finished: queues them again (at most 20 a run).
	 *
	 * @return int How many were queued.
	 */
	public static function sweep(): int {
		if ( ! self::enabled() || ! function_exists( 'wc_get_orders' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$queued = 0;
		$orders = wc_get_orders(
			array(
				'status'       => array( 'processing', 'completed' ),
				'created_via'  => 'beltoft-services-restore',
				'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
				'limit'        => 20,
				'return'       => 'ids',
			)
		);

		foreach ( $orders as $id ) {
			$order  = wc_get_order( (int) $id );
			$meta   = $order ? $order->get_meta( self::ORDER_META ) : null;
			$r      = is_array( $meta ) ? Resource::find( (int) ( $meta['resource'] ?? 0 ) ) : null;

			if ( $r && ! in_array( (string) $id, array_map( 'strval', (array) $r->get( 'restored_orders' ) ), true ) && ! self::act_open( $r->id(), (string) $id ) ) {
				Jobs::queue_act( $r->id(), self::ACTION, array( 'order' => (string) $id ) );
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Is a restore job for this order waiting or running?
	 *
	 * @param int    $resource_id Resource.
	 * @param string $order_id    Restore order.
	 */
	private static function act_open( int $resource_id, string $order_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			if ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'args' => array( $resource_id, self::ACTION, array( 'order' => $order_id ) ), 'group' => Jobs::GROUP, 'status' => $status, 'per_page' => 1 ), 'ids' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Has the registrar's renewal date passed? Then the registrar has cancelled the domain and a plain renew is not the way back.
	 *
	 * @param string $expiry The registrar's renewal date, UTC.
	 */
	public static function expired( string $expiry ): bool {
		$time = '' === trim( $expiry ) ? false : strtotime( $expiry . ' UTC' );

		return false !== $time && time() >= $time;
	}

	/**
	 * A paid NORMAL renewal order that arrives after the expiry (plan finding 9). The registrar's restore is a separate, dearer purchase, so
	 * nothing is bought on it. Owner decision 2026-11-27: the customer never pays the year twice, so this order is recorded as the paid year of
	 * the restore of this expiry (meta restore_year_paid). The restore order then has the reactivation fee alone, and the restore job counts
	 * this order as the year. An unpaid two-line restore order is cancelled so the customer is offered the fee-only one. When a two-line
	 * restore order is already paid, or another late renewal already counts, this order buys nothing and staff are told to refund it (the only
	 * refund case left).
	 *
	 * @param Resource  $r        Domain.
	 * @param \WC_Order $order    The paid renewal order.
	 * @param string    $order_id Its id.
	 */
	public static function late_renewal( Resource $r, \WC_Order $order, string $order_id ): StepResult {
		$domain   = (string) $r->get( 'domain' );
		$baseline = (string) $r->get( 'expires_at' );
		$counted  = self::year_paid( $r );
		$refund   = null !== self::paid_open( $r ) || ( null !== $counted && (string) $counted !== $order_id );

		if ( ! $refund && (string) $counted !== $order_id ) {
			$r->set( array( 'restore_year_paid' => array( 'order' => (int) $order_id, 'baseline' => $baseline ) ) );
			$earlier = (int) $r->get( 'restore_order' ) > 0 ? wc_get_order( (int) $r->get( 'restore_order' ) ) : false;

			if ( $earlier instanceof \WC_Order && ! $earlier->is_paid() && $earlier->has_status( array( 'pending', 'failed' ) ) ) {
				$earlier->update_status( 'cancelled', __( 'Replaced: a renewal paid after the expiry now counts toward the restore.', 'beltoft-services' ) );
			}
		}

		Alerts::notify(
			( $refund ? 'Paid renewal after expiry buys nothing, refund it: ' : 'Paid renewal after expiry counted toward the restore: ' ) . $domain,
			array(
				sprintf( 'Domain: %s', $domain ),
				sprintf( 'Renewal order: %s (paid)', $order_id ),
				sprintf( 'Paid: %s %s', (string) $order->get_total(), (string) $order->get_currency() ),
				'The registrar has already expired this domain. Nothing was bought at the registrar on this order.',
				$refund
					? 'A restore order with the renewal year is already paid (or another late renewal already counts), so this order buys nothing. Refund it.'
					: 'It was counted toward the restore: the customer now only pays the reactivation fee on the domain page, and the restore job uses this order as the paid year.',
			),
			$r,
			'beltoft_services_late_renewal_' . $r->id() . '_' . $order_id,
			array( 'ttl' => YEAR_IN_SECONDS )
		);

		return StepResult::fail(
			'The renewal was paid after the domain expired. Nothing was bought at the registrar.',
			array(
				Jobs::TEXT     => $refund
					? 'Your payment is received, but the domain had already expired and this payment is not needed. We have been told and will refund it.'
					: 'Your payment is received and counts toward restoring the domain. To restore it, pay the reactivation fee on your domain page.',
				Jobs::EXPECTED => true,
			)
		);
	}

	/**
	 * The job `restore` of a paid restore order.
	 *
	 * @param Resource $r        Domain resource.
	 * @param string   $order_id The paid restore order.
	 */
	public static function run( Resource $r, string $order_id ): StepResult {
		$domain = (string) $r->get( 'domain' );

		list( $ran, $result ) = Locks::with(
			Locks::domain( $domain ),
			0,
			static fn(): StepResult => self::steps( Resource::find( $r->id() ) ?? $r, $order_id )
		);

		return $ran && $result instanceof StepResult ? $result : StepResult::retry( 60, 'The domain is busy with another change.' );
	}

	/**
	 * The steps of the job, under the domain lock.
	 *
	 * @param Resource $r        Domain resource.
	 * @param string   $order_id Restore order id.
	 */
	private static function steps( Resource $r, string $order_id ): StepResult {
		$order  = ctype_digit( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		$meta   = $order ? $order->get_meta( self::ORDER_META ) : null;
		$domain = (string) $r->get( 'domain' );

		if ( ! $order || ! $order->is_paid() || ! is_array( $meta ) || (int) ( $meta['resource'] ?? 0 ) !== $r->id() || strtolower( $domain ) !== (string) ( $meta['domain'] ?? '' ) ) {
			return StepResult::fail( 'No paid restore order belongs to this domain.' );
		}

		$done = array_map( 'strval', (array) $r->get( 'restored_orders' ) );

		if ( in_array( $order_id, $done, true ) ) {
			return StepResult::done();
		}

		// A fee-only order counts on a late renewal order for the year: that order must be paid.
		$year = (int) ( $meta['year_order'] ?? 0 );

		if ( $year > 0 ) {
			$year_order = wc_get_order( $year );

			if ( ! $year_order instanceof \WC_Order || ! $year_order->is_paid() ) {
				return StepResult::fail( 'The renewal order that paid the year of this restore is not paid.' );
			}
		}

		if ( str_ends_with( strtolower( $domain ), '.dk' ) ) {
			return self::dk_by_hand( $r, $order_id, $domain );
		}

		$started       = (array) $r->get( 'restore_started' );
		$posted        = (array) $r->get( 'restore_posted' );
		$renew_started = (array) $r->get( 'restore_renew_started' );

		// One restore at a time: another order's marker means its money may be spent and its new date would look like this order's.
		foreach ( array_keys( $started ) as $other ) {
			if ( (string) $other !== $order_id ) {
				return StepResult::retry( 3600, 'Another restore of this domain is awaiting registry confirmation.' );
			}
		}

		$baseline = (string) ( $started[ $order_id ] ?? $meta['baseline'] ?? '' );
		$id       = (string) $r->get( 'remote_id' );

		if ( '' === $id || '' === $baseline ) {
			return StepResult::retry( 3600, 'The registrar id is unavailable.' );
		}

		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );

		if ( is_wp_error( $detail ) ) {
			return StepResult::retry( 3600, 'The domain could not be checked at the registrar.' );
		}

		$detail = (array) $detail;
		$expiry = RestoreFacts::expiry_of( $detail );

		if ( 'ACT' === strtoupper( (string) ( $detail['status'] ?? '' ) ) && Renewals::later( $expiry, $baseline ) ) {
			// Restored or renewed already. Nothing is called. A second paid order for the same expiry gets its money back by hand.
			if ( ! isset( $started[ $order_id ] ) ) {
				self::already_restored( $r, $order, $order_id );
			}

			return self::finish( $r, $order, $order_id, $detail, $expiry, $done, $started, $posted, $renew_started );
		}

		if ( isset( $renew_started[ $order_id ] ) || ( isset( $started[ $order_id ] ) && empty( $posted[ $order_id ] ) ) ) {
			return StepResult::retry( 3600, 'A restore request is awaiting registry confirmation.' );
		}

		// The restore was sent but the date has not moved: the registry may lag, and the restore may have included the year. Wait before buying one.
		if ( ! empty( $posted[ $order_id ] ) && time() - (int) $posted[ $order_id ] < self::RENEW_WAIT ) {
			return StepResult::retry( 1800, 'The restore is awaiting registry confirmation.' );
		}

		if ( empty( $posted[ $order_id ] ) ) {
			$over = self::window_over( $domain, $baseline, $detail );

			if ( null !== $over ) {
				return self::stopped( $r, $order_id, $domain, $over );
			}

			$blocked = SpendGuard::guard_restore( $r, $domain, $order );

			if ( $blocked ) {
				return $blocked;
			}

			$started[ $order_id ] = $baseline;

			if ( ! $r->set( array( 'restore_started' => $started ) ) ) {
				return StepResult::retry( 3600, 'The restore could not be recorded before requesting it.' );
			}

			$reply = Clients::registrar()->request( 'POST', '/domains/' . $id . '/restore', array( 'domain' => self::parts( $domain ) ) );

			if ( is_wp_error( $reply ) ) {
				// A refusal that reached the registrar means nothing was bought: free the marker so the next attempt asks again. A timeout or 5xx keeps it.
				if ( ! Renewals::refusal( $r, $reply ) ) {
					return StepResult::retry( 3600, 'The registrar restore needs staff attention.' );
				}

				unset( $started[ $order_id ] );
				$r->set( array( 'restore_started' => $started ) );
				Alerts::admin( $r, 'The registrar refused to restore ' . $domain . ' (order ' . $order_id . '): ' . $reply->get_error_code() );

				return StepResult::retry( 3600, 'The registrar restore was refused and will be tried again.' );
			}

			$posted[ $order_id ] = time();

			if ( ! $r->set( array( 'restore_posted' => $posted ) ) ) {
				return StepResult::retry( 3600, 'The restore could not be recorded after requesting it.' );
			}

			$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );

			if ( is_wp_error( $detail ) ) {
				return StepResult::retry( 3600, 'The restore is awaiting registry confirmation.' );
			}

			$detail = (array) $detail;
			$expiry = RestoreFacts::expiry_of( $detail );

			if ( Renewals::later( $expiry, $baseline ) ) {
				return self::finish( $r, $order, $order_id, $detail, $expiry, $done, $started, $posted, $renew_started );
			}

			return StepResult::retry( 1800, 'The restore is awaiting registry confirmation.' );
		}

		// The restore call did not move the date: the year is bought separately (Openprovider: a restore needs at least one year of renewal).
		$renew_started[ $order_id ] = $baseline;

		if ( ! $r->set( array( 'restore_renew_started' => $renew_started ) ) ) {
			return StepResult::retry( 3600, 'The renewal could not be recorded before requesting it.' );
		}

		$reply = Clients::registrar()->request( 'POST', '/domains/' . $id . '/renew', array( 'period' => 1 ) );

		if ( is_wp_error( $reply ) ) {
			if ( ! Renewals::refusal( $r, $reply ) ) {
				return StepResult::retry( 3600, 'The registrar renewal after the restore needs staff attention.' );
			}

			unset( $renew_started[ $order_id ] );
			$r->set( array( 'restore_renew_started' => $renew_started ) );
			Alerts::admin( $r, 'The registrar refused the renewal after restoring ' . $domain . ' (order ' . $order_id . '): ' . $reply->get_error_code() );

			return StepResult::retry( 3600, 'The registrar renewal after the restore was refused and will be tried again.' );
		}

		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );

		if ( is_wp_error( $detail ) ) {
			return StepResult::retry( 3600, 'The renewal after the restore is awaiting registry confirmation.' );
		}

		$detail = (array) $detail;
		$expiry = RestoreFacts::expiry_of( $detail );

		return Renewals::later( $expiry, $baseline )
			? self::finish( $r, $order, $order_id, $detail, $expiry, $done, $started, $posted, $renew_started )
			: StepResult::retry( 3600, 'A renewal request after the restore is awaiting registry confirmation.' );
	}

	/**
	 * Everything that follows a restored domain: name servers back if they differ, the new dates, the subscription active again, the order
	 * marked, and the renewed action (which sends notice N6).
	 *
	 * @param array<string, mixed> $detail Fresh GET /domains/{id}.
	 */
	private static function finish( Resource $r, \WC_Order $order, string $order_id, array $detail, string $expiry, array $done, array $started, array $posted, array $renew_started ): StepResult {
		$domain = (string) $r->get( 'domain' );
		$ours   = self::nameservers( $r );
		$theirs = array();

		foreach ( (array) ( $detail['name_servers'] ?? array() ) as $server ) {
			$name = strtolower( rtrim( trim( (string) ( is_array( $server ) ? ( $server['name'] ?? '' ) : $server ) ), '.' ) );

			if ( '' !== $name ) {
				$theirs[] = $name;
			}
		}

		sort( $ours );
		sort( $theirs );

		if ( array() !== $ours && $ours !== $theirs ) {
			// Openprovider points a quarantined domain at its own name servers (plan S6). A failure does not undo the restore: staff are told.
			$put = Clients::registrar()->request( 'PUT', '/domains/' . rawurlencode( (string) $r->get( 'remote_id' ) ), array( 'ns_group' => '', 'name_servers' => array_map( static fn( $host ) => array( 'name' => $host ), $ours ) ) );

			if ( is_wp_error( $put ) ) {
				Alerts::admin( $r, 'The domain ' . $domain . ' was restored but its name servers could not be put back.' );
			}
		}

		$r->set( array( 'expires_at' => $expiry ) );
		$subscription = Stage::subscription( $r );

		// The unpaid renewal left the subscription on hold: the restore is its payment.
		if ( $subscription && $subscription->has_status( 'on-hold' ) ) {
			$subscription->update_status( 'active', sprintf( 'Domain restored: restore order %s was paid.', $order_id ) );
		}

		$dates = Renewals::set_dates( Resource::find( $r->id() ) ?? $r );

		if ( ! $dates->is_done() ) {
			return $dates;
		}

		$done[] = $order_id;
		$year   = (int) ( $order->get_meta( self::ORDER_META )['year_order'] ?? 0 );

		// The late renewal order that paid the year is finished with the restore: its own renew job must not buy a year again.
		if ( $year > 0 ) {
			$renewed   = array_map( 'strval', (array) $r->get( 'renewed_orders' ) );
			$renewed[] = (string) $year;
			$r->set( array( 'renewed_orders' => array_values( array_unique( $renewed ) ) ) );
		}

		unset( $started[ $order_id ], $posted[ $order_id ], $renew_started[ $order_id ] );
		$order->update_meta_data( '_beltoft_services_restore_done', time() );
		$order->add_order_note( sprintf( 'The domain %s was restored. It is registered until %s.', $domain, $expiry ) );
		$order->save();

		/** This action runs after Renewals finishes a paid renewal: a restore is one too (it sends notice N6). */
		do_action( 'beltoft_services_domain_renewed', $r, $expiry );

		return StepResult::done(
			array_merge(
				$dates->meta(),
				array(
					'restored_orders'       => $done,
					'restore_started'       => $started,
					'restore_posted'        => $posted,
					'restore_renew_started' => $renew_started,
					'expires_at'            => $expiry,
				)
			),
			'Domain restored after paid restore order ' . $order_id
		);
	}

	/**
	 * Why the registrar can no longer restore the domain, or null when it still can (or the window is not known).
	 *
	 * @param array<string, mixed> $detail GET /domains/{id}.
	 */
	private static function window_over( string $domain, string $baseline, array $detail ): ?string {
		if ( ! empty( $detail['is_deleted'] ) ) {
			return 'The registrar says the domain is deleted.';
		}

		$days = RestoreFacts::window_days( $domain );
		$time = strtotime( $baseline . ' UTC' );

		if ( null !== $days && $time && time() >= $time + $days * DAY_IN_SECONDS ) {
			return sprintf( 'The restore period of %d days after %s is over.', $days, gmdate( 'Y-m-d', $time ) );
		}

		return null;
	}

	/**
	 * No registrar call: staff decide (refund, or a restore Openprovider support does).
	 */
	private static function stopped( Resource $r, string $order_id, string $domain, string $why ): StepResult {
		Alerts::notify(
			'Paid restore cannot be done: ' . $domain,
			array(
				sprintf( 'Domain: %s', $domain ),
				sprintf( 'Restore order: %s (paid)', $order_id ),
				$why,
				'Nothing was bought at the registrar. Decide on a refund of the order.',
			),
			$r,
			'beltoft_services_restore_stopped_' . $r->id() . '_' . $order_id,
			array( 'ttl' => YEAR_IN_SECONDS )
		);

		return StepResult::fail( $why, array( Jobs::TEXT => self::WAITING, Jobs::EXPECTED => true ) );
	}

	/** A .dk domain is restored by staff in the registry's portal: the customer paid, nothing is written at the registrar. */
	private static function dk_by_hand( Resource $r, string $order_id, string $domain ): StepResult {
		Alerts::notify(
			'Restore .dk by hand: ' . $domain,
			array(
				sprintf( 'Domain: %s', $domain ),
				sprintf( 'Restore order: %s (paid)', $order_id ),
				'Restore this .dk domain by hand (the registry portal with the registrar role, or Openprovider support). The registry deletes it 30 days after the suspension began. Nothing was written at the registrar.',
			),
			$r,
			'beltoft_services_restore_dk_' . $r->id() . '_' . $order_id,
			array( 'ttl' => YEAR_IN_SECONDS )
		);

		return StepResult::fail( 'A .dk domain is restored by our staff.', array( Jobs::TEXT => self::WAITING, Jobs::EXPECTED => true ) );
	}

	/** A paid restore order whose domain is already restored: nothing is bought, staff decide on a refund. */
	private static function already_restored( Resource $r, \WC_Order $order, string $order_id ): void {
		Alerts::notify(
			'Restore order paid for a domain that is already restored: ' . (string) $r->get( 'domain' ),
			array(
				sprintf( 'Domain: %s', (string) $r->get( 'domain' ) ),
				sprintf( 'Restore order: %s (paid, %s %s)', $order_id, (string) $order->get_total(), (string) $order->get_currency() ),
				'The registrar already shows a later renewal date than the order was made for. Nothing was bought. Decide on a refund of the order.',
			),
			$r,
			'beltoft_services_restore_already_' . $r->id() . '_' . $order_id,
			array( 'ttl' => YEAR_IN_SECONDS )
		);
	}

	/**
	 * The name and extension of a domain for the registrar's request body.
	 *
	 * @param string $domain Full domain name.
	 * @return array{name: string, extension: string}
	 */
	private static function parts( string $domain ): array {
		$tld = RestoreFacts::row( $domain )[0];

		if ( '' !== $tld ) {
			return array( 'name' => substr( $domain, 0, -( strlen( $tld ) + 1 ) ), 'extension' => $tld );
		}

		$parts = explode( '.', $domain, 2 );

		return array( 'name' => $parts[0], 'extension' => $parts[1] ?? '' );
	}

	/**
	 * The name servers the domain should have: its own list when the customer chose custom ones, the ones it was transferred in with, else ours.
	 *
	 * @param Resource $r Domain resource.
	 * @return string[]
	 */
	private static function nameservers( Resource $r ): array {
		if ( 'custom' === $r->get( 'ns_mode' ) ) {
			$hosts = (array) $r->get( 'nameservers' );
		} elseif ( DomainDriver::is_external( $r ) ) {
			$hosts = DomainDriver::stored_nameservers( $r );
		} else {
			$hosts = Zone::nameservers();
		}

		return array_values( array_filter( array_map( static fn( $host ): string => strtolower( rtrim( trim( (string) $host ), '.' ) ), $hosts ) ) );
	}
}

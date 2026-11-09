<?php
/**
 * Add-ons on a hosting subscription: an extra registrable domain, an extra 5 GB pack for one Email lite mailbox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Domains\PublicSuffix;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One generic mechanism. An add-on product is a WooCommerce product with the post meta `_beltoft_services_plan_addon`
 * set to the kind (`domain` or `mail_pack`); its price is the price per month. Billing, because Subscriptions cannot
 * pro-rate a line added in the middle of a period:
 * - the time until the next renewal is charged now as a one-off order, the price for each started month, paid with the
 *   subscription's saved card or through the pay-for-order link;
 * - once that order is paid, a line is added to the hosting subscription that bills with its renewals (the price times
 *   the months of the billing period);
 * - removing an add-on only marks its line as ending; at the next renewal the line leaves the subscription and the
 *   renewal order, and the domain or storage goes with it. No refund.
 * A line carries item meta: the kind, the resource it belongs to, its target (the registrable domain or the mailbox
 * address) and, when ending, `ending`.
 */
final class Addons {

	public const KIND_DOMAIN = 'domain';
	public const KIND_PACK   = 'mail_pack';
	public const KINDS       = array( self::KIND_DOMAIN, self::KIND_PACK );

	public const PRODUCT_KEY = '_beltoft_services_plan_addon';
	public const ORDER_META  = '_beltoft_services_addon_order';
	public const ORDER_DONE  = '_beltoft_services_addon_done';
	public const ORDER_STRAY = '_beltoft_services_addon_stray_alerted';
	public const I_KIND      = '_beltoft_services_addon_kind';
	public const I_RESOURCE  = '_beltoft_services_addon_resource';
	public const I_TARGET    = '_beltoft_services_addon_target';
	public const I_ENDING    = '_beltoft_services_addon_ending';
	public const I_ORDER     = '_beltoft_services_addon_order_id';

	/** An unpaid add-on order is cancelled after this many seconds. */
	public const STALE_AFTER = 2 * DAY_IN_SECONDS;
	public const SWEEP_HOOK  = 'beltoft_services_addon_sweep';
	public const SWEEP_CRON  = '30 3 * * *';

	/** One later attempt at an order whose activation lock was busy (the holder may stop half way). */
	public const ACTIVATE_RETRY_HOOK = 'beltoft_services_addon_activate_retry';

	/** Storage of one pack, MB, and the most packs one mailbox can have. */
	public const PACK_MB   = \BeltoftServices\Mail\MailPacks::PACK_MB;
	public const MAX_PACKS = \BeltoftServices\Mail\MailPacks::MAX_PACKS;

	/**
	 * True while the saved card is charged in buy(): a payment made then is answered to the page, which adds the domain.
	 *
	 * @var bool
	 */
	private static $buying = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'refuse_direct_add' ), 5, 6 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'activate' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'activate' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'activate' ), 20 );
		add_filter( 'wcs_renewal_order_created', array( __CLASS__, 'at_renewal' ), 20, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'reverse' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'reverse' ), 20 );
		add_action( 'woocommerce_subscriptions_switch_completed', array( __CLASS__, 'after_switch' ), 20 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'cancel_stale' ) );
		add_action( self::ACTIVATE_RETRY_HOOK, array( __CLASS__, 'activate' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily sweep in Action Scheduler, once. The sweep used to be a WP-Cron event of the same hook: that
	 * event is removed here so the sweep does not run twice.
	 */
	public static function schedule(): void {
		if ( false !== wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}

		Jobs::ensure_cron( self::SWEEP_HOOK, self::SWEEP_CRON, array(), HOUR_IN_SECONDS );
	}

	/**
	 * Removes the sweep (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SWEEP_HOOK, array(), Jobs::GROUP );
		}
	}

	/**
	 * Filter woocommerce_add_to_cart_validation (also run by the Store API): an add-on product is bought from the
	 * hosting account page, never through the cart, where it would charge the unit price and give nothing.
	 *
	 * @param mixed $valid        Whether the add is valid so far.
	 * @param mixed $product_id   Product being added.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation being added.
	 * @param mixed $variation    Variation attributes.
	 * @param mixed $data         Cart item data.
	 * @return bool
	 */
	public static function refuse_direct_add( $valid, $product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ): bool {
		unset( $quantity, $variation_id, $variation );

		if ( ! $valid || '' === (string) get_post_meta( (int) $product_id, self::PRODUCT_KEY, true ) ) {
			return (bool) $valid;
		}

		// The renewal, resubscribe and switch carts of WooCommerce Subscriptions legitimately carry the lines.
		$data = is_array( $data ) ? $data : array();

		if ( CartFlow::has_value( $data, CartFlow::BOUGHT_BEFORE ) ) {
			return true;
		}

		wc_add_notice( __( 'Extra domains and mailbox storage are bought from your hosting account page.', 'beltoft-services' ), 'error' );

		return false;
	}

	/**
	 * The add-on product of a kind, or null when the shop has none.
	 *
	 * @param string $kind Kind.
	 */
	public static function product( string $kind ): ?\WC_Product {
		if ( ! in_array( $kind, self::KINDS, true ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => self::PRODUCT_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery -- one lookup of a rare key.
				'meta_value'     => $kind, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$p   = array() === $ids ? null : wc_get_product( (int) $ids[0] );

		return $p instanceof \WC_Product && (float) $p->get_price() > 0 ? $p : null;
	}

	/**
	 * The subscription that pays for the account.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function subscription( Resource $r ): ?\WC_Subscription {
		$id = (int) ( $r->license()['subscription_id'] ?? 0 );
		$s  = $id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : null;

		return $s instanceof \WC_Subscription ? $s : null;
	}

	/**
	 * The months of the subscription's billing period (a year is 12, anything shorter than a month counts as 1).
	 *
	 * @param \WC_Subscription $sub Subscription.
	 */
	public static function period_months( \WC_Subscription $sub ): int {
		$each = 'year' === $sub->get_billing_period() ? 12 : 1;

		return max( 1, $each * max( 1, (int) $sub->get_billing_interval() ) );
	}

	/**
	 * Started months from now to the next renewal: the smallest number of months, at least 1, that reaches it.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @param int|null         $now Time (tests).
	 */
	public static function months_to_renewal( \WC_Subscription $sub, ?int $now = null ): int {
		$now  = $now ?? time();
		$next = (int) $sub->get_time( 'next_payment' );
		$m    = 1;

		while ( $m < 120 && strtotime( '+' . $m . ' months', $now ) < $next ) {
			++$m;
		}

		return $m;
	}

	/**
	 * What the customer is asked to confirm.
	 *
	 * @param Resource $r   Hosting resource.
	 * @param string   $kind Kind.
	 * @param int|null $now Time (tests).
	 * @return array{unit: float, months_now: int, now: float, period: int, recurring: float}|null Null without a product or a subscription.
	 */
	public static function quote( Resource $r, string $kind, ?int $now = null ): ?array {
		$product = self::product( $kind );
		$sub     = self::subscription( $r );

		// A subscription that will not renew (pending cancel, on hold, no date) would deliver the add-on without ever billing it.
		if ( null === $product || null === $sub || ! $sub->has_status( 'active' ) || (int) $sub->get_time( 'next_payment' ) <= ( $now ?? time() ) ) {
			return null;
		}

		$unit   = (float) wc_get_price_excluding_tax( $product );
		$months = self::months_to_renewal( $sub, $now );
		$period = self::period_months( $sub );

		return array(
			'unit'       => $unit,
			'months_now' => $months,
			'now'        => round( $unit * $months, 2 ),
			'period'     => $period,
			'recurring'  => round( $unit * $period, 2 ),
		);
	}

	/**
	 * A price as plain text.
	 *
	 * @param float $amount Amount.
	 */
	public static function money( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * What the customer confirms before an add-on is bought.
	 *
	 * @param string               $kind  Kind.
	 * @param array<string, mixed> $quote From quote().
	 */
	public static function confirm_text( string $kind, array $quote ): string {
		$what = self::KIND_DOMAIN === $kind
			? __( 'An extra domain', 'beltoft-services' )
			: __( '5 GB of extra storage for one mailbox', 'beltoft-services' );

		return sprintf(
			/* translators: 1: what is bought, 2: price per month, 3: amount charged now, 4: number of months until the next renewal, 5: price per billing period. */
			_n(
				'%1$s costs %2$s per month, excluding VAT. We charge %3$s now for the %4$d month until your next renewal, and %5$s with each renewal after that. You can remove it later; it then ends at the next renewal and nothing is refunded.',
				'%1$s costs %2$s per month, excluding VAT. We charge %3$s now for the %4$d months until your next renewal, and %5$s with each renewal after that. You can remove it later; it then ends at the next renewal and nothing is refunded.',
				(int) $quote['months_now'],
				'beltoft-services'
			),
			$what,
			self::money( (float) $quote['unit'] ),
			self::money( (float) $quote['now'] ),
			(int) $quote['months_now'],
			self::money( (float) $quote['recurring'] )
		);
	}

	/**
	 * The lines of the account's add-ons on its subscription.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Target, '' for every target.
	 * @return array<int, \WC_Order_Item_Product> Item id => item.
	 */
	public static function lines( Resource $r, string $kind, string $target = '' ): array {
		$sub = self::subscription( $r );
		$out = array();

		foreach ( $sub ? $sub->get_items() : array() as $id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $kind === (string) $item->get_meta( self::I_KIND ) && (int) $item->get_meta( self::I_RESOURCE ) === $r->id() && ( '' === $target || $target === (string) $item->get_meta( self::I_TARGET ) ) ) {
				$out[ (int) $id ] = $item;
			}
		}

		return $out;
	}

	/**
	 * The registrable domain of a host name (the name itself when the ending is unknown).
	 *
	 * @param string $host Host.
	 */
	public static function registrable( string $host ): string {
		$host = strtolower( rtrim( trim( $host ), '.' ) );

		$labels = explode( '.', $host );

		// An ending the public suffix list does not know: the last two labels.
		return PublicSuffix::registrable( $host ) ?? ( count( $labels ) > 2 ? implode( '.', array_slice( $labels, -2 ) ) : $host );
	}

	/**
	 * The registrable domain a new name would add to the account when it is not the account's first one, else null
	 * (the first domain and everything under a domain the account has are free).
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $host New host name.
	 */
	public static function domain_needing_addon( Resource $r, string $host ): ?string {
		$reg  = self::registrable( $host );
		$have = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] ) {
				$have[] = self::registrable( $row['host'] );
			}
		}

		return array() === $have || in_array( $reg, $have, true ) ? null : $reg;
	}

	/**
	 * Does the account have a line for this target (one that is ending still counts until the renewal)?
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Target.
	 */
	public static function has( Resource $r, string $kind, string $target ): bool {
		return array() !== self::lines( $r, $kind, $target );
	}

	/**
	 * Registrable domains whose add-on line ends at the next renewal.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string>
	 */
	public static function ending_domains( Resource $r ): array {
		$out = array();

		foreach ( self::lines( $r, self::KIND_DOMAIN ) as $item ) {
			if ( 'yes' === (string) $item->get_meta( self::I_ENDING ) ) {
				$out[] = (string) $item->get_meta( self::I_TARGET );
			}
		}

		return $out;
	}

	/**
	 * Takes back the ending mark of a line (the customer wants the domain again before the renewal): it bills again.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Target.
	 * @return bool Whether a line was kept.
	 */
	public static function keep( Resource $r, string $kind, string $target ): bool {
		$kept = false;

		foreach ( self::lines( $r, $kind, $target ) as $item ) {
			if ( 'yes' === (string) $item->get_meta( self::I_ENDING ) ) {
				$item->delete_meta_data( self::I_ENDING );
				$item->save();
				$kept = true;
			}
		}

		$sub = $kept ? self::subscription( $r ) : null;

		if ( $sub ) {
			$sub->add_order_note( sprintf( 'Add-on %1$s (%2$s) was wanted again and stays at the next renewal.', $kind, $target ) );
		}

		return $kept;
	}

	/**
	 * Packs of each Email lite mailbox, lines that are ending included (they still hold the storage).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, int> Mailbox address => packs.
	 */
	public static function packs( Resource $r ): array {
		$out = array();

		foreach ( self::lines( $r, self::KIND_PACK ) as $item ) {
			$to         = (string) $item->get_meta( self::I_TARGET );
			$out[ $to ] = ( $out[ $to ] ?? 0 ) + 1;
		}

		return $out;
	}

	/**
	 * Mails the shop owner about a payment that needs a person (never silent).
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 */
	public static function alert( string $subject, string $body ): void {
		\BeltoftServices\Alerts::notify( $subject, array( $body ), null, '', array( 'newline' => false ) );
	}

	/**
	 * An unpaid add-on order of the same account and target that is still fresh.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Target.
	 */
	private static function pending_order( Resource $r, string $kind, string $target ): ?\WC_Order {
		$sub = self::subscription( $r );

		foreach ( $sub ? wc_get_orders( array( 'limit' => -1, 'customer_id' => (int) $sub->get_customer_id(), 'created_via' => 'beltoft_services_addon', 'status' => array( 'pending', 'on-hold', 'failed' ) ) ) : array() as $order ) {
			$meta = $order->get_meta( self::ORDER_META );

			// An order on hold (a payment on its way) is reused whatever its age; an unpaid one only while it is fresh.
			$fresh = $order->has_status( 'on-hold' ) || time() - (int) $order->get_date_created()->getTimestamp() < self::STALE_AFTER;

			if ( is_array( $meta ) && (int) ( $meta['resource'] ?? 0 ) === $r->id() && $kind === ( $meta['kind'] ?? '' ) && $target === ( $meta['target'] ?? '' ) && $fresh ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * The result of a purchase for the customer: paid, still being processed, or an order to pay.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{status: string, order: int, total: float, pay_url: string, message: string}
	 */
	private static function outcome( \WC_Order $order ): array {
		$paid = $order->has_status( array( 'processing', 'completed' ) );
		$hold = $order->has_status( 'on-hold' );

		return array(
			'status'  => $paid ? 'paid' : ( $hold ? 'pending' : 'pay' ),
			'order'   => $order->get_id(),
			'total'   => (float) $order->get_total(),
			'pay_url' => $paid || $hold ? '' : $order->get_checkout_payment_url(),
			'message' => $hold ? __( 'Your payment is being processed.', 'beltoft-services' ) : '',
		);
	}

	/**
	 * Starts buying an add-on: the one-off order for the time until the renewal, paid with the saved card or through
	 * the pay-for-order link. The line is added when the order is paid. An unpaid order for the same target is reused,
	 * never doubled.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Registrable domain, or mailbox address.
	 * @param int|null $now    Time (tests).
	 * @param array    $connect For a domain: host and slug to add to the website once the order is paid later (the pay link).
	 * @return array{status: string, order: int, total: float, pay_url: string, message: string}|\WP_Error status is paid, pending or pay.
	 */
	public static function buy( Resource $r, string $kind, string $target, ?int $now = null, array $connect = array() ) {
		$sub     = self::subscription( $r );
		$product = self::product( $kind );
		$quote   = self::quote( $r, $kind, $now );

		if ( null === $sub || null === $product || null === $quote ) {
			return new \WP_Error( 'failed', __( 'This add-on cannot be bought online right now. Contact support.', 'beltoft-services' ) );
		}

		if ( self::KIND_PACK === $kind && count( self::lines( $r, $kind, $target ) ) >= self::MAX_PACKS ) {
			return new \WP_Error( 'limit', __( 'This mailbox has the most extra storage it can have.', 'beltoft-services' ) );
		}

		if ( self::KIND_DOMAIN === $kind && self::has( $r, $kind, $target ) ) {
			return new \WP_Error( 'exists', __( 'This domain is paid for already.', 'beltoft-services' ) );
		}

		// The look for an open order and the making of a new one are one step: a double click or a second tab waits and then finds the order.
		[ $taken, $out ] = Locks::with(
			Locks::name( 'addon_buy', $r->id() ),
			self::lock_wait(),
			static fn() => self::order_for( $r, $sub, $product, $quote, $kind, $target, $connect )
		);

		return $taken ? $out : new \WP_Error( 'busy', __( 'This add-on is being bought already. Try again in a moment.', 'beltoft-services' ) );
	}

	/**
	 * Offers the open order of an add-on, or makes the order and tries the saved card.
	 *
	 * @param Resource             $r       Hosting resource.
	 * @param \WC_Subscription     $sub     Its subscription.
	 * @param \WC_Product          $product Add-on product.
	 * @param array<string, mixed> $quote   The quote.
	 * @param string               $kind    Add-on kind.
	 * @param string               $target  Add-on target.
	 * @param array<string, string> $connect Domain to connect after payment.
	 * @return array{status: string, order: int, total: float, pay_url: string, message: string}|\WP_Error
	 */
	private static function order_for( Resource $r, $sub, $product, array $quote, string $kind, string $target, array $connect ) {
		self::cancel_stale();
		$open = self::pending_order( $r, $kind, $target );

		if ( $open ) {
			return self::outcome( $open );
		}

		$order = wc_create_order( array( 'customer_id' => (int) $sub->get_customer_id(), 'created_via' => 'beltoft_services_addon' ) );

		if ( is_wp_error( $order ) ) {
			return new \WP_Error( 'failed', __( 'The order could not be made. Try again in a moment.', 'beltoft-services' ) );
		}

		$order->set_currency( $sub->get_currency() );
		$order->set_address( $sub->get_address( 'billing' ), 'billing' );
		$order->set_address( $sub->get_address( 'shipping' ), 'shipping' );

		// The VAT position of the subscription (an exempt customer with a VAT number) is the order's too, before the totals.
		foreach ( array( 'is_vat_exempt', '_billing_eu_vat_number' ) as $key ) {
			$value = (string) $sub->get_meta( $key, true );

			if ( '' !== $value ) {
				$order->update_meta_data( $key, $value );
			}
		}

		$order->add_product(
			$product,
			1,
			array(
				'subtotal' => (string) $quote['now'],
				'total'    => (string) $quote['now'],
			)
		);
		$order->update_meta_data(
			self::ORDER_META,
			array(
				'resource' => $r->id(),
				'sub'      => $sub->get_id(),
				'kind'     => $kind,
				'target'   => $target,
				'period'   => $quote['period'],
				'next'     => (int) $sub->get_time( 'next_payment' ),
				'host'     => (string) ( $connect['host'] ?? '' ),
				'slug'     => (string) ( $connect['slug'] ?? '' ),
			)
		);
		$order->calculate_totals( true );
		$order->save();

		// The order is not linked as a renewal (WooCommerce Subscriptions would treat its payment as one and move the
		// subscription's dates), so notes on both sides tie the order to the subscription.
		$order->add_order_note(
			sprintf(
				/* translators: %d: subscription number. */
				__( 'Hosting add-on for subscription #%d.', 'beltoft-services' ),
				$sub->get_id()
			)
		);
		$sub->add_order_note(
			sprintf(
				/* translators: %d: order number. */
				__( 'Add-on order #%d was made for this subscription.', 'beltoft-services' ),
				$order->get_id()
			)
		);

		if ( ! $sub->is_manual() && '' !== (string) $sub->get_payment_method() && function_exists( 'wcs_copy_payment_method_to_order' ) ) {
			wcs_copy_payment_method_to_order( $sub, $order );
			$order->save();

			// Paid on the spot: the page that asked adds the domain right after, so it is not added here too.
			self::$buying = true;

			try {
				\WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $order );
			} finally {
				self::$buying = false;
			}
		}

		return self::outcome( wc_get_order( $order->get_id() ) );
	}

	/**
	 * Cancels the unpaid add-on orders that have waited longer than the quote holds.
	 */
	public static function cancel_stale(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		foreach ( wc_get_orders( array( 'limit' => 50, 'created_via' => 'beltoft_services_addon', 'status' => array( 'pending', 'failed' ), 'date_created' => '<' . ( time() - self::STALE_AFTER ) ) ) as $order ) {
			if ( '' === (string) $order->get_meta( self::ORDER_DONE ) ) {
				$order->update_status( 'cancelled', 'The add-on order was not paid in time.' );
			}
		}
	}

	/**
	 * A paid add-on order: the line goes on the subscription, once.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function activate( $order_id ): void {
		static $busy = array();

		$id = (int) $order_id;

		if ( isset( $busy[ $id ] ) ) {
			return;
		}

		$busy[ $id ] = true;

		try {
			// One process at a time for an order: the payment webhook and the customer's return page can fire together.
			list( $taken ) = Locks::with(
				Locks::name( 'addon_order', $id ),
				self::lock_wait(),
				static function () use ( $id ): void {
					// Read inside the lock, so the one that waited sees what the other did.
					$order = wc_get_order( $id );
					$meta  = $order instanceof \WC_Order ? $order->get_meta( self::ORDER_META ) : null;

					if ( $order instanceof \WC_Order && ! is_array( $meta ) && $order->has_status( array( 'processing', 'completed' ) ) ) {
						self::stray( $order );

						return;
					}

					if ( ! is_array( $meta ) || '' !== (string) $order->get_meta( self::ORDER_DONE ) || ! $order->has_status( array( 'processing', 'completed' ) ) ) {
						return;
					}

					self::add_line( $order, $meta );
				}
			);

			// Another process holds the order. If it stops half way nothing would call this again, so look once more later.
			if ( ! $taken ) {
				Jobs::schedule_once( time() + 120, self::ACTIVATE_RETRY_HOOK, array( $id ) );
			}
		} finally {
			unset( $busy[ $id ] );
		}
	}

	/**
	 * Seconds a purchase or an order's activation waits for its lock (default 10).
	 */
	private static function lock_wait(): int {
		/**
		 * Filters how long an add-on purchase or activation waits for its lock.
		 *
		 * @param int $seconds Seconds (default 10).
		 */
		return max( 0, (int) apply_filters( 'beltoft_services_addon_lock_wait', 10 ) );
	}

	/**
	 * A paid order with an add-on product that this plugin did not make: nothing is delivered, so say so, once.
	 *
	 * @param \WC_Order $order Paid order.
	 */
	private static function stray( \WC_Order $order ): void {
		// Renewal, resubscribe and switch orders carry the add-on lines of the subscription on purpose.
		if ( '' !== (string) $order->get_meta( self::ORDER_STRAY ) || ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, array( 'parent', 'renewal', 'resubscribe', 'switch' ) ) ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && '' !== (string) get_post_meta( (int) $item->get_product_id(), self::PRODUCT_KEY, true ) ) {
				$order->update_meta_data( self::ORDER_STRAY, 'yes' );
				$order->add_order_note( 'Paid order with an add-on product that was not bought from the hosting account page: nothing was delivered. Check it and refund if needed.' );
				$order->save();
				self::alert( 'Add-on payment needs a look', sprintf( "Order #%d was paid with an add-on product, but it was not bought from the hosting account page, so nothing was delivered.\n\nCheck the order and refund it if needed.", $order->get_id() ) );

				return;
			}
		}
	}

	/**
	 * Adds the line of a paid order, or says why it was not added (a paid order is never dropped without a trace).
	 *
	 * @param \WC_Order            $order Paid order.
	 * @param array<string, mixed> $meta  Order meta of the add-on.
	 */
	private static function add_line( \WC_Order $order, array $meta ): void {
		$r       = Resource::find( (int) ( $meta['resource'] ?? 0 ) );
		$sub     = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $meta['sub'] ?? 0 ) ) : null;
		$kind    = (string) ( $meta['kind'] ?? '' );
		$target  = (string) ( $meta['target'] ?? '' );
		$product = self::product( $kind );
		$problem = '';

		// A line of this very order is already on the subscription (saved, but the order was not marked): never add a second.
		foreach ( $r ? self::lines( $r, $kind, $target ) : array() as $item ) {
			if ( (string) $order->get_id() === (string) $item->get_meta( self::I_ORDER ) ) {
				$order->update_meta_data( self::ORDER_DONE, gmdate( 'c' ) );
				$order->save();

				return;
			}
		}

		if ( ! $r || ! $sub instanceof \WC_Subscription || null === $product ) {
			$problem = 'the account, its subscription or the add-on product is gone';
		} elseif ( (int) ( $meta['next'] ?? 0 ) > 0 && time() > (int) $meta['next'] ) {
			$problem = 'it was paid after the renewal date it was quoted for, so the charge does not match the period';
		} elseif ( self::KIND_DOMAIN === $kind && self::has( $r, $kind, $target ) ) {
			$problem = 'the domain already has an add-on line (a duplicate payment)';
		} elseif ( self::KIND_PACK === $kind && count( self::lines( $r, $kind, $target ) ) >= self::MAX_PACKS ) {
			$problem = 'the mailbox already has the most packs (a duplicate payment)';
		}

		if ( '' === $problem ) {
			$price = (string) round( (float) wc_get_price_excluding_tax( $product ) * (int) ( $meta['period'] ?? 1 ), 2 );
			$id    = $sub->add_product( $product, 1, array( 'subtotal' => $price, 'total' => $price ) );
			$item  = $id ? $sub->get_item( (int) $id ) : null;

			if ( $item instanceof \WC_Order_Item_Product ) {
				$item->update_meta_data( self::I_KIND, $kind );
				$item->update_meta_data( self::I_RESOURCE, (string) $r->id() );
				$item->update_meta_data( self::I_TARGET, $target );
				$item->update_meta_data( self::I_ORDER, (string) $order->get_id() );
				$item->save();
				$sub->calculate_totals();
				$sub->add_order_note( sprintf( 'Add-on %1$s (%2$s) added after order #%3$d was paid.', $kind, $target, $order->get_id() ) );
				$sub->save();
				$order->update_meta_data( self::ORDER_DONE, gmdate( 'c' ) );
				$order->save();
				self::apply( $r );

				// Paid later (the pay link, a payment that was on hold): nobody is on the page, so the domain joins its website now.
				if ( self::KIND_DOMAIN === $kind && ! self::$buying ) {
					self::connect( $order, $r, $meta );
				}

				return;
			}

			$problem = 'the line could not be saved on the subscription';
		}

		$order->update_meta_data( self::ORDER_DONE, 'refused' );
		$order->add_order_note( 'Paid add-on order, but no line was added: ' . $problem . '. Check it and refund if needed.' );
		$order->save();
		self::alert( 'Add-on payment needs a look', sprintf( "Order #%1\$d (%2\$s, %3\$s) was paid but no line was added: %4\$s.\n\nCheck the order and refund it if needed.", $order->get_id(), $kind, $target, $problem ) );
	}

	/**
	 * Adds the domain a paid add-on order was for to its website, or says that it could not be (the line is paid and stays).
	 *
	 * @param \WC_Order            $order Paid order.
	 * @param Resource             $r     Hosting resource.
	 * @param array<string, mixed> $meta  Order meta of the add-on.
	 */
	private static function connect( \WC_Order $order, Resource $r, array $meta ): void {
		$host = (string) ( $meta['host'] ?? '' );
		$slug = (string) ( $meta['slug'] ?? '' );

		if ( '' === $host || '' === $slug ) {
			return;
		}

		$added = ( new HostingActions( $r ) )->add_domain( $slug, $host );

		if ( is_wp_error( $added ) ) {
			$order->add_order_note( 'The domain ' . $host . ' is paid but could not be added to the website: ' . $added->get_error_message() );
			self::alert( 'Paid domain was not connected', sprintf( "Order #%1\$d paid for the extra domain %2\$s, but it could not be added to the website: %3\$s.\n\nAdd it by hand or ask the customer to add it from the hosting page.", $order->get_id(), $host, $added->get_error_message() ) );
		}
	}

	/**
	 * Paid extra domains that are not on a website yet (the customer has not added them, or they are being connected).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string> Registrable domains.
	 */
	public static function unconnected_domains( Resource $r ): array {
		$have = array();

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] ) {
				$have[] = self::registrable( $row['host'] );
			}
		}

		$out = array();

		foreach ( self::lines( $r, self::KIND_DOMAIN ) as $item ) {
			$target = (string) $item->get_meta( self::I_TARGET );

			if ( '' === (string) $item->get_meta( self::I_ENDING ) && ! in_array( $target, $have, true ) ) {
				$out[] = $target;
			}
		}

		return $out;
	}

	/**
	 * A one-off add-on order that was refunded or cancelled after its line was added: the line is marked as ending, so it
	 * leaves at the next renewal like a normal removal (the domain or storage stays until then).
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function reverse( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		$meta  = $order instanceof \WC_Order ? $order->get_meta( self::ORDER_META ) : null;

		if ( ! is_array( $meta ) || '' === (string) $order->get_meta( self::ORDER_DONE ) || 'refused' === (string) $order->get_meta( self::ORDER_DONE ) ) {
			return;
		}

		$r   = Resource::find( (int) ( $meta['resource'] ?? 0 ) );
		$sub = $r ? self::subscription( $r ) : null;

		foreach ( $r ? self::lines( $r, (string) $meta['kind'], (string) $meta['target'] ) : array() as $item ) {
			if ( (string) $order->get_id() === (string) $item->get_meta( self::I_ORDER ) && '' === (string) $item->get_meta( self::I_ENDING ) ) {
				$item->update_meta_data( self::I_ENDING, 'yes' );
				$item->save();

				if ( $sub ) {
					$sub->add_order_note( sprintf( 'Add-on %1$s (%2$s) ends at the next renewal: order #%3$d was refunded or cancelled.', (string) $meta['kind'], (string) $meta['target'], $order->get_id() ) );
				}
			}
		}
	}

	/**
	 * After a plan switch the billing period may have changed: the add-on lines bill the price for the new period.
	 *
	 * @param mixed $order Switch order.
	 */
	public static function after_switch( $order ): void {
		if ( function_exists( 'wcs_get_subscriptions_for_switch_order' ) && $order instanceof \WC_Order ) {
			foreach ( wcs_get_subscriptions_for_switch_order( $order ) as $sub ) {
				self::resync_prices( $sub );
			}
		}
	}

	/**
	 * Sets each add-on line of a subscription to the unit price times the months of its billing period.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 */
	public static function resync_prices( \WC_Subscription $sub ): void {
		$changed = false;

		foreach ( $sub->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product && '' !== (string) $item->get_meta( self::I_KIND ) ? self::product( (string) $item->get_meta( self::I_KIND ) ) : null;

			if ( $product ) {
				$price = (string) round( (float) wc_get_price_excluding_tax( $product ) * self::period_months( $sub ), 2 );

				if ( (float) $item->get_total() !== (float) $price ) {
					$item->set_subtotal( $price );
					$item->set_total( $price );
					$item->save();
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			$sub->calculate_totals();
			$sub->save();
		}
	}

	/**
	 * Ends an add-on: its line leaves at the next renewal, the domain or storage stays until then.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Target.
	 * @return bool Whether a line was marked.
	 */
	public static function end( Resource $r, string $kind, string $target ): bool {
		foreach ( self::lines( $r, $kind, $target ) as $item ) {
			if ( '' === (string) $item->get_meta( self::I_ENDING ) ) {
				$item->update_meta_data( self::I_ENDING, 'yes' );
				$item->save();

				return true;
			}
		}

		return false;
	}

	/**
	 * The renewal order is made: the ending lines leave the subscription and the renewal order, and what they gave
	 * goes with them.
	 *
	 * @param mixed $renewal Renewal order.
	 * @param mixed $sub     Subscription.
	 * @return mixed The renewal order.
	 */
	public static function at_renewal( $renewal, $sub ) {
		if ( ! $renewal instanceof \WC_Order || ! $sub instanceof \WC_Subscription ) {
			return $renewal;
		}

		$ended = array();

		foreach ( $sub->get_items() as $id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && '' !== (string) $item->get_meta( self::I_KIND ) && 'yes' === (string) $item->get_meta( self::I_ENDING ) ) {
				$ended[] = array( (int) $item->get_meta( self::I_RESOURCE ), (string) $item->get_meta( self::I_KIND ), (string) $item->get_meta( self::I_TARGET ) );
				$sub->remove_item( (int) $id );
			}
		}

		if ( array() === $ended ) {
			return $renewal;
		}

		foreach ( $renewal->get_items() as $id => $item ) {
			if ( 'yes' === (string) $item->get_meta( self::I_ENDING ) ) {
				$renewal->remove_item( (int) $id );
			}
		}

		$sub->calculate_totals();
		$sub->save();
		$renewal->calculate_totals();
		$renewal->save();

		foreach ( $ended as $one ) {
			$r = Resource::find( $one[0] );

			if ( $r ) {
				$ok = self::KIND_DOMAIN !== $one[1] || self::drop_domain( $r, $one[2] );
				$ok = self::apply( $r ) && $ok;

				// The line is gone for good, but what it gave stays until somebody looks: never silent.
				if ( ! $ok ) {
					$sub->add_order_note( sprintf( 'Add-on %1$s (%2$s) ended at the renewal, but what it gave could not be taken back.', $one[1], $one[2] ) );
					self::alert( 'Add-on change needs a look', sprintf( "Subscription #%1\$d: the add-on %2\$s (%3\$s) ended at the renewal, but the domain or storage it gave could not be taken back (the server did not answer).\n\nTake it back by hand, or run the change again from the account.", $sub->get_id(), $one[1], $one[2] ) );
				}
			}
		}

		return $renewal;
	}

	/**
	 * Removes the names of a registrable domain from the account (its line has just ended).
	 *
	 * @param Resource $r   Hosting resource.
	 * @param string   $reg Registrable domain.
	 * @return bool False when a name could not be removed.
	 */
	private static function drop_domain( Resource $r, string $reg ): bool {
		$actions = new HostingActions( $r );
		$ok      = true;

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] && self::registrable( $row['host'] ) === $reg && is_wp_error( $actions->remove_domain( $row['host'], true ) ) ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Brings what the add-ons give in line with the lines now: the Email lite storage of each mailbox.
	 *
	 * @param Resource $r Hosting resource.
	 * @return bool False when the mail server could not be changed.
	 */
	public static function apply( Resource $r ): bool {
		$child = SiteMail::child_of( $r );

		return $child ? \BeltoftServices\Mail\MailPacks::apply( $child, self::packs( $r ) ) : true;
	}
}

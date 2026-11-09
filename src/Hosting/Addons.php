<?php
/**
 * Add-ons on a hosting subscription: an extra registrable domain, an extra 5 GB pack for one Email lite mailbox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\PublicSuffix;
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
	public const I_KIND      = '_beltoft_services_addon_kind';
	public const I_RESOURCE  = '_beltoft_services_addon_resource';
	public const I_TARGET    = '_beltoft_services_addon_target';
	public const I_ENDING    = '_beltoft_services_addon_ending';

	/** Storage of one pack, MB, and the most packs one mailbox can have. */
	public const PACK_MB   = 5120;
	public const MAX_PACKS = 4;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'activate' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'activate' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'activate' ), 20 );
		add_filter( 'wcs_renewal_order_created', array( __CLASS__, 'at_renewal' ), 20, 2 );
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

		if ( null === $product || null === $sub ) {
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
			/* translators: 1: what is bought, 2: price per month, 3: amount charged now, 4: number of started months, 5: price per billing period. */
			_n(
				'%1$s costs %2$s per month, excluding VAT. We charge %3$s now for the time until your next renewal (%4$d month started), and %5$s with each renewal after that. You can remove it later; it then ends at the next renewal and nothing is refunded.',
				'%1$s costs %2$s per month, excluding VAT. We charge %3$s now for the time until your next renewal (%4$d months started), and %5$s with each renewal after that. You can remove it later; it then ends at the next renewal and nothing is refunded.',
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
	 * Starts buying an add-on: the one-off order for the time until the renewal, paid with the saved card or through
	 * the pay-for-order link. The line is added when the order is paid.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Registrable domain, or mailbox address.
	 * @param int|null $now    Time (tests).
	 * @return array{status: string, order: int, total: float, pay_url: string}|\WP_Error status is paid or pay.
	 */
	public static function buy( Resource $r, string $kind, string $target, ?int $now = null ) {
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

		$order = wc_create_order( array( 'customer_id' => (int) $sub->get_customer_id(), 'created_via' => 'beltoft_services_addon' ) );

		if ( is_wp_error( $order ) ) {
			return new \WP_Error( 'failed', __( 'The order could not be made. Try again in a moment.', 'beltoft-services' ) );
		}

		$order->set_currency( $sub->get_currency() );
		$order->set_address( $sub->get_address( 'billing' ), 'billing' );
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
			)
		);
		$order->calculate_totals( true );
		$order->save();

		if ( ! $sub->is_manual() && '' !== (string) $sub->get_payment_method() && function_exists( 'wcs_copy_payment_method_to_order' ) ) {
			wcs_copy_payment_method_to_order( $sub, $order );
			$order->save();
			\WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $order );
		}

		$order = wc_get_order( $order->get_id() );
		$paid  = $order instanceof \WC_Order && ! $order->needs_payment();

		return array(
			'status'  => $paid ? 'paid' : 'pay',
			'order'   => $order->get_id(),
			'total'   => (float) $order->get_total(),
			'pay_url' => $paid ? '' : $order->get_checkout_payment_url(),
		);
	}

	/**
	 * A paid add-on order: the line goes on the subscription, once.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function activate( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		$meta  = $order instanceof \WC_Order ? $order->get_meta( self::ORDER_META ) : null;

		if ( ! is_array( $meta ) || '' !== (string) $order->get_meta( self::ORDER_DONE ) || $order->needs_payment() ) {
			return;
		}

		$r       = Resource::find( (int) ( $meta['resource'] ?? 0 ) );
		$sub     = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) ( $meta['sub'] ?? 0 ) ) : null;
		$product = self::product( (string) ( $meta['kind'] ?? '' ) );

		if ( ! $r || ! $sub instanceof \WC_Subscription || null === $product ) {
			$order->add_order_note( 'The add-on was paid but could not be added to the subscription. Check it.' );

			return;
		}

		$order->update_meta_data( self::ORDER_DONE, gmdate( 'c' ) );
		$order->save();

		$price = (string) round( (float) wc_get_price_excluding_tax( $product ) * (int) ( $meta['period'] ?? 1 ), 2 );
		$id    = $sub->add_product( $product, 1, array( 'subtotal' => $price, 'total' => $price ) );
		$item  = $id ? $sub->get_item( (int) $id ) : null;

		if ( $item instanceof \WC_Order_Item_Product ) {
			$item->update_meta_data( self::I_KIND, (string) $meta['kind'] );
			$item->update_meta_data( self::I_RESOURCE, (string) $r->id() );
			$item->update_meta_data( self::I_TARGET, (string) $meta['target'] );
			$item->save();
		}

		$sub->calculate_totals();
		$sub->add_order_note( sprintf( 'Add-on %1$s (%2$s) added after order #%3$d was paid.', (string) $meta['kind'], (string) $meta['target'], $order->get_id() ) );
		$sub->save();

		self::apply( $r );
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
				if ( self::KIND_DOMAIN === $one[1] ) {
					self::drop_domain( $r, $one[2] );
				}

				self::apply( $r );
			}
		}

		return $renewal;
	}

	/**
	 * Removes the names of a registrable domain from the account (its line has just ended).
	 *
	 * @param Resource $r   Hosting resource.
	 * @param string   $reg Registrable domain.
	 */
	private static function drop_domain( Resource $r, string $reg ): void {
		$actions = new HostingActions( $r );

		foreach ( SiteDomains::for_resource( $r->id() ) as $row ) {
			if ( 'preview' !== $row['kind'] && self::registrable( $row['host'] ) === $reg ) {
				$actions->remove_domain( $row['host'], true );
			}
		}
	}

	/**
	 * Brings what the add-ons give in line with the lines now: the Email lite storage of each mailbox.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function apply( Resource $r ): void {
		$child = SiteMail::child_of( $r );

		if ( $child ) {
			MailPacks::apply( $child, self::packs( $r ) );
		}
	}
}

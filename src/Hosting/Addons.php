<?php
/**
 * Add-ons on a hosting subscription: an extra registrable domain, an extra 5 GB pack for one Email lite mailbox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Jobs;
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
 *
 * This class is the facade: the constants, the hooks and one-line delegates for every public method. The money side
 * (quotes, orders, lines, renewal) is AddonBilling; what a paid line changes on the account (domains, mailbox
 * storage) is AddonEffects.
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
		Jobs::recurring( array( __CLASS__, 'schedule' ) );
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
		return AddonBilling::refuse_direct_add( $valid, $product_id, $quantity, $variation_id, $variation, $data );
	}

	/**
	 * The add-on product of a kind, or null when the shop has none.
	 *
	 * @param string $kind Kind.
	 */
	public static function product( string $kind ): ?\WC_Product {
		return AddonBilling::product( $kind );
	}

	/**
	 * The subscription that pays for the account.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function subscription( Resource $r ): ?\WC_Subscription {
		return AddonBilling::subscription( $r );
	}

	/**
	 * The months of the subscription's billing period (a year is 12, anything shorter than a month counts as 1).
	 *
	 * @param \WC_Subscription $sub Subscription.
	 */
	public static function period_months( \WC_Subscription $sub ): int {
		return AddonBilling::period_months( $sub );
	}

	/**
	 * Started months from now to the next renewal: the smallest number of months, at least 1, that reaches it.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @param int|null         $now Time (tests).
	 */
	public static function months_to_renewal( \WC_Subscription $sub, ?int $now = null ): int {
		return AddonBilling::months_to_renewal( $sub, $now );
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
		return AddonBilling::quote( $r, $kind, $now );
	}

	/**
	 * A price as plain text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code, '' for the current one (a quote names its own, the subscription's).
	 */
	public static function money( float $amount, string $currency = '' ): string {
		return AddonBilling::money( $amount, $currency );
	}

	/**
	 * An amount excluding VAT as the customer sees prices (AddonBilling::shown()).
	 *
	 * @param float  $excl     Amount excluding VAT.
	 * @param string $currency Currency code.
	 */
	public static function shown( float $excl, string $currency = '' ): string {
		return AddonBilling::shown( $excl, $currency );
	}

	/**
	 * What the customer confirms before an add-on is bought.
	 *
	 * @param string               $kind  Kind.
	 * @param array<string, mixed> $quote From quote().
	 */
	public static function confirm_text( string $kind, array $quote ): string {
		return AddonBilling::confirm_text( $kind, $quote );
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
		return AddonBilling::lines( $r, $kind, $target );
	}

	/**
	 * The registrable domain of a host name (the name itself when the ending is unknown).
	 *
	 * @param string $host Host.
	 */
	public static function registrable( string $host ): string {
		return AddonEffects::registrable( $host );
	}

	/**
	 * The registrable domain a new name would add to the account when it is not the account's first one, else null
	 * (the first domain and everything under a domain the account has are free).
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $host New host name.
	 */
	public static function domain_needing_addon( Resource $r, string $host ): ?string {
		return AddonEffects::domain_needing_addon( $r, $host );
	}

	/**
	 * Does the account have a line for this target (one that is ending still counts until the renewal)?
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $kind   Kind.
	 * @param string   $target Target.
	 */
	public static function has( Resource $r, string $kind, string $target ): bool {
		return AddonBilling::has( $r, $kind, $target );
	}

	/**
	 * Registrable domains whose add-on line ends at the next renewal.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string>
	 */
	public static function ending_domains( Resource $r ): array {
		return AddonEffects::ending_domains( $r );
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
		return AddonBilling::keep( $r, $kind, $target );
	}

	/**
	 * Packs of each Email lite mailbox, lines that are ending included (they still hold the storage).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, int> Mailbox address => packs.
	 */
	public static function packs( Resource $r ): array {
		return AddonEffects::packs( $r );
	}

	/**
	 * Mails the shop owner about a payment that needs a person (never silent).
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 */
	public static function alert( string $subject, string $body ): void {
		AddonBilling::alert( $subject, $body );
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
		return AddonBilling::buy( $r, $kind, $target, $now, $connect );
	}

	/**
	 * Cancels the unpaid add-on orders that have waited longer than the quote holds.
	 */
	public static function cancel_stale(): void {
		AddonBilling::cancel_stale();
	}

	/**
	 * A paid add-on order: the line goes on the subscription, once.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function activate( $order_id ): void {
		AddonBilling::activate( $order_id );
	}

	/**
	 * Paid extra domains that are not on a website yet (the customer has not added them, or they are being connected).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, string> Registrable domains.
	 */
	public static function unconnected_domains( Resource $r ): array {
		return AddonEffects::unconnected_domains( $r );
	}

	/**
	 * A one-off add-on order that was refunded or cancelled after its line was added: the line is marked as ending, so it
	 * leaves at the next renewal like a normal removal (the domain or storage stays until then).
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function reverse( $order_id ): void {
		AddonBilling::reverse( $order_id );
	}

	/**
	 * After a plan switch the billing period may have changed: the add-on lines bill the price for the new period.
	 *
	 * @param mixed $order Switch order.
	 */
	public static function after_switch( $order ): void {
		AddonBilling::after_switch( $order );
	}

	/**
	 * Sets each add-on line of a subscription to the unit price times the months of its billing period.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 */
	public static function resync_prices( \WC_Subscription $sub ): void {
		AddonBilling::resync_prices( $sub );
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
		return AddonBilling::end( $r, $kind, $target );
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
		return AddonBilling::at_renewal( $renewal, $sub );
	}

	/**
	 * Brings what the add-ons give in line with the lines now: the Email lite storage of each mailbox.
	 *
	 * @param Resource $r Hosting resource.
	 * @return bool False when the mail server could not be changed.
	 */
	public static function apply( Resource $r ): bool {
		return AddonEffects::apply( $r );
	}
}

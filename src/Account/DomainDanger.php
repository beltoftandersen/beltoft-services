<?php
/**
 * The Danger zone tab of a domain page: auto-renew for a registered domain, stopping DNS hosting for a DNS-only one.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftLicenses\Licenses;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Both actions use WooCommerce Subscriptions' own engine and nothing here deletes anything at the registrar:
 *
 *  - Auto-renew: the same calls as WooCommerce Subscriptions' own toggle on the subscription page
 *    (WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal(), WC_Subscription::set_requires_manual_renewal()).
 *    With auto-renew off the customer gets an invoice by email instead of a charge; the domain stays registered
 *    until its end date.
 *  - Stop DNS hosting (free DNS-only service): after the customer types the domain name, the subscription is
 *    cancelled with WooCommerce Subscriptions' own customer status change
 *    (WCS_User_Change_Status_Handler::change_users_subscription(), what the Cancel button of the subscription
 *    page runs). DnsCancel ends a free DNS subscription at once and the records stop answering.
 *
 * The forms are plain posts to the page with a nonce: the notices WooCommerce shows need a session, and a form
 * works without a script.
 */
final class DomainDanger {

	/**
	 * Name of the posted field that says what to do.
	 */
	public const FIELD = 'beltoft_domain_danger';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_domain_tabs', array( __CLASS__, 'tabs' ), 20, 2 );
		add_action( 'beltoft_services_domain_tab_pane', array( __CLASS__, 'pane' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
	}

	/**
	 * Adds the tab when the customer has something to do there.
	 *
	 * @param mixed $tabs        Tabs, slug => title.
	 * @param mixed $resource_id Resource id.
	 * @return mixed
	 */
	public static function tabs( $tabs, $resource_id = 0 ) {
		$r = is_array( $tabs ) ? Resource::find( (int) $resource_id ) : null;

		if ( $r && null !== self::offer( $r, get_current_user_id() ) ) {
			$tabs['danger'] = __( 'Danger zone', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * Prints the pane of the tab.
	 *
	 * @param mixed $slug Tab slug.
	 * @param mixed $r    Resource.
	 * @param mixed $d    Page values.
	 */
	public static function pane( $slug, $r = null, $d = array() ): void {
		unset( $d );

		if ( 'danger' !== $slug || ! $r instanceof Resource ) {
			return;
		}

		$offer = self::offer( $r, get_current_user_id() );

		if ( null === $offer ) {
			return;
		}

		$license = $r->license();
		$vars    = array(
			'offer'   => $offer,
			'r'       => $r,
			'domain'  => (string) $r->get( 'domain' ),
			'action'  => Domains::url( $license, 'danger' ),
			'nonce'   => wp_create_nonce( self::nonce_action( (int) $license['id'] ) ),
			'expires' => self::date( (string) $r->get( 'expires_at' ), true ),
			'license' => (int) $license['id'],
		);

		( static function ( array $vars ): void {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, a fixed list built above.
			extract( $vars, EXTR_SKIP );
			include Domains::file( 'domain-danger.php' );
		} )( $vars );
	}

	/**
	 * What the danger tab offers a customer for a domain, or null when it has nothing to show.
	 *
	 * @param Resource $r       The domain's resource.
	 * @param int      $user_id Customer.
	 * @return array{kind: string, subscription: \WC_Subscription, manual?: bool, can_enable?: bool, next?: string}|null
	 */
	public static function offer( Resource $r, int $user_id ): ?array {
		$license = $r->license();

		if ( $user_id <= 0 || ! $license || (int) ( $license['user_id'] ?? 0 ) !== $user_id || $r->folded_into() > 0 || ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}

		$subscription = (int) ( $license['subscription_id'] ?? 0 ) > 0 ? wcs_get_subscription( (int) $license['subscription_id'] ) : null;

		if ( ! $subscription instanceof \WC_Subscription || (int) $subscription->get_user_id() !== $user_id ) {
			return null;
		}

		$driver = (string) $r->get( 'driver' );

		if ( 'domain' === $driver ) {
			if ( ! class_exists( 'WCS_My_Account_Auto_Renew_Toggle' ) || ! \WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $subscription, $user_id ) ) {
				return null;
			}

			$manual = (bool) $subscription->is_manual();

			return array(
				'kind'         => 'renew',
				'subscription' => $subscription,
				'manual'       => $manual,
				// WooCommerce Subscriptions turns auto-renew on only when there is a payment method to charge.
				'can_enable'   => ! $manual || (bool) wc_get_payment_gateway_by_order( $subscription ),
				'next'         => self::date( (string) $subscription->get_date( 'next_payment', 'site' ) ),
			);
		}

		// DNS hosting is free: it ends at once when cancelled (DnsCancel).
		if ( 'dns' === $driver && 0.0 === (float) $subscription->get_total() && $subscription->can_be_updated_to( 'cancelled' ) && user_can( $user_id, 'edit_shop_subscription_status', $subscription->get_id() ) ) {
			return array(
				'kind'         => 'stop',
				'subscription' => $subscription,
			);
		}

		return null;
	}

	/**
	 * The nonce action of a license's danger forms.
	 *
	 * @param int $license_id License id.
	 */
	public static function nonce_action( int $license_id ): string {
		return 'beltoft-services-domain-danger-' . $license_id;
	}

	/**
	 * A date in the site's format, or ''.
	 *
	 * @param string $value A date as text.
	 * @param bool   $utc   Whether it is UTC (registrar dates are).
	 */
	private static function date( string $value, bool $utc = false ): string {
		$time = '' !== $value && '0' !== $value ? strtotime( $value . ( $utc ? ' UTC' : '' ) ) : false;

		return $time ? wp_date( (string) get_option( 'date_format' ), $time ) : '';
	}

	/**
	 * Listener of template_redirect: a posted danger form. Checks the nonce, does the action and sends the customer
	 * back with a notice.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below, per license.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::FIELD ] ) || ! is_user_logged_in() ) {
			return;
		}

		$license_id = isset( $_POST['license'] ) ? absint( wp_unslash( $_POST['license'] ) ) : 0;
		$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$post       = array(
			'action'  => sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ),
			'license' => $license_id,
			'confirm' => isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $license_id <= 0 || ! wp_verify_nonce( $nonce, self::nonce_action( $license_id ) ) ) {
			wc_add_notice( __( 'This page expired. Reload it and try again.', 'beltoft-services' ), 'error' );
			wp_safe_redirect( Domains::url_list() );
			exit;
		}

		$result = self::process( get_current_user_id(), $post );

		if ( '' !== $result['message'] ) {
			wc_add_notice( $result['message'], $result['ok'] ? 'success' : 'error' );
		}

		wp_safe_redirect( $result['redirect'] );
		exit;
	}

	/**
	 * Does one posted action for a customer.
	 *
	 * @param int                  $user_id Customer.
	 * @param array<string, mixed> $post    action (auto_renew_off, auto_renew_on, stop_hosting), license, confirm.
	 * @return array{ok: bool, message: string, redirect: string}
	 */
	public static function process( int $user_id, array $post ): array {
		$license  = Licenses::find( (int) ( $post['license'] ?? 0 ) );
		$resource = $license ? Resource::for_license( (int) $license['id'] ) : null;
		$list     = Domains::url_list();

		if ( ! $license || ! $resource || ! Domains::is_domain( $license ) || (int) ( $license['user_id'] ?? 0 ) !== $user_id ) {
			return array( 'ok' => false, 'message' => __( 'That domain was not found.', 'beltoft-services' ), 'redirect' => $list );
		}

		$back  = Domains::url( $license, 'danger' );
		$offer = self::offer( $resource, $user_id );
		$what  = (string) ( $post['action'] ?? '' );

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:domain-danger', 10, MINUTE_IN_SECONDS ) ) {
			return array( 'ok' => false, 'message' => __( 'Too many requests. Wait a minute and try again.', 'beltoft-services' ), 'redirect' => $back );
		}

		$kinds = array(
			'auto_renew_off' => 'renew',
			'auto_renew_on'  => 'renew',
			'stop_hosting'   => 'stop',
		);

		if ( null === $offer || ! isset( $kinds[ $what ] ) || $kinds[ $what ] !== $offer['kind'] ) {
			return array( 'ok' => false, 'message' => __( 'That is not available for this domain.', 'beltoft-services' ), 'redirect' => Domains::url( $license ) );
		}

		$subscription = $offer['subscription'];

		if ( 'stop_hosting' === $what ) {
			if ( strtolower( trim( (string) ( $post['confirm'] ?? '' ) ) ) !== strtolower( (string) $resource->get( 'domain' ) ) ) {
				return array( 'ok' => false, 'message' => __( 'Type the domain name exactly as shown to stop DNS hosting.', 'beltoft-services' ), 'redirect' => $back );
			}

			// What the Cancel button on the subscription page runs; it adds WooCommerce's own notice.
			\WCS_User_Change_Status_Handler::change_users_subscription( $subscription, 'cancelled' );

			return array( 'ok' => true, 'message' => '', 'redirect' => $list );
		}

		$done = self::set_auto_renew( $subscription, 'auto_renew_on' === $what );

		return array( 'ok' => $done['ok'], 'message' => $done['message'], 'redirect' => $back );
	}

	/**
	 * Turns automatic renewal of a subscription on or off and leaves a note on it. The caller has checked that the
	 * customer owns the subscription and that WooCommerce Subscriptions lets them toggle it (offer(), or
	 * WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal).
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @param bool             $on           Renew by itself.
	 * @param bool             $by_app       The customer asked a connected app (Claude) to do it, not the page.
	 * @return array{ok: bool, message: string}
	 */
	public static function set_auto_renew( \WC_Subscription $subscription, bool $on, bool $by_app = false ): array {
		$manual = (bool) $subscription->is_manual();

		if ( $on ) {
			if ( ! $manual ) {
				return array( 'ok' => true, 'message' => '' );
			}

			// WooCommerce Subscriptions turns auto-renew on only when there is a payment method to charge.
			if ( ! wc_get_payment_gateway_by_order( $subscription ) ) {
				return array( 'ok' => false, 'message' => __( 'Add a payment method to your account first, then turn auto-renew back on.', 'beltoft-services' ) );
			}

			$subscription->set_requires_manual_renewal( false );
			$subscription->add_order_note( $by_app ? __( 'Customer turned on automatic renewals through Claude.', 'beltoft-services' ) : __( 'Customer turned on automatic renewals on the domain page of their account.', 'beltoft-services' ) );
			$subscription->save();

			return array( 'ok' => true, 'message' => __( 'Auto-renew is on.', 'beltoft-services' ) );
		}

		if ( $manual ) {
			return array( 'ok' => true, 'message' => '' );
		}

		$subscription->set_requires_manual_renewal( true );
		$subscription->add_order_note( $by_app ? __( 'Customer turned off automatic renewals through Claude.', 'beltoft-services' ) : __( 'Customer turned off automatic renewals on the domain page of their account.', 'beltoft-services' ) );
		$subscription->save();

		return array( 'ok' => true, 'message' => __( 'Auto-renew is off.', 'beltoft-services' ) );
	}
}

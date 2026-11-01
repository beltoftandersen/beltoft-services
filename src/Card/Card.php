<?php
/**
 * Saved card: add one without ordering, show it, replace it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Card;

use BeltoftServices\Account\Panel;
use BeltoftServices\RateLimit;
use BeltoftServices\Usage\Account;

defined( 'ABSPATH' ) || exit;

/**
 * The card is the Mollie mandate on the customer's usage account subscription, which usage invoices charge.
 *
 * Add: a cart with only the hidden usage account line (flagged with CART_KEY, 0 total), checked out the normal
 * way; Mollie's hosted page takes a 0 first payment and the Mollie plugin stores the mandate on the subscription.
 * Replace: a new 0 first payment for the same Mollie customer, created here with a webhook of ours; when Mollie
 * says it is paid (fetched, never taken from the request), the new mandate goes on the subscription and the old
 * one is revoked. The Mollie plugin's renewal reads `_mollie_mandate_id` and `_mollie_customer_id` of the
 * subscription, so the next usage invoice charges the new mandate.
 */
final class Card {

	/**
	 * Cart item key of the account line made to save a card.
	 */
	public const CART_KEY = 'beltoft_services_card_setup';

	public const NONCE          = 'beltoft_services_card';
	public const ACTION_ADD     = 'beltoft_services_card_add';
	public const ACTION_REPLACE = 'beltoft_services_card_replace';
	public const QUERY          = 'beltoft_card';

	/**
	 * Subscription meta: Mollie payment ids of replace payments started and not yet applied, and those applied.
	 */
	public const META_PENDING = '_beltoft_services_card_pending';
	public const META_DONE    = '_beltoft_services_card_done';

	/**
	 * Seconds the mandate details are kept.
	 */
	public const CACHE_TTL = 600;

	/**
	 * Replace payments one customer can start in the window.
	 */
	public const REPLACE_LIMIT  = 5;
	public const REPLACE_WINDOW = 600;

	public const NAMESPACE_REST = 'beltoft-services/v1';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
		add_action( 'template_redirect', array( __CLASS__, 'back_to_billing' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 40 );
		add_action( 'woocommerce_account_payment-methods_endpoint', array( __CLASS__, 'swap_page' ), 1 );
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'bricks_methods' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'gateways' ), 30 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_name' ), 20, 2 );
	}

	/**
	 * The payment methods page address.
	 *
	 * @param array<string, string> $args Query args.
	 */
	public static function url( array $args = array() ): string {
		$url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'payment-methods' ) : home_url( '/' );

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * The page is listed even when no gateway offers saved cards (the Mollie plugin does not), so the card box has a place.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public static function menu( $items ) {
		if ( is_array( $items ) && ! isset( $items['payment-methods'] ) && is_user_logged_in() ) {
			$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();
			unset( $items['customer-logout'] );
			$items['payment-methods'] = __( 'Payment methods', 'beltoft-services' );
			$items                    = $items + $logout;
		}

		return $items;
	}

	/**
	 * The page shows the card box, then WooCommerce's own list only when the customer has saved methods of its kind.
	 */
	public static function swap_page(): void {
		remove_action( 'woocommerce_account_payment-methods_endpoint', 'woocommerce_account_payment_methods' );
		add_action( 'woocommerce_account_payment-methods_endpoint', array( __CLASS__, 'render' ), 9 );
		add_action( 'woocommerce_account_payment-methods_endpoint', array( __CLASS__, 'render_tokens' ), 10 );
	}

	/**
	 * With Bricks the payment methods list is its own element: the card box goes in front of it, and the list
	 * (which only says that nothing is saved) stays only when the customer has saved methods of its kind.
	 *
	 * @param mixed $html    The element's markup.
	 * @param mixed $element The element.
	 * @return mixed
	 */
	public static function bricks_methods( $html, $element ) {
		if ( ! is_string( $html ) || ! is_object( $element ) || 'woocommerce-account-payment-methods' !== ( $element->name ?? '' ) || ! is_user_logged_in() ) {
			return $html;
		}

		$has = function_exists( 'wc_get_customer_saved_methods_list' ) && wc_get_customer_saved_methods_list( get_current_user_id() );

		return self::html() . ( $has ? $html : '' );
	}

	/**
	 * Endpoint output of the card box.
	 */
	public static function render(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}

	/**
	 * WooCommerce's list of saved methods, only when there are any.
	 */
	public static function render_tokens(): void {
		if ( function_exists( 'wc_get_customer_saved_methods_list' ) && wc_get_customer_saved_methods_list( get_current_user_id() ) && function_exists( 'woocommerce_account_payment_methods' ) ) {
			woocommerce_account_payment_methods();
		}
	}

	/**
	 * The box's markup.
	 */
	public static function html(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		Panel::style();

		ob_start();
		wc_get_template( 'card.php', array( 'card' => self::data( get_current_user_id() ) ), 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

		return (string) ob_get_clean();
	}

	// ------------------------------------------------------------------ the saved card.

	/**
	 * The customer's Mollie customer id on the account, or ''.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function customer_id( \WC_Subscription $account ): string {
		$id = (string) $account->get_meta( '_mollie_customer_id', true );

		if ( '' === $id && $account->get_parent() ) {
			$id = (string) $account->get_parent()->get_meta( '_mollie_customer_id', true );
		}

		return $id;
	}

	/**
	 * The mandate id on the account, or ''.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function mandate_id( \WC_Subscription $account ): string {
		$id = (string) $account->get_meta( '_mollie_mandate_id', true );

		if ( '' === $id && $account->get_parent() ) {
			$id = (string) $account->get_parent()->get_meta( '_mollie_mandate_id', true );
		}

		return $id;
	}

	/**
	 * A list kept in subscription meta, without empty entries.
	 *
	 * @param \WC_Subscription $account Account.
	 * @param string           $key     Meta key.
	 * @return string[]
	 */
	private static function ids( \WC_Subscription $account, string $key ): array {
		return array_values( array_filter( array_map( 'strval', (array) $account->get_meta( $key, true ) ), 'strlen' ) );
	}

	/**
	 * What Mollie says about the account's mandate, cached for ten minutes (a failed look-up is not cached).
	 *
	 * @param \WC_Subscription $account Account.
	 * @return array{status: string, expired: bool, label: string, last4: string, expiry: string}|null Null when the account has no mandate.
	 */
	public static function details( \WC_Subscription $account ): ?array {
		$customer = self::customer_id( $account );
		$mandate  = self::mandate_id( $account );

		if ( '' === $customer || '' === $mandate ) {
			return null;
		}

		$name   = self::cache_name( $customer, $mandate );
		$cached = get_transient( $name );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$raw = Mollie::mandate( $customer, $mandate );

		if ( is_wp_error( $raw ) ) {
			return array(
				'status'  => 'unknown',
				'expired' => false,
				'label'   => '',
				'last4'   => '',
				'expiry'  => '',
			);
		}

		$info = self::summarize( $raw );
		set_transient( $name, $info, self::CACHE_TTL );

		return $info;
	}

	/**
	 * Transient name of one mandate's details.
	 *
	 * @param string $customer Customer id.
	 * @param string $mandate  Mandate id.
	 */
	private static function cache_name( string $customer, string $mandate ): string {
		return 'beltoft_services_card_' . substr( md5( $customer . '|' . $mandate ), 0, 24 );
	}

	/**
	 * Forgets the cached details of the account's mandate.
	 *
	 * @param string $customer Customer id.
	 * @param string $mandate  Mandate id.
	 */
	public static function forget( string $customer, string $mandate ): void {
		delete_transient( self::cache_name( $customer, $mandate ) );
	}

	/**
	 * A Mollie mandate as the few facts the page needs.
	 *
	 * @param array<string, mixed> $raw Mandate.
	 * @return array{status: string, expired: bool, label: string, last4: string, expiry: string}
	 */
	public static function summarize( array $raw ): array {
		$details = isset( $raw['details'] ) && is_array( $raw['details'] ) ? $raw['details'] : array();
		$status  = isset( $raw['status'] ) ? sanitize_key( (string) $raw['status'] ) : 'unknown';
		$expiry  = '';
		$expired = false;

		if ( ! empty( $details['cardExpiryDate'] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $details['cardExpiryDate'], $m ) ) {
			$expiry  = $m[2] . '/' . substr( $m[1], 2 );
			$expired = gmdate( 'Y-m-d' ) > $m[0];
		}

		$label = isset( $details['cardLabel'] ) ? sanitize_text_field( (string) $details['cardLabel'] ) : '';

		if ( '' === $label && 'creditcard' !== ( $raw['method'] ?? '' ) && ! empty( $raw['method'] ) ) {
			$label = ucfirst( sanitize_text_field( str_replace( 'directdebit', 'direct debit', (string) $raw['method'] ) ) );
		}

		return array(
			'status'  => $status,
			'expired' => $expired,
			'label'   => $label,
			'last4'   => isset( $details['cardNumber'] ) ? preg_replace( '/\D/', '', (string) $details['cardNumber'] ) : '',
			'expiry'  => $expiry,
		);
	}

	/**
	 * "Visa ending 4242, expires 08/28".
	 *
	 * @param array{status: string, expired: bool, label: string, last4: string, expiry: string} $info Details.
	 */
	public static function describe( array $info ): string {
		$label = '' !== $info['label'] ? $info['label'] : __( 'Card', 'beltoft-services' );
		$text  = '' !== $info['last4']
			/* translators: 1: card brand, 2: last four digits. */
			? sprintf( __( '%1$s ending %2$s', 'beltoft-services' ), $label, $info['last4'] )
			: $label;

		if ( '' !== $info['expiry'] ) {
			/* translators: 1: card, 2: expiry as MM/YY. */
			$text = sprintf( __( '%1$s, expires %2$s', 'beltoft-services' ), $text, $info['expiry'] );
		}

		return $text;
	}

	/**
	 * Whether the details are of a card that can be charged.
	 *
	 * @param array{status: string, expired: bool, label: string, last4: string, expiry: string}|null $info Details.
	 */
	public static function usable( ?array $info ): bool {
		return is_array( $info ) && 'valid' === $info['status'] && ! $info['expired'];
	}

	/**
	 * What the page shows.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	public static function data( int $user_id ): array {
		$account = Account::for_user( $user_id, Account::ESTABLISHED );
		$notice  = '';
		$kind    = 'success';

		if ( $account ) {
			self::sync_pending( $account );
			$account = Account::for_user( $user_id, Account::ESTABLISHED );
		}

		$info  = $account ? self::details( $account ) : null;
		$state = 'none';

		if ( $info ) {
			$state = 'unknown' === $info['status'] ? 'unknown' : ( self::usable( $info ) ? 'valid' : 'expired' );
		}

		$returned = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::QUERY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a message.

		if ( 'return' === $returned || 'replaced' === $returned ) {
			if ( 'valid' === $state ) {
				$notice = 'replaced' === $returned ? __( 'Card replaced.', 'beltoft-services' ) : __( 'Card saved.', 'beltoft-services' );
			} elseif ( 'unknown' === $state ) {
				$notice = __( 'We could not check your card just now. Reload this page in a moment.', 'beltoft-services' );
				$kind   = 'info';
			} else {
				$notice = __( 'The card was not saved. You can try again.', 'beltoft-services' );
				$kind   = 'error';
			}
		} elseif ( 'limit' === $returned ) {
			$notice = __( 'Too many attempts. Try again in a few minutes.', 'beltoft-services' );
			$kind   = 'error';
		} elseif ( 'failed' === $returned ) {
			$notice = __( 'The card page could not be opened. Try again in a moment.', 'beltoft-services' );
			$kind   = 'error';
		} elseif ( 'cart' === $returned ) {
			$notice = __( 'Your cart has other items. Check out or empty your cart first, then add a card.', 'beltoft-services' );
			$kind   = 'error';
		}

		return array(
			'state'       => $state,
			'text'        => $info && 'unknown' !== $info['status'] ? self::describe( $info ) : '',
			'can_add'     => null === $account,
			'can_replace' => $account && '' !== self::customer_id( $account ) && Mollie::configured(),
			'notice'      => $notice,
			'notice_kind' => $kind,
			'add_action'  => self::ACTION_ADD,
			'replace_action' => self::ACTION_REPLACE,
		);
	}

	// ------------------------------------------------------------------ add and replace.

	/**
	 * The two forms: sends the customer on.
	 */
	public static function handle(): void {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}

		$to = self::dispatch( get_current_user_id(), $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in dispatch().

		if ( null === $to ) {
			return;
		}

		if ( str_starts_with( $to, 'https://' ) && ! str_starts_with( $to, home_url() ) ) {
			// Mollie's hosted page.
			wp_redirect( $to ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The address Mollie gave for this payment.
		} else {
			wp_safe_redirect( $to );
		}

		exit;
	}

	/**
	 * What a posted form does. Both forms need a login and the nonce.
	 *
	 * @param int                  $user_id Current user (0 for a visitor).
	 * @param array<string, mixed> $post    Posted fields.
	 * @return string|null Where to send the customer, null when the post is not one of ours.
	 */
	public static function dispatch( int $user_id, array $post ): ?string {
		$add     = ! empty( $post[ self::ACTION_ADD ] );
		$replace = ! empty( $post[ self::ACTION_REPLACE ] );

		if ( ! $add && ! $replace ) {
			return null;
		}

		if ( $user_id < 1 ) {
			return wc_get_page_permalink( 'myaccount' );
		}

		$nonce = isset( $post['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $post['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wc_add_notice( __( 'The page expired. Please try again.', 'beltoft-services' ), 'error' );

			return self::url();
		}

		return $add ? self::start_add( $user_id ) : self::start_replace( $user_id );
	}

	/**
	 * Puts the card-only account line in the cart.
	 *
	 * @param int $user_id Customer.
	 * @return string Where to go next.
	 */
	public static function start_add( int $user_id ): string {
		if ( Account::for_user( $user_id, Account::ESTABLISHED ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return self::url();
		}

		$cart = WC()->cart;

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) && (int) $item['product_id'] !== (int) get_option( Account::OPTION_PRODUCT ) ) {
				return self::url( array( self::QUERY => 'cart' ) );
			}
		}

		$cart->empty_cart();

		$key = $cart->add_to_cart( Account::product_id(), 1, 0, array(), array( self::CART_KEY => 1 ) );

		return false === $key ? self::url( array( self::QUERY => 'failed' ) ) : wc_get_checkout_url();
	}

	/**
	 * Creates the 0 first payment of a replacement.
	 *
	 * @param int $user_id Customer.
	 * @return string Mollie's checkout address, or a page of ours with a message.
	 */
	public static function start_replace( int $user_id ): string {
		$account = Account::for_user( $user_id, Account::ESTABLISHED );

		if ( ! $account || '' === self::customer_id( $account ) ) {
			return self::url();
		}

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:card-replace', self::REPLACE_LIMIT, self::REPLACE_WINDOW ) ) {
			return self::url( array( self::QUERY => 'limit' ) );
		}

		$payment = Mollie::create_payment(
			array(
				'amount'       => array(
					'currency' => get_woocommerce_currency(),
					'value'    => '0.00',
				),
				'description'  => __( 'Replace card for Pay as you go', 'beltoft-services' ),
				'customerId'   => self::customer_id( $account ),
				'sequenceType' => 'first',
				'method'       => 'creditcard',
				'redirectUrl'  => self::url( array( self::QUERY => 'replaced' ) ),
				'webhookUrl'   => self::webhook_url(),
				'metadata'     => array(
					'beltoft_card_subscription' => $account->get_id(),
					'beltoft_card_user'         => $user_id,
				),
			)
		);

		$href = is_array( $payment ) && isset( $payment['_links']['checkout']['href'] ) ? (string) $payment['_links']['checkout']['href'] : '';

		if ( is_wp_error( $payment ) || empty( $payment['id'] ) || '' === $href ) {
			return self::url( array( self::QUERY => 'failed' ) );
		}

		$pending   = self::ids( $account, self::META_PENDING );
		$pending[] = (string) $payment['id'];
		$account->update_meta_data( self::META_PENDING, array_values( array_slice( array_unique( $pending ), -5 ) ) );
		$account->save();

		return $href;
	}

	/**
	 * The address Mollie calls when a replace payment changes.
	 */
	public static function webhook_url(): string {
		/**
		 * Filters the webhook address of a replace payment (a site behind a tunnel or proxy).
		 *
		 * @param string $url The REST route.
		 */
		return (string) apply_filters( 'beltoft_services_card_webhook_url', rest_url( self::NAMESPACE_REST . '/card/webhook' ) );
	}

	// ------------------------------------------------------------------ the webhook.

	/**
	 * REST route.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE_REST,
			'/card/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'webhook' ),
				// Public by design: Mollie calls it. The body is never believed: the payment is fetched from Mollie.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Mollie says a payment changed (form field id=tr_...).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,40}$/', $id ) || RateLimit::exceeded( RateLimit::visitor(), 'beltoft-services:card-webhook', 120 ) ) {
			return new \WP_REST_Response( array( 'result' => 'ignored' ), 200 );
		}

		$result = self::apply_payment( $id );

		// Mollie tries again after a failure on our side only.
		return new \WP_REST_Response( array( 'result' => $result ), 'error' === $result ? 503 : 200 );
	}

	/**
	 * Applies the replace payments the customer started (when they come back, in case the webhook is late).
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function sync_pending( \WC_Subscription $account ): void {
		foreach ( self::ids( $account, self::META_PENDING ) as $id ) {
			if ( is_string( $id ) && '' !== $id ) {
				self::apply_payment( $id );
			}
		}
	}

	/**
	 * Fetches the payment from Mollie and, when it is a paid replace payment we started, swaps the mandate.
	 * Safe to call again and from two requests at once: a payment is applied once.
	 *
	 * @param string $id Mollie payment id.
	 * @return string saved, done (already applied), waiting (not paid yet), ignored or error.
	 */
	public static function apply_payment( string $id ): string {
		$payment = Mollie::payment( $id );

		if ( is_wp_error( $payment ) ) {
			return 404 === (int) str_replace( 'beltoft_services_mollie_', '', $payment->get_error_code() ) ? 'ignored' : 'error';
		}

		$sub_id  = isset( $payment['metadata']['beltoft_card_subscription'] ) ? (int) $payment['metadata']['beltoft_card_subscription'] : 0;
		$account = $sub_id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;

		if ( ! $account instanceof \WC_Subscription || ! Account::is_account( $account ) ) {
			return 'ignored';
		}

		global $wpdb;

		$lock = 'bs_card_' . substr( md5( DB_NAME . $wpdb->prefix . $sub_id ), 0, 20 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock.
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );

		if ( 1 !== $got ) {
			return 'error';
		}

		try {
			// Read again under the lock.
			$account = wcs_get_subscription( $sub_id );

			if ( ! $account instanceof \WC_Subscription ) {
				return 'ignored';
			}

			if ( in_array( $id, self::ids( $account, self::META_DONE ), true ) ) {
				return 'done';
			}

			// Only a payment this site started for this account, for its Mollie customer, as a first payment.
			if ( ! in_array( $id, self::ids( $account, self::META_PENDING ), true ) || ( $payment['customerId'] ?? '' ) !== self::customer_id( $account ) || 'first' !== ( $payment['sequenceType'] ?? '' ) ) {
				return 'ignored';
			}

			if ( 'paid' !== ( $payment['status'] ?? '' ) || empty( $payment['mandateId'] ) ) {
				return 'waiting';
			}

			self::swap_mandate( $account, (string) $payment['mandateId'] );

			$done   = self::ids( $account, self::META_DONE );
			$done[] = $id;
			$account->update_meta_data( self::META_DONE, array_values( array_slice( array_unique( $done ), -20 ) ) );
			$account->update_meta_data( self::META_PENDING, array_values( array_diff( self::ids( $account, self::META_PENDING ), array( $id ) ) ) );
			$account->save();

			return 'saved';
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Puts a new mandate on the account (and its first order, as the Mollie plugin does) and revokes the old one.
	 *
	 * @param \WC_Subscription $account Account.
	 * @param string           $new     New mandate id.
	 */
	private static function swap_mandate( \WC_Subscription $account, string $new ): void {
		$old      = (string) $account->get_meta( '_mollie_mandate_id', true );
		$customer = self::customer_id( $account );

		$account->update_meta_data( '_mollie_mandate_id', $new );
		$account->update_meta_data( '_mollie_customer_id', $customer );
		$account->save();

		$parent = $account->get_parent();

		if ( $parent ) {
			$parent->update_meta_data( '_mollie_mandate_id', $new );
			$parent->save();
		}

		self::forget( $customer, $old );
		self::forget( $customer, $new );

		$note = __( 'Card replaced: a new payment method is saved for usage invoices.', 'beltoft-services' );

		if ( '' !== $old && $old !== $new ) {
			$revoked = Mollie::revoke( $customer, $old );

			if ( is_wp_error( $revoked ) ) {
				$note .= ' ' . __( 'The old one could not be revoked at Mollie.', 'beltoft-services' );
			}
		}

		$account->add_order_note( $note );
	}

	// ------------------------------------------------------------------ the checkout of a card-only cart.

	/**
	 * Whether the cart holds the card-only account line.
	 *
	 * @param \WC_Cart|null $cart Cart.
	 */
	public static function cart_has_setup( ?\WC_Cart $cart = null ): bool {
		$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );

		foreach ( $cart ? $cart->get_cart() : array() as $item ) {
			if ( ! empty( $item[ self::CART_KEY ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A card can only be saved with a payment method that makes a mandate.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors Errors.
	 */
	public static function validate( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! is_array( $data ) || ! self::cart_has_setup() || Account::for_user( get_current_user_id(), Account::ESTABLISHED ) ) {
			return;
		}

		$methods = function_exists( 'WC' ) ? WC()->payment_gateways()->payment_gateways() : array();
		$chosen  = (string) ( $data['payment_method'] ?? '' );

		if ( '' === $chosen || ! isset( $methods[ $chosen ] ) || ! $methods[ $chosen ]->supports( 'subscriptions' ) ) {
			$errors->add( 'beltoft_services_card', __( 'Choose a card as the payment method to save a card.', 'beltoft-services' ) );
		}
	}

	/**
	 * A card-only cart offers the card method only (when it is available).
	 *
	 * @param mixed $gateways Available gateways.
	 * @return mixed
	 */
	public static function gateways( $gateways ) {
		if ( ! is_array( $gateways ) || is_admin() || ! self::cart_has_setup() ) {
			return $gateways;
		}

		/**
		 * The payment method ids that can save a card.
		 *
		 * @param string[] $ids Default: Mollie's card method.
		 */
		$ids  = (array) apply_filters( 'beltoft_services_card_gateways', array( 'mollie_wc_gateway_creditcard' ) );
		$kept = array_intersect_key( $gateways, array_flip( $ids ) );

		return $kept ? $kept : $gateways;
	}

	/**
	 * The line reads "Card for Pay as you go" in the cart of a card-only checkout.
	 *
	 * @param mixed $name Name.
	 * @param mixed $item Cart item.
	 * @return mixed
	 */
	public static function cart_name( $name, $item = array() ) {
		return is_array( $item ) && ! empty( $item[ self::CART_KEY ] ) ? esc_html__( 'Card for Pay as you go', 'beltoft-services' ) : $name;
	}

	/**
	 * After a card-only order is paid, Mollie sends the customer to the order-received page: they go to the
	 * payment methods page instead, where the card shows.
	 */
	public static function back_to_billing(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) || ! is_user_logged_in() ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The order key is the check.

		if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), $key ) || (int) $order->get_customer_id() !== get_current_user_id() || ! self::is_card_order( $order ) ) {
			return;
		}

		wp_safe_redirect( self::url( array( self::QUERY => 'return' ) ) );
		exit;
	}

	/**
	 * Is this order only the card account line?
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_card_order( \WC_Order $order ): bool {
		$items = $order->get_items();
		$id    = (int) get_option( Account::OPTION_PRODUCT );

		return 1 === count( $items ) && $id > 0 && (int) reset( $items )->get_product_id() === $id;
	}
}

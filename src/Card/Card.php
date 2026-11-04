<?php
/**
 * Saved card: add or change it on Mollie's hosted card page, with no order and no checkout.
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
 * Add and change are one flow: a button posts here, a 0.00 first payment (method creditcard) is made for the
 * customer's Mollie customer, and the customer goes to Mollie's hosted card page, then back to Payment methods.
 * When Mollie says the payment is paid (fetched, never taken from the request; the webhook and the return both
 * do this, once) the mandate goes on the usage account subscription. A customer with no account gets one made
 * right there: an active 0 per month subscription with the card on it, the same state a server order's checkout
 * leaves, so later pay-as-you-go orders attach to it. The Mollie plugin's renewal reads `_mollie_mandate_id` of
 * the subscription and `_mollie_customer_id` of the renewal order (copied from it), so the next usage invoice
 * charges this mandate. A replaced mandate is revoked.
 */
final class Card {

	public const NONCE  = 'beltoft_services_card';
	public const ACTION = 'beltoft_services_card_start';
	public const QUERY  = 'beltoft_card';

	/**
	 * User meta: Mollie payment ids of card payments started and not yet settled, and those applied.
	 */
	public const META_PENDING = '_beltoft_services_card_pending';
	public const META_DONE    = '_beltoft_services_card_done';

	/**
	 * User meta: how the last settled card payment ended (saved, cancelled, failed), for the notice on return.
	 */
	public const META_LAST = '_beltoft_services_card_last';

	/**
	 * Subscription meta of an account made by the old card-only checkout, still pending: the daily cleanup
	 * cancels those after a day. No new account gets it.
	 */
	public const META_SETUP = '_beltoft_services_card_setup';
	public const CLEANUP    = 'beltoft_services_card_cleanup';

	/**
	 * Option name prefix of the row that says a payment was applied.
	 */
	public const CLAIM = 'beltoft_services_card_claim_';

	/**
	 * The payment method of the account's card.
	 */
	public const GATEWAY = 'mollie_wc_gateway_creditcard';

	/**
	 * Seconds the mandate details are kept.
	 */
	public const CACHE_TTL = 600;

	/**
	 * Card pages one customer can start in the window.
	 */
	public const START_LIMIT  = 5;
	public const START_WINDOW = 600;

	public const NAMESPACE_REST = 'beltoft-services/v1';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( self::CLEANUP, array( __CLASS__, 'cleanup' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 40 );
		add_action( 'woocommerce_account_payment-methods_endpoint', array( __CLASS__, 'swap_page' ), 1 );
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'bricks_methods' ), 10, 2 );
		// A card saved on WooCommerce's Add payment method page (or made the default) becomes the usage account's card.
		add_action( 'woocommerce_payment_token_set_default', array( __CLASS__, 'token_default' ), 10, 2 );
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
		wp_enqueue_script( 'beltoft-services-card', plugins_url( 'assets/card.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Status::HANDLE ), \BeltoftServices\Assets::version( 'assets/card.js' ), true );
		wp_localize_script( 'beltoft-services-card', 'beltoftCard', array( 'working' => __( 'Working…', 'beltoft-services' ) ) );

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
	 * What Mollie says about the account's mandate, cached for ten minutes (a failed look-up is not cached).
	 *
	 * @param \WC_Subscription $account Account.
	 * @return array{status: string, expired: bool, label: string, last4: string, expiry: string}|null Null when the account has no mandate.
	 */
	public static function details( \WC_Subscription $account ): ?array {
		// A card saved through a payment method that is no longer active (Mollie after the switch to Stripe) cannot be
		// charged: show no card, so the customer adds one with the active provider.
		if ( ! self::gateway_active( (string) $account->get_payment_method() ) ) {
			return null;
		}

		$customer = self::customer_id( $account );
		$mandate  = self::mandate_id( $account );

		if ( '' === $customer || '' === $mandate ) {
			// No Mollie mandate: the card is a saved WooCommerce token (Stripe or any gateway that saves tokens).
			return self::token_details( $account );
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

		if ( ! empty( $details['cardExpiryDate'] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', (string) $details['cardExpiryDate'], $m ) ) {
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
		// Payments the customer has come back from (or whose webhook is late) are settled before the page is drawn.
		self::sync_pending( $user_id );
		wp_cache_delete( $user_id, 'user_meta' );

		$account = Account::for_user( $user_id, Account::ESTABLISHED );
		$info    = $account ? self::details( $account ) : null;
		$state   = 'none';

		if ( $info ) {
			$state = 'unknown' === $info['status'] ? 'unknown' : ( self::usable( $info ) ? 'valid' : 'expired' );
		}

		$returned = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::QUERY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a message.
		$notice   = self::notice( $user_id, $returned, $state );

		return array(
			'state'       => $state,
			'text'        => $info && 'unknown' !== $info['status'] ? self::describe( $info ) : '',
			'can_start'   => 'token' === self::flow() ? true : Mollie::configured(),
			'flow'        => self::flow(),
			'add_url'     => self::add_url(),
			'change'      => 'none' !== $state,
			'notice'      => $notice[0],
			'notice_kind' => $notice[1],
			'action'      => self::ACTION,
		);
	}

	/**
	 * The message after a trip to Mollie's card page, or after a refusal of ours.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $returned Value of the query arg.
	 * @param string $state    none, valid, expired or unknown.
	 * @return array{0: string, 1: string} Message and its kind.
	 */
	private static function notice( int $user_id, string $returned, string $state ): array {
		if ( 'limit' === $returned ) {
			return array( __( 'Too many attempts. Try again in a few minutes.', 'beltoft-services' ), 'error' );
		}

		if ( 'failed' === $returned ) {
			return array( __( 'The card page could not be opened. Try again in a moment.', 'beltoft-services' ), 'error' );
		}

		if ( 'return' !== $returned ) {
			return array( '', 'success' );
		}

		$last = (string) get_user_meta( $user_id, self::META_LAST, true );

		// An earlier card page that was left open stays pending: only a customer with no result yet is told to wait.
		if ( '' === $last && self::ids( $user_id, self::META_PENDING ) ) {
			return array( __( 'We are waiting for the payment provider to confirm your card. Reload this page in a moment.', 'beltoft-services' ), 'info' );
		}

		if ( 'saved' === $last && 'unknown' === $state ) {
			return array( __( 'We could not check your card just now. Reload this page in a moment.', 'beltoft-services' ), 'info' );
		}

		if ( 'saved' === $last ) {
			return array( __( 'Card saved.', 'beltoft-services' ), 'success' );
		}

		if ( 'cancelled' === $last ) {
			return array( __( 'Card not saved. You cancelled on the card page. You can try again.', 'beltoft-services' ), 'info' );
		}

		return array( __( 'The card was not saved. The payment provider could not confirm it. You can try again.', 'beltoft-services' ), 'error' );
	}

	// ------------------------------------------------------------------ the button.

	/**
	 * The form: sends the customer on.
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
	 * What a posted form does. It needs a login and the nonce.
	 *
	 * @param int                  $user_id Current user (0 for a visitor).
	 * @param array<string, mixed> $post    Posted fields.
	 * @return string|null Where to send the customer, null when the post is not ours.
	 */
	public static function dispatch( int $user_id, array $post ): ?string {
		if ( empty( $post[ self::ACTION ] ) ) {
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

		return self::start( $user_id );
	}

	/**
	 * Creates the 0.00 first payment.
	 *
	 * @param int $user_id Customer.
	 * @return string Mollie's checkout address, or a page of ours with a message.
	 */
	public static function start( int $user_id ): string {
		if ( ! Mollie::configured() ) {
			return self::url( array( self::QUERY => 'failed' ) );
		}

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:card-start', self::START_LIMIT, self::START_WINDOW ) ) {
			return self::url( array( self::QUERY => 'limit' ) );
		}

		$customer = self::customer_for( $user_id );

		if ( '' === $customer ) {
			return self::url( array( self::QUERY => 'failed' ) );
		}

		// A card page that was opened and left leaves a pending mandate at Mollie: it goes before the next one starts.
		$list    = Mollie::call( 'GET', '/customers/' . rawurlencode( $customer ) . '/mandates' );
		$account = Account::for_user( $user_id, Account::ESTABLISHED );
		$current = $account ? self::mandate_id( $account ) : '';

		foreach ( is_array( $list ) && isset( $list['_embedded']['mandates'] ) ? (array) $list['_embedded']['mandates'] : array() as $m ) {
			if ( 'pending' === ( $m['status'] ?? '' ) && ! empty( $m['id'] ) && (string) $m['id'] !== $current ) {
				Mollie::revoke( $customer, (string) $m['id'] );
			}
		}

		$payment = Mollie::create_payment(
			array(
				'amount'       => array(
					'currency' => get_woocommerce_currency(),
					'value'    => '0.00',
				),
				'description'  => __( 'Card for Pay as you go', 'beltoft-services' ),
				'customerId'   => $customer,
				'sequenceType' => 'first',
				'method'       => 'creditcard',
				'redirectUrl'  => self::url( array( self::QUERY => 'return' ) ),
				'webhookUrl'   => self::webhook_url(),
				'metadata'     => array( 'beltoft_card_user' => $user_id ),
			)
		);

		$href = is_array( $payment ) && isset( $payment['_links']['checkout']['href'] ) ? (string) $payment['_links']['checkout']['href'] : '';

		if ( is_wp_error( $payment ) || empty( $payment['id'] ) || '' === $href ) {
			return self::url( array( self::QUERY => 'failed' ) );
		}

		$pending   = self::ids( $user_id, self::META_PENDING );
		$pending[] = (string) $payment['id'];
		update_user_meta( $user_id, self::META_PENDING, array_values( array_slice( array_unique( $pending ), -5 ) ) );
		delete_user_meta( $user_id, self::META_LAST );

		return $href;
	}

	/**
	 * The customer's Mollie customer: the one on the account, else the one the Mollie plugin keeps on the user
	 * (when Mollie still knows it), else a new one (name and email from the WordPress user).
	 *
	 * @param int $user_id Customer.
	 * @return string Customer id (cst_...), or '' when Mollie could not be asked.
	 */
	private static function customer_for( int $user_id ): string {
		$account = Account::for_user( $user_id, Account::ESTABLISHED );
		$id      = $account ? self::customer_id( $account ) : '';

		if ( '' !== $id ) {
			return $id;
		}

		$id = (string) get_user_meta( $user_id, 'mollie_customer_id', true );

		if ( '' !== $id ) {
			$known = Mollie::call( 'GET', '/customers/' . rawurlencode( $id ) );

			if ( is_wp_error( $known ) ) {
				// Only a customer Mollie says does not exist is replaced.
				if ( 'beltoft_services_mollie_404' !== $known->get_error_code() ) {
					return '';
				}

				$id = '';
			}
		}

		if ( '' !== $id ) {
			return $id;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return '';
		}

		$name = trim( $user->first_name . ' ' . $user->last_name );
		$new  = Mollie::call(
			'POST',
			'/customers',
			array(
				'name'     => '' !== $name ? $name : $user->display_name,
				'email'    => $user->user_email,
				'metadata' => array( 'user_id' => $user_id ),
			)
		);

		if ( is_wp_error( $new ) || empty( $new['id'] ) ) {
			return '';
		}

		// The key the Mollie plugin reads, so a later order reuses this customer.
		update_user_meta( $user_id, 'mollie_customer_id', (string) $new['id'] );

		return (string) $new['id'];
	}

	/**
	 * The address Mollie calls when a card payment changes.
	 */
	public static function webhook_url(): string {
		/**
		 * Filters the webhook address of a card payment (a site behind a tunnel or proxy).
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

		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,40}$/D', $id ) || RateLimit::exceeded( RateLimit::visitor(), 'beltoft-services:card-webhook', 120 ) ) {
			return new \WP_REST_Response( array( 'result' => 'ignored' ), 200 );
		}

		$result = self::apply_payment( $id );

		// Mollie tries again after a failure on our side only.
		return new \WP_REST_Response( array( 'result' => $result ), 'error' === $result ? 503 : 200 );
	}

	/**
	 * Settles the card payments the customer started (when they come back, in case the webhook is late).
	 *
	 * @param int $user_id Customer.
	 */
	public static function sync_pending( int $user_id ): void {
		foreach ( self::ids( $user_id, self::META_PENDING ) as $id ) {
			self::apply_payment( $id );
		}
	}

	/**
	 * A list kept in user meta, without empty entries.
	 *
	 * @param int    $user_id Customer.
	 * @param string $key     Meta key.
	 * @return string[]
	 */
	private static function ids( int $user_id, string $key ): array {
		return array_values( array_filter( array_map( 'strval', (array) get_user_meta( $user_id, $key, true ) ), 'strlen' ) );
	}

	/**
	 * Fetches the payment from Mollie and, when it is a paid card payment this site started, puts its mandate on
	 * the customer's account. Safe to call again and from two requests at once: a payment is applied once.
	 *
	 * @param string $id Mollie payment id.
	 * @return string saved, done (already applied), waiting (not paid yet), cancelled, failed, ignored or error.
	 */
	public static function apply_payment( string $id ): string {
		$payment = Mollie::payment( $id );

		if ( is_wp_error( $payment ) ) {
			return 'beltoft_services_mollie_404' === $payment->get_error_code() ? 'ignored' : 'error';
		}

		$user_id = isset( $payment['metadata']['beltoft_card_user'] ) ? (int) $payment['metadata']['beltoft_card_user'] : 0;

		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return 'ignored';
		}

		global $wpdb;

		$lock = 'bs_card_' . substr( md5( DB_NAME . $wpdb->prefix . $user_id ), 0, 20 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock.
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );

		if ( 1 !== $got ) {
			return 'error';
		}

		try {
			// Another request (the webhook, or the return) may have written the customer's meta while this one waited for the lock.
			wp_cache_delete( $user_id, 'user_meta' );

			$claim = self::CLAIM . md5( $id );

			if ( false !== get_option( $claim ) || in_array( $id, self::ids( $user_id, self::META_DONE ), true ) ) {
				return 'done';
			}

			// Only a payment this site started for this customer, for the customer's Mollie customer, as a first payment.
			$account  = Account::for_user( $user_id, Account::ESTABLISHED );
			$customer = $account ? self::customer_id( $account ) : '';
			$customer = '' !== $customer ? $customer : (string) get_user_meta( $user_id, 'mollie_customer_id', true );

			if ( ! in_array( $id, self::ids( $user_id, self::META_PENDING ), true ) || '' === $customer || ( $payment['customerId'] ?? '' ) !== $customer || 'first' !== ( $payment['sequenceType'] ?? '' ) ) {
				return 'ignored';
			}

			$status = (string) ( $payment['status'] ?? '' );

			if ( in_array( $status, array( 'canceled', 'failed', 'expired' ), true ) ) {
				self::settle( $user_id, $id, 'canceled' === $status ? 'cancelled' : 'failed' );

				return 'canceled' === $status ? 'cancelled' : 'failed';
			}

			if ( ! in_array( $status, array( 'paid', 'authorized' ), true ) || empty( $payment['mandateId'] ) ) {
				return 'waiting';
			}

			// The claim is one row an option name can only have once: a payment is applied by one request, whatever the object cache holds.
			if ( ! add_option( $claim, time(), '', false ) ) {
				return 'done';
			}

			try {
				if ( $account ) {
					self::swap_mandate( $account, $customer, (string) $payment['mandateId'] );
				} else {
					self::create_account( $user_id, $customer, (string) $payment['mandateId'] );
				}
			} catch ( \Throwable $e ) {
				delete_option( $claim );
				throw $e;
			}

			$done   = self::ids( $user_id, self::META_DONE );
			$done[] = $id;
			update_user_meta( $user_id, self::META_DONE, array_values( array_slice( array_unique( $done ), -20 ) ) );
			self::settle( $user_id, $id, 'saved' );

			return 'saved';
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * A payment has ended: it leaves the pending list and the result is kept for the notice.
	 *
	 * @param int    $user_id Customer.
	 * @param string $id      Payment id.
	 * @param string $result  saved, cancelled or failed.
	 */
	private static function settle( int $user_id, string $id, string $result ): void {
		update_user_meta( $user_id, self::META_PENDING, array_values( array_diff( self::ids( $user_id, self::META_PENDING ), array( $id ) ) ) );

		// A left-open earlier page that expires later does not undo a card saved since.
		if ( 'saved' === $result || 'saved' !== (string) get_user_meta( $user_id, self::META_LAST, true ) ) {
			update_user_meta( $user_id, self::META_LAST, $result );
		}
	}

	/**
	 * Puts a new mandate on the account and revokes the old one.
	 *
	 * @param \WC_Subscription $account  Account.
	 * @param string           $customer Mollie customer id.
	 * @param string           $new      New mandate id.
	 */
	private static function swap_mandate( \WC_Subscription $account, string $customer, string $new ): void {
		$old = (string) $account->get_meta( '_mollie_mandate_id', true );

		$account->update_meta_data( '_mollie_mandate_id', $new );
		$account->update_meta_data( '_mollie_customer_id', $customer );
		self::use_card_gateway( $account );
		$account->save();

		$parent = $account->get_parent();

		// An account made by an order keeps the mandate on that order too, where the Mollie plugin looks when the subscription has none.
		if ( $parent ) {
			$parent->update_meta_data( '_mollie_mandate_id', $new );
			$parent->save();
		}

		self::forget( $customer, $old );
		self::forget( $customer, $new );

		$note = '' !== $old && $old !== $new
			/* translators: 1: old mandate id, 2: new mandate id. */
			? sprintf( __( 'Card changed: mandate %2$s replaces %1$s for usage invoices.', 'beltoft-services' ), $old, $new )
			/* translators: %s: mandate id. */
			: sprintf( __( 'Card saved: mandate %s for usage invoices.', 'beltoft-services' ), $new );

		if ( '' !== $old && $old !== $new ) {
			$revoked = Mollie::revoke( $customer, $old );

			if ( is_wp_error( $revoked ) ) {
				$note .= ' ' . __( 'The old one could not be revoked at Mollie.', 'beltoft-services' );
			}
		}

		$account->add_order_note( $note );
	}

	/**
	 * Makes the customer's usage account around a saved card: an active subscription of the hidden 0 per month
	 * product, charged automatically with the card. It is what a first server order's checkout would leave.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $customer Mollie customer id.
	 * @param string $mandate  Mandate id.
	 * @throws \RuntimeException When the subscription cannot be made.
	 */
	private static function create_account( int $user_id, string $customer, string $mandate ): void {
		self::build_account(
			$user_id,
			function ( \WC_Subscription $sub ) use ( $customer, $mandate ): void {
				$sub->update_meta_data( '_mollie_customer_id', $customer );
				$sub->update_meta_data( '_mollie_mandate_id', $mandate );
				self::use_card_gateway( $sub );
			},
			__( 'Card saved on the payment provider\'s card page.', 'beltoft-services' )
		);

		self::forget( $customer, $mandate );
	}

	/**
	 * Builds the active 0 per month usage subscription and lets the caller put the card on it.
	 *
	 * @param int      $user_id Customer.
	 * @param callable $set     Puts the card on the new subscription (not yet saved).
	 * @param string   $note    Status note.
	 * @throws \RuntimeException When the subscription cannot be made.
	 */
	private static function build_account( int $user_id, callable $set, string $note ): void {
		$product = Account::product_id() > 0 ? wc_get_product( Account::product_id() ) : false;

		if ( ! function_exists( 'wcs_create_subscription' ) || ! $product ) {
			throw new \RuntimeException( 'The usage account cannot be made.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		$sub = wcs_create_subscription(
			array(
				'customer_id'      => $user_id,
				'status'           => 'pending',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'start_date'       => gmdate( 'Y-m-d H:i:s' ),
				'currency'         => get_woocommerce_currency(),
			)
		);

		if ( is_wp_error( $sub ) ) {
			throw new \RuntimeException( 'The usage account cannot be made.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
		}

		$user         = get_userdata( $user_id );
		$customer_obj = new \WC_Customer( $user_id );

		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $field ) {
			$setter = 'set_billing_' . $field;
			$getter = 'get_billing_' . $field;
			$sub->$setter( (string) $customer_obj->$getter() );
		}

		$sub->set_billing_email( '' !== $customer_obj->get_billing_email() ? $customer_obj->get_billing_email() : (string) $user->user_email );

		if ( '' === $sub->get_billing_first_name() && '' === $sub->get_billing_last_name() ) {
			$sub->set_billing_first_name( (string) $user->first_name );
			$sub->set_billing_last_name( (string) $user->last_name );
		}

		$sub->set_requires_manual_renewal( false );
		$sub->add_product( $product, 1 );
		$sub->update_meta_data( Account::META, 'yes' );
		$set( $sub );
		$sub->calculate_totals();
		$sub->save();

		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ) ) );
		$sub->update_status( 'active', $note );
	}

	/**
	 * The account is charged by Mollie's card method.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	private static function use_card_gateway( \WC_Subscription $account ): void {
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		if ( isset( $gateways[ self::GATEWAY ] ) ) {
			$account->set_payment_method( $gateways[ self::GATEWAY ] );
		} else {
			$account->set_payment_method( self::GATEWAY );
			$account->set_payment_method_title( __( 'Credit card', 'beltoft-services' ) );
		}

		$account->set_requires_manual_renewal( false );
	}

	// ------------------------------------------------------------------ the generic token flow (Stripe and any gateway that saves tokens).

	/**
	 * Subscription meta holding the saved payment method's token: the key WooCommerce Subscriptions gateways
	 * (the Stripe plugin among them) read when a renewal is charged.
	 */
	public const TOKEN_META = '_payment_method_token';

	/**
	 * Which way a card is added: 'mollie' (a first payment on Mollie's hosted page) or 'token' (WooCommerce's own
	 * Add payment method page, for a gateway that saves tokens and charges subscriptions). Mollie is used when its card
	 * gateway is the enabled one, and also when no gateway offers saved tokens (the long-standing default).
	 */
	/**
	 * Is this payment method registered and enabled on the site now? An empty method counts as active (nothing to check).
	 *
	 * @param string $method Payment method id of the account.
	 */
	public static function gateway_active( string $method ): bool {
		if ( '' === $method ) {
			return true;
		}

		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$active   = isset( $gateways[ $method ] ) && 'yes' === $gateways[ $method ]->enabled;

		/**
		 * Whether a saved card's payment method is active, so the card can be shown and charged.
		 *
		 * @param bool   $active Registered and enabled.
		 * @param string $method Payment method id.
		 */
		return (bool) apply_filters( 'beltoft_services_card_gateway_active', $active, $method );
	}

	public static function flow(): string {
		$flow = array() !== self::token_gateways() ? 'token' : 'mollie';

		/**
		 * Filters the card flow.
		 *
		 * @param string $flow 'mollie' or 'token'.
		 */
		$flow = (string) apply_filters( 'beltoft_services_card_flow', $flow );

		return 'token' === $flow ? 'token' : 'mollie';
	}

	/**
	 * Enabled payment gateways that can save a card on My Account and charge a subscription with it.
	 *
	 * @return array<string, \WC_Payment_Gateway>
	 */
	public static function token_gateways(): array {
		$found    = array();
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		foreach ( $gateways as $id => $gateway ) {
			if ( ! $gateway instanceof \WC_Payment_Gateway || self::GATEWAY === $id || str_starts_with( (string) $id, 'mollie_wc_gateway_' ) ) {
				continue;
			}

			if ( 'yes' === $gateway->enabled && $gateway->supports( 'add_payment_method' ) && $gateway->supports( 'subscriptions' ) ) {
				$found[ (string) $id ] = $gateway;
			}
		}

		return $found;
	}

	/**
	 * WooCommerce's Add payment method page (a saved card is also changed there: the newest default is the account's card).
	 */
	public static function add_url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'add-payment-method' ) : home_url( '/' );
	}

	/**
	 * Does this gateway id keep its saved card as a token the account must carry (so a renewal can charge it)?
	 *
	 * @param string $gateway Payment method id.
	 */
	public static function needs_token( string $gateway ): bool {
		/**
		 * Gateway id prefixes whose subscriptions are charged from a saved token (Stripe for WooCommerce: stripe_).
		 *
		 * @param string[] $prefixes Prefixes.
		 */
		$prefixes = (array) apply_filters( 'beltoft_services_card_token_gateways', array( 'stripe_' ) );

		foreach ( $prefixes as $prefix ) {
			if ( '' !== (string) $prefix && str_starts_with( $gateway, (string) $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What a subscription must carry for a token gateway to charge it on renewal: the payment method token and,
	 * for Stripe, the Stripe customer.
	 *
	 * @param \WC_Subscription $account Account.
	 * @return string[] Meta keys.
	 */
	public static function required_meta( \WC_Subscription $account ): array {
		$keys = array( self::TOKEN_META );

		if ( str_starts_with( (string) $account->get_payment_method(), 'stripe_' ) ) {
			$keys[] = '_wc_stripe_customer';
		}

		/**
		 * Subscription meta a renewal of this account needs.
		 *
		 * @param string[]         $keys    Meta keys.
		 * @param \WC_Subscription $account The account.
		 */
		return (array) apply_filters( 'beltoft_services_card_required_meta', $keys, $account );
	}

	/**
	 * The saved WooCommerce token the account's card is: the one named by the account's token meta, when the customer still has it.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function token_for( \WC_Subscription $account ): ?\WC_Payment_Token {
		$wanted = (string) $account->get_meta( self::TOKEN_META, true );

		if ( '' === $wanted || $account->get_user_id() < 1 || ! class_exists( \WC_Payment_Tokens::class ) ) {
			return null;
		}

		foreach ( \WC_Payment_Tokens::get_customer_tokens( $account->get_user_id() ) as $token ) {
			if ( $token->get_token() === $wanted ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * A token as the few facts the page needs (no API call): brand, last four, expiry.
	 *
	 * @param \WC_Payment_Token $token Token.
	 * @return array{status: string, expired: bool, label: string, last4: string, expiry: string}
	 */
	public static function summarize_token( \WC_Payment_Token $token ): array {
		$label   = '';
		$last4   = '';
		$expiry  = '';
		$expired = false;

		$pick = static function ( array $methods ) use ( $token ): string {
			foreach ( $methods as $method ) {
				if ( is_callable( array( $token, $method ) ) ) {
					return (string) $token->$method();
				}
			}

			return '';
		};

		// WooCommerce's card token, and Stripe's own card token, name the same facts differently.
		$brand = $pick( array( 'get_card_type', 'get_brand' ) );
		$digit = $pick( array( 'get_last4' ) );

		if ( '' !== $digit && '' !== $brand ) {
			$label = ucfirst( sanitize_text_field( $brand ) );
			$last4 = preg_replace( '/\D/', '', $digit );
			$month = (int) $pick( array( 'get_expiry_month', 'get_exp_month' ) );
			$year  = (int) $pick( array( 'get_expiry_year', 'get_exp_year' ) );

			if ( $year > 0 && $year < 100 ) {
				$year += 2000;
			}

			if ( $month >= 1 && $month <= 12 && $year > 0 ) {
				$expiry  = sprintf( '%02d/%02d', $month, $year % 100 );
				$expired = gmdate( 'Y-m' ) > sprintf( '%04d-%02d', $year, $month );
			}
		} else {
			$label = sanitize_text_field( wp_strip_all_tags( (string) $token->get_display_name() ) );
		}

		return array(
			'status'  => 'valid',
			'expired' => $expired,
			'label'   => $label,
			'last4'   => (string) $last4,
			'expiry'  => $expiry,
		);
	}

	/**
	 * Details of the account's saved token, or null when the account has none the customer still holds.
	 *
	 * @param \WC_Subscription $account Account.
	 * @return array{status: string, expired: bool, label: string, last4: string, expiry: string}|null
	 */
	public static function token_details( \WC_Subscription $account ): ?array {
		$token = self::token_for( $account );

		return $token ? self::summarize_token( $token ) : null;
	}

	/**
	 * The card's fingerprint (the same physical card gives the same one), '' when it is not known. A saved token has
	 * it on the gateway's payment method, which the Stripe plugin's client reads; the filter lets a site supply it.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function token_fingerprint( \WC_Subscription $account ): string {
		$token       = self::token_for( $account );
		$fingerprint = '';

		if ( $token && function_exists( 'stripe_wc' ) && str_starts_with( $token->get_gateway_id(), 'stripe_' ) ) {
			try {
				$pm = stripe_wc()->client->mode( $account )->paymentMethods->retrieve( $token->get_token() );

				if ( ! is_wp_error( $pm ) && isset( $pm->card->fingerprint ) ) {
					$fingerprint = (string) $pm->card->fingerprint;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * The fingerprint of the account's saved card.
		 *
		 * @param string                 $fingerprint Fingerprint, or ''.
		 * @param \WC_Payment_Token|null $token       The saved token.
		 * @param \WC_Subscription       $account     The account.
		 */
		return trim( (string) apply_filters( 'beltoft_services_card_fingerprint', $fingerprint, $token, $account ) );
	}

	/**
	 * Makes the card of a token the usage account's: sets the account's payment method and token meta (the keys the
	 * gateway reads on renewal). Without an account the customer gets one, like a first server order would leave.
	 * Acts for subscription-capable token gateways only (never the Mollie card gateway, which saves no tokens).
	 *
	 * @param int|string        $token_id Token id.
	 * @param \WC_Payment_Token $token    The token that became the default.
	 */
	public static function token_default( $token_id, $token = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $token instanceof \WC_Payment_Token || $token->get_user_id() < 1 || str_starts_with( $token->get_gateway_id(), 'mollie_wc_gateway_' ) || ! function_exists( 'wcs_create_subscription' ) ) {
			return;
		}

		// Only the customer's own card pages: not a checkout or a pay page (a card saved there is for that order), not a subscription's change-payment-method, not admin or cron.
		if ( get_current_user_id() !== (int) $token->get_user_id() || defined( 'WOOCOMMERCE_CHECKOUT' ) || did_action( 'woocommerce_subscriptions_pre_update_payment_method' ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return;
		}

		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ $token->get_gateway_id() ] ?? null;

		if ( ! $gateway instanceof \WC_Payment_Gateway || ! $gateway->supports( 'subscriptions' ) ) {
			return;
		}

		$user_id = (int) $token->get_user_id();
		$account = Account::for_user( $user_id );

		try {
			if ( $account ) {
				$changed = (string) $account->get_meta( self::TOKEN_META, true ) !== $token->get_token();
				self::apply_token( $account, $token, $gateway );

				if ( $changed ) {
					$account->add_order_note( __( 'Card changed: the new default card is used for usage invoices.', 'beltoft-services' ) );
				}
			} else {
				self::create_account_with_token( $user_id, $token, $gateway );
			}
		} catch ( \Throwable $e ) {
			// The card is saved; the account follows the next time the card is made the default.
			unset( $e );
		}
	}

	/**
	 * Writes a token on an account.
	 *
	 * @param \WC_Subscription  $account Account.
	 * @param \WC_Payment_Token $token   Token.
	 * @param \WC_Payment_Gateway $gateway The token's gateway.
	 */
	public static function apply_token( \WC_Subscription $account, \WC_Payment_Token $token, \WC_Payment_Gateway $gateway ): void {
		$account->set_payment_method( $gateway );
		$account->set_requires_manual_renewal( false );

		$meta = array( self::TOKEN_META => $token->get_token() );

		if ( str_starts_with( $gateway->id, 'stripe_' ) ) {
			if ( is_callable( array( $token, 'get_customer_id' ) ) ) {
				$meta['_wc_stripe_customer'] = (string) $token->get_customer_id();
			}

			if ( function_exists( 'wc_stripe_mode' ) ) {
				$meta['_wc_stripe_mode'] = (string) wc_stripe_mode();
			}
		}

		/**
		 * The subscription meta a saved token is written as (what the gateway reads when it charges a renewal).
		 *
		 * @param array<string, string> $meta    Meta key => value.
		 * @param \WC_Payment_Token     $token   The token.
		 * @param \WC_Subscription      $account The account.
		 */
		$meta = (array) apply_filters( 'beltoft_services_card_token_meta', $meta, $token, $account );

		foreach ( $meta as $key => $value ) {
			$account->update_meta_data( (string) $key, $value );
		}

		$title = trim( wp_strip_all_tags( (string) $token->get_display_name() ) );

		if ( '' !== $title ) {
			$account->set_payment_method_title( $title );
		}

		$account->save();
	}

	/**
	 * Makes the usage account for a customer whose first card was saved on My Account.
	 *
	 * @param int                 $user_id Customer.
	 * @param \WC_Payment_Token   $token   Token.
	 * @param \WC_Payment_Gateway $gateway The token's gateway.
	 */
	private static function create_account_with_token( int $user_id, \WC_Payment_Token $token, \WC_Payment_Gateway $gateway ): void {
		self::build_account(
			$user_id,
			function ( \WC_Subscription $sub ) use ( $token, $gateway ): void {
				self::apply_token( $sub, $token, $gateway );
			},
			__( 'Card saved on My Account.', 'beltoft-services' )
		);
	}

	// ------------------------------------------------------------------ accounts left by the old card-only checkout.

	/**
	 * Daily job.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::CLEANUP, array(), 'beltoft-services' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP, array(), 'beltoft-services' );
		}
	}

	/**
	 * Cancels card-setup accounts still pending after 24 hours (never an active one), and their unpaid first order.
	 *
	 * @return int Accounts cancelled.
	 */
	public static function cleanup(): int {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return 0;
		}

		$n = 0;

		foreach ( wcs_get_subscriptions( array( 'subscription_status' => 'pending', 'subscriptions_per_page' => -1 ) ) as $sub ) {
			$created = $sub->get_date_created();

			if ( 'yes' !== $sub->get_meta( self::META_SETUP ) || ! Account::is_account( $sub ) || ! $created || $created->getTimestamp() > time() - DAY_IN_SECONDS ) {
				continue;
			}

			$parent = $sub->get_parent();

			if ( $parent && $parent->has_status( array( 'pending', 'failed' ) ) ) {
				$parent->update_status( 'cancelled', __( 'Card setup not completed.', 'beltoft-services' ) );
			}

			if ( $sub->has_status( 'pending' ) ) {
				$sub->update_status( 'cancelled', __( 'Card setup not completed.', 'beltoft-services' ) );
				++$n;
			}
		}

		return $n;
	}

}

<?php
/** Domain purchase cart lines. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\Crypto;
use BeltoftServices\Currency;
use BeltoftServices\Product;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class Cart {
	/**
	 * Wipes the encrypted transfer codes from the lines of an order that failed, was cancelled or refunded, or is removed:
	 * such an order never provisions, so nothing else would ever delete them.
	 *
	 * @param mixed $order_id Order id (or a post id: only orders are touched).
	 */
	public static function wipe_transfer_codes( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			if ( '' !== (string) $item->get_meta( '_beltoft_services_domain_auth', true ) ) {
				$item->delete_meta_data( '_beltoft_services_domain_auth' );
				$item->save();
			}
		}
	}

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_prices' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_direct_add' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'require_domain_selection' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item' ), 10, 4 );
		foreach ( array( 'woocommerce_order_status_failed', 'woocommerce_order_status_cancelled', 'woocommerce_order_status_refunded', 'woocommerce_trash_order', 'woocommerce_before_delete_order', 'wp_trash_post', 'before_delete_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'wipe_transfer_codes' ) );
		}
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( __CLASS__, 'meta_display' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_unverified_dns_items' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_unpriced_domain_items' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_duplicate_domain_items' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_duplicate_domain_checkout' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_unpriced_domain_checkout' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_dns_checkout' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_transfer_lock_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'bind_dns_claims' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'bind_dns_claims' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_dns_claims' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_dns_claims' ) );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'release_dns_claims' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'release_trashed_post' ) );
		add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( __CLASS__, 'recurring_key' ), 10, 2 );
		// After Subscriptions and the core trial rule: a free transfer is a $0 cart that still needs a payment method, and is not a trial.
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'needs_payment' ), 30, 2 );
		add_filter( 'beltoft_services_trial_cart_item', array( __CLASS__, 'is_trial_item' ), 10, 2 );
	}

	/** The meta keys Subscriptions reads a trial from, set on the cart's own copy of the product. */
	private const TRIAL_LENGTH = '_subscription_trial_length';
	private const TRIAL_PERIOD = '_subscription_trial_period';
	/** Set on the cart's copy of the product only, so a later pass can take the trial back. */
	private const TRIAL_MARK = '_beltoft_services_free_trial';

	/** Is this cart line a free transfer? Only a new transfer line can be. */
	public static function is_free_transfer_item( array $item ): bool {
		$line = $item['beltoft_services_domain'] ?? null;
		return is_array( $line ) && ! empty( $line['free_transfer'] ) && 'transfer' === ( $line['mode'] ?? '' ) && ! self::is_continuation( $item );
	}

	/** Filter beltoft_services_trial_cart_item: a free transfer is a way of paying, not a trial of the service, so the one trial per customer rule skips it. */
	public static function is_trial_item( $is_trial, $item = array() ) {
		return is_array( $item ) && self::is_free_transfer_item( $item ) ? false : $is_trial;
	}

	/**
	 * A cart with a free transfer needs a payment method although today's total is 0: the first yearly payment is taken from it when the
	 * domain is up for renewal. Not when the store charges renewals by hand (Subscriptions' own rule).
	 *
	 * @param mixed $needs Whether the cart needs payment.
	 * @param mixed $cart  Cart.
	 */
	public static function needs_payment( $needs, $cart = null ) {
		if ( $needs || ! $cart instanceof \WC_Cart ) { return $needs; }
		if ( function_exists( 'wcs_is_manual_renewal_required' ) && wcs_is_manual_renewal_required() ) { return $needs; }
		foreach ( $cart->get_cart() as $item ) {
			if ( self::is_free_transfer_item( $item ) ) { return true; }
		}
		return $needs;
	}

	public static function routes(): void {
		register_rest_route( Routes::NAMESPACE, '/domains/cart', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'rest_add' ),
			'permission_callback' => '__return_true',
			'args' => array( 'domain' => array( 'type' => 'string', 'required' => true ), 'mode' => array( 'type' => 'string', 'required' => true ), 'auth_code' => array( 'type' => 'string' ), 'dns' => array( 'type' => 'string' ), 'mail_for' => array( 'type' => 'integer' ) ),
		) );
	}

	public static function rest_add( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WC-Nonce' ), 'wc_store_api' ) ) {
			return new \WP_Error( 'bad_nonce', __( 'Refresh the page and try again.', 'beltoft-services' ), array( 'status' => 403 ) );
		}
		if ( \BeltoftServices\RateLimit::exceeded( \BeltoftServices\RateLimit::visitor(), 'domain-cart', (int) apply_filters( 'beltoft_services_domain_cart_limit', 20 ) ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests. Please wait a minute.', 'beltoft-services' ), array( 'status' => 429 ) );
		}
		$result = self::add_line( (string) $request['domain'], (string) $request['mode'], (string) ( $request['auth_code'] ?? '' ), (string) ( $request['dns'] ?? '' ), (int) ( $request['mail_for'] ?? 0 ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return new \WP_REST_Response( $result, ! empty( $result['added'] ) ? 201 : 200 );
	}

	/**
	 * Puts a domain in the cart: the checks and the cart write of the /domains/cart route, shared with the one-click add link.
	 * The caller has done its own request checks (nonce, rate limit).
	 *
	 * @return array{added: bool, already_in_cart?: bool, checkout_url: string}|\WP_Error
	 */
	public static function add_line( string $domain, string $mode, string $auth_code = '', string $dns = '', int $mail_for = 0 ): array|\WP_Error {
		// A locked domain cannot be transferred: asked again now (a few seconds at most); the cached answer stands in when that fails.
		if ( 'transfer' === $mode ) {
			$known = TransferDns::fresh_registrar( $domain );
			if ( ! empty( $known['locked'] ) ) { return new \WP_Error( 'domain_locked', TransferDns::locked_message( $domain ), array( 'status' => 422 ) ); }
		}
		$data = self::line_data( $domain, $mode, $auth_code, $dns, $mail_for );
		if ( is_wp_error( $data ) ) { return $data; }
		// WooCommerce leaves its cart unloaded on custom REST routes.
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); }
		$cart = WC()->cart;
		if ( ! $cart ) { return new \WP_Error( 'no_cart', __( 'The cart is unavailable.', 'beltoft-services' ), array( 'status' => 503 ) ); }
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ( $item['beltoft_services_domain']['domain'] ?? '' ) === $data['beltoft_services_domain']['domain'] ) {
				// The same domain the same way, for example after going back from checkout: carry on to checkout.
				if ( ( $item['beltoft_services_domain']['mode'] ?? '' ) === $data['beltoft_services_domain']['mode'] ) {
					// A transfer ordered again with another DNS choice takes the new choice.
					if ( 'transfer' === $data['beltoft_services_domain']['mode'] && ( $item['beltoft_services_domain']['dns'] ?? '' ) !== ( $data['beltoft_services_domain']['dns'] ?? '' ) ) {
						$cart->remove_cart_item( $key );
						break;
					}
					return array( 'added' => false, 'already_in_cart' => true, 'checkout_url' => wc_get_checkout_url() );
				}
				return new \WP_Error( 'duplicate_domain', __( 'This domain is already in your cart.', 'beltoft-services' ), array( 'status' => 409 ) );
			}
		}
		$mode = $data['beltoft_services_domain']['mode'];
		if ( in_array( $mode, array( 'register', 'transfer' ), true ) ) {
			$holder = self::domain_held( $data['beltoft_services_domain']['domain'] );
			if ( null !== $holder ) {
				// Only the holder is told it is theirs; anyone else gets an answer that does not say who has the name.
				$message = get_current_user_id() > 0 && $holder === get_current_user_id() ? __( 'This domain is already registered here.', 'beltoft-services' ) : __( 'This domain cannot be added right now.', 'beltoft-services' );
				return new \WP_Error( 'domain_held', $message, array( 'status' => 409 ) );
			}
		}
		$product = self::product_for( 'dns' === $mode ? 'dns' : 'domain' );
		if ( ! $product ) { return new \WP_Error( 'product_missing', __( 'This service is not available yet.', 'beltoft-services' ), array( 'status' => 503 ) ); }
		if ( 'register' === $mode || 'transfer' === $mode ) {
			$search = Search::find( $data['beltoft_services_domain']['domain'], true );
			if ( is_wp_error( $search ) ) {
				// Only the plugin's own answers pass; the registrar's errors become one generic text.
				return 'registrar_unavailable' === $search->get_error_code() ? new \WP_Error( 'registrar_unavailable', __( 'This domain cannot be added right now. Try again in a few minutes.', 'beltoft-services' ), array( 'status' => 503 ) ) : $search;
			}
			$status = $search['results'][0]['status'] ?? 'not_sold';
			if ( ( 'register' === $mode && 'free' !== $status ) || ( 'transfer' === $mode && 'taken' !== $status ) ) {
				$message = 'transfer' === $mode
					? __( 'This domain cannot be transferred here. Check that it is registered and that its extension is offered here.', 'beltoft-services' )
					: __( 'This domain cannot be ordered in that way.', 'beltoft-services' );
				return new \WP_Error( 'domain_unavailable', $message, array( 'status' => 409 ) );
			}
		}
		$key = $cart->add_to_cart( $product->get_id(), 1, 0, array(), $data );
		if ( ! $key ) { return new \WP_Error( 'cart_failed', __( 'The domain could not be added to your cart.', 'beltoft-services' ), array( 'status' => 400 ) ); }
		WC()->session->set_customer_session_cookie( true );
		return array( 'added' => true, 'checkout_url' => wc_get_checkout_url() );
	}

	public static function line_data( string $domain, string $mode, string $auth_code = '', string $dns = '', int $mail_for = 0 ): array|\WP_Error {
		$domain = Search::normalise( $domain );
		if ( is_wp_error( $domain ) ) { return $domain; }
		if ( ! in_array( $mode, array( 'register', 'transfer', 'dns' ), true ) ) { return new \WP_Error( 'bad_mode', __( 'Choose registration, transfer or DNS hosting.', 'beltoft-services' ) ); }
		if ( 'dns' === $mode ) { return new \WP_Error( 'dns_verification_required', __( 'DNS hosting for a domain registered elsewhere requires ownership verification. This option is temporarily unavailable.', 'beltoft-services' ), array( 'status' => 503 ) ); }
		if ( 'dns' !== $mode && str_ends_with( $domain, '.dk' ) && ! DkAgreement::ready() ) { return new \WP_Error( 'dk_pending', __( '.dk orders are not available yet.', 'beltoft-services' ), array( 'status' => 503 ) ); }
		$parts = explode( '.', $domain );
		if ( 'dns' === $mode && count( $parts ) > 2 ) {
			$table = get_option( Prices::OPTION, array() );
			$suffix = implode( '.', array_slice( $parts, 1 ) );
			if ( ! is_array( $table ) || ! isset( $table[ $suffix ] ) ) { return new \WP_Error( 'subdomain', __( 'Enter the main domain, without a subdomain.', 'beltoft-services' ) ); }
		}
		if ( 'transfer' === $mode && '' === trim( $auth_code ) ) { return new \WP_Error( 'auth_required', __( 'Enter the transfer code.', 'beltoft-services' ), array( 'status' => 400 ) ); }
		if ( 'transfer' === $mode && ! self::auth_code_valid( trim( $auth_code ) ) ) { return new \WP_Error( 'auth_invalid', __( 'The transfer code must be 6 to 64 characters, letters, digits and common symbols only.', 'beltoft-services' ), array( 'status' => 400 ) ); }
		$dns = trim( $dns );
		if ( 'transfer' === $mode && '' !== $dns && ! in_array( $dns, TransferDns::CHOICES, true ) ) { return new \WP_Error( 'bad_dns_choice', __( 'Choose where your DNS lives.', 'beltoft-services' ), array( 'status' => 400 ) ); }
		// The line carries the currency its price is worked out in, so a cart that is re-priced to another currency cannot keep it.
		$data = array( 'beltoft_services_domain' => array( 'domain' => $domain, 'mode' => $mode ), Currency::CART_KEY => Currency::current() );
		if ( 'transfer' === $mode && '' !== $dns ) { $data['beltoft_services_domain']['dns'] = $dns; }
		// Free now, or not: the line keeps what was promised when it was added, and apply_prices() drops it if the rule stops holding.
		if ( 'transfer' === $mode && Prices::free_transfer( $domain ) ) { $data['beltoft_services_domain']['free_transfer'] = true; }
		if ( 'transfer' === $mode ) {
			try { $data['beltoft_services_domain_auth'] = Crypto::encrypt( trim( $auth_code ) ); }
			catch ( \RuntimeException $e ) { return new \WP_Error( 'encryption_unavailable', __( 'Transfer cannot start until secure storage is configured.', 'beltoft-services' ) ); }
		}
		// A domain bought for an email plan: the Mail layer takes the plan only when it is the customer's own, live and has room
		// (the order checks again when the domain is ready).
		if ( $mail_for > 0 && in_array( $mode, array( 'register', 'transfer' ), true ) ) {
			/**
			 * The email plan a domain line is bought for, or 0.
			 *
			 * @param int $attach  0.
			 * @param int $mail_for Resource id the page asked for.
			 * @param int $user_id Current customer.
			 */
			$attach = (int) apply_filters( 'beltoft_services_domain_cart_attach', 0, $mail_for, get_current_user_id() );
			if ( $attach > 0 ) { $data['beltoft_services_mail_attach'] = $attach; }
		}
		return $data;
	}

	/** Registrar transfer codes are short printable ASCII text; anything else is a typing or paste mistake, and the length is bounded. */
	public static function auth_code_valid( string $code ): bool {
		return 1 === preg_match( '/^[\x20-\x7E]{6,64}$/D', $code );
	}

	/** Is this a renewal, resubscribe or switch line, which carries no new ownership proof? */
	private static function is_continuation( array $item ): bool {
		return CartFlow::has_value( $item, CartFlow::BOUGHT_BEFORE );
	}

	/**
	 * May this DNS hosting line be bought by this customer now? Only with a verified claim of their own for the
	 * same domain, and the domain reserved for them (or, once the claim went into an unpaid order, held by it).
	 *
	 * @param array<string, mixed> $data    Cart item, or an array with the line's beltoft_services_domain.
	 * @param int                  $user_id Customer.
	 * @param int|null             $order_id The order being created or paid; null takes the checkout's awaiting-payment order.
	 * @return true|\WP_Error
	 */
	private static function dns_claim_ok( array $data, int $user_id, ?int $order_id = null ) {
		$fail = new \WP_Error( 'dns_claim_expired', __( 'This DNS hosting check has expired. Start again from My Account, Domains.', 'beltoft-services' ) );
		$line = $data['beltoft_services_domain'] ?? null;
		if ( ! DnsClaimsFeature::enabled() ) { return $fail; }
		if ( $user_id <= 0 || ! is_array( $line ) || 'dns' !== ( $line['mode'] ?? '' ) || ! isset( $line['claim_id'] ) || ! is_int( $line['claim_id'] ) || $line['claim_id'] <= 0 ) { return $fail; }
		$claim = DnsClaims::get( $line['claim_id'] );
		if ( ! $claim || (int) $claim['user_id'] !== $user_id || '' === (string) ( $line['domain'] ?? '' ) || (string) $claim['domain'] !== (string) $line['domain'] ) { return $fail; }
		$row = DnsAssignments::get( (string) $claim['domain'] );
		if ( ! $row || (int) $row['user_id'] !== $user_id || (int) $row['claim_id'] !== (int) $claim['id'] ) { return $fail; }
		// A new purchase only uses a reservation that is open: never an active or released domain.
		if ( 'reserved' !== $row['status'] ) { return $fail; }
		if ( ! empty( $row['reserved_until'] ) && strtotime( (string) $row['reserved_until'] . ' UTC' ) <= time() ) { return $fail; }
		if ( null === $order_id ) { $order_id = ( function_exists( 'WC' ) && WC()->session ) ? (int) WC()->session->get( 'order_awaiting_payment' ) : 0; }
		$bound = (int) $row['order_id'];
		if ( $bound > 0 && $bound !== $order_id ) { return $fail; }
		if ( DnsClaims::usable( $claim, $user_id ) ) { return true; }
		// The claim went into the order that holds this reservation: only that order may be paid or retried.
		if ( 'consumed' === $claim['status'] && $bound > 0 && $bound === $order_id ) {
			$order = wc_get_order( $bound );
			if ( $order && (int) $order->get_customer_id() === $user_id && $order->has_status( array( 'pending', 'on-hold' ) ) ) { return true; }
		}
		return $fail;
	}

	/** Stop DNS lines without a valid, current ownership claim of the logged-in customer from reaching checkout. */
	public static function check_unverified_dns_items(): void {
		if ( ! WC()->cart ) { return; }
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( 'dns' !== ( $item['beltoft_services_domain']['mode'] ?? '' ) || self::is_continuation( $item ) ) { continue; }
			$ok = self::dns_claim_ok( $item, get_current_user_id() );
			if ( is_wp_error( $ok ) ) {
				wc_add_notice( $ok->get_error_message(), 'error' );
				return;
			}
		}
	}

	/** The same rule when the classic checkout form is posted. */
	public static function validate_dns_checkout( $data, $errors ): void {
		if ( ! WC()->cart || ! $errors instanceof \WP_Error ) { return; }
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( 'dns' !== ( $item['beltoft_services_domain']['mode'] ?? '' ) || self::is_continuation( $item ) ) { continue; }
			$ok = self::dns_claim_ok( $item, get_current_user_id() );
			if ( is_wp_error( $ok ) ) {
				$errors->add( 'dns_claim_expired', $ok->get_error_message() );
				return;
			}
		}
	}

	/**
	 * The customer of a domain service here that holds this name, or null. Buying it again would only fail at provisioning, after
	 * payment. A failed service, and one paused after its license ended (waiting out retention), do not hold it: a retry or a new
	 * purchase is allowed.
	 */
	public static function domain_held( string $domain ): ?int {
		foreach ( Resource::live_ids( 'domain' ) as $id ) {
			$other = Resource::find( $id );
			if ( ! $other || strtolower( (string) $other->get( 'domain' ) ) !== strtolower( $domain ) ) { continue; }
			$state   = $other->get( 'state' );
			$license = $other->license();
			if ( in_array( $state, array( 'deleted', 'failed' ), true ) ) { continue; }
			if ( 'paused' === $state && ( ! $license || \BeltoftLicenses\Licenses::STATUS_ACTIVE !== \BeltoftLicenses\Licenses::effective_status( $license ) ) ) { continue; }
			return (int) ( $license['user_id'] ?? 0 );
		}
		return null;
	}

	/** The message for a cart that holds a domain twice or one that is already held here, or null. */
	private static function duplicate_message(): ?string {
		if ( ! WC()->cart ) { return null; }
		$seen = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$line = $item['beltoft_services_domain'] ?? null;
			if ( ! is_array( $line ) || ! in_array( $line['mode'] ?? '', array( 'register', 'transfer' ), true ) || self::is_continuation( $item ) ) { continue; }
			$name = strtolower( (string) ( $line['domain'] ?? '' ) );
			if ( isset( $seen[ $name ] ) ) {
				/* translators: %s: domain name */
				return sprintf( __( '%s is in your cart twice. Remove one of them.', 'beltoft-services' ), HostName::display( $name ) );
			}
			$seen[ $name ] = true;
			$holder = self::domain_held( $name );
			if ( null !== $holder ) {
				if ( get_current_user_id() > 0 && $holder === get_current_user_id() ) {
					/* translators: %s: domain name */
					return sprintf( __( '%s is already registered here. Remove it from your cart.', 'beltoft-services' ), HostName::display( $name ) );
				}
				/* translators: %s: domain name */
				return sprintf( __( '%s cannot be added right now. Remove it from your cart.', 'beltoft-services' ), HostName::display( $name ) );
			}
		}
		return null;
	}

	public static function check_duplicate_domain_items(): void {
		$message = self::duplicate_message();
		if ( null !== $message ) { wc_add_notice( $message, 'error' ); }
	}

	/** A transfer line whose domain is locked at its registrar now stops the checkout: the lock is asked again, with a short timeout. */
	public static function validate_transfer_lock_checkout( $data, $errors ): void {
		if ( ! WC()->cart || ! $errors instanceof \WP_Error ) { return; }
		$seen = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$line = $item['beltoft_services_domain'] ?? array();
			$name = strtolower( (string) ( $line['domain'] ?? '' ) );
			if ( 'transfer' !== ( $line['mode'] ?? '' ) || '' === $name || isset( $seen[ $name ] ) || self::is_continuation( $item ) ) { continue; }
			$seen[ $name ] = true;
			$known = TransferDns::fresh_registrar( $name );
			if ( ! empty( $known['locked'] ) ) { $errors->add( 'domain_locked', TransferDns::locked_message( $name ) ); }
		}
	}

	/** The same rule when the classic checkout form is posted. */
	public static function validate_duplicate_domain_checkout( $data, $errors ): void {
		$message = $errors instanceof \WP_Error ? self::duplicate_message() : null;
		if ( null !== $message ) { $errors->add( 'domain_duplicate', $message ); }
	}

	/** Is a new registration or transfer line for an extension that is no longer in the price table? Such a line would keep the product's base price. */
	private static function is_unpriced_domain_line( array $item ): bool {
		$mode = $item['beltoft_services_domain']['mode'] ?? '';
		return in_array( $mode, array( 'register', 'transfer' ), true ) && ! self::is_continuation( $item ) && null === self::price_for( $item );
	}

	/** Stop registration and transfer lines without a sellable price from reaching checkout. */
	public static function check_unpriced_domain_items(): void {
		if ( ! WC()->cart ) { return; }
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_unpriced_domain_line( $item ) ) {
				wc_add_notice( __( 'This domain extension is not offered any more. Remove it from your cart and search again.', 'beltoft-services' ), 'error' );
				return;
			}
		}
	}

	/** The same rule when the classic checkout form is posted. */
	public static function validate_unpriced_domain_checkout( $data, $errors ): void {
		if ( ! WC()->cart || ! $errors instanceof \WP_Error ) { return; }
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_unpriced_domain_line( $item ) ) {
				$errors->add( 'domain_not_offered', __( 'This domain extension is not offered any more. Remove it from your cart and search again.', 'beltoft-services' ) );
				return;
			}
		}
	}

	/** The proof is used up when the order exists: the reservation is tied to it and the claim is consumed. */
	public static function bind_dns_claims( $order ): void {
		if ( ! $order instanceof \WC_Order ) { return; }
		foreach ( $order->get_items() as $item ) {
			$claim_id = (int) $item->get_meta( '_beltoft_services_dns_claim', true );
			$line = $item->get_meta( '_beltoft_services_domain', true );
			if ( $claim_id <= 0 || ! is_array( $line ) ) { continue; }
			$user_id = (int) $order->get_customer_id();
			$ok = self::dns_claim_ok( array( 'beltoft_services_domain' => $line ), $user_id, (int) $order->get_id() );
			if ( is_wp_error( $ok ) ) {
				/* translators: %s: domain name */
				$order->add_order_note( sprintf( __( 'DNS hosting for %s: the ownership check is no longer valid, so the domain was not reserved for this order.', 'beltoft-services' ), (string) ( $line['domain'] ?? '' ) ) );
				continue;
			}
			if ( DnsAssignments::bind_order( (string) $line['domain'], $user_id, (int) $order->get_id(), (int) $item->get_id() ) ) {
				DnsClaims::set_status( $claim_id, 'consumed' );
				continue;
			}
			/* translators: %s: domain name */
			$order->add_order_note( sprintf( __( 'DNS hosting for %s: the domain could not be tied to this order.', 'beltoft-services' ), (string) $line['domain'] ) );
		}
	}

	/** An order that will not be paid frees its reservation. Active rows are never touched. */
	public static function release_dns_claims( $order_id ): void {
		$order_id = (int) $order_id;
		$claims   = array();
		foreach ( DnsAssignments::for_status( 'reserved' ) as $row ) {
			if ( (int) $row['order_id'] === $order_id && $order_id > 0 ) { $claims[] = (int) $row['claim_id']; }
		}
		if ( ! DnsAssignments::release_for_order( $order_id ) ) { return; }
		// The proof was used up by the order: it now reads as expired, so the customer can start again.
		foreach ( $claims as $claim_id ) {
			$claim = DnsClaims::get( $claim_id );
			if ( $claim && 'consumed' === $claim['status'] ) { DnsClaims::set_status( $claim_id, 'expired' ); }
		}
	}

	public static function release_trashed_post( $post_id ): void {
		if ( 'shop_order' === get_post_type( (int) $post_id ) ) { self::release_dns_claims( $post_id ); }
	}

	/** Prevent a direct product add from creating an unprovisionable license. */
	public static function require_domain_selection( array $data, int $product_id ): array {
		// Store API invokes this filter before its validation hook. Let that hook return a normal 400 response.
		if ( method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() ) { return $data; }
		self::check_domain_selection( $data, $product_id );
		return $data;
	}

	private static function check_domain_selection( array $data, int $product_id ): void {
		$driver = Product::driver_for( $product_id );
		if ( ! $driver || ! in_array( $driver->id(), array( 'domain', 'dns' ), true ) ) { return; }
		if ( CartFlow::has_value( $data, CartFlow::BOUGHT_BEFORE ) ) { return; }
		if ( 'dns' === $driver->id() ) {
			$ok = self::dns_claim_ok( $data, get_current_user_id() );
			if ( is_wp_error( $ok ) ) { throw new \Exception( esc_html( $ok->get_error_message() ) ); }
			return;
		}
		$chosen = $data['beltoft_services_domain'] ?? null;
		if ( ! is_array( $chosen ) || empty( $chosen['domain'] ) || is_wp_error( Search::normalise( (string) $chosen['domain'] ) ) || ( ( 'dns' === $driver->id() ) !== ( 'dns' === ( $chosen['mode'] ?? '' ) ) ) ) {
			throw new \Exception( esc_html__( 'Choose a domain on the domain search page first.', 'beltoft-services' ) );
		}
	}

	/** Return a normal cart error for WooCommerce's direct product and Store API routes. */
	public static function validate_direct_add( bool $valid, int $product_id, $quantity = null, $variation_id = 0, $variation = array(), $data = array() ): bool {
		if ( ! $valid ) { return false; }
		$data = is_array( $data ) ? $data : array();
		$driver = Product::driver_for( $product_id );
		if ( $driver && in_array( $driver->id(), array( 'domain', 'dns' ), true ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() && ! CartFlow::has_value( $data, CartFlow::BOUGHT_BEFORE ) ) {
			wc_add_notice( __( 'Choose a domain on the domain search page first.', 'beltoft-services' ), 'error' );
			return false;
		}
		try { self::check_domain_selection( $data, $product_id ); }
		catch ( \Exception $e ) { wc_add_notice( $e->getMessage(), 'error' ); return false; }
		return true;
	}

	public static function price_for( array $item ): int|float|null {
		if ( CartFlow::has_value( $item, CartFlow::BOUGHT_BEFORE ) ) { return null; }
		$domain = $item['beltoft_services_domain'] ?? null;
		if ( ! is_array( $domain ) || 'dns' === ( $domain['mode'] ?? '' ) ) { return null; }
		$name = (string) ( $domain['domain'] ?? '' );
		$table = get_option( Prices::OPTION, array() );
		$tlds = is_array( $table ) ? array_keys( $table ) : array();
		usort( $tlds, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		foreach ( $tlds as $tld ) {
			if ( str_ends_with( $name, '.' . $tld ) ) { return Prices::price( $tld ); }
		}
		return null;
	}

	private static bool $noticed = false;

	public static function apply_prices( \WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			// One line is one domain: a quantity raised by a cart update or the Store API goes back to 1.
			if ( isset( $item['beltoft_services_domain'] ) && 1 !== (int) ( $item['quantity'] ?? 1 ) ) { $cart->cart_contents[ $key ]['quantity'] = 1; }
			// A priced domain line was chosen in a currency (a line from before lines carried one is in the shop's): in another currency the
			// line goes, with one notice, and the customer searches again.
			$domain = $item['beltoft_services_domain'] ?? null;
			if ( is_array( $domain ) && 'dns' !== ( $domain['mode'] ?? '' ) && ! self::is_continuation( $item ) ) {
				$tag = '' !== (string) ( $item[ Currency::CART_KEY ] ?? '' ) ? (string) $item[ Currency::CART_KEY ] : Currency::base();
				if ( Currency::current() !== $tag ) {
					$cart->remove_cart_item( $key );
					if ( ! self::$noticed && function_exists( 'wc_add_notice' ) ) {
						self::$noticed = true;
						wc_add_notice( __( 'A domain was removed from your cart because the currency changed. Search for it again in the new currency.', 'beltoft-services' ), 'notice' );
					}
					continue;
				}
			}
			$free = self::settle_free_transfer( $cart, (string) $key, $item );
			$price = self::price_for( $item );
			if ( null !== $price && isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				$cart->cart_contents[ $key ][ Currency::CART_KEY ] = Currency::current();
				self::set_free_trial( $item['data'], $free );
				// The regular price too: left at the product's own price, a cheaper extension showed as a sale ("Save").
				$item['data']->set_regular_price( $price );
				$item['data']->set_sale_price( '' );
				$item['data']->set_price( $price );
			}
		}
	}

	/**
	 * Does this line still qualify as a free transfer? A stamp the rule no longer backs (the setting was turned off, the registrar's price
	 * changed, the extension left the table) is removed with one notice and the line is a paid transfer; a stamp on any other kind of
	 * line is removed without a word. A line without the stamp never gains it here.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @param string   $key  Cart item key.
	 * @param array    $item The item as it was before this call.
	 */
	private static function settle_free_transfer( \WC_Cart $cart, string $key, array $item ): bool {
		$line = $item['beltoft_services_domain'] ?? null;
		if ( ! is_array( $line ) || ! isset( $line['free_transfer'] ) ) { return false; }
		if ( self::is_free_transfer_item( $item ) && Prices::free_transfer( (string) ( $line['domain'] ?? '' ) ) ) { return true; }
		unset( $cart->cart_contents[ $key ]['beltoft_services_domain']['free_transfer'] );
		if ( self::is_free_transfer_item( $item ) && ! self::$noticed_free && function_exists( 'wc_add_notice' ) ) {
			self::$noticed_free = true;
			wc_add_notice( __( 'A transfer in your cart is no longer free, so it is charged as one year.', 'beltoft-services' ), 'notice' );
		}
		return false;
	}

	private static bool $noticed_free = false;

	/**
	 * Today's amount of a free transfer is 0 and the yearly price comes after: the cart's own copy of the product gets a one year free
	 * trial, the way Subscriptions prices that. The year is a placeholder: the first payment is dated from the domain's expiry once the
	 * transfer is done (RenewalSweep::set_dates). The stored product is not touched.
	 */
	private static function set_free_trial( \WC_Product $product, bool $free ): void {
		if ( $free ) {
			$product->update_meta_data( self::TRIAL_LENGTH, '1' );
			$product->update_meta_data( self::TRIAL_PERIOD, 'year' );
			$product->update_meta_data( self::TRIAL_MARK, '1' );
		} elseif ( '' !== (string) $product->get_meta( self::TRIAL_MARK ) ) {
			// This copy got the trial earlier in the request and the line has lost its right to it.
			$product->update_meta_data( self::TRIAL_LENGTH, '0' );
			$product->delete_meta_data( self::TRIAL_MARK );
		}
	}

	public static function order_item( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( isset( $values['beltoft_services_domain'] ) ) {
			$item->add_meta_data( '_beltoft_services_domain', $values['beltoft_services_domain'], true );
			if ( ! empty( $values['beltoft_services_domain']['claim_id'] ) ) { $item->add_meta_data( '_beltoft_services_dns_claim', (int) $values['beltoft_services_domain']['claim_id'], true ); }
			$item->add_meta_data( __( 'Domain', 'beltoft-services' ), (string) ( $values['beltoft_services_domain']['domain'] ?? '' ), true );
		}
		if ( isset( $values['beltoft_services_mail_attach'] ) ) { $item->add_meta_data( '_beltoft_services_mail_attach', (int) $values['beltoft_services_mail_attach'], true ); }
		if ( isset( $values['beltoft_services_domain_auth'] ) ) { $item->add_meta_data( '_beltoft_services_domain_auth', $values['beltoft_services_domain_auth'], true ); }
	}

	public static function quantity( string $html, string $cart_item_key, array $cart_item ): string {
		return isset( $cart_item['beltoft_services_domain'] ) ? '1' : $html;
	}

	/** Show the exact domain being ordered in cart and checkout summaries. */
	public static function item_data( array $data, array $cart_item ): array {
		$domain = $cart_item['beltoft_services_domain'] ?? null;
		if ( is_array( $domain ) && ! empty( $domain['domain'] ) ) {
			$data[] = array( 'key' => 'transfer' === ( $domain['mode'] ?? '' ) ? __( 'Transfer', 'beltoft-services' ) : __( 'Domain', 'beltoft-services' ), 'value' => (string) $domain['domain'], 'display' => HostName::display( (string) $domain['domain'] ) );
		}
		return $data;
	}

	/** Is this the domain line of an order item? The key was stored in the locale of the order, so the value is checked as well. */
	private static function is_domain_meta( object $meta ): bool {
		$key = (string) ( $meta->key ?? '' );
		if ( in_array( $key, array( 'Domain', __( 'Domain', 'beltoft-services' ) ), true ) ) {
			return true;
		}
		return 1 === preg_match( '/^\s*(?:<p>)?\s*(?:[a-z0-9-]+\.)*xn--[a-z0-9-]+(?:\.[a-z0-9-]+)+\s*(?:<\/p>)?\s*$/iD', (string) ( $meta->value ?? '' ) );
	}

	/** The domain meta line of an order item in Unicode letters (the stored value stays punycode). */
	public static function meta_display( $value, $meta ) {
		return is_object( $meta ) && is_string( $value ) && false !== stripos( $value, 'xn--' ) && ( ! isset( $meta->key ) || ! str_starts_with( (string) $meta->key, '_' ) ) && self::is_domain_meta( $meta ) ? (string) preg_replace_callback( '/(?:[a-z0-9-]+\\.)*xn--[a-z0-9-]+(?:\\.[a-z0-9-]+)*/i', static fn ( array $m ): string => esc_html( HostName::display( $m[0] ) ), $value ) : $value;
	}

	/** Keep each domain on its own subscription, so each gets its own license and renewal date. */
	public static function recurring_key( string $key, array $cart_item ): string {
		$domain = (string) ( $cart_item['beltoft_services_domain']['domain'] ?? '' );
		return '' === $domain ? $key : $key . '_domain_' . md5( $domain );
	}

	/** The DNS hosting product, for the claim checkout route. */
	public static function dns_product(): ?\WC_Product {
		return self::product_for( 'dns' );
	}

	/** The domain product, for the tax class of a domain price. */
	public static function domain_product(): ?\WC_Product {
		return self::product_for( 'domain' );
	}

	private static function product_for( string $driver ): ?\WC_Product {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 20, 'meta_key' => Product::META_DRIVER, 'meta_value' => $driver ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small published service-product catalogue, capped at 20.
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->is_purchasable() ) { return $product; }
		}
		return null;
	}
}

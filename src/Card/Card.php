<?php
/**
 * The saved card: the customer's default saved payment method (a WooCommerce token, Stripe's) is the card
 * Pay as you go invoices are charged to.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Card;

use BeltoftServices\Account\Panel;
use BeltoftServices\Usage\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Cards are added, changed and deleted on WooCommerce's own pages (Add payment method, the token table, the
 * checkout). Whenever a token is added, made the default or deleted, the customer's default token is written on
 * the usage account subscription (the keys the Stripe plugin reads when it charges a renewal), or removed from it
 * when the customer has no card left. The Payment methods page shows that one card and WooCommerce's list.
 */
final class Card {

	/**
	 * Subscription meta holding the saved payment method's token: the key WooCommerce Subscriptions gateways
	 * (the Stripe plugin among them) read when a renewal is charged.
	 */
	public const TOKEN_META = '_payment_method_token';

	/**
	 * Meta a retired payment provider left on accounts and their first orders.
	 */
	public const LEGACY_META = array( '_mollie_customer_id', '_mollie_mandate_id' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'action_scheduler_init', array( __CLASS__, 'unschedule' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 40 );
		add_action( 'woocommerce_account_payment-methods_endpoint', array( __CLASS__, 'swap_page' ), 1 );
		add_filter( 'bricks/frontend/render_element', array( __CLASS__, 'bricks_methods' ), 10, 2 );
		// Any change of a customer's saved cards: the default card is the usage account's card.
		add_action( 'woocommerce_new_payment_token', array( __CLASS__, 'token_event' ), 20, 2 );
		add_action( 'woocommerce_payment_token_set_default', array( __CLASS__, 'token_event' ), 20, 2 );
		add_action( 'woocommerce_payment_token_deleted', array( __CLASS__, 'token_deleted' ), 20, 2 );
	}

	/**
	 * The payment methods page address.
	 */
	public static function url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'payment-methods' ) : home_url( '/' );
	}

	/**
	 * The page is listed even when no gateway offers saved cards, so the card box has a place.
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
	 * The page shows the card box, then WooCommerce's own list only when the customer has saved methods.
	 */
	public static function swap_page(): void {
		remove_action( 'woocommerce_account_payment-methods_endpoint', 'woocommerce_account_payment_methods' );
		add_action( 'woocommerce_account_payment-methods_endpoint', array( __CLASS__, 'render' ), 9 );
		add_action( 'woocommerce_account_payment-methods_endpoint', array( __CLASS__, 'render_tokens' ), 10 );
	}

	/**
	 * With Bricks the payment methods list is its own element: the card box goes in front of it, and the list
	 * (which only says that nothing is saved) stays only when the customer has saved methods.
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

	// ------------------------------------------------------------------ what the page shows.

	/**
	 * What the page shows: the customer's default saved card, never the account's own meta, so the box and
	 * WooCommerce's list always agree. An account that does not carry that card yet is linked here (the customer's
	 * own page, no new account).
	 *
	 * @param int $user_id Customer.
	 * @return array{state: string, text: string, can_start: bool, add_url: string, change: bool}
	 */
	public static function data( int $user_id ): array {
		$token = self::default_token( $user_id );
		$info  = $token ? self::summarize_token( $token ) : null;
		$state = 'none';

		if ( $info ) {
			$state = self::usable( $info ) ? 'valid' : 'expired';
			self::guarded_sync( $user_id, false );
		}

		return array(
			'state'     => $state,
			'text'      => $info ? self::describe( $info ) : '',
			'can_start' => array() !== self::token_gateways(),
			'add_url'   => self::add_url(),
			'change'    => 'none' !== $state,
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

	// ------------------------------------------------------------------ gateways.

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

	/**
	 * Enabled payment gateways that can save a card on My Account and charge a subscription with it.
	 *
	 * @return array<string, \WC_Payment_Gateway>
	 */
	public static function token_gateways(): array {
		$found    = array();
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		foreach ( $gateways as $id => $gateway ) {
			if ( $gateway instanceof \WC_Payment_Gateway && 'yes' === $gateway->enabled && $gateway->supports( 'add_payment_method' ) && $gateway->supports( 'subscriptions' ) ) {
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

	// ------------------------------------------------------------------ the saved card (a WooCommerce token).

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
	 * Details of the account's card, or null when it has none that can be charged (no saved token, or a payment
	 * method that is not active on the site).
	 *
	 * @param \WC_Subscription $account Account.
	 * @return array{status: string, expired: bool, label: string, last4: string, expiry: string}|null
	 */
	public static function details( \WC_Subscription $account ): ?array {
		if ( ! self::gateway_active( (string) $account->get_payment_method() ) ) {
			return null;
		}

		return self::token_details( $account );
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

	// ------------------------------------------------------------------ the default card is the account's card.

	/**
	 * Can this saved method be the Pay as you go card: its gateway is registered and charges subscriptions.
	 *
	 * @param \WC_Payment_Token $token Token.
	 */
	public static function eligible( \WC_Payment_Token $token ): bool {
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ $token->get_gateway_id() ] ?? null;

		return $gateway instanceof \WC_Payment_Gateway && $gateway->supports( 'subscriptions' );
	}

	/**
	 * The customer's default saved method when it can be charged for subscriptions, else null.
	 *
	 * @param int $user_id Customer.
	 */
	public static function default_token( int $user_id ): ?\WC_Payment_Token {
		if ( $user_id < 1 || ! class_exists( \WC_Payment_Tokens::class ) ) {
			return null;
		}

		$token = \WC_Payment_Tokens::get_customer_default_token( $user_id );

		return $token && self::eligible( $token ) ? $token : null;
	}

	/**
	 * Is the account already on this card, with nothing left from a retired provider?
	 *
	 * @param \WC_Subscription  $account Account.
	 * @param \WC_Payment_Token $token   Token.
	 */
	public static function linked( \WC_Subscription $account, \WC_Payment_Token $token ): bool {
		return (string) $account->get_meta( self::TOKEN_META, true ) === $token->get_token()
			&& (string) $account->get_payment_method() === $token->get_gateway_id()
			&& ! $account->is_manual()
			&& ! self::has_legacy( $account );
	}

	/**
	 * Does the account or its first order still carry meta of a retired payment provider?
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function has_legacy( \WC_Subscription $account ): bool {
		$parent = $account->get_parent();

		foreach ( self::LEGACY_META as $key ) {
			if ( '' !== (string) $account->get_meta( $key, true ) || ( $parent && '' !== (string) $parent->get_meta( $key, true ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes the retired provider's meta from the account (saved by the caller) and its first order.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	private static function strip_legacy( \WC_Subscription $account ): void {
		$parent = $account->get_parent();

		foreach ( self::LEGACY_META as $key ) {
			$account->delete_meta_data( $key );
		}

		if ( $parent ) {
			foreach ( self::LEGACY_META as $key ) {
				$parent->delete_meta_data( $key );
			}

			$parent->save();
		}
	}

	/**
	 * A token was added or made the default.
	 *
	 * @param int|string $token_id Token id.
	 * @param mixed      $token    The token.
	 */
	public static function token_event( $token_id, $token = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( $token instanceof \WC_Payment_Token && $token->get_user_id() > 0 ) {
			self::guarded_sync( (int) $token->get_user_id(), true );
		}
	}

	/**
	 * A token was deleted: when it was the default, the newest card left becomes the default, and the account follows.
	 *
	 * @param int|string $token_id Token id.
	 * @param mixed      $token    The deleted token.
	 */
	public static function token_deleted( $token_id, $token = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- action signature.
		if ( ! $token instanceof \WC_Payment_Token || $token->get_user_id() < 1 ) {
			return;
		}

		$user_id = (int) $token->get_user_id();

		try {
			if ( null === \WC_Payment_Tokens::get_customer_default_token( $user_id ) ) {
				$left = array_filter( \WC_Payment_Tokens::get_customer_tokens( $user_id ), array( __CLASS__, 'eligible' ) );

				if ( $left ) {
					\WC_Payment_Tokens::set_users_default( $user_id, (int) max( array_map( static fn( $t ) => $t->get_id(), $left ) ) );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		self::guarded_sync( $user_id, true );
	}

	/**
	 * Sync that never breaks the page that saved or deleted the card: the card is saved either way, and the account
	 * follows the next time a card changes or the customer opens Payment methods.
	 *
	 * @param int  $user_id Customer.
	 * @param bool $create  Make the account when there is none.
	 */
	private static function guarded_sync( int $user_id, bool $create ): void {
		try {
			self::sync( $user_id, $create );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Makes the usage account carry the customer's default card, or no card when none is left. A customer with no
	 * account gets one when the card is saved on their own My Account pages (never at a checkout, by cron or by an admin).
	 *
	 * @param int  $user_id Customer.
	 * @param bool $create  Make the account when there is none.
	 * @throws \RuntimeException When the account cannot be made.
	 */
	public static function sync( int $user_id, bool $create ): void {
		if ( $user_id < 1 || ! function_exists( 'wcs_create_subscription' ) ) {
			return;
		}

		$token   = self::default_token( $user_id );
		$account = Account::for_user( $user_id );

		if ( $token ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = $gateways[ $token->get_gateway_id() ];

			if ( $account ) {
				if ( ! self::linked( $account, $token ) ) {
					$changed = (string) $account->get_meta( self::TOKEN_META, true ) !== $token->get_token();
					self::apply_token( $account, $token, $gateway );
					$account->add_order_note( $changed ? __( 'Card changed: the default card is used for usage invoices.', 'beltoft-services' ) : __( 'The default card is used for usage invoices.', 'beltoft-services' ) );
				}
			} elseif ( $create && get_current_user_id() === $user_id && ! defined( 'WOOCOMMERCE_CHECKOUT' ) && ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
				self::create_account_with_token( $user_id, $token, $gateway );
			}

			return;
		}

		// No usable default: a customer who still holds saved methods keeps the account as it is for now; with none left it has no card.
		if ( $account && ! self::holds_tokens( $user_id ) && self::carries_card( $account ) ) {
			self::unlink( $account );
			$account->add_order_note( __( 'The customer has no saved card left.', 'beltoft-services' ) );
		}
	}

	/**
	 * Does the customer hold any saved method that can be charged for subscriptions?
	 *
	 * @param int $user_id Customer.
	 */
	private static function holds_tokens( int $user_id ): bool {
		return array() !== array_filter( \WC_Payment_Tokens::get_customer_tokens( $user_id ), array( __CLASS__, 'eligible' ) );
	}

	/**
	 * Does the account name a card (a token, a payment method it is charged with, or old provider data)?
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function carries_card( \WC_Subscription $account ): bool {
		return '' !== (string) $account->get_meta( self::TOKEN_META, true ) || ( '' !== (string) $account->get_payment_method() && ! $account->is_manual() ) || self::has_legacy( $account );
	}

	/**
	 * Takes the card off the account: no payment method, charged manually (so nothing is attempted), no token meta.
	 *
	 * @param \WC_Subscription $account Account.
	 */
	public static function unlink( \WC_Subscription $account ): void {
		foreach ( array( self::TOKEN_META, '_wc_stripe_customer', '_wc_stripe_mode' ) as $key ) {
			$account->delete_meta_data( $key );
		}

		self::strip_legacy( $account );
		$account->set_payment_method( '' );
		$account->set_payment_method_title( '' );
		$account->set_requires_manual_renewal( true );
		$account->save();
	}

	/**
	 * Brings one account to the customer's current default card and removes what a retired provider left on it.
	 *
	 * @param \WC_Subscription $account Account.
	 * @param bool             $dry     Report only, write nothing.
	 * @return string One line saying what was (or would be) done.
	 */
	public static function relink( \WC_Subscription $account, bool $dry = false ): string {
		$user_id = (int) $account->get_user_id();
		$token   = self::default_token( $user_id );
		$legacy  = self::has_legacy( $account );
		$prefix  = sprintf( 'Account #%d (user #%d, %s): ', $account->get_id(), $user_id, $account->get_status() );

		if ( $token ) {
			if ( self::linked( $account, $token ) ) {
				return $prefix . 'already on the default card, nothing to do.';
			}

			if ( ! $dry ) {
				$gateways = WC()->payment_gateways()->payment_gateways();
				self::apply_token( $account, $token, $gateways[ $token->get_gateway_id() ] );
				$account->add_order_note( __( 'The default card is used for usage invoices.', 'beltoft-services' ) );
			}

			return $prefix . ( $dry ? 'would link ' : 'linked ' ) . self::describe( self::summarize_token( $token ) ) . ' (' . $token->get_gateway_id() . ')'
				. ( $legacy ? ( $dry ? ', would remove' : ', removed' ) . ' the old provider data' : '' ) . '.';
		}

		if ( $legacy || str_starts_with( (string) $account->get_payment_method(), 'mollie_wc_gateway_' ) ) {
			if ( ! $dry ) {
				self::unlink( $account );
			}

			return $prefix . 'no default card; ' . ( $dry ? 'would take' : 'took' ) . ' the old provider off the account, it has no card.';
		}

		return $prefix . 'no default card, nothing to do.';
	}

	/**
	 * Writes a token on an account: the payment method and the meta the gateway reads when it charges a renewal
	 * (the token, and for Stripe the customer and the mode), and clears what a retired provider left.
	 *
	 * @param \WC_Subscription    $account Account.
	 * @param \WC_Payment_Token   $token   Token.
	 * @param \WC_Payment_Gateway $gateway The token's gateway.
	 */
	public static function apply_token( \WC_Subscription $account, \WC_Payment_Token $token, \WC_Payment_Gateway $gateway ): void {
		$account->set_payment_method( $gateway );
		$account->set_requires_manual_renewal( false );

		$meta = array( self::TOKEN_META => $token->get_token() );

		if ( str_starts_with( $gateway->id, 'stripe_' ) ) {
			$customer = is_callable( array( $token, 'get_customer_id' ) ) ? (string) $token->get_customer_id() : '';

			if ( '' === $customer && function_exists( 'wc_stripe_get_customer_id' ) ) {
				$customer = (string) wc_stripe_get_customer_id( (int) $account->get_user_id() );
			}

			if ( '' !== $customer ) {
				$meta['_wc_stripe_customer'] = $customer;
			}

			$mode = is_callable( array( $token, 'get_environment' ) ) ? (string) $token->get_environment() : '';

			if ( '' === $mode && function_exists( 'wc_stripe_mode' ) ) {
				$mode = (string) wc_stripe_mode();
			}

			if ( '' !== $mode ) {
				$meta['_wc_stripe_mode'] = $mode;
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

		self::strip_legacy( $account );

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
	 * The recurring cleanup job of the old card-only checkout is gone: take it off the schedule.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'beltoft_services_card_cleanup', array(), 'beltoft-services' );
		}
	}
}

<?php
/**
 * Credit codes: a WooCommerce coupon type that is redeemed on the Billing page into account credit.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

use Automattic\WooCommerce\Utilities\DiscountsUtil;
use BeltoftServices\Card\Card;
use BeltoftServices\Locks;
use BeltoftServices\RateLimit;
use BeltoftServices\Usage\Account;

defined( 'ABSPATH' ) || exit;

/**
 * An administrator makes a coupon of the type "Account credit" on WooCommerce's Coupons screen: the amount is the credit in the
 * store currency, and expiry, usage limit, limit per user and allowed emails are the screen's own. Such a coupon never
 * applies in a cart or at checkout. A customer redeems it on the credit page. The rules, in order: logged in and not
 * rate limited, the code is an account credit coupon and is valid (not expired, within its usage limit, within its limit
 * per user, the customer's email allowed), the customer has a saved card that is valid, and that card (by its Stripe
 * fingerprint) has not redeemed this code before. The fingerprints are kept only as keyed hashes
 * in the coupon's meta. The credit is a ledger row (kind promo, reference "code", "<coupon id>-<card hash>"), so the same
 * card never credits one code twice even if the meta write is lost; the coupon's usage count goes up with the customer
 * recorded, so WooCommerce's limit per user counts. The whole redemption holds a lock on the code.
 */
final class Code {

	public const TYPE   = 'beltoft_credit';
	public const ACTION = 'beltoft_services_credit_redeem';
	public const NONCE  = 'beltoft_services_credit_redeem';
	public const META   = '_beltoft_services_credit_cards';

	/**
	 * Redemption attempts per customer and hour.
	 */
	public const RATE_LIMIT = 10;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_coupon_discount_types', array( __CLASS__, 'types' ) );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'not_in_cart' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'cart_message' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Adds the coupon type.
	 *
	 * @param mixed $types Types.
	 * @return mixed
	 */
	public static function types( $types ) {
		if ( is_array( $types ) ) {
			$types[ self::TYPE ] = __( 'Account credit (redeemed on the Billing page, amount in the store currency)', 'beltoft-services' );
		}

		return $types;
	}

	/**
	 * What a cart or checkout says about a credit code.
	 */
	public static function cart_text(): string {
		return __( 'This is a credit code. Redeem it on your Billing page.', 'beltoft-services' );
	}

	/**
	 * A credit code is never valid in a cart or checkout.
	 *
	 * @param mixed $valid  Whether valid so far.
	 * @param mixed $coupon Coupon.
	 * @return mixed
	 * @throws \Exception With the reason, for WooCommerce to show.
	 */
	public static function not_in_cart( $valid, $coupon ) {
		if ( $coupon instanceof \WC_Coupon && $coupon->is_type( self::TYPE ) ) {
			throw new \Exception( esc_html( self::cart_text() ), 100 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped.
		}

		return $valid;
	}

	/**
	 * Whatever else was wrong with a credit code in a cart (expired, used up), the message is the same.
	 *
	 * @param mixed $message Message.
	 * @param mixed $code    Error code.
	 * @param mixed $coupon  Coupon.
	 * @return mixed
	 */
	public static function cart_message( $message, $code = 0, $coupon = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		return $coupon instanceof \WC_Coupon && $coupon->is_type( self::TYPE ) ? self::cart_text() : $message;
	}

	/**
	 * The coupon of a typed code, or null.
	 *
	 * @param string $typed Code as typed.
	 */
	public static function find( string $typed ): ?\WC_Coupon {
		$typed = wc_format_coupon_code( wp_unslash( $typed ) );

		if ( '' === $typed ) {
			return null;
		}

		$id = (int) wc_get_coupon_id_by_code( $typed );

		if ( $id < 1 ) {
			return null;
		}

		$coupon = new \WC_Coupon( $id );

		return $coupon->get_id() && 'publish' === get_post_status( $id ) && $coupon->is_type( self::TYPE ) ? $coupon : null;
	}

	/**
	 * The keyed hash of a card fingerprint for one code (the fingerprint itself is never stored).
	 *
	 * @param string $fingerprint The card fingerprint.
	 * @param int    $coupon_id   Coupon.
	 */
	public static function card_hash( string $fingerprint, int $coupon_id ): string {
		return hash_hmac( 'sha256', $coupon_id . '|' . $fingerprint, wp_salt( 'auth' ) );
	}

	/**
	 * Redeems a code for a customer.
	 *
	 * @param int    $user_id Customer.
	 * @param string $typed   The code as typed.
	 * @return array{ok: bool, message: string}
	 */
	public static function redeem( int $user_id, string $typed ): array {
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return self::fail( __( 'Log in to redeem a code.', 'beltoft-services' ) );
		}

		if ( ! Config::enabled() ) {
			return self::fail( __( 'Account credit is switched off.', 'beltoft-services' ) );
		}

		if ( RateLimit::exceeded( $user_id, 'beltoft-services:credit-code', (int) apply_filters( 'beltoft_services_credit_code_rate_limit', self::RATE_LIMIT ), HOUR_IN_SECONDS ) ) {
			return self::fail( __( 'Too many tries. Wait a while and try again.', 'beltoft-services' ) );
		}

		$coupon = self::find( $typed );

		if ( ! $coupon ) {
			return self::fail( __( 'That code is not valid.', 'beltoft-services' ) );
		}

		list( $locked, $result ) = Locks::with(
			Locks::name( 'creditcode', $coupon->get_id() ),
			Locks::balance_wait(),
			static function () use ( $user_id, $coupon ) {
				return self::redeem_locked( $user_id, new \WC_Coupon( $coupon->get_id() ) );
			}
		);

		return $locked ? $result : self::fail( __( 'The code is busy. Try again.', 'beltoft-services' ) );
	}

	/**
	 * The rules and the writes, under the code's lock (the coupon is read fresh).
	 *
	 * @param int        $user_id Customer.
	 * @param \WC_Coupon $coupon  Coupon.
	 * @return array{ok: bool, message: string}
	 */
	private static function redeem_locked( int $user_id, \WC_Coupon $coupon ): array {
		$cents = Balance::cents( $coupon->get_amount() );
		$user  = get_userdata( $user_id );

		if ( $cents < 1 ) {
			return self::fail( __( 'That code is not valid.', 'beltoft-services' ) );
		}

		$expires = $coupon->get_date_expires();

		if ( $expires && time() > $expires->getTimestamp() ) {
			return self::fail( __( 'That code has expired.', 'beltoft-services' ) );
		}

		if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return self::fail( __( 'That code has been used up.', 'beltoft-services' ) );
		}

		$per_user = (int) $coupon->get_usage_limit_per_user();

		if ( $per_user > 0 && (int) $coupon->get_data_store()->get_usage_by_user_id( $coupon, (string) $user_id ) >= $per_user ) {
			return self::fail( __( 'You have already used this code.', 'beltoft-services' ) );
		}

		$restrictions = array_filter( array_map( 'strtolower', (array) $coupon->get_email_restrictions() ) );

		if ( $restrictions ) {
			$emails = array_filter( array( strtolower( (string) $user->user_email ), strtolower( (string) get_user_meta( $user_id, 'billing_email', true ) ) ) );

			if ( ! DiscountsUtil::is_coupon_emails_allowed( $emails, $restrictions ) ) {
				return self::fail( __( 'This code is not available for your email address.', 'beltoft-services' ) );
			}
		}

		$account = Account::for_user( $user_id );
		if ( ! $account || null === Card::token_for( $account ) || ! Card::usable( Card::token_details( $account ) ) ) {
			return self::fail( __( 'Add a card first, then redeem the code.', 'beltoft-services' ) );
		}

		// The card fingerprint comes from the gateway's payment method (Stripe's), never stored in the clear.
		$fingerprint = Card::token_fingerprint( $account );

		if ( '' === $fingerprint ) {
			return self::fail( __( 'This card cannot be used to redeem a code. Add a credit or debit card.', 'beltoft-services' ) );
		}

		$hash  = self::card_hash( $fingerprint, $coupon->get_id() );
		$known = array_values( array_filter( (array) $coupon->get_meta( self::META, true ) ) );
		$ref   = $coupon->get_id() . '-' . substr( $hash, 0, 32 );

		if ( in_array( $hash, $known, true ) || Balance::exists( Balance::PROMO, 'code', $ref ) ) {
			return self::fail( __( 'This code was already used with this card.', 'beltoft-services' ) );
		}

		$note = sprintf( /* translators: %s: the code. */ __( 'Credit code %s', 'beltoft-services' ), strtoupper( $coupon->get_code() ) );

		if ( ! Balance::credit( $user_id, $cents, Balance::PROMO, 'code', $ref, $note ) ) {
			return self::fail( __( 'The credit could not be added. Try again.', 'beltoft-services' ) );
		}

		$known[] = $hash;
		$coupon->update_meta_data( self::META, array_values( array_unique( $known ) ) );
		$coupon->save();
		$coupon->increase_usage_count( (string) $user_id );

		return array(
			'ok'      => true,
			'message' => sprintf( /* translators: %s: amount with currency. */ __( '%s credit added.', 'beltoft-services' ), Page::money( $cents, get_woocommerce_currency() ) ),
		);
	}

	/**
	 * A refusal.
	 *
	 * @param string $message Why.
	 * @return array{ok: bool, message: string}
	 */
	private static function fail( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
		);
	}

	/**
	 * The form on the credit page.
	 */
	public static function handle(): void {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || empty( $_POST[ self::ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			return;
		}

		$back = Page::url();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			wc_add_notice( __( 'The page expired. Please try again.', 'beltoft-services' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$typed  = isset( $_POST['beltoft_credit_code'] ) ? sanitize_text_field( wp_unslash( $_POST['beltoft_credit_code'] ) ) : '';
		$result = self::redeem( get_current_user_id(), $typed );

		wc_add_notice( $result['message'], $result['ok'] ? 'success' : 'error', $result['ok'] ? array() : array( 'beltoft_field' => 'code' ) );
		wp_safe_redirect( $back );
		exit;
	}
}

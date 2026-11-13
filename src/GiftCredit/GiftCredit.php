<?php
/**
 * Account credit as gift cards: the glue between this plugin and the add-on Beltoft Gift Cards Account Credit.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\GiftCredit;

use Bgca\Wallet;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The generic part (wallet, top-up, adding a gift card code, paying checkout orders and renewals with the cards) is the add-on plugin
 * Beltoft Gift Cards Account Credit, which builds on Beltoft Gift Cards for WooCommerce. This folder is what is particular to this plugin:
 * - {@see PromoFlag}: the product box "promotional credit can pay orders and renewals" (free cards pay only such lines).
 * - {@see Rules}: when the cards pay (the saved-card rule at checkout, the usage minimum) and in which currency the account is.
 * - {@see Page}: the credit page of My Account, with the usage estimate.
 * - {@see Checkout}: what the checkout shows before the order is made, and the card that is still asked for.
 *
 * It does nothing unless the setting `gift_credit` is on and the add-on is active (active()). Money in campaigns is a free gift card
 * made in the gift card plugin (source promotion); there is no credit coupon type. Nothing in this folder uses src/Credit/.
 */
final class GiftCredit {

	/**
	 * Is credit held as gift cards: the setting is on, and the add-on and the gift card plugin are active?
	 */
	public static function active(): bool {
		return (bool) Settings::get( 'gift_credit' ) && class_exists( '\\Bgca\\Plugin' ) && \Bgca\Plugin::requirements_met();
	}

	/**
	 * Hooks. The setting is always listed (its text says what it needs); the product box is always there.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'settings_fields' ), 9 );
		PromoFlag::register();

		if ( ! class_exists( '\\Bgca\\Plugin' ) ) {
			return;
		}

		// The add-on's filters: every answer is "no change" while gift credit is off, except that the cards then pay nothing.
		add_filter( 'bgca_account_view_enabled', array( __CLASS__, 'own_page_off' ) );
		add_filter( 'bgca_account_url', array( Page::class, 'url_filter' ) );
		add_filter( 'bgca_account_currency', array( Rules::class, 'account_currency' ), 10, 2 );
		add_filter( 'bgca_card_may_pay_line', array( PromoFlag::class, 'card_may_pay_line' ), 10, 4 );
		add_filter( 'bgca_card_may_pay_fee', array( Rules::class, 'card_may_pay_fee' ), 10, 3 );
		add_filter( 'bgca_allow_partial_use', array( Rules::class, 'allow_partial_use' ), 10, 4 );
		add_filter( 'bgca_auto_apply', array( Rules::class, 'auto_apply' ), 10, 4 );
		// After the usage account's own answer (20) is not needed: a customer with cards and no other currency is in the cards' currency.
		add_filter( 'beltoft_services_user_currency', array( Rules::class, 'user_currency' ), 11, 2 );
		add_filter( 'beltoft_services_usage_credit_cents', array( Rules::class, 'usage_credit_cents' ), 10, 3 );

		Page::register();
		Checkout::register();
	}

	/**
	 * This plugin draws the credit page itself, so the add-on's own page is off while gift credit is on. With gift credit off the add-on
	 * shows nothing either: the cards are not part of the shop yet.
	 *
	 * @return bool
	 */
	public static function own_page_off(): bool {
		return false;
	}

	/**
	 * The setting on the General tab, beside the other account credit settings.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions by key.
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_fields( array $fields ): array {
		return array_merge(
			$fields,
			array(
				'gift_credit' => array(
					'tab'     => 'general',
					'section' => 'credit',
					'type'    => 'checkbox',
					'default' => 0,
					'label'   => __( 'Account credit as gift cards', 'beltoft-services' ),
					'desc'    => __( 'Account credit is gift cards of the plugin Beltoft Gift Cards for WooCommerce, handled by the add-on Beltoft Gift Cards Account Credit: a top-up buys a card for the customer, free credit is a free card made in the gift card plugin, and the cards pay checkout orders, renewals and usage invoices (set in the add-on). Credit already on the old ledger is not applied while this is on, so move it to cards first. Needs both plugins to be active; without them this setting does nothing.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * What the customer holds in a currency, in cents.
	 *
	 * @param int    $user_id  Customer.
	 * @param string $currency Currency code.
	 */
	public static function balance( int $user_id, string $currency ): int {
		return self::active() ? Wallet::totals( $user_id, $currency )['total'] : 0;
	}
}

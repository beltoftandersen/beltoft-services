<?php
/**
 * The e-mail of a new card, worded as credit added to the account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\GiftCredit;

defined( 'ABSPATH' ) || exit;

/**
 * While gift credit is on, a card is account credit, so the gift card plugin's delivery e-mail ("You received a gift card") says
 * credit instead (owner 2026-11-19: customers never read "gift card"). The e-mail is still the plugin's own WooCommerce e-mail
 * (id bgcw_gift_card_delivery, sent when it always was: an admin makes a card for an address; top-ups send none): only its
 * subject, heading and templates change. Templates: templates/emails/credit-added.php and its plain twin.
 */
final class CreditMail {

	/** The gift card plugin's e-mail id. */
	public const EMAIL_ID = 'bgcw_gift_card_delivery';

	/** The plugin's template names, and ours in their place. */
	private const TEMPLATES = array(
		'emails/gift-card-delivery.php'       => 'emails/credit-added.php',
		'emails/plain/gift-card-delivery.php' => 'emails/plain/credit-added.php',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wc_get_template', array( __CLASS__, 'template' ), 20, 2 );
		add_filter( 'woocommerce_email_subject_' . self::EMAIL_ID, array( __CLASS__, 'subject' ), 20, 3 );
		add_filter( 'woocommerce_email_heading_' . self::EMAIL_ID, array( __CLASS__, 'heading' ), 20, 3 );
	}

	/**
	 * Our template for the plugin's delivery e-mail.
	 *
	 * @param mixed $located       Path found so far.
	 * @param mixed $template_name Template name.
	 * @return mixed
	 */
	public static function template( $located, $template_name = '' ) {
		if ( ! GiftCredit::active() || ! is_string( $template_name ) || ! isset( self::TEMPLATES[ $template_name ] ) ) {
			return $located;
		}

		$ours = BELTOFT_SERVICES_PATH . 'templates/' . self::TEMPLATES[ $template_name ];

		return is_readable( $ours ) ? $ours : $located;
	}

	/**
	 * Subject: "{amount} credit added to your account".
	 *
	 * @param mixed $subject Subject so far.
	 * @param mixed $object  The card.
	 * @param mixed $email   The e-mail.
	 * @return mixed
	 */
	public static function subject( $subject, $object = null, $email = null ) {
		if ( ! GiftCredit::active() || ! $email instanceof \WC_Email ) {
			return $subject;
		}

		/* translators: %s: amount with currency. */
		return sprintf( __( '%s credit added to your account', 'beltoft-services' ), self::amount( $email ) );
	}

	/**
	 * Heading: "Credit added to your account".
	 *
	 * @param mixed $heading Heading so far.
	 * @param mixed $object  The card.
	 * @param mixed $email   The e-mail.
	 * @return mixed
	 */
	public static function heading( $heading, $object = null, $email = null ) {
		if ( ! GiftCredit::active() || ! $email instanceof \WC_Email ) {
			return $heading;
		}

		return __( 'Credit added to your account', 'beltoft-services' );
	}

	/**
	 * The card's amount as plain text, from the e-mail's own placeholder.
	 *
	 * @param \WC_Email $email The e-mail.
	 */
	private static function amount( \WC_Email $email ): string {
		return wp_strip_all_tags( html_entity_decode( (string) ( $email->placeholders['{amount}'] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
	}
}

<?php
/**
 * E-mail (plain text): credit added to the customer's account.
 *
 * @package BeltoftServices
 * @var object    $gift_card     The card.
 * @var string    $email_heading Heading.
 * @var \WC_Email $email         The e-mail.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( wp_strip_all_tags( wc_price( (float) $gift_card->initial_amount, array( 'currency' => (string) $gift_card->currency ) ) ) ) . "\n\n";
echo esc_html__( 'This credit has been added to your account. It pays your orders, renewals and pay as you go invoices by itself. You can see your balance under Credit in your account.', 'beltoft-services' ) . "\n\n";
if ( ! empty( $gift_card->message ) ) {
	echo esc_html( (string) $gift_card->message ) . "\n\n";
}
echo esc_html__( 'See your credit', 'beltoft-services' ) . ': ' . esc_url_raw( \BeltoftServices\GiftCredit\Page::url() ) . "\n\n";
/* translators: %s: the code. */
echo esc_html( sprintf( __( 'No account with this e-mail address yet? Create one with this address, or type the code %s on the Credit page.', 'beltoft-services' ), (string) $gift_card->code ) ) . "\n\n";
echo esc_html( wp_strip_all_tags( wptexturize( (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.

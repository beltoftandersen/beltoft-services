<?php
/**
 * Customer email (plain text): a long renewal is coming.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-renewal-reminder.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var array<string,mixed> $renewal            date, plan, price_html, view_url.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$beltoft_services_r = is_array( $renewal ?? null ) ? $renewal : array();

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_strip_all_tags( $email_heading );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	echo wp_strip_all_tags( sprintf( __( 'Hi %s,', 'beltoft-services' ), $customer_name ) ) . "\n\n";
} else {
	echo __( 'Hi,', 'beltoft-services' ) . "\n\n";
}

/* translators: 1: plan, 2: date. */
echo wp_strip_all_tags( sprintf( __( 'Your subscription %1$s renews on %2$s.', 'beltoft-services' ), (string) ( $beltoft_services_r['plan'] ?? '' ), (string) ( $beltoft_services_r['date'] ?? '' ) ) ) . "\n\n";
echo __( 'Renewal price:', 'beltoft-services' ) . ' ' . html_entity_decode( wp_strip_all_tags( (string) ( $beltoft_services_r['price_html'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) . "\n\n";
echo __( 'You do not need to do anything to keep your service: it renews and is charged on that date.', 'beltoft-services' ) . "\n\n";
echo __( 'If you do not want to renew, you can cancel in My Account: open the subscription and choose Cancel. Your service then runs until the end of the period you have paid for.', 'beltoft-services' ) . "\n\n";
echo __( 'Open your subscription in My Account:', 'beltoft-services' ) . ' ' . esc_url_raw( (string) ( $beltoft_services_r['view_url'] ?? '' ) ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

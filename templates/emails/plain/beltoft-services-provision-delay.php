<?php
/**
 * Customer email (plain text): a delay setting up the service.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-provision-delay.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $additional_content Additional content from the email's settings.
 * @var \WC_Email         $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_strip_all_tags( $email_heading );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	echo wp_strip_all_tags( sprintf( __( 'Hi %s,', 'beltoft-services' ), $customer_name ) ) . "\n\n";
} else {
	echo __( 'Hi,', 'beltoft-services' ) . "\n\n";
}

echo __( "There's a delay setting up your service. We're on it.", 'beltoft-services' ) . "\n\n";

echo __( 'View your services in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

<?php
/**
 * Customer email (plain text): your domain is registered.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-domain-registered.php.
 *
 * @package BeltoftServices
 *
 * @var string   $email_heading      Heading.
 * @var string   $customer_name      Customer's first name, or ''.
 * @var string   $domain_name        The domain.
 * @var string   $domain_renews      Next renewal date, or ''.
 * @var string   $domains_url        My Account, Domains.
 * @var string[] $nameservers_shown  Name servers of the domain (registration only).
 * @var string   $additional_content Additional content from the email's settings.
 * @var \WC_Email $email             The email.
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

echo wp_strip_all_tags( sprintf( /* translators: %s: domain name. */ __( 'Your domain %s is registered.', 'beltoft-services' ), $domain_name ) ) . "\n\n";

if ( $nameservers_shown ) {
	echo wp_strip_all_tags( sprintf( /* translators: %s: name servers, comma separated. */ __( 'Name servers: %s', 'beltoft-services' ), implode( ', ', array_map( 'strval', $nameservers_shown ) ) ) ) . "\n\n";
}

if ( '' !== $domain_renews ) {
	/* translators: %s: date. */
	echo wp_strip_all_tags( sprintf( __( 'Next renewal: %s.', 'beltoft-services' ), $domain_renews ) ) . "\n\n";
}

echo __( 'Manage your domains in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $domains_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

<?php
/**
 * Customer email (plain text): the SSL certificate is issued.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-ssl-issued.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var string              $account_url        The certificate's page.
 * @var array<string,mixed> $ssl                Display values.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
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

/* translators: %s: name on the certificate. */
echo wp_strip_all_tags( sprintf( $ssl['smime'] ? __( 'Your S/MIME certificate for %s is issued.', 'beltoft-services' ) : __( 'Your SSL certificate for %s is issued.', 'beltoft-services' ), $ssl['name'] ) );
echo '' !== $ssl['valid_to'] ? ' ' . wp_strip_all_tags( sprintf( /* translators: %s: date. */ __( 'It is valid until %s.', 'beltoft-services' ), $ssl['valid_to'] ) ) . "\n\n" : "\n\n";
echo '1. ' . __( 'Download the certificate with its chain from your certificate page.', 'beltoft-services' ) . "\n";
$beltoft_services_n = 2;
if ( $ssl['key_waiting'] ) {
	echo $beltoft_services_n++ . '. ' . ( $ssl['smime'] ? __( 'Take the key and certificate we made for you as a .p12 file. It can be taken once, in the next 7 days, and then we delete the key. It is never sent by email.', 'beltoft-services' ) : __( 'Take the private key we made for you. It can be taken once, in the next 7 days, and then we delete it. It is never sent by email.', 'beltoft-services' ) ) . "\n";
}
if ( $ssl['smime'] ) {
	echo $beltoft_services_n . '. ' . __( 'Install them in your email program to sign and encrypt email.', 'beltoft-services' ) . "\n\n";
} else {
	echo $beltoft_services_n . '. ' . __( 'Install both on your server.', 'beltoft-services' ) . "\n\n";
	echo __( 'Certificates are valid for a short time, at most 199 days now and 100 days from March 2027. During your year the certificate is reissued, and each new one must be installed. We email you each time.', 'beltoft-services' ) . "\n\n";
	echo __( 'To fetch the current certificate from a script, read this address with an API token:', 'beltoft-services' ) . ' ' . esc_url_raw( $ssl['pull_url'] ) . "\n\n";
}
echo __( 'Your certificate:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

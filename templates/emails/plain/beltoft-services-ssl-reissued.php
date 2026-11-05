<?php
/**
 * Customer email (plain text): a new certificate is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-ssl-reissued.php.
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
echo wp_strip_all_tags( sprintf( __( 'A new certificate for %s is ready.', 'beltoft-services' ), $ssl['name'] ) );
echo '' !== $ssl['valid_to'] ? ' ' . wp_strip_all_tags( sprintf( /* translators: %s: date. */ __( 'It is valid until %s.', 'beltoft-services' ), $ssl['valid_to'] ) ) . "\n\n" : "\n\n";
echo ( $ssl['by_customer'] ? __( 'You asked for this reissue.', 'beltoft-services' ) : __( 'Certificates are valid for a short time, so the certificate authority reissues yours during the year.', 'beltoft-services' ) ) . ' ' . __( 'The old certificate keeps working until it ends, but install the new one before then.', 'beltoft-services' ) . "\n\n";
if ( $ssl['key_waiting'] ) {
	echo __( 'We made a new private key for this certificate. Take it once from your certificate page in the next 7 days.', 'beltoft-services' ) . "\n\n";
}
echo __( 'To fetch the current certificate from a script, read this address with an API token:', 'beltoft-services' ) . ' ' . esc_url_raw( $ssl['pull_url'] ) . "\n\n";
echo __( 'Your certificate:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

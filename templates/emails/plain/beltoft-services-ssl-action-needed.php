<?php
/**
 * Customer email (plain text): the domain has to be validated.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-ssl-action-needed.php.
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
echo wp_strip_all_tags( sprintf( __( 'To issue your certificate for %s the domain has to be validated.', 'beltoft-services' ), $ssl['name'] ) ) . "\n\n";
if ( 'email' === $ssl['reason'] ) {
	/* translators: %s: approval email address. */
	echo wp_strip_all_tags( sprintf( __( 'The certificate authority has sent an email to %s. Open it and approve the request.', 'beltoft-services' ), $ssl['approver'] ) ) . "\n\n";
} elseif ( 'file' === $ssl['reason'] ) {
	echo __( 'Put a file with this content on your website, at this address:', 'beltoft-services' ) . "\n\n";
	foreach ( $ssl['rows'] as $beltoft_services_row ) {
		echo wp_strip_all_tags( (string) $beltoft_services_row['name'] ) . "\n" . wp_strip_all_tags( (string) $beltoft_services_row['value'] ) . "\n\n";
	}
} else {
	echo __( 'Add this CNAME record where the DNS of your domain is hosted:', 'beltoft-services' ) . "\n\n";
	foreach ( $ssl['rows'] as $beltoft_services_row ) {
		echo 'CNAME ' . wp_strip_all_tags( (string) $beltoft_services_row['name'] ) . ' ' . wp_strip_all_tags( (string) $beltoft_services_row['value'] ) . "\n";
	}
	echo "\n" . __( 'Leave the record in place after the certificate is issued. It lets later reissues validate without you.', 'beltoft-services' ) . "\n\n";
}
echo __( 'The certificate is issued a few minutes after the authority has seen it. Certificates that name a company can take a few days.', 'beltoft-services' ) . "\n\n";
echo __( 'Your certificate:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

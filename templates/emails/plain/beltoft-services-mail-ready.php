<?php
/**
 * Customer email (plain text): the email domain is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-mail-ready.php.
 *
 * @package BeltoftServices
 *
 * @var string                           $email_heading      Heading.
 * @var string                           $customer_name      Customer's first name, or ''.
 * @var string                           $account_url        The Email area.
 * @var string                           $mail_domain        The email domain.
 * @var bool                             $mail_external      The domain is hosted elsewhere.
 * @var array<int, array<string, mixed>> $mail_records       Records: name, type, value, priority, status.
 * @var string                           $mail_host          The mail host, or ''.
 * @var int                              $mail_imap          IMAP port.
 * @var int                              $mail_smtp          SMTP port.
 * @var string                           $mail_guide_url     The setup guide, or ''.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
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

/* translators: %s: domain name. */
echo wp_strip_all_tags( sprintf( __( 'Your email domain %s is set up.', 'beltoft-services' ), $mail_domain ) ) . "\n\n";

if ( $mail_records ) {
	echo __( 'Add these DNS records where the DNS of your domain is hosted. Email works once they are in place, and we check for them every ten minutes.', 'beltoft-services' ) . "\n\n";
	foreach ( $mail_records as $beltoft_services_row ) {
		echo wp_strip_all_tags( $beltoft_services_row['type'] . ' ' . $beltoft_services_row['name'] . ' ' . ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . $beltoft_services_row['value'] ) . "\n";
	}
	echo "\n";
} else {
	echo __( 'The DNS records are in place. You can add your mailboxes now.', 'beltoft-services' ) . "\n\n";
}

echo __( 'Add your mailboxes in the Email area. Passwords are never sent by email.', 'beltoft-services' ) . "\n\n";
if ( '' !== $mail_host ) {
	/* translators: 1: mail host, 2: IMAP port, 3: mail host, 4: SMTP port. */
	echo wp_strip_all_tags( sprintf( __( 'If your mail app does not find the settings by itself, enter them by hand. Incoming: IMAP, server %1$s, port %2$d, SSL/TLS. Outgoing: SMTP, server %3$s, port %4$d, SSL/TLS. The user name is the full email address.', 'beltoft-services' ), $mail_host, $mail_imap, $mail_host, $mail_smtp ) ) . "\n\n";
}

echo __( 'Open your Email area:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( '' !== $mail_guide_url ) {
	echo __( 'Setup guide for mail programs:', 'beltoft-services' ) . ' ' . esc_url_raw( $mail_guide_url ) . "\n\n";
}

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

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
echo wp_strip_all_tags( sprintf( __( 'Your email domain %s is set up.', 'beltoft-services' ), $mail_domain ) );
echo $mail_records ? "\n\n" : ' ' . __( 'The DNS records are in place.', 'beltoft-services' ) . "\n\n";

if ( $mail_records ) {
	echo __( 'Add these DNS records where the DNS of your domain is hosted. Email works once they are in place, and we check for them every ten minutes.', 'beltoft-services' ) . "\n\n";
	foreach ( $mail_records as $beltoft_services_row ) {
		echo wp_strip_all_tags( $beltoft_services_row['type'] . ' ' . $beltoft_services_row['name'] . ' ' . ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . $beltoft_services_row['value'] ) . "\n";
	}
	echo "\n";
}

echo __( 'Next steps', 'beltoft-services' ) . "\n\n";
echo '1. ' . __( 'Create your mailboxes in your Email area:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n";
echo '2. ' . __( 'Show each mailbox password once there and copy it. Passwords are never sent by email.', 'beltoft-services' ) . "\n";
echo '3. ' . __( 'Add the mailbox to your mail app. Most apps find the settings from the email address.', 'beltoft-services' );
echo '' !== $mail_guide_url ? ' ' . __( 'Setup guide:', 'beltoft-services' ) . ' ' . esc_url_raw( $mail_guide_url ) . "\n\n" : "\n\n";

if ( '' !== $mail_host ) {
	echo __( 'Settings to enter by hand, if your mail app does not find them:', 'beltoft-services' ) . "\n\n";
	/* translators: 1: mail host, 2: IMAP port. */
	$beltoft_services_in = sprintf( __( '%1$s, port %2$d, SSL/TLS', 'beltoft-services' ), $mail_host, $mail_imap );
	/* translators: 1: mail host, 2: SMTP port. */
	$beltoft_services_out = sprintf( __( '%1$s, port %2$d, SSL/TLS', 'beltoft-services' ), $mail_host, $mail_smtp );
	echo '- ' . __( 'Incoming mail (IMAP):', 'beltoft-services' ) . ' ' . wp_strip_all_tags( $beltoft_services_in ) . "\n";
	echo '- ' . __( 'Outgoing mail (SMTP):', 'beltoft-services' ) . ' ' . wp_strip_all_tags( $beltoft_services_out ) . "\n";
	echo '- ' . __( 'User name:', 'beltoft-services' ) . ' ' . __( 'The full email address', 'beltoft-services' ) . "\n\n";
}

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

<?php
/**
 * Customer email (plain text): DNS records are still missing.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-mail-dns-reminder.php.
 *
 * @package BeltoftServices
 *
 * @var string                           $email_heading      Heading.
 * @var string                           $customer_name      Customer's first name, or ''.
 * @var string                           $account_url        The Email area.
 * @var string                           $mail_domain        The email domain.
 * @var bool                             $mail_external      The domain is hosted elsewhere.
 * @var array<int, array<string, mixed>> $mail_records_needed Needed records still missing: name, type, value, priority, status.
 * @var string                           $mail_guide_url     The setup guide, or ''.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

/* translators: %s: domain name. */
echo wp_strip_all_tags( sprintf( __( 'Email for %s will not work fully until these DNS records are added where the domain is hosted. Mail arrives once the MX record is in place. Your mailboxes cannot send until the SPF and DKIM records are found.', 'beltoft-services' ), $mail_domain ) ) . "\n\n";

foreach ( $mail_records_needed as $beltoft_services_row ) {
	echo wp_strip_all_tags( $beltoft_services_row['type'] . ' ' . $beltoft_services_row['name'] . ' ' . ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . $beltoft_services_row['value'] ) . "\n";
}
echo "\n";
echo __( 'It can take a while before a change at your DNS host is visible. We keep checking, and the Email area shows which records we have found.', 'beltoft-services' ) . "\n\n";
echo __( 'Open your Email area:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( '' !== $mail_guide_url ) {
	echo __( 'Setup guide for mail programs:', 'beltoft-services' ) . ' ' . esc_url_raw( $mail_guide_url ) . "\n\n";
}

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

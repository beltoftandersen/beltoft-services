<?php
/**
 * Customer email (plain text): DNS records of an email domain are missing.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-mail-dns-lost.php.
 *
 * @package BeltoftServices
 *
 * @var string                           $email_heading      Heading.
 * @var string                           $customer_name      Customer's first name, or ''.
 * @var string                           $account_url        The Email area.
 * @var string                           $mail_domain        The email domain.
 * @var bool                             $mail_moved         The name servers no longer point to us.
 * @var string                           $mail_lost_names    The lost records, such as MX, SPF.
 * @var array<int, array<string, mixed>> $mail_records_lost  The lost records still missing: name, type, value, priority, status.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

/* translators: 1: domain name, 2: names of the lost records such as MX, SPF. */
echo wp_strip_all_tags( sprintf( __( 'We found the DNS records of %1$s before. These are not found now: %2$s. Mail may not reach your mailboxes, and sending can be paused. Nothing is deleted.', 'beltoft-services' ), $mail_domain, $mail_lost_names ) ) . "\n\n";

if ( $mail_moved ) {
	/* translators: %s: domain name. */
	echo wp_strip_all_tags( sprintf( __( 'The name servers of %s no longer point to us, so the records we made for you are not used.', 'beltoft-services' ), $mail_domain ) ) . "\n\n";
}

echo __( 'Add the records again at your DNS provider:', 'beltoft-services' ) . "\n\n";

foreach ( $mail_records_lost as $beltoft_services_row ) {
	echo wp_strip_all_tags( $beltoft_services_row['type'] . ' ' . $beltoft_services_row['name'] . ' ' . ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . $beltoft_services_row['value'] ) . "\n";
}

echo "\n";
echo __( 'We keep checking. It can take a while before a change at your DNS provider is visible.', 'beltoft-services' ) . "\n\n";
echo __( 'Open your Email area:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

<?php
/**
 * Customer email (plain text): the DNS records of an email domain are found again.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-mail-dns-back.php.
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

/* translators: %s: domain name. */
echo wp_strip_all_tags( sprintf( __( 'The DNS records of %s are found again. Email works as before.', 'beltoft-services' ), $mail_domain ) ) . "\n\n";
echo __( 'Open your Email area:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

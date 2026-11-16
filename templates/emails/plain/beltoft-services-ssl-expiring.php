<?php
/**
 * Customer email (plain text): the certificate ends soon.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-ssl-expiring.php.
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

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

/* translators: 1: name on the certificate, 2: date. */
echo wp_strip_all_tags( sprintf( __( 'Your certificate for %1$s ends on %2$s and no newer certificate has been issued yet.', 'beltoft-services' ), $ssl['name'], $ssl['valid_to'] ) ) . "\n\n";
echo __( 'We have told our staff. You can also reissue the certificate from your certificate page, which is free.', 'beltoft-services' ) . "\n\n";
echo __( 'Your certificate:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

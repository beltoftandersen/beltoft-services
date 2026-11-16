<?php
/**
 * Customer email (plain text): a delay setting up the service.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-provision-delay.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $additional_content Additional content from the email's settings.
 * @var \WC_Email         $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

echo __( "There's a delay setting up your service. We're on it.", 'beltoft-services' ) . "\n\n";

echo __( 'View your services in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

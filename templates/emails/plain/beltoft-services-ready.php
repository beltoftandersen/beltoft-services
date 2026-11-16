<?php
/**
 * Customer email (plain text): the service is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-ready.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $deletion_date      Date of the deletion (deletion warning), or ''.
 * @var array<string, string> $details        Lines about the service (label => value), may be empty. Never a password.
 * @var string            $disk_server_url    Card of the server an extra disk is on, or ''.
 * @var string            $disk_guide_url     The disk's setup guide, or ''.
 * @var bool              $dns_only           DNS hosting only.
 * @var string[]          $nameservers        Our name servers (DNS hosting only).
 * @var string            $usage              Usage line (limit exceeded), or ''.
 * @var string            $additional_content Additional content from the email's settings.
 * @var \WC_Email         $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

/* translators: %s: product name. */
echo wp_strip_all_tags( sprintf( __( 'Your %s is ready to use.', 'beltoft-services' ), $product_name ) ) . "\n\n";

foreach ( $details as $beltoft_services_label => $beltoft_services_value ) {
	echo wp_strip_all_tags( $beltoft_services_label . ': ' . $beltoft_services_value ) . "\n";
}

if ( $details ) {
	echo "\n";
}

if ( ! empty( $disk_server_url ) ) {
	echo __( 'Find your disk in My account > VPS > your server > Disks.', 'beltoft-services' ) . "\n";
	echo __( 'Open your server:', 'beltoft-services' ) . ' ' . esc_url_raw( $disk_server_url ) . "\n";
	echo __( 'Disk setup guide:', 'beltoft-services' ) . ' ' . esc_url_raw( $disk_guide_url ) . "\n\n";
}

if ( ! empty( $dns_only ) && $nameservers ) {
	echo __( 'Our name servers:', 'beltoft-services' ) . ' ' . wp_strip_all_tags( implode( ', ', array_map( 'strval', $nameservers ) ) ) . "\n";
	echo __( 'Change the name servers at your registrar to these. If your registrar shows a DS record (DNSSEC), remove it first and wait a day.', 'beltoft-services' ) . "\n\n";
}

echo __( 'View your services in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

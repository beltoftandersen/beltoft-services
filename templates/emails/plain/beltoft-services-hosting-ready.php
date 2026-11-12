<?php
/**
 * Customer email (plain text): the hosting account is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-hosting-ready.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var string              $account_url        The service in My Account.
 * @var array<string,mixed> $hosting            site_name, site_url, admin_url, admin_user, admin_email, sftp_host, sftp_port, sftp_user, ssh, domains, dns_records, has_email.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$beltoft_services_h = is_array( $hosting ?? null ) ? $hosting : array();

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_strip_all_tags( $email_heading );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	echo wp_strip_all_tags( sprintf( __( 'Hi %s,', 'beltoft-services' ), $customer_name ) ) . "\n\n";
} else {
	echo __( 'Hi,', 'beltoft-services' ) . "\n\n";
}

echo __( 'Your hosting is set up and your website is online.', 'beltoft-services' ) . "\n\n";

if ( ! empty( $beltoft_services_h['site_url'] ) ) {
	echo __( 'Your website:', 'beltoft-services' ) . ' ' . esc_url_raw( $beltoft_services_h['site_url'] ) . "\n";
	echo __( 'WordPress admin:', 'beltoft-services' ) . ' ' . esc_url_raw( $beltoft_services_h['admin_url'] ) . "\n";

	if ( ! empty( $beltoft_services_h['sftp_host'] ) ) {
		/* translators: 1: host, 2: port, 3: user name. */
		echo __( 'SFTP:', 'beltoft-services' ) . ' ' . wp_strip_all_tags( sprintf( __( 'Host %1$s, port %2$d, user %3$s', 'beltoft-services' ), $beltoft_services_h['sftp_host'], (int) $beltoft_services_h['sftp_port'], $beltoft_services_h['sftp_user'] ) ) . "\n";
	}

	echo "\n";
}

if ( ! empty( $beltoft_services_h['admin_user'] ) ) {
	/* translators: %s: WordPress user name. */
	echo wp_strip_all_tags( sprintf( __( 'In My Account, "Log in to WordPress" signs you in as %s without a password.', 'beltoft-services' ), $beltoft_services_h['admin_user'] ) ) . ' ';
} else {
	echo __( 'In My Account, "Log in to WordPress" signs you in without a password.', 'beltoft-services' ) . ' ';
}

echo __( 'You can also set a WordPress password there. A password is never sent by email.', 'beltoft-services' ) . "\n\n";

if ( ! empty( $beltoft_services_h['dns_records'] ) ) {
	echo __( 'Connect your domain', 'beltoft-services' ) . "\n\n";
	echo __( 'Add these DNS records where the DNS of your domain is hosted. Your website moves to your domain once they are in place, and we check for them regularly.', 'beltoft-services' ) . "\n\n";

	foreach ( $beltoft_services_h['dns_records'] as $beltoft_services_row ) {
		echo wp_strip_all_tags( $beltoft_services_row['type'] . ' ' . $beltoft_services_row['name'] . ' ' . $beltoft_services_row['value'] ) . "\n";
	}

	echo "\n";
}

echo __( 'Open your hosting in My Account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( empty( $beltoft_services_h['has_email'] ) ) {
	echo __( 'Set up Email lite in My Account so your websites can send mail. Email is included with your plan; without it a website cannot send mail.', 'beltoft-services' ) . "\n\n";
}

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

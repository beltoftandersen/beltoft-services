<?php
/** Plain customer email: the app is live on the customer's own domain. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
/* translators: 1: app name, 2: domain name. */
echo wp_strip_all_tags( sprintf( __( 'Your domain %2$s points at your server and %1$s is installed on it. Open https://%2$s to use it.', 'beltoft-services' ), '' !== $app_name ? $app_name : __( 'your app', 'beltoft-services' ), $app_domain ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'The HTTPS certificate is requested automatically. If your browser shows a certificate warning, wait a few minutes and reload the page.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'View the app in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.

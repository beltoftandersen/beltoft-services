<?php
/** Plain customer email: the app is live on the customer's own domain. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
/* translators: 1: app name, 2: domain name. */
echo wp_strip_all_tags( sprintf( __( 'Your domain %2$s points at your server and %1$s is installed on it. Open https://%2$s to use it.', 'beltoft-services' ), '' !== $app_name ? $app_name : __( 'your app', 'beltoft-services' ), $app_domain ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'The HTTPS certificate is requested automatically. If your browser shows a certificate warning, wait a few minutes and reload the page.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
if ( '' !== $app_www ) {
	if ( 'live' === $app_www_state ) {
		/* translators: 1: www name, 2: domain name. */
		echo wp_strip_all_tags( sprintf( __( '%1$s is served too and redirects to %2$s.', 'beltoft-services' ), $app_www, $app_domain ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	} else {
		/* translators: 1: www name, 2: server IPv4 address. */
		echo wp_strip_all_tags( sprintf( __( '%1$s: waiting for the A record. To use it too, point an A record for it to %2$s. We add it when it points to your server, within about ten minutes.', 'beltoft-services' ), $app_www, '' !== $app_ip ? $app_ip : __( 'your server address', 'beltoft-services' ) ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	}
}
echo __( 'View the app in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own filter; plain text email.

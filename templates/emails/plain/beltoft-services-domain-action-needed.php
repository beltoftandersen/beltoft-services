<?php
/** Plain pending-domain email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
/* translators: %s: the customer's domain name. */
echo wp_strip_all_tags( sprintf( __( 'Your domain %s is waiting for a confirmation.', 'beltoft-services' ), $domain_name ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo ( 'registrant_check' === $domain_action_reason ? __( 'Please complete the identity check sent by the registry.', 'beltoft-services' ) : __( 'Please approve the transfer request sent by the registry or current registrar.', 'beltoft-services' ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo __( 'View your domain in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own filter; plain text email.

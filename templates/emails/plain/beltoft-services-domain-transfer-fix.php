<?php
/**
 * Plain text customer email: a transfer waits for a new transfer code or for the domain to be unlocked.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_who = '' !== $registrar_name ? $registrar_name : __( 'your current registrar', 'beltoft-services' );

echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.

if ( 'auth_refused' === $transfer_fix_reason ) {
	/* translators: %s: the customer's domain name. */
	echo wp_strip_all_tags( sprintf( __( 'The transfer code for %s was not accepted.', 'beltoft-services' ), $domain_name ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	/* translators: %s: the current registrar. */
	echo wp_strip_all_tags( sprintf( __( 'Ask %s for a new transfer code (also called auth code or EPP code). You find it in the domain settings there. Then open your domain in My Account and enter the new code.', 'beltoft-services' ), $beltoft_who ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	echo __( 'The transfer is sent again as soon as we have a new code. Without one it stops after 14 days.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
} else {
	/* translators: 1: the customer's domain name, 2: the current registrar. */
	echo wp_strip_all_tags( sprintf( __( 'The transfer of %1$s cannot start because the domain is locked at %2$s.', 'beltoft-services' ), $domain_name, $beltoft_who ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	/* translators: %s: the current registrar. */
	echo wp_strip_all_tags( sprintf( __( 'Unlock the domain at %s (the setting is called transfer lock or registrar lock). Then open your domain in My Account and click Check now.', 'beltoft-services' ), $beltoft_who ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	echo __( 'We also try again every 6 hours. If the domain is still locked after 14 days, the transfer stops.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
}

echo __( 'View your domain in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own filter; plain text email.

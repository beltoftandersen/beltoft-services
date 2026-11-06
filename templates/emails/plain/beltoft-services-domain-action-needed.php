<?php
/** Plain pending-domain email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
if ( 'move_dns' === $domain_action_reason ) {
	/* translators: %s: the customer's domain name. */
	echo wp_strip_all_tags( sprintf( __( 'Your transfer of %s starts when the domain uses our name servers.', 'beltoft-services' ), $domain_name ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	echo __( 'We have copied your DNS records, so your website and email keep working. At your current registrar, turn off DNSSEC and set these name servers:', 'beltoft-services' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	foreach ( $our_nameservers as $beltoft_ns ) { echo '- ' . wp_strip_all_tags( $beltoft_ns ) . "\n"; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	echo "\n" . __( 'We check every few minutes and send the transfer once the domain uses them. If that has not happened within 14 days, the transfer is not sent.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
} elseif ( 'transfer_approval' === $domain_action_reason ) {
	$beltoft_who = '' !== $registrar_name ? $registrar_name : __( 'your current registrar', 'beltoft-services' );
	/* translators: 1: the customer's domain name, 2: the current registrar. */
	echo wp_strip_all_tags( sprintf( __( 'Your transfer of %1$s is waiting for %2$s to release the domain.', 'beltoft-services' ), $domain_name, $beltoft_who ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	/* translators: %s: the current registrar. */
	echo wp_strip_all_tags( sprintf( __( 'This takes up to 5 days. To finish sooner, approve the transfer in %s or in the email they sent you. Your website and email keep working.', 'beltoft-services' ), $beltoft_who ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
} else {
	/* translators: %s: the customer's domain name. */
	echo wp_strip_all_tags( sprintf( __( 'Your domain %s is waiting for a confirmation.', 'beltoft-services' ), $domain_name ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
	echo ( 'registrant_check' === $domain_action_reason ? __( 'Please complete the identity check sent by the registry.', 'beltoft-services' ) : __( 'Please approve the transfer request sent by the registry or current registrar.', 'beltoft-services' ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
}
echo __( 'View your domain in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own filter; plain text email.

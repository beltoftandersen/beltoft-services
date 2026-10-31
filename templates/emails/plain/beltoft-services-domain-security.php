<?php
/** Plain domain security notice. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
$beltoft_lines = array(
	/* translators: %s: the customer's domain name. */
	'unlocked'    => __( 'Your domain %s was unlocked, so it can be transferred to another registrar.', 'beltoft-services' ),
	/* translators: %s: the customer's domain name. */
	'code_viewed' => __( 'The transfer code for your domain %s was shown in your account.', 'beltoft-services' ),
	/* translators: %s: the customer's domain name. */
	'ns_changed'  => __( 'The name servers of your domain %s were changed.', 'beltoft-services' ),
	/* translators: %s: the customer's domain name. */
	'relocked'    => __( 'Your domain %s was locked again, because it had been unlocked for several days.', 'beltoft-services' ),
);
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo wp_strip_all_tags( sprintf( $beltoft_lines[ $domain_security_event ] ?? $beltoft_lines['unlocked'], $domain_name ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo ( 'relocked' === $domain_security_event ? __( 'You can unlock it again in your account when you need to transfer it.', 'beltoft-services' ) : __( 'If you did not do this, contact support now and change your account password.', 'beltoft-services' ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo __( 'View your domain in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text email.
echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own filter; plain text email.

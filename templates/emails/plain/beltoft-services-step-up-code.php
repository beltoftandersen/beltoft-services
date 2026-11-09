<?php
/** Plain email with the confirmation code. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
if ( '' !== (string) ( $customer_name ?? '' ) ) {
	/* translators: %s: customer's first name. */
	echo wp_strip_all_tags( sprintf( __( 'Hi %s,', 'beltoft-services' ), $customer_name ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
} else {
	echo __( 'Hi,', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
}
/* translators: %s: what the code is for, for example "making a Full access API token". */
echo wp_strip_all_tags( sprintf( __( 'Your code for %s is:', 'beltoft-services' ), $purpose_text ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo wp_strip_all_tags( $code ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
/* translators: %d: minutes the code works. */
echo wp_strip_all_tags( sprintf( _n( 'It works once and expires in %d minute.', 'It works once and expires in %d minutes.', (int) $minutes, 'beltoft-services' ), (int) $minutes ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
/* translators: %s: name of the site. */
echo wp_strip_all_tags( sprintf( __( 'Nobody from %s will ever ask you for this code. If you did not ask for it, ignore this email.', 'beltoft-services' ), $site_name ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
}
echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own filter; plain text email.

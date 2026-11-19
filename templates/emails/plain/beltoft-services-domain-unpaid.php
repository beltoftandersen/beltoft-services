<?php
/**
 * Customer email (plain text): a domain whose renewal was not paid.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

foreach ( $unpaid_lines as $beltoft_line ) {
	echo wp_strip_all_tags( $beltoft_line ) . "\n\n";
}

if ( '' !== $unpaid_link[1] ) {
	echo wp_strip_all_tags( $unpaid_link[0] ) . ': ' . esc_url_raw( $unpaid_link[1] ) . "\n";
}

echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.

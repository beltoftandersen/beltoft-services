<?php
/**
 * The additional content and the footer of an HTML email.
 *
 * Included by the customer email templates. Expects $additional_content and $email.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

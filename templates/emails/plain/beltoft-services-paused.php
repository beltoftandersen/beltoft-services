<?php
/**
 * Customer email (plain text): the service is paused.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-paused.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $deletion_date      Date of the deletion (deletion warning), or ''.
 * @var string            $reason             Why it was paused, or ''.
 * @var string            $deletes_on         Date the service will be deleted, or '' when none is promised.
 * @var bool              $held               The service is suspended (breach hold).
 * @var bool              $dns_only           DNS hosting only.
 * @var string            $usage              Usage line (limit exceeded), or ''.
 * @var string            $additional_content Additional content from the email's settings.
 * @var \WC_Email         $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_strip_all_tags( $email_heading );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	echo wp_strip_all_tags( sprintf( __( 'Hi %s,', 'beltoft-services' ), $customer_name ) ) . "\n\n";
} else {
	echo __( 'Hi,', 'beltoft-services' ) . "\n\n";
}

/* translators: %s: product name. */
echo wp_strip_all_tags( sprintf( __( 'Your %s has been paused.', 'beltoft-services' ), $product_name ) ) . "\n\n";

if ( '' !== $reason ) {
	/* translators: %s: reason. */
	echo wp_strip_all_tags( sprintf( __( 'Reason: %s', 'beltoft-services' ), $reason ) ) . "\n\n";
}

if ( '' !== $deletes_on ) {
	if ( $held ) {
		/* translators: %s: date. */
		echo wp_strip_all_tags( sprintf( __( 'It will be deleted no earlier than %s.', 'beltoft-services' ), $deletes_on ) ) . "\n\n";
	} else {
		/* translators: %s: date. */
		echo wp_strip_all_tags( sprintf( __( 'It will be deleted no earlier than %s unless your subscription is active again before then.', 'beltoft-services' ), $deletes_on ) ) . "\n\n";
	}
} elseif ( ! $held ) {
	echo __( 'Its data is kept, and it starts again once your subscription is active.', 'beltoft-services' ) . "\n\n";
}

if ( ! empty( $dns_only ) ) {
	echo __( 'Your DNS records are kept but no longer answered.', 'beltoft-services' ) . "\n\n";
}

echo __( 'View your services in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

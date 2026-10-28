<?php
/**
 * Customer email (plain text): the service will be deleted.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-deletion-warning.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $deletion_date      Date of the deletion (deletion warning), or ''.
 * @var string            $usage              Usage line (limit exceeded), or ''.
 * @var int[]             $extra_disks        Sizes in GB of the extra disks deleted with a server, or empty.
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

/* translators: 1: product name, 2: date. */
echo wp_strip_all_tags( sprintf( __( 'Your %1$s has been paused for a while and will be deleted with its data on %2$s. Renew your subscription before then to keep it.', 'beltoft-services' ), $product_name, $deletion_date ) ) . "\n\n";

foreach ( (array) ( $extra_disks ?? array() ) as $beltoft_disk_gb ) {
	/* translators: %d: size of an extra disk in gigabytes. */
	echo wp_strip_all_tags( sprintf( __( 'Extra disks deleted with the server: %d GB', 'beltoft-services' ), (int) $beltoft_disk_gb ) ) . "\n";
}
echo "\n";

echo __( 'View your services in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
// phpcs:enable

<?php
/**
 * Customer email: the service will be deleted.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-deletion-warning.php.
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

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<p>
<?php
if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	printf( esc_html__( 'Hi %s,', 'beltoft-services' ), esc_html( $customer_name ) );
} else {
	esc_html_e( 'Hi,', 'beltoft-services' );
}
?>
</p>
<p>
<?php
/* translators: 1: product name, 2: date. */
echo esc_html( sprintf( __( 'Your %1$s has been paused for a while and will be deleted with its data on %2$s. Renew your subscription before then to keep it.', 'beltoft-services' ), $product_name, $deletion_date ) );
?>
</p>
<?php foreach ( (array) ( $extra_disks ?? array() ) as $beltoft_disk_gb ) : ?>
<p>
<?php
/* translators: %d: size of an extra disk in gigabytes. */
echo esc_html( sprintf( __( 'Extra disks deleted with the server: %d GB', 'beltoft-services' ), (int) $beltoft_disk_gb ) );
?>
</p>
<?php endforeach; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your services in your account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

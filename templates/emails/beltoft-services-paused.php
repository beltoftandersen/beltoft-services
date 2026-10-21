<?php
/**
 * Customer email: the service is paused.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-paused.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $deletion_date      Date of the deletion (deletion warning), or ''.
 * @var string            $reason             Why it was paused, or ''.
 * @var int               $retention_days     Days the data is kept before deletion, or 0.
 * @var string            $usage              Usage line (limit exceeded), or ''.
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
/* translators: %s: product name. */
echo esc_html( sprintf( __( 'Your %s has been paused.', 'beltoft-services' ), $product_name ) );
?>
</p>
<?php if ( '' !== $reason ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: reason. */ __( 'Reason: %s', 'beltoft-services' ), $reason ) ); ?></p>
<?php endif; ?>
<p>
<?php
if ( $retention_days > 0 ) {
	/* translators: %d: number of days. */
	echo esc_html( sprintf( _n( 'Its data is kept for %d day. It starts again once your subscription is active.', 'Its data is kept for %d days. It starts again once your subscription is active.', $retention_days, 'beltoft-services' ), $retention_days ) );
} else {
	esc_html_e( 'Its data is kept, and it starts again once your subscription is active.', 'beltoft-services' );
}
?>
</p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your services in your account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

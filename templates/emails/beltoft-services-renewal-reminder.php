<?php
/**
 * Customer email: a long renewal is coming.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-renewal-reminder.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var string              $product_name       The service.
 * @var array<string,mixed> $renewal            date, plan, price_html, view_url.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_r = is_array( $renewal ?? null ) ? $renewal : array();

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
<p><?php echo esc_html( sprintf( /* translators: 1: plan, 2: date. */ __( 'Your subscription %1$s renews on %2$s.', 'beltoft-services' ), (string) ( $beltoft_services_r['plan'] ?? '' ), (string) ( $beltoft_services_r['date'] ?? '' ) ) ); ?></p>
<p><?php esc_html_e( 'Renewal price:', 'beltoft-services' ); ?> <strong><?php echo wp_kses_post( (string) ( $beltoft_services_r['price_html'] ?? '' ) ); ?></strong></p>
<p><?php esc_html_e( 'You do not need to do anything to keep your service: it renews and is charged on that date.', 'beltoft-services' ); ?></p>
<p><?php esc_html_e( 'If you do not want to renew, you can cancel in My Account: open the subscription and choose Cancel. Your service then runs until the end of the period you have paid for.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( (string) ( $beltoft_services_r['view_url'] ?? '' ) ); ?>"><?php esc_html_e( 'Open your subscription in My Account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

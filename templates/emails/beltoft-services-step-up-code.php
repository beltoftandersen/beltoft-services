<?php
/** HTML email with the confirmation code. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p>
<?php
if ( '' !== (string) ( $customer_name ?? '' ) ) {
	/* translators: %s: customer's first name. */
	printf( esc_html__( 'Hi %s,', 'beltoft-services' ), esc_html( $customer_name ) );
} else {
	esc_html_e( 'Hi,', 'beltoft-services' );
}
?>
</p>
<?php /* translators: %s: what the code is for, for example "making a Full access API token". */ ?>
<p><?php echo esc_html( sprintf( __( 'Your code for %s is:', 'beltoft-services' ), $purpose_text ) ); ?></p>
<p style="font-size: 28px; font-weight: bold; letter-spacing: 4px;"><?php echo esc_html( $code ); ?></p>
<?php /* translators: %d: minutes the code works. */ ?>
<p><?php echo esc_html( sprintf( _n( 'It works once and expires in %d minute.', 'It works once and expires in %d minutes.', (int) $minutes, 'beltoft-services' ), (int) $minutes ) ); ?></p>
<?php /* translators: %s: name of the site. */ ?>
<p><?php echo esc_html( sprintf( __( 'Nobody from %s will ever ask you for this code. If you did not ask for it, ignore this email.', 'beltoft-services' ), $site_name ) ); ?></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

<?php
/** HTML customer notice email. @package BeltoftServices */
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
<?php foreach ( \BeltoftServices\Emails\Notice::lines( (string) $notice_kind, (string) $product_name ) as $beltoft_services_line ) : ?>
<p><?php echo esc_html( $beltoft_services_line ); ?></p>
<?php endforeach; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php echo esc_html( in_array( $notice_kind, array( 'db_access', 'db_world' ), true ) ? __( 'View your database in your account', 'beltoft-services' ) : __( 'View your server in your account', 'beltoft-services' ) ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

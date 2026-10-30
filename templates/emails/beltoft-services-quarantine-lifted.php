<?php
/** HTML customer email: outgoing traffic open again. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p><?php
/* translators: %s: server name. */
echo esc_html( sprintf( __( 'The block on outgoing traffic from your server %s has been lifted. The server can reach the internet again.', 'beltoft-services' ), $product_name ) );
?></p>
<p><?php esc_html_e( 'Please make sure the cause is fixed. If the same traffic shows up again, the block is put back.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your server in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

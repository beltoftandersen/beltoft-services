<?php
/** HTML customer email: the app is live on the customer's own domain. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p><?php
/* translators: 1: app name, 2: domain name. */
echo esc_html( sprintf( __( 'Your domain %2$s points at your server and %1$s is installed on it. Open https://%2$s to use it.', 'beltoft-services' ), '' !== $app_name ? $app_name : __( 'your app', 'beltoft-services' ), $app_domain ) );
?></p>
<p><?php esc_html_e( 'The HTTPS certificate is requested automatically. If your browser shows a certificate warning, wait a few minutes and reload the page.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View the app in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

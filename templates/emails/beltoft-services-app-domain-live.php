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
<?php if ( '' !== $app_www ) : ?>
<p><?php
if ( 'live' === $app_www_state ) {
	/* translators: 1: www name, 2: domain name. */
	echo esc_html( sprintf( __( '%1$s is served too and redirects to %2$s.', 'beltoft-services' ), $app_www, $app_domain ) );
} else {
	/* translators: 1: www name, 2: server IPv4 address. */
	echo esc_html( sprintf( __( '%1$s: waiting for the A record. To use it too, point an A record for it to %2$s. We add it when it points to your server, within about ten minutes.', 'beltoft-services' ), $app_www, '' !== $app_ip ? $app_ip : __( 'your server address', 'beltoft-services' ) ) );
}
?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View the app in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

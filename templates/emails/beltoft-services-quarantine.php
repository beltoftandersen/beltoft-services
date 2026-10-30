<?php
/** HTML customer email: outgoing traffic blocked. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p><?php
/* translators: 1: server name, 2: date and time. */
echo esc_html( sprintf( __( 'On %2$s we saw traffic from your server %1$s that looks like scanning other servers or a flood of connections:', 'beltoft-services' ), $product_name, $quarantine_at ) );
?></p>
<ul>
<?php foreach ( \BeltoftServices\Emails\Quarantine::lines( $quarantine_signal, $quarantine_counts ) as $label => $number ) : ?>
	<li><?php echo esc_html( $label . ': ' . number_format_i18n( $number ) ); ?></li>
<?php endforeach; ?>
</ul>
<p><?php esc_html_e( 'The numbers are from one minute. The same level had held for several minutes.', 'beltoft-services' ); ?></p>
<p><?php esc_html_e( 'We have blocked outgoing traffic from your server, except DNS lookups. Your server keeps running, you can still log in and visitors can still reach it.', 'beltoft-services' ); ?></p>
<p><?php esc_html_e( 'Please contact support. When the cause is found and fixed we lift the block.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your server in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

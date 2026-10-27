<?php
/** HTML customer monitoring email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<?php if ( 'disk' === $monitor_reason ) : ?>
	<p><?php
	/* translators: 1: used disk space in GB, 2: total disk space in GB. */
	echo esc_html( sprintf( __( 'Your server disk is nearly full: %1$s of %2$s GB is used.', 'beltoft-services' ), number_format_i18n( $monitor_disk_used / 1073741824, 1 ), number_format_i18n( $monitor_disk_total / 1073741824, 1 ) ) );
	?></p>
	<p><?php esc_html_e( 'Free some disk space or increase the server size. Contact support if you need help.', 'beltoft-services' ); ?></p>
<?php else : ?>
	<p><?php esc_html_e( 'Your server has not been running for about 30 minutes. This alert was sent only if you did not shut it down from your account.', 'beltoft-services' ); ?></p>
	<p><?php esc_html_e( 'Check the server in your account and start it if needed. Contact support if it does not start.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your server in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

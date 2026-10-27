<?php
/** Plain customer monitoring email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
if ( 'disk' === $monitor_reason ) {
	/* translators: 1: used disk space in GB, 2: total disk space in GB. */
	echo wp_strip_all_tags( sprintf( __( 'Your server disk is nearly full: %1$s of %2$s GB is used.', 'beltoft-services' ), number_format_i18n( $monitor_disk_used / 1073741824, 1 ), number_format_i18n( $monitor_disk_total / 1073741824, 1 ) ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'Free some disk space or increase the server size. Contact support if you need help.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
} else {
	echo __( 'Your server has not been running for about 30 minutes. This alert was sent only if you did not shut it down from your account.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'Check the server in your account and start it if needed. Contact support if it does not start.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
}
echo __( 'View your server in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.

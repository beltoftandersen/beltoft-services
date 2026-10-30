<?php
/** Plain customer email: outgoing traffic blocked. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
/* translators: 1: server name, 2: date and time. */
echo wp_strip_all_tags( sprintf( __( 'On %2$s we saw traffic from your server %1$s that looks like scanning other servers or a flood of connections:', 'beltoft-services' ), $product_name, $quarantine_at ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
foreach ( \BeltoftServices\Emails\Quarantine::lines( $quarantine_signal, $quarantine_counts ) as $label => $number ) {
	echo wp_strip_all_tags( '- ' . $label . ': ' . number_format_i18n( $number ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
}
echo "\n" . __( 'The numbers are from one minute. The same level had held for several minutes.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'We have blocked outgoing traffic from your server, except DNS lookups. Your server keeps running, you can still log in and visitors can still reach it.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'Please contact support. When the cause is found and fixed we lift the block.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'View your server in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.

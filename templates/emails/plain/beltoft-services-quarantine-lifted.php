<?php
/** Plain customer email: outgoing traffic open again. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
/* translators: %s: server name. */
echo wp_strip_all_tags( sprintf( __( 'The block on outgoing traffic from your server %s has been lifted. The server can reach the internet again.', 'beltoft-services' ), $product_name ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'Please make sure the cause is fixed. If the same traffic shows up again, the block is put back.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo __( 'View your server in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.

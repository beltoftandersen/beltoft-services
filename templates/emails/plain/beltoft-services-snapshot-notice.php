<?php
/** Plain customer snapshot email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
if ( 'disk_removed' === $snapshot_reason ) {
	echo __( 'An extra disk was removed from your server. The snapshots that included that disk were deleted with it, because a snapshot can only be rolled back with all the disks it covered.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'Snapshots that did not include the disk are unchanged.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
} elseif ( 'slots_reduced' === $snapshot_reason ) {
	echo __( 'Your snapshot allowance went down, so some of your server snapshots will be deleted sooner than planned.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	if ( '' !== $deletion_date ) {
		/* translators: %s: date. */
		echo wp_strip_all_tags( sprintf( __( 'The first of them will be deleted on %s. Delete the ones you no longer need yourself to keep the ones you want.', 'beltoft-services' ), $deletion_date ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	}
} else {
	echo __( 'A snapshot of your server reached the end of its keeping time and was deleted.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
}
echo __( 'View your server in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
echo "\n" . wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", (string) apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own filter; plain text email.

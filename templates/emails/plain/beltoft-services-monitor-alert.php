<?php
/** Plain customer monitoring email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
$beltoft_services_name = '' !== (string) $product_name ? (string) $product_name : __( 'Your server', 'beltoft-services' );
echo wp_strip_all_tags( $email_heading ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
if ( 'db_disk' === $monitor_reason ) {
	$beltoft_services_db = '' !== (string) $product_name ? (string) $product_name : __( 'Your database', 'beltoft-services' );
	/* translators: 1: database name, 2: percent used. */
	echo wp_strip_all_tags( sprintf( __( '%1$s: the data disk is %2$d percent full.', 'beltoft-services' ), $beltoft_services_db, $monitor_disk_percent ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'When the disk is full, the database stops accepting changes.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'Delete data you do not need, or contact support to get a larger disk.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
} elseif ( 'db_down' === $monitor_reason ) {
	$beltoft_services_db = '' !== (string) $product_name ? (string) $product_name : __( 'Your database', 'beltoft-services' );
	/* translators: %s: database name. */
	echo wp_strip_all_tags( sprintf( __( '%s has not answered its health check for about 10 minutes. We have been told.', 'beltoft-services' ), $beltoft_services_db ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'You do not need to do anything. If your applications still cannot connect after some time, contact support.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
} elseif ( 'disk' === $monitor_reason || 'disk_urgent' === $monitor_reason ) {
	$beltoft_services_pct = $monitor_disk_total > 0 ? (int) floor( 100 * $monitor_disk_used / $monitor_disk_total ) : 0;
	/* translators: 1: server name, 2: used disk space in GB, 3: total disk space in GB, 4: percent used. */
	echo wp_strip_all_tags( sprintf( __( '%1$s: %2$s of %3$s GB is used (%4$d percent).', 'beltoft-services' ), $beltoft_services_name, number_format_i18n( $monitor_disk_used / 1073741824, 1 ), number_format_i18n( $monitor_disk_total / 1073741824, 1 ), $beltoft_services_pct ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo wp_strip_all_tags( 'disk_urgent' === $monitor_reason ? __( 'Please act today. When the disk is full, the server cannot save files, log in or start services.', 'beltoft-services' ) : __( 'When the disk is full, the server cannot save files or start services.', 'beltoft-services' ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'What you can do:', 'beltoft-services' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo '- ' . __( 'Free space. The largest folders are usually /var/log, /var/lib and /home.', 'beltoft-services' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	if ( '' !== (string) $monitor_disks_url ) { echo '- ' . __( 'Add a disk to your server:', 'beltoft-services' ) . ' ' . esc_url_raw( $monitor_disks_url ) . "\n"; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	if ( '' !== (string) $monitor_upgrade_url ) { echo '- ' . __( 'Move to a plan with a larger disk:', 'beltoft-services' ) . ' ' . esc_url_raw( $monitor_upgrade_url ) . "\n"; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo "\n";
} elseif ( 'cpu' === $monitor_reason ) {
	/* translators: 1: server name, 2: CPU percent, 3: time of day. */
	echo wp_strip_all_tags( sprintf( __( '%1$s has been at %2$d percent or more of its CPU since %3$s.', 'beltoft-services' ), $beltoft_services_name, $monitor_cpu_percent, $monitor_cpu_since ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'This can be normal for a build or an import. If you do not expect it, a program may be stuck, or the server may be misused.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'What you can do:', 'beltoft-services' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo '- ' . __( 'Check what is running (top or htop).', 'beltoft-services' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo '- ' . __( 'Restart the server from your account.', 'beltoft-services' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	if ( '' !== (string) $monitor_upgrade_url ) { echo '- ' . __( 'Move to a plan with more cores:', 'beltoft-services' ) . ' ' . esc_url_raw( $monitor_upgrade_url ) . "\n"; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo "\n";
} else {
	echo __( 'Your server has not been running for about 30 minutes. This alert was sent only if you did not shut it down from your account.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
	echo __( 'Check the server in your account and start it if needed. Contact support if it does not start.', 'beltoft-services' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
}
echo ( in_array( $monitor_reason, array( 'db_down', 'db_disk' ), true ) ? __( 'View your database in your account:', 'beltoft-services' ) : __( 'View your server in your account:', 'beltoft-services' ) ) . ' ' . esc_url_raw( $account_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
if ( in_array( $monitor_reason, array( 'disk', 'disk_urgent', 'cpu' ), true ) ) {
	echo "\n" . __( 'You can turn these emails off for this server in your account, under Monitoring.', 'beltoft-services' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email.
}

<?php
/** HTML customer monitoring email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p>
<?php
if ( '' !== (string) ( $customer_name ?? '' ) ) {
	/* translators: %s: customer's first name. */
	printf( esc_html__( 'Hi %s,', 'beltoft-services' ), esc_html( $customer_name ) );
} else {
	esc_html_e( 'Hi,', 'beltoft-services' );
}
?>
</p>
<?php
$beltoft_services_name = '' !== (string) $product_name ? (string) $product_name : __( 'Your server', 'beltoft-services' );
$beltoft_services_usage = in_array( $monitor_reason, array( 'disk', 'disk_urgent', 'cpu' ), true );
?>
<?php if ( 'db_disk' === $monitor_reason ) : ?>
	<p><?php
	/* translators: 1: database name, 2: percent used. */
	echo esc_html( sprintf( __( '%1$s: the data disk is %2$d percent full.', 'beltoft-services' ), '' !== (string) $product_name ? (string) $product_name : __( 'Your database', 'beltoft-services' ), $monitor_disk_percent ) );
	?> <?php esc_html_e( 'When the disk is full, the database stops accepting changes.', 'beltoft-services' ); ?></p>
	<p><?php esc_html_e( 'Delete data you do not need, or make the storage larger.', 'beltoft-services' ); ?></p>
	<?php if ( '' !== (string) ( $monitor_grow_url ?? '' ) ) : ?><p><a href="<?php echo esc_url( $monitor_grow_url ); ?>"><?php esc_html_e( 'Make your storage larger', 'beltoft-services' ); ?></a></p><?php endif; ?>
<?php elseif ( 'db_down' === $monitor_reason ) : ?>
	<p><?php
	/* translators: %s: database name. */
	echo esc_html( sprintf( __( '%s has not answered its health check for about 10 minutes. We have been told.', 'beltoft-services' ), '' !== (string) $product_name ? (string) $product_name : __( 'Your database', 'beltoft-services' ) ) );
	?></p>
	<p><?php esc_html_e( 'You do not need to do anything. If your applications still cannot connect after some time, contact support.', 'beltoft-services' ); ?></p>
<?php elseif ( 'disk' === $monitor_reason || 'disk_urgent' === $monitor_reason ) : ?>
	<?php $beltoft_services_pct = $monitor_disk_total > 0 ? (int) floor( 100 * $monitor_disk_used / $monitor_disk_total ) : 0; ?>
	<p><?php
	/* translators: 1: server name, 2: used disk space in GB, 3: total disk space in GB, 4: percent used. */
	echo esc_html( sprintf( __( '%1$s: %2$s of %3$s GB is used (%4$d percent).', 'beltoft-services' ), $beltoft_services_name, number_format_i18n( $monitor_disk_used / 1073741824, 1 ), number_format_i18n( $monitor_disk_total / 1073741824, 1 ), $beltoft_services_pct ) );
	?>
	<?php echo esc_html( 'disk_urgent' === $monitor_reason ? __( 'Please act today. When the disk is full, the server cannot save files, log in or start services.', 'beltoft-services' ) : __( 'When the disk is full, the server cannot save files or start services.', 'beltoft-services' ) ); ?></p>
	<p><?php esc_html_e( 'What you can do:', 'beltoft-services' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Free space. The largest folders are usually /var/log, /var/lib and /home.', 'beltoft-services' ); ?></li>
		<?php if ( '' !== (string) $monitor_disks_url ) : ?><li><a href="<?php echo esc_url( $monitor_disks_url ); ?>"><?php esc_html_e( 'Add a disk to your server', 'beltoft-services' ); ?></a></li><?php endif; ?>
		<?php if ( '' !== (string) $monitor_upgrade_url ) : ?><li><a href="<?php echo esc_url( $monitor_upgrade_url ); ?>"><?php esc_html_e( 'Move to a plan with a larger disk', 'beltoft-services' ); ?></a></li><?php endif; ?>
	</ul>
<?php elseif ( 'cpu' === $monitor_reason ) : ?>
	<p><?php
	/* translators: 1: server name, 2: CPU percent, 3: time of day. */
	echo esc_html( sprintf( __( '%1$s has been at %2$d percent or more of its CPU since %3$s.', 'beltoft-services' ), $beltoft_services_name, $monitor_cpu_percent, $monitor_cpu_since ) );
	?> <?php esc_html_e( 'This can be normal for a build or an import. If you do not expect it, a program may be stuck, or the server may be misused.', 'beltoft-services' ); ?></p>
	<p><?php esc_html_e( 'What you can do:', 'beltoft-services' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'Check what is running (top or htop).', 'beltoft-services' ); ?></li>
		<li><?php esc_html_e( 'Restart the server from your account.', 'beltoft-services' ); ?></li>
		<?php if ( '' !== (string) $monitor_upgrade_url ) : ?><li><a href="<?php echo esc_url( $monitor_upgrade_url ); ?>"><?php esc_html_e( 'Move to a plan with more cores', 'beltoft-services' ); ?></a></li><?php endif; ?>
	</ul>
<?php else : ?>
	<p><?php esc_html_e( 'Your server has not been running for about 30 minutes. This alert was sent only if you did not shut it down from your account.', 'beltoft-services' ); ?></p>
	<p><?php esc_html_e( 'Check the server in your account and start it if needed. Contact support if it does not start.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php echo esc_html( in_array( $monitor_reason, array( 'db_down', 'db_disk' ), true ) ? __( 'View your database in your account', 'beltoft-services' ) : __( 'View your server in your account', 'beltoft-services' ) ); ?></a></p>
<?php if ( $beltoft_services_usage ) : ?>
	<p><?php esc_html_e( 'You can turn these emails off for this server in your account, under Monitoring.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

<?php
/** HTML customer snapshot email. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<?php if ( 'disk_removed' === $snapshot_reason ) : ?>
	<p><?php esc_html_e( 'An extra disk was removed from your server. The snapshots that included that disk were deleted with it, because a snapshot can only be rolled back with all the disks it covered.', 'beltoft-services' ); ?></p>
	<p><?php esc_html_e( 'Snapshots that did not include the disk are unchanged.', 'beltoft-services' ); ?></p>
<?php elseif ( 'slots_reduced' === $snapshot_reason ) : ?>
	<p><?php esc_html_e( 'Your snapshot allowance went down, so some of your server snapshots will be deleted sooner than planned.', 'beltoft-services' ); ?></p>
	<?php if ( '' !== $deletion_date ) : ?>
		<p><?php
		/* translators: %s: date. */
		echo esc_html( sprintf( __( 'The first of them will be deleted on %s. Delete the ones you no longer need yourself to keep the ones you want.', 'beltoft-services' ), $deletion_date ) );
		?></p>
	<?php endif; ?>
<?php else : ?>
	<p><?php esc_html_e( 'A snapshot of your server reached the end of its keeping time and was deleted.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your server in your account', 'beltoft-services' ); ?></a></p>
<?php
if ( $additional_content ) { echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); }
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.

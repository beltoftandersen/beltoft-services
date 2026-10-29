<?php
/** Extra disk instructions in My Account. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-disk">
	<p><?php /* translators: 1: the name of the extra disk, such as Data disk 1. 2: its size in gigabytes. */ echo esc_html( sprintf( __( '%1$s: %2$d GB', 'beltoft-services' ), $name, (int) $size ) ); ?></p>
	<?php if ( ! empty( $paused ) ) : ?>
		<p><?php esc_html_e( 'This disk is detached from your server because its subscription is not active. Your data is kept until the disk is deleted.', 'beltoft-services' ); ?></p>
</div>
		<?php return; ?>
	<?php endif; ?>
	<?php if ( $server ) : ?><p><?php esc_html_e( 'Attached to your server.', 'beltoft-services' ); ?></p><?php endif; ?>
	<?php include BELTOFT_SERVICES_PATH . 'templates/account/disk-guide.php'; ?>
</div>

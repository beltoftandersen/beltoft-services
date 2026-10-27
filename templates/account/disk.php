<?php
/** Extra disk instructions in My Account. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-disk">
	<p><?php /* translators: %d: extra disk size in gigabytes. */ echo esc_html( sprintf( __( 'Extra disk: %d GB', 'beltoft-services' ), (int) $size ) ); ?><?php if ( $slot ) : ?> (<?php echo esc_html( $slot ); ?>)<?php endif; ?></p>
	<?php if ( $server ) : ?><p><?php esc_html_e( 'Attached to your server.', 'beltoft-services' ); ?></p><?php endif; ?>
	<?php if ( $needs_reboot ) : ?><p><?php esc_html_e( 'Restart the server if the disk does not appear yet.', 'beltoft-services' ); ?></p><?php endif; ?>
	<p><?php esc_html_e( 'This disk is empty. In the server, use lsblk -f to identify the new device. Check the device name before formatting it.', 'beltoft-services' ); ?></p>
	<pre><code>lsblk -f
sudo mkfs.ext4 /dev/DEVICE
sudo mkdir -p /mnt/data
sudo mount /dev/DEVICE /mnt/data
sudo blkid /dev/DEVICE</code></pre>
	<p><?php esc_html_e( 'Use the UUID shown by blkid in /etc/fstab to mount the disk after a restart. After making the disk larger, grow the file system with sudo resize2fs /dev/DEVICE.', 'beltoft-services' ); ?></p>
	<p><?php esc_html_e( 'Extra disks are not included in the nightly backups.', 'beltoft-services' ); ?></p>
</div>

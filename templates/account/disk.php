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
	<?php if ( $needs_reboot ) : ?><p><?php esc_html_e( 'Restart the server if the disk does not appear yet.', 'beltoft-services' ); ?></p><?php endif; ?>
	<p><?php esc_html_e( 'This disk is empty. Format it once, inside your server.', 'beltoft-services' ); ?></p>
	<details class="beltoft-services-disk__guide" id="<?php echo esc_attr( $guide_id ); ?>">
		<summary><?php esc_html_e( 'Set up this disk', 'beltoft-services' ); ?></summary>
		<p class="beltoft-services-disk__warning"><strong><?php echo esc_html( $guide['warning'] ); ?></strong></p>
		<p><?php echo esc_html( $guide['intro'] ); ?></p>
		<ol>
			<?php foreach ( $guide['steps'] as $beltoft_services_step ) : ?>
				<li><?php echo esc_html( $beltoft_services_step['text'] ); ?><pre><code><?php echo esc_html( $beltoft_services_step['code'] ); ?></code></pre></li>
			<?php endforeach; ?>
		</ol>
		<?php if ( ! empty( $guide['grow'] ) ) : ?>
			<?php foreach ( $guide['grow'] as $beltoft_services_step ) : ?>
				<p><?php echo esc_html( $beltoft_services_step['text'] ); ?></p>
				<pre><code><?php echo esc_html( $beltoft_services_step['code'] ); ?></code></pre>
			<?php endforeach; ?>
		<?php endif; ?>
	</details>
	<p><?php esc_html_e( 'Extra disks are not included in the nightly backups.', 'beltoft-services' ); ?></p>
</div>

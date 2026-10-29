<?php
/**
 * The setup guide of an extra disk: the restart note, the steps to format it and the backup note.
 * Printed on the disk's card and in the Disks tab of the server page.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $guide        DiskGuide::guide().
 * @var string               $guide_id     Anchor of the guide (DiskGuide::anchor()).
 * @var bool                 $needs_reboot The server has to restart before the disk shows.
 */

defined( 'ABSPATH' ) || exit;
?>
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

<?php
/** Snapshot slots in My Account. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;

/**
 * @var int    $slots      Slots this pack adds.
 * @var int    $days       Days a snapshot is kept with it, 0 for the setting.
 * @var bool   $paused     The pack is paused.
 * @var string $server_url Card of the server.
 */
?>
<div class="beltoft-services-snapshot">
	<p><?php /* translators: %d: snapshot slots added to the server. */ echo esc_html( sprintf( __( 'Snapshot slots: +%d', 'beltoft-services' ), (int) $slots ) ); ?></p>
	<?php if ( ! empty( $paused ) ) : ?>
		<p><?php esc_html_e( 'These slots are off because their subscription is not active. Snapshots beyond your free slots are deleted after a few days.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Added to your server.', 'beltoft-services' ); ?><?php if ( '' !== $server_url ) : ?> <a href="<?php echo esc_url( $server_url ); ?>"><?php esc_html_e( 'Open your server', 'beltoft-services' ); ?></a><?php endif; ?></p>
	<?php endif; ?>
</div>

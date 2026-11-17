<?php
/**
 * The backups: the stored list, Back up now, the restore buttons and Undo. Nothing here calls the node.
 *
 * Variables: $v: HostingView::backups().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-hosting-panel beltoft-hosting-backups" id="beltoft-hosting-backups" data-hosting-scope>
	<header class="beltoft-hosting-panel__head"><h3 class="beltoft-hosting-panel__title"><?php esc_html_e( 'Backups', 'beltoft-services' ); ?></h3></header>
	<?php if ( $v['busy'] ) : ?>
		<p><?php esc_html_e( 'A backup or a restore is running.', 'beltoft-services' ); ?></p>
	<?php endif; ?>

	<?php if ( array() === $v['rows'] ) : ?>
		<p><?php esc_html_e( 'No backups yet. The first one is made tonight.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<table class="beltoft-hosting-backup-list">
			<thead><tr><th><?php esc_html_e( 'Date', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Kind', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Data in backup', 'beltoft-services' ); ?></th><?php echo $v['acts'] ? '<th></th>' : ''; ?></tr></thead>
			<tbody>
				<?php foreach ( $v['rows'] as $beltoft_services_row ) : ?>
					<tr><td><?php echo esc_html( $beltoft_services_row['date'] ); ?></td><td><?php echo esc_html( $beltoft_services_row['kind'] ); ?></td><td><?php echo esc_html( $beltoft_services_row['size'] ); ?></td>
						<?php if ( $v['acts'] ) : ?>
							<td><div class="beltoft-hosting-actions"><button type="button" class="beltoft-hosting-btn bsh-btn--sm" data-hosting-action="restore" data-scope="account" data-volid="<?php echo esc_attr( $beltoft_services_row['volid'] ); ?>"><?php esc_html_e( 'Restore everything', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-btn bsh-btn--sm" data-hosting-action="restore" data-scope="files" data-volid="<?php echo esc_attr( $beltoft_services_row['volid'] ); ?>"><?php esc_html_e( 'Restore files', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-btn bsh-btn--sm" data-hosting-action="restore" data-scope="database" data-volid="<?php echo esc_attr( $beltoft_services_row['volid'] ); ?>"><?php esc_html_e( 'Restore database', 'beltoft-services' ); ?></button><button type="button" class="beltoft-hosting-btn bsh-btn--sm bs-btn--danger" data-hosting-action="backup_delete" data-volid="<?php echo esc_attr( $beltoft_services_row['volid'] ); ?>" data-confirm="<?php echo esc_attr( $beltoft_services_row['confirm'] ); ?>"><?php esc_html_e( 'Delete', 'beltoft-services' ); ?></button></div></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="beltoft-hosting-help"><?php esc_html_e( 'Each backup holds your whole hosting as it was that night. Backups are kept apart from your disk and do not use its space.', 'beltoft-services' ); ?></p>
	<?php endif; ?>

	<?php if ( $v['acts'] ) : ?>
		<?php if ( '' !== $v['one'] ) : ?>
			<?php // One website: nothing to choose, the restore buttons use it. ?>
			<input type="hidden" data-hosting-field="restore_slug" value="<?php echo esc_attr( $v['one'] ); ?>">
		<?php elseif ( array() !== $v['choices'] ) : ?>
			<div class="beltoft-hosting-formrow"><label class="beltoft-hosting-field"><span><?php esc_html_e( 'Website to restore', 'beltoft-services' ); ?></span>
				<select data-hosting-field="restore_slug">
					<?php foreach ( $v['choices'] as $beltoft_services_choice ) : ?>
						<option value="<?php echo esc_attr( $beltoft_services_choice['slug'] ); ?>"><?php echo esc_html( $beltoft_services_choice['name'] ); ?></option>
					<?php endforeach; ?>
				</select></label></div>
		<?php endif; ?>

		<div class="bs-action">
			<p class="bs-action__text"><?php esc_html_e( 'A backup is made every night. You can also make one now.', 'beltoft-services' ); ?></p>
			<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="backup_now"><?php esc_html_e( 'Back up now', 'beltoft-services' ); ?></button></div>
		</div>
		<?php if ( '' !== $v['safety'] ) : ?>
			<div class="bs-action">
				<p class="bs-action__text"><?php echo esc_html( $v['safety'] ); ?></p>
				<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="undo"><?php esc_html_e( 'Undo the last restore', 'beltoft-services' ); ?></button></div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</section>

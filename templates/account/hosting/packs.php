<?php
/**
 * Extra storage per Email lite mailbox: packs of 5 GB, up to four for each, added and ended here.
 *
 * Variables: $v: HostingView::packs() (intro, confirm, cents, rows).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-hosting-packs"><h4><?php esc_html_e( 'Extra mailbox storage', 'beltoft-services' ); ?></h4>
	<?php if ( array() === $v['rows'] ) : ?>
		<p><?php esc_html_e( 'Create a mailbox first. Extra storage is added to a mailbox.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p><?php echo esc_html( $v['intro'] ); ?></p>
		<ul class="beltoft-hosting-pack-list">
			<?php foreach ( $v['rows'] as $beltoft_services_row ) : ?>
				<li><span class="beltoft-hosting-pack-name"><?php echo esc_html( $beltoft_services_row['name'] ); ?></span>
					<?php echo '' !== $beltoft_services_row['ending'] ? ' ' . esc_html( $beltoft_services_row['ending'] ) : ''; ?>
					<?php if ( $beltoft_services_row['add'] ) : ?>
						<button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="add_pack" data-mailbox="<?php echo esc_attr( $beltoft_services_row['email'] ); ?>" data-confirm="<?php echo esc_attr( $v['confirm'] ); ?>" data-cents="<?php echo (int) $v['cents']; ?>"><?php esc_html_e( 'Add 5 GB', 'beltoft-services' ); ?></button>
					<?php endif; ?>
					<?php if ( $beltoft_services_row['remove'] ) : ?>
						<button type="button" class="beltoft-hosting-linkbtn" data-hosting-action="remove_pack" data-mailbox="<?php echo esc_attr( $beltoft_services_row['email'] ); ?>"><?php esc_html_e( 'Remove a pack', 'beltoft-services' ); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

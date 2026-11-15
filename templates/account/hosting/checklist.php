<?php
/**
 * The first-visit list: a count and "Hide this list" in the heading row, then one row for each item with its action at the right edge.
 *
 * Variables: $v: HostingView::checklist().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-hosting-panel beltoft-hosting-checklist" id="beltoft-hosting-checklist">
	<header class="beltoft-hosting-panel__head">
		<h3 class="beltoft-hosting-panel__title"><?php esc_html_e( 'Get started', 'beltoft-services' ); ?></h3>
		<span class="beltoft-hosting-panel__count"><?php echo esc_html( $v['count'] ); ?></span>
		<?php if ( $v['ready'] ) : ?>
			<button type="button" class="beltoft-hosting-linkbtn beltoft-hosting-panel__aside" data-hosting-action="dismiss_checklist"><?php esc_html_e( 'Hide this list', 'beltoft-services' ); ?></button>
		<?php endif; ?>
	</header>
	<ul>
		<?php foreach ( $v['rows'] as $beltoft_services_row ) : ?>
			<li data-done="<?php echo $beltoft_services_row['done'] ? '1' : '0'; ?>"><span class="beltoft-hosting-mark" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( $beltoft_services_row['done'] ? __( 'Done:', 'beltoft-services' ) : __( 'To do:', 'beltoft-services' ) ); ?></span><span class="beltoft-hosting-check-label"><?php echo esc_html( $beltoft_services_row['label'] ); ?></span>
				<?php if ( '' !== $beltoft_services_row['link'] ) : ?>
					<a class="beltoft-hosting-btn bsh-btn--sm beltoft-hosting-check-action" href="<?php echo esc_url( $beltoft_services_row['link'] ); ?>"><?php echo esc_html( $beltoft_services_row['text'] ); ?></a>
				<?php elseif ( '' !== $beltoft_services_row['item'] ) : ?>
					<button type="button" class="beltoft-hosting-btn bsh-btn--sm beltoft-hosting-check-action" data-hosting-action="check_item" data-item="<?php echo esc_attr( $beltoft_services_row['item'] ); ?>"><?php echo esc_html( $beltoft_services_row['text'] ); ?></button>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>

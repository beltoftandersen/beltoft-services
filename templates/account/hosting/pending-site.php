<?php
/**
 * A website that is being added: its row is there at once, with the step the work is at.
 *
 * Variables: $v: HostingView::pending_site() (slug, title, step).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-hosting-panel beltoft-hosting-site beltoft-hosting-site--pending" id="<?php echo esc_attr( 'beltoft-site-' . $v['slug'] ); ?>" data-slug="<?php echo esc_attr( $v['slug'] ); ?>">
	<header class="beltoft-hosting-panel__head"><h3 class="beltoft-hosting-panel__title"><?php echo esc_html( $v['title'] ); ?></h3><span class="beltoft-hosting-status"><?php esc_html_e( 'Being set up', 'beltoft-services' ); ?></span></header>
	<p class="beltoft-hosting-line" role="status" data-task-step><?php echo esc_html( $v['step'] ); ?></p>
</section>

<?php
/**
 * The "Move a website here" panel.
 *
 * Variables: $v: HostingView::move() (url, text).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-hosting-panel beltoft-hosting-quiet" id="beltoft-hosting-move">
	<header class="beltoft-hosting-panel__head"><h3 class="beltoft-hosting-panel__title"><?php esc_html_e( 'Move a website here', 'beltoft-services' ); ?></h3></header>
	<div class="bs-action"><p class="bs-action__text"><?php echo esc_html( $v['text'] ); ?></p><div class="bs-action__do"><a class="beltoft-hosting-btn" href="<?php echo esc_url( $v['url'] ); ?>"><?php esc_html_e( 'Request a move', 'beltoft-services' ); ?></a></div></div>
</section>

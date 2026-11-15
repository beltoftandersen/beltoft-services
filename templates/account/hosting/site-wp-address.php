<?php
/**
 * What the page says about the address WordPress itself uses, and the one button that goes with it.
 *
 * Variables: $v: HostingView::wordpress() (slug, line, button).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

if ( null === $v['button'] ) : ?>
<p class="beltoft-hosting-line" data-wp-address><?php echo esc_html( $v['line'] ); ?></p>
<?php else : ?>
<div class="bs-action" data-wp-address>
	<p class="bs-action__text"><strong class="bsh-action__title"><?php esc_html_e( 'WordPress address', 'beltoft-services' ); ?></strong> <?php echo esc_html( $v['line'] ); ?></p>
	<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="wp_address" data-slug="<?php echo esc_attr( $v['slug'] ); ?>" data-host="<?php echo esc_attr( $v['button']['host'] ); ?>" data-confirm="<?php echo esc_attr( $v['button']['confirm'] ); ?>"><?php echo esc_html( $v['button']['text'] ); ?></button></div>
</div>
<?php endif; ?>

<?php
/**
 * The Cache section of a website: what is on (asked from the node when the page opens), the button, and where the page cache is switched.
 *
 * Variables: $v: HostingView::site().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug = $v['slug'];
?>
<details class="bsh-sec" data-sec="cache" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-cache' ); ?>">
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'Cache', 'beltoft-services' ); ?></span><span class="bsh-sec__value"></span></summary>
	<div class="bsh-sec__body">
		<?php if ( $v['ready'] ) : ?>
			<dl class="bsh-kv" data-cache-facts data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" hidden>
				<div><dt><?php esc_html_e( 'Page cache', 'beltoft-services' ); ?></dt><dd data-cache-page></dd></div>
				<div><dt><?php esc_html_e( 'Object cache', 'beltoft-services' ); ?></dt><dd data-cache-object></dd></div>
			</dl>
			<div class="bs-action">
				<p class="bs-action__text"><?php esc_html_e( 'Clears the page cache and the object cache of this website. Visitors then get fresh pages.', 'beltoft-services' ); ?></p>
				<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="cache" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-busy="<?php esc_attr_e( 'Clearing the cache...', 'beltoft-services' ); ?>" data-done="<?php esc_attr_e( 'The cache of this website was cleared.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Clear cache', 'beltoft-services' ); ?></button></div>
			</div>
		<?php endif; ?>
		<p class="beltoft-hosting-help"><?php esc_html_e( 'Another caching plugin needs the page cache off first. Switch it off in WordPress under Settings, or deactivate its plugin.', 'beltoft-services' ); ?></p>
	</div>
</details>

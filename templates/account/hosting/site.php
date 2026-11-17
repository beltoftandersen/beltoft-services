<?php
/**
 * One website as a panel: its name, main address and state in the heading row, the buttons used most, then the sections.
 *
 * Variables: $v: HostingView::site(). The sections are templates of their own (site-*.php).
 *
 * @package BeltoftServices
 */

use BeltoftServices\Hosting\HostingView;

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug = $v['slug'];
?>
<section class="beltoft-hosting-panel beltoft-hosting-site" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug ); ?>" data-hosting-scope data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>">
	<header class="beltoft-hosting-panel__head">
		<h3 class="beltoft-hosting-panel__title"><?php echo esc_html( $v['name'] ); ?></h3>
		<a class="beltoft-hosting-link beltoft-hosting-panel__main" href="<?php echo esc_url( 'https://' . $v['main'] . '/' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $v['main_show'] ); ?></a>
		<span class="beltoft-hosting-status<?php echo $v['status_ok'] ? ' is-ok' : ''; ?>" data-site-status data-idle-text="<?php echo esc_attr( $v['idle_text'] ); ?>" data-idle-ok="<?php echo $v['ready'] ? '1' : '0'; ?>"><?php echo esc_html( $v['status_text'] ); ?></span>
	</header>

	<?php if ( $v['ready'] ) : ?>
		<div class="bsh-quick">
			<?php if ( $v['has_wp'] ) : ?>
				<button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="login" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-busy="<?php esc_attr_e( 'Opening WordPress in a new tab...', 'beltoft-services' ); ?>" data-done="<?php esc_attr_e( 'WordPress opened in a new tab.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Log in to WordPress', 'beltoft-services' ); ?></button>
			<?php endif; ?>
			<button type="button" class="beltoft-hosting-btn<?php echo $v['has_wp'] ? '' : ' beltoft-hosting-btn--primary'; ?>" data-hosting-action="tool_files" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-done="<?php esc_attr_e( 'The file manager opened in a new tab.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Open file manager', 'beltoft-services' ); ?></button>
			<button type="button" class="beltoft-hosting-btn" data-hosting-action="tool_database" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-done="<?php esc_attr_e( 'The database tool opened in a new tab.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Open database', 'beltoft-services' ); ?></button>
			<?php if ( $v['has_wp'] ) : ?>
				<button type="button" class="beltoft-hosting-btn" data-hosting-action="cache" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-busy="<?php esc_attr_e( 'Clearing the cache...', 'beltoft-services' ); ?>" data-done="<?php esc_attr_e( 'The cache of this website was cleared.', 'beltoft-services' ); ?>"><?php esc_html_e( 'Clear cache', 'beltoft-services' ); ?></button>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="bsh-secs">
		<?php
		HostingView::render( 'site-domains', $v );
		HostingView::render( 'site-php', $v );

		if ( $v['has_wp'] ) {
			HostingView::render( 'site-login', $v );
			HostingView::render( 'site-cache', $v );
		}

		HostingView::render( 'site-tools', $v );
		HostingView::render( 'site-cron', $v['cron'] );

		if ( null !== $v['scan'] ) {
			HostingView::render( 'site-scan', array( 'slug' => $beltoft_services_slug ) + $v['scan'] );
		}

		if ( $v['staging']['show'] ) {
			HostingView::render( 'site-staging', $v['staging'] );
		}

		HostingView::render( 'site-country', $v['country'] );
		?>
	</div>

	<?php if ( $v['ready'] && $v['many'] ) : ?>
		<?php // The same danger block as deleting a server: what happens, the typed name, the red button. ?>
		<?php $beltoft_services_field = 'beltoft-remove-' . $beltoft_services_slug; ?>
		<details class="bs-danger beltoft-hosting-remove" data-hosting-scope data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>">
			<summary class="bs-danger__row"><?php esc_html_e( 'Remove website', 'beltoft-services' ); ?></summary>
			<div class="bs-danger__body">
				<p><?php esc_html_e( "The website's files and database are deleted. This cannot be undone.", 'beltoft-services' ); ?></p>
				<p><label for="<?php echo esc_attr( $beltoft_services_field ); ?>"><?php /* translators: %s: the word to type */ echo esc_html( sprintf( __( 'Type %s to confirm', 'beltoft-services' ), $beltoft_services_slug ) ); ?></label><input type="text" id="<?php echo esc_attr( $beltoft_services_field ); ?>" data-hosting-field="remove_confirm" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="<?php echo esc_attr( $beltoft_services_slug ); ?>"></p>
				<p><button type="button" class="button woocommerce-button bs-btn bs-btn--danger-solid" data-hosting-action="remove_site" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Remove website', 'beltoft-services' ); ?></button></p>
			</div>
		</details>
	<?php endif; ?>
</section>

<?php
/**
 * The Scheduled jobs section of a website: what a job is, the server cron of WordPress (for a website with WordPress), and the list of jobs as a form
 * that is saved as a whole. The last runs are read from the node by the page script (data-cron-box), never printed here.
 *
 * Variables: $v: SiteCron::view().
 *
 * @package BeltoftServices
 */

use BeltoftServices\Hosting\HostingView;

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug = $v['slug'];
?>
<details class="bsh-sec" data-sec="cron" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-cron' ); ?>">
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'Scheduled jobs', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php echo esc_html( $v['value'] ); ?></span></summary>
	<div class="bsh-sec__body" data-cron-box data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>" data-id="<?php echo esc_attr( $v['id'] ); ?>">
		<p class="beltoft-hosting-help" data-cron-unavailable<?php echo $v['unavailable'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Scheduled jobs are not available on your server yet.', 'beltoft-services' ); ?></p>
		<div data-cron-main<?php echo $v['unavailable'] ? ' hidden' : ''; ?>>
			<p class="beltoft-hosting-help"><?php esc_html_e( 'A scheduled job runs by itself at the times you choose. It runs a PHP file of this website, or fetches an address of this website. Times are server time, not your local time.', 'beltoft-services' ); ?></p>

			<?php if ( $v['wp'] && $v['ready'] ) : ?>
				<?php if ( null === $v['server_cron'] ) : ?>
					<div class="bs-action">
						<p class="bs-action__text"><strong class="bsh-action__title"><?php esc_html_e( 'Server cron', 'beltoft-services' ); ?></strong> <?php esc_html_e( 'Server cron runs the due tasks of WordPress every 5 minutes. WordPress\'s own cron runs only when a visitor reaches WordPress, and a page that is answered from the cache does not, so server cron is the better choice for a shop or a busy website with caching. While server cron is on, WordPress\'s own cron is switched off. It counts as one of your scheduled jobs.', 'beltoft-services' ); ?></p>
						<?php if ( $v['can_server'] ) : ?>
							<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="cron_wp_on" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Switch on server cron', 'beltoft-services' ); ?></button></div>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="bs-action">
						<p class="bs-action__text"><strong class="bsh-action__title"><?php esc_html_e( 'Server cron', 'beltoft-services' ); ?></strong> <?php esc_html_e( 'Server cron is on: it is in the list below, where you can change when it runs. WordPress\'s own cron is switched off while it is on.', 'beltoft-services' ); ?></p>
						<div class="bs-action__do"><button type="button" class="beltoft-hosting-btn" data-hosting-action="cron_wp_off" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Switch off server cron', 'beltoft-services' ); ?></button></div>
					</div>
					<?php if ( $v['wp_both'] ) : ?>
						<p class="beltoft-hosting-warning"><?php esc_html_e( 'WordPress\'s own cron could not be switched off on this website, so its tasks can run twice.', 'beltoft-services' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<ul class="bs-list bsh-cron" data-cron-list>
				<?php foreach ( $v['jobs'] as $beltoft_services_job ) : ?>
					<?php HostingView::render( 'site-cron-row', array( 'row' => $beltoft_services_job, 'php_versions' => $v['php_versions'], 'names' => $v['names'] ) ); ?>
				<?php endforeach; ?>
			</ul>
			<p class="beltoft-hosting-line" data-cron-empty<?php echo array() === $v['jobs'] ? '' : ' hidden'; ?>><?php esc_html_e( 'No scheduled jobs.', 'beltoft-services' ); ?></p>

			<?php if ( $v['ready'] ) : ?>
				<template data-cron-template>
					<?php HostingView::render( 'site-cron-row', array( 'row' => $v['blank'], 'php_versions' => $v['php_versions'], 'names' => $v['names'] ) ); ?>
				</template>
				<div class="beltoft-hosting-buttons">
					<?php if ( $v['limit'] > 0 ) : ?>
						<button type="button" class="beltoft-hosting-btn" data-hosting-action="cron_add" data-limit="<?php echo (int) $v['limit']; ?>"><?php esc_html_e( 'Add a job', 'beltoft-services' ); ?></button>
					<?php endif; ?>
					<button type="button" class="beltoft-hosting-btn beltoft-hosting-btn--primary" data-hosting-action="cron_save" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Save jobs', 'beltoft-services' ); ?></button>
				</div>
			<?php endif; ?>
			<p class="beltoft-hosting-help">
				<?php
				echo esc_html(
					$v['limit'] > 0
						/* translators: %d: scheduled jobs a website may have. */
						? sprintf( __( 'Your plan has up to %d scheduled jobs for each website, and a job runs at most every 5 minutes.', 'beltoft-services' ), (int) $v['limit'] )
						: __( 'Your plan has no scheduled jobs.', 'beltoft-services' )
				);
				?>
			</p>
		</div>
	</div>
</details>

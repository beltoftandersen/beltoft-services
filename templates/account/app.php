<?php
/**
 * The App tab of a server page. Printed by BeltoftServices\Proxmox\AppTab::pane().
 *
 * Variables: $view (see AppTab::view()): name, version, installed, kind (waiting, installing, slow, ready,
 * failed), steps (label and status), position and total, step_label, url, secure, domain, domain_wanted,
 * domain_failed, can_retry, can_reveal, getting_started, docs_url, newer, retired, support_url, resource_id.
 * The login is never part of this template: the button asks the server for it once.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_kind = (string) $view['kind'];
$beltoft_services_status_text = array(
	'waiting'    => __( 'Waiting for the server', 'beltoft-services' ),
	'installing' => __( 'Installing', 'beltoft-services' ),
	'slow'       => __( 'Taking longer than usual', 'beltoft-services' ),
	'ready'      => __( 'Ready', 'beltoft-services' ),
	'failed'     => __( 'Not installed', 'beltoft-services' ),
);
?>
<div class="beltoft-services-app-pane" data-app-pane data-app-state="<?php echo esc_attr( $beltoft_services_kind ); ?>">
	<dl class="beltoft-services-facts">
		<div><dt><?php esc_html_e( 'App', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( (string) $view['name'] ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Version', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( (string) $view['version'] ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Status', 'beltoft-services' ); ?></dt><dd data-app-status><?php echo esc_html( $beltoft_services_status_text[ $beltoft_services_kind ] ); ?></dd></div>
		<?php if ( '' !== $view['installed'] ) : ?>
			<div><dt><?php esc_html_e( 'Installed', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( (string) $view['installed'] ); ?></dd></div>
		<?php endif; ?>
		<?php if ( '' !== $view['url'] ) : ?>
			<div class="beltoft-services-facts__wide"><dt><?php esc_html_e( 'Address', 'beltoft-services' ); ?></dt><dd><a href="<?php echo esc_url( (string) $view['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $view['url'] ); ?></a></dd></div>
		<?php endif; ?>
	</dl>

	<?php if ( 'waiting' === $beltoft_services_kind || 'installing' === $beltoft_services_kind ) : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php echo 'waiting' === $beltoft_services_kind ? esc_html__( 'The app installs when the server is ready', 'beltoft-services' ) : esc_html__( 'Installing', 'beltoft-services' ); ?></h4>
			<ol class="beltoft-services-app-steps">
				<?php foreach ( $view['steps'] as $beltoft_services_step ) : ?>
					<li class="beltoft-services-app-steps__item is-<?php echo esc_attr( (string) $beltoft_services_step['status'] ); ?>"<?php echo 'current' === $beltoft_services_step['status'] ? ' aria-current="step"' : ''; ?>><?php echo esc_html( (string) $beltoft_services_step['label'] ); ?></li>
				<?php endforeach; ?>
			</ol>
			<?php if ( 'installing' === $beltoft_services_kind && $view['position'] > 0 ) : ?>
				<p class="description" role="status">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: step number, 2: number of steps, 3: what is being done */
							__( 'Step %1$d of %2$d: %3$s', 'beltoft-services' ),
							(int) $view['position'],
							(int) $view['total'],
							(string) $view['step_label']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'You can leave this page. The login appears here when the app is ready.', 'beltoft-services' ); ?></p>
		</div>
	<?php elseif ( 'slow' === $beltoft_services_kind ) : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Taking longer than usual', 'beltoft-services' ); ?></h4>
			<p><?php esc_html_e( 'The install has not reported back yet. Your server is running. After an hour you can try again, or contact support.', 'beltoft-services' ); ?></p>
			<?php if ( $view['can_retry'] ) : ?>
				<p class="beltoft-services-vm__reveal"><button type="button" class="button woocommerce-button" data-app-retry><?php esc_html_e( 'Try again', 'beltoft-services' ); ?></button></p>
				<p class="beltoft-services-panel__notice" data-app-notice role="status" aria-live="polite"></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( (string) $view['support_url'] ); ?>"><?php esc_html_e( 'Contact support', 'beltoft-services' ); ?></a></p>
		</div>
	<?php elseif ( 'failed' === $beltoft_services_kind ) : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'The app could not be installed', 'beltoft-services' ); ?></h4>
			<p><?php esc_html_e( 'Your server is running and you can log in over SSH. We have been told about the problem. Contact support and we will help you.', 'beltoft-services' ); ?></p>
			<?php if ( $view['can_retry'] ) : ?>
				<p class="beltoft-services-vm__reveal"><button type="button" class="button woocommerce-button" data-app-retry><?php esc_html_e( 'Try again', 'beltoft-services' ); ?></button></p>
				<p class="beltoft-services-panel__notice" data-app-notice role="status" aria-live="polite"></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( (string) $view['support_url'] ); ?>"><?php esc_html_e( 'Contact support', 'beltoft-services' ); ?></a></p>
		</div>
	<?php elseif ( 'ready' === $beltoft_services_kind ) : ?>
		<?php if ( $view['can_reveal'] ) : ?>
			<div class="beltoft-services-vm__more">
				<h4><?php esc_html_e( 'Login', 'beltoft-services' ); ?></h4>
				<p class="beltoft-services-vm__reveal">
					<button type="button" class="button woocommerce-button" data-app-reveal><?php esc_html_e( 'Show login once', 'beltoft-services' ); ?></button>
				</p>
				<dl class="beltoft-services-app-login" data-app-login hidden></dl>
				<p class="beltoft-services-panel__notice" data-app-notice role="status" aria-live="polite"></p>
				<p class="description"><?php esc_html_e( 'The login is shown one time only. Copy it now and keep it safe.', 'beltoft-services' ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( '' !== $view['url'] && ! $view['secure'] ) : ?>
			<p class="description"><?php esc_html_e( 'This address is not encrypted. Add a domain to use HTTPS before you enter real data.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $view['domain_failed'] ) : ?>
		<p class="description" data-app-domain-notice>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: a domain name */
					__( 'The domain %s could not be pointed at this server. Check the domain in your DNS settings.', 'beltoft-services' ),
					(string) $view['domain_wanted']
				)
			);
			echo ' ' . ( 'failed' === $beltoft_services_kind ? '' : esc_html__( 'The app uses the server address instead.', 'beltoft-services' ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $view['getting_started'] && 'failed' !== $beltoft_services_kind ) : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Getting started', 'beltoft-services' ); ?></h4>
			<p><?php echo esc_html( (string) $view['getting_started'] ); ?></p>
			<?php if ( '' !== $view['docs_url'] ) : ?>
				<p><a href="<?php echo esc_url( (string) $view['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'beltoft-services' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $view['newer'] || $view['retired'] ) : ?>
		<p class="description">
			<?php
			if ( '' !== $view['newer'] ) {
				echo esc_html(
					sprintf(
						/* translators: %s: a version such as WordPress 7.2.0 */
						__( 'A newer version is available: %s. Updating the app is up to you; the documentation explains how. You can also reinstall the server with the newer version, which erases everything on it.', 'beltoft-services' ),
						(string) $view['newer']
					)
				);
			}

			if ( $view['retired'] ) {
				echo ' ' . esc_html__( 'This version is no longer offered for new servers.', 'beltoft-services' );
			}
			?>
		</p>
	<?php endif; ?>

	<p class="description"><?php esc_html_e( 'You keep the app up to date yourself. Security updates for the operating system are installed automatically.', 'beltoft-services' ); ?></p>
</div>

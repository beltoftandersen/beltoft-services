<?php
/**
 * The App tab of a server page. Printed by BeltoftServices\Proxmox\AppTab::pane().
 *
 * Variables: $view (see AppTab::view()): name, version, installed, kind (waiting, installing, slow, ready,
 * failed, dns), steps (label and status), position and total, step_label, url, secure, domain, domain_wanted,
 * domain_failed, licence (a paid app: state, tier, tiers to switch to), can_retry, can_reveal, getting_started, docs_url, newer, retired, support_url, resource_id.
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
	'dns'        => __( 'Waiting for your DNS record', 'beltoft-services' ),
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

	<?php if ( ! empty( $view['licence'] ) ) : ?>
		<?php $beltoft_services_licence = (array) $view['licence']; ?>
		<div class="beltoft-services-vm__more" data-app-licence="<?php echo esc_attr( (string) $beltoft_services_licence['state'] ); ?>">
			<h4><?php esc_html_e( 'Licence', 'beltoft-services' ); ?></h4>
			<?php if ( 'none' === $beltoft_services_licence['state'] ) : ?>
				<p><?php esc_html_e( 'This server has no licence for the app. Contact support.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<p><?php /* translators: %s: tier name */ echo esc_html( sprintf( __( 'Tier: %s', 'beltoft-services' ), (string) $beltoft_services_licence['tier'] ) ); ?></p>
				<?php if ( ! empty( $beltoft_services_licence['usage'] ) ) : ?>
					<p data-app-licence-usage><?php /* translators: %s: price such as 0.0226 per hour, max 16.50 per month excluding VAT */ echo esc_html( sprintf( __( 'Billed by the hour with your server: %s.', 'beltoft-services' ), (string) $beltoft_services_licence['usage'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( 'ended' === $beltoft_services_licence['state'] ) : ?>
					<p><?php esc_html_e( 'The licence for this app has ended. Your server keeps running and the app stays installed, but it may stop working without a licence. Nothing is removed from your server.', 'beltoft-services' ); ?></p>
				<?php elseif ( 'paused' === $beltoft_services_licence['state'] ) : ?>
					<p><?php esc_html_e( 'The licence for this app is on hold because its subscription is not active. Your server keeps running.', 'beltoft-services' ); ?></p>
				<?php elseif ( ! empty( $beltoft_services_licence['tiers'] ) ) : ?>
					<p><?php esc_html_e( 'Change tier. The change is billed through your subscription.', 'beltoft-services' ); ?></p>
					<ul class="beltoft-services-add" data-app-tiers-switch>
						<?php foreach ( $beltoft_services_licence['tiers'] as $beltoft_services_other ) : ?>
							<li><a href="<?php echo esc_url( (string) $beltoft_services_other['url'] ); ?>"><?php echo esc_html( (string) $beltoft_services_other['label'] ); ?></a> <span class="description"><?php echo esc_html( (string) $beltoft_services_other['price'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

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
	<?php elseif ( 'dns' === $beltoft_services_kind ) : ?>
		<div class="beltoft-services-vm__more" data-app-own-domain>
			<h4><?php esc_html_e( 'Point your domain at this server', 'beltoft-services' ); ?></h4>
			<?php if ( '' !== $view['own']['ip'] ) : ?>
				<p><?php
				printf(
					/* translators: 1: domain name, 2: server IPv4 address */
					esc_html__( 'Point an A record for %1$s to %2$s.', 'beltoft-services' ),
					'<strong>' . esc_html( (string) $view['own']['name'] ) . '</strong>',
					'<strong>' . esc_html( (string) $view['own']['ip'] ) . '</strong>'
				);
				?></p>
			<?php endif; ?>
			<p data-app-own-status role="status">
				<?php
				echo esc_html(
					match ( (string) $view['own']['reason'] ) {
						'none'        => __( 'The name has no A record yet.', 'beltoft-services' ),
						'other'       => __( 'The name has an A record, but it does not point to this server only. Remove the other addresses.', 'beltoft-services' ),
						'aaaa'        => __( 'The name points to this server, but it also has an AAAA record. Remove the AAAA record, because this server has no IPv6 address.', 'beltoft-services' ),
						'unreachable' => __( 'The name could not be looked up. We will try again.', 'beltoft-services' ),
						default       => __( 'We have not looked yet. We check soon.', 'beltoft-services' ),
					}
				);
				?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of days, 2: date */
						__( 'We check every ten minutes. When the name points to this server, the app is installed with HTTPS, and we email you. If this has not happened by %2$s (%1$d days after the server started), the app is installed on the server address instead.', 'beltoft-services' ),
						(int) $view['own']['days'],
						(string) $view['own']['deadline']
					)
				);
				?>
			</p>
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
				if ( '' !== $view['domain_reason'] ) {
					echo ' ' . esc_html( $view['domain_reason'] );
				}
				echo ' ' . ( 'failed' === $beltoft_services_kind ? '' : esc_html__( 'The app uses the server address instead.', 'beltoft-services' ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $view['own']['name'] && 'waiting' === $view['own']['state'] && 'dns' !== $beltoft_services_kind ) : ?>
		<p class="description" data-app-own-domain-before>
			<?php
			if ( '' !== $view['own']['ip'] ) {
				echo esc_html(
					sprintf(
						/* translators: 1: domain name, 2: server IPv4 address */
						__( 'Point an A record for %1$s to %2$s. We check it once the server is ready, and the app is installed with HTTPS when the name points to the server.', 'beltoft-services' ),
						(string) $view['own']['name'],
						(string) $view['own']['ip']
					)
				);
			} else {
				echo esc_html(
					sprintf(
						/* translators: %s: domain name */
						__( 'When the server has its address you can point an A record for %s to it here. The app is installed with HTTPS when the name points to the server.', 'beltoft-services' ),
						(string) $view['own']['name']
					)
				);
			}
			?>
		</p>
	<?php endif; ?>

	<?php if ( 'timeout' === $view['own']['state'] ) : ?>
		<p class="description" data-app-own-domain-timeout>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: domain name, 2: number of days */
					__( 'The name %1$s did not point to this server within %2$d days, so the app was installed on the server address, without HTTPS. You can reinstall the server and choose the domain again.', 'beltoft-services' ),
					(string) $view['own']['name'],
					(int) $view['own']['days']
				)
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $view['domain_wanted'] && '' === $view['domain'] && ! $view['domain_failed'] ) : ?>
		<p class="description" data-app-domain-waiting>
			<?php
			echo esc_html( sprintf(
				/* translators: %s: domain name the customer selected. */
				__( 'We will point %s at this server when the server is ready.', 'beltoft-services' ),
				$view['domain_wanted']
			) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $view['domain_left'] ) : ?>
		<p class="description" data-app-domain-left>
			<?php
			printf(
				/* translators: %s: old domain name. */
				esc_html__( 'The DNS record for %s was not made by us, so it was left in place.', 'beltoft-services' ),
				esc_html( $view['domain_left'] )
			);
			?>
			<?php if ( '' !== $view['domain_left_url'] ) : ?>
				<a href="<?php echo esc_url( $view['domain_left_url'] ); ?>"><?php esc_html_e( 'You can remove it in DNS records.', 'beltoft-services' ); ?></a>
			<?php endif; ?>
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

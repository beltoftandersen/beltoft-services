<?php
/**
 * The App tab of a server page. Printed by BeltoftServices\Apps\AppTab::pane().
 *
 * Variables: $view (see AppTab::view()): name, version, installed, kind (waiting, installing, slow, ready,
 * failed, dns), steps (label and status), position and total, step_label, url, secure, domain, domain_wanted,
 * domain_failed, domain_support, change (AppTab::change_view), www (name, state, reason, ip of a bare domain's www name), licence (a paid app: state, tier, tiers to switch to), can_retry, can_reveal, getting_started, docs_url, newer, retired, support_url, resource_id.
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
				<?php if ( ! empty( $beltoft_services_licence['price'] ) ) : ?>
					<p data-app-licence-price><?php /* translators: %s: price such as 14.29 / month excl. VAT */ echo esc_html( sprintf( __( 'Licence price: %s.', 'beltoft-services' ), (string) $beltoft_services_licence['price'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $beltoft_services_licence['next'] ) ) : ?>
					<p data-app-licence-next><?php /* translators: %s: date */ echo esc_html( sprintf( __( 'Next payment: %s. A whole month is charged in advance.', 'beltoft-services' ), (string) $beltoft_services_licence['next'] ) ); ?></p>
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
		<?php echo \BeltoftServices\Apps\AppProgress::dns_box( \BeltoftServices\Resource::find( (int) $view['resource_id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>
	<?php elseif ( 'slow' === $beltoft_services_kind ) : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Taking longer than usual', 'beltoft-services' ); ?></h4>
			<p><?php esc_html_e( 'The install has not reported back yet. Your server is running. After an hour you can try again, or contact support.', 'beltoft-services' ); ?></p>
			<?php if ( $view['can_retry'] ) : ?>
				<p class="beltoft-services-vm__reveal"><button type="button" class="button woocommerce-button" data-app-retry><?php esc_html_e( 'Try again', 'beltoft-services' ); ?></button></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( (string) $view['support_url'] ); ?>"><?php esc_html_e( 'Contact support', 'beltoft-services' ); ?></a></p>
		</div>
	<?php elseif ( 'failed' === $beltoft_services_kind ) : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'The app could not be installed', 'beltoft-services' ); ?></h4>
			<p><?php esc_html_e( 'Your server is running and you can log in over SSH. We have been told about the problem. Contact support and we will help you.', 'beltoft-services' ); ?></p>
			<?php if ( $view['can_retry'] ) : ?>
				<p class="beltoft-services-vm__reveal"><button type="button" class="button woocommerce-button" data-app-retry><?php esc_html_e( 'Try again', 'beltoft-services' ); ?></button></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( (string) $view['support_url'] ); ?>"><?php esc_html_e( 'Contact support', 'beltoft-services' ); ?></a></p>
		</div>
	<?php elseif ( 'ready' === $beltoft_services_kind ) : ?>
		<?php if ( $view['domain_support'] ) : ?>
			<?php
			$beltoft_services_chg   = (array) $view['change'];
			$beltoft_services_cst   = (string) $beltoft_services_chg['state'];
			$beltoft_services_cur   = (string) $beltoft_services_chg['current'];
			$beltoft_services_new   = (string) $beltoft_services_chg['name'];
			$beltoft_services_ip    = (string) $beltoft_services_chg['ip'];
			$beltoft_services_zones = array_values( (array) $beltoft_services_chg['zones'] );
			$beltoft_services_id    = 'beltoft-app-chg-' . (int) $view['resource_id'];
			$beltoft_services_open  = $beltoft_services_chg['supported'] && ( '' === $beltoft_services_cst || 'failed' === $beltoft_services_cst );
			// A name wraps only after a dot, never inside a label (a hyphen is no break either): each label is one unbreakable piece.
			$beltoft_services_wrap  = static function ( string $name ): string {
				$labels = array_map(
					static fn( string $label ): string => '<span class="beltoft-services-app-domain__label">' . esc_html( $label ) . '</span>',
					explode( '.', $name )
				);
				return implode( '.<wbr>', $labels );
			};
			$beltoft_services_kses  = array( 'wbr' => array(), 'span' => array( 'class' => true ) );
			?>
			<div class="beltoft-services-vm__more beltoft-services-app-domain" data-app-domain-block data-app-domain-state="<?php echo esc_attr( '' === $beltoft_services_cst ? 'idle' : $beltoft_services_cst ); ?>">
				<h4><?php esc_html_e( 'Domain', 'beltoft-services' ); ?></h4>
				<div class="beltoft-services-app-domain__row">
					<p class="beltoft-services-app-domain__value" data-app-domain-current><?php echo '' !== $beltoft_services_cur ? $beltoft_services_wrap( $beltoft_services_cur ) : esc_html__( 'The app uses the server address.', 'beltoft-services' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></p>
					<?php if ( 'applying' === $beltoft_services_cst ) : ?>
						<p class="beltoft-services-app-domain__state is-busy" role="status" data-app-domain-status><?php
						/* translators: %s: a domain name */
						echo wp_kses( sprintf( esc_html__( 'Changing the address to %s', 'beltoft-services' ), $beltoft_services_wrap( $beltoft_services_new ) ), $beltoft_services_kses );
						?></p>
						<?php if ( 'slow' === (string) $beltoft_services_chg['reason'] ) : ?>
							<p class="beltoft-services-app-domain__hint description" data-app-domain-slow><?php esc_html_e( 'The change is taking longer than expected. We have been told and are looking at it.', 'beltoft-services' ); ?></p>
						<?php else : ?>
							<p class="beltoft-services-app-domain__hint description"><?php esc_html_e( 'The app keeps answering on the current address until the new one works. This takes a few minutes.', 'beltoft-services' ); ?></p>
						<?php endif; ?>
					<?php elseif ( 'waiting' === $beltoft_services_cst ) : ?>
						<p class="beltoft-services-app-domain__state is-busy" role="status" data-app-domain-status><?php esc_html_e( 'Waiting for your DNS record', 'beltoft-services' ); ?></p>
						<p class="beltoft-services-app-domain__hint description" data-app-domain-record>
							<?php
							if ( '' !== $beltoft_services_ip && '' !== (string) $beltoft_services_chg['www'] ) {
								printf(
									/* translators: 1: domain name, 2: www name, 3: server IPv4 address */
									esc_html__( 'Point A records for %1$s and %2$s to %3$s.', 'beltoft-services' ),
									'<strong>' . $beltoft_services_wrap( $beltoft_services_new ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure.
									'<strong>' . $beltoft_services_wrap( (string) $beltoft_services_chg['www'] ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure.
									'<strong>' . esc_html( $beltoft_services_ip ) . '</strong>'
								);
							} elseif ( '' !== $beltoft_services_ip ) {
								printf(
									/* translators: 1: domain name, 2: server IPv4 address */
									esc_html__( 'Point an A record for %1$s to %2$s.', 'beltoft-services' ),
									'<strong>' . $beltoft_services_wrap( $beltoft_services_new ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure.
									'<strong>' . esc_html( $beltoft_services_ip ) . '</strong>'
								);
							}
							echo ' ' . esc_html(
								match ( (string) $beltoft_services_chg['reason'] ) {
									'none'        => __( 'The name has no A record yet.', 'beltoft-services' ),
									'other'       => __( 'The name has an A record, but it does not point to this server only. Remove the other addresses.', 'beltoft-services' ),
									'aaaa'        => __( 'The name points to this server, but it also has an AAAA record. Remove the AAAA record, because this server has no IPv6 address.', 'beltoft-services' ),
									'unreachable' => __( 'The name could not be looked up. We will try again.', 'beltoft-services' ),
									default       => __( 'We have not looked yet. We check soon.', 'beltoft-services' ),
								}
							);
							?>
						</p>
						<p class="beltoft-services-app-domain__hint description" data-app-domain-until>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: a date */
									__( 'We check every ten minutes until %s. When the name points to this server, the app switches to it. Until then nothing changes and the app keeps answering on the current address.', 'beltoft-services' ),
									(string) $beltoft_services_chg['deadline']
								)
							);
							?>
						</p>
					<?php elseif ( 'failed' === $beltoft_services_cst ) : ?>
						<p class="beltoft-services-app-domain__state is-bad" role="status" data-app-domain-status><?php
						/* translators: %s: a domain name */
						echo wp_kses( sprintf( esc_html__( 'The address was not changed to %s', 'beltoft-services' ), $beltoft_services_wrap( $beltoft_services_new ) ), $beltoft_services_kses );
						?></p>
						<p class="beltoft-services-app-domain__hint description" data-app-domain-failure>
							<?php
							echo esc_html(
								match ( (string) $beltoft_services_chg['reason'] ) {
									// A name in one of our zones got its A record from us: only an own domain can have a wrong record or a CAA record.
									'certificate' => 'change' === (string) $beltoft_services_chg['mode']
										? __( 'A certificate could not be issued for the name. Try again in a few minutes. If it fails again, contact support.', 'beltoft-services' )
										: __( 'A certificate could not be issued for the name. Check that its A record points to this server and that no CAA record blocks it.', 'beltoft-services' ),
									'timeout'     => __( 'The name did not point to this server in time.', 'beltoft-services' ),
									'agent'       => __( 'The server did not answer. Check that it is running.', 'beltoft-services' ),
									'late'        => __( 'The change did not finish in time, so everything was put back.', 'beltoft-services' ),
									'rollback'    => __( 'The change failed and could not be put back. We are looking at it.', 'beltoft-services' ),
									'busy'        => __( 'Another change was still running. Try again in a few minutes.', 'beltoft-services' ),
									'caddy', 'app', 'probe' => __( 'The app did not answer on the new address, so everything was put back.', 'beltoft-services' ),
									default       => __( 'The change could not be finished.', 'beltoft-services' ),
								}
							);
							echo 'rollback' === (string) $beltoft_services_chg['reason'] ? '' : ' ' . ( 'certificate' === (string) $beltoft_services_chg['reason'] && 'change' === (string) $beltoft_services_chg['mode']
								? esc_html__( 'The app still answers on the current address.', 'beltoft-services' )
								: esc_html__( 'The app still answers on the current address. You can try again. If it fails again, contact support.', 'beltoft-services' ) );
							?>
						</p>
					<?php elseif ( '' !== (string) $beltoft_services_chg['changed'] ) : ?>
						<p class="beltoft-services-app-domain__hint description" data-app-domain-done>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: a date */
									__( 'The address was changed on %s. Links saved elsewhere or inside the app, such as posts, webhook addresses or emails already sent, still name the old address. Update them yourself.', 'beltoft-services' ),
									(string) $beltoft_services_chg['changed']
								)
							);
							?>
						</p>
					<?php elseif ( '' !== (string) $beltoft_services_chg['why'] ) : ?>
						<p class="beltoft-services-app-domain__hint description" data-app-domain-why><?php echo esc_html( (string) $beltoft_services_chg['why'] ); ?></p>
					<?php endif; ?>
					<?php if ( 'waiting' === $beltoft_services_cst ) : ?>
						<form class="beltoft-services-vm-form beltoft-services-app-domain__cancel" data-action="app_domain_cancel" data-app-domain-cancel>
							<p><button type="submit" class="button woocommerce-button bs-btn bs-btn--sm bs-btn--secondary"><?php esc_html_e( 'Cancel the change', 'beltoft-services' ); ?></button></p>
						</form>
					<?php endif; ?>
					<?php if ( $beltoft_services_open ) : ?>
						<p class="beltoft-services-app-domain__action"><button type="button" class="button woocommerce-button bs-btn bs-btn--sm bs-btn--secondary" data-app-domain-open aria-controls="<?php echo esc_attr( $beltoft_services_id ); ?>" aria-expanded="false"><?php echo '' !== $beltoft_services_cur ? esc_html__( 'Change domain', 'beltoft-services' ) : esc_html__( 'Add a domain', 'beltoft-services' ); ?></button></p>
					<?php endif; ?>
				</div>
				<?php if ( $beltoft_services_open ) : ?>
					<form class="beltoft-services-vm-form beltoft-services-app-domain__form" id="<?php echo esc_attr( $beltoft_services_id ); ?>" data-action="app_domain" data-app-domain-form hidden>
						<fieldset class="beltoft-services-app-domain__choices">
							<legend><?php echo '' !== $beltoft_services_cur ? esc_html__( 'Change domain', 'beltoft-services' ) : esc_html__( 'Add a domain', 'beltoft-services' ); ?></legend>
							<?php if ( $beltoft_services_zones ) : ?>
								<label class="beltoft-services-app-domain__choice"><input type="radio" name="app_domain_mode" value="change" checked> <?php esc_html_e( 'Use another domain', 'beltoft-services' ); ?>
									<span class="description"><?php esc_html_e( 'Adds an A record to one of your domains on our name servers, and the site is served with HTTPS. Your other records are not changed. On the domain itself, www is added too when it has no record yet, and redirects to the domain.', 'beltoft-services' ); ?></span></label>
								<div class="beltoft-services-app-domain__fields" data-app-domain-fields>
									<label for="<?php echo esc_attr( $beltoft_services_id . '-zone' ); ?>"><?php esc_html_e( 'Domain', 'beltoft-services' ); ?></label>
									<select id="<?php echo esc_attr( $beltoft_services_id . '-zone' ); ?>" name="app_zone">
										<?php foreach ( $beltoft_services_zones as $beltoft_services_zone ) : ?>
											<option value="<?php echo esc_attr( (string) $beltoft_services_zone ); ?>"><?php echo esc_html( (string) $beltoft_services_zone ); ?></option>
										<?php endforeach; ?>
									</select>
									<label for="<?php echo esc_attr( $beltoft_services_id . '-sub' ); ?>"><?php esc_html_e( 'Subdomain', 'beltoft-services' ); ?></label>
									<input type="text" id="<?php echo esc_attr( $beltoft_services_id . '-sub' ); ?>" name="app_sub" maxlength="120" placeholder="www" autocomplete="off">
									<span class="description"><?php esc_html_e( 'Leave empty to use the domain itself; www is then added too when it has no record yet.', 'beltoft-services' ); ?></span>
								</div>
							<?php endif; ?>
							<label class="beltoft-services-app-domain__choice"><input type="radio" name="app_domain_mode" value="own"<?php echo $beltoft_services_zones ? '' : ' checked'; ?>> <?php esc_html_e( 'Use my own domain', 'beltoft-services' ); ?>
								<span class="description"><?php esc_html_e( 'For a domain at another DNS host. We change no DNS records. You add an A record that points the name to this server, and we switch when it does.', 'beltoft-services' ); ?></span></label>
							<div class="beltoft-services-app-domain__fields" data-app-domain-own-fields<?php echo $beltoft_services_zones ? ' hidden' : ''; ?>>
								<label for="<?php echo esc_attr( $beltoft_services_id . '-own' ); ?>"><?php esc_html_e( 'Your domain name', 'beltoft-services' ); ?></label>
								<input type="text" id="<?php echo esc_attr( $beltoft_services_id . '-own' ); ?>" name="app_own" maxlength="253" placeholder="<?php echo esc_attr( /* translators: example shop address shown in an empty field. */ __( 'shop.example.com', 'beltoft-services' ) ); ?>" autocomplete="off" spellcheck="false">
							</div>
						</fieldset>
						<div hidden data-confirm-source data-confirm-title="<?php echo '' !== $beltoft_services_cur ? esc_attr__( 'Change domain', 'beltoft-services' ) : esc_attr__( 'Add a domain', 'beltoft-services' ); ?>" data-confirm-yes="<?php echo '' !== $beltoft_services_cur ? esc_attr__( 'Change domain', 'beltoft-services' ) : esc_attr__( 'Add a domain', 'beltoft-services' ); ?>">
							<p data-for="change"><?php esc_html_e( 'The app keeps answering on the current address until the new one works. Then the app switches to the new address. This can take up to a minute, and the current address stops working.', 'beltoft-services' ); ?></p>
							<p data-for="own"><?php esc_html_e( 'The app keeps answering on the current address until the new one works. Then the app switches to the new address, and the current address stops working.', 'beltoft-services' ); ?></p>
							<p data-for="change"><?php esc_html_e( 'We add an A record for the new name to your domain on our name servers. Your other records are not changed.', 'beltoft-services' ); ?></p>
							<p data-for="own">
								<?php
								echo esc_html(
									'' !== $beltoft_services_ip
										? sprintf(
											/* translators: 1: server IPv4 address, 2: number of days */
											_n( 'We change no DNS records. Add an A record that points the name to %1$s. We check every ten minutes for up to %2$d day, and switch when the name points to this server. Until then nothing changes.', 'We change no DNS records. Add an A record that points the name to %1$s. We check every ten minutes for up to %2$d days, and switch when the name points to this server. Until then nothing changes.', (int) $beltoft_services_chg['days'], 'beltoft-services' ),
											$beltoft_services_ip,
											(int) $beltoft_services_chg['days']
										)
										: sprintf(
											/* translators: %d: number of days */
											_n( 'We change no DNS records. Add an A record that points the name to this server. We check every ten minutes for up to %d day, and switch when the name points to this server. Until then nothing changes.', 'We change no DNS records. Add an A record that points the name to this server. We check every ten minutes for up to %d days, and switch when the name points to this server. Until then nothing changes.', (int) $beltoft_services_chg['days'], 'beltoft-services' ),
											(int) $beltoft_services_chg['days']
										)
								);
								?>
							</p>
							<p><?php esc_html_e( 'Links saved elsewhere or inside the app, such as posts, webhook addresses or emails already sent, still name the old address. We do not change them. Update them yourself.', 'beltoft-services' ); ?></p>
						</div>
						<p class="beltoft-services-app-domain__buttons">
							<button type="submit" class="button woocommerce-button bs-btn bs-btn--sm"><?php esc_html_e( 'Continue', 'beltoft-services' ); ?></button>
							<button type="button" class="button woocommerce-button bs-btn bs-btn--sm bs-btn--secondary" data-app-domain-close><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
						</p>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( $view['can_reveal'] ) : ?>
			<div class="beltoft-services-vm__more">
				<h4><?php esc_html_e( 'Login', 'beltoft-services' ); ?></h4>
				<p class="beltoft-services-vm__reveal" id="beltoft-app-login">
					<button type="button" class="button woocommerce-button" data-app-reveal><?php esc_html_e( 'Show login once', 'beltoft-services' ); ?></button>
				</p>
				<dl class="beltoft-services-app-login" data-app-login hidden></dl>
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
					(string) \BeltoftServices\Domains\HostName::display( (string) $view['domain_wanted'] )
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
			if ( '' !== $view['own']['ip'] && '' !== $view['www']['name'] ) {
				echo esc_html(
					sprintf(
						/* translators: 1: domain name, 2: www name, 3: server IPv4 address */
						__( 'Point A records for %1$s and %2$s to %3$s. We check them once the server is ready, and the app is installed with HTTPS when the first name points to the server. The www name is added when its record is there too.', 'beltoft-services' ),
						(string) $view['own']['name'],
						(string) $view['www']['name'],
						(string) $view['own']['ip']
					)
				);
			} elseif ( '' !== $view['own']['ip'] ) {
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

	<?php if ( 'ready' === $beltoft_services_kind && '' !== $view['www']['name'] && in_array( $view['www']['state'], array( 'waiting', 'applying', 'live' ), true ) ) : ?>
		<p class="description" data-app-www data-app-www-state="<?php echo esc_attr( (string) $view['www']['state'] ); ?>">
			<?php
			if ( 'live' === $view['www']['state'] ) {
				echo esc_html( sprintf(
					/* translators: 1: www name, 2: domain name */
					__( '%1$s is served too and redirects to %2$s.', 'beltoft-services' ),
					(string) \BeltoftServices\Domains\HostName::display( (string) $view['www']['name'] ),
					(string) \BeltoftServices\Domains\HostName::display( (string) $view['domain'] )
				) );
			} elseif ( 'applying' === $view['www']['state'] ) {
				echo esc_html( sprintf(
					/* translators: %s: www name */
					__( '%s: the A record was found, adding it to the server.', 'beltoft-services' ),
					(string) $view['www']['name']
				) );
			} else {
				echo esc_html( sprintf(
					/* translators: %s: www name */
					__( '%s: waiting for the A record', 'beltoft-services' ),
					(string) $view['www']['name']
				) );
				if ( '' !== $view['www']['ip'] ) {
					echo ' ' . esc_html( sprintf(
						/* translators: %s: server IPv4 address */
						__( 'Point an A record for it to %s and we add it within about ten minutes.', 'beltoft-services' ),
						(string) $view['www']['ip']
					) );
				}
				echo ' ' . esc_html( match ( (string) $view['www']['reason'] ) {
					'other'  => __( 'It has an A record, but it does not point to this server only.', 'beltoft-services' ),
					'aaaa'   => __( 'It also has an AAAA record, which this server cannot answer. Remove the AAAA record.', 'beltoft-services' ),
					default  => '',
				} );
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
					_n( 'The name %1$s did not point to this server within %2$d day, so the app was installed on the server address, without HTTPS. You can reinstall the server and choose the domain again.', 'The name %1$s did not point to this server within %2$d days, so the app was installed on the server address, without HTTPS. You can reinstall the server and choose the domain again.', (int) $view['own']['days'], 'beltoft-services' ),
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
				\BeltoftServices\Domains\HostName::display( (string) $view['domain_wanted'] )
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

<?php
/**
 * The page of an email domain. Printed by BeltoftServices\Mail\MailCard::render().
 *
 * Variables: $view (see MailView::data()), $nav (the page's tabs, slug => title), $active (the tab shown), $link (a
 * callable that gives a tab's address). No password is part of this template: a button asks the server for it once and
 * the script shows it.
 *
 * Override by copying to yourtheme/beltoft-services/account/mail.php.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_domain = (string) $view['domain'];
$beltoft_services_shown  = \BeltoftServices\Domains\HostName::display( $beltoft_services_domain );
$beltoft_services_limits = $view['limits'];
$beltoft_services_status = array(
	'ready'    => __( 'Ready', 'beltoft-services' ),
	'building' => __( 'Being set up', 'beltoft-services' ),
	'paused'   => __( 'Paused', 'beltoft-services' ),
	'failed'   => __( 'Not available', 'beltoft-services' ),
	'removed'  => __( 'Removed', 'beltoft-services' ),
);
$beltoft_services_wait   = array(
	'building' => null !== $view['dns']['proof']
		? __( 'We are waiting for the proof that the domain is yours. Add the record on the DNS tab. This page updates by itself.', 'beltoft-services' )
		: __( 'Your email domain is being set up. Mailboxes can be made when it is ready. You can leave this page.', 'beltoft-services' ),
	'paused'   => __( 'Your email service is paused. Mail to your addresses is not delivered until it is active again.', 'beltoft-services' ),
	'failed'   => __( 'Your email domain could not be set up. We have been told about the problem. Please contact support.', 'beltoft-services' ),
	'removed'  => __( 'This email service has been removed.', 'beltoft-services' ),
);
$beltoft_services_size   = static function ( int $mb ): string {
	return $mb >= 1024 ? sprintf( /* translators: %s: size in GB, such as 5 or 2.5 */ __( '%s GB', 'beltoft-services' ), (string) round( $mb / 1024, 1 ) ) : sprintf( /* translators: %d: size in MB */ __( '%d MB', 'beltoft-services' ), $mb );
};
$beltoft_services_all    = ! $nav;
$beltoft_services_row    = static function ( string $label, string $value ): void {
	echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the caller escapes the value.
};
?>
<div class="beltoft-services-db beltoft-services-mail" data-mail-card>
	<?php if ( $nav ) : ?>
		<nav class="beltoft-services-tabs" aria-label="<?php esc_attr_e( 'Email sections', 'beltoft-services' ); ?>">
			<?php foreach ( $nav as $beltoft_services_slug => $beltoft_services_title ) : ?>
				<a href="<?php echo esc_url( $link( $beltoft_services_slug ) ); ?>"<?php echo $beltoft_services_slug === $active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $beltoft_services_title ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $beltoft_services_all || 'overview' === $active ) : ?>
		<dl class="beltoft-services-facts">
			<?php
			$beltoft_services_row( __( 'Status', 'beltoft-services' ), esc_html( $beltoft_services_status[ $view['kind'] ] ?? '' ) );
			if ( '' !== (string) $view['included'] ) {
				$beltoft_services_row( __( 'Plan', 'beltoft-services' ), esc_html( (string) $view['included'] ) );
			}
			$beltoft_services_row( __( 'Mailboxes', 'beltoft-services' ), esc_html( sprintf( /* translators: 1: mailboxes in use in the whole plan, 2: mailboxes the plan allows */ _n( '%1$d of %2$d mailbox in your plan', '%1$d of %2$d mailboxes in your plan', (int) $beltoft_services_limits['mailboxes'], 'beltoft-services' ), (int) $view['plan']['used']['mailboxes'], (int) $beltoft_services_limits['mailboxes'] ) ) );
			if ( count( $view['plan']['members'] ) > 1 ) {
				$beltoft_services_row( __( 'On this domain', 'beltoft-services' ), esc_html( (string) (int) $view['counts']['mailboxes'] ) );
			}
			$beltoft_services_row( __( 'Size of each', 'beltoft-services' ), esc_html( $beltoft_services_size( (int) $beltoft_services_limits['quota_mb'] ) ) );
			$beltoft_services_row( __( 'Aliases allowed', 'beltoft-services' ), esc_html( (string) (int) $beltoft_services_limits['aliases'] ) );
			?>
		</dl>

		<?php
		$beltoft_services_plan   = $view['plan'];
		$beltoft_services_in     = count( $beltoft_services_plan['members'] );
		$beltoft_services_room   = $beltoft_services_in < (int) $beltoft_services_plan['limits']['domains'];
		$beltoft_services_zones  = array();
		if ( $beltoft_services_plan['is_root'] && $beltoft_services_room && 'ready' === $view['kind'] ) {
			$beltoft_services_zones = array_values( array_diff( \BeltoftServices\Mail\MailField::zones( get_current_user_id() ), $beltoft_services_plan['domains'] ) );
		}
		?>
		<?php if ( (int) $beltoft_services_plan['limits']['domains'] > 1 || ! $beltoft_services_plan['is_root'] ) : ?>
			<div class="beltoft-services-vm__more" id="beltoft-mail-domains" data-mail-domains>
				<h4><?php esc_html_e( 'Domains in this plan', 'beltoft-services' ); ?></h4>
				<?php if ( ! $beltoft_services_plan['is_root'] ) : ?>
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: the name of the plan, such as Team, 2: the first domain of the plan, as a link */
								__( 'Part of the %1$s plan of %2$s', 'beltoft-services' ),
								esc_html( (string) $beltoft_services_plan['root']['name'] ),
								'<a href="' . esc_url( $other_url( (int) $beltoft_services_plan['root']['id'] ) ) . '">' . esc_html( \BeltoftServices\Domains\HostName::display( (string) $beltoft_services_plan['root']['domain'] ) ) . '</a>'
							),
							array( 'a' => array( 'href' => array() ) )
						);
						?>
					</p>
				<?php endif; ?>
				<p><?php echo esc_html( sprintf( /* translators: 1: domains in the plan, 2: domains the plan allows */ _n( '%1$d of %2$d domain', '%1$d of %2$d domains', (int) $beltoft_services_plan['limits']['domains'], 'beltoft-services' ), $beltoft_services_in, (int) $beltoft_services_plan['limits']['domains'] ) ); ?></p>
				<ul>
					<?php foreach ( $beltoft_services_plan['members'] as $beltoft_services_member ) : ?>
						<li><?php echo $beltoft_services_member['id'] === $view['id'] ? esc_html( \BeltoftServices\Domains\HostName::display( $beltoft_services_member['domain'] ) ) : '<a href="' . esc_url( $other_url( (int) $beltoft_services_member['id'] ) ) . '">' . esc_html( \BeltoftServices\Domains\HostName::display( $beltoft_services_member['domain'] ) ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></li>
					<?php endforeach; ?>
				</ul>

				<?php if ( ! $beltoft_services_plan['is_root'] ) : ?>
					<?php if ( ! $beltoft_services_plan['root_ready'] ) : ?>
						<p><button type="button" class="button woocommerce-button beltoft-services-mail__outline" disabled data-mail-domain-remove="<?php echo esc_attr( $beltoft_services_domain ); ?>"><?php esc_html_e( 'Remove this domain', 'beltoft-services' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'You can remove a domain when the plan is active again.', 'beltoft-services' ); ?></p>
					<?php elseif ( (int) $view['counts']['mailboxes'] > 0 ) : ?>
						<p class="description"><?php esc_html_e( 'Delete the mailboxes on this domain first.', 'beltoft-services' ); ?></p>
					<?php else : ?>
						<p><button type="button" class="button woocommerce-button beltoft-services-mail__outline" data-mail-domain-remove="<?php echo esc_attr( $beltoft_services_domain ); ?>"><?php esc_html_e( 'Remove this domain', 'beltoft-services' ); ?></button></p>
					<?php endif; ?>
				<?php elseif ( 'ready' === $view['kind'] ) : ?>
					<?php if ( ! $beltoft_services_room ) : ?>
						<p><?php esc_html_e( 'Your plan has no room for another domain.', 'beltoft-services' ); ?></p>
					<?php else : ?>
						<div class="beltoft-services-db__form" data-mail-domain-add>
							<?php if ( array() !== $beltoft_services_zones ) : ?>
								<p>
									<label><input type="radio" name="beltoft-mail-domain-mode" value="ours" checked data-mail-domain-mode> <?php esc_html_e( 'One of your domains with us', 'beltoft-services' ); ?></label>
									<select data-mail-domain-zone aria-label="<?php esc_attr_e( 'One of your domains with us', 'beltoft-services' ); ?>">
										<?php foreach ( $beltoft_services_zones as $beltoft_services_zone ) : ?>
											<option value="<?php echo esc_attr( $beltoft_services_zone ); ?>"><?php echo esc_html( \BeltoftServices\Domains\HostName::display( $beltoft_services_zone ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
							<?php endif; ?>
							<p>
								<label><input type="radio" name="beltoft-mail-domain-mode" value="own"<?php echo array() === $beltoft_services_zones ? ' checked' : ''; ?> data-mail-domain-mode> <?php esc_html_e( 'A domain at another provider', 'beltoft-services' ); ?></label>
								<input type="text" autocomplete="off" maxlength="253" placeholder="example.com" aria-label="<?php esc_attr_e( 'A domain at another provider', 'beltoft-services' ); ?>" data-mail-domain-own>
								<span class="description"><?php esc_html_e( 'You add a few DNS records at your provider. We show you which ones, starting with one that proves the domain is yours.', 'beltoft-services' ); ?></span>
							</p>
							<p><button type="button" class="button woocommerce-button" data-mail-domain-submit><?php esc_html_e( 'Add domain', 'beltoft-services' ); ?></button></p>
						</div>
						<?php
						$beltoft_services_register = \BeltoftServices\Account\Domains::url_register();
						/**
						 * The address of the "Transfer a domain to us" link on the email page.
						 *
						 * @param string $url The domain search page with its transfer form (the id of the form's domain input is the default fragment), or ''.
						 */
						$beltoft_services_transfer = (string) apply_filters( 'beltoft_services_mail_transfer_url', '' !== $beltoft_services_register ? $beltoft_services_register . '#beltoft-transfer-domain' : '' );
						// The page learns which plan the domain is for; add_query_arg() keeps a #fragment at the end.
						$beltoft_services_for = (int) $beltoft_services_plan['root']['id'];
						$beltoft_services_register = '' !== $beltoft_services_register ? add_query_arg( 'mail_for', $beltoft_services_for, $beltoft_services_register ) : '';
						$beltoft_services_transfer = '' !== $beltoft_services_transfer ? add_query_arg( 'mail_for', $beltoft_services_for, $beltoft_services_transfer ) : '';
						?>
						<?php if ( '' !== $beltoft_services_register ) : ?>
							<p>
								<a href="<?php echo esc_url( $beltoft_services_register ); ?>"><?php esc_html_e( 'Register a new domain', 'beltoft-services' ); ?></a>
								<?php if ( '' !== $beltoft_services_transfer ) : ?>
									<br><a href="<?php echo esc_url( $beltoft_services_transfer ); ?>"><?php esc_html_e( 'Transfer a domain to us', 'beltoft-services' ); ?></a>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( isset( $beltoft_services_wait[ $view['kind'] ] ) ) : ?>
			<p><?php echo esc_html( $beltoft_services_wait[ $view['kind'] ] ); ?></p>
			<?php if ( 'building' === $view['kind'] && null !== $view['dns']['proof'] ) : ?>
				<p><button type="button" class="button woocommerce-button" data-mail-proof-check><?php esc_html_e( 'Check now', 'beltoft-services' ); ?></button></p>
			<?php endif; ?>
		<?php else : ?>
			<?php if ( ! empty( $view['send_held'] ) ) : ?>
				<p class="beltoft-services-panel__notice" role="status" data-mail-hold><?php esc_html_e( 'Mail does not arrive on this domain until the MX record is in place, and your mailboxes cannot send until the SPF and DKIM records are found. You can make mailboxes now.', 'beltoft-services' ); ?> <a href="<?php echo esc_url( $nav ? $link( 'dns' ) : '#beltoft-mail-dns' ); ?>"><?php esc_html_e( 'Open the DNS records guide', 'beltoft-services' ); ?></a></p>
			<?php elseif ( 'ok' !== $view['dns']['status'] && array() !== $view['dns']['rows'] ) : ?>
				<p class="beltoft-services-panel__notice" role="status"><?php esc_html_e( 'Mail does not arrive on this domain until its DNS records are in place. You can make mailboxes now.', 'beltoft-services' ); ?> <a href="<?php echo esc_url( $nav ? $link( 'dns' ) : '#beltoft-mail-dns' ); ?>"><?php esc_html_e( 'Open the DNS records guide', 'beltoft-services' ); ?></a></p>
			<?php endif; ?>
			<div class="beltoft-services-vm__more">
				<h4><?php esc_html_e( 'Mailboxes', 'beltoft-services' ); ?></h4>
				<?php if ( isset( $view['errors']['mailboxes'] ) ) : ?>
					<p class="beltoft-services-panel__notice" role="status"><?php echo esc_html( $view['errors']['mailboxes'] ); ?></p>
				<?php elseif ( array() === (array) $view['mailboxes'] ) : ?>
					<p><?php esc_html_e( 'You have no mailboxes yet. Make the first one below.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<table class="beltoft-services-vm__backup-list beltoft-services-mail__table">
						<thead>
							<tr><th scope="col"><?php esc_html_e( 'Address', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Storage', 'beltoft-services' ); ?></th><th scope="col"></th></tr>
						</thead>
						<tbody>
							<?php foreach ( (array) $view['mailboxes'] as $beltoft_services_box ) : ?>
								<?php $beltoft_services_percent = $beltoft_services_box['quota_mb'] > 0 ? (int) min( 100, floor( $beltoft_services_box['used_mb'] * 100 / $beltoft_services_box['quota_mb'] ) ) : 0; ?>
								<tr data-mail-box="<?php echo esc_attr( $beltoft_services_box['email'] ); ?>">
									<td><code><?php echo esc_html( \BeltoftServices\Domains\HostName::display( $beltoft_services_box['email'] ) ); ?></code><?php echo $beltoft_services_box['active'] ? '' : ' <span class="description">' . esc_html__( 'Paused', 'beltoft-services' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped text. ?>
										<?php if ( ! empty( $view['send_held'] ) ) : ?>
											<span class="description" data-mail-hold-row><?php esc_html_e( 'Sending on hold', 'beltoft-services' ); ?></span>
										<?php endif; ?></td>
									<td><?php echo esc_html( $beltoft_services_box['name'] ); ?></td>
									<td><?php echo esc_html( sprintf( /* translators: 1: storage used, 2: storage of the mailbox, 3: percent used */ __( '%1$s of %2$s (%3$d%%)', 'beltoft-services' ), $beltoft_services_size( (int) $beltoft_services_box['used_mb'] ), $beltoft_services_size( (int) $beltoft_services_box['quota_mb'] ), $beltoft_services_percent ) ); ?></td>
									<td class="beltoft-services-mail__actions">
										<?php if ( $beltoft_services_box['reveal'] ) : ?>
											<button type="button" class="button woocommerce-button beltoft-services-mail__outline" data-mail-reveal="<?php echo esc_attr( $beltoft_services_box['email'] ); ?>"><?php esc_html_e( 'Show password once', 'beltoft-services' ); ?></button>
										<?php endif; ?>
										<button type="button" class="button woocommerce-button beltoft-services-mail__outline" data-mail-reset="<?php echo esc_attr( $beltoft_services_box['email'] ); ?>"><?php esc_html_e( 'New password', 'beltoft-services' ); ?></button>
										<details class="beltoft-services-vm__more">
											<summary><?php esc_html_e( 'Delete', 'beltoft-services' ); ?></summary>
											<p class="description"><?php esc_html_e( 'Every message in the mailbox is deleted for good. This cannot be undone.', 'beltoft-services' ); ?></p>
											<div class="beltoft-services-db__form">
												<p>
													<label for="beltoft-mail-del-<?php echo esc_attr( md5( $beltoft_services_box['email'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: the word to type */ __( 'Type %s to confirm', 'beltoft-services' ), (string) $view['delete_word'] ) ); ?></label>
													<input type="text" id="beltoft-mail-del-<?php echo esc_attr( md5( $beltoft_services_box['email'] ) ); ?>" autocomplete="off" data-mail-delete-confirm>
												</p>
												<p><button type="button" class="button woocommerce-button beltoft-services-mail__danger" data-mail-delete="<?php echo esc_attr( $beltoft_services_box['email'] ); ?>"><?php esc_html_e( 'Delete mailbox', 'beltoft-services' ); ?></button></p>
											</div>
										</details>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<div class="beltoft-services-mail__secret" data-mail-secret hidden>
					<p data-mail-secret-text></p>
					<div class="beltoft-services-db__block"><code class="beltoft-services-db__conn" data-mail-secret-value></code><button type="button" class="button woocommerce-button beltoft-services-vm__copy" data-mail-copy><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>
					<p><button type="button" class="button woocommerce-button" data-mail-secret-done><?php esc_html_e( 'Done', 'beltoft-services' ); ?></button></p>
				</div>
			</div>

			<div class="beltoft-services-vm__more">
				<h4><?php esc_html_e( 'New mailbox', 'beltoft-services' ); ?></h4>
				<?php if ( (int) $view['plan']['used']['mailboxes'] >= (int) $beltoft_services_limits['mailboxes'] ) : ?>
					<p data-mail-no-room>
						<?php
						esc_html_e( 'Your plan has no room for more mailboxes. Delete a mailbox or change to a larger plan.', 'beltoft-services' );

						if ( '' !== (string) $view['plan']['upgrade'] ) {
							echo ' <a href="' . esc_url( (string) $view['plan']['upgrade'] ) . '">' . esc_html__( 'Change plan', 'beltoft-services' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here.
						}
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: size of a mailbox, such as 5 GB */ __( 'Each mailbox has %s of storage. You get a password to show once.', 'beltoft-services' ), $beltoft_services_size( (int) $beltoft_services_limits['quota_mb'] ) ) ); ?></p>
					<div class="beltoft-services-db__form" data-mail-create>
						<p>
							<label for="beltoft-mail-local"><?php esc_html_e( 'Name before the @', 'beltoft-services' ); ?></label>
							<input type="text" id="beltoft-mail-local" autocomplete="off" maxlength="64" placeholder="info" data-mail-local>
							<span class="beltoft-services-mail__at">@<?php echo esc_html( $beltoft_services_shown ); ?></span>
						</p>
						<p>
							<label for="beltoft-mail-name"><?php esc_html_e( 'Name shown (optional)', 'beltoft-services' ); ?></label>
							<input type="text" id="beltoft-mail-name" autocomplete="off" maxlength="100" data-mail-name>
						</p>
						<?php if ( (int) $beltoft_services_limits['quota_mb'] >= 2048 ) : ?>
							<p>
								<label for="beltoft-mail-size"><?php echo esc_html( sprintf( /* translators: %s: the plan's size of a mailbox, such as 5 GB */ __( 'Size in GB (up to %s, empty for the full size)', 'beltoft-services' ), $beltoft_services_size( (int) $beltoft_services_limits['quota_mb'] ) ) ); ?></label>
								<input type="number" id="beltoft-mail-size" min="1" max="<?php echo (int) floor( $beltoft_services_limits['quota_mb'] / 1024 ); ?>" step="1" data-mail-size>
							</p>
						<?php endif; ?>
						<p><button type="button" class="button woocommerce-button" data-mail-create-submit><?php esc_html_e( 'Create mailbox', 'beltoft-services' ); ?></button></p>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $beltoft_services_all || 'aliases' === $active ) : ?>
		<?php if ( isset( $beltoft_services_wait[ $view['kind'] ] ) ) : ?>
			<p><?php echo esc_html( $beltoft_services_wait[ $view['kind'] ] ); ?></p>
		<?php else : ?>
			<div class="beltoft-services-vm__more" data-mail-aliases data-confirm="<?php esc_attr_e( 'Delete this alias?', 'beltoft-services' ); ?>">
				<h4><?php esc_html_e( 'Aliases', 'beltoft-services' ); ?></h4>
				<p class="description"><?php echo esc_html( $view['forward_external'] ? __( 'An alias is an address that delivers to one or more other addresses, in this domain or elsewhere.', 'beltoft-services' ) : __( 'An alias is an address that delivers to one or more other addresses in this domain.', 'beltoft-services' ) ); ?></p>
				<?php if ( isset( $view['errors']['aliases'] ) ) : ?>
					<p class="beltoft-services-panel__notice" role="status"><?php echo esc_html( $view['errors']['aliases'] ); ?></p>
				<?php elseif ( array() === (array) $view['aliases'] ) : ?>
					<p><?php esc_html_e( 'You have no aliases yet.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<table class="beltoft-services-vm__backup-list beltoft-services-mail__table">
						<thead>
							<tr><th scope="col"><?php esc_html_e( 'Alias', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Delivers to', 'beltoft-services' ); ?></th><th scope="col"></th></tr>
						</thead>
						<tbody>
							<?php foreach ( (array) $view['aliases'] as $beltoft_services_alias ) : ?>
								<tr>
									<td><code><?php echo esc_html( \BeltoftServices\Domains\HostName::display( $beltoft_services_alias['address'] ) ); ?></code></td>
									<td><?php echo esc_html( implode( ', ', $beltoft_services_alias['goto'] ) ); ?></td>
									<td class="beltoft-services-mail__actions"><button type="button" class="button woocommerce-button beltoft-services-mail__outline" data-mail-alias-delete="<?php echo esc_attr( $beltoft_services_alias['address'] ); ?>"><?php esc_html_e( 'Delete', 'beltoft-services' ); ?></button></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: 1: aliases in use, 2: aliases the plan allows */ _n( '%1$d of %2$d alias used.', '%1$d of %2$d aliases used.', (int) $beltoft_services_limits['aliases'], 'beltoft-services' ), (int) $view['counts']['aliases'], (int) $beltoft_services_limits['aliases'] ) ); ?></p>
			</div>
			<div class="beltoft-services-vm__more">
				<h4><?php esc_html_e( 'New alias', 'beltoft-services' ); ?></h4>
				<?php if ( (int) $view['counts']['aliases'] >= (int) $beltoft_services_limits['aliases'] ) : ?>
					<p><?php esc_html_e( 'Your plan has no room for more aliases.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<div class="beltoft-services-db__form" data-mail-alias-create>
						<p>
							<label for="beltoft-mail-alias"><?php esc_html_e( 'Name before the @', 'beltoft-services' ); ?></label>
							<input type="text" id="beltoft-mail-alias" autocomplete="off" maxlength="64" placeholder="sales" data-mail-alias-local>
							<span class="beltoft-services-mail__at">@<?php echo esc_html( $beltoft_services_shown ); ?></span>
						</p>
						<p>
							<label for="beltoft-mail-goto"><?php esc_html_e( 'Deliver to', 'beltoft-services' ); ?></label>
							<textarea id="beltoft-mail-goto" rows="3" autocomplete="off" data-mail-alias-goto></textarea>
							<span class="description"><?php echo esc_html( $view['forward_external'] ? __( 'One or more addresses, one per line. Up to ten.', 'beltoft-services' ) : __( 'One or more addresses in this domain, one per line. Up to ten.', 'beltoft-services' ) ); ?></span>
						</p>
						<p><button type="button" class="button woocommerce-button" data-mail-alias-submit><?php esc_html_e( 'Create alias', 'beltoft-services' ); ?></button></p>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $beltoft_services_all || 'dns' === $active ) : ?>
		<?php $beltoft_services_dns = $view['dns']; ?>
		<div class="beltoft-services-vm__more" id="beltoft-mail-dns" data-mail-dns>
			<h4><?php esc_html_e( 'DNS records', 'beltoft-services' ); ?></h4>
			<?php if ( null === $beltoft_services_dns['guide'] ) : ?>
				<p><?php esc_html_e( 'The records appear here when your email domain is ready.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<?php \BeltoftServices\Domains\DnsRecordsGuide::render( $beltoft_services_dns['guide'] ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $beltoft_services_all || 'settings' === $active ) : ?>
		<?php $beltoft_services_connect = $view['connect']; ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Mail program settings', 'beltoft-services' ); ?></h4>
			<?php if ( '' === $beltoft_services_connect['host'] ) : ?>
				<p><?php esc_html_e( 'The settings appear here when email is set up.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'The user name is the whole address, for example info@your-domain, and the password is the one you showed when you made the mailbox. Use an encrypted connection.', 'beltoft-services' ); ?></p>
				<table class="beltoft-services-vm__backup-list beltoft-services-mail__table">
					<thead>
						<tr><th scope="col"><?php esc_html_e( 'Use', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Server', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Port', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Security', 'beltoft-services' ); ?></th></tr>
					</thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Incoming, IMAP', 'beltoft-services' ); ?></td><td><code><?php echo esc_html( $beltoft_services_connect['host'] ); ?></code></td><td><?php echo (int) $beltoft_services_connect['imap']; ?></td><td><?php esc_html_e( 'SSL/TLS', 'beltoft-services' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Incoming, POP3', 'beltoft-services' ); ?></td><td><code><?php echo esc_html( $beltoft_services_connect['host'] ); ?></code></td><td><?php echo (int) $beltoft_services_connect['pop']; ?></td><td><?php esc_html_e( 'SSL/TLS', 'beltoft-services' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Outgoing, SMTP', 'beltoft-services' ); ?></td><td><code><?php echo esc_html( $beltoft_services_connect['host'] ); ?></code></td><td><?php echo (int) $beltoft_services_connect['smtp'][0]; ?></td><td><?php esc_html_e( 'SSL/TLS', 'beltoft-services' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Outgoing, SMTP', 'beltoft-services' ); ?></td><td><code><?php echo esc_html( $beltoft_services_connect['host'] ); ?></code></td><td><?php echo (int) $beltoft_services_connect['smtp'][1]; ?></td><td><?php esc_html_e( 'STARTTLS', 'beltoft-services' ); ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( '' !== (string) ( $beltoft_services_connect['dav'] ?? '' ) ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: calendar and contacts address */ __( 'Calendar and contacts (CalDAV and CardDAV): %s, with your full email address and the mailbox password.', 'beltoft-services' ), (string) $beltoft_services_connect['dav'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $beltoft_services_connect['guide'] ) : ?>
				<p><a href="<?php echo esc_url( $beltoft_services_connect['guide'] ); ?>"><?php esc_html_e( 'Setup guide for your mail app', 'beltoft-services' ); ?></a></p>
			<?php endif; ?>
			<?php if ( '' !== $beltoft_services_connect['webmail'] ) : ?>
				<p><a class="button woocommerce-button" href="<?php echo esc_url( $beltoft_services_connect['webmail'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open webmail', 'beltoft-services' ); ?></a></p>
			<?php endif; ?>
		</div>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Your plan', 'beltoft-services' ); ?></h4>
			<dl class="beltoft-services-facts">
				<?php
				$beltoft_services_row( __( 'Mailboxes', 'beltoft-services' ), esc_html( (string) (int) $beltoft_services_limits['mailboxes'] ) );
				$beltoft_services_row( __( 'Size of each', 'beltoft-services' ), esc_html( $beltoft_services_size( (int) $beltoft_services_limits['quota_mb'] ) ) );
				$beltoft_services_row( __( 'Aliases', 'beltoft-services' ), esc_html( (string) (int) $beltoft_services_limits['aliases'] ) );
				?>
			</dl>
			<?php if ( $view['send']['mailbox_day'] > 0 || $view['send']['domain_minute'] > 0 ) : ?>
				<h4><?php esc_html_e( 'Sending limits', 'beltoft-services' ); ?></h4>
				<ul>
					<?php if ( $view['send']['mailbox_day'] > 0 ) : ?>
						<li><?php echo esc_html( sprintf( /* translators: %d: messages */ _n( 'Each mailbox can send %d message a day.', 'Each mailbox can send %d messages a day.', (int) $view['send']['mailbox_day'], 'beltoft-services' ), (int) $view['send']['mailbox_day'] ) ); ?></li>
					<?php endif; ?>
					<?php if ( $view['send']['domain_minute'] > 0 ) : ?>
						<li><?php echo esc_html( sprintf( /* translators: %d: messages */ _n( 'All mailboxes of the domain together can send %d message a minute.', 'All mailboxes of the domain together can send %d messages a minute.', (int) $view['send']['domain_minute'], 'beltoft-services' ), (int) $view['send']['domain_minute'] ) ); ?></li>
					<?php endif; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'When a limit is reached, sending is held back until the limit resets. Receiving is not affected.', 'beltoft-services' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

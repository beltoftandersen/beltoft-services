<?php
/**
 * The card of a managed database. Printed by BeltoftServices\Proxmox\DbCard::render().
 *
 * Variables: $view (see DbCard::view()), $nav (the page's tabs, slug => title, or empty on a card), $active (the tab shown),
 * $link (a callable that gives a tab's address). The password is never part of this template: the button asks the
 * server for it once and the script puts it into the connection strings.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_db_status = array(
	'ready'    => __( 'Ready', 'beltoft-services' ),
	'building' => __( 'Being set up', 'beltoft-services' ),
	'paused'   => __( 'Paused', 'beltoft-services' ),
	'restoring' => __( 'Being restored', 'beltoft-services' ),
	'failed'   => __( 'Not available', 'beltoft-services' ),
);
?>
<?php
$beltoft_services_all = ! $nav;
?>
<div class="beltoft-services-db" data-db-card>
	<?php if ( $nav ) : ?>
		<nav class="beltoft-services-tabs" aria-label="<?php esc_attr_e( 'Database sections', 'beltoft-services' ); ?>">
			<?php foreach ( $nav as $beltoft_services_slug => $beltoft_services_title ) : ?>
				<a href="<?php echo esc_url( $link( $beltoft_services_slug ) ); ?>"<?php echo $beltoft_services_slug === $active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $beltoft_services_title ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
	<?php if ( $beltoft_services_all || 'overview' === $active ) : ?>
	<dl class="beltoft-services-facts">
		<div><dt><?php esc_html_e( 'Status', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $beltoft_services_db_status[ $view['kind'] ] ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Engine', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $view['version'] > 0 ? 'PostgreSQL ' . $view['version'] : 'PostgreSQL' ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Size', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( (string) $view['size'] ); ?></dd></div>
		<?php if ( $view['backups']['enabled'] ) : ?>
			<div><dt><?php esc_html_e( 'Backups', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: number of days */ _n( 'Daily, kept %d day', 'Daily, kept %d days', (int) $view['backups']['days'], 'beltoft-services' ), (int) $view['backups']['days'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Last backup', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( '' !== $view['backups']['last_ok'] ? (string) $view['backups']['last_ok'] : __( 'None yet', 'beltoft-services' ) ); ?></dd></div>
		<?php endif; ?>
		<?php if ( 'ready' === $view['kind'] ) : ?>
			<div><dt><?php esc_html_e( 'Host', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['host'] ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'Port', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['port'] ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'Database', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['database'] ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'User', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['user'] ); ?></code></dd></div>
		<?php endif; ?>
	</dl>

	<?php if ( 'building' === $view['kind'] ) : ?>
		<p><?php esc_html_e( 'Your database is being set up. The connection details appear here when it is ready. You can leave this page.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'restoring' === $view['kind'] ) : ?>
		<p><?php esc_html_e( 'Your database is being restored from a backup. It is not available until the restore is finished. You can leave this page.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'paused' === $view['kind'] ) : ?>
		<p><?php esc_html_e( 'Your applications cannot reach this database until the service is active again.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'failed' === $view['kind'] ) : ?>
		<p><?php esc_html_e( 'Your database could not be set up. We have been told about the problem. Please contact support.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Connection', 'beltoft-services' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Reachable from your servers on the private network.', 'beltoft-services' ); ?></p>
			<p><?php esc_html_e( 'With certificate check (recommended):', 'beltoft-services' ); ?></p>
			<div class="beltoft-services-db__block"><code class="beltoft-services-db__conn" data-db-conn><?php echo esc_html( (string) $view['conn_verify'] ); ?></code><button type="button" class="button woocommerce-button beltoft-services-vm__copy" data-db-copy data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>
			<p><?php esc_html_e( 'Encrypted, without certificate check:', 'beltoft-services' ); ?></p>
			<div class="beltoft-services-db__block"><code class="beltoft-services-db__conn" data-db-conn><?php echo esc_html( (string) $view['conn_require'] ); ?></code><button type="button" class="button woocommerce-button beltoft-services-vm__copy" data-db-copy data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>

			<?php if ( $view['can_reveal'] ) : ?>
				<p class="beltoft-services-vm__reveal"><button type="button" class="button woocommerce-button" data-db-reveal><?php esc_html_e( 'Show password once', 'beltoft-services' ); ?></button></p>
			<?php endif; ?>
			<p class="beltoft-services-panel__notice" data-db-notice role="status" aria-live="polite"></p>
		</div>

		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Certificate', 'beltoft-services' ); ?></h4>
			<?php if ( '' !== $view['ca_url'] ) : ?>
				<p><a class="button woocommerce-button" href="<?php echo esc_url( (string) $view['ca_url'] ); ?>"><?php esc_html_e( 'Download CA certificate', 'beltoft-services' ); ?></a></p>
			<?php endif; ?>
			<?php if ( '' !== $view['fingerprint'] ) : ?>
				<p><?php esc_html_e( 'SHA-256 fingerprint:', 'beltoft-services' ); ?></p>
				<div class="beltoft-services-db__block"><code class="beltoft-services-db__conn"><?php echo esc_html( (string) $view['fingerprint'] ); ?></code><button type="button" class="button woocommerce-button beltoft-services-vm__copy" data-db-copy data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>
			<?php endif; ?>
		</div>
	<?php endif; // Kind of state. ?>
	<?php endif; // Overview pane. ?>

	<?php if ( in_array( $view['kind'], array( 'ready', 'restoring' ), true ) && ( $beltoft_services_all || 'backups' === $active ) ) : ?>
		<?php $beltoft_services_backups = $view['backups']; ?>
		<div class="beltoft-services-vm__more beltoft-services-vm__backups">
			<h4><?php esc_html_e( 'Backups', 'beltoft-services' ); ?></h4>
			<?php if ( ! $beltoft_services_backups['enabled'] ) : ?>
				<p><?php esc_html_e( 'Backups are not available for this database.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( /* translators: %d: number of days */ _n( 'A backup of the whole database server is taken every night and kept for %d day.', 'A backup of the whole database server is taken every night and kept for %d days.', (int) $beltoft_services_backups['days'], 'beltoft-services' ), (int) $beltoft_services_backups['days'] ) ); ?></p>
				<?php if ( '' !== $beltoft_services_backups['last_dump'] ) : ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: date and time */ __( 'Last nightly dump: %s', 'beltoft-services' ), $beltoft_services_backups['last_dump'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( array() === $beltoft_services_backups['list'] ) : ?>
					<p><?php esc_html_e( 'The first backup will appear here after tonight\'s run.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<table class="beltoft-services-vm__backup-list">
						<thead>
							<tr><th scope="col"><?php esc_html_e( 'Backup', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Size', 'beltoft-services' ); ?></th></tr>
						</thead>
						<tbody>
							<?php foreach ( $beltoft_services_backups['list'] as $beltoft_services_backup ) : ?>
								<tr><td><?php echo esc_html( $beltoft_services_backup['when'] ); ?></td><td><?php echo esc_html( $beltoft_services_backup['size'] ); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( 'restoring' === $view['kind'] ) : ?>
					<p><?php esc_html_e( 'A restore is running. It can take several minutes.', 'beltoft-services' ); ?></p>
				<?php elseif ( $beltoft_services_backups['can_restore'] ) : ?>
					<details class="beltoft-services-vm__more">
						<summary><?php esc_html_e( 'Restore from a backup', 'beltoft-services' ); ?></summary>
						<p class="description"><?php esc_html_e( 'Restoring puts the whole database server back to the backup. Everything after this backup is lost. The database is not available while it is restored. The password goes back to the one the backup had. The address stays.', 'beltoft-services' ); ?></p>
						<div class="beltoft-services-db__form">
						<p>
							<label for="beltoft-db-backup"><?php esc_html_e( 'Backup', 'beltoft-services' ); ?></label>
							<select id="beltoft-db-backup" data-db-backup>
								<?php foreach ( $beltoft_services_backups['list'] as $beltoft_services_backup ) : ?>
									<option value="<?php echo esc_attr( $beltoft_services_backup['volid'] ); ?>"><?php echo esc_html( $beltoft_services_backup['when'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="beltoft-db-restore-confirm"><?php echo esc_html( sprintf( /* translators: %s: the word to type */ __( 'Type %s to confirm', 'beltoft-services' ), (string) $beltoft_services_backups['confirm'] ) ); ?></label>
							<input type="text" id="beltoft-db-restore-confirm" autocomplete="off" data-db-restore-confirm>
						</p>
						<p><button type="button" class="button woocommerce-button" data-db-restore><?php esc_html_e( 'Restore now', 'beltoft-services' ); ?></button></p>
						</div>
						<p class="beltoft-services-panel__notice" data-db-notice role="status" aria-live="polite"></p>
					</details>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( 'ready' === $view['kind'] && ( $beltoft_services_all || 'password' === $active ) ) : ?>
		<?php if ( $view['can_reset'] ) : ?>
			<div class="beltoft-services-vm__more">
				<h4><?php esc_html_e( 'New password', 'beltoft-services' ); ?></h4>
				<p><?php esc_html_e( 'The old password stops working at once. Applications that use it must be changed.', 'beltoft-services' ); ?></p>
				<div class="beltoft-services-db__form">
				<p>
					<label for="beltoft-db-confirm"><?php echo esc_html( sprintf( /* translators: %s: the words to type */ __( 'Type %s to confirm', 'beltoft-services' ), (string) $view['confirm'] ) ); ?></label>
					<input type="text" id="beltoft-db-confirm" autocomplete="off" data-db-confirm>
				</p>
				<p><button type="button" class="button woocommerce-button" data-db-reset><?php esc_html_e( 'New password', 'beltoft-services' ); ?></button></p>
				</div>
			</div>
		<?php elseif ( $view['resetting'] ) : ?>
			<p><?php esc_html_e( 'A new password is being made. This can take a minute.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

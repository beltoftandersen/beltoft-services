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
// What a database that is not ready says, on the Overview and on every other tab.
$beltoft_services_db_wait = array(
	'building'  => __( 'Your database is being set up. The connection details appear here when it is ready. You can leave this page.', 'beltoft-services' ),
	'restoring' => __( 'Your database is being restored from a backup. It is not available until the restore is finished. You can leave this page.', 'beltoft-services' ),
	'paused'    => __( 'Your applications cannot reach this database until the service is active again.', 'beltoft-services' ),
	'failed'    => __( 'Your database could not be set up. We have been told about the problem. Please contact support.', 'beltoft-services' ),
);
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
		<div><dt><?php esc_html_e( 'Engine', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( (string) $view['engine'] ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Size', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( (string) $view['size'] ); ?></dd></div>
		<?php if ( $view['backups']['enabled'] ) : ?>
			<div><dt><?php esc_html_e( 'Backups', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: number of days */ _n( 'Daily, kept %d day', 'Daily, kept %d days', (int) $view['backups']['days'], 'beltoft-services' ), (int) $view['backups']['days'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Last backup', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( '' !== $view['backups']['last_ok'] ? (string) $view['backups']['last_ok'] : __( 'None yet', 'beltoft-services' ) ); ?></dd></div>
		<?php endif; ?>
		<?php if ( 'ready' === $view['kind'] ) : ?>
			<div><dt><?php esc_html_e( 'Host', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['host'] ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'Port', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['port'] ); ?></code></dd></div>
			<?php if ( '' !== (string) $view['database'] ) : ?>
				<div><dt><?php esc_html_e( 'Database', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['database'] ); ?></code></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'User', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['user'] ); ?></code></dd></div>
		<?php endif; ?>
	</dl>
	<?php if ( '' !== (string) ( $view['limits'] ?? '' ) ) : ?><p class="description"><?php echo esc_html( (string) $view['limits'] ); ?></p><?php endif; ?>
	<?php if ( '' !== (string) ( $view['cost'] ?? '' ) ) : ?><p class="beltoft-services-cost"><?php echo esc_html( $view['cost'] ); ?></p><?php endif; ?>

	<?php if ( 'ready' === $view['kind'] ) : ?>
		<?php $beltoft_services_storage = $view['storage']; ?>
		<div class="beltoft-services-vm__more beltoft-services-db__storage">
			<h4><?php esc_html_e( 'Storage', 'beltoft-services' ); ?></h4>
			<p><?php echo esc_html( sprintf( /* translators: %d: size of the data disk in GB */ __( 'Your database has %d GB of storage.', 'beltoft-services' ), (int) $beltoft_services_storage['gb'] ) ); ?></p>
			<?php if ( $beltoft_services_storage['growing'] || $beltoft_services_storage['manual'] ) : ?>
				<p><?php esc_html_e( 'Your storage is being made larger. The new size appears here when it is ready. Your database stays available. You can leave this page.', 'beltoft-services' ); ?></p>
			<?php elseif ( array() !== $beltoft_services_storage['options'] ) : ?>
				<p class="description"><?php esc_html_e( 'You can make the storage larger. It cannot be made smaller again, and the extra storage is part of your database plan until the database is deleted. Your database stays available.', 'beltoft-services' ); ?></p>
				<ul class="beltoft-services-db__storage-options">
					<?php foreach ( $beltoft_services_storage['options'] as $beltoft_services_option ) : ?>
						<li><a class="button woocommerce-button" href="<?php echo esc_url( (string) $beltoft_services_option['url'] ); ?>"><?php echo esc_html( sprintf( /* translators: 1: new size in GB, 2: price difference such as +€8.00 / month */ __( 'Make larger: %1$d GB (%2$s)', 'beltoft-services' ), (int) $beltoft_services_option['total'], (string) $beltoft_services_option['price'] ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $beltoft_services_storage['full'] ) : ?>
				<p><?php esc_html_e( 'No more storage available right now.', 'beltoft-services' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( isset( $beltoft_services_db_wait[ $view['kind'] ] ) ) : ?>
		<p><?php echo esc_html( $beltoft_services_db_wait[ $view['kind'] ] ); ?></p>
	<?php else : ?>
		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Connection', 'beltoft-services' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Reachable from your servers on the private network.', 'beltoft-services' ); ?></p>
			<?php foreach ( $view['connections'] as $beltoft_services_conn ) : ?>
				<p><?php echo esc_html( (string) $beltoft_services_conn['label'] ); ?></p>
				<div class="beltoft-services-db__block"><code class="beltoft-services-db__conn" data-db-conn><?php echo esc_html( (string) $beltoft_services_conn['value'] ); ?></code><button type="button" class="button woocommerce-button beltoft-services-vm__copy" data-db-copy data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>
			<?php endforeach; ?>
			<?php if ( '' !== (string) $view['connection_note'] ) : ?>
				<p class="description"><?php echo esc_html( (string) $view['connection_note'] ); ?></p>
			<?php endif; ?>

			<?php if ( array() !== $view['public_connections'] ) : ?>
				<p class="description"><?php esc_html_e( 'From anywhere on the internet, for the addresses on your access list (see the Access tab):', 'beltoft-services' ); ?></p>
				<?php foreach ( $view['public_connections'] as $beltoft_services_conn ) : ?>
					<div class="beltoft-services-db__block"><code class="beltoft-services-db__conn" data-db-conn><?php echo esc_html( (string) $beltoft_services_conn['value'] ); ?></code><button type="button" class="button woocommerce-button beltoft-services-vm__copy" data-db-copy data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( $view['can_reveal'] ) : ?>
				<p class="beltoft-services-vm__reveal"><button type="button" class="button woocommerce-button" data-db-reveal><?php esc_html_e( 'Show password once', 'beltoft-services' ); ?></button></p>
			<?php endif; ?>
			<p class="beltoft-services-panel__notice" data-db-notice role="status" aria-live="polite"></p>
		</div>

		<div class="beltoft-services-vm__more">
			<h4><?php esc_html_e( 'Certificate', 'beltoft-services' ); ?></h4>
			<?php if ( '' !== (string) ( $view['ca_hint'] ?? '' ) ) : ?>
				<p class="description"><?php echo esc_html( (string) $view['ca_hint'] ); ?></p>
			<?php endif; ?>
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

	<?php if ( 'ready' === $view['kind'] && is_array( $view['policy'] ?? null ) && ( $beltoft_services_all || 'overview' === $active ) ) : ?>
		<?php $beltoft_services_policy = $view['policy']; ?>
		<div class="beltoft-services-vm__more" data-db-policy-box>
			<h4><?php esc_html_e( 'When memory is full', 'beltoft-services' ); ?></h4>
			<p class="description"><?php esc_html_e( 'The database keeps its data in memory. This decides what it does when the memory limit is reached. The change applies at once and stays after a restart.', 'beltoft-services' ); ?></p>
			<?php if ( $beltoft_services_policy['old'] ) : ?>
				<p><?php echo esc_html( (string) $beltoft_services_policy['old_text'] ); ?></p>
			<?php endif; ?>
			<?php if ( $beltoft_services_policy['can_set'] && ! $beltoft_services_policy['working'] ) : ?>
				<div class="beltoft-services-db__form">
					<p>
						<label for="beltoft-db-policy"><?php esc_html_e( 'Setting', 'beltoft-services' ); ?></label>
						<select id="beltoft-db-policy" data-db-policy>
							<?php foreach ( $beltoft_services_policy['choices'] as $beltoft_services_value => $beltoft_services_choice ) : ?>
								<option value="<?php echo esc_attr( (string) $beltoft_services_value ); ?>"<?php selected( $beltoft_services_value, $beltoft_services_policy['current'] ); ?>><?php echo esc_html( $beltoft_services_choice[0] . ' (' . $beltoft_services_value . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><button type="button" class="button woocommerce-button" data-db-policy-save><?php esc_html_e( 'Save', 'beltoft-services' ); ?></button></p>
				</div>
				<ul class="beltoft-services-db__policies">
					<?php foreach ( $beltoft_services_policy['choices'] as $beltoft_services_value => $beltoft_services_choice ) : ?>
						<li><strong><code><?php echo esc_html( (string) $beltoft_services_value ); ?></code></strong> <?php echo esc_html( $beltoft_services_choice[1] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php echo esc_html( $beltoft_services_policy['working'] ? __( 'A change is being applied. This can take a minute.', 'beltoft-services' ) : sprintf( /* translators: %s: the current setting */ __( 'Current setting: %s', 'beltoft-services' ), (string) $beltoft_services_policy['current'] ) ); ?></p>
			<?php endif; ?>
			<p class="beltoft-services-panel__notice" data-db-notice role="status" aria-live="polite"></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $beltoft_services_all && 'overview' !== $active && isset( $beltoft_services_db_wait[ $view['kind'] ] ) ) : ?>
		<p><?php echo esc_html( $beltoft_services_db_wait[ $view['kind'] ] ); ?></p>
	<?php endif; ?>

	<?php if ( 'ready' === $view['kind'] && ( $beltoft_services_all || 'access' === $active ) ) : ?>
		<?php $beltoft_services_access = $view['access']; ?>
		<div class="beltoft-services-vm__more beltoft-services-db__access" data-db-access data-confirm-remove="<?php esc_attr_e( 'Remove this address from the list?', 'beltoft-services' ); ?>">
			<h4><?php esc_html_e( 'Public IP address', 'beltoft-services' ); ?></h4>
			<?php if ( '' !== $beltoft_services_access['address'] ) : ?>
				<p><?php esc_html_e( 'Address:', 'beltoft-services' ); ?> <code><?php echo esc_html( (string) $beltoft_services_access['address'] ); ?></code> <?php esc_html_e( 'Port:', 'beltoft-services' ); ?> <code><?php echo esc_html( (string) $view['port'] ); ?></code></p>
				<?php if ( $beltoft_services_access['removing'] ) : ?>
					<p><?php esc_html_e( 'The public IP address is being removed. Your database restarts once.', 'beltoft-services' ); ?></p>
				<?php elseif ( '' !== $beltoft_services_access['remove_url'] ) : ?>
					<p class="description"><?php esc_html_e( 'Applications that connect through this address will stop working when it is removed. Your database restarts once, which takes about a minute.', 'beltoft-services' ); ?></p>
					<p><a class="button woocommerce-button" href="<?php echo esc_url( (string) $beltoft_services_access['remove_url'] ); ?>"><?php esc_html_e( 'Remove the public IP address', 'beltoft-services' ); ?></a></p>
				<?php endif; ?>
			<?php elseif ( $beltoft_services_access['adding'] ) : ?>
				<p><?php esc_html_e( 'Your public IP address is being added. Your database restarts once, which takes about a minute. The address appears here when it is ready. You can leave this page.', 'beltoft-services' ); ?></p>
			<?php elseif ( $beltoft_services_access['sold_out'] ) : ?>
				<p><?php esc_html_e( 'No public IP address is available right now. Please try again later.', 'beltoft-services' ); ?></p>
			<?php elseif ( '' !== $beltoft_services_access['add_url'] ) : ?>
				<p><?php esc_html_e( 'Your database is reachable from your servers on the private network. A public IP address makes it reachable from elsewhere too, for the addresses you allow.', 'beltoft-services' ); ?></p>
				<p class="description"><?php echo esc_html( '' !== $beltoft_services_access['add_price'] ? sprintf( /* translators: %s: the price per period, such as +€4.00 / month */ __( 'Price: %s. Your database restarts once, which takes about a minute.', 'beltoft-services' ), $beltoft_services_access['add_price'] ) : __( 'Your database restarts once, which takes about a minute.', 'beltoft-services' ) ); ?></p>
				<p><a class="button woocommerce-button" href="<?php echo esc_url( (string) $beltoft_services_access['add_url'] ); ?>"><?php esc_html_e( 'Add a public IP address', 'beltoft-services' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'This database has no public IP address. It is reachable from your servers on the private network.', 'beltoft-services' ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $beltoft_services_access['address'] ) : ?>
				<h4><?php esc_html_e( 'Who may connect', 'beltoft-services' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Only these addresses can reach the database over its public IP address. Everything else is refused.', 'beltoft-services' ); ?></p>
				<?php if ( $beltoft_services_access['world'] ) : ?>
					<p class="beltoft-services-panel__notice" role="status"><?php esc_html_e( 'The database is open to everyone. Anyone on the internet can try to log in. The list below is not used while this is on.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<?php if ( array() === $beltoft_services_access['entries'] ) : ?>
					<p><?php esc_html_e( 'No address is allowed yet.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<table class="beltoft-services-vm__backup-list">
						<thead>
							<tr><th scope="col"><?php esc_html_e( 'Address', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Label', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Added', 'beltoft-services' ); ?></th><th scope="col"></th></tr>
						</thead>
						<tbody>
							<?php foreach ( $beltoft_services_access['entries'] as $beltoft_services_entry ) : ?>
								<tr>
									<td><code><?php echo esc_html( $beltoft_services_entry['cidr'] ); ?></code></td>
									<td><?php echo esc_html( $beltoft_services_entry['label'] ); ?></td>
									<td><?php echo esc_html( $beltoft_services_entry['added'] ); ?></td>
									<td><?php if ( $beltoft_services_access['editable'] ) : ?><button type="button" class="button woocommerce-button" data-db-access-remove="<?php echo esc_attr( $beltoft_services_entry['cidr'] ); ?>"><?php esc_html_e( 'Remove', 'beltoft-services' ); ?></button><?php endif; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $beltoft_services_access['editable'] ) : ?>
					<div class="beltoft-services-db__form">
						<p>
							<label for="beltoft-db-cidr"><?php esc_html_e( 'Address or range', 'beltoft-services' ); ?></label>
							<input type="text" id="beltoft-db-cidr" autocomplete="off" placeholder="203.0.113.7" data-db-access-cidr>
							<?php if ( '' !== $beltoft_services_access['my_ip'] ) : ?>
								<button type="button" class="button woocommerce-button" data-db-access-mine="<?php echo esc_attr( (string) $beltoft_services_access['my_ip'] ); ?>"><?php esc_html_e( 'Use my current IP', 'beltoft-services' ); ?></button>
							<?php endif; ?>
						</p>
						<p>
							<label for="beltoft-db-label"><?php esc_html_e( 'Label (optional)', 'beltoft-services' ); ?></label>
							<input type="text" id="beltoft-db-label" maxlength="40" autocomplete="off" data-db-access-label>
						</p>
						<p><button type="button" class="button woocommerce-button" data-db-access-add><?php esc_html_e( 'Add address', 'beltoft-services' ); ?></button></p>
					</div>

					<?php if ( $beltoft_services_access['world'] ) : ?>
						<p><button type="button" class="button woocommerce-button" data-db-access-world="world_off"><?php esc_html_e( 'Limit access to the list', 'beltoft-services' ); ?></button></p>
					<?php else : ?>
						<details class="beltoft-services-vm__more">
							<summary><?php esc_html_e( 'Open to everyone', 'beltoft-services' ); ?></summary>
							<p class="description"><?php esc_html_e( 'Use this only when your application has no fixed address. Anyone on the internet can then try to log in to your database. The password and the encrypted connection still protect it. We email you when it is turned on.', 'beltoft-services' ); ?></p>
							<div class="beltoft-services-db__form">
								<p>
									<label for="beltoft-db-world-confirm"><?php echo esc_html( sprintf( /* translators: %s: the words to type */ __( 'Type %s to confirm', 'beltoft-services' ), (string) $beltoft_services_access['confirm'] ) ); ?></label>
									<input type="text" id="beltoft-db-world-confirm" autocomplete="off" data-db-access-confirm>
								</p>
								<p><button type="button" class="button woocommerce-button" data-db-access-world="world_on"><?php esc_html_e( 'Open to everyone', 'beltoft-services' ); ?></button></p>
							</div>
						</details>
					<?php endif; ?>
				<?php elseif ( 'applying' === $beltoft_services_access['state'] ) : ?>
					<p data-db-access-pending><?php esc_html_e( 'A change is being applied. This can take a minute.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<p class="beltoft-services-panel__notice" data-db-notice role="status" aria-live="polite"></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

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

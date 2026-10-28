<?php
/**
 * The virtual machine part of the service panel (My Account → Licenses).
 *
 * Variables (set by BeltoftServices\Proxmox\Panels::account()): $ip, $plan (cores, memory_mb,
 * disk_gb), $power (running, stopped or empty when not known), $os_label, $os_options
 * (VMID => label), $os_current, $has_key, $can_reveal, $forms (whether actions are allowed now), $ssh_user,
 * $backups (null, or enabled, days, list of volid/when/size, last_ok, add_url, add_price, remove_url, can_restore).
 * The password is never part of this template: the reveal button asks the server for it once.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_power_labels = array(
	'running' => __( 'Running', 'beltoft-services' ),
	'stopped' => __( 'Stopped', 'beltoft-services' ),
);
?>
<div class="beltoft-services-vm">
	<dl class="beltoft-services-vm__facts">
		<?php if ( '' !== $ip ) : ?>
			<dt><?php esc_html_e( 'IP address', 'beltoft-services' ); ?></dt>
			<dd><code><?php echo esc_html( $ip ); ?></code></dd>
			<dt><?php esc_html_e( 'Log in', 'beltoft-services' ); ?></dt>
			<dd><code>ssh <?php echo esc_html( $ssh_user ); ?>@<?php echo esc_html( $ip ); ?></code></dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'Plan', 'beltoft-services' ); ?></dt>
		<dd>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of vCPUs, 2: memory in MB, 3: disk in GB */
					__( '%1$d vCPU, %2$d MB RAM, %3$d GB disk', 'beltoft-services' ),
					(int) $plan['cores'],
					(int) $plan['memory_mb'],
					(int) $plan['disk_gb']
				)
			);
			?>
		</dd>
		<?php if ( '' !== $os_label ) : ?>
			<dt><?php esc_html_e( 'Operating system', 'beltoft-services' ); ?></dt>
			<dd><?php echo esc_html( $os_label ); ?></dd>
		<?php endif; ?>
		<?php if ( isset( $beltoft_services_power_labels[ $power ] ) ) : ?>
			<dt><?php esc_html_e( 'Power', 'beltoft-services' ); ?></dt>
			<dd><?php echo esc_html( $beltoft_services_power_labels[ $power ] ); ?></dd>
		<?php endif; ?>
	</dl>

	<section class="beltoft-services-vm__disks">
		<h4><?php esc_html_e( 'Disks', 'beltoft-services' ); ?></h4>
		<dl class="beltoft-services-vm__facts">
			<dt><?php esc_html_e( 'Boot disk', 'beltoft-services' ); ?></dt>
			<dd><?php echo esc_html( (int) $plan['disk_gb'] . ' GB' ); ?></dd>
		</dl>
		<?php foreach ( $extra_disks['items'] as $beltoft_extra_disk ) : ?>
			<div class="beltoft-services-vm__disk">
				<p><strong><?php echo esc_html( ( $beltoft_extra_disk['slot'] ?: __( 'Extra disk', 'beltoft-services' ) ) . ': ' . $beltoft_extra_disk['size'] . ' GB' ); ?></strong> <?php echo esc_html( $beltoft_extra_disk['state'] ); ?></p>
				<?php if ( ! empty( $beltoft_extra_disk['paused'] ) ) : ?><p class="description"><?php esc_html_e( 'This disk is detached from your server because its subscription is not active. Your data is kept until the disk is deleted.', 'beltoft-services' ); ?></p><?php endif; ?>
				<?php if ( $beltoft_extra_disk['grow_url'] ) : ?><a href="<?php echo esc_url( $beltoft_extra_disk['grow_url'] ); ?>"><?php esc_html_e( 'Make larger', 'beltoft-services' ); ?><?php if ( $beltoft_extra_disk['grow_price'] ) : ?> (<?php echo esc_html( $beltoft_extra_disk['grow_price'] ); ?>)<?php endif; ?></a><?php endif; ?>
				<?php if ( $beltoft_extra_disk['remove_url'] ) : ?><a href="<?php echo esc_url( $beltoft_extra_disk['remove_url'] ); ?>"><?php esc_html_e( 'Remove', 'beltoft-services' ); ?></a><?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php if ( $extra_disks['items'] ) : ?>
			<p class="description">
				<?php if ( 'on' === $extra_disks['mode'] ) : ?>
					<?php
					$beltoft_disk_retention = (int) ( $extra_disks['items'][0]['retention_days'] ?? 0 );
					if ( $beltoft_disk_retention > 0 ) {
						/* translators: %d: days after the disk subscription's paid period ends. */
						echo esc_html( sprintf( _n( 'When a disk subscription ends, the disk is detached from your server and its data is kept, then removed %d day after the paid period ends.', 'When a disk subscription ends, the disk is detached from your server and its data is kept, then removed %d days after the paid period ends.', $beltoft_disk_retention, 'beltoft-services' ), $beltoft_disk_retention ) );
					} else {
						esc_html_e( 'When a disk subscription ends, the disk is detached from your server and its data is kept until we remove the disk.', 'beltoft-services' );
					}
					?>
				<?php else : ?>
					<?php esc_html_e( 'When a disk subscription ends, billing stops. The disk is detached from your server and its data is kept until we remove the disk.', 'beltoft-services' ); ?>
				<?php endif; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Reinstall and restore are unavailable until the extra disks are removed.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( $extra_disks['add'] ) : ?>
			<p><?php esc_html_e( 'Add a disk:', 'beltoft-services' ); ?>
			<?php foreach ( $extra_disks['add'] as $beltoft_disk_size ) : ?>
				<a href="<?php echo esc_url( $beltoft_disk_size['url'] ); ?>"><?php echo esc_html( $beltoft_disk_size['size'] . ' GB (' . $beltoft_disk_size['price'] . ' excluding VAT)' ); ?></a>
			<?php endforeach; ?></p>
		<?php endif; ?>
	</section>

	<?php if ( $metrics ) : ?>
		<section class="beltoft-vm-usage" data-vm-metrics>
			<div class="beltoft-vm-usage__head">
				<h4><?php esc_html_e( 'Usage', 'beltoft-services' ); ?></h4>
				<div class="beltoft-vm-usage__ranges" role="group" aria-label="<?php esc_attr_e( 'Time range', 'beltoft-services' ); ?>">
					<button type="button" data-range="hour" aria-pressed="true"><?php esc_html_e( '1 hour', 'beltoft-services' ); ?></button>
					<button type="button" data-range="day" aria-pressed="false"><?php esc_html_e( '1 day', 'beltoft-services' ); ?></button>
					<button type="button" data-range="month" aria-pressed="false"><?php esc_html_e( '30 days', 'beltoft-services' ); ?></button>
				</div>
			</div>
			<p class="beltoft-vm-usage__disk" data-disk-line>
				<?php if ( is_numeric( $disk_used ) && is_numeric( $disk_total ) && (int) $disk_total > 0 ) : ?>
					<?php
					/* translators: 1: used disk space in GB, 2: total disk space in GB. */
					echo esc_html( sprintf( __( 'Disk used: %1$s of %2$s GB', 'beltoft-services' ), number_format_i18n( (int) $disk_used / 1073741824, 1 ), number_format_i18n( (int) $disk_total / 1073741824, 1 ) ) );
					?>
				<?php else : ?>
					<?php
					/* translators: %d: the plan's disk size in GB. */
					echo esc_html( sprintf( __( 'Disk: %d GB', 'beltoft-services' ), (int) $plan['disk_gb'] ) );
					?>
				<?php endif; ?>
			</p>
			<p class="beltoft-vm-usage__notice" data-metrics-notice role="status" aria-live="polite"><?php esc_html_e( 'Loading usage…', 'beltoft-services' ); ?></p>
			<div class="beltoft-vm-usage__grid">
				<?php foreach ( array( 'cpu' => __( 'CPU', 'beltoft-services' ), 'mem' => __( 'Memory', 'beltoft-services' ), 'net' => __( 'Network', 'beltoft-services' ), 'io' => __( 'Disk activity', 'beltoft-services' ) ) as $beltoft_metric => $beltoft_title ) : ?>
					<div class="beltoft-vm-usage__chart" data-chart="<?php echo esc_attr( $beltoft_metric ); ?>">
						<h5><?php echo esc_html( $beltoft_title ); ?></h5>
						<div class="beltoft-vm-usage__plot" aria-label="<?php echo esc_attr( $beltoft_title ); ?>"></div>
						<p class="beltoft-vm-usage__readout" data-readout></p>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( $forms && is_array( $backups ) ) : ?>
		<div class="beltoft-services-vm__backups">
			<h4><?php esc_html_e( 'Backups', 'beltoft-services' ); ?></h4>
			<?php if ( ! $backups['enabled'] ) : ?>
				<p>
					<a class="button woocommerce-button" href="<?php echo esc_url( $backups['add_url'] ); ?>">
						<?php
						echo esc_html(
							'' === $backups['add_price']
								? __( 'Add daily backups', 'beltoft-services' )
								/* translators: %s: price difference such as +€12.00 / month */
								: sprintf( __( 'Add daily backups (%s)', 'beltoft-services' ), $backups['add_price'] )
						);
						?>
					</a>
				</p>
				<p class="description"><?php esc_html_e( 'A backup of the whole server is taken every night.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of days */
							_n( 'A backup is taken every night and kept for %d day.', 'A backup is taken every night and kept for %d days.', (int) $backups['days'], 'beltoft-services' ),
							(int) $backups['days']
						)
					);
					?>
				</p>
				<?php if ( array() === $backups['list'] ) : ?>
					<p><?php esc_html_e( 'The first backup will appear here after tonight\'s run.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<table class="beltoft-services-vm__backup-list">
						<thead>
							<tr><th scope="col"><?php esc_html_e( 'Backup', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Size', 'beltoft-services' ); ?></th></tr>
						</thead>
						<tbody>
							<?php foreach ( $backups['list'] as $beltoft_services_backup ) : ?>
								<tr><td><?php echo esc_html( $beltoft_services_backup['when'] ); ?></td><td><?php echo esc_html( $beltoft_services_backup['size'] ); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $backups['can_restore'] && ! $extra_disks['items'] ) : ?>
					<details class="beltoft-services-vm__more">
						<summary><?php esc_html_e( 'Restore from a backup', 'beltoft-services' ); ?></summary>
						<form class="beltoft-services-vm-form" data-action="restore">
							<p class="description"><?php esc_html_e( 'Restoring replaces everything on the server with the backup. The server is switched off while it is restored. The password and the SSH key go back to the ones the backup had. The IP address stays.', 'beltoft-services' ); ?></p>
							<p>
								<label for="beltoft-vm-backup"><?php esc_html_e( 'Backup', 'beltoft-services' ); ?></label>
								<select id="beltoft-vm-backup" name="volid">
									<?php foreach ( $backups['list'] as $beltoft_services_backup ) : ?>
										<option value="<?php echo esc_attr( $beltoft_services_backup['volid'] ); ?>"><?php echo esc_html( $beltoft_services_backup['when'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label for="beltoft-vm-restore-confirm">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: the word to type, RESTORE */
											__( 'Type %s to confirm', 'beltoft-services' ),
											'RESTORE'
										)
									);
									?>
								</label>
								<input type="text" id="beltoft-vm-restore-confirm" name="confirm" autocomplete="off" required>
							</p>
							<p><button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Restore now', 'beltoft-services' ); ?></button></p>
						</form>
					</details>
				<?php endif; ?>

				<?php if ( '' !== $backups['remove_url'] ) : ?>
					<p>
						<a href="<?php echo esc_url( $backups['remove_url'] ); ?>"><?php esc_html_e( 'Remove backups', 'beltoft-services' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'If you remove backups, all backups of this server are deleted.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $forms ) : ?>
		<?php if ( $can_reveal ) : ?>
			<p class="beltoft-services-vm__reveal">
				<button type="button" class="button woocommerce-button" data-vm-reveal><?php esc_html_e( 'Show password once', 'beltoft-services' ); ?></button>
				<code class="beltoft-services-vm__password" data-vm-password hidden></code>
			</p>
			<p class="description"><?php esc_html_e( 'The password is shown one time only. Copy it now and keep it safe.', 'beltoft-services' ); ?></p>
		<?php endif; ?>

		<details class="beltoft-services-vm__more">
			<summary><?php echo $has_key ? esc_html__( 'Replace SSH key', 'beltoft-services' ) : esc_html__( 'Add SSH key', 'beltoft-services' ); ?></summary>
			<form class="beltoft-services-vm-form" data-action="replace_ssh_key">
				<p>
					<label for="beltoft-vm-key"><?php esc_html_e( 'SSH public key', 'beltoft-services' ); ?></label>
					<textarea id="beltoft-vm-key" name="key" rows="3" maxlength="8192" autocomplete="off" spellcheck="false" required></textarea>
				</p>
				<p class="description"><?php esc_html_e( 'Paste the single line from your .pub file. It replaces the key now on the server.', 'beltoft-services' ); ?></p>
				<p><button type="submit" class="button woocommerce-button"><?php echo $has_key ? esc_html__( 'Replace SSH key', 'beltoft-services' ) : esc_html__( 'Add SSH key', 'beltoft-services' ); ?></button></p>
			</form>
		</details>

		<?php if ( ! $extra_disks['items'] ) : ?><details class="beltoft-services-vm__more">
			<summary><?php esc_html_e( 'Reinstall', 'beltoft-services' ); ?></summary>
			<form class="beltoft-services-vm-form" data-action="reinstall">
				<p class="description"><?php esc_html_e( 'Reinstalling erases everything on the server and installs the system again. The IP address stays. You get a new password.', 'beltoft-services' ); ?></p>
				<?php if ( $os_options ) : ?>
					<p>
						<label for="beltoft-vm-os"><?php esc_html_e( 'Operating system', 'beltoft-services' ); ?></label>
						<select id="beltoft-vm-os" name="os">
							<?php foreach ( $os_options as $beltoft_services_vmid => $beltoft_services_label ) : ?>
								<option value="<?php echo esc_attr( (string) $beltoft_services_vmid ); ?>" <?php selected( (int) $beltoft_services_vmid, (int) $os_current ); ?>><?php echo esc_html( $beltoft_services_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<p>
					<label for="beltoft-vm-confirm">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: the word to type, REINSTALL */
								__( 'Type %s to confirm', 'beltoft-services' ),
								'REINSTALL'
							)
						);
						?>
					</label>
					<input type="text" id="beltoft-vm-confirm" name="confirm" autocomplete="off" required>
				</p>
				<p><button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Reinstall now', 'beltoft-services' ); ?></button></p>
			</form>
		</details><?php endif; ?>
	<?php endif; ?>
</div>

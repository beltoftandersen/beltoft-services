<?php
/**
 * The virtual machine part of the service panel (My Account → Servers).
 *
 * On a server page (see BeltoftServices\Account\Servers) the card is split in tabs, each one a
 * link with its own address (overview, snapshots, backups, disks, access, reinstall). Every pane is
 * printed and the inactive ones are hidden, so the status poll and the forms keep working in any
 * tab, and without script the links still open the right tab. Elsewhere (a card on the Licenses tab)
 * the sections follow each other with no tabs.
 *
 * Variables (set by BeltoftServices\Proxmox\Panels::account()): $ip, $plan (cores, memory_mb,
 * disk_gb), $busy (a reinstall, restore or other work shows: Power then says Busy), $power (running, stopped or empty when not known), $os_label, $os_cluster, $os_options
 * (VMID => label), $os_current, $has_key, $can_reveal, $forms (whether actions are allowed now), $ssh_user,
 * $backups (null, or enabled, days, list of volid/when/size, last_ok, add_url, add_price, remove_url, can_restore),
 * $snapshots (null when the block is absent, or slots, used, included, added, packs (the packs the server has: slots, days, paused), buy (packs to add), full, days, free_days, paid_days, max_label, entries of id/label/taken/keeps/consistent/state),
 * $resource_id (the resource id, for ids that must be unique on a page),
 * $server_name (the name now set, or empty), $can_rename (whether the rename form is shown), $hostname_pending (a name the server has not taken yet, or empty),
 * $snapshot_warnings (reinstall and restore: the text to show when snapshots exist, else empty),
 * $page (null on a card, or the server page: license row and requested tab), $can_reset (whether Reset password is offered).
 * Each extra disk has id, ending (null, or date and undo_url once its subscription is ended), paid_until, snapshot_labels (the snapshots that include it), remove_url.
 * The password is never part of this template: the reveal button asks the server for it once.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_pending = (string) ( $hostname_pending ?? '' );
$beltoft_services_rename  = (bool) ( $can_rename ?? false );
$beltoft_services_named   = (string) ( $server_name ?? '' );
$beltoft_services_page    = is_array( $page ?? null ) ? $page : null;
$beltoft_services_reset   = (bool) ( $can_reset ?? false );

$beltoft_services_power_labels = array(
	'running' => __( 'Running', 'beltoft-services' ),
	'stopped' => __( 'Stopped', 'beltoft-services' ),
);

// The tabs of a server page: only the ones that have something to show.
$beltoft_services_tabs   = array();
$beltoft_services_active = 'overview';

if ( null !== $beltoft_services_page ) {
	$beltoft_services_tabs['overview'] = __( 'Overview', 'beltoft-services' );

	if ( is_array( $snapshots ) ) {
		$beltoft_services_tabs['snapshots'] = __( 'Snapshots', 'beltoft-services' );
	}

	if ( $forms && is_array( $backups ) ) {
		$beltoft_services_tabs['backups'] = __( 'Backups', 'beltoft-services' );
	}

	if ( $extra_disks['items'] || $extra_disks['add'] ) {
		$beltoft_services_tabs['disks'] = __( 'Disks', 'beltoft-services' );
	}

	if ( $forms ) {
		$beltoft_services_tabs['access']    = __( 'Access', 'beltoft-services' );
		$beltoft_services_tabs['reinstall'] = __( 'Reinstall', 'beltoft-services' );
	}

	/**
	 * Filters the tabs of a server page, slug => title, in the order they are shown. An extension adds its
	 * slug to beltoft_services_server_page_tabs too and prints its pane on beltoft_services_server_tab_pane.
	 *
	 * @param array<string, string> $tabs        Tabs.
	 * @param int                   $resource_id The server's resource id.
	 */
	$beltoft_services_tabs = (array) apply_filters( 'beltoft_services_server_tabs', $beltoft_services_tabs, (int) $resource_id );

	if ( isset( $beltoft_services_tabs[ (string) ( $beltoft_services_page['tab'] ?? '' ) ] ) ) {
		$beltoft_services_active = (string) $beltoft_services_page['tab'];
	}
}

// A pane of the page, opened and closed around a section. On a card they print nothing.
$beltoft_services_open  = static function ( string $slug ) use ( $beltoft_services_page, $beltoft_services_active, $resource_id ): void {
	if ( null === $beltoft_services_page ) {
		return;
	}
	?>
	<section class="beltoft-services-tab" id="beltoft-vm-tab-<?php echo esc_attr( $slug ); ?>-<?php echo (int) $resource_id; ?>" data-tab="<?php echo esc_attr( $slug ); ?>"<?php echo $slug === $beltoft_services_active ? '' : ' hidden'; ?>>
	<?php
};
$beltoft_services_close = static function () use ( $beltoft_services_page ): void {
	if ( null !== $beltoft_services_page ) {
		echo '</section>';
	}
};
// The address of a tab.
$beltoft_services_link  = static function ( string $slug ) use ( $beltoft_services_page ): string {
	return null === $beltoft_services_page ? '' : \BeltoftServices\Account\Servers::url( $beltoft_services_page['license'], 'overview' === $slug ? '' : $slug );
};
// A secondary block: a closed details on a card, a titled block on a page (the tab is already its own place).
$beltoft_services_more_open  = static function ( string $title ) use ( $beltoft_services_page ): void {
	echo null === $beltoft_services_page ? '<details class="beltoft-services-vm__more"><summary>' . esc_html( $title ) . '</summary>' : '<div class="beltoft-services-vm__more"><h4>' . esc_html( $title ) . '</h4>';
};
$beltoft_services_more_close = static function () use ( $beltoft_services_page ): void {
	echo null === $beltoft_services_page ? '</details>' : '</div>';
};
// The note that a new name is used after the next reboot.
$beltoft_services_note = static function () use ( $beltoft_services_pending, $forms, $power ): void {
	if ( '' === $beltoft_services_pending ) {
		return;
	}
	?>
		<p class="description" data-hostname-note>
			<?php esc_html_e( "The new name is used as the hostname after the next reboot. Your SSH client may warn that the server's host key changed.", 'beltoft-services' ); ?>
			<?php if ( $forms && 'stopped' !== $power ) : ?><button type="button" class="beltoft-services-action beltoft-services-vm__link" data-action="reboot"><?php esc_html_e( 'Reboot', 'beltoft-services' ); ?></button><?php endif; ?>
		</p>
	<?php
};
?>
<div class="beltoft-services-vm<?php echo null !== $beltoft_services_page ? ' beltoft-services-vm--page' : ''; ?>" data-power="<?php echo esc_attr( (string) $power ); ?>">
	<p class="beltoft-services-vm__flash" data-flash role="status" aria-live="polite" hidden></p>
	<?php if ( null !== $beltoft_services_page ) : ?>
		<?php $beltoft_services_note(); ?>
		<nav class="beltoft-services-tabs" aria-label="<?php esc_attr_e( 'Server sections', 'beltoft-services' ); ?>">
			<?php foreach ( $beltoft_services_tabs as $beltoft_services_slug => $beltoft_services_title ) : ?>
				<a href="<?php echo esc_url( $beltoft_services_link( $beltoft_services_slug ) ); ?>" data-tab-link="<?php echo esc_attr( $beltoft_services_slug ); ?>"<?php echo $beltoft_services_slug === $beltoft_services_active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $beltoft_services_title ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php $beltoft_services_open( 'overview' ); ?>
	<?php if ( null !== $beltoft_services_page ) : ?>
		<dl class="beltoft-services-facts">
			<?php if ( '' !== $ip ) : ?>
				<div><dt><?php esc_html_e( 'IP address', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( $ip ); ?></code></dd></div>
				<div><dt><?php esc_html_e( 'Log in', 'beltoft-services' ); ?></dt><dd><code>ssh <?php echo esc_html( $ssh_user ); ?>@<?php echo esc_html( $ip ); ?></code></dd></div>
			<?php endif; ?>
			<div>
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
			</div>
			<?php if ( '' !== $os_label ) : ?>
				<div><dt><?php esc_html_e( 'System', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $os_label ); ?></dd></div>
			<?php endif; ?>
		</dl>
	<?php else : ?>
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
			<?php if ( ! empty( $busy ) ) : ?>
				<dt><?php esc_html_e( 'Power', 'beltoft-services' ); ?></dt>
				<dd data-power-line><?php esc_html_e( 'Busy', 'beltoft-services' ); ?></dd>
			<?php elseif ( isset( $beltoft_services_power_labels[ $power ] ) ) : ?>
				<dt><?php esc_html_e( 'Power', 'beltoft-services' ); ?></dt>
				<dd data-power-line><?php echo esc_html( $beltoft_services_power_labels[ $power ] ); ?></dd>
			<?php endif; ?>
		</dl>
		<?php $beltoft_services_note(); ?>
	<?php endif; ?>

	<?php if ( $beltoft_services_rename ) : ?>
		<details class="beltoft-services-vm__more">
			<summary><?php esc_html_e( 'Rename', 'beltoft-services' ); ?></summary>
			<form class="beltoft-services-vm-form" data-action="rename">
				<p>
					<label for="beltoft-vm-name-<?php echo (int) $resource_id; ?>"><?php esc_html_e( 'Server name', 'beltoft-services' ); ?></label>
					<input type="text" id="beltoft-vm-name-<?php echo (int) $resource_id; ?>" name="name" value="<?php echo esc_attr( $beltoft_services_named ); ?>" maxlength="63" autocomplete="off" spellcheck="false" required>
				</p>
				<p class="description"><?php esc_html_e( "Used as the server's hostname, for example web-01. Lowercase letters, digits and hyphens. The change shows on the server after the next reboot.", 'beltoft-services' ); ?></p>
				<p><button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Save name', 'beltoft-services' ); ?></button></p>
			</form>
		</details>
	<?php endif; ?>

	<?php if ( $metrics ) : ?>
		<section class="beltoft-vm-usage" data-vm-metrics>
			<div class="beltoft-vm-usage__head">
				<h4><?php esc_html_e( 'Monitoring', 'beltoft-services' ); ?></h4>
				<div class="beltoft-vm-usage__ranges" role="group" aria-label="<?php esc_attr_e( 'Time range', 'beltoft-services' ); ?>">
					<button type="button" data-range="hour" aria-pressed="true"><?php esc_html_e( '1 hour', 'beltoft-services' ); ?></button>
					<button type="button" data-range="day" aria-pressed="false"><?php esc_html_e( '1 day', 'beltoft-services' ); ?></button>
					<button type="button" data-range="month" aria-pressed="false"><?php esc_html_e( '30 days', 'beltoft-services' ); ?></button>
				</div>
			</div>
			<p class="beltoft-vm-usage__notice" data-metrics-notice role="status" aria-live="polite"><?php esc_html_e( 'Loading usage…', 'beltoft-services' ); ?></p>
			<?php if ( ! empty( $usage_states ) || ! empty( $usage_alerts_shown ) ) : ?>
				<div class="beltoft-vm-usage__alerts">
					<?php foreach ( (array) ( $usage_states ?? array() ) as $beltoft_services_state ) : ?>
						<p class="beltoft-vm-usage__state"><?php echo esc_html( (string) $beltoft_services_state ); ?></p>
					<?php endforeach; ?>
					<?php if ( ! empty( $usage_alerts_shown ) ) : ?>
						<label class="beltoft-vm-usage__switch">
							<input type="checkbox" data-usage-alerts<?php echo ! empty( $usage_alerts_on ) ? ' checked' : ''; ?>>
							<span><?php esc_html_e( 'Email me when this server is short of disk or CPU', 'beltoft-services' ); ?></span>
						</label>
						<p class="beltoft-vm-usage__switch-note" data-usage-alerts-note role="status" aria-live="polite"></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="beltoft-vm-usage__grid">
				<?php foreach ( array( 'cpu' => __( 'CPU', 'beltoft-services' ), 'mem' => __( 'Memory', 'beltoft-services' ) ) as $beltoft_metric => $beltoft_title ) : ?>
					<div class="beltoft-vm-usage__chart" data-chart="<?php echo esc_attr( $beltoft_metric ); ?>">
						<div class="beltoft-vm-usage__top"><h5><?php echo esc_html( $beltoft_title ); ?></h5><span class="beltoft-vm-usage__now" data-now></span></div>
						<div class="beltoft-vm-usage__plot" aria-label="<?php echo esc_attr( $beltoft_title ); ?>"></div>
						<p class="beltoft-vm-usage__axis" data-axis></p>
						<p class="beltoft-vm-usage__readout" data-readout></p>
					</div>
				<?php endforeach; ?>
				<?php
				// The disk has no history: one reading from the server's guest agent every six hours, drawn as a bar.
				$beltoft_services_disk_pct = is_numeric( $disk_used ) && is_numeric( $disk_total ) && (int) $disk_total > 0 ? max( 0, min( 100, (int) round( (int) $disk_used / (int) $disk_total * 100 ) ) ) : null;
				?>
				<div class="beltoft-vm-usage__chart beltoft-vm-usage__chart--disk">
					<div class="beltoft-vm-usage__top">
						<h5><?php esc_html_e( 'Disk', 'beltoft-services' ); ?></h5>
						<?php if ( null !== $beltoft_services_disk_pct ) : ?><span class="beltoft-vm-usage__now"><b><?php echo (int) $beltoft_services_disk_pct; ?>%</b></span><?php endif; ?>
					</div>
					<?php if ( null !== $beltoft_services_disk_pct ) : ?>
						<div class="beltoft-vm-usage__bar" role="img" aria-label="<?php /* translators: %d: percent of the disk in use */ echo esc_attr( sprintf( __( 'Disk, %d percent used', 'beltoft-services' ), (int) $beltoft_services_disk_pct ) ); ?>"><i style="width:<?php echo (int) $beltoft_services_disk_pct; ?>%"></i></div>
					<?php endif; ?>
					<p class="beltoft-vm-usage__disk" data-disk-line>
						<?php if ( null !== $beltoft_services_disk_pct ) : ?>
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
					<?php if ( null !== $beltoft_services_disk_pct ) : ?><p class="beltoft-vm-usage__axis"><?php esc_html_e( 'Read every 6 hours', 'beltoft-services' ); ?></p><?php endif; ?>
				</div>
			</div>
			<details class="beltoft-vm-usage__more">
				<summary><?php esc_html_e( 'Network and disk activity', 'beltoft-services' ); ?></summary>
				<div class="beltoft-vm-usage__grid">
					<?php foreach ( array( 'net' => __( 'Network', 'beltoft-services' ), 'io' => __( 'Disk activity', 'beltoft-services' ) ) as $beltoft_metric => $beltoft_title ) : ?>
						<div class="beltoft-vm-usage__chart" data-chart="<?php echo esc_attr( $beltoft_metric ); ?>">
							<div class="beltoft-vm-usage__top"><h5><?php echo esc_html( $beltoft_title ); ?></h5><span class="beltoft-vm-usage__now" data-now></span></div>
							<div class="beltoft-vm-usage__plot" aria-label="<?php echo esc_attr( $beltoft_title ); ?>"></div>
							<p class="beltoft-vm-usage__axis" data-axis></p>
							<p class="beltoft-vm-usage__readout" data-readout></p>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
		</section>
	<?php endif; ?>

	<?php if ( null !== $beltoft_services_page && count( $beltoft_services_tabs ) > 1 ) : ?>
		<div class="beltoft-services-glance">
			<?php if ( isset( $beltoft_services_tabs['snapshots'] ) ) : ?>
				<a class="beltoft-services-glance__item" href="<?php echo esc_url( $beltoft_services_link( 'snapshots' ) ); ?>" data-tab-link="snapshots">
					<span class="beltoft-services-glance__label"><?php esc_html_e( 'Snapshots', 'beltoft-services' ); ?></span>
					<?php
					$beltoft_services_strip = array( 'used' => (int) $snapshots['used'], 'slots' => (int) $snapshots['slots'] );
					include BELTOFT_SERVICES_PATH . 'templates/account/slots.php';
					?>
					<span class="beltoft-services-glance__value">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: snapshots in use, 2: snapshots allowed */
								__( '%1$d of %2$d used', 'beltoft-services' ),
								(int) $snapshots['used'],
								(int) $snapshots['slots']
							)
						);
						?>
					</span>
				</a>
			<?php endif; ?>
			<?php if ( isset( $beltoft_services_tabs['backups'] ) ) : ?>
				<a class="beltoft-services-glance__item" href="<?php echo esc_url( $beltoft_services_link( 'backups' ) ); ?>" data-tab-link="backups">
					<span class="beltoft-services-glance__label"><?php esc_html_e( 'Backups', 'beltoft-services' ); ?></span>
					<span class="beltoft-services-glance__value">
						<?php
						echo esc_html(
							$backups['enabled']
								/* translators: %d: number of days */
								? sprintf( _n( 'Every night, kept %d day', 'Every night, kept %d days', (int) $backups['days'], 'beltoft-services' ), (int) $backups['days'] )
								: __( 'Not added', 'beltoft-services' )
						);
						?>
					</span>
				</a>
			<?php endif; ?>
			<?php if ( isset( $beltoft_services_tabs['disks'] ) ) : ?>
				<a class="beltoft-services-glance__item" href="<?php echo esc_url( $beltoft_services_link( 'disks' ) ); ?>" data-tab-link="disks">
					<span class="beltoft-services-glance__label"><?php esc_html_e( 'Disks', 'beltoft-services' ); ?></span>
					<span class="beltoft-services-glance__value">
						<?php
						echo esc_html(
							$extra_disks['items']
								/* translators: %d: number of extra disks */
								? sprintf( _n( '%d extra disk', '%d extra disks', count( $extra_disks['items'] ), 'beltoft-services' ), count( $extra_disks['items'] ) )
								: __( 'No extra disks', 'beltoft-services' )
						);
						?>
					</span>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php $beltoft_services_close(); ?>

	<?php if ( null === $beltoft_services_page || isset( $beltoft_services_tabs['disks'] ) ) : ?>
		<?php $beltoft_services_open( 'disks' ); ?>
	<section class="beltoft-services-vm__disks">
		<h4><?php esc_html_e( 'Disks', 'beltoft-services' ); ?></h4>
		<dl class="beltoft-services-vm__facts">
			<dt><?php esc_html_e( 'Boot disk', 'beltoft-services' ); ?></dt>
			<dd><?php echo esc_html( (int) $plan['disk_gb'] . ' GB' ); ?></dd>
		</dl>
		<?php foreach ( $extra_disks['items'] as $beltoft_extra_disk ) : ?>
			<div class="beltoft-services-vm__disk">
				<p><strong><?php echo esc_html( $beltoft_extra_disk['name'] . ': ' . $beltoft_extra_disk['size'] . ' GB' ); ?></strong> <?php echo esc_html( $beltoft_extra_disk['state'] ); ?></p>
				<?php if ( ! empty( $beltoft_extra_disk['paused'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'This disk is detached from your server because its subscription is not active. Your data is kept until the disk is deleted.', 'beltoft-services' ); ?></p>
				<?php elseif ( ! empty( $beltoft_extra_disk['guide'] ) ) : ?>
					<div class="beltoft-services-disk">
						<?php
						// The setup guide, with the anchor that the emails and the thank-you page link to.
						$guide        = $beltoft_extra_disk['guide'];
						$guide_id     = $beltoft_extra_disk['guide_id'];
						$needs_reboot = ! empty( $beltoft_extra_disk['needs_reboot'] );
						include BELTOFT_SERVICES_PATH . 'templates/account/disk-guide.php';
						?>
					</div>
				<?php endif; ?>
				<?php if ( $beltoft_extra_disk['grow_url'] ) : ?><a href="<?php echo esc_url( $beltoft_extra_disk['grow_url'] ); ?>"><?php esc_html_e( 'Make larger', 'beltoft-services' ); ?><?php if ( $beltoft_extra_disk['grow_price'] ) : ?> (<?php echo esc_html( $beltoft_extra_disk['grow_price'] ); ?>)<?php endif; ?></a><?php endif; ?>
				<?php if ( $beltoft_extra_disk['remove_url'] ) : ?><button type="button" class="beltoft-services-vm__link" data-confirm-open="beltoft-vm-disk-confirm-<?php echo (int) $beltoft_extra_disk['id']; ?>" data-cancel-url="<?php echo esc_url( $beltoft_extra_disk['remove_url'] ); ?>"><?php esc_html_e( 'Remove', 'beltoft-services' ); ?></button><?php endif; ?>
				<?php if ( ( $beltoft_extra_disk['remove_url'] || ( $beltoft_extra_disk['ending'] && '' !== $beltoft_extra_disk['ending']['undo_url'] ) ) && '' !== $beltoft_extra_disk['manage_url'] ) : ?>
					<noscript><p class="description"><?php esc_html_e( 'To remove this disk or to undo it, use', 'beltoft-services' ); ?> <a href="<?php echo esc_url( $beltoft_extra_disk['manage_url'] ); ?>"><?php esc_html_e( 'Manage subscription', 'beltoft-services' ); ?></a>.</p></noscript>
				<?php endif; ?>
				<?php if ( $beltoft_extra_disk['ending'] ) : ?>
					<p class="beltoft-services-vm__ending" data-disk-ending>
						<?php
						echo esc_html(
							'' === $beltoft_extra_disk['ending']['date']
								? __( 'The extra disk will be removed when its paid period ends.', 'beltoft-services' )
								/* translators: %s: date the extra disk is removed */
								: sprintf( __( 'The extra disk will be removed on %s.', 'beltoft-services' ), $beltoft_extra_disk['ending']['date'] )
						);
						?>
						<?php if ( '' !== $beltoft_extra_disk['ending']['undo_url'] ) : ?><button type="button" class="beltoft-services-vm__link" data-undo-url="<?php echo esc_url( $beltoft_extra_disk['ending']['undo_url'] ); ?>"><?php esc_html_e( 'Undo', 'beltoft-services' ); ?></button><?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( $beltoft_extra_disk['remove_url'] ) : ?>
					<div hidden id="beltoft-vm-disk-confirm-<?php echo (int) $beltoft_extra_disk['id']; ?>" data-confirm-source data-confirm-title="<?php esc_attr_e( 'Remove this extra disk?', 'beltoft-services' ); ?>" data-confirm-yes="<?php esc_attr_e( 'Remove the disk', 'beltoft-services' ); ?>">
						<p>
							<?php
							echo esc_html(
								'' === $beltoft_extra_disk['paid_until']
									? __( 'The disk stays on your server until its paid period ends.', 'beltoft-services' )
									/* translators: %s: date the paid period ends */
									: sprintf( __( 'The disk stays on your server until its paid period ends on %s.', 'beltoft-services' ), $beltoft_extra_disk['paid_until'] )
							);
							?>
						</p>
						<p>
							<?php
							$beltoft_remove_days = (int) $beltoft_extra_disk['retention_days'];
							if ( 'on' === $extra_disks['mode'] && $beltoft_remove_days > 0 ) {
								/* translators: %d: days after the paid period ends */
								echo esc_html( sprintf( _n( 'After that the disk is detached from your server. The disk and its data are deleted %d day later.', 'After that the disk is detached from your server. The disk and its data are deleted %d days later.', $beltoft_remove_days, 'beltoft-services' ), $beltoft_remove_days ) );
							} elseif ( 'on' === $extra_disks['mode'] ) {
								esc_html_e( 'After that the disk is detached from your server, and the disk and its data are deleted.', 'beltoft-services' );
							} else {
								esc_html_e( 'After that the disk is detached from your server. Its data is kept until we remove the disk.', 'beltoft-services' );
							}
							?>
						</p>
						<?php if ( $beltoft_extra_disk['snapshot_labels'] ) : ?>
							<p><?php esc_html_e( 'The snapshots that include this disk are deleted with it:', 'beltoft-services' ); ?></p>
							<ul>
								<?php foreach ( $beltoft_extra_disk['snapshot_labels'] as $beltoft_snapshot_label ) : ?>
									<li><?php echo esc_html( $beltoft_snapshot_label ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p>
							<?php
							echo esc_html(
								'' === $beltoft_extra_disk['paid_until']
									? __( 'You can undo this until then.', 'beltoft-services' )
									/* translators: %s: date the paid period ends */
									: sprintf( __( 'You can undo this until %s.', 'beltoft-services' ), $beltoft_extra_disk['paid_until'] )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
				<?php if ( $beltoft_extra_disk['remove_url'] && ! empty( $beltoft_extra_disk['snapshots_covering'] ) ) : ?>
					<p class="description" data-snapshot-disk-warning>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of snapshots that include the disk */
								_n( 'Removing this disk also deletes the %d snapshot that includes it.', 'Removing this disk also deletes the %d snapshots that include it.', (int) $beltoft_extra_disk['snapshots_covering'], 'beltoft-services' ),
								(int) $beltoft_extra_disk['snapshots_covering']
							)
						);
						?>
					</p>
				<?php endif; ?>
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
		<?php endif; ?>
		<?php if ( $extra_disks['add'] ) : ?>
			<div class="beltoft-services-add" data-disk-add>
				<h5 class="beltoft-services-add__title"><?php esc_html_e( 'Add a disk', 'beltoft-services' ); ?></h5>
				<div class="beltoft-services-add__grid">
				<?php foreach ( $extra_disks['add'] as $beltoft_disk_size ) : ?>
					<a class="beltoft-services-add__tile" href="<?php echo esc_url( $beltoft_disk_size['url'] ); ?>">
						<span class="beltoft-services-add__name"><?php echo esc_html( $beltoft_disk_size['size'] . ' GB' ); ?></span>
						<span class="beltoft-services-add__price"><?php /* translators: %s: price */ echo esc_html( sprintf( __( '%s excluding VAT', 'beltoft-services' ), $beltoft_disk_size['price'] ) ); ?></span>
					</a>
				<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>
	</section>
		<?php $beltoft_services_close(); ?>
	<?php endif; ?>

	<?php if ( null === $beltoft_services_page || isset( $beltoft_services_tabs['backups'] ) ) : ?>
		<?php $beltoft_services_open( 'backups' ); ?>
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

				<?php if ( $backups['can_restore'] ) : ?>
					<details class="beltoft-services-vm__more">
						<summary><?php esc_html_e( 'Restore from a backup', 'beltoft-services' ); ?></summary>
						<form class="beltoft-services-vm-form" data-action="restore"<?php echo $extra_disks['items'] ? ' data-disks="1"' : ''; ?>>
							<p class="description"><?php esc_html_e( 'Restoring replaces everything on the server with the backup. The server is switched off while it is restored. The password and the SSH key go back to the ones the backup had. The IP address stays.', 'beltoft-services' ); ?></p>
							<?php if ( '' !== $snapshot_warnings['restore'] ) : ?><p class="description" data-snapshot-warning><?php echo esc_html( $snapshot_warnings['restore'] ); ?></p><?php endif; ?>
							<?php if ( $extra_disks['items'] ) : ?><p class="description"><?php esc_html_e( 'Extra disks are not part of the backup: they keep their current data and are attached again after the restore.', 'beltoft-services' ); ?></p><?php endif; ?>
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
							<div hidden data-confirm-source data-confirm-title="<?php esc_attr_e( 'Restore this backup?', 'beltoft-services' ); ?>" data-confirm-yes="<?php esc_attr_e( 'Restore now', 'beltoft-services' ); ?>">
								<p><?php esc_html_e( 'Everything on the server is replaced with the backup. The server is off while it is restored.', 'beltoft-services' ); ?></p>
								<p><?php esc_html_e( 'The password and the SSH key go back to the ones the backup had. The IP address stays.', 'beltoft-services' ); ?></p>
								<?php if ( '' !== $snapshot_warnings['restore'] ) : ?><p><?php esc_html_e( 'The snapshots of this server are deleted.', 'beltoft-services' ); ?></p><?php endif; ?>
								<?php if ( $extra_disks['items'] ) : ?><p><?php esc_html_e( 'Extra disks are not part of the backup. They keep their current data and are attached again after the restore.', 'beltoft-services' ); ?></p><?php endif; ?>
							</div>
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
		<?php $beltoft_services_close(); ?>
	<?php endif; ?>

	<?php if ( null === $beltoft_services_page || isset( $beltoft_services_tabs['snapshots'] ) ) : ?>
		<?php $beltoft_services_open( 'snapshots' ); ?>
	<?php if ( is_array( $snapshots ) ) : ?>
		<div class="beltoft-services-vm__backups beltoft-services-vm__snapshots" data-snapshots>
			<h4><?php esc_html_e( 'Snapshots', 'beltoft-services' ); ?></h4>
			<?php
			// The slots as squares, used ones filled.
			$beltoft_services_strip = array( 'used' => (int) $snapshots['used'], 'slots' => (int) $snapshots['slots'] );
			include BELTOFT_SERVICES_PATH . 'templates/account/slots.php';
			?>
			<p class="description" data-snapshot-slots>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: snapshots in use, 2: snapshots allowed */
						__( '%1$d of %2$d used', 'beltoft-services' ),
						(int) $snapshots['used'],
						(int) $snapshots['slots']
					)
				);
				if ( $snapshots['added'] > 0 ) {
					echo esc_html(
						' ' . sprintf(
							/* translators: 1: free snapshot slots, 2: snapshot slots added by a subscription */
							__( '(%1$d included, %2$d added)', 'beltoft-services' ),
							(int) $snapshots['included'],
							(int) $snapshots['added']
						)
					);
				}
				?>
			</p>
			<?php foreach ( $snapshots['packs'] as $beltoft_services_pack_card ) : ?>
				<?php
				// A snapshot pack of this server: its slots, and a note when it is paused.
				$slots      = $beltoft_services_pack_card['slots'];
				$days       = $beltoft_services_pack_card['days'];
				$paused     = $beltoft_services_pack_card['paused'];
				$server_url = '';
				include BELTOFT_SERVICES_PATH . 'templates/account/snapshot.php';
				?>
			<?php endforeach; ?>
			<?php if ( $snapshots['buy'] ) : ?>
				<div class="beltoft-services-add" data-snapshot-add>
					<h5 class="beltoft-services-add__title"><?php esc_html_e( 'Need more snapshots?', 'beltoft-services' ); ?></h5>
					<div class="beltoft-services-add__grid">
					<?php foreach ( $snapshots['buy'] as $beltoft_services_pack ) : ?>
						<a class="beltoft-services-add__tile" href="<?php echo esc_url( $beltoft_services_pack['url'] ); ?>">
							<span class="beltoft-services-add__name"><?php /* translators: %d: snapshot slots in the pack */ echo esc_html( sprintf( _n( '+%d snapshot', '+%d snapshots', (int) $beltoft_services_pack['slots'], 'beltoft-services' ), (int) $beltoft_services_pack['slots'] ) ); ?></span>
							<span class="beltoft-services-add__price"><?php /* translators: %s: price */ echo esc_html( sprintf( __( '%s excluding VAT', 'beltoft-services' ), $beltoft_services_pack['price'] ) ); ?></span>
						</a>
					<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( array() === $snapshots['entries'] ) : ?>
				<p><?php esc_html_e( 'You have no snapshots yet.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<?php foreach ( $snapshots['entries'] as $beltoft_services_snapshot ) : ?>
					<div class="beltoft-services-vm__disk beltoft-services-vm__snapshot" data-snapshot="<?php echo esc_attr( $beltoft_services_snapshot['id'] ); ?>">
						<dl class="beltoft-services-vm__facts">
							<dt><?php esc_html_e( 'Snapshot', 'beltoft-services' ); ?></dt>
							<dd><?php echo esc_html( $beltoft_services_snapshot['label'] ); ?></dd>
							<dt><?php esc_html_e( 'Taken', 'beltoft-services' ); ?></dt>
							<dd>
								<?php
								if ( 'creating' === $beltoft_services_snapshot['state'] ) {
									$beltoft_services_how = __( 'being taken', 'beltoft-services' );
								} elseif ( 'deleting' === $beltoft_services_snapshot['state'] ) {
									$beltoft_services_how = __( 'being deleted', 'beltoft-services' );
								} elseif ( $beltoft_services_snapshot['consistent'] ) {
									$beltoft_services_how = __( 'consistent', 'beltoft-services' );
								} else {
									$beltoft_services_how = __( 'taken without the guest agent', 'beltoft-services' );
								}

								echo esc_html( $beltoft_services_snapshot['taken'] . ', ' . $beltoft_services_how );
								?>
							</dd>
							<dt><?php esc_html_e( 'Kept until', 'beltoft-services' ); ?></dt>
							<dd><?php echo esc_html( $beltoft_services_snapshot['keeps'] ); ?></dd>
						</dl>
						<?php if ( 'ok' === $beltoft_services_snapshot['state'] && in_array( $snapshots['mode'], array( 'full', 'delete' ), true ) ) : ?>
							<p class="beltoft-services-panel__actions">
								<?php if ( 'full' === $snapshots['mode'] ) : ?>
								<button type="button" class="button woocommerce-button" data-snapshot-rollback="<?php echo esc_attr( $beltoft_services_snapshot['id'] ); ?>" data-snapshot-name="<?php echo esc_attr( '' !== $beltoft_services_snapshot['label'] ? $beltoft_services_snapshot['label'] : $beltoft_services_snapshot['taken'] ); ?>"><?php esc_html_e( 'Go back to this snapshot', 'beltoft-services' ); ?></button>
								<?php endif; ?>
								<button type="button" class="button woocommerce-button" data-snapshot-delete="<?php echo esc_attr( $beltoft_services_snapshot['id'] ); ?>" data-snapshot-name="<?php echo esc_attr( '' !== $beltoft_services_snapshot['label'] ? $beltoft_services_snapshot['label'] : $beltoft_services_snapshot['taken'] ); ?>"><?php esc_html_e( 'Delete', 'beltoft-services' ); ?></button>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( 'working' === $snapshots['mode'] ) : ?>
				<p data-snapshot-working><?php esc_html_e( 'Working on your server, snapshot actions are back when it is done.', 'beltoft-services' ); ?></p>
			<?php elseif ( 'inactive' === $snapshots['mode'] ) : ?>
				<p data-snapshot-inactive><?php esc_html_e( 'Snapshot actions are available again when the server is active.', 'beltoft-services' ); ?></p>
			<?php elseif ( 'full' !== $snapshots['mode'] ) : ?>
				<?php // Not available now (delete only) or read only: the list, no take form. ?>
			<?php elseif ( $snapshots['full'] ) : ?>
				<p data-snapshot-full><?php esc_html_e( 'All your snapshot slots are in use. Delete a snapshot first.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<form class="beltoft-services-vm-form" data-action="snapshot_take">
					<p>
						<label for="beltoft-vm-snapshot-label-<?php echo (int) $resource_id; ?>"><?php esc_html_e( 'Name (optional)', 'beltoft-services' ); ?></label>
						<input type="text" id="beltoft-vm-snapshot-label-<?php echo (int) $resource_id; ?>" name="label" maxlength="<?php echo (int) $snapshots['max_label']; ?>" autocomplete="off">
					</p>
					<p><button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Take a snapshot', 'beltoft-services' ); ?></button></p>
				</form>
			<?php endif; ?>

			<?php if ( 'full' === $snapshots['mode'] ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: days a free snapshot is kept */
						_n(
							'Includes every disk. The server is off for a moment while it goes back. The password and SSH key go back to the ones saved. Free snapshots are kept %d day.',
							'Includes every disk. The server is off for a moment while it goes back. The password and SSH key go back to the ones saved. Free snapshots are kept %d days.',
							(int) $snapshots['free_days'],
							'beltoft-services'
						),
						(int) $snapshots['free_days']
					)
				);
				if ( $snapshots['buy'] || $snapshots['added'] > 0 ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %d: days a snapshot is kept while the server has added slots */
							_n( 'With added slots, snapshots are kept %d day.', 'With added slots, snapshots are kept %d days.', (int) $snapshots['paid_days'], 'beltoft-services' ),
							(int) $snapshots['paid_days']
						)
					);
				}
				?>
			</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
		<?php $beltoft_services_close(); ?>
	<?php endif; ?>

	<?php if ( null === $beltoft_services_page || isset( $beltoft_services_tabs['access'] ) ) : ?>
		<?php $beltoft_services_open( 'access' ); ?>
		<?php if ( $forms ) : ?>
		<?php if ( $can_reveal ) : ?>
			<p class="beltoft-services-vm__reveal">
				<button type="button" class="button woocommerce-button" data-vm-reveal><?php esc_html_e( 'Show password once', 'beltoft-services' ); ?></button>
				<code class="beltoft-services-vm__password" data-vm-password hidden></code>
			</p>
			<p class="description"><?php esc_html_e( 'The password is shown one time only. Copy it now and keep it safe.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
			<?php if ( $beltoft_services_reset && null !== $beltoft_services_page ) : ?>
				<div class="beltoft-services-vm__reset">
					<p><button type="button" class="button woocommerce-button beltoft-services-action" data-action="reset_password"><?php esc_html_e( 'Reset password', 'beltoft-services' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'Sets a new password on the server. Use Show password once to see it.', 'beltoft-services' ); ?></p>
				</div>
			<?php endif; ?>

		<?php $beltoft_services_more_open( $has_key ? __( 'Replace SSH key', 'beltoft-services' ) : __( 'Add SSH key', 'beltoft-services' ) ); ?>
			<form class="beltoft-services-vm-form" data-action="replace_ssh_key">
				<p>
					<label for="beltoft-vm-key"><?php esc_html_e( 'SSH public key', 'beltoft-services' ); ?></label>
					<textarea id="beltoft-vm-key" name="key" rows="3" maxlength="8192" autocomplete="off" spellcheck="false" required></textarea>
				</p>
				<p class="description"><?php esc_html_e( 'Paste the single line from your .pub file. It replaces the key now on the server.', 'beltoft-services' ); ?></p>
				<p><button type="submit" class="button woocommerce-button"><?php echo $has_key ? esc_html__( 'Replace SSH key', 'beltoft-services' ) : esc_html__( 'Add SSH key', 'beltoft-services' ); ?></button></p>
			</form>
		<?php $beltoft_services_more_close(); ?>
		<?php endif; ?>
		<?php $beltoft_services_close(); ?>
	<?php endif; ?>

	<?php if ( null === $beltoft_services_page || isset( $beltoft_services_tabs['reinstall'] ) ) : ?>
		<?php $beltoft_services_open( 'reinstall' ); ?>
		<?php if ( $forms ) : ?>
		<?php $beltoft_services_more_open( __( 'Reinstall', 'beltoft-services' ) ); ?>
			<form class="beltoft-services-vm-form" data-action="reinstall"<?php echo $extra_disks['items'] ? ' data-disks="1"' : ''; ?>>
				<?php if ( $extra_disks['items'] ) : ?>
					<p class="description"><?php esc_html_e( 'Reinstalling erases the server\'s main disk and installs the system again. Extra disks are kept with their data and stay attached. The IP address stays. You get a new password.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Reinstalling erases everything on the server and installs the system again. The IP address stays. You get a new password.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $snapshot_warnings['reinstall'] ) : ?><p class="description" data-snapshot-warning><?php echo esc_html( $snapshot_warnings['reinstall'] ); ?></p><?php endif; ?>
				<?php if ( $os_options ) : ?>
					<div class="beltoft-services-vm-os"><?php \BeltoftServices\Proxmox\Tiles::print_os( (string) $os_cluster, $os_options, (int) $os_current, 'os', 'beltoft-vm-os' ); ?></div>
				<?php endif; ?>
				<?php
				/**
				 * Extra fields of the reinstall form (the app choice).
				 *
				 * @param int $resource_id The server's resource id.
				 */
				do_action( 'beltoft_services_reinstall_fields', (int) $resource_id );
				?>
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
				<div hidden data-confirm-source data-confirm-title="<?php esc_attr_e( 'Reinstall your server?', 'beltoft-services' ); ?>" data-confirm-yes="<?php esc_attr_e( 'Reinstall now', 'beltoft-services' ); ?>">
					<?php $beltoft_services_lost = is_array( $snapshots ) ? (int) $snapshots['used'] : 0; ?>
					<p><?php echo $extra_disks['items'] ? esc_html__( 'The main disk of the server is erased and the system is installed again. Everything on it is lost. Extra disks keep their data and stay attached.', 'beltoft-services' ) : esc_html__( 'Everything on the server is erased and the system is installed again. All data on it is lost.', 'beltoft-services' ); ?></p>
					<?php if ( $beltoft_services_lost > 0 ) : ?>
						<?php /* translators: %d: number of snapshots */ ?>
						<p><?php echo esc_html( 1 === $beltoft_services_lost ? __( 'Your snapshot is deleted first.', 'beltoft-services' ) : sprintf( __( 'Your %d snapshots are deleted first.', 'beltoft-services' ), $beltoft_services_lost ) ); ?></p>
					<?php endif; ?>
					<p><?php echo $has_key ? esc_html__( 'You get a new password. Your SSH key stays on the server.', 'beltoft-services' ) : esc_html__( 'You get a new password.', 'beltoft-services' ); ?></p>
					<p><?php esc_html_e( 'The IP address stays.', 'beltoft-services' ); ?></p>
					<?php do_action( 'beltoft_services_reinstall_confirm', (int) $resource_id ); ?>
				</div>
				<p><button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Reinstall now', 'beltoft-services' ); ?></button></p>
			</form>
		<?php $beltoft_services_more_close(); ?>
		<?php endif; ?>
		<?php $beltoft_services_close(); ?>
	<?php endif; ?>

	<?php if ( null !== $beltoft_services_page ) : ?>
		<?php foreach ( array_diff( array_keys( $beltoft_services_tabs ), array( 'overview', 'snapshots', 'backups', 'disks', 'access', 'reinstall' ) ) as $beltoft_services_extra ) : ?>
			<?php $beltoft_services_open( (string) $beltoft_services_extra ); ?>
			<?php
			/**
			 * Prints the pane of a tab an extension added to the server page.
			 *
			 * @param string $slug        Tab slug.
			 * @param int    $resource_id The server's resource id.
			 */
			do_action( 'beltoft_services_server_tab_pane', (string) $beltoft_services_extra, (int) $resource_id );
			?>
			<?php $beltoft_services_close(); ?>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( is_array( $snapshots ) && 'full' === $snapshots['mode'] ) : ?>
	<dialog class="beltoft-services-vm__dialog" data-snapshot-dialog aria-labelledby="beltoft-vm-rollback-title-<?php echo (int) $resource_id; ?>">
		<form class="beltoft-services-vm-form" data-action="snapshot_rollback">
			<h5 id="beltoft-vm-rollback-title-<?php echo (int) $resource_id; ?>"><?php esc_html_e( 'Go back to this snapshot', 'beltoft-services' ); ?></h5>
			<input type="hidden" name="id" value="">
			<p class="description"><strong data-snapshot-dialog-name></strong></p>
			<p class="description"><?php esc_html_e( 'Going back replaces everything on the server with the snapshot, extra disks included. The server is off for a moment while it goes back. The password and the SSH key go back to the ones saved. Anything changed since is lost.', 'beltoft-services' ); ?></p>
			<p>
				<label for="beltoft-vm-rollback-confirm-<?php echo (int) $resource_id; ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the word to type, ROLLBACK */
							__( 'Type %s to confirm', 'beltoft-services' ),
							'ROLLBACK'
						)
					);
					?>
				</label>
				<input type="text" id="beltoft-vm-rollback-confirm-<?php echo (int) $resource_id; ?>" name="confirm" autocomplete="off" required>
			</p>
			<p class="beltoft-services-panel__notice" data-dialog-notice role="status" aria-live="polite"></p>
			<p class="beltoft-services-vm__buttons">
				<button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Go back now', 'beltoft-services' ); ?></button>
				<button type="button" class="button woocommerce-button" data-snapshot-cancel><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
			</p>
		</form>
	</dialog>
	<?php endif; ?>

	<?php if ( $forms || array_filter( array_column( $extra_disks['items'], 'remove_url' ) ) ) : ?>
	<dialog class="beltoft-services-vm__dialog" data-confirm-dialog aria-labelledby="beltoft-vm-confirm-title-<?php echo (int) $resource_id; ?>">
		<form class="beltoft-services-vm-form" data-action="confirm">
			<h5 id="beltoft-vm-confirm-title-<?php echo (int) $resource_id; ?>" data-confirm-heading></h5>
			<div class="beltoft-services-vm__confirm-body" data-confirm-body></div>
			<p class="beltoft-services-panel__notice" data-dialog-notice role="status" aria-live="polite"></p>
			<p class="beltoft-services-vm__buttons">
				<button type="submit" class="button woocommerce-button" data-confirm-submit></button>
				<button type="button" class="button woocommerce-button" data-confirm-cancel><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
			</p>
		</form>
	</dialog>
	<?php endif; ?>
</div>

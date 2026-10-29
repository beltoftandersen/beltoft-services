<?php
/**
 * The Firewall tab of a server page. Printed by BeltoftServices\Proxmox\FirewallTab::pane().
 *
 * Variables: $r (the resource) and $view (Firewall::view(): fixed lines, max, app). The rules and the state are
 * filled in by assets/firewall.js from the firewall route; this shell holds the fixed text and the dialog.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_fw_id = (int) $r->id();
?>
<div class="beltoft-services-fw" data-fw data-resource="<?php echo (int) $beltoft_services_fw_id; ?>" data-fw-state="loading">
	<h4><?php esc_html_e( 'Firewall', 'beltoft-services' ); ?></h4>
	<p class="description"><?php esc_html_e( 'Choose which ports can be reached on your server. Changes take effect within a minute and need no SSH.', 'beltoft-services' ); ?></p>
	<p class="beltoft-services-fw__status" data-fw-status role="status" aria-live="polite"><?php esc_html_e( 'Loading…', 'beltoft-services' ); ?></p>

	<div class="beltoft-services-fw__start" data-fw-start hidden>
		<p data-fw-live></p>
		<p class="description"><?php esc_html_e( 'Choose how to start. You can change everything afterwards.', 'beltoft-services' ); ?></p>
		<div class="beltoft-services-fw__choices">
			<div>
				<button type="button" class="button woocommerce-button" data-fw-start-standard><?php esc_html_e( 'Use the standard rules', 'beltoft-services' ); ?></button>
				<p class="description"><?php esc_html_e( 'SSH (22), HTTP (80), HTTPS (443) and ping are open to everyone. Everything else is closed.', 'beltoft-services' ); ?></p>
			</div>
			<div>
				<button type="button" class="button woocommerce-button" data-fw-start-open><?php esc_html_e( 'Keep everything open', 'beltoft-services' ); ?></button>
				<p class="description"><?php esc_html_e( 'Nothing changes on your server. You can close ports later.', 'beltoft-services' ); ?></p>
			</div>
		</div>
	</div>

	<div class="beltoft-services-fw__main" data-fw-main hidden>
		<p class="beltoft-services-fw__switch">
			<strong data-fw-switch-label></strong>
			<button type="button" class="button woocommerce-button" data-fw-switch></button>
		</p>
		<p class="beltoft-services-fw__warning" data-fw-off-warning hidden><?php esc_html_e( 'Every port on your server can be reached from the internet.', 'beltoft-services' ); ?></p>

		<div class="beltoft-services-fw__table" data-fw-table role="table" aria-label="<?php esc_attr_e( 'Open ports', 'beltoft-services' ); ?>">
			<div class="beltoft-services-fw__row beltoft-services-fw__row--head" role="row">
				<span role="columnheader"><?php esc_html_e( 'What', 'beltoft-services' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Port', 'beltoft-services' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Who can connect', 'beltoft-services' ); ?></span>
				<span role="columnheader"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'beltoft-services' ); ?></span></span>
			</div>
			<div data-fw-rows></div>
		</div>

		<div class="beltoft-services-fw__add">
			<h5><?php esc_html_e( 'Add a rule', 'beltoft-services' ); ?></h5>
			<p class="beltoft-services-fw__presets">
				<button type="button" class="beltoft-services-fw__link" data-fw-preset="ssh"><?php esc_html_e( 'SSH', 'beltoft-services' ); ?></button>
				<button type="button" class="beltoft-services-fw__link" data-fw-preset="web"><?php esc_html_e( 'Web', 'beltoft-services' ); ?></button>
				<button type="button" class="beltoft-services-fw__link" data-fw-preset="ping"><?php esc_html_e( 'Ping', 'beltoft-services' ); ?></button>
			</p>
			<form class="beltoft-services-fw__form" data-fw-form novalidate>
				<p>
					<label for="beltoft-fw-proto-<?php echo (int) $beltoft_services_fw_id; ?>"><?php esc_html_e( 'Protocol', 'beltoft-services' ); ?></label>
					<select id="beltoft-fw-proto-<?php echo (int) $beltoft_services_fw_id; ?>" name="proto">
						<option value="tcp"><?php esc_html_e( 'TCP', 'beltoft-services' ); ?></option>
						<option value="udp"><?php esc_html_e( 'UDP', 'beltoft-services' ); ?></option>
					</select>
				</p>
				<p>
					<label for="beltoft-fw-port-<?php echo (int) $beltoft_services_fw_id; ?>"><?php esc_html_e( 'Port or range', 'beltoft-services' ); ?></label>
					<input type="text" id="beltoft-fw-port-<?php echo (int) $beltoft_services_fw_id; ?>" name="port" inputmode="numeric" autocomplete="off" placeholder="8080 or 8000-8100">
				</p>
				<p>
					<label for="beltoft-fw-who-<?php echo (int) $beltoft_services_fw_id; ?>"><?php esc_html_e( 'Who can connect', 'beltoft-services' ); ?></label>
					<select id="beltoft-fw-who-<?php echo (int) $beltoft_services_fw_id; ?>" name="who">
						<option value="anyone"><?php esc_html_e( 'Anyone', 'beltoft-services' ); ?></option>
						<option value="some"><?php esc_html_e( 'Only these addresses', 'beltoft-services' ); ?></option>
					</select>
				</p>
				<p data-fw-sources-row hidden>
					<label for="beltoft-fw-sources-<?php echo (int) $beltoft_services_fw_id; ?>"><?php esc_html_e( 'Addresses (up to 5, separated by commas)', 'beltoft-services' ); ?></label>
					<input type="text" id="beltoft-fw-sources-<?php echo (int) $beltoft_services_fw_id; ?>" name="sources" autocomplete="off" placeholder="203.0.113.7, 203.0.113.0/24">
					<button type="button" class="beltoft-services-fw__link" data-fw-add-mine hidden><?php esc_html_e( 'Add my address', 'beltoft-services' ); ?></button>
				</p>
				<p>
					<label for="beltoft-fw-label-<?php echo (int) $beltoft_services_fw_id; ?>"><?php esc_html_e( 'Name (optional)', 'beltoft-services' ); ?></label>
					<input type="text" id="beltoft-fw-label-<?php echo (int) $beltoft_services_fw_id; ?>" name="label" maxlength="40" autocomplete="off">
				</p>
				<p class="beltoft-services-fw__error" data-fw-form-error role="alert"></p>
				<p><button type="submit" class="button woocommerce-button"><?php esc_html_e( 'Add to the list', 'beltoft-services' ); ?></button></p>
			</form>
		</div>

		<div class="beltoft-services-fw__ssh" data-fw-ssh hidden>
			<p data-fw-ssh-text></p>
			<p data-fw-ssh-ack-row hidden>
				<label><input type="checkbox" data-fw-ssh-ack> <?php esc_html_e( 'I understand that I will not be able to log in over SSH', 'beltoft-services' ); ?></label>
			</p>
			<p data-fw-ssh-mine-row hidden><button type="button" class="beltoft-services-fw__link" data-fw-ssh-mine><?php esc_html_e( 'Add my address', 'beltoft-services' ); ?></button></p>
		</div>

		<p class="beltoft-services-fw__error" data-fw-error role="alert"></p>
		<p class="beltoft-services-fw__apply">
			<button type="button" class="button woocommerce-button" data-fw-apply disabled><?php esc_html_e( 'Apply changes', 'beltoft-services' ); ?></button>
			<span class="beltoft-services-fw__saved" data-fw-saved></span>
		</p>

		<div class="beltoft-services-fw__fixed">
			<h5><?php esc_html_e( 'Always in place', 'beltoft-services' ); ?></h5>
			<ul>
				<?php foreach ( (array) $view['fixed'] as $beltoft_services_fw_line ) : ?>
					<li><?php echo esc_html( (string) $beltoft_services_fw_line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="beltoft-services-fw__layers">
			<h5><?php esc_html_e( 'Two firewalls', 'beltoft-services' ); ?></h5>
			<?php if ( ! empty( $view['app'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Your app opens its own ports inside the server. A port you add here is also needed inside the server, for example with the command that the Copy button next to the rule gives you.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'This firewall sits in front of your server. If you turned on a firewall inside the server, the port must be open there too.', 'beltoft-services' ); ?></p>
			<?php endif; ?>
		</div>

		<p class="beltoft-services-fw__again"><button type="button" class="button woocommerce-button" data-fw-reset><?php esc_html_e( 'Start again', 'beltoft-services' ); ?></button></p>
	</div>

	<dialog class="beltoft-services-vm__dialog beltoft-services-fw__dialog" data-fw-dialog aria-labelledby="beltoft-fw-dialog-title-<?php echo (int) $beltoft_services_fw_id; ?>">
		<form class="beltoft-services-vm-form" method="dialog" data-fw-dialog-form>
			<h5 id="beltoft-fw-dialog-title-<?php echo (int) $beltoft_services_fw_id; ?>" data-fw-dialog-title></h5>
			<p data-fw-dialog-body></p>
			<p class="beltoft-services-vm__buttons">
				<button type="submit" class="button woocommerce-button" data-fw-dialog-ok></button>
				<button type="button" class="button woocommerce-button" data-fw-dialog-cancel><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
			</p>
		</form>
	</dialog>
</div>

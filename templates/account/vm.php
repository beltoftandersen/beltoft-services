<?php
/**
 * The virtual machine part of the service panel (My Account → Licenses).
 *
 * Variables (set by BeltoftServices\Proxmox\Panels::account()): $ip, $plan (cores, memory_mb,
 * disk_gb), $power (running, stopped or empty when not known), $os_label, $os_options
 * (VMID => label), $os_current, $has_key, $can_reveal, $forms (whether actions are allowed now).
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
			<dd><code>ssh root@<?php echo esc_html( $ip ); ?></code></dd>
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

		<details class="beltoft-services-vm__more">
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
		</details>
	<?php endif; ?>
</div>

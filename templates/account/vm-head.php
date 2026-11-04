<?php
/**
 * The head of a server's page: the back link with the buttons for what the customer does most on one row, then the name, and the state line (the state, then the plan's name, or what the page is doing: see assets/status.js).
 *
 * Variables (set by BeltoftServices\Proxmox\Panels::page_head()): $title, $group (getting-ready, ready, paused, removed or failed),
 * $badge (the state text), $message (a sentence about the state), $show_power (the power replaces the state text),
 * $busy, $show_message (print the message line: not ready, or the app is not done), $power (running, stopped or empty), $plan (the plan's name, or empty),
 * $resource_id, $can_rename (the name can be changed: the name is a button that opens the field), $server_name (the name now set, or empty), $ip, $ssh_user, $actions (start, reboot, shutdown => label, the ones offered now), $billing_note (a pay-as-you-go server: "A stopped server is still billed...", else empty).
 * Reboot and Shut down ask first in the page's dialog (assets/vm.js). The state text and the message are the ones the
 * status poll keeps up to date, so the elements keep their data attributes.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_power_text = $busy ? __( 'Busy', 'beltoft-services' ) : ( 'running' === $power ? __( 'Running', 'beltoft-services' ) : __( 'Stopped', 'beltoft-services' ) );
?>
<?php $beltoft_services_back = \BeltoftServices\Account\ServerLinks::take_back(); ?>
<header class="beltoft-services-page__head">
	<?php if ( '' !== $beltoft_services_back || '' !== $ip || $actions ) : ?>
		<div class="beltoft-services-page__bar">
			<?php if ( '' !== $beltoft_services_back ) : ?>
				<p class="beltoft-services-server__back"><?php echo $beltoft_services_back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_url and esc_html. ?></p>
			<?php endif; ?>
			<?php if ( '' !== $ip || $actions ) : ?>
				<div class="beltoft-services-page__actions">
					<?php if ( '' !== $ip ) : ?>
						<button type="button" class="button woocommerce-button beltoft-services-btn" data-copy="<?php echo esc_attr( 'ssh ' . $ssh_user . '@' . $ip ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy SSH command', 'beltoft-services' ); ?></button>
					<?php endif; ?>
					<?php foreach ( array( 'start', 'reboot', 'shutdown' ) as $beltoft_services_name ) : ?>
						<?php if ( ! isset( $actions[ $beltoft_services_name ] ) ) { continue; } ?>
						<?php $beltoft_services_label = $actions[ $beltoft_services_name ]; ?>
						<?php if ( 'start' === $beltoft_services_name ) : ?>
							<button type="button" class="button woocommerce-button beltoft-services-btn beltoft-services-action" data-action="start"><?php echo esc_html( $beltoft_services_label ); ?></button>
						<?php else : ?>
							<button type="button" class="button woocommerce-button beltoft-services-btn" data-power-action="<?php echo esc_attr( $beltoft_services_name ); ?>"><?php echo esc_html( $beltoft_services_label ); ?></button>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="beltoft-services-page__title">
		<?php if ( ! empty( $can_rename ) ) : ?>
			<?php $beltoft_services_field = 'beltoft-vm-name-' . (int) $resource_id; ?>
			<div class="beltoft-services-page__namerow" data-rename>
				<h2 class="beltoft-services-page__name">
					<button type="button" class="beltoft-services-page__rename" data-rename-open aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the server's name */ __( 'Rename %s', 'beltoft-services' ), $title ) ); ?>">
						<span class="beltoft-services-page__rename-name"><?php echo esc_html( $title ); ?></span>
						<span class="beltoft-services-page__rename-hint" aria-hidden="true"><?php esc_html_e( 'Rename', 'beltoft-services' ); ?></span>
					</button>
				</h2>
				<form class="beltoft-services-vm-form beltoft-services-page__renameform" data-action="rename" hidden>
					<label class="screen-reader-text" for="<?php echo esc_attr( $beltoft_services_field ); ?>"><?php esc_html_e( 'Server name', 'beltoft-services' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $beltoft_services_field ); ?>" name="name" value="<?php echo esc_attr( '' !== (string) $server_name ? (string) $server_name : $title ); ?>" maxlength="63" autocomplete="off" spellcheck="false" required aria-describedby="<?php echo esc_attr( $beltoft_services_field ); ?>-help">
					<span class="screen-reader-text" id="<?php echo esc_attr( $beltoft_services_field ); ?>-help"><?php esc_html_e( "Used as the server's hostname, for example web-01. Lowercase letters, digits and hyphens. The change shows on the server after the next reboot.", 'beltoft-services' ); ?></span>
					<button type="submit" class="button woocommerce-button beltoft-services-btn"><?php esc_html_e( 'Save', 'beltoft-services' ); ?></button>
					<button type="button" class="button woocommerce-button beltoft-services-btn" data-rename-cancel><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
				</form>
			</div>
		<?php else : ?>
			<h2 class="beltoft-services-page__name"><?php echo esc_html( $title ); ?></h2>
		<?php endif; ?>
		<p class="beltoft-services-page__meta">
			<span class="beltoft-services-badge beltoft-services-badge--<?php echo esc_attr( $group ); ?>" data-badge<?php echo $show_power ? ' hidden' : ''; ?>><?php echo esc_html( $badge ); ?></span>
			<?php if ( $show_power ) : ?><span class="beltoft-services-badge beltoft-services-badge--ready" data-power-line><?php echo esc_html( $beltoft_services_power_text ); ?></span><?php endif; ?>
			<span class="bs-inline" data-bs-inline role="status" aria-live="polite"><span class="bs-inline__text"><?php echo esc_html( $plan ); ?></span></span>
		</p>
		<p class="beltoft-services-panel__message" data-message<?php echo ! empty( $show_message ) ? '' : ' hidden'; ?>><?php echo esc_html( $message ); ?></p>
	</div>
	<?php if ( '' !== $ip || $actions ) : ?>
		<?php if ( '' !== (string) ( $billing_note ?? '' ) && ( isset( $actions['shutdown'] ) || isset( $actions['start'] ) ) ) : ?>
			<p class="description beltoft-services-page__billing" data-billing-note><?php echo esc_html( $billing_note ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</header>

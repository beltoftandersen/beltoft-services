<?php
/**
 * The head of a server's page: name, state, plan, renewal and the buttons for what the customer does most.
 *
 * Variables (set by BeltoftServices\Proxmox\Panels::page_head()): $title, $group (getting-ready, ready, paused, removed or failed),
 * $badge (the state text), $message (a sentence about the state), $show_power (the power replaces the state text),
 * $busy, $show_message (print the message line: not ready, or the app is not done), $power (running, stopped or empty), $plan (the plan's name, or empty), $renews (a sentence such as "Renews 29/11/2026", or empty),
 * $ip, $ssh_user, $actions (start, reboot, shutdown => label, the ones offered now).
 * Reboot and Shut down ask first in the page's dialog (assets/vm.js). The state text and the message are the ones the
 * status poll keeps up to date, so the elements keep their data attributes.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_power_text = $busy ? __( 'Busy', 'beltoft-services' ) : ( 'running' === $power ? __( 'Running', 'beltoft-services' ) : __( 'Stopped', 'beltoft-services' ) );
?>
<header class="beltoft-services-page__head">
	<div class="beltoft-services-page__title">
		<h2 class="beltoft-services-page__name"><?php echo esc_html( $title ); ?></h2>
		<p class="beltoft-services-page__meta">
			<span class="beltoft-services-badge beltoft-services-badge--<?php echo esc_attr( $group ); ?>" data-badge<?php echo $show_power ? ' hidden' : ''; ?>><?php echo esc_html( $badge ); ?></span>
			<?php if ( $show_power ) : ?><span class="beltoft-services-badge beltoft-services-badge--ready" data-power-line><?php echo esc_html( $beltoft_services_power_text ); ?></span><?php endif; ?>
			<?php if ( '' !== $plan ) : ?><span class="beltoft-services-page__plan"><?php echo esc_html( $plan ); ?></span><?php endif; ?>
			<?php if ( '' !== $renews ) : ?><span class="beltoft-services-page__renews"><?php echo esc_html( $renews ); ?></span><?php endif; ?>
		</p>
		<p class="beltoft-services-panel__message" data-message<?php echo ! empty( $show_message ) ? '' : ' hidden'; ?>><?php echo esc_html( $message ); ?></p>
	</div>
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
</header>

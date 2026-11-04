<?php
/**
 * The head of a managed database's page: the back link with the power buttons on one row, then the name, and the state
 * line (the state, then the engine, or what the page is doing: see assets/status.js).
 *
 * Variables (set by BeltoftServices\Account\ServiceArea::head()): $title, $group (getting-ready, ready, paused, removed or failed),
 * $badge (the state text), $message (a sentence about the state), $show_message (print the message line), $kind (the engine),
 * $renews (a renewal sentence, empty for a pay as you go database), $power (running, stopped or empty), $busy, $show_power
 * (the power replaces the state text), $actions (shutdown or start => label, the ones offered now), $note (the line about
 * billing of a stopped database). The buttons are handled by assets/db.js. The state text and the message are the ones the
 * status poll keeps up to date, so the elements keep their data attributes.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_power_text = $busy ? __( 'Busy', 'beltoft-services' ) : ( 'running' === $power ? __( 'Running', 'beltoft-services' ) : __( 'Stopped', 'beltoft-services' ) );
$beltoft_services_back       = \BeltoftServices\Account\ServerLinks::take_back();
?>
<header class="beltoft-services-page__head">
	<?php if ( '' !== $beltoft_services_back || $actions ) : ?>
		<div class="beltoft-services-page__bar">
			<?php if ( '' !== $beltoft_services_back ) : ?>
				<p class="beltoft-services-server__back"><?php echo $beltoft_services_back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_url and esc_html. ?></p>
			<?php endif; ?>
			<?php if ( $actions ) : ?>
				<div class="beltoft-services-page__actions">
					<?php foreach ( $actions as $beltoft_services_name => $beltoft_services_label ) : ?>
						<button type="button" class="button woocommerce-button beltoft-services-btn" data-db-power="<?php echo esc_attr( (string) $beltoft_services_name ); ?>"><?php echo esc_html( (string) $beltoft_services_label ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="beltoft-services-page__title">
		<h2 class="beltoft-services-page__name"><?php echo esc_html( $title ); ?></h2>
		<p class="beltoft-services-page__meta">
			<span class="beltoft-services-badge beltoft-services-badge--<?php echo esc_attr( $group ); ?>" data-badge<?php echo $show_power ? ' hidden' : ''; ?>><?php echo esc_html( $badge ); ?></span>
			<?php if ( $show_power ) : ?><span class="beltoft-services-badge beltoft-services-badge--ready" data-power-line><?php echo esc_html( $beltoft_services_power_text ); ?></span><?php endif; ?>
			<span class="bs-inline" data-bs-inline role="status" aria-live="polite"><span class="bs-inline__text"><?php echo esc_html( (string) $kind ); ?></span></span>
			<?php if ( '' !== $renews ) : ?><span class="beltoft-services-page__renews"><?php echo esc_html( $renews ); ?></span><?php endif; ?>
		</p>
		<p class="beltoft-services-panel__message" data-message<?php echo ! empty( $show_message ) ? '' : ' hidden'; ?>><?php echo esc_html( $message ); ?></p>
	</div>
	<?php if ( $actions && '' !== (string) $note ) : ?>
		<p class="description beltoft-services-page__billing" data-db-power-note><?php echo esc_html( $note ); ?></p>
	<?php endif; ?>
</header>

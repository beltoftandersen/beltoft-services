<?php
/**
 * The live progress block of a service (server card, and any page that shows a service being set up).
 *
 * Variables (set by BeltoftServices\Progress::render()): $progress (the payload of Progress::build()),
 * $poll_url (a URL assets/progress.js polls itself, or empty when the page drives it).
 * assets/progress.js draws the same markup from a payload; keep the two in step.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_phase = (string) ( $progress['phase'] ?? 'idle' );
?>
<div class="beltoft-services-progress is-<?php echo esc_attr( $beltoft_services_phase ); ?>" data-progress data-phase="<?php echo esc_attr( $beltoft_services_phase ); ?>"<?php echo ! empty( $progress['active'] ) ? ' aria-busy="true"' : ''; ?><?php echo '' !== $poll_url ? ' data-progress-url="' . esc_url( $poll_url ) . '"' : ''; ?>>
	<p class="beltoft-services-progress__text" role="status" aria-live="polite" data-progress-text><?php echo esc_html( (string) ( $progress['text'] ?? '' ) ); ?></p>
	<ol class="beltoft-services-progress__steps" data-progress-steps<?php echo empty( $progress['steps'] ) ? ' hidden' : ''; ?>>
		<?php foreach ( (array) ( $progress['steps'] ?? array() ) as $beltoft_services_step ) : ?>
			<li class="is-<?php echo esc_attr( $beltoft_services_step['status'] ); ?>"<?php echo 'current' === $beltoft_services_step['status'] ? ' aria-current="step"' : ''; ?> data-step="<?php echo esc_attr( $beltoft_services_step['key'] ); ?>">
				<?php echo esc_html( $beltoft_services_step['label'] ); ?>
				<span class="screen-reader-text"><?php echo esc_html( $beltoft_services_step['status_label'] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>
	<div class="beltoft-services-progress__note" data-progress-note<?php echo '' === (string) ( $progress['note'] ?? '' ) ? ' hidden' : ''; ?>>
		<p data-progress-note-text><?php echo esc_html( (string) ( $progress['note'] ?? '' ) ); ?></p>
		<ul data-progress-hosts<?php echo empty( $progress['hosts'] ) ? ' hidden' : ''; ?>>
			<?php foreach ( (array) ( $progress['hosts'] ?? array() ) as $beltoft_services_host ) : ?>
				<li><?php echo esc_html( (string) $beltoft_services_host ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p data-progress-link<?php echo empty( $progress['link']['url'] ) ? ' hidden' : ''; ?>><?php if ( ! empty( $progress['link']['url'] ) ) : ?><a href="<?php echo esc_url( (string) $progress['link']['url'] ); ?>"><?php echo esc_html( (string) ( $progress['link']['label'] ?? '' ) ); ?></a><?php endif; ?></p>
	</div>
	<p class="beltoft-services-progress__leave" data-progress-leave<?php echo '' === (string) ( $progress['leave'] ?? '' ) ? ' hidden' : ''; ?>><?php echo esc_html( (string) ( $progress['leave'] ?? '' ) ); ?></p>
</div>

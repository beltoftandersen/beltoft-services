<?php
/**
 * The hosting account page: the tab strip (on a page), the line of the account's background task, the notices, then the parts of the tab shown.
 *
 * Printed by BeltoftServices\Hosting\HostingAccount::render().
 *
 * Variables: $v: resource_id, task_json (the task for the script, or ''), task (HostingView::task_line()), notices
 * (HostingView::notices()), nav (tab slug => title and address, empty when the panel is not on a page), active (the tab shown),
 * body (the printed parts, already escaped).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-hosting" data-hosting-resource="<?php echo esc_attr( (string) $v['resource_id'] ); ?>"<?php echo '' === $v['task_json'] ? '' : ' data-hosting-task="' . esc_attr( $v['task_json'] ) . '"'; ?>>
	<?php \BeltoftServices\Account\ServiceArea::tab_nav( $v['nav'], $v['active'], __( 'Hosting sections', 'beltoft-services' ) ); ?>
	<?php // The shared progress line (status.js) says what the account is doing; this line is only for a browser without script. ?>
	<noscript><p class="beltoft-hosting-notice" role="status"<?php echo $v['task']['hidden'] ? ' hidden' : ''; ?>><span data-task-step><?php echo esc_html( $v['task']['step'] ); ?></span> <span data-task-wait><?php echo esc_html( $v['task']['wait'] ); ?></span></p></noscript>
	<?php if ( '' !== $v['task']['failed'] ) : ?>
		<p class="beltoft-hosting-warning" data-task-failed role="alert"><?php echo esc_html( $v['task']['failed'] ); ?></p>
	<?php endif; ?>
	<?php if ( array() !== $v['notices'] ) : ?>
		<div class="beltoft-hosting-notices">
			<?php foreach ( $v['notices'] as $beltoft_services_notice ) : ?>
				<p class="<?php echo esc_attr( $beltoft_services_notice['class'] ); ?>"><?php echo esc_html( $beltoft_services_notice['text'] ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<?php echo $v['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The parts printed by the templates of this folder. ?>
</div>

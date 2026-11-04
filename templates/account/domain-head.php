<?php
/**
 * The head of a page of a service in an area (a domain, mail, an app): the back link, the name, and the state line (the state, then $kind, or what the page is doing: see assets/status.js).
 *
 * Variables (set by BeltoftServices\Account\DomainPage::head()): $title, $group (getting-ready, ready, paused, removed or failed),
 * $badge (the state text), $message (a sentence about the state), $show_message (print the message line), $kind, $renews.
 * The state text and the message are the ones the status poll keeps up to date, so the elements keep their data attributes.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<?php $beltoft_services_back = \BeltoftServices\Account\ServerLinks::take_back(); ?>
<header class="beltoft-services-page__head">
	<?php if ( '' !== $beltoft_services_back ) : ?>
		<div class="beltoft-services-page__bar">
			<p class="beltoft-services-server__back"><?php echo $beltoft_services_back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_url and esc_html. ?></p>
		</div>
	<?php endif; ?>
	<div class="beltoft-services-page__title">
		<h2 class="beltoft-services-page__name"><?php echo esc_html( $title ); ?></h2>
		<p class="beltoft-services-page__meta">
			<span class="beltoft-services-badge beltoft-services-badge--<?php echo esc_attr( $group ); ?>" data-badge><?php echo esc_html( $badge ); ?></span>
			<span class="bs-inline" data-bs-inline role="status" aria-live="polite"><span class="bs-inline__text"><?php echo esc_html( (string) $kind ); ?></span></span>
			<?php if ( '' !== $renews ) : ?><span class="beltoft-services-page__renews"><?php echo esc_html( $renews ); ?></span><?php endif; ?>
		</p>
		<p class="beltoft-services-panel__message" data-message<?php echo ! empty( $show_message ) ? '' : ' hidden'; ?>><?php echo esc_html( $message ); ?></p>
	</div>
</header>

<?php
/**
 * The head of a domain's page: the domain name, its state, what kind of service it is and its renewal.
 *
 * Variables (set by BeltoftServices\Account\DomainPage::head()): $title, $group (getting-ready, ready, paused, removed or failed),
 * $badge (the state text), $message (a sentence about the state), $show_message (print the message line), $kind, $renews.
 * The state text and the message are the ones the status poll keeps up to date, so the elements keep their data attributes.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="beltoft-services-page__head">
	<div class="beltoft-services-page__title">
		<h2 class="beltoft-services-page__name"><?php echo esc_html( $title ); ?></h2>
		<p class="beltoft-services-page__meta">
			<span class="beltoft-services-badge beltoft-services-badge--<?php echo esc_attr( $group ); ?>" data-badge><?php echo esc_html( $badge ); ?></span>
			<span class="beltoft-services-page__plan"><?php echo esc_html( $kind ); ?></span>
			<?php if ( '' !== $renews ) : ?><span class="beltoft-services-page__renews"><?php echo esc_html( $renews ); ?></span><?php endif; ?>
		</p>
		<p class="beltoft-services-panel__message" data-message<?php echo ! empty( $show_message ) ? '' : ' hidden'; ?>><?php echo esc_html( $message ); ?></p>
	</div>
</header>

<?php
/**
 * The box at the top of the overview of a server whose app is ready. Printed by BeltoftServices\Apps\AppTab::ready_box().
 *
 * Variables: $view (AppTab::view()), $login_url (the App tab, at the button that shows the login).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-app-ready" data-app-ready>
	<p class="beltoft-services-app-ready__title"><?php echo esc_html( sprintf( /* translators: %s: app name */ __( '%s is ready', 'beltoft-services' ), (string) $view['name'] ) ); ?></p>
	<p class="beltoft-services-app-ready__buttons">
		<?php if ( '' !== $view['url'] ) : ?>
			<a class="button woocommerce-button bs-btn bs-btn--sm" href="<?php echo esc_url( $view['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( /* translators: %s: app name */ __( 'Open %s', 'beltoft-services' ), (string) $view['name'] ) ); ?></a>
		<?php endif; ?>
		<?php if ( $view['can_reveal'] ) : ?>
			<a class="button woocommerce-button bs-btn bs-btn--sm bs-btn--secondary" href="<?php echo esc_url( $login_url ); ?>" data-tab-link="app"><?php esc_html_e( 'Show login', 'beltoft-services' ); ?></a>
		<?php endif; ?>
	</p>
</div>

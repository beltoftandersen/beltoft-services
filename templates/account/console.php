<?php
/** Customer console tab. @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-console" data-console>
	<p><?php esc_html_e( 'Use the console when you cannot log in with SSH. It shows the same screen as a monitor on the server.', 'beltoft-services' ); ?></p>
	<div class="beltoft-services-console__toolbar" data-console-toolbar hidden>
		<button type="button" class="beltoft-services-btn beltoft-services-console__action" data-console-fullscreen><?php esc_html_e( 'Full screen', 'beltoft-services' ); ?></button>
		<button type="button" class="beltoft-services-btn beltoft-services-console__action" data-console-cad><?php esc_html_e( 'Ctrl+Alt+Del', 'beltoft-services' ); ?></button>
		<button type="button" class="beltoft-services-btn beltoft-services-console__action" data-console-keyboard><?php esc_html_e( 'Keyboard', 'beltoft-services' ); ?></button>
		<button type="button" class="beltoft-services-btn beltoft-services-console__action" data-console-disconnect><?php esc_html_e( 'Disconnect', 'beltoft-services' ); ?></button>
	</div>
	<p class="beltoft-services-console__status" role="status" aria-live="polite" data-console-status></p>
	<div class="beltoft-services-console__screen" data-console-screen hidden></div>
	<input class="beltoft-services-console__keyboard" type="text" inputmode="text" autocomplete="off" aria-label="<?php esc_attr_e( 'Keyboard input', 'beltoft-services' ); ?>" data-console-input hidden>
	<button class="beltoft-services-console__open" type="button" data-console-open><?php esc_html_e( 'Open console', 'beltoft-services' ); ?></button>
</section>

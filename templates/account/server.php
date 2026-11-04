<?php
/**
 * My Account → Servers → one server: its page.
 *
 * Override by copying to yourtheme/beltoft-services/account/server.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $view License view (BeltoftLicenses View::license()).
 * @var string               $list Address of the list of servers.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-server">
	<?php
	$beltoft_services_quarantined = \BeltoftServices\Resource::for_license( (int) ( $view['license']['id'] ?? 0 ) );
	if ( $beltoft_services_quarantined && \BeltoftServices\Proxmox\Quarantine::active( $beltoft_services_quarantined ) ) :
		?>
		<p class="beltoft-services-notice" role="status"><?php echo esc_html( \BeltoftServices\Proxmox\Quarantine::notice() ); ?></p>
	<?php endif; ?>
	<?php
	// The head prints the back link on the row of its buttons; any other page prints it above.
	\BeltoftServices\Account\ServerLinks::set_back( $list, __( 'All servers', 'beltoft-services' ) );
	ob_start();
	\BeltoftServices\Account\Panel::render( $view['license'], $view );
	$beltoft_services_panel = ob_get_clean();
	echo \BeltoftServices\Account\ServerLinks::end_back(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_url and esc_html.
	echo $beltoft_services_panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The panel's own, escaped markup.
	?>
</div>

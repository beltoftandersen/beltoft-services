<?php
/**
 * The IPv6 tab of a server page. Printed by BeltoftServices\Proxmox\Network\Ipv6Tab::pane().
 *
 * Variables: $view (Ipv6Switch::view()): resource_id, address, can_add, can_remove, busy, locked.
 * assets/ipv6-tab.js asks first (the words come from Ipv6Switch::words(), printed in data attributes), posts the
 * action and waits for the job.
 *
 * @package BeltoftServices
 */

use BeltoftServices\Proxmox\Network\Ipv6Switch;

defined( 'ABSPATH' ) || exit;

$beltoft_services_ipv6_add    = Ipv6Switch::words( Ipv6Switch::ADD );
$beltoft_services_ipv6_remove = Ipv6Switch::words( Ipv6Switch::REMOVE );
?>
<div class="beltoft-services-net" data-ipv6 data-resource="<?php echo (int) $view['resource_id']; ?>" data-ipv6-busy="<?php echo $view['busy'] ? '1' : '0'; ?>">
	<h4><?php esc_html_e( 'IPv6', 'beltoft-services' ); ?></h4>
	<p class="description"><?php esc_html_e( 'An IPv6 address next to your IPv4 address. Your server keeps its IPv4 address.', 'beltoft-services' ); ?></p>

	<dl class="beltoft-services-facts">
		<div><dt><?php esc_html_e( 'Status', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $view['busy'] ? __( 'Changing', 'beltoft-services' ) : ( '' !== $view['address'] ? __( 'On', 'beltoft-services' ) : __( 'Off', 'beltoft-services' ) ) ); ?></dd></div>
		<?php if ( '' !== $view['address'] ) : ?>
			<div><dt><?php esc_html_e( 'IPv6 address', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['address'] ); ?></code></dd></div>
		<?php endif; ?>
	</dl>

	<?php if ( $view['can_add'] || $view['can_remove'] ) : ?>
		<p class="description"><?php echo esc_html( $view['can_add'] ? $beltoft_services_ipv6_add['text'] : $beltoft_services_ipv6_remove['text'] ); ?></p>
	<?php elseif ( $view['locked'] ) : ?>
		<p class="description"><?php esc_html_e( 'IPv6 cannot be removed from this server, because it has no IPv4 address.', 'beltoft-services' ); ?></p>
	<?php endif; ?>

	<p class="beltoft-services-net__buttons" data-bs-note-anchor>
		<?php if ( $view['can_add'] ) : ?>
			<button type="button" class="button woocommerce-button" data-ipv6-action="<?php echo esc_attr( Ipv6Switch::ADD ); ?>" data-title="<?php echo esc_attr( $beltoft_services_ipv6_add['title'] ); ?>" data-text="<?php echo esc_attr( $beltoft_services_ipv6_add['text'] ); ?>" data-confirm="<?php echo esc_attr( $beltoft_services_ipv6_add['confirm'] ); ?>"><?php esc_html_e( 'Add IPv6', 'beltoft-services' ); ?></button>
		<?php endif; ?>
		<?php if ( $view['can_remove'] ) : ?>
			<button type="button" class="button woocommerce-button" data-ipv6-action="<?php echo esc_attr( Ipv6Switch::REMOVE ); ?>" data-title="<?php echo esc_attr( $beltoft_services_ipv6_remove['title'] ); ?>" data-text="<?php echo esc_attr( $beltoft_services_ipv6_remove['text'] ); ?>" data-confirm="<?php echo esc_attr( $beltoft_services_ipv6_remove['confirm'] ); ?>"><?php esc_html_e( 'Remove IPv6', 'beltoft-services' ); ?></button>
		<?php endif; ?>
	</p>
</div>

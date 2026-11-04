<?php
/**
 * The Network tab of a server page. Printed by BeltoftServices\Proxmox\NetworkTab::pane().
 *
 * Variables: $view (PrivateAttach::view()): resource_id, state (empty, attaching, attached, detaching, failed),
 * address, cidr, others (address and name of the customer's other servers on the network), notice, mac,
 * can_attach, can_detach. assets/network-tab.js posts the actions and waits for the job.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_net_state = (string) $view['state'];
$beltoft_services_net_words = array(
	''          => __( 'Not attached', 'beltoft-services' ),
	'attaching' => __( 'Attaching', 'beltoft-services' ),
	'attached'  => __( 'Attached', 'beltoft-services' ),
	'detaching' => __( 'Detaching', 'beltoft-services' ),
	'failed'    => __( 'Needs attention', 'beltoft-services' ),
);
?>
<div class="beltoft-services-net" data-net data-resource="<?php echo (int) $view['resource_id']; ?>" data-net-state="<?php echo esc_attr( $beltoft_services_net_state ); ?>">
	<h4><?php esc_html_e( 'Private network', 'beltoft-services' ); ?></h4>
	<p class="description"><?php esc_html_e( 'A network that only your own servers share. Traffic on it never goes over the internet. It needs the guest agent (qemu-guest-agent) running in your server.', 'beltoft-services' ); ?></p>

	<dl class="beltoft-services-facts">
		<div><dt><?php esc_html_e( 'Status', 'beltoft-services' ); ?></dt><dd data-net-status><?php echo esc_html( $beltoft_services_net_words[ $beltoft_services_net_state ] ); ?></dd></div>
		<?php if ( '' !== $view['address'] ) : ?>
			<div><dt><?php esc_html_e( 'Private address', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['address'] ); ?></code></dd></div>
		<?php endif; ?>
		<?php if ( '' !== $view['cidr'] ) : ?>
			<div><dt><?php esc_html_e( 'Network', 'beltoft-services' ); ?></dt><dd><code><?php echo esc_html( (string) $view['cidr'] ); ?></code></dd></div>
		<?php endif; ?>
	</dl>

	<?php if ( '' !== $view['notice'] ) : ?>
		<p class="beltoft-services-net__notice" role="status"><?php echo esc_html( (string) $view['notice'] ); ?></p>
	<?php endif; ?>

	<?php if ( array() !== $view['others'] ) : ?>
		<h5><?php esc_html_e( 'Other servers on this network', 'beltoft-services' ); ?></h5>
		<ul class="beltoft-services-net__others">
			<?php foreach ( (array) $view['others'] as $beltoft_services_net_other ) : ?>
				<li><code><?php echo esc_html( (string) $beltoft_services_net_other['address'] ); ?></code> <?php echo esc_html( (string) $beltoft_services_net_other['name'] ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( 'attached' === $beltoft_services_net_state ) : ?>
		<p class="description"><?php esc_html_e( 'To check inside the server, run this and look for the private address:', 'beltoft-services' ); ?> <code>ip -br a</code></p>
	<?php endif; ?>


	<p class="beltoft-services-net__buttons">
		<?php if ( $view['can_attach'] ) : ?>
			<button type="button" class="button woocommerce-button" data-net-attach><?php esc_html_e( 'Attach', 'beltoft-services' ); ?></button>
		<?php endif; ?>
		<?php if ( $view['can_detach'] ) : ?>
			<button type="button" class="button woocommerce-button" data-net-detach><?php esc_html_e( 'Detach', 'beltoft-services' ); ?></button>
		<?php endif; ?>
	</p>
</div>

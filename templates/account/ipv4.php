<?php
/**
 * The block "IPv4 addresses" of a server page. Variable: $ipv4, from Ipv4\Ipv4Panels::view().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-vm__ipv4" id="beltoft-services-ipv4">
	<h4><?php esc_html_e( 'IPv4 addresses', 'beltoft-services' ); ?></h4>
	<?php if ( array() === $ipv4['addresses'] ) : ?>
		<p class="description"><?php esc_html_e( 'This server has an IPv6 address and no IPv4 address. Add an IPv4 address if visitors or services must reach the server over IPv4. Some services, for example GitHub, cannot be reached from a server with IPv6 only.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<ul class="beltoft-services-vm__ipv4-list">
			<?php foreach ( $ipv4['addresses'] as $beltoft_services_row ) : ?>
				<li>
					<code><?php echo esc_html( $beltoft_services_row['address'] ); ?></code>
					<span class="description">
						<?php
						echo esc_html( 'main' === $beltoft_services_row['role'] ? __( 'Main address', 'beltoft-services' ) : __( 'Extra address', 'beltoft-services' ) );

						if ( ! $beltoft_services_row['live'] ) {
							echo ', ' . esc_html__( 'being added', 'beltoft-services' );
						} elseif ( '' !== $beltoft_services_row['ending'] ) {
							/* translators: %s: date the address ends */
							echo ', ' . esc_html( sprintf( __( 'ends %s', 'beltoft-services' ), $beltoft_services_row['ending'] ) );
						}
						?>
					</span>
					<?php if ( '' !== $beltoft_services_row['remove_url'] ) : ?>
						<a class="button woocommerce-button bs-btn" href="<?php echo esc_url( $beltoft_services_row['remove_url'] ); ?>"><?php esc_html_e( 'Remove', 'beltoft-services' ); ?></a>
					<?php elseif ( $beltoft_services_row['delete_id'] > 0 ) : ?>
						<?php
						$beltoft_services_delete_id = (int) $beltoft_services_row['delete_id'];
						include BELTOFT_SERVICES_PATH . 'templates/account/delete.php';
						?>
					<?php elseif ( '' !== $beltoft_services_row['undo_url'] ) : ?>
						<a class="button woocommerce-button bs-btn" href="<?php echo esc_url( $beltoft_services_row['undo_url'] ); ?>"><?php esc_html_e( 'Keep it', 'beltoft-services' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( array() !== $ipv4['setup'] ) : ?>
			<details class="beltoft-services-vm__ipv4-setup">
				<summary><?php esc_html_e( 'Set up the extra addresses in your server', 'beltoft-services' ); ?></summary>
				<p class="description"><?php esc_html_e( 'The firewall allows the extra addresses already. Put them in the network settings of your server with the tool it uses. No restart is needed.', 'beltoft-services' ); ?></p>
				<?php foreach ( array( 'netplan' => 'Netplan', 'ifupdown' => 'ifupdown (/etc/network/interfaces)', 'networkmanager' => 'NetworkManager' ) as $beltoft_services_tool => $beltoft_services_label ) : ?>
					<?php if ( '' !== (string) ( $ipv4['setup'][ $beltoft_services_tool ] ?? '' ) ) : ?>
						<p><strong><?php echo esc_html( $beltoft_services_label ); ?></strong></p>
						<pre><code><?php echo esc_html( $ipv4['setup'][ $beltoft_services_tool ] ); ?></code></pre>
					<?php endif; ?>
				<?php endforeach; ?>
			</details>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $ipv4['busy'] ) : ?>
		<p class="description"><?php esc_html_e( 'An IPv4 address is being changed.', 'beltoft-services' ); ?></p>
	<?php elseif ( $ipv4['sold_out'] ) : ?>
		<p class="description"><?php esc_html_e( 'No IPv4 address is free right now.', 'beltoft-services' ); ?></p>
	<?php elseif ( '' !== $ipv4['add_url'] ) : ?>
		<?php if ( $ipv4['first_restarts'] ) : ?>
			<p class="description"><?php esc_html_e( 'The first IPv4 address becomes the main address of the server. The server restarts once, which takes about a minute, and its SSH host keys are made again.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $ipv4['add_price'] ) : ?><p class="description"><?php /* translators: %s: price such as 19 kr. / month */ echo esc_html( sprintf( __( 'Price: %s.', 'beltoft-services' ), $ipv4['add_price'] ) ); ?></p><?php endif; ?>
		<p><a class="button woocommerce-button bs-btn" href="<?php echo esc_url( $ipv4['add_url'] ); ?>"><?php esc_html_e( 'Add an IPv4 address', 'beltoft-services' ); ?></a></p>
	<?php endif; ?>
</div>

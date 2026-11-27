<?php
/**
 * The "Point your domain" box of an app that waits for the customer's own domain: the steps to take at the DNS provider, the records
 * to make with a copy button each, what DNS says right now, and what happens next. One template for the server page (progress block)
 * and the App tab; built by BeltoftServices\Apps\AppProgress::dns_box().
 *
 * Variables: $box (AppProgress::dns_view()).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_rows = array();

if ( '' !== $box['ip'] ) {
	$beltoft_services_rows[] = array( sprintf( /* translators: %s: domain name; @ is how DNS panels write the name itself */ __( '%s (@)', 'beltoft-services' ), $box['name'] ), $box['ip'] );

	if ( '' !== $box['www'] ) {
		$beltoft_services_rows[] = array( $box['www_label'], $box['ip'] );
	}
}
?>
<section class="beltoft-services-dnsbox" data-app-own-domain>
	<header class="beltoft-services-dnsbox__head">
		<h4><?php echo esc_html( sprintf( /* translators: %s: domain name */ __( 'Point %s to your server', 'beltoft-services' ), $box['name'] ) ); ?></h4>
		<span class="beltoft-services-dnsbox__pill<?php echo $box['found'] ? ' is-found' : ' is-missing'; ?>"><?php echo $box['found'] ? esc_html__( 'Found', 'beltoft-services' ) : esc_html__( 'Missing', 'beltoft-services' ); ?></span>
	</header>
	<p class="beltoft-services-dnsbox__lead"><?php echo esc_html( sprintf( /* translators: %s: app name such as Odoo */ __( '%s is installed as soon as your domain points to the server.', 'beltoft-services' ), $box['app'] ) ); ?></p>
	<ol class="beltoft-services-dnsbox__steps">
		<li><?php echo esc_html( sprintf( /* translators: %s: domain name */ __( 'Log in to your DNS provider (where %s is set up).', 'beltoft-services' ), $box['name'] ) ); ?></li>
		<?php if ( array() !== $beltoft_services_rows ) : ?>
			<li>
				<?php esc_html_e( 'Change or create these records:', 'beltoft-services' ); ?>
				<table class="beltoft-services-dnsbox__table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $beltoft_services_rows as $beltoft_services_row ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Type', 'beltoft-services' ); ?>">A</td>
								<td data-label="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><code><?php echo esc_html( $beltoft_services_row[0] ); ?></code></td>
								<td data-label="<?php esc_attr_e( 'Value', 'beltoft-services' ); ?>"><code><?php echo esc_html( $beltoft_services_row[1] ); ?></code> <button type="button" class="beltoft-services-copy" data-copy="<?php echo esc_attr( $beltoft_services_row[1] ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</li>
			<li>
				<?php esc_html_e( 'Delete any other A records for these names.', 'beltoft-services' ); ?>
				<?php if ( $box['aaaa'] ) : ?>
					<?php esc_html_e( 'Delete the AAAA record.', 'beltoft-services' ); ?>
				<?php endif; ?>
				<span class="beltoft-services-dnsbox__hint"><?php esc_html_e( 'Using Cloudflare? Turn the proxy off (grey cloud, "DNS only").', 'beltoft-services' ); ?></span>
			</li>
		<?php else : ?>
			<li><?php esc_html_e( 'The address of your server is shown here as soon as it is known.', 'beltoft-services' ); ?></li>
		<?php endif; ?>
	</ol>
	<p class="beltoft-services-dnsbox__now" data-app-own-status role="status"><?php echo esc_html( $box['now'] ); ?>
		<?php if ( '' !== $box['checked'] ) : ?>
			<span class="beltoft-services-dnsbox__checked"><?php echo esc_html( $box['checked'] ); ?></span>
		<?php endif; ?>
	</p>
	<?php if ( ! $box['found'] ) : ?>
		<p class="beltoft-services-dnsbox__check"><button type="button" class="beltoft-services-dnsbox__button" data-dns-check="<?php echo esc_attr( (string) $box['id'] ); ?>" data-label="<?php esc_attr_e( 'Check now', 'beltoft-services' ); ?>" data-next="<?php echo esc_attr( (string) $box['next'] ); ?>"<?php echo $box['next'] > 0 ? ' disabled' : ''; ?>><?php echo $box['next'] > 0 ? esc_html( $box['next_text'] ) : esc_html__( 'Check now', 'beltoft-services' ); ?></button></p>
	<?php endif; ?>
	<p class="beltoft-services-dnsbox__foot"><?php esc_html_e( 'We check every 10 minutes and start the installation ourselves.', 'beltoft-services' ); ?>
		<?php if ( '' !== $box['deadline'] ) : ?>
			<?php echo esc_html( sprintf( /* translators: 1: date, 2: app name such as Odoo */ __( 'If this has not happened by %1$s, %2$s is installed on the server address instead.', 'beltoft-services' ), $box['deadline'], $box['app'] ) ); ?>
		<?php endif; ?>
	</p>
</section>

<?php
/**
 * The "Connect to your database" box of the Access tab, in the shape of the "Point your domain" box (numbered steps, a table with a
 * copy button per value). Built by BeltoftServices\Proxmox\Db\DbConnect::build(), printed by templates/account/db.php.
 *
 * Variables: $connect (DbConnect::build()), $view (DbCard::view(), for the certificate link), $link (a callable that gives a tab's
 * address, or one that gives '' on a card).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_rows = array();

foreach ( $connect['hosts'] as $beltoft_services_host ) {
	$beltoft_services_rows[] = array( $beltoft_services_host['label'], $beltoft_services_host['value'], true );
}

$beltoft_services_rows[] = array( __( 'Port', 'beltoft-services' ), (string) $connect['port'], true );
$beltoft_services_rows[] = array( __( 'User', 'beltoft-services' ), (string) $connect['user'], true );

if ( '' !== (string) $connect['database'] ) {
	$beltoft_services_rows[] = array( __( 'Database', 'beltoft-services' ), (string) $connect['database'], true );
}

if ( '' !== $connect['command'] ) {
	$beltoft_services_rows[] = array( __( 'Command', 'beltoft-services' ), $connect['command'], true );
}

$beltoft_services_password_url = (string) $link( 'password' );
$beltoft_services_network_url  = (string) $link( 'network' );
$beltoft_services_found        = in_array( $connect['state'], array( 'allowed', 'open' ), true );
$beltoft_services_copy         = static function ( string $value ): void {
	?>
	<code><?php echo esc_html( $value ); ?></code> <button type="button" class="button woocommerce-button beltoft-services-vm__copy beltoft-services-copy" data-db-copy data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" hidden><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button>
	<?php
};
?>
<section class="beltoft-services-dnsbox beltoft-services-dbconnect" data-db-connect>
	<header class="beltoft-services-dnsbox__head">
		<h4><?php esc_html_e( 'Connect to your database', 'beltoft-services' ); ?></h4>
		<?php if ( 'unknown' !== $connect['state'] ) : ?>
			<span class="beltoft-services-dnsbox__pill<?php echo $beltoft_services_found ? ' is-found' : ' is-missing'; ?>"><?php echo $beltoft_services_found ? esc_html__( 'Your address is allowed', 'beltoft-services' ) : esc_html__( 'Your address is not allowed', 'beltoft-services' ); ?></span>
		<?php endif; ?>
	</header>
	<p class="beltoft-services-dnsbox__lead"><?php esc_html_e( 'Four things decide whether you can connect from your own computer or server.', 'beltoft-services' ); ?></p>
	<ol class="beltoft-services-dnsbox__steps">
		<li>
			<?php esc_html_e( 'Your address must be on the allowed list.', 'beltoft-services' ); ?>
			<?php if ( 'open' === $connect['state'] ) : ?>
				<span class="beltoft-services-dnsbox__hint"><?php esc_html_e( 'The database is open to everyone, so every address may connect.', 'beltoft-services' ); ?></span>
			<?php elseif ( 'allowed' === $connect['state'] ) : ?>
				<span class="beltoft-services-dnsbox__hint"><?php echo esc_html( sprintf( /* translators: %s: the visitor's IP address */ __( 'Your current address %s is on the list.', 'beltoft-services' ), $connect['my_ip'] ) ); ?></span>
			<?php elseif ( 'missing' === $connect['state'] ) : ?>
				<span class="beltoft-services-dnsbox__hint"><?php echo esc_html( sprintf( /* translators: %s: the visitor's IP address */ __( 'Your current address is %s. It is not on the list yet.', 'beltoft-services' ), $connect['my_ip'] ) ); ?></span>
				<?php if ( $connect['can_allow'] ) : ?>
					<span class="beltoft-services-dbconnect__action"><button type="button" class="button woocommerce-button beltoft-services-dnsbox__button" data-db-access-allow="<?php echo esc_attr( $connect['my_ip'] ); ?>" data-label="<?php esc_attr_e( 'My address', 'beltoft-services' ); ?>"><?php esc_html_e( 'Allow my address', 'beltoft-services' ); ?></button></span>
				<?php else : ?>
					<span class="beltoft-services-dnsbox__hint"><?php esc_html_e( 'Add it under Who may connect, further down on this page.', 'beltoft-services' ); ?></span>
				<?php endif; ?>
			<?php elseif ( 'unreachable' === $connect['state'] ) : ?>
				<span class="beltoft-services-dnsbox__hint"><?php echo esc_html( sprintf( /* translators: %s: the visitor's IP address */ __( 'You are visiting from %s. The database has no address of that kind, so it cannot be reached from there. Use a connection that has the kind of address the database has.', 'beltoft-services' ), $connect['my_ip'] ) ); ?></span>
			<?php else : ?>
				<span class="beltoft-services-dnsbox__hint"><?php esc_html_e( 'We could not read your current address. Add the address you connect from under Who may connect, further down on this page.', 'beltoft-services' ); ?></span>
			<?php endif; ?>
		</li>
		<li>
			<?php if ( $connect['ipv6_only'] ) : ?>
				<?php esc_html_e( 'Your own connection needs IPv6, unless you add an IPv4 address.', 'beltoft-services' ); ?>
				<?php
				$ipv6_only = array( 'kind' => 'database', 'add_url' => $connect['add_url'], 'add_price' => $connect['add_price'], 'adding' => $connect['adding'], 'sold_out' => $connect['sold_out'] );
				include BELTOFT_SERVICES_PATH . 'templates/account/ipv6-only.php';
				?>
				<?php if ( $connect['adding'] ) : ?>
					<span class="beltoft-services-dnsbox__hint"><?php esc_html_e( 'An IPv4 address is being added. It appears here when it is ready.', 'beltoft-services' ); ?></span>
				<?php elseif ( $connect['sold_out'] ) : ?>
					<span class="beltoft-services-dnsbox__hint"><?php esc_html_e( 'No IPv4 address is free right now.', 'beltoft-services' ); ?></span>
				<?php endif; ?>
			<?php elseif ( count( $connect['hosts'] ) > 1 ) : ?>
				<?php esc_html_e( 'The database has an IPv6 and an IPv4 address. Use the one your connection has.', 'beltoft-services' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'The database has an IPv4 address. Your own connection needs IPv4.', 'beltoft-services' ); ?>
			<?php endif; ?>
		</li>
		<li>
			<?php esc_html_e( 'Use these details:', 'beltoft-services' ); ?>
			<table class="beltoft-services-dnsbox__table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $beltoft_services_rows as $beltoft_services_row ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_row[0] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Value', 'beltoft-services' ); ?>"><?php $beltoft_services_copy( $beltoft_services_row[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<span class="beltoft-services-dnsbox__hint">
				<?php esc_html_e( 'All connections are encrypted. Replace PASSWORD with the password of the database.', 'beltoft-services' ); ?>
				<?php if ( '' !== $beltoft_services_password_url ) : ?>
					<a href="<?php echo esc_url( $beltoft_services_password_url ); ?>"><?php esc_html_e( 'Get a new password', 'beltoft-services' ); ?></a>
				<?php endif; ?>
			</span>
			<?php if ( '' !== (string) $view['ca_url'] ) : ?>
				<span class="beltoft-services-dnsbox__hint"><?php esc_html_e( 'The command expects the certificate file ca.crt in the folder you run it from.', 'beltoft-services' ); ?> <a href="<?php echo esc_url( (string) $view['ca_url'] ); ?>"><?php esc_html_e( 'Download CA certificate', 'beltoft-services' ); ?></a></span>
			<?php endif; ?>
			<?php if ( '' !== (string) $connect['ca_hint'] ) : ?>
				<span class="beltoft-services-dnsbox__hint"><?php echo esc_html( (string) $connect['ca_hint'] ); ?></span>
			<?php endif; ?>
		</li>
		<li>
			<?php esc_html_e( 'Or connect from your own server over the private network. No list and no IPv4 address are needed then.', 'beltoft-services' ); ?>
			<?php if ( '' !== $connect['private_host'] ) : ?>
				<table class="beltoft-services-dnsbox__table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th scope="col"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th></tr></thead>
					<tbody>
						<tr>
							<td data-label="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><?php esc_html_e( 'Private address', 'beltoft-services' ); ?></td>
							<td data-label="<?php esc_attr_e( 'Value', 'beltoft-services' ); ?>"><?php $beltoft_services_copy( $connect['private_host'] ); ?></td>
						</tr>
						<?php if ( '' !== $connect['private_string'] ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><?php esc_html_e( 'Connection', 'beltoft-services' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Value', 'beltoft-services' ); ?>"><?php $beltoft_services_copy( $connect['private_string'] ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<span class="beltoft-services-dnsbox__hint">
				<?php esc_html_e( 'Put your server on the same private network as the database. The Network tab shows how.', 'beltoft-services' ); ?>
				<?php if ( '' !== $beltoft_services_network_url ) : ?>
					<a href="<?php echo esc_url( $beltoft_services_network_url ); ?>"><?php esc_html_e( 'Open the Network tab', 'beltoft-services' ); ?></a>
				<?php endif; ?>
			</span>
		</li>
	</ol>
</section>

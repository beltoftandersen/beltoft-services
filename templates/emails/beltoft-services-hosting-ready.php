<?php
/**
 * Customer email: the hosting account is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-hosting-ready.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var string              $account_url        The service in My Account.
 * @var array<string,mixed> $hosting            site_name, site_url, admin_url, sftp_host, sftp_port, sftp_user, ssh, domains, dns_records, has_email.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_h = is_array( $hosting ?? null ) ? $hosting : array();

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<p>
<?php
if ( '' !== $customer_name ) {
	/* translators: %s: customer's first name. */
	printf( esc_html__( 'Hi %s,', 'beltoft-services' ), esc_html( $customer_name ) );
} else {
	esc_html_e( 'Hi,', 'beltoft-services' );
}
?>
</p>
<p><?php esc_html_e( 'Your hosting is set up and your website is online.', 'beltoft-services' ); ?></p>
<?php if ( ! empty( $beltoft_services_h['site_url'] ) ) : ?>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr>
		<th scope="row" style="text-align:left"><?php esc_html_e( 'Your website', 'beltoft-services' ); ?></th>
		<td><a href="<?php echo esc_url( $beltoft_services_h['site_url'] ); ?>"><?php echo esc_html( $beltoft_services_h['site_url'] ); ?></a></td>
	</tr>
	<tr>
		<th scope="row" style="text-align:left"><?php esc_html_e( 'WordPress admin', 'beltoft-services' ); ?></th>
		<td><a href="<?php echo esc_url( $beltoft_services_h['admin_url'] ); ?>"><?php echo esc_html( $beltoft_services_h['admin_url'] ); ?></a></td>
	</tr>
	<?php if ( ! empty( $beltoft_services_h['sftp_host'] ) ) : ?>
	<tr>
		<th scope="row" style="text-align:left"><?php esc_html_e( 'SFTP', 'beltoft-services' ); ?></th>
		<td><?php echo esc_html( sprintf( /* translators: 1: host, 2: port, 3: user name. */ __( 'Host %1$s, port %2$d, user %3$s', 'beltoft-services' ), $beltoft_services_h['sftp_host'], (int) $beltoft_services_h['sftp_port'], $beltoft_services_h['sftp_user'] ) ); ?></td>
	</tr>
	<?php endif; ?>
</table>
<?php endif; ?>
<p><?php esc_html_e( 'Your WordPress admin password is shown once in My Account. For your safety it is never sent by email.', 'beltoft-services' ); ?></p>
<?php if ( ! empty( $beltoft_services_h['dns_records'] ) ) : ?>
<h2><?php esc_html_e( 'Connect your domain', 'beltoft-services' ); ?></h2>
<p><?php esc_html_e( 'Add these DNS records where the DNS of your domain is hosted. Your website moves to your domain once they are in place, and we check for them regularly.', 'beltoft-services' ); ?></p>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th>
	</tr>
	<?php foreach ( $beltoft_services_h['dns_records'] as $beltoft_services_row ) : ?>
	<tr>
		<td><?php echo esc_html( (string) $beltoft_services_row['type'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['name'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['value'] ); ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your hosting in My Account', 'beltoft-services' ); ?></a></p>
<?php if ( empty( $beltoft_services_h['has_email'] ) ) : ?>
<p><?php esc_html_e( 'Set up Email lite in My Account so your websites can send mail. Email is included with your plan; without it a website cannot send mail.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

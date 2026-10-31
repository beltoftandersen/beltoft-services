<?php
/**
 * Customer email: the email domain is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-mail-ready.php.
 *
 * @package BeltoftServices
 *
 * @var string                           $email_heading      Heading.
 * @var string                           $customer_name      Customer's first name, or ''.
 * @var string                           $account_url        The Email area.
 * @var string                           $mail_domain        The email domain.
 * @var bool                             $mail_external      The domain is hosted elsewhere.
 * @var array<int, array<string, mixed>> $mail_records       Records: name, type, value, priority, status.
 * @var string                           $mail_host          The mail host, or ''.
 * @var int                              $mail_imap          IMAP port.
 * @var int                              $mail_smtp          SMTP port.
 * @var string                           $mail_guide_url     The setup guide, or ''.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
 */

defined( 'ABSPATH' ) || exit;

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
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'Your email domain %s is set up.', 'beltoft-services' ), $mail_domain ) ); ?>
<?php if ( ! $mail_records ) : ?>
<?php esc_html_e( 'The DNS records are in place.', 'beltoft-services' ); ?>
<?php endif; ?>
</p>
<?php if ( $mail_records ) : ?>
<p><?php esc_html_e( 'Add these DNS records where the DNS of your domain is hosted. Email works once they are in place, and we check for them every ten minutes.', 'beltoft-services' ); ?></p>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th>
	</tr>
	<?php foreach ( $mail_records as $beltoft_services_row ) : ?>
	<tr>
		<td><?php echo esc_html( (string) $beltoft_services_row['type'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['name'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . (string) $beltoft_services_row['value'] ); ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>
<h2><?php esc_html_e( 'Next steps', 'beltoft-services' ); ?></h2>
<ol style="margin:0 0 16px;padding-left:20px">
	<li style="margin-bottom:6px"><?php esc_html_e( 'Create your mailboxes in your Email area.', 'beltoft-services' ); ?> <a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your Email area', 'beltoft-services' ); ?></a></li>
	<li style="margin-bottom:6px"><?php esc_html_e( 'Show each mailbox password once there and copy it. Passwords are never sent by email.', 'beltoft-services' ); ?></li>
	<li style="margin-bottom:6px"><?php esc_html_e( 'Add the mailbox to your mail app. Most apps find the settings from the email address.', 'beltoft-services' ); ?>
	<?php if ( '' !== $mail_guide_url ) : ?>
		<a href="<?php echo esc_url( $mail_guide_url ); ?>"><?php esc_html_e( 'Setup guide for mail apps', 'beltoft-services' ); ?></a>
	<?php endif; ?>
	</li>
</ol>
<?php if ( '' !== $mail_host ) : ?>
<h2><?php esc_html_e( 'Settings to enter by hand', 'beltoft-services' ); ?></h2>
<p><?php esc_html_e( 'If your mail app does not find the settings by itself:', 'beltoft-services' ); ?></p>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr>
		<th scope="row" style="text-align:left"><?php esc_html_e( 'Incoming mail (IMAP)', 'beltoft-services' ); ?></th>
		<td><?php echo esc_html( sprintf( /* translators: 1: mail host, 2: IMAP port. */ __( '%1$s, port %2$d, SSL/TLS', 'beltoft-services' ), $mail_host, $mail_imap ) ); ?></td>
	</tr>
	<tr>
		<th scope="row" style="text-align:left"><?php esc_html_e( 'Outgoing mail (SMTP)', 'beltoft-services' ); ?></th>
		<td><?php echo esc_html( sprintf( /* translators: 1: mail host, 2: SMTP port. */ __( '%1$s, port %2$d, SSL/TLS', 'beltoft-services' ), $mail_host, $mail_smtp ) ); ?></td>
	</tr>
	<tr>
		<th scope="row" style="text-align:left"><?php esc_html_e( 'User name', 'beltoft-services' ); ?></th>
		<td><?php esc_html_e( 'The full email address', 'beltoft-services' ); ?></td>
	</tr>
</table>
<?php endif; ?>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

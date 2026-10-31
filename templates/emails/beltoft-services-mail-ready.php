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
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'Your email domain %s is set up.', 'beltoft-services' ), $mail_domain ) ); ?></p>
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
<?php else : ?>
<p><?php esc_html_e( 'The DNS records are in place. You can add your mailboxes now.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'Add your mailboxes in the Email area. Passwords are never sent by email.', 'beltoft-services' ); ?></p>
<?php if ( '' !== $mail_host ) : ?>
<p><?php echo esc_html( sprintf( /* translators: 1: mail host, 2: IMAP port, 3: mail host, 4: SMTP port. */ __( 'If your mail app does not find the settings by itself, enter them by hand. Incoming: IMAP, server %1$s, port %2$d, SSL/TLS. Outgoing: SMTP, server %3$s, port %4$d, SSL/TLS. The user name is the full email address.', 'beltoft-services' ), $mail_host, $mail_imap, $mail_host, $mail_smtp ) ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your Email area', 'beltoft-services' ); ?></a></p>
<?php if ( '' !== $mail_guide_url ) : ?>
<p><a href="<?php echo esc_url( $mail_guide_url ); ?>"><?php esc_html_e( 'Read the setup guide for mail programs', 'beltoft-services' ); ?></a></p>
<?php endif; ?>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

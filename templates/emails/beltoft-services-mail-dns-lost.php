<?php
/**
 * Customer email: DNS records of an email domain are missing.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-mail-dns-lost.php.
 *
 * @package BeltoftServices
 *
 * @var string                           $email_heading      Heading.
 * @var string                           $customer_name      Customer's first name, or ''.
 * @var string                           $account_url        The Email area.
 * @var string                           $mail_domain        The email domain.
 * @var bool                             $mail_moved         The name servers no longer point to us.
 * @var string                           $mail_lost_names    The lost records, such as MX, SPF.
 * @var array<int, array<string, mixed>> $mail_records_lost  The lost records still missing: name, type, value, priority, status.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<p><?php echo esc_html( sprintf( /* translators: 1: domain name, 2: names of the lost records such as MX, SPF. */ __( 'We found the DNS records of %1$s before. These are not found now: %2$s. Mail may not reach your mailboxes, and sending can be paused. Nothing is deleted.', 'beltoft-services' ), $mail_domain, $mail_lost_names ) ); ?></p>
<?php if ( $mail_moved ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'The name servers of %s no longer point to us, so the records we made for you are not used.', 'beltoft-services' ), $mail_domain ) ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'Add the records again at your DNS provider:', 'beltoft-services' ); ?></p>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th>
	</tr>
	<?php foreach ( $mail_records_lost as $beltoft_services_row ) : ?>
	<tr>
		<td><?php echo esc_html( (string) $beltoft_services_row['type'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['name'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . (string) $beltoft_services_row['value'] ); ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<p><?php esc_html_e( 'We keep checking. It can take a while before a change at your DNS provider is visible.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your Email area', 'beltoft-services' ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

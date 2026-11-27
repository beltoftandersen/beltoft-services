<?php
/**
 * Customer email: DNS records of a service are missing.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-dns-lost.php.
 *
 * @package BeltoftServices
 *
 * @var string                           $email_heading      Heading.
 * @var string                           $customer_name      Customer's first name, or ''.
 * @var string                           $dns_name           The domain.
 * @var string                           $dns_subject        What the records are for, such as "Email for example.com".
 * @var string                           $dns_effect         What the missing records mean, from the feature.
 * @var string                           $dns_where          Where the customer sees the state, such as "the Email area".
 * @var string                           $dns_link           The page of the service.
 * @var string                           $dns_open_text      The words of that link.
 * @var string                           $dns_guide_url      A setup guide, or ''.
 * @var string                           $dns_guide_text     The words of the guide link.
 * @var int                              $dns_days           Days the records are looked for every few minutes.
 * @var bool                             $dns_moved          The name servers no longer point to us.
 * @var string                           $dns_lost_names     The lost records, such as MX, SPF.
 * @var array<int, array<string, mixed>> $dns_missing        The needed records still missing: name, type, value, priority, status.
 * @var array<int, array<string, mixed>> $dns_lost_rows      The lost records still missing.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<p><?php echo esc_html( sprintf( /* translators: 1: domain name, 2: names of the lost records such as MX, SPF, 3: what the missing records mean. */ __( 'We found the DNS records of %1$s before. These are not found now: %2$s. %3$s Nothing is deleted.', 'beltoft-services' ), $dns_name, $dns_lost_names, $dns_effect ) ); ?></p>
<?php if ( $dns_moved ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'The name servers of %s no longer point to us, so the records we made for you are not used.', 'beltoft-services' ), $dns_name ) ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'Add the records again at your DNS provider:', 'beltoft-services' ); ?></p>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th>
	</tr>
	<?php foreach ( $dns_lost_rows as $beltoft_services_row ) : ?>
	<tr>
		<td><?php echo esc_html( (string) $beltoft_services_row['type'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['name'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . (string) $beltoft_services_row['value'] ); ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<p><?php esc_html_e( 'We keep checking. It can take a while before a change at your DNS provider is visible.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( $dns_link ); ?>"><?php echo esc_html( $dns_open_text ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

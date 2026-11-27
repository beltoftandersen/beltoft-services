<?php
/**
 * Customer email: DNS records are still missing.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-dns-reminder.php.
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
<p><?php echo esc_html( sprintf( /* translators: 1: what the records are for, such as "Email for example.com", 2: what the missing records mean. */ __( '%1$s will not work fully until these DNS records are added where the domain is hosted. %2$s', 'beltoft-services' ), $dns_subject, $dns_effect ) ); ?></p>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
		<th scope="col" style="text-align:left"><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th>
	</tr>
	<?php foreach ( $dns_missing as $beltoft_services_row ) : ?>
	<tr>
		<td><?php echo esc_html( (string) $beltoft_services_row['type'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['name'] ); ?></td>
		<td style="word-break:break-all"><?php echo esc_html( ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . (string) $beltoft_services_row['value'] ); ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<p><?php echo esc_html( sprintf( /* translators: %s: where the customer sees the state, such as "the Email area". */ __( 'It can take a while before a change at your DNS host is visible. We keep checking, and %s shows which records we have found.', 'beltoft-services' ), $dns_where ) ); ?></p>
<p><a href="<?php echo esc_url( $dns_link ); ?>"><?php echo esc_html( $dns_open_text ); ?></a></p>
<?php if ( '' !== $dns_guide_url ) : ?>
<p><a href="<?php echo esc_url( $dns_guide_url ); ?>"><?php echo esc_html( $dns_guide_text ); ?></a></p>
<?php endif; ?>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

<?php
/**
 * Customer email: the domain has to be validated.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-ssl-action-needed.php.
 *
 * @package BeltoftServices
 *
 * @var string              $email_heading      Heading.
 * @var string              $customer_name      Customer's first name, or ''.
 * @var string              $account_url        The certificate's page.
 * @var array<string,mixed> $ssl                Display values: name, reason, rows, approver, valid_to, by_customer, pull_url, key_waiting.
 * @var string              $additional_content Additional content from the email's settings.
 * @var \WC_Email           $email              The email.
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
<p><?php echo esc_html( sprintf( /* translators: %s: name on the certificate. */ __( 'To issue your certificate for %s the domain has to be validated.', 'beltoft-services' ), $ssl['name'] ) ); ?></p>
<?php if ( 'email' === $ssl['reason'] ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: approval email address. */ __( 'The certificate authority has sent an email to %s. Open it and approve the request.', 'beltoft-services' ), $ssl['approver'] ) ); ?></p>
<?php elseif ( 'file' === $ssl['reason'] ) : ?>
<p><?php esc_html_e( 'Put a file with this content on your website, at this address:', 'beltoft-services' ); ?></p>
<?php foreach ( $ssl['rows'] as $beltoft_services_row ) : ?>
<p><code style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['name'] ); ?></code></p>
<pre style="white-space:pre-wrap;word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['value'] ); ?></pre>
<?php endforeach; ?>
<?php else : ?>
<p><?php esc_html_e( 'Add this CNAME record where the DNS of your domain is hosted:', 'beltoft-services' ); ?></p>
<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:16px" border="1">
	<tr><th scope="col" style="text-align:left"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th scope="col" style="text-align:left"><?php esc_html_e( 'Points to', 'beltoft-services' ); ?></th></tr>
<?php foreach ( $ssl['rows'] as $beltoft_services_row ) : ?>
	<tr><td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['name'] ); ?></td><td style="word-break:break-all"><?php echo esc_html( (string) $beltoft_services_row['value'] ); ?></td></tr>
<?php endforeach; ?>
</table>
<p><?php esc_html_e( 'Leave the record in place after the certificate is issued. It lets later reissues validate without you.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'The certificate is issued a few minutes after the authority has seen it. Certificates that name a company can take a few days.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your certificate', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

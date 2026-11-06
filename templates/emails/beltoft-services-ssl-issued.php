<?php
/**
 * Customer email: the SSL certificate is issued.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-ssl-issued.php.
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
<p><?php echo esc_html( sprintf( /* translators: %s: name on the certificate. */ $ssl['smime'] ? __( 'Your S/MIME certificate for %s is issued.', 'beltoft-services' ) : __( 'Your SSL certificate for %s is issued.', 'beltoft-services' ), $ssl['name'] ) ); ?>
<?php if ( '' !== $ssl['valid_to'] ) : ?>
<?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'It is valid until %s.', 'beltoft-services' ), $ssl['valid_to'] ) ); ?>
<?php endif; ?></p>
<ol style="margin:0 0 16px;padding-left:20px">
	<li style="margin-bottom:6px"><?php esc_html_e( 'Download the certificate with its chain from your certificate page.', 'beltoft-services' ); ?></li>
<?php if ( $ssl['key_waiting'] ) : ?>
	<li style="margin-bottom:6px"><?php echo esc_html( $ssl['smime'] ? __( 'Take the key and certificate we made for you as a .p12 file. It can be taken once, in the next 7 days, and then we delete the key. It is never sent by email.', 'beltoft-services' ) : __( 'Take the private key we made for you. It can be taken once, in the next 7 days, and then we delete it. It is never sent by email.', 'beltoft-services' ) ); ?></li>
<?php endif; ?>
<?php if ( $ssl['smime'] ) : ?>
	<li style="margin-bottom:6px"><?php esc_html_e( 'Install them in your email program to sign and encrypt email.', 'beltoft-services' ); ?></li>
</ol>
<?php else : ?>
	<li style="margin-bottom:6px"><?php esc_html_e( 'Install both on your server.', 'beltoft-services' ); ?></li>
</ol>
<p><?php esc_html_e( 'Certificates are valid for a short time, at most 199 days now and 100 days from March 2027. During your year the certificate is reissued, and each new one must be installed. We email you each time.', 'beltoft-services' ); ?></p>
<p><?php esc_html_e( 'To fetch the current certificate from a script, read this address with an API token:', 'beltoft-services' ); ?><br><code><?php echo esc_html( $ssl['pull_url'] ); ?></code></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your certificate', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

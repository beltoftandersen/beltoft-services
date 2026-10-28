<?php
/**
 * Customer email: your domain was transferred to us.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-domain-transferred.php.
 *
 * @package BeltoftServices
 *
 * @var string   $email_heading      Heading.
 * @var string   $customer_name      Customer's first name, or ''.
 * @var string   $domain_name        The domain.
 * @var bool     $dns_stays          Our DNS already served the domain.
 * @var string   $domain_renews      Next renewal date, or ''.
 * @var string   $domains_url        My Account, Domains.
 * @var string[] $nameservers_shown  Name servers of the domain (registration only).
 * @var string   $additional_content Additional content from the email's settings.
 * @var \WC_Email $email             The email.
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
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'Your domain %s is now with us. We are its registrar from now on.', 'beltoft-services' ), $domain_name ) ); ?></p>
<?php if ( $dns_stays ) : ?>
<p><?php esc_html_e( 'Your DNS stays as it is.', 'beltoft-services' ); ?></p>
<?php else : ?>
<p><?php esc_html_e( 'The domain now uses our name servers. Check that your DNS records are in My account > Domains, so your website and email keep working.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<?php if ( '' !== $domain_renews ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'Next renewal: %s.', 'beltoft-services' ), $domain_renews ) ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $domains_url ); ?>"><?php esc_html_e( 'Manage your domains in your account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

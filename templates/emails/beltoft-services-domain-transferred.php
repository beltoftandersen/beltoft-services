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
 * @var bool     $dns_external       The DNS stays with the current name servers.
 * @var string   $dns_choice         Where the customer chose to have the DNS at the transfer: first, after or keep ('' for a registration).
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
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( '%s is now registered with us. Your website and email work as before.', 'beltoft-services' ), $domain_name ) ); ?></p>
<?php if ( $dns_stays ) : ?>
<p><?php esc_html_e( 'Your DNS stays as it is.', 'beltoft-services' ); ?></p>
<?php elseif ( $dns_external ) : ?>
<p><?php esc_html_e( 'Your DNS stays where it is now. Your name servers do not change. Check that your DNS stays active, because your previous registrar may switch it off.', 'beltoft-services' ); ?></p>
<?php elseif ( 'first' === $dns_choice ) : ?>
<p><?php esc_html_e( 'You moved your DNS to our name servers before the transfer, and we copied your DNS records first, so your website and email kept working. Check that your DNS records are in My account > Domains.', 'beltoft-services' ); ?></p>
<?php elseif ( 'after' === $dns_choice ) : ?>
<p><?php esc_html_e( 'We copied your DNS records before the transfer and have now moved your domain to our name servers, so your website and email keep working. Check that your DNS records are in My account > Domains.', 'beltoft-services' ); ?></p>
<?php else : ?>
<p><?php esc_html_e( 'The domain now uses our name servers. Check that your DNS records are in My account > Domains, so your website and email keep working.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<?php if ( '' !== $domain_renews ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'It renews on %s.', 'beltoft-services' ), $domain_renews ) ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $domains_url ); ?>"><?php esc_html_e( 'Manage your domains in your account', 'beltoft-services' ); ?></a></p>
<?php

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.

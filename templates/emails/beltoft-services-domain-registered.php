<?php
/**
 * Customer email: your domain is registered.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-domain-registered.php.
 *
 * @package BeltoftServices
 *
 * @var string   $email_heading      Heading.
 * @var string   $customer_name      Customer's first name, or ''.
 * @var string   $domain_name        The domain.
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
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'Your domain %s is registered.', 'beltoft-services' ), $domain_name ) ); ?></p>
<?php if ( $nameservers_shown ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: name servers, comma separated. */ __( 'Name servers: %s', 'beltoft-services' ), implode( ', ', array_map( 'strval', $nameservers_shown ) ) ) ); ?></p>
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

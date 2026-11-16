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

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'Your domain %s is registered.', 'beltoft-services' ), $domain_name ) ); ?></p>
<?php if ( $nameservers_shown ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: name servers, comma separated. */ __( 'Name servers: %s', 'beltoft-services' ), implode( ', ', array_map( 'strval', $nameservers_shown ) ) ) ); ?></p>
<?php endif; ?>
<?php if ( '' !== $domain_renews ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'Next renewal: %s.', 'beltoft-services' ), $domain_renews ) ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $domains_url ); ?>"><?php esc_html_e( 'Manage your domains in your account', 'beltoft-services' ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

<?php
/**
 * Customer email (plain text): your domain was transferred to us.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-domain-transferred.php.
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

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

echo wp_strip_all_tags( sprintf( /* translators: %s: domain name. */ __( '%s is now registered with us. Your website and email work as before.', 'beltoft-services' ), $domain_name ) ) . "\n\n";
if ( $dns_stays ) {
	echo __( 'Your DNS stays as it is.', 'beltoft-services' ) . "\n\n";
} elseif ( $dns_external ) {
	echo __( 'Your DNS stays where it is now. Your name servers do not change. Check that your DNS stays active, because your previous registrar may switch it off.', 'beltoft-services' ) . "\n\n";
} elseif ( 'first' === $dns_choice ) {
	echo __( 'You moved your DNS to our name servers before the transfer, and we copied your DNS records first, so your website and email kept working. Check that your DNS records are in My account > Domains.', 'beltoft-services' ) . "\n\n";
} elseif ( 'after' === $dns_choice ) {
	echo __( 'We copied your DNS records before the transfer and have now moved your domain to our name servers, so your website and email keep working. Check that your DNS records are in My account > Domains.', 'beltoft-services' ) . "\n\n";
} else {
	echo __( 'The domain now uses our name servers. Check that your DNS records are in My account > Domains, so your website and email keep working.', 'beltoft-services' ) . "\n\n";
}

if ( '' !== $domain_renews ) {
	/* translators: %s: date. */
	echo wp_strip_all_tags( sprintf( __( 'It renews on %s.', 'beltoft-services' ), $domain_renews ) ) . "\n\n";
}

echo __( 'Manage your domains in your account:', 'beltoft-services' ) . ' ' . esc_url_raw( $domains_url ) . "\n\n";

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

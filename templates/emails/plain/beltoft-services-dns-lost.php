<?php
/**
 * Customer email (plain text): DNS records of a service are missing.
 *
 * Override by copying it to yourtheme/woocommerce/emails/plain/beltoft-services-dns-lost.php.
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
 * @var array<int, array<string, mixed>> $dns_missing        The needed records still missing.
 * @var array<int, array<string, mixed>> $dns_lost_rows      The lost records still missing.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
 */

defined( 'ABSPATH' ) || exit;

// A plain text email: tags are stripped, and HTML-escaping would show entities such as &amp; in the text.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-heading.php';

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-greeting.php';

/* translators: 1: domain name, 2: names of the lost records such as MX, SPF, 3: what the missing records mean. */
echo wp_strip_all_tags( sprintf( __( 'We found the DNS records of %1$s before. These are not found now: %2$s. %3$s Nothing is deleted.', 'beltoft-services' ), $dns_name, $dns_lost_names, $dns_effect ) ) . "\n\n";

if ( $dns_moved ) {
	/* translators: %s: domain name. */
	echo wp_strip_all_tags( sprintf( __( 'The name servers of %s no longer point to us, so the records we made for you are not used.', 'beltoft-services' ), $dns_name ) ) . "\n\n";
}

echo __( 'Add the records again at your DNS provider:', 'beltoft-services' ) . "\n\n";

foreach ( $dns_lost_rows as $beltoft_services_row ) {
	echo wp_strip_all_tags( $beltoft_services_row['type'] . ' ' . $beltoft_services_row['name'] . ' ' . ( ! empty( $beltoft_services_row['priority'] ) ? $beltoft_services_row['priority'] . ' ' : '' ) . $beltoft_services_row['value'] ) . "\n";
}

echo "\n";
echo __( 'We keep checking. It can take a while before a change at your DNS provider is visible.', 'beltoft-services' ) . "\n\n";
echo $dns_open_text . ':' . ' ' . esc_url_raw( $dns_link ) . "\n\n";

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/plain-footer.php';

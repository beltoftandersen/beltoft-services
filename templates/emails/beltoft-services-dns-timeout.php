<?php
/**
 * Customer email: we stopped checking the DNS of a service.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-dns-timeout.php.
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
<p><?php echo esc_html( sprintf( /* translators: 1: domain name, 2: number of days, 3: what is kept, 4: where the customer presses Check now, such as "the Email area". */ __( 'We have stopped checking the DNS of %1$s after %2$d days. %3$s Add the records and press Check now in %4$s, and we start again.', 'beltoft-services' ), $dns_name, $dns_days, $dns_effect, $dns_where ) ); ?></p>
<p><a href="<?php echo esc_url( $dns_link ); ?>"><?php echo esc_html( $dns_open_text ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

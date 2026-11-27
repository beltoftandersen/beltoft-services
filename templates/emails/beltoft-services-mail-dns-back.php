<?php
/**
 * Customer email: the DNS records of an email domain are found again.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-mail-dns-back.php.
 *
 * @package BeltoftServices
 *
 * @var string                           $email_heading      Heading.
 * @var string                           $customer_name      Customer's first name, or ''.
 * @var string                           $account_url        The Email area.
 * @var string                           $mail_domain        The email domain.
 * @var bool                             $mail_moved         The name servers no longer point to us.
 * @var string                           $mail_lost_names    The lost records, such as MX, SPF.
 * @var array<int, array<string, mixed>> $mail_records_lost  The lost records still missing: name, type, value, priority, status.
 * @var string                           $additional_content Additional content from the email's settings.
 * @var \WC_Email                        $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<p><?php echo esc_html( sprintf( /* translators: %s: domain name. */ __( 'The DNS records of %s are found again. Email works as before.', 'beltoft-services' ), $mail_domain ) ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your Email area', 'beltoft-services' ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

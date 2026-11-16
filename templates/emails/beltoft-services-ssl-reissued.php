<?php
/**
 * Customer email: a new certificate is ready.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-ssl-reissued.php.
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

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<p><?php echo esc_html( sprintf( /* translators: %s: name on the certificate. */ __( 'A new certificate for %s is ready.', 'beltoft-services' ), $ssl['name'] ) ); ?>
<?php if ( '' !== $ssl['valid_to'] ) : ?>
<?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'It is valid until %s.', 'beltoft-services' ), $ssl['valid_to'] ) ); ?>
<?php endif; ?></p>
<p><?php echo esc_html( $ssl['by_customer'] ? __( 'You asked for this reissue.', 'beltoft-services' ) : __( 'Certificates are valid for a short time, so the certificate authority reissues yours during the year.', 'beltoft-services' ) ); ?>
<?php esc_html_e( 'The old certificate keeps working until it ends, but install the new one before then.', 'beltoft-services' ); ?></p>
<?php if ( $ssl['key_waiting'] ) : ?>
<p><?php esc_html_e( 'We made a new private key for this certificate. Take it once from your certificate page in the next 7 days.', 'beltoft-services' ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'To fetch the current certificate from a script, read this address with an API token:', 'beltoft-services' ); ?><br><code><?php echo esc_html( $ssl['pull_url'] ); ?></code></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Open your certificate', 'beltoft-services' ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

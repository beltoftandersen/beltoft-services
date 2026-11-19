<?php
/**
 * Customer email: a domain whose renewal was not paid.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-domain-unpaid.php.
 *
 * @package BeltoftServices
 *
 * @var string                $email_heading      Heading.
 * @var string                $customer_name      Customer's first name, or ''.
 * @var string[]              $unpaid_lines       The paragraphs of the notice.
 * @var array{0:string,1:string} $unpaid_link     Link label and address ('' address: no link).
 * @var string                $additional_content Additional content from the email's settings.
 * @var \WC_Email             $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<?php foreach ( $unpaid_lines as $beltoft_line ) : ?>
<p><?php echo esc_html( $beltoft_line ); ?></p>
<?php endforeach; ?>
<?php if ( '' !== $unpaid_link[1] ) : ?>
<p><a href="<?php echo esc_url( $unpaid_link[1] ); ?>"><?php echo esc_html( $unpaid_link[0] ); ?></a></p>
<?php endif; ?>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';

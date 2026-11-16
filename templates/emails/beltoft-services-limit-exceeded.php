<?php
/**
 * Customer email: the service is over its limit.
 *
 * Override by copying it to yourtheme/woocommerce/emails/beltoft-services-limit-exceeded.php.
 *
 * @package BeltoftServices
 *
 * @var string            $email_heading      Heading.
 * @var string            $product_name       Product name.
 * @var string            $customer_name      Customer's first name, or ''.
 * @var string            $account_url        My Account → Licenses.
 * @var string            $deletion_date      Date of the deletion (deletion warning), or ''.
 * @var string            $usage              Usage line (limit exceeded), or ''.
 * @var string            $additional_content Additional content from the email's settings.
 * @var \WC_Email         $email              The email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
?>

<?php require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-greeting.php'; ?>
<p>
<?php
/* translators: %s: product name. */
echo esc_html( sprintf( __( 'Your %s is over the limit of your plan.', 'beltoft-services' ), $product_name ) );
?>
</p>
<p>
<?php
/* translators: %s: usage, such as "6 of 5". */
echo esc_html( sprintf( __( 'Usage: %s.', 'beltoft-services' ), $usage ) );
?>
</p>
<p><?php esc_html_e( 'Nothing has been stopped. To stay within the limit, change to a larger plan in your account, or reduce your usage.', 'beltoft-services' ); ?></p>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'View your services in your account', 'beltoft-services' ); ?></a></p>
<?php

require BELTOFT_SERVICES_PATH . 'templates/parts/emails/html-footer.php';
